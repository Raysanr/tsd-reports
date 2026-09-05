<?php

namespace App\Http\Controllers\CallTracker;

use App\Http\Controllers\Concerns\PersistsCallTrackerFilters;
use App\Http\Controllers\Controller;
use App\Models\TsaShift;
use App\Support\GoogleDriveClient;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * Browses each TSA's real Google Drive recording folder directly — explicit
 * follow-up request, 2026-09-05: "in the call recordings i thought it will
 * display the recordings in the gdrive like that." This page used to list
 * `CallRecording` rows (a separate, unused pipeline: a TSA's PC uploading via
 * Phone Link + a system-audio recorder — confirmed live, 0 rows ever
 * written), which is why it always showed empty even though the Android-
 * phone-auto-upload-to-Drive pipeline (SyncCallRecordings, feeding
 * call_recording_hours with real aggregated per-hour totals) was working the
 * whole time. This controller instead reuses the exact same live Drive
 * lookup LeadController::recordings()/streamRecording() already use for the
 * lead-detail "listen to recording" popup — same auth, same folder-walk, same
 * Range-aware streaming — just scoped to a picked TSA + date RANGE across
 * every recording in that window, not one lead's phone number.
 *
 * Route-gated by role:super_admin,admin (see routes/web.php), not checked
 * again here — same convention the old version of this controller followed.
 */
class CallRecordingController extends Controller
{
    use PersistsCallTrackerFilters;

    public function index(Request $request, GoogleDriveClient $drive)
    {
        $dateFrom = $this->rememberedFilter($request, 'call-recordings', 'date_from', now('Asia/Manila')->format('Y-m-d'));
        $dateTo   = $this->rememberedFilter($request, 'call-recordings', 'date_to', $dateFrom);
        $from     = Carbon::parse($dateFrom, 'Asia/Manila')->startOfDay();
        $to       = Carbon::parse($dateTo, 'Asia/Manila')->endOfDay();

        $tsaFilterInput = $this->rememberedFilter($request, 'call-recordings', 'tsa');
        $selectedTsa    = $tsaFilterInput ? (int) $tsaFilterInput : null;

        $tsas = TsaShift::where('active', true)->orderBy('sort_order')->get();

        $token = $drive->accessToken();
        if (!$token) {
            return view('calls.call-recordings', [
                'recordings'    => collect(),
                'tsas'          => $tsas,
                'selectedTsa'   => $selectedTsa,
                'dateFrom'      => $dateFrom,
                'dateTo'        => $dateTo,
                'driveConnected' => false,
                'needsTsa'      => false,
            ]);
        }

        // Confirmed live, 2026-09-05: walking Drive for EVERY active TSA on
        // every page load (each one its own OAuth-token-then-folder-walk
        // chain of blocking HTTP calls to Drive) made the page take many
        // seconds to render even with nothing else going on. A single TSA's
        // folder walk is fast; "every TSA at once" is what made it slow —
        // so require an explicit TSA pick before ever touching Drive at all,
        // same as picking one team/filter narrows any other report page.
        if (!$selectedTsa) {
            return view('calls.call-recordings', [
                'recordings'    => collect(),
                'tsas'          => $tsas,
                'selectedTsa'   => null,
                'dateFrom'      => $dateFrom,
                'dateTo'        => $dateTo,
                'driveConnected' => true,
                'needsTsa'      => true,
            ]);
        }

        // A month can span the picked range on either edge (e.g. Aug 30 -
        // Sep 2), so every real calendar month touched by [$from, $to] gets
        // its own resolveTsaFolder() lookup — see that method's own doc
        // comment on why a TSA's folder lives under a MONTH folder, not
        // flatly under their team.
        $tsasToSearch = $tsas->where('id', $selectedTsa);
        $months = collect();
        for ($cursor = $from->copy()->startOfMonth(); $cursor->lte($to); $cursor->addMonthNoOverflow()) {
            $months->push($cursor->copy());
        }

        $recordings = collect();
        foreach ($tsasToSearch as $tsa) {
            $seenFolderIds = [];
            foreach ($months as $month) {
                $folder = $drive->resolveTsaFolder($token, $tsa, $month);
                // Same TSA folder can resolve identically for two different
                // months only when the "no month folder yet, fall back to
                // the team root" branch in resolveTsaFolder() kicks in for
                // both — walking it twice would double-list every file in
                // it, not just re-check an already-covered month.
                if (!$folder || in_array($folder['id'], $seenFolderIds, true)) {
                    continue;
                }
                $seenFolderIds[] = $folder['id'];

                foreach ($drive->listFilesRecursively($token, $folder['id']) as $file) {
                    $moment = $this->parsedRecordingMoment($file['name']);
                    if ($moment && $moment->betweenIncluded($from, $to)) {
                        $recordings->push([
                            'id'     => $file['id'],
                            'name'   => $file['name'],
                            'label'  => $moment->format('M j, g:i A'),
                            'moment' => $moment,
                            'tsa'    => $tsa,
                            // The month this file's OWN folder was resolved
                            // under (not necessarily $moment's calendar
                            // month — a recording made in the last days of
                            // a month can still get filed under that
                            // month's own folder either way in practice,
                            // but this is the value stream() actually needs
                            // to re-find the same folder without guessing).
                            'month'  => $month->format('Y-m'),
                        ]);
                    }
                }
            }
        }

        $recordings = $recordings->sortByDesc(fn ($r) => $r['moment']->timestamp)->values();

        return view('calls.call-recordings', [
            'recordings'     => $recordings,
            'tsas'           => $tsas,
            'selectedTsa'    => $selectedTsa,
            'dateFrom'       => $dateFrom,
            'dateTo'         => $dateTo,
            'driveConnected' => true,
            'needsTsa'       => false,
        ]);
    }

