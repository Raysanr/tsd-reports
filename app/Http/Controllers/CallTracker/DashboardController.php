<?php

namespace App\Http\Controllers\CallTracker;

use App\Http\Controllers\Controller;
use App\Models\CallRecordingHour;
use App\Models\Lead;
use App\Models\LeadSyncRun;
use App\Models\Product;
use App\Models\TsaShift;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;

/**
 * Ported from call-tracker (merged into one app 2026-08-12), namespace-only
 * rewrite: Tsa -> TsaShift throughout. NOTE: TsaShift::STATUS_* / STATUSES /
 * SELF_SERVICE_STATUSES constants and the active/status/status_changed_at
 * columns this controller reads don't exist on TsaShift until Phase 4's
 * schema-extending migration lands — this page will error at runtime until
 * then (see the merge plan's Phase 2/4 split; flagged in the Phase 2 report).
 */
class DashboardController extends Controller
{
    /**
     * Explicit request (2026-08-08): a real overview page, now Call
     * Tracker's actual home (see routes/web.php's 'dashboard' rename).
     *
     * KPI row rebuilt 2026-08-18 (explicit request, matching a KPI-dashboard
     * reference image) to 5 cards — TSA Log In, Total Leads, Total Catered
     * Leads, AHT, Unproductive Time — replacing the previous Assigned/
     * Called/Overdue/Callbacks/Unassigned/Upsells funnel row (that data
     * isn't gone from the app: Overdue/Callbacks still have their own Leads
     * views, Upsells its own activity type). All 5 move with the picked
     * date range except TSA Log In, which — like the TSA Status board and
     * At-Risk Products below it — stays live regardless of the picker:
     * "who's logged in right now" has no historical snapshot to show for a
     * past date. AHT/Unproductive Time were switched 2026-08-24 (explicit
     * request) from CallEvent to CallRecordingHour (real per-hour call
     * durations synced from Google Drive, see SyncCallRecordings) —
     * CallEvent needs each TSA's phone to hit the app directly via
     * MacroDroid, which isn't in real use yet, so it stayed permanently
     * empty and made these two cards always show blank/meaningless numbers.
     * Deliberately real-data-only, no fallback estimate for hours with no
     * synced recording yet (unlike TsaPerformanceController's own blended
     * OPT/AHT, which mixes in a 3-min/call guess) — an explicit choice so
     * these cards never show a partly-fabricated number.
     */
    public function index(Request $request)
    {
        $user = Auth::user();

        $dateFrom = $request->filled('date_from')
            ? Carbon::parse($request->query('date_from'))->startOfDay()
            : today();
        $dateTo = $request->filled('date_to')
            ? Carbon::parse($request->query('date_to'))->endOfDay()
            : today()->copy()->endOfDay();
        if ($dateTo->lt($dateFrom)) {
            $dateTo = $dateFrom->copy()->endOfDay();
        }
        $isToday = $dateFrom->isToday() && $dateTo->isToday();

        // Same ALL/SH Naturals/Eyecare filter as TSD Reports' own Dashboard
        // (explicit request, 2026-08-17) — 'all' isn't a real config('teams')
        // key, handled as its own branch below, same convention as that page.
        $teamsConfig  = config('teams', []);
        $teams        = ['all' => 'ALL'] + array_map(fn ($t) => $t['name'], $teamsConfig);
        $selectedTeam = $request->input('team', 'all');
        if ($selectedTeam !== 'all' && !array_key_exists($selectedTeam, $teamsConfig)) {
            $selectedTeam = 'all';
        }
        // Leads don't carry team directly — resolved via their TSA's team.
        // Unassigned leads (no tsa_id yet) can never match a specific team
        // filter, same as TSD Reports' own Unmatched Orders exclusion.
        $teamTsaIds = $selectedTeam !== 'all'
            ? TsaShift::where('team', $teamsConfig[$selectedTeam]['order_team'])->pluck('id')
            : null;

        // Total Leads / Total Catered Leads — explicit request (2026-08-18):
        // replaces the previous Assigned/Called/Overdue/Callbacks/Unassigned/
        // Upsells funnel row with the 5-card set from the KPI-dashboard
        // reference image (TSA Log In, Total Leads, Total Catered Leads,
        // AHT, Unproductive Time). "Total Leads" = every lead that entered
        // the system in the picked range (pancake_created_at, same anchor
        // the old Unassigned card used), regardless of current status —
        // unlike Assigned/Called before it, this one's meant to count
        // everything. "Catered" = actually called (called_at in range,
        // status now 'called') — same definition the old Called card used,
        // just relabeled to match the reference's own term for it.
        $totalLeadsQuery   = Lead::whereBetween('pancake_created_at', [$dateFrom, $dateTo]);
        $totalCateredQuery = Lead::where('status', 'called')->whereBetween('called_at', [$dateFrom, $dateTo]);
        if (!$user->isAtLeastAdmin()) {
            $totalLeadsQuery->where('tsa_id', $user->tsa_id);
            $totalCateredQuery->where('tsa_id', $user->tsa_id);
        } elseif ($teamTsaIds !== null) {
            $totalLeadsQuery->whereIn('tsa_id', $teamTsaIds);
            $totalCateredQuery->whereIn('tsa_id', $teamTsaIds);
        }
        $totalLeads        = $totalLeadsQuery->count();
        $totalCateredLeads = $totalCateredQuery->count();
        $cateredRate        = $totalLeads > 0 ? round($totalCateredLeads / $totalLeads * 100, 1) : null;

        // AHT & Unproductive Time — real per-hour call-duration totals
        // synced from Google Drive (CallRecordingHour, see
        // SyncCallRecordings), not CallEvent (see this method's own doc
        // comment for why). Scoped the same self/team/all way as the two
        // counts above, using TsaShift rows (not Lead rows) since a TSA can
        // be "in scope" here with zero leads and still have logged calls
        // (or vice versa). Keyed by tsa_key, not tsa_id — CallRecordingHour
        // has no tsa_id column (see its own migration).
        $scopeTsasQuery = TsaShift::query();
        if (!$user->isAtLeastAdmin()) {
            $scopeTsasQuery->where('id', $user->tsa_id);
        } elseif ($teamTsaIds !== null) {
            $scopeTsasQuery->whereIn('id', $teamTsaIds);
        }
        $scopeTsas    = $scopeTsasQuery->with('restDays')->get();
        $scopeTsaKeys = $scopeTsas->pluck('tsa_key');

        $rangeRecordingHours = CallRecordingHour::whereIn('tsa_key', $scopeTsaKeys)
            ->whereDate('date', '>=', $dateFrom)
            ->whereDate('date', '<=', $dateTo)
            ->get();

        // AHT = total real seconds / total real calls (a true pooled
        // average across every synced hour), not an average-of-per-hour-
        // averages — same reasoning the TOTAL row below already uses for
        // the per-TSA table's own AHT.
        $rangeRealCalls = $rangeRecordingHours->sum('call_count');
        $ahtSeconds = $rangeRealCalls > 0
            ? (int) round($rangeRecordingHours->sum('total_seconds') / $rangeRealCalls)
            : null;

        // Per TSA: working days in range (TsaShift::isOffOn(), same rest-day
        // rule round-robin assignment already respects) x the same flat
        // 440min/day shift constant AnalyticsController uses, minus that
        // TSA's own real synced call duration in the range — then averaged
        // across the roster in scope for one team-wide number.
        $avgUnproductiveMinutes = null;
        if ($scopeTsas->isNotEmpty()) {
            $perTsaUnproductive = $scopeTsas->map(function (TsaShift $tsa) use ($rangeRecordingHours, $dateFrom, $dateTo) {
                $workingDays = 0;
                for ($day = $dateFrom->copy()->startOfDay(); $day->lte($dateTo); $day->addDay()) {
                    if (!$tsa->isOffOn($day)) {
                        $workingDays++;
                    }
                }
                $realSeconds = $rangeRecordingHours->where('tsa_key', $tsa->tsa_key)->sum('total_seconds');
                return max(0, $workingDays * 440 - $realSeconds / 60);
            });
            $avgUnproductiveMinutes = $perTsaUnproductive->avg();
        }

        $formatMmSs = function (?float $totalSeconds): string {
            if ($totalSeconds === null) {
                return '—';
            }
            $totalSeconds = (int) round($totalSeconds);
            return sprintf('%02d:%02d', intdiv($totalSeconds, 60), $totalSeconds % 60);
        };
        $ahtDisplay          = $formatMmSs($ahtSeconds);
        $unproductiveDisplay = $formatMmSs($avgUnproductiveMinutes !== null ? $avgUnproductiveMinutes * 60 : null);

        // TSA status board — every TSA regardless of who's viewing (not
        // sensitive, and a TSA benefits from seeing who else is actually
        // logged in just as much as an admin does). Team filter narrows this
        // too, unlike TSD Reports' own live-status-equivalent widgets which
        // have none — this one genuinely can, since every TSA has exactly
        // one team.
        $tsasQuery = TsaShift::where('active', true)->orderBy('sort_order');
        if ($selectedTeam !== 'all') {
            $tsasQuery->where('team', $teamsConfig[$selectedTeam]['order_team']);
        }
        $tsas = $tsasQuery->get();

        // TSA Log In — how many of the (already team-filtered) roster above
        // are actually available for round-robin right now. Live, not
        // date-range-scoped, same reasoning as the TSA Status board itself.
        $tsaLoginCount = $tsas->where('status', TsaShift::STATUS_LOGIN)->count();

        // Round-robin risk — a product whose entire roster is active but
        // nobody on it is currently logged in will silently stop receiving
        // new leads (RoundRobinAssigner::next() returns null), with nothing
        // else in the app surfacing that until leads visibly start piling
        // up unassigned. Checked here so it's caught before that happens.
        $atRiskProductsQuery = Product::with('tsas');
        if ($selectedTeam !== 'all') {
            $atRiskProductsQuery->where('team', $teamsConfig[$selectedTeam]['order_team']);
        }
        $atRiskProducts = $atRiskProductsQuery->get()->filter(function ($product) {
            return $product->tsas->isNotEmpty()
                && $product->tsas->every(fn ($tsa) => !$tsa->active || !in_array($tsa->status, \App\Support\RoundRobinAssigner::ELIGIBLE_STATUSES, true));
        })->values();

        // TSA Performance Overview — explicit request (2026-08-18), replaces
        // the TSA Status + Recent Activity two-panel row with a single
        // full-roster table matching the KPI-dashboard reference image's own
        // bottom table: same per-TSA metrics as the 5 cards above (Total
        // Leads/Catered/AHT/Unproductive), broken out per TSA instead of
        // aggregated. Shown to every viewer regardless of role, same as the
        // TSA Status board it replaces (login status was already
        // roster-wide before this) — this follows the picked date range,
        // not scoped down to "just me" the way the KPI cards are for a
        // non-admin viewer, since a performance table with only one row
        // wouldn't be a team overview at all. Fetched once each and grouped
        // in-memory per TSA below (same pattern AnalyticsController's own
        // $rows uses) rather than N+1 queries per roster row.
        //
        // Anchored on assigned_at, NOT pancake_created_at (fixed 2026-08-18
        // — an admin spotted this table's Total Leads disagreeing with Leads
        // Setup's own "Assigned Today" for the same TSA/day): a per-TSA
        // breakdown is inherently "what did round-robin actually hand this
        // TSA," the same question TsaShift::leadsAssignedToday() (Leads
        // Setup's daily-cap column) and AnalyticsController's own $rows
        // already answer with assigned_at — pancake_created_at answers a
        // different question ("when did the underlying Pancake order get
        // created"), which is what the aggregate Total Leads Today KPI card
        // above deliberately uses instead (it wants to count everything,
        // including still-unassigned leads with no assigned_at at all — see
        // that card's own comment). Catered is derived from this SAME
        // assigned_at-scoped set (status now 'called'), matching Analytics'
        // own 'called' definition exactly, rather than an independently
        // called_at-scoped query that could disagree with Analytics too.
        $tsaIds  = $tsas->pluck('id');
        $tsaKeys = $tsas->pluck('tsa_key');

        $perfLeads = Lead::whereIn('tsa_id', $tsaIds)
            ->whereBetween('assigned_at', [$dateFrom, $dateTo])->get();
        $perfRecordingHours = CallRecordingHour::whereIn('tsa_key', $tsaKeys)
            ->whereDate('date', '>=', $dateFrom)
            ->whereDate('date', '<=', $dateTo)
            ->get();

        $tsaPerformance = $tsas->map(function (TsaShift $tsa) use ($perfLeads, $perfRecordingHours, $dateFrom, $dateTo, $formatMmSs) {
            $tsaLeads      = $perfLeads->where('tsa_id', $tsa->id);
            $tsaTotalLeads = $tsaLeads->count();
            $tsaCatered    = $tsaLeads->where('status', 'called')->count();
            $tsaHours      = $perfRecordingHours->where('tsa_key', $tsa->tsa_key);
            $tsaRealCalls  = $tsaHours->sum('call_count');
            $tsaAhtSeconds = $tsaRealCalls > 0 ? (int) round($tsaHours->sum('total_seconds') / $tsaRealCalls) : null;

            $workingDays = 0;
            for ($day = $dateFrom->copy()->startOfDay(); $day->lte($dateTo); $day->addDay()) {
                if (!$tsa->isOffOn($day)) {
                    $workingDays++;
                }
            }
            $tsaUnproductiveMinutes = max(0, $workingDays * 440 - $tsaHours->sum('total_seconds') / 60);

            return [
                'tsa'                 => $tsa,
                'totalLeads'          => $tsaTotalLeads,
                'catered'             => $tsaCatered,
                'ahtDisplay'          => $formatMmSs($tsaAhtSeconds),
                'unproductiveMinutes' => $tsaUnproductiveMinutes,
                'unproductiveDisplay' => $formatMmSs($tsaUnproductiveMinutes * 60),
                'cateredRate'         => $tsaTotalLeads > 0 ? round($tsaCatered / $tsaTotalLeads * 100, 1) : null,
            ];
        });

        // TOTAL row — sums for counts (a real roster-wide total), the true
        // pooled average (not an average-of-averages) for AHT so a
        // high-volume TSA isn't weighted the same as one with a single
        // call, a per-TSA average for Unproductive Time (it's inherently a
        // per-shift figure, summing it across people wouldn't mean
        // anything), and an overall rate (sum/sum) for Catered Leads Rate.
        $tsaPerformanceTotal = [
            'totalLeads'          => $tsaPerformance->sum('totalLeads'),
            'catered'             => $tsaPerformance->sum('catered'),
            'ahtDisplay'          => $formatMmSs($perfRecordingHours->sum('call_count') > 0
                ? $perfRecordingHours->sum('total_seconds') / $perfRecordingHours->sum('call_count')
                : null),
            'unproductiveDisplay' => $formatMmSs($tsaPerformance->isNotEmpty() ? $tsaPerformance->avg('unproductiveMinutes') * 60 : null),
            'cateredRate'         => $tsaPerformance->sum('totalLeads') > 0
                ? round($tsaPerformance->sum('catered') / $tsaPerformance->sum('totalLeads') * 100, 1)
                : null,
        ];

        // Chart payload — bar/donut reshape the same Total Leads/Catered
        // Leads numbers already in the KPI cards above (never a separate
        // source of truth). The AHT & Unproductive Time trend is its own
        // trailing-7-day window, deliberately NOT scoped to the picked date
        // range (same "always live, not a historical snapshot" reasoning as
        // the TSA Status board above) — same CallRecordingHour source and
        // per-day version of the working-days formula used for the cards,
        // just run once per day instead of once for the whole range. A TSA
        // off that day is excluded from that day's average entirely
        // (there's no "unproductive" shift to measure), not counted as 0.
        $trendDays = collect(range(6, 0))->map(fn ($i) => today()->subDays($i));
        $trendFrom = $trendDays->first()->copy()->startOfDay();
        $trendTo   = $trendDays->last()->copy()->endOfDay();

        $trendRecordingHours = CallRecordingHour::whereIn('tsa_key', $scopeTsaKeys)
            ->whereDate('date', '>=', $trendFrom)
            ->whereDate('date', '<=', $trendTo)
            ->get();

        $trendAht = $trendDays->map(function ($day) use ($trendRecordingHours) {
            $dayHours = $trendRecordingHours->filter(fn ($r) => $r->date->isSameDay($day));
            $dayCalls = $dayHours->sum('call_count');
            return $dayCalls > 0 ? (int) round($dayHours->sum('total_seconds') / $dayCalls) : null;
        })->values();

        $trendUnproductive = $trendDays->map(function ($day) use ($trendRecordingHours, $scopeTsas) {
            $dayHours = $trendRecordingHours->filter(fn ($r) => $r->date->isSameDay($day));
            $working  = $scopeTsas->reject(fn (TsaShift $tsa) => $tsa->isOffOn($day));
            if ($working->isEmpty()) {
                return null;
            }
            $perTsa = $working->map(fn (TsaShift $tsa) => max(0, 440 - $dayHours->where('tsa_key', $tsa->tsa_key)->sum('total_seconds') / 60));
            return round($perTsa->avg(), 1);
        })->values();

        $chartData = [
            'leadsOverview' => [
                'labels'  => ['Total Leads', 'Total Catered Leads'],
                'total'   => $totalLeads,
                'catered' => $totalCateredLeads,
            ],
            'hasOverviewData' => $totalLeads > 0,
            'cateredRate'     => $cateredRate,
            'trend' => [
                'labels'       => $trendDays->map(fn ($d) => $d->format('M j'))->values(),
                'ahtSeconds'   => $trendAht,
                'unproductive' => $trendUnproductive,
            ],
            'hasTrendData' => $trendRecordingHours->isNotEmpty(),
        ];

        return view('calls.dashboard', [
            'tsaLoginCount'          => $tsaLoginCount,
            'totalLeads'             => $totalLeads,
            'totalCateredLeads'      => $totalCateredLeads,
            'ahtDisplay'             => $ahtDisplay,
            'unproductiveDisplay'    => $unproductiveDisplay,
            'tsas'                   => $tsas,
            'atRiskProducts'         => $atRiskProducts,
            'tsaPerformance'         => $tsaPerformance,
            'tsaPerformanceTotal'    => $tsaPerformanceTotal,
            'statuses'               => TsaShift::STATUSES,
            'dateFrom'               => $dateFrom,
            'dateTo'                 => $dateTo,
            'isToday'                => $isToday,
            'teams'                  => $teams,
            'selectedTeam'           => $selectedTeam,
            'chartData'              => $chartData,
        ]);
    }

