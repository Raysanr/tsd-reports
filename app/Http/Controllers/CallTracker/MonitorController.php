<?php

namespace App\Http\Controllers\CallTracker;

use App\Http\Controllers\Concerns\PersistsCallTrackerFilters;
use App\Http\Controllers\Controller;
use App\Models\Lead;
use App\Models\TsaShift;
use App\Models\TsaStatusLog;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Monitor TSA (explicit request, 2026-08-20) — a live, at-a-glance view of
 * every active TSA's current status side by side, for a supervisor watching
 * the floor rather than digging through TSA Logs' historical table.
 * Admin-only, same as every other Reports/Config page in this group.
 */
class MonitorController extends Controller
{
    use PersistsCallTrackerFilters;

    public function index(Request $request)
    {
        [$teams, $selectedTeam, $teamsConfig] = $this->resolveTeam($request);
        [$dateFrom, $dateTo] = $this->resolveDateRange($request);

        $q      = trim((string) $request->input('q', ''));
        $status = $request->string('status')->toString();

        $tsas = $this->filteredTsas($request, $selectedTeam, $teamsConfig, $q, $status);

        // Keyed by tsa id — each TSA's own per-status seconds within the
        // picked range (today by default), same shared algorithm Analytics
        // uses for its own (team-wide) breakdown
        // (TsaStatusLog::secondsByStatus()), just scoped to one TSA. Only
        // the "Daily minute record" section is date-scoped this way —
        // current status/current-status-time are always live, a past date
        // has no "current" status of its own.
        //
        // carryOverPriorStatus: false (explicit request, 2026-08-20) — a
        // fresh reset each day, not carried over from whatever status was
        // last touched on some earlier day. See secondsByStatus()'s own
        // doc comment for the real incident this fixed (a phantom 17+ hour
        // "Break" total on a TSA whose only log today was a single Logout).
        $dailyRecords = $tsas->mapWithKeys(fn (TsaShift $t) => [
            $t->id => TsaStatusLog::secondsByStatus($t, $dateFrom, $dateTo, false),
        ]);

        // Lead-queue health per TSA (explicit request, 2026-08-21) —
        // Monitor previously showed TSA status/time only, with zero
        // visibility into whether any of them actually have leads piling
        // up. Same date range as the Daily minute record above (a past
        // date's "overdue"/"callback due" is a real, meaningful question —
        // "how far behind did that day end"), and the exact same query
        // definitions LeadController's own Overdue/Callbacks views and
        // NotificationController's sidebar badges already use, so these
        // counts can never drift from what a TSA's own Leads page shows.
        $leadCounts = $tsas->mapWithKeys(fn (TsaShift $t) => [
            $t->id => [
                'overdue' => Lead::where('tsa_id', $t->id)
                    ->where('status', 'assigned')
                    ->whereBetween('assigned_at', [$dateFrom, $dateTo])
                    ->where('assigned_at', '<=', now()->subHours(LeadController::overdueThresholdHours()))
                    ->count(),
                'callbacks' => Lead::where('tsa_id', $t->id)
                    ->whereNotNull('callback_at')
                    ->whereBetween('callback_at', [$dateFrom, $dateTo])
                    ->where('callback_at', '<=', now())
                    ->count(),
            ],
        ]);

        // Unassigned leads have no tsa_id, so there's no per-TSA card to put
        // this on — shown instead as its own team-wide summary tile (same
        // team-scoping $tsas above already applies, via each lead's own
        // product; 'all' team intentionally counts every team's unassigned
        // leads, same as the roster below does for TSAs). Same date-scoped
        // definition NotificationController's own admin-only "unassigned"
        // badge count uses (by pancake_created_at, not assigned_at — an
        // unassigned lead was never assigned).
        $unassignedLeadsQuery = Lead::where('status', 'unassigned')
            ->whereBetween('pancake_created_at', [$dateFrom, $dateTo]);
        if ($selectedTeam !== 'all') {
            $unassignedLeadsQuery->whereHas('product', fn ($q2) => $q2->where('team', $teamsConfig[$selectedTeam]['order_team']));
        }
        $unassignedLeadsCount = $unassignedLeadsQuery->count();

        // Counts for the legend/summary cards — over every ACTIVE TSA in
        // the selected team (ignoring the search box, but still respecting
        // the status filter dropdown so the numbers stay consistent with
        // whatever's on screen) rather than just the search-narrowed set,
        // so "how many are on Break right now" doesn't change just because
        // someone typed a name into the search box.
        $countBase = $this->filteredTsas($request, $selectedTeam, $teamsConfig, '', $status);
        $statusCounts = collect(TsaShift::STATUSES)->keys()
            ->mapWithKeys(fn ($s) => [$s => $countBase->where('status', $s)->count()]);

        $data = [
            'tsas'                 => $tsas,
            'dailyRecords'         => $dailyRecords,
            'leadCounts'           => $leadCounts,
            'unassignedLeadsCount' => $unassignedLeadsCount,
            'statusCounts'         => $statusCounts,
            'q'                    => $q,
            'selectedStatus'       => $status,
            'teams'                => $teams,
            'selectedTeam'         => $selectedTeam,
            'dateFrom'             => $dateFrom,
            'dateTo'               => $dateTo,
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
     * CSV export of exactly what's on screen right now (same team/search/
     * status/date filters) — current status + the picked range's per-status
     * minutes + total tracked, one row per TSA. Streamed rather than built
     * in memory: this roster is small today, but streaming costs nothing
     * and never needs revisiting if it grows.
     */
    public function export(Request $request)
    {
        [, $selectedTeam, $teamsConfig] = $this->resolveTeam($request);
        [$dateFrom, $dateTo] = $this->resolveDateRange($request);

        $q      = trim((string) $request->input('q', ''));
        $status = $request->string('status')->toString();

        $tsas        = $this->filteredTsas($request, $selectedTeam, $teamsConfig, $q, $status);
        $statusOrder = array_keys(TsaShift::STATUSES);

        $filename = 'monitor-tsa-' . now('Asia/Manila')->format('Y-m-d_His') . '.csv';

        return response()->streamDownload(function () use ($tsas, $statusOrder, $dateFrom, $dateTo) {
            $out = fopen('php://output', 'w');

            $header = array_merge(['TSA', 'Team', 'Current Status', 'Current Status Since'],
                array_map(fn ($s) => TsaShift::STATUSES[$s]['label'] . ' (min)', $statusOrder),
                ['Total Tracked (min)']);
            fputcsv($out, $header);

            foreach ($tsas as $tsa) {
                // Same non-carry-over reset as index()'s own $dailyRecords —
                // the export should always match what's on screen.
                $seconds = TsaStatusLog::secondsByStatus($tsa, $dateFrom, $dateTo, false);
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

    /** Same ALL/SH Naturals/Eyecare filter as Dashboard (explicit request,
     *  2026-08-20) — identical resolution logic (config('teams'), default
     *  'all', an unknown key falls back to 'all' rather than erroring).
     *  Remembered across a tab-away-and-back navigation since 2026-08-24 —
     *  see PersistsCallTrackerFilters's own doc comment. */
    private function resolveTeam(Request $request): array
    {
        $teamsConfig  = config('teams', []);
        $teams        = ['all' => 'ALL'] + array_map(fn ($t) => $t['name'], $teamsConfig);
        $selectedTeam = $this->rememberedFilter($request, 'monitor', 'team', 'all');
        if ($selectedTeam !== 'all' && !array_key_exists($selectedTeam, $teamsConfig)) {
            $selectedTeam = 'all';
        }

        return [$teams, $selectedTeam, $teamsConfig];
    }

    /** Same shared date-picker partial as Dashboard/Analytics (explicit
     *  request, 2026-08-20) — defaults to today (Asia/Manila) when nothing's
     *  picked, same as those pages. TsaStatusLog::secondsByStatus() already
     *  clips a still-future $dateTo to now() on its own, so passing today's
     *  end-of-day here for the common "today" case works the same as the
     *  literal now() this method passed before date filtering existed.
     *  Remembered across a tab-away-and-back navigation since 2026-08-24 —
     *  see PersistsCallTrackerFilters's own doc comment. */
    private function resolveDateRange(Request $request): array
    {
        $dateFromInput = $this->rememberedFilter($request, 'monitor', 'date_from');
        $dateToInput   = $this->rememberedFilter($request, 'monitor', 'date_to');

        $dateFrom = $dateFromInput
            ? Carbon::parse($dateFromInput, 'Asia/Manila')->startOfDay()
            : now('Asia/Manila')->startOfDay();
        $dateTo = $dateToInput
            ? Carbon::parse($dateToInput, 'Asia/Manila')->endOfDay()
            : now('Asia/Manila')->endOfDay();

        return [$dateFrom, $dateTo];
    }

    /** Shared by index()/export() — team + search + status, in that order
     *  (team narrows the roster before search/status ever look at it). */
    private function filteredTsas(Request $request, string $selectedTeam, array $teamsConfig, string $q, string $status): Collection
    {
        $tsas = TsaShift::where('active', true)->orderBy('sort_order')->get();

        if ($selectedTeam !== 'all') {
            $tsas = $tsas->where('team', $teamsConfig[$selectedTeam]['order_team'])->values();
        }
        if ($q !== '') {
            $needle = strtolower($q);
            $tsas = $tsas->filter(fn (TsaShift $t) => str_contains(strtolower($t->display_name), $needle)
                || str_contains(strtolower($t->tsa_key ?? ''), $needle))->values();
        }
        if ($status !== '') {
            $tsas = $tsas->where('status', $status)->values();
        }

        return $tsas;
    }
}
