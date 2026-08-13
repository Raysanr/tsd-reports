<?php

namespace App\Http\Controllers;

use App\Models\CallEvent;
use App\Models\CallRecording;
use App\Models\Lead;
use App\Models\TsaShift;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * Ported from call-tracker (merged into one app 2026-08-12) — the STATELESS
 * upload half only (store()), Tsa -> TsaShift. App-root namespace (not
 * CallTracker\), matching tsd-reports' own CronController convention for
 * unauthenticated/token-guarded controllers — see routes/api.php. The
 * session-authed web half (index/stream, admin-only browsing/playback) is a
 * SEPARATE class at App\Http\Controllers\CallTracker\CallRecordingController
 * — call-tracker itself had both actions on one class; split here so the
 * two totally different auth models (per-TSA api_token vs session+role) each
 * get their own controller instead of sharing one across routes/web.php and
 * routes/api.php.
 *
 * Real call recordings — since Android itself can't reliably record a call
 * (see CallEventController's own doc comment), these come from a TSA's own
 * PC instead: Phone Link takes the call over their phone's real SIM (load
 * still deducts normally), a free system-audio recorder (OBS Studio, etc.)
 * captures it, and this endpoint receives the resulting file. Authenticated
 * the same way as CallEventController — a per-TSA api_token, no browser
 * session involved.
 *
 * Stored on the 'call_recordings' disk (config/filesystems.php), not the
 * generic 'local' one — production points its root at a persistent Railway
 * Volume via CALL_RECORDINGS_DISK_ROOT, so an upload survives the next
 * redeploy/restart instead of silently vanishing (the previous behavior,
 * fixed 2026-08-13).
 */
class CallRecordingController extends Controller
{
    private const MAX_FILE_KB = 100 * 1024; // 100MB — a long call at a modest bitrate comfortably fits.
    private const MATCH_WINDOW_MINUTES = 10;

    public function store(Request $request)
    {
        $data = $request->validate([
            'api_token'    => ['required', 'string'],
            'recording'    => ['required', 'file', 'mimes:mp3,wav,m4a,mp4,mkv,ogg,webm', 'max:' . self::MAX_FILE_KB],
            'phone_number' => ['nullable', 'string', 'max:32'],
            'recorded_at'  => ['nullable', 'date'],
        ]);

        $tsa = TsaShift::where('api_token', $data['api_token'])->first();
        abort_if(!$tsa, 401, 'Unknown or revoked api_token.');

        $recordedAt = isset($data['recorded_at']) ? Carbon::parse($data['recorded_at']) : now();

        $file = $request->file('recording');
        $diskPath = $file->storeAs(
            "call-recordings/{$tsa->id}",
            $recordedAt->format('Y-m-d_His') . '_' . Str::random(8) . '.' . $file->getClientOriginalExtension(),
            'call_recordings'
        );

        [$leadId, $callEventId] = $this->matchLeadAndCallEvent($tsa, $data['phone_number'] ?? null, $recordedAt);

        $recording = CallRecording::create([
            'tsa_id'             => $tsa->id,
            'lead_id'            => $leadId,
            'call_event_id'      => $callEventId,
            'disk_path'          => $diskPath,
            'original_filename'  => $file->getClientOriginalName(),
            'file_size_bytes'    => $file->getSize(),
            'recorded_at'        => $recordedAt,
        ]);

        return response()->json(['success' => true, 'id' => $recording->id, 'matched_lead_id' => $leadId]);
    }

    /**
     * A phone number, if the recording tool happens to provide one, matches
     * a lead directly (same normalization as CallEvent). Otherwise — the
     * usual case, since a PC-side audio recorder has no way to know what
     * number was dialed — falls back to whichever of this TSA's own
     * call_events landed closest in time, inheriting its lead too. That
     * link is a best guess, not a guarantee: only trusted within a ±10
     * minute window so an unrelated call hours away is never wrongly
     * attached.
     */
    private function matchLeadAndCallEvent(TsaShift $tsa, ?string $phoneNumber, Carbon $recordedAt): array
    {
        if ($phoneNumber) {
            $normalized = CallEvent::normalizePhone($phoneNumber);
            $lead = $normalized
                ? Lead::where('tsa_id', $tsa->id)->get()->first(fn (Lead $l) => CallEvent::normalizePhone($l->phone_number) === $normalized)
                : null;
            if ($lead) return [$lead->id, null];
        }

        $nearestEvent = CallEvent::where('tsa_id', $tsa->id)
            ->whereBetween('occurred_at', [$recordedAt->copy()->subMinutes(self::MATCH_WINDOW_MINUTES), $recordedAt->copy()->addMinutes(self::MATCH_WINDOW_MINUTES)])
            ->get()
            ->sortBy(fn (CallEvent $e) => abs($e->occurred_at->diffInSeconds($recordedAt)))
            ->first();

        return [$nearestEvent?->lead_id, $nearestEvent?->id];
    }
}