    /**
     * Sync — kicks off pancake:sync-leads (Call Tracker's own lead sync,
     * distinct from TSD Reports' pancake:sync-today order sync) as a
     * detached background process and returns instantly, same pattern as
     * TSD Reports' own DashboardController::sync()/syncStatus() — this
     * container has only one web worker, so running it synchronously would
     * block the request long enough for the platform's own health check to
     * kill the instance mid-sync on a big run.
     */
    public function sync(Request $request)
    {
        $lastRunIdBeforeSync = LeadSyncRun::max('id') ?? 0;

        $php     = escapeshellarg(PHP_BINARY);
        $artisan = escapeshellarg(base_path('artisan'));
        $logFile = escapeshellarg(storage_path('logs/manual-lead-sync.log'));
        exec("{$php} {$artisan} pancake:sync-leads >> {$logFile} 2>&1 &");

        return response()->json(['since' => $lastRunIdBeforeSync]);
    }

    /** Polled by the Sync button (calls.dashboard) after sync() above kicks
     *  the actual work off in the background — see that method's doc
     *  comment. 'done' stays false until a new LeadSyncRun row (the command's
     *  own recordRun()) has landed. */
    public function syncStatus(Request $request)
    {
        $since = (int) $request->input('since', 0);

        $run = LeadSyncRun::where('id', '>', $since)->orderBy('id')->first();
        if (!$run) {
            return response()->json(['done' => false]);
        }

        return response()->json([
            'done'          => true,
            'success'       => $run->success,
            'new_leads'     => $run->new_leads,
            'total_fetched' => $run->total_fetched,
            'error_message' => $run->error_message,
        ]);
    }
}
