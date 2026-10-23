<?php

namespace App\Support;

use App\Models\Lead;
use App\Models\LeadActivity;
use App\Models\Setting;
use App\Services\SmsGateApi;
use Illuminate\Support\Str;
use Throwable;

/**
 * Auto-text a new lead from the assigned TSA's own phone (explicit request,
 * 2026-10-23): one SMS the moment a lead first gets a TSA, worded by that
 * lead's product (Product.sms_template), sent through the TSA's own SMSGate
 * account so the text comes from the same number that will call them.
 *
 * Silent when switched off (global Setting `auto_sms_enabled` or the TSA's
 * own sms_enabled) — nothing to log there. Any other reason a text didn't
 * go out (phone offline, no message for this product, bad number, API
 * error) is skipped AND logged on the lead as `sms_skipped`, never retried
 * (explicit choice: "skip and log it"). Never throws — a failed text must
 * not break the lead sync it runs inside.
 */
class LeadAutoText
{
    public const SETTING_KEY = 'auto_sms_enabled';

    /** @var array<string, ?bool> */
    private static array $onlineMemo = [];

    /** Tests only — the per-minute online memo is process-wide. */
    public static function flushOnlineMemo(): void
    {
        self::$onlineMemo = [];
    }

    public static function enabled(): bool
    {
        return (bool) Setting::get(self::SETTING_KEY, false);
    }

    public static function sendForNewLead(Lead $lead): void
    {
        try {
            self::attempt($lead);
        } catch (Throwable $e) {
            report($e);
            self::skip($lead, 'unexpected error (' . class_basename($e) . ')');
        }
    }

    private static function attempt(Lead $lead): void
    {
        $tsa = $lead->tsa;
        if (!self::enabled() || !$tsa || !$tsa->sms_enabled) {
            return;
        }

        if (!$tsa->smsgate_username || !$tsa->smsgate_password) {
            self::skip($lead, "{$tsa->display_name} has no SMSGate login set in TSA Management");
            return;
        }

        $template = trim((string) $lead->product?->sms_template);
        if ($template === '') {
            self::skip($lead, 'no auto-text message set for ' . ($lead->product?->display_name ?? 'this product'));
            return;
        }

        // A text reading "Hi !" or "deliver to ." is worse than none.
        if ($missing = self::missing($template, $lead)) {
            self::skip($lead, 'this lead has no ' . implode(', ', $missing) . ' for the message');
            return;
        }

        $phone = self::normalizePhone($lead->phone_number);
        if ($phone === null) {
            self::skip($lead, 'invalid phone number "' . $lead->phone_number . '"');
            return;
        }

        $api = app(SmsGateApi::class);
        // Checked once per phone per minute, so a burst of new leads in one
        // sync run doesn't re-ask SMSGate for the same phone every time.
        $memoKey = $tsa->id . ':' . now()->format('YmdHi');
        $online = self::$onlineMemo[$memoKey] ??= $api->isOnline($tsa->smsgate_username, $tsa->smsgate_password);
        if ($online === null) {
            self::skip($lead, "couldn't reach SMSGate for {$tsa->display_name}'s phone (check the login)");
            return;
        }
        if (!$online) {
            self::skip($lead, "{$tsa->display_name}'s phone is offline in SMSGate");
            return;
        }

        $text = self::render($template, $lead);
        $result = $api->send($tsa->smsgate_username, $tsa->smsgate_password, $phone, $text);

        if ($result['ok']) {
            // description is varchar(255) — Postgres rejects longer.
            LeadActivity::log($lead, 'sms_sent', Str::limit("Auto-text sent from {$tsa->display_name}'s phone: \"{$text}\"", 250));
        } else {
            self::skip($lead, $result['error']);
        }
    }

    /** Insertable fields for the message editor's { } menu — key is the
     *  placeholder written in the template. {name}/{fullname} stay as
     *  aliases for messages saved before the menu existed. */
    public const PLACEHOLDERS = [
        'first_name' => 'First name',
        'last_name'  => 'Last name',
        'full_name'  => 'Full name',
        'phone'      => 'Phone number',
        'address'    => 'Address',
        'product'    => 'Product',
        'tsa'        => 'TSA name',
    ];

    /** Placeholder => value for this lead ('' when the lead lacks it). */
    public static function values(Lead $lead): array
    {
        $fullName = trim(preg_replace('/\s+/', ' ', (string) $lead->customer_name));
        $parts = $fullName === '' ? [] : explode(' ', $fullName);
        $first = $parts[0] ?? '';
        $last = count($parts) > 1 ? implode(' ', array_slice($parts, 1)) : '';

        return [
            'first_name' => $first,
            'last_name'  => $last,
            'full_name'  => $fullName,
            'phone'      => trim((string) $lead->phone_number),
            'address'    => trim((string) $lead->customer_address),
            'product'    => (string) $lead->product?->display_name,
            'tsa'        => (string) $lead->tsa?->display_name,
            'name'       => $first,
            'fullname'   => $fullName,
        ];
    }

    /** Placeholders the template uses that this lead has no value for. */
    public static function missing(string $template, Lead $lead): array
    {
        preg_match_all('/\{([a-z_]+)\}/', $template, $m);
        $values = self::values($lead);

        return collect($m[1])->unique()
            ->filter(fn ($key) => array_key_exists($key, $values) && $values[$key] === '')
            ->map(fn ($key) => strtolower(self::PLACEHOLDERS[$key] ?? $key))
            ->values()->all();
    }

    public static function render(string $template, Lead $lead): string
    {
        $values = self::values($lead);

        return strtr($template, collect($values)->mapWithKeys(fn ($v, $k) => ['{' . $k . '}' => $v])->all());
    }

    /** PH mobile numbers → +639XXXXXXXXX; anything else → null. */
    public static function normalizePhone(?string $raw): ?string
    {
        $digits = preg_replace('/\D+/', '', (string) $raw);

        if (preg_match('/^09\d{9}$/', $digits)) {
            return '+63' . substr($digits, 1);
        }
        if (preg_match('/^639\d{9}$/', $digits)) {
            return '+' . $digits;
        }
        if (preg_match('/^9\d{9}$/', $digits)) {
            return '+63' . $digits;
        }

        return null;
    }

    private static function skip(Lead $lead, string $reason): void
    {
        LeadActivity::log($lead, 'sms_skipped', Str::limit("Auto-text not sent — {$reason}.", 250));
    }
}
