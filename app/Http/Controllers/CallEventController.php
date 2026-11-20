<?php

namespace App\Http\Controllers;

use App\Models\CallEvent;
use App\Models\Lead;
use App\Models\TsaShift;
use Illuminate\Http\Request;

/**
 * Ported from call-tracker (merged into one app 2026-08-12): Tsa -> TsaShift.
 * App-root namespace (not CallTracker\), matching tsd-reports' own
 * CronController convention for unauthenticated/token-guarded controllers —
 * see routes/api.php.
 *
 * Receives real call events from a TSA's own phone — no logged-in browser
 * session on that side (it's a phone automation app, not a person clicking
 * around Call Tracker), so this is authenticated by a per-TSA api_token
 * instead (see TsaShift::generateApiToken(), issued/shown in TSA Management
 * — added to TsaShift in Phase 4, this endpoint 401s until then).
 *
 * This is the practical answer to "connect their SIM to the system": no
 * telco exposes a way for any app to read or debit a personal prepaid
 * balance, so instead of pretending to "deduct load", every real call the
 * TSA's phone reports gets logged here — call count/duration per TSA is
 * what a load-reimbursement report (CallLogController) is built from.
 */
class CallEventController extends Controller
{
    /** A phone report this soon after going Calling is the call starting. */
    public const CALL_START_SECONDS = 5;

    public function store(Request $request)
    {
        // MacroDroid's own [call_type] magic variable reports "Incoming"/
        // "Outgoing"/"Missed" (capitalized) — normalizing here means the
        // phone-side setup never needs an extra Search & Replace step just
        // to match this system's lowercase convention.
        // Production showed every phone's call-ended request answered with a
        // 302 and nothing saved (2026-11-18): a failed validate() redirects
        // a non-JSON client instead of explaining. MacroDroid can send the
        // body as text/plain, which Laravel doesn't parse into fields, and
        // a magic variable that didn't substitute arrives as literal
        // "[call_duration]". Read the raw body in that case, drop an
        // unusable duration, and answer/log failures as JSON.
        if (!$request->has('api_token') && ($raw = $request->getContent()) !== '') {
            parse_str($raw, $fields);
            $request->merge($fields);
        }
        // Production (2026-11-18, right after the text/plain fix): a real
        // phone's report was rejected for "direction" — MacroDroid's call
        // type isn't always exactly Outgoing/Incoming/Missed (wording,
        // language, or an unsubstituted "[call_type]"). Map by keyword;
        // anything unrecognisable counts as outgoing (every TSA call is
        // dialed from Call Tracker) rather than losing the call-ended signal.
        $rawDirection = trim((string) $request->input('direction', ''));
        $lower = strtolower($rawDirection);
        $direction = match (true) {
            str_contains($lower, 'miss') => 'missed',
            str_contains($lower, 'out') => 'outgoing',
            str_contains($lower, 'in') && !str_contains($lower, '[') => 'incoming',
            default => 'outgoing',
        };
        if (!in_array($lower, ['outgoing', 'incoming', 'missed'], true)) {
            \Illuminate\Support\Facades\Log::info('call-events direction normalised', ['received' => mb_substr($rawDirection, 0, 40), 'used' => $direction]);
        }
        $request->merge(['direction' => $direction]);
        if ($request->filled('duration_seconds') && !ctype_digit(trim((string) $request->input('duration_seconds')))) {
            $request->merge(['duration_seconds' => null]);
        }

        $validator = \Illuminate\Support\Facades\Validator::make($request->all(), [
            'api_token'        => ['required', 'string'],
            'phone_number'     => ['required', 'string', 'max:32'],
            'direction'        => ['required', 'string', 'in:outgoing,incoming,missed'],
            'duration_seconds' => ['nullable', 'integer', 'min:0'],
            'occurred_at'      => ['nullable', 'date'],
        ]);
        if ($validator->fails()) {
            \Illuminate\Support\Facades\Log::warning('call-events rejected', [
                'errors'       => $validator->errors()->toArray(),
                'fields'       => array_keys($request->except('api_token')),
                'has_token'    => $request->filled('api_token'),
                'content_type' => $request->header('Content-Type'),
            ]);

            return response()->json(['success' => false, 'errors' => $validator->errors()], 422);
        }
        $data = $validator->validated();

        $tsa = TsaShift::where('api_token', $data['api_token'])->first();
        if (!$tsa) {
            \Illuminate\Support\Facades\Log::warning('call-events rejected: unknown or revoked api_token');

            return response()->json(['success' => false, 'message' => 'Unknown or revoked api_token.'], 401);
        }

        // Two TSAs sharing one phone (explicit request, 2026-09-11: "2 tsa,
        // one cellphone... no shift schedules, whoever is online and clicks
        // dial") — the token always resolves to the pair's PRIMARY row
        // (TsaShift::pairWith() clears the partner's own token), so a call
        // physically dialed by the partner would otherwise get silently
        // misattributed to the primary. Re-resolve to whichever of the two
        // is actually the one on the phone right now — see
        // TsaShift::resolveActiveOfPair()'s own doc comment for the
        // status=calling / most-recent-status fallback order. Solo TSAs
        // (the overwhelming common case) skip this entirely.
        if ($tsa->isPairPrimary()) {
            $tsa = TsaShift::resolveActiveOfPair($tsa, $tsa->pairedPartner()->firstOrFail());
        }

        // Monitor TSA (explicit request, 2026-08-20): this webhook is the
        // only real signal this app ever gets that a call actually ended —
        // there's no separate "call started" event (see this class's own
        // doc comment above), so a TSA sets themselves to Calling manually
        // before dialing, and THIS is what flips them out of it again. Only
        // acts if they're still marked Calling — if they'd already manually
        // switched to something else (e.g. Break) before this webhook
        // landed, that's a real, more-recent choice this shouldn't stomp on.
        // Wrap Up itself no longer auto-expires (removed 2026-09-01,
        // explicit request) — from here the TSA stays in it until they
        // manually pick their next real status.
        // TSA feedback (2026-11-20): "still calling, but it auto-dials the
        // next lead". The phone also reports when a call STARTS, in the same
        // second as the dial click (195 of 357 events on 2026-11-20). When
        // that report landed just after the click set Calling, it flipped the
        // TSA to Wrap Up while they were still on the phone, so Wrap Up ran
        // out and Auto-call dialed the next lead mid-call. A report within a
        // few seconds of going Calling is that start signal, not a hang-up.
        // Safety net (Aguinaldo, 2026-11-20): his phone also reports when a
        // call connects, 6–22s after dialing — past the window above — and
        // that report ended Calling while he was still talking, so Auto-call
        // dialed over the live call. Phones that send MacroDroid's
        // [trigger_that_fired] say which trigger fired; only "Call Ended"
        // ends the call. Phones that don't send it keep the old behaviour.
        $trigger = self::triggerName($request->input('trigger'));
        $justStartedCalling = $tsa->status_changed_at
            && $tsa->status_changed_at->gt(now()->subSeconds(self::CALL_START_SECONDS));
        if ($tsa->status === TsaShift::STATUS_CALLING && ! $justStartedCalling && self::isEndReport($trigger)
            && $this->isAboutTheCurrentCall($tsa, $data['direction'], $data['phone_number'])) {
            $tsa->applyStatusChange(TsaShift::STATUS_WRAP_UP);
        }

        $event = CallEvent::create([
            'tsa_id'           => $tsa->id,
            'lead_id'          => $this->matchLead($tsa, $data['phone_number'])?->id,
            'phone_number'     => $data['phone_number'],
            'direction'        => $data['direction'],
            'trigger'          => $trigger,
            'duration_seconds' => $data['duration_seconds'] ?? null,
            // The automation fires right as the call ends — trust the
            // phone's own timestamp when it sends one (it knows the real
            // moment better than "whenever this HTTP request happens to
            // land"), fall back to now() when it doesn't.
            'occurred_at'      => $data['occurred_at'] ?? now(),
        ]);

        return response()->json(['success' => true, 'id' => $event->id, 'matched_lead_id' => $event->lead_id]);
    }