    /**
     * Streams one real recording's audio bytes from Drive — same Range-aware
     * proxy pattern as LeadController::streamRecording() (see that method's
     * own doc comment for why this can't just hand the browser a Drive URL
     * directly: no client-side token exposure). $tsa + a required `month`
     * query param (the recording's own real month, already known from
     * index()'s own listing pass — see the blade view's stream URL) let this
     * re-resolve the TSA's own Drive folder for that EXACT month and confirm
     * the file genuinely lives inside it before ever touching Drive, the
     * same "never trust an id from the URL alone" guard LeadController's own
     * version applies for lead+phone scoping — without needing to blindly
     * re-walk every recent month's folder to find which one has it.
     */
    public function stream(Request $request, TsaShift $tsa, string $fileId, GoogleDriveClient $drive)
    {
        $token = $drive->accessToken();
        if (!$token) {
            abort(404);
        }

        $month  = $request->query('month');
        $forDate = $month ? Carbon::createFromFormat('Y-m', $month, 'Asia/Manila')->startOfMonth() : now('Asia/Manila');

        $folder = $drive->resolveTsaFolder($token, $tsa, $forDate);
        if (!$folder || !collect($drive->listFilesRecursively($token, $folder['id']))->firstWhere('id', $fileId)) {
            abort(404);
        }

        $result = $drive->downloadFileRanged($token, $fileId, $request->header('Range'));
        if (!$result['successful']) {
            abort(404);
        }

        $headers = [
            'Content-Type'  => 'audio/mp4',
            'Accept-Ranges' => 'bytes',
        ];
        if ($result['content_length']) {
            $headers['Content-Length'] = $result['content_length'];
        }
        if ($result['content_range']) {
            $headers['Content-Range'] = $result['content_range'];
        }

        return response($result['body'], $result['status'], $headers);
    }

    /** "<phone> 2026-08-19 14-30-05.m4a" -> a real Carbon instant, or null if
     *  the filename doesn't match the expected format at all — same regex
     *  LeadController::parsedRecordingMoment()/SyncCallRecordings::
     *  parseFilename() already use for this identical filename convention. */
    private function parsedRecordingMoment(string $filename): ?Carbon
    {
        if (!preg_match('/(\d{4}-\d{2}-\d{2})\s+(\d{2})-(\d{2})-(\d{2})/', $filename, $m)) {
            return null;
        }
        return Carbon::createFromFormat('Y-m-d H:i:s', "{$m[1]} {$m[2]}:{$m[3]}:{$m[4]}", 'Asia/Manila');
    }
}
