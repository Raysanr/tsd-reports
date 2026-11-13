<?php

namespace App\Support;

use App\Models\Lead;
use App\Models\LeadActivity;
use App\Models\Order;
use Illuminate\Support\Carbon;

/**
 * No Confirmation page (explicit request, 2026-11-13): after an upsell the
 * TSA asks the customer, on the call, to reply YES/CONFIRM in Messenger to
 * proceed. This reads the lead's Pancake conversation and checks whether
 * the CUSTOMER replied a confirmation after the upsell was added.
 *
 * Results on the lead (upsell_confirmation):
 * - confirmed   — a customer reply matching CONFIRM_PATTERN after upsell_at;
 * - unconfirmed — readable conversation, no such reply (listed on the page);
 * - unreadable  — Pancake refused the conversation (page not on this
 *   account's token) — never listed, per the request.
 */
class UpsellConfirmation
{
    /** YES, CONFIRM(ED), OO, OPO, SIGE, OK/OKAY as whole words. */
    public const CONFIRM_PATTERN = '/\b(yes|confirm|confirmed|oo|opo|sige|ok|okay)\b/iu';

    public static function isConfirmation(string $text): bool
    {
        return (bool) preg_match(self::CONFIRM_PATTERN, $text);
    }

    /** Same rule as calls.js renderMessage(): staff reply or the page itself. */
    public static function isFromCustomer(array $msg): bool
    {
        $from = $msg['from'] ?? [];

        return empty($from['admin_id']) && empty($from['uid'])
            && !(isset($msg['page_id'], $from['id']) && (string) $from['id'] === (string) $msg['page_id']);
    }

    /**
     * When the upsell was added: this app's own successful upsell, else the
     * time an UPSELL tag first appeared in Pancake's history (upsell done
     * directly in POS). Null when it can't be told.
     */
    public static function upsellAt(Lead $lead, PancakeOrderTagApi $api): ?Carbon
    {
        $inApp = LeadActivity::where('lead_id', $lead->id)->where('type', 'upsell_added')
            ->whereNotNull('amount')->min('created_at');
        if ($inApp) {
            return Carbon::parse($inApp);
        }

        foreach (collect($api->getOrderHistories($lead->pancake_order_id) ?? [])->sortBy('updated_at') as $entry) {
            if (!isset($entry['tags']['new'], $entry['updated_at'])) {
                continue;
            }
            $hasUpsell = fn ($tags) => collect($tags)->contains(
                fn ($t) => stripos(\is_array($t) ? ($t['name'] ?? '') : (string) $t, 'UPSELL') !== false
            );
            if ($hasUpsell($entry['tags']['new']) && !$hasUpsell($entry['tags']['old'] ?? [])) {
                return Carbon::parse($entry['updated_at'], 'UTC')->setTimezone(config('app.timezone'));
            }
        }

        return null;
    }

    /** Check one lead and store the result. */
    public static function check(Lead $lead, PancakeConversationApi $conversations, PancakeOrderTagApi $orders): void
    {
        $lead->upsell_at ??= self::upsellAt($lead, $orders);
        $lead->upsell_confirmation_checked_at = now();

        if ($lead->upsell_at === null) {
            $lead->saveQuietly();
            return;
        }

        $result = $conversations->getMessages($lead->pancake_page_id, $lead->pancake_conversation_id);
        if (!$result['success']) {
            $lead->upsell_confirmation = 'unreadable';
            $lead->saveQuietly();
            return;
        }

        $reply = collect($result['messages'])->first(function ($msg) use ($lead) {
            $text = trim(strip_tags((string) ($msg['original_message'] ?? $msg['message'] ?? '')));
            $at = isset($msg['inserted_at']) ? Carbon::parse($msg['inserted_at'], 'UTC') : null;

            return $text !== '' && $at !== null && $at->gte($lead->upsell_at)
                && self::isFromCustomer($msg) && self::isConfirmation($text);
        });

        $lead->upsell_confirmation = $reply ? 'confirmed' : 'unconfirmed';
        $lead->upsell_confirmation_text = $reply
            ? mb_substr(trim(strip_tags((string) ($reply['original_message'] ?? $reply['message']))), 0, 255)
            : null;
        $lead->saveQuietly();
    }

    /** Leads with an upsell worth checking: in-app upsell or a real upsell order. */
    public static function candidates(Carbon $since)
    {
        $upsoldOrderIds = Order::where('pancake_created_at', '>=', $since)->get()
            ->filter(fn (Order $o) => Order::isBroadRealUpsell($o))->pluck('pancake_order_id');

        return Lead::where('pancake_created_at', '>=', $since)
            ->whereNotNull('pancake_page_id')->whereNotNull('pancake_conversation_id')
            ->where(fn ($q) => $q->whereNull('upsell_confirmation')->orWhere('upsell_confirmation', 'unconfirmed'))
            ->where(fn ($q) => $q->whereIn('pancake_order_id', $upsoldOrderIds)
                ->orWhereExists(fn ($e) => $e->selectRaw('1')->from('lead_activities')
                    ->whereColumn('lead_activities.lead_id', 'leads.id')
                    ->where('type', 'upsell_added')->whereNotNull('amount')));
    }
}
