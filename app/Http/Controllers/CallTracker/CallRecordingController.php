<?php

namespace App\Http\Controllers\CallTracker;

use App\Http\Controllers\Controller;
use App\Models\CallRecording;
use App\Models\TsaShift;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;

/**
 * Ported from call-tracker (merged into one app 2026-08-12) — the WEB half
 * only (index/stream, session-authed, admin-only). The stateless upload
 * endpoint (store(), per-TSA api_token authed, hit by whatever uploads a
 * finished recording from a TSA's own PC) is a SEPARATE class at
 * App\Http\Controllers\CallRecordingController (app-root namespace, matching
 * tsd-reports' CronController convention for unauthenticated/token-guarded
 * controllers) — see routes/api.php. call-tracker itself had both actions on
 * one class; split here so the web (CallTracker\-namespaced, `auth`+`active`+
 * role-gated) and API (stateless, routes/api.php, no session/CSRF) halves
 * don't share a controller across two totally different auth models.
 *
 * NOTE: Storage::disk('local') recordings are ephemeral on Railway (wiped on
 * every redeploy/restart) — see the merge plan's Phase 5, a separate
 * pre-launch blocker, not addressed here.
 */
class CallRecordingController extends Controller
{
    /** Admin-only, same as Call Log — real customer call recordings are
     *  sensitive, browsing across every TSA's calls is a monitoring/QA
     *  function, not something a TSA needs for their own day-to-day work. */
    public function index(Request $request)
    {
        $dateFrom = $request->string('date_from', now('Asia/Manila')->format('Y-m-d'))->toString();
        $dateTo   = $request->string('date_to', $dateFrom)->toString();
        $from     = Carbon::parse($dateFrom, 'Asia/Manila')->startOfDay();
        $to       = Carbon::parse($dateTo, 'Asia/Manila')->endOfDay();

        $query = CallRecording::with(['tsa', 'lead'])->whereBetween('recorded_at', [$from, $to])->latest('recorded_at');

        if ($request->filled('tsa')) {
            $query->where('tsa_id', $request->integer('tsa'));
        }

        return view('calls.call-recordings', [
            'recordings'  => $query->paginate(30)->withQueryString(),
            'tsas'        => TsaShift::orderBy('sort_order')->get(),
            'selectedTsa' => $request->integer('tsa'),
            'dateFrom'    => $dateFrom,
            'dateTo'      => $dateTo,
        ]);
    }

    /** Streams the raw audio/video for the <audio>/<video> player — never a
     *  public URL, gated by the same admin-role route middleware as index(). */
    public function stream(CallRecording $recording)
    {
        abort_unless(Storage::disk('local')->exists($recording->disk_path), 404);

        return Storage::disk('local')->response($recording->disk_path, $recording->original_filename);
    }
}
