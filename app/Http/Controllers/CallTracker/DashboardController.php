<?php

namespace App\Http\Controllers\CallTracker;

use App\Http\Controllers\Controller;
use App\Models\Lead;
use App\Models\LeadActivity;
use App\Models\LeadSyncRun;
use App\Models\Product;
use App\Models\TsaShift;
use App\Models\TsaStatusLog;
use Carbon\Carbon;
use Illuminate\Http\Request;
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
     * Deliberately does NOT duplicate TSD Reports' own call-volume/pick-up-
     * rate analytics — that's already the source of truth for those
     * numbers. What's shown here is what only Call Tracker itself can
     * know: each TSA's live status and whether round-robin is actually
     * able to route anything right now, PLUS a date-scoped lead funnel
     * (2026-08-10: topbar date-range picker, same partial/pattern as every
     * other page here).
     *
     * Every KPI card moves with the picked range (2026-08-10: explicit
     * request — all six, not just Called/Upsells), each scoped by whichever
     * timestamp makes it a real funnel-stage-entered-during-this-period
     * metric rather than a live count: Assigned/Overdue by assigned_at,
     * Called by called_at, Unassigned by pancake_created_at (when it
     * entered the system), Callbacks by callback_at, Upsells by
     * created_at. The TSA Status board and At-Risk Products stay live
     * regardless of the picker — they're "who's logged in / can route
     * right now," not KPI cards, and there's no historical snapshot of
     * either to show for a past date.
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

        $assignedQuery = Lead::where('status', 'assigned')->whereBetween('assigned_at', [$dateFrom, $dateTo]);
        $calledQuery   = Lead::where('status', 'called')->whereBetween('called_at', [$dateFrom, $dateTo]);
        $callbackQuery = Lead::whereNotNull('callback_at')->whereBetween('callback_at', [$dateFrom, $dateTo]);
        $unassignedQuery = Lead::where('status', 'unassigned')->whereBetween('pancake_created_at', [$dateFrom, $dateTo]);

        if (!$user->isAtLeastAdmin()) {
            $assignedQuery->where('tsa_id', $user->tsa_id);
            $calledQuery->where('tsa_id', $user->tsa_id);
            $callbackQuery->where('tsa_id', $user->tsa_id);
        } elseif ($teamTsaIds !== null) {
            $assignedQuery->whereIn('tsa_id', $teamTsaIds);
            $calledQuery->whereIn('tsa_id', $teamTsaIds);
            $callbackQuery->whereIn('tsa_id', $teamTsaIds);
            $unassignedQuery->whereRaw('1 = 0'); // no team could ever own an unassigned lead
        }

        $overdueQuery = (clone $assignedQuery)
            ->where('assigned_at', '<=', now()->subHours(LeadController::overdueThresholdHours()));

        $funnel = [
            'assigned'    => $assignedQuery->count(),
            'called'      => $calledQuery->count(),
            'overdue'     => $overdueQuery->count(),
            'callbacks'   => $callbackQuery->count(),
            'unassigned'  => $user->isAtLeastAdmin() ? $unassignedQuery->count() : 0,
        ];

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
                && $product->tsas->every(fn ($tsa) => !$tsa->active || $tsa->status !== TsaShift::STATUS_LOGIN);
        })->values();

        $rangeUpsells = LeadActivity::where('type', 'upsell_added')
            ->whereNotNull('amount')
            ->whereBetween('created_at', [$dateFrom, $dateTo]);
        if ($teamTsaIds !== null) {
            $rangeUpsells->whereHas('lead', fn ($q) => $q->whereIn('tsa_id', $teamTsaIds));
        }

        $upsellStats = [
            'count'  => (clone $rangeUpsells)->count(),
            'amount' => (clone $rangeUpsells)->sum('amount'),
        ];

        // Combined recent-activity feed — lead activity and TSA status
        // changes are two separate tables (different subjects entirely),
        // merged here into one timeline sorted by real recency rather than
        // making an admin check two separate log pages to see "what just
        // happened." Scoped to the picked range so a past date shows that
        // day's activity instead of always the newest 15 ever.
        $recentLeadActivityQuery = LeadActivity::with(['lead', 'user'])
            ->whereBetween('created_at', [$dateFrom, $dateTo]);
        if ($teamTsaIds !== null) {
            $recentLeadActivityQuery->whereHas('lead', fn ($q) => $q->whereIn('tsa_id', $teamTsaIds));
        }
        $recentLeadActivity = $recentLeadActivityQuery
            ->latest('created_at')->limit(15)->get()
            ->map(fn ($a) => ['at' => $a->created_at, 'description' => $a->description, 'kind' => 'lead']);

        $recentStatusChangesQuery = TsaStatusLog::with('tsa')
            ->whereBetween('created_at', [$dateFrom, $dateTo]);
        if ($teamTsaIds !== null) {
            $recentStatusChangesQuery->whereIn('tsa_id', $teamTsaIds);
        }
        $recentStatusChanges = $recentStatusChangesQuery
            ->latest('created_at')->limit(15)->get()
            ->map(fn ($s) => [
                'at'          => $s->created_at,
                'description' => ($s->tsa->display_name ?? 'A TSA') . ' switched to ' . (TsaShift::STATUSES[$s->status]['label'] ?? $s->status),
                'kind'        => 'status',
            ]);

        $recentActivity = $recentLeadActivity->concat($recentStatusChanges)
            ->sortByDesc('at')->take(15)->values();

        return view('calls.dashboard', [
            'funnel'          => $funnel,
            'tsas'            => $tsas,
            'atRiskProducts'  => $atRiskProducts,
            'upsellStats'     => $upsellStats,
            'recentActivity'  => $recentActivity,
            'statuses'        => TsaShift::STATUSES,
            'dateFrom'        => $dateFrom,
            'dateTo'          => $dateTo,
            'isToday'         => $isToday,
            'teams'           => $teams,
            'selectedTeam'    => $selectedTeam,
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
