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
    public function store(Request $request)
    {
        // MacroDroid's own [call_type] magic variable reports "Incoming"/
        // "Outgoing"/"Missed" (capitalized) — normalizing here means the
        // phone-side setup never needs an extra Search & Replace step just
        // to match this system's lowercase convention.
        if ($request->filled('direction')) {
            $request->merge(['direction' => strtolower($request->string('direction'))]);
        }

        $data = $request->validate([
            'api_token'        => ['required', 'string'],
            'phone_number'     => ['required', 'string', 'max:32'],
            'direction'        => ['required', 'string', 'in:outgoing,incoming,missed'],
            'duration_seconds' => ['nullable', 'integer', 'min:0'],
            'occurred_at'      => ['nullable', 'date'],
        ]);

        $tsa = TsaShift::where('api_token', $data['api_token'])->first();
        abort_if(!$tsa, 401, 'Unknown or revoked api_token.');

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
        if ($tsa->status === TsaShift::STATUS_CALLING) {
            $tsa->applyStatusChange(TsaShift::STATUS_WRAP_UP);
        }

        $event = CallEvent::create([
            'tsa_id'           => $tsa->id,
            'lead_id'          => $this->matchLead($tsa, $data['phone_number'])?->id,
            'phone_number'     => $data['phone_number'],
            'direction'        => $data['direction'],
            'duration_seconds' => $data['duration_seconds'] ?? null,
            // The automation fires right as the call ends — trust the
            // phone's own timestamp when it sends one (it knows the real
            // moment better than "whenever this HTTP request happens to
            // land"), fall back to now() when it doesn't.
            'occurred_at'      => $data['occurred_at'] ?? now(),
        ]);

        return response()->json(['success' => true, 'id' => $event->id, 'matched_lead_id' => $event->lead_id]);
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
