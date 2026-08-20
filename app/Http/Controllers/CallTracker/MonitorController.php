<?php

namespace App\Http\Controllers\CallTracker;

use App\Http\Controllers\Controller;
use App\Models\Setting;
use App\Models\TsaShift;
use App\Models\TsaStatusLog;
use Illuminate\Http\Request;

/**
 * Monitor TSA (explicit request, 2026-08-20) — a live, at-a-glance view of
 * every active TSA's current status side by side, for a supervisor watching
 * the floor rather than digging through TSA Logs' historical table.
 * Admin-only, same as every other Reports/Config page in this group.
 */
class MonitorController extends Controller
{
    public function index(Request $request)
    {
        $q      = trim((string) $request->input('q', ''));
        $status = $request->string('status')->toString();

        $tsas = TsaShift::where('active', true)->orderBy('sort_order')->get();

        if ($q !== '') {
            $needle = strtolower($q);
            $tsas = $tsas->filter(fn (TsaShift $t) => str_contains(strtolower($t->display_name), $needle)
                || str_contains(strtolower($t->tsa_key ?? ''), $needle))->values();
        }
        if ($status !== '') {
            $tsas = $tsas->where('status', $status)->values();
        }

        $todayStart = now('Asia/Manila')->startOfDay();
        $now        = now('Asia/Manila');

        // Keyed by tsa id — each TSA's own today-so-far seconds-per-status,
        // same shared algorithm Analytics uses for its own (team-wide,
        // date-range) breakdown (TsaStatusLog::secondsByStatus()), just
        // scoped to one TSA and always "today" here regardless of any date
        // filter elsewhere in the app — Monitor is a live view, not a
        // historical report.
        $dailyRecords = $tsas->mapWithKeys(fn (TsaShift $t) => [
            $t->id => TsaStatusLog::secondsByStatus($t, $todayStart, $now),
        ]);

        // Counts for the legend/summary cards — over every ACTIVE TSA
        // (ignoring the search box, but still respecting the status filter
        // dropdown so the numbers stay consistent with whatever's on
        // screen) rather than just the search-narrowed set, so "how many
        // are on Break right now" doesn't change just because someone typed
        // a name into the search box.
        $countBase = TsaShift::where('active', true)->get();
        if ($status !== '') {
            $countBase = $countBase->where('status', $status)->values();
        }
        $statusCounts = collect(TsaShift::STATUSES)->keys()
            ->mapWithKeys(fn ($s) => [$s => $countBase->where('status', $s)->count()]);

        $data = [
            'tsas'             => $tsas,
            'dailyRecords'     => $dailyRecords,
            'statusCounts'     => $statusCounts,
            'q'                => $q,
            'selectedStatus'   => $status,
            'wrapUpSeconds'    => max(1, (int) Setting::get('wrap_up_duration_seconds', 60)),
        ];

        // Same "poll this same URL, swap in just the content" convention
        // Leads' table already uses (see LeadController::index()) — Monitor
        // is meant to sit on a wall/second screen and update itself without
        // ever needing a manual refresh.
        if ($request->header('X-Table-Refresh')) {
            return view('calls.monitor._content', $data);
        }

        return view('calls.monitor', $data);
    }

    /**
     * Manual "End Call -> Auto Wrap Up" — the fallback for when the
     * MacroDroid call-ended webhook doesn't fire (phone not set up yet, no
     * signal at the moment the call ended, etc.). Only makes sense from
     * Calling; a no-op guard rather than an error for anything else, since
     * this button only ever renders while a card is showing Calling in the
     * first place — by the time this request lands, that may have already
     * changed (e.g. the webhook itself just beat this click).
     */
    public function endCall(TsaShift $tsa)
    {
        if ($tsa->status === TsaShift::STATUS_CALLING) {
            $tsa->applyStatusChange(TsaShift::STATUS_WRAP_UP);
        }

        if (request()->wantsJson()) {
            return response()->json(['success' => true, 'status' => $tsa->fresh()->status]);
        }

        return back();
    }

    /**
     * CSV export of exactly what's on screen right now (same search/status
     * filters) — current status + today's per-status minutes + total
     * tracked, one row per TSA. Streamed rather than built in memory: this
     * roster is small today, but streaming costs nothing and never needs
     * revisiting if it grows.
     */
    public function export(Request $request)
    {
        $q      = trim((string) $request->input('q', ''));
        $status = $request->string('status')->toString();

        $tsas = TsaShift::where('active', true)->orderBy('sort_order')->get();
        if ($q !== '') {
            $needle = strtolower($q);
            $tsas = $tsas->filter(fn (TsaShift $t) => str_contains(strtolower($t->display_name), $needle)
                || str_contains(strtolower($t->tsa_key ?? ''), $needle))->values();
        }
        if ($status !== '') {
            $tsas = $tsas->where('status', $status)->values();
        }

        $todayStart  = now('Asia/Manila')->startOfDay();
        $now         = now('Asia/Manila');
        $statusOrder = array_keys(TsaShift::STATUSES);

        $filename = 'monitor-tsa-' . now('Asia/Manila')->format('Y-m-d_His') . '.csv';

        return response()->streamDownload(function () use ($tsas, $statusOrder, $todayStart, $now) {
            $out = fopen('php://output', 'w');

            $header = array_merge(['TSA', 'Team', 'Current Status', 'Current Status Since'],
                array_map(fn ($s) => TsaShift::STATUSES[$s]['label'] . ' (min)', $statusOrder),
                ['Total Tracked (min)']);
            fputcsv($out, $header);

            foreach ($tsas as $tsa) {
                $seconds = TsaStatusLog::secondsByStatus($tsa, $todayStart, $now);
                $minutes = array_map(fn ($s) => round($seconds[$s] / 60, 1), $statusOrder);

                fputcsv($out, array_merge([
                    $tsa->display_name,
                    $tsa->team,
                    TsaShift::STATUSES[$tsa->status]['label'] ?? $tsa->status,
                    optional($tsa->status_changed_at)->format('Y-m-d H:i:s'),
                ], $minutes, [round(array_sum($seconds) / 60, 1)]));
            }

            fclose($out);
        }, $filename, ['Content-Type' => 'text/csv']);
    }
}