    /**
     * TSA feedback again (2026-11-20, after the 5-second fix): "still on the
     * call, a new dial starts and my call gets cut". The phone's "Call Ended"
     * macro fires for EVERY call on the phone — a missed/declined call that
     * rings in during the TSA's call, or a late report of the previous
     * call, landed while they were Calling and was taken as their hang-up.
     * Wrap Up then ran out, Auto-call dialed, and the new dial cut the call.
     * Only a report about the call actually being made ends it: an outgoing
     * call, to the number of the lead they dialed (when both numbers are known).
     */
    /** MacroDroid's [trigger_that_fired], or null when the phone doesn't
     *  send it (or it arrived unsubstituted, still in brackets). */
    public static function triggerName(mixed $raw): ?string
    {
        $raw = trim((string) $raw);

        return $raw === '' || preg_match('/[\[\]{}]/', $raw) ? null : mb_substr($raw, 0, 60);
    }

    /** A report from the Call Ended trigger — or from a phone that doesn't
     *  say which trigger fired (old setup: trusted as before). */
    public static function isEndReport(?string $trigger): bool
    {
        return $trigger === null || (bool) preg_match('/end|disconnect|hang/i', $trigger);
    }

    private function isAboutTheCurrentCall(TsaShift $tsa, string $direction, string $phoneNumber): bool
    {
        if ($direction !== 'outgoing') {
            return false;
        }
        $reported = CallEvent::normalizePhone($phoneNumber);
        if ($reported === null || $tsa->status_changed_at === null) {
            return true;
        }
        $dialed = Lead::where('tsa_id', $tsa->id)
            ->where('dialed_at', '>=', $tsa->status_changed_at->copy()->subSeconds(10))
            ->orderByDesc('dialed_at')
            ->first();
        $dialedNumber = CallEvent::normalizePhone($dialed?->phone_number);

        return $dialedNumber === null || $dialedNumber === $reported;
    }

    /** Best-effort match to one of THIS TSA's own leads by phone number —
     *  never another TSA's, even if the numbers happen to match, since a
     *  call event only makes sense attributed to whoever's phone reported
     *  it. Compares normalized digits (see CallEvent::normalizePhone())
     *  so formatting differences between what the phone reports and what's
     *  stored on the lead ("+639171234567" vs "0917 123 4567") don't cause
     *  a false miss. */
    private function matchLead(TsaShift $tsa, string $phoneNumber): ?Lead
    {
        $normalized = CallEvent::normalizePhone($phoneNumber);
        if (!$normalized) return null;

        return Lead::where('tsa_id', $tsa->id)
            ->get()
            ->first(fn (Lead $lead) => CallEvent::normalizePhone($lead->phone_number) === $normalized);
    }
}
