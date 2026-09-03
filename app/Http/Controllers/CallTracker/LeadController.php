<?php

namespace App\Http\Controllers\CallTracker;

use App\Http\Controllers\Controller;
use App\Models\CallEvent;
use App\Models\Lead;
use App\Models\LeadActivity;
use App\Models\Order;
use App\Models\Product;
use App\Models\Setting;
use App\Models\TsaShift;
use App\Support\GoogleDriveClient;
use App\Support\PancakeConversationApi;
use App\Support\PancakeOrderTagApi;
use App\Support\PancakeProductApi;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;

/**
 * Ported from call-tracker (merged into one app 2026-08-12): Tsa -> TsaShift,
 * ActivityLog writes now go through tsd-reports' own App\Support\ActivityLogger
 * (this controller itself never wrote to it directly — see LeadActivity::log()
 * instead, which is unchanged). isAdmin() -> isAtLeastAdmin() throughout: this
 * app's User::isAdmin() only matches role==='admin', not 'super_admin', which
 * would silently exclude super admins from "sees every TSA's leads" — see
 * User::isAtLeastAdmin(), the same helper SearchController already uses for
 * this exact distinction.
 *
 * NOTE: searchTags()/searchProducts()/addUpsell()/conversation()/
 * updateDisposition() depend on App\Support\PancakeOrderTagApi /
 * PancakeConversationApi / PancakeProductApi, which are ported in Phase 3, not
 * this phase — those actions will fail to resolve (class not found) until
 * Phase 3 lands. Flagged in the Phase 2 report.
 */
class LeadController extends Controller
{
    /** Disposition keywords that mean "we didn't actually reach/confirm this
     *  lead" and so need a follow-up attempt — see updateDisposition()'s own
     *  comment on why Unattended/Not Answering join Call Back here. Matched
     *  case-insensitively as a substring, same as the keywords list itself. */
    private const CALLBACK_TRIGGER_KEYWORDS = ['call back', 'unattended', 'not answering'];

    public static function overdueThresholdHours(): int
    {
        return max(1, (int) Setting::get('overdue_threshold_hours', 4));
    }

    /** Valid values for the status filter re-added below — kept as its own
     *  const (not inline in index()) so the controller and the blade filter
     *  UI can never list a status the query itself doesn't recognize. */
    private const STATUS_FILTER_VALUES = ['unassigned', 'assigned', 'called'];

    public function index(Request $request, PancakeOrderTagApi $api)
    {
        $user = Auth::user();
        $view = $request->string('view')->toString(); // '', 'overdue', 'callbacks'

        // Pinned leads float to the top regardless of view/sort — explicit
        // request, 2026-08-17 — added as the FIRST orderBy so every other
        // ordering below (latest/assigned_at/callback_at) stays intact as
        // the secondary sort within pinned vs unpinned.
        $query = Lead::with(['product', 'tsa'])
            ->orderByRaw('pinned_at IS NULL')
            ->latest('pancake_created_at');

        // A TSA only ever sees their own queue; an admin can optionally
        // narrow to one TSA via ?tsa=, defaulting to everyone's.
        if (!$user->isAtLeastAdmin()) {
            $query->where('tsa_id', $user->tsa_id);
        } elseif ($request->filled('tsa')) {
            $query->where('tsa_id', $request->integer('tsa'));
        }

        // Product filter, scoped by team (explicit request, 2026-08-28) —
        // Product::team is the same literal order_team string TsaShift::team
        // already uses (see config('teams')'s own doc comment), so "which
        // products does this team's TSAs handle" is just Product::where(
        // 'team', ...), no join through product_tsa needed. A specific
        // product implies its own team already, so it wins outright over a
        // (possibly stale/mismatched, e.g. leftover in the URL after
        // switching teams) team param rather than ANDing both and risking a
        // silently-empty result.
        $selectedTeam = $request->string('team')->toString();
        if ($request->filled('product')) {
            $query->where('product_id', $request->integer('product'));
        } elseif ($selectedTeam) {
            $query->whereHas('product', fn ($q) => $q->where('team', $selectedTeam));
        }

        // One shared date window for every view (explicit request,
        // 2026-08-15) — whatever's picked via date_from/date_to, defaulting
        // to today when nothing's picked. Mirrors TSD Reports' own "Excess"
        // metric, which always reads against whichever period is selected
        // rather than a fixed "today": picking yesterday on Leads and
        // clicking Overdue now shows yesterday's overdue, not always today's,
        // and it resets at midnight the same way the sidebar badge does when
        // nothing's explicitly picked.
        $dateFromInput = $request->string('date_from')->toString();
        $dateToInput   = $request->string('date_to')->toString();
        $rangeFrom = $dateFromInput ? Carbon::parse($dateFromInput)->startOfDay() : today();
        $rangeTo   = $dateToInput ? Carbon::parse($dateToInput)->endOfDay() : today()->copy()->endOfDay();
        if ($rangeTo->lt($rangeFrom)) {
            $rangeTo = $rangeFrom->copy()->endOfDay();
        }

        if ($view === 'overdue') {
            // Assigned but nobody's called it yet, and it's been sitting
            // long enough that this is no longer "hasn't gotten to it yet" —
            // exactly the gap that let a lead sit uncalled for hours before
            // anyone noticed.
            $query->where('status', 'assigned')
                ->whereBetween('assigned_at', [$rangeFrom, $rangeTo])
                ->where('assigned_at', '<=', now()->subHours(self::overdueThresholdHours()))
                ->orderBy('assigned_at');
        } elseif ($view === 'callbacks') {
            // A TSA promised to call back by a specific time — due now or
            // already past due, not "someday in the future".
            $query->whereNotNull('callback_at')
                ->whereBetween('callback_at', [$rangeFrom, $rangeTo])
                ->where('callback_at', '<=', now())
                ->orderBy('callback_at');
        }
        // Status filter, brought back (explicit request, 2026-08-21) — the
        // old Assigned/Called/Unassigned filter that lived here was removed
        // 2026-08-20 in favor of a status-CHANGE control that looks similar
        // but does something different (see leads/index.blade.php's own
        // comment on that control). Only applies to the default Leads view
        // — Overdue/Callbacks already have their own implicit status
        // meaning (Overdue is always status=assigned; a callback can be due
        // on a lead of any status), so a second, independent status filter
        // there would just be confusing, not useful.
        //
        // Catered/Uncatered added (explicit request, 2026-08-26) — same
        // "Catered" language this app already uses on the Call Tracker
        // Dashboard KPI (DashboardController::index(), Lead::where('status',
        // 'called')) rather than TSD Reports' own stricter Order-based
        // Catered/Excess definition (ProductPerformance::tally()), which
        // needs a recognized disposition keyword match, not just any
        // logged outcome — those are two different metrics on two
        // different models, and this filter is scoped to Lead, not Order.
        // 'called' and 'catered' end up doing the exact same where() on
        // this model (LeadController::updateDisposition() always writes
        // status and disposition together — a Lead can never be 'called'
        // with a null disposition or vice versa), so this is a second,
        // more-familiar label for a value the dropdown already offered,
        // plus the one genuinely new option: Uncatered, which the old
        // three-way Unassigned/Assigned/Called split had no single value
        // for (previously required picking Unassigned OR Assigned
        // separately, or eyeballing "All Statuses" minus Called by hand).
        $status = $request->string('status')->toString();
        if (!$view && in_array($status, self::STATUS_FILTER_VALUES, true)) {
            $query->where('status', $status);
        } elseif (!$view && $status === 'catered') {
            $query->where('status', 'called');
        } elseif (!$view && $status === 'uncatered') {
            $query->where('status', '!=', 'called');
        }

        // Search re-opened to a TSA too (explicit follow-up, 2026-09-02:
        // "add product, status, search in the tsa(normal user) in leads") —
        // was admin-only since this filter form's very first version; no
        // extra scoping needed here beyond what already exists above (line
        // 73-74 already restricts the base $query to only this TSA's own
        // leads before this ever runs), so a TSA searching can only ever
        // search within their own queue, never anyone else's.
        if ($request->filled('q')) {
            $q = trim($request->string('q'));
            $query->where(function ($sub) use ($q) {
                $sub->where('customer_name', 'like', "%{$q}%")
                    ->orWhere('phone_number', 'like', "%{$q}%")
                    ->orWhere('pancake_order_id', 'like', "%{$q}%");
            });
        }

        // The default view now always has a window too — defaulting to
        // today, same as Overdue/Callbacks above, rather than "every lead
        // ever" when nothing's explicitly picked. Explicit request,
        // 2026-08-26: "all of the newly created order in the POS should be
        // only in today" — real examples (#1347599 and others, created days
        // earlier) were sitting in today's queue purely because this view
        // never had a default cutoff. Deliberately keyed off *creation*
        // date, NOT order status: a TSA changing an order's status after a
        // call — Ordered, Awaiting Stock, Confirmed, whatever it becomes —
        // must never make the lead disappear from their own queue, only its
        // date does. An explicit date_from/date_to pick still overrides this
        // and can widen the window to any past day on purpose.
        //
        // Filters on COALESCE(assigned_at, pancake_created_at) rather than
        // plain pancake_created_at (root-caused 2026-08-15: the sidebar
        // badge and Leads Setup both count "assigned today" via assigned_at,
        // but this list was filtering by creation date — a lead created
        // yesterday and picked up by round-robin TODAY showed in the
        // badge's "17" but not in this filtered list's "7"). A lead with
        // neither set at all (no real sync data) still shows — fail-open,
        // same convention used elsewhere in this method, rather than hiding
        // something we can't actually judge.
        if (!$view) {
            $query->where(function ($q) use ($rangeFrom, $rangeTo) {
                $q->whereRaw('COALESCE(assigned_at, pancake_created_at) BETWEEN ? AND ?', [$rangeFrom, $rangeTo])
                    ->orWhere(function ($q2) {
                        $q2->whereNull('assigned_at')->whereNull('pancake_created_at');
                    });
            });
        }

        $leads = $query->paginate(30)->withQueryString();

        // Order status pill + real tag chips (explicit request, 2026-08-22, mirrors
        // Pancake POS's own Status control and tag chips) — both read from the
        // locally-synced orders table, same "trust the periodic sync" convention
        // every other bulk view in this app already follows (Leads Report, TSA
        // Performance, etc.), rather than a live per-row Pancake fetch: this table
        // can hold 30 rows and gets polled every 15s (calls.js' pollLeadsTable()),
        // so a live fetch here would mean up to 30 extra Pancake API calls on every
        // single poll tick.
        $orders = Order::whereIn('pancake_order_id', $leads->pluck('pancake_order_id')->filter())
            ->get(['pancake_order_id', 'status_code', 'raw_tags', 'base_product'])
            ->keyBy('pancake_order_id');
        $orderStatuses = $orders->map->status_code;
        $orderTags     = $orders->map(fn ($o) => $o->raw_tags ?? []);

        // Variation badge (explicit request, 2026-09-03: "5 Pterygium Drops"
        // like the POS shows) — base_product is already the exact variation
        // label Pancake itself uses (SyncTodayOrders::extractUpsellProduct(),
        // index 0's own variation_info.name for a normal single-item order),
        // synced periodically same as status/tags right above — no live
        // Pancake call needed, unlike the earlier per-row getOrderDetail()
        // attempt that made this page slow to load and was reverted.
        $orderBaseProducts = $orders->map(fn ($o) => $o->base_product);

        // Real tag catalog colors (explicit request, 2026-08-22) — matches each
        // real tag's dot to the same color Pancake POS itself uses, not a generic
        // gray. listTags() is cached 5 minutes (see PancakeOrderTagApi's own doc
        // comment), so this is cheap even on every 15s poll.
        $tagColors = collect($api->listTags())
            ->filter(fn ($t) => !empty($t['name']))
            ->mapWithKeys(fn ($t) => [strtolower($t['name']) => $t['color'] ?? '#94a3b8']);

        $data = [
            'leads'                 => $leads,
            'orderStatuses'         => $orderStatuses,
            'orderTags'             => $orderTags,
            'orderBaseProducts'     => $orderBaseProducts,
            'tagColors'             => $tagColors,
            'tsas'                  => $user->isAtLeastAdmin() ? TsaShift::orderBy('sort_order')->get() : collect(),
            'selectedTsa'           => $request->integer('tsa'),
            'teams'                 => collect(config('teams'))->pluck('order_team')->all(),
            'selectedTeam'          => $selectedTeam,
            // Options narrow to the picked team (all products when no team
            // is picked) — same "the dropdown can never offer something the
            // query itself would reject" guarantee STATUS_FILTER_VALUES
            // already gives the status filter above. A TSA (explicit
            // follow-up, 2026-09-02: "add product, status, search in the
            // tsa(normal user) in leads") gets a narrower list scoped to
            // products actually appearing on THEIR OWN leads, not their
            // whole team's catalog — an admin's product list can offer
            // something zero of their currently-visible leads use (they see
            // the whole team), but a TSA's own queue is small enough that
            // offering a product with nothing to show would just be
            // confusing empty options.
            'products'              => $user->isAtLeastAdmin()
                ? Product::orderBy('sort_order')->when($selectedTeam, fn ($q) => $q->where('team', $selectedTeam))->get()
                : Product::whereIn('id', Lead::where('tsa_id', $user->tsa_id)->whereNotNull('product_id')->distinct()->pluck('product_id'))
                    ->orderBy('sort_order')->get(),
            'selectedProduct'       => $request->integer('product'),
            'q'                     => $request->string('q')->toString(),
            'view'                  => $view,
            'selectedStatus'        => $status,
            'dateFrom'              => $dateFromInput ?: $rangeFrom->toDateString(),
            'dateTo'                => $dateToInput ?: $rangeTo->toDateString(),
            'overdueThresholdHours' => self::overdueThresholdHours(),
        ];

        // The "real-time" leads table polls this same URL+filters every few
        // seconds (see calls.js) and swaps in just the table — re-rendering
        // the whole layout on every poll would be wasteful and would also
        // reset scroll position/focus for no reason.
        if ($request->header('X-Table-Refresh')) {
            return view('calls.leads._table', $data);
        }

        return view('calls.leads.index', $data);
    }

    /** Explicit request (2026-08-25): "same UI as in the POS ... pop up like
     *  a modal" — the Leads table now opens this content in a modal
     *  (openLeadModal(), see calls.js) via a fetch with an X-Table-Refresh
     *  header, same AJAX-partial convention TSA Management's own table already
     *  uses. A plain GET (no header — direct link, bookmark, right-click-open-
     *  in-new-tab) still renders the full page, unchanged.
     *
     *  $order — the matching row in the separate `orders` table (same
     *  pancake_order_id, a different local sync pipeline than Leads — Lead
     *  itself never stores an amount/bundle description) — feeds the
     *  product/price card in calls/leads/_detail.blade.php. Null when that
     *  order hasn't synced locally yet; the view falls back to the Product
     *  catalog name alone.
     *
     *  $liveOrder — Pancake's real current items[]/tags[] for this order
     *  (PancakeOrderTagApi::getOrderDetail(), explicit follow-up request,
     *  2026-08-25: "see too the current upsell in the pos and also the
     *  current pos tags") — $order above only ever has ONE computed
     *  summary line (deliberately the isolated upsell's own info for an
     *  upsell order, not the base item — see extractUpsellProduct()'s own
     *  comment), so a genuine multi-item order had no way to show the base
     *  item's own line/price alongside it, and raw tags were never
     *  persisted locally at all. Null when Pancake isn't reachable (not
     *  configured, timeout, etc.) — the view falls back to $order's single
     *  summarized line same as before this fetch existed. */
    public function show(Request $request, Lead $lead, PancakeOrderTagApi $api)
    {
        $user = Auth::user();

        if (!$user->isAtLeastAdmin() && $lead->tsa_id !== $user->tsa_id) {
            abort(403);
        }

        $lead->load(['product', 'tsa', 'calledBy', 'activities.user']);
        $order = $lead->pancake_order_id ? Order::where('pancake_order_id', $lead->pancake_order_id)->first() : null;
        $liveOrder = $lead->pancake_order_id ? $api->getOrderDetail($lead->pancake_order_id) : null;

        if ($request->header('X-Table-Refresh')) {
            return view('calls.leads._detail', ['lead' => $lead, 'order' => $order, 'liveOrder' => $liveOrder]);
        }

        return view('calls.leads.show', ['lead' => $lead, 'order' => $order, 'liveOrder' => $liveOrder]);
    }

    /**
     * JSON-wrapped HTML for just the History card (explicit follow-up: "i
     * want real time like in the pos in all leads detail history") —
     * polled every 8s while a lead's modal is open (initHistoryPanel() in
     * calls.js, same cadence as the existing Pancake Notes poll), so a
     * status/tag/note/delivery/item change made directly in Pancake POS
     * or by another admin shows up without the TSA closing and reopening
     * the lead. Deliberately scoped to ONLY this card, not the whole
     * modal — refreshing Products/Tags/Delivery too would risk wiping out
     * whatever a TSA is actively mid-edit on those fields the moment a
     * poll lands (see the earlier scoping decision this follow-up made:
     * "just the History panel," not "the whole lead modal").
     */
    public function history(Lead $lead, PancakeOrderTagApi $api)
    {
        $user = Auth::user();

        if (!$user->isAtLeastAdmin() && $lead->tsa_id !== $user->tsa_id) {
            abort(403);
        }

        $lead->load('activities');
        $liveOrder = $lead->pancake_order_id ? $api->getOrderDetail($lead->pancake_order_id) : null;

        return response()->json([
            'success' => true,
            'html' => view('calls.leads._history', ['lead' => $lead, 'liveOrder' => $liveOrder])->render(),
        ]);
    }

    /** Pin/unpin — same ownership guard as show() (a TSA only manages their
     *  own leads, an admin can manage any). Sorts to the top of the Leads
     *  table via index()'s own orderByRaw('pinned_at IS NULL') above. */
    public function togglePin(Lead $lead)
    {
        $user = Auth::user();

        if (!$user->isAtLeastAdmin() && $lead->tsa_id !== $user->tsa_id) {
            abort(403);
        }

        $lead->update(['pinned_at' => $lead->pinned_at ? null : now()]);

        return back();
    }

    /**
     * Reassigns a lead to a different TSA — admin-only (the TSA column
     * itself is already admin-only, see leads/_table.blade.php). Resets
     * assigned_at to now() so the new TSA's overdue-threshold clock starts
     * fresh rather than inheriting however long the lead already sat with
     * the old TSA, and so this lead attributes to the NEW tsa on today's
     * TSA Performance/Dashboard tables (both anchored on assigned_at, same
     * convention as round-robin assignment itself). A still-unassigned lead
     * flips to 'assigned' like a normal round-robin pickup would; an
     * already-called lead keeps its status/disposition as-is — transferring
     * ownership doesn't erase what was already logged.
     */
    public function transfer(Request $request, Lead $lead, PancakeOrderTagApi $api)
    {
        $user = Auth::user();

        if (!$user->isAtLeastAdmin()) {
            abort(403);
        }

        $data = $request->validate([
            'tsa_id' => ['required', 'integer', 'exists:tsa_shifts,id'],
        ]);

        $newTsa = TsaShift::findOrFail($data['tsa_id']);

        if ($lead->tsa_id === $newTsa->id) {
            return response()->json(['success' => true, 'message' => "Already assigned to {$newTsa->display_name}."]);
        }

        $fromLabel = $lead->tsa?->display_name ?? 'Unassigned';

        $lead->update([
            'tsa_id'      => $newTsa->id,
            'assigned_at' => now(),
            'status'      => $lead->status === 'unassigned' ? 'assigned' : $lead->status,
        ]);

        LeadActivity::log($lead, 'transferred', "Transferred from {$fromLabel} to {$newTsa->display_name} by {$user->name}.", $user);

        // New owner's own POS name tag, same as any other assignment path —
        // see tagTsaOnPancakeOrder()'s own doc comment.
        self::tagTsaOnPancakeOrder($lead, $api);

        return response()->json(['success' => true, 'message' => "Transferred to {$newTsa->display_name}."]);
    }

    /**
     * Bulk version of togglePin() — explicit request, 2026-08-26, "like
     * that for the example" (Product Management's own checkbox + bulk-bar
     * pattern). Admin-only (explicit correction, same day: bulk actions in
     * general are admin-only, not just Transfer) — unlike the single-row
     * pin button, which a TSA can still use on their own leads via
     * togglePin() itself, untouched by this change. No LeadActivity
     * entries, matching togglePin() itself, which never logged pin/unpin
     * either.
     */
    public function bulkPin(Request $request)
    {
        $user = Auth::user();

        if (!$user->isAtLeastAdmin()) {
            abort(403);
        }

        $data = $request->validate([
            'lead_ids'   => ['required', 'array', 'min:1'],
            'lead_ids.*' => ['integer'],
            'pin'        => ['required', 'boolean'],
        ]);

        $count = Lead::whereIn('id', $data['lead_ids'])->count();
        Lead::whereIn('id', $data['lead_ids'])->update(['pinned_at' => $data['pin'] ? now() : null]);

        $verb = $data['pin'] ? 'Pinned' : 'Unpinned';
        $noun = \Illuminate\Support\Str::plural('lead', $count);
        return response()->json(['success' => true, 'message' => "{$verb} {$count} {$noun}."]);
    }

    /**
     * Bulk version of transfer() above — explicit request, 2026-08-26,
     * "like that for the example." Admin-only, same as the single-row
     * version (the TSA column itself is already admin-only). Logs one
     * LeadActivity per lead, same as the single-row version, so each
     * lead's own activity trail still shows the real transfer — a single
     * combined log entry would lose that per-lead history.
     */
    public function bulkTransfer(Request $request, PancakeOrderTagApi $api)
    {
        $user = Auth::user();

        if (!$user->isAtLeastAdmin()) {
            abort(403);
        }

        $data = $request->validate([
            'lead_ids'   => ['required', 'array', 'min:1'],
            'lead_ids.*' => ['integer'],
            'tsa_id'     => ['required', 'integer', 'exists:tsa_shifts,id'],
        ]);

        $newTsa = TsaShift::findOrFail($data['tsa_id']);
        $leads = Lead::whereIn('id', $data['lead_ids'])->get();

        $moved = 0;
        foreach ($leads as $lead) {
            if ($lead->tsa_id === $newTsa->id) {
                continue;
            }

            $fromLabel = $lead->tsa?->display_name ?? 'Unassigned';

            $lead->update([
                'tsa_id'      => $newTsa->id,
                'assigned_at' => now(),
                'status'      => $lead->status === 'unassigned' ? 'assigned' : $lead->status,
            ]);

            LeadActivity::log($lead, 'transferred', "Transferred from {$fromLabel} to {$newTsa->display_name} by {$user->name}.", $user);

            // New owner's own POS name tag, same as any other assignment
            // path — see tagTsaOnPancakeOrder()'s own doc comment.
            self::tagTsaOnPancakeOrder($lead, $api);

            $moved++;
        }

        $noun = \Illuminate\Support\Str::plural('lead', $moved);
        return response()->json(['success' => true, 'message' => "Transferred {$moved} {$noun} to {$newTsa->display_name}."]);
    }

    /**
     * JSON feed for the "listen to recording" popup (explicit request,
     * 2026-08-19) — lists every Drive recording matching this lead's phone
     * number in its assigned TSA's own Drive folder (see
     * SyncCallRecordings' own doc comment for the folder tree/filename
     * format this reads; matchingRecordingFiles() below is the same idea,
     * just matched by phone instead of by date). A lead can have more than
     * one match (e.g. a callback attempt), newest first.
     */
    public function recordings(Lead $lead, GoogleDriveClient $drive)
    {
        $user = Auth::user();

        if (!$user->isAtLeastAdmin() && $lead->tsa_id !== $user->tsa_id) {
            abort(403);
        }

        $files = $this->matchingRecordingFiles($lead, $drive);
        if ($files === null) {
            return response()->json(['success' => false, 'error' => "Google Drive isn't connected yet — set it up in Settings."]);
        }

        return response()->json(['success' => true, 'recordings' => collect($files)->map(fn ($f) => [
            'id'    => $f['id'],
            'label' => $this->recordingLabel($f['name']),
        ])->values()]);
    }

    /**
     * Streams one matched recording's actual audio bytes from Drive —
     * proxied through here rather than handing the browser a Drive URL
     * directly, since that would require exposing the shared Drive access
     * token client-side. Re-resolves and re-checks $fileId against this
     * lead's own TSA folder (not just trusted from the URL) so a TSA can't
     * swap in an arbitrary Drive file id belonging to a lead/TSA they don't
     * have access to.
     */
    /** Relays the browser's own Range header straight through to Drive and
     *  back (see GoogleDriveClient::downloadFileRanged()'s own doc comment
     *  for why this is required, not optional, for playback to work at
     *  all) — a real 206 Partial Content on a Range request, Accept-Ranges
     *  on every response so the <audio> element knows it CAN seek/range-
     *  request in the first place. */
    public function streamRecording(Request $request, Lead $lead, string $fileId, GoogleDriveClient $drive)
    {
        $user = Auth::user();

        if (!$user->isAtLeastAdmin() && $lead->tsa_id !== $user->tsa_id) {
            abort(403);
        }

        $files = $this->matchingRecordingFiles($lead, $drive);
        $file  = collect($files)->firstWhere('id', $fileId);
        if (!$file) {
            abort(404);
        }

        $token = $drive->accessToken();
        if (!$token) {
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

    /** Matches this lead's phone number against every recording filename in
     *  its TSA's own Drive folder — same "<phone> <date> <time>.m4a" format
     *  SyncCallRecordings::parseFilename() reads, just matched by phone
     *  instead of by date. Compares only the trailing 9 digits: a lead's
     *  stored phone_number and the phone's own recorder can format the same
     *  real number differently (with/without a leading 0 or +63), but the
     *  last 9 digits of a PH mobile number are stable either way. Returns
     *  null (not an empty array) when Drive isn't configured/reachable at
     *  all — distinct from "found the TSA's folder but genuinely no matches
     *  yet", which the caller needs to tell apart to show the right message. */
    private function matchingRecordingFiles(Lead $lead, GoogleDriveClient $drive): ?array
    {
        if (!$lead->tsa_id || !$lead->phone_number) {
            return [];
        }

        $token = $drive->accessToken();
        if (!$token) {
            return null;
        }

        // Best-guess month for the month-folder layer (see
        // GoogleDriveClient::resolveTsaFolder()'s own doc comment) — when
        // this lead was actually called, not just created, since that's
        // when its recording would have been filed; falls back to when the
        // lead itself was created for one never actually called yet (no
        // recording to find either way, but keeps this from crashing on a
        // null date).
        $folder = $drive->resolveTsaFolder($token, $lead->tsa, $lead->called_at ?? $lead->pancake_created_at);
        if (!$folder) {
            return [];
        }

        $digits = preg_replace('/[^0-9]/', '', $lead->phone_number);
        $last9  = substr($digits, -9);
        if (strlen($last9) < 9) {
            return [];
        }

        // Recurses through the TSA's day-subfolders instead of a flat
        // listChildren() (fixed 2026-08-25) — real day-subfolder naming is
        // inconsistent per TSA (confirmed live: "AUGUST 7" vs "August 13--
        // Recording uploaded"), so a flat listing on the TSA folder alone
        // only ever found day-FOLDERS, never the actual .m4a files inside
        // them — this silently never matched anything until now.
        return collect($drive->listFilesRecursively($token, $folder['id']))
            ->filter(fn ($f) => str_contains(preg_replace('/[^0-9]/', '', $f['name']), $last9))
            ->sortByDesc(fn ($f) => $this->parsedRecordingMoment($f['name'])?->timestamp ?? 0)
            ->values()
            ->all();
    }

    /** "<phone> 2026-08-19 14-30-05.m4a" -> a real Carbon instant, or null
     *  if the filename doesn't match the expected format at all. */
    private function parsedRecordingMoment(string $filename): ?Carbon
    {
        if (!preg_match('/(\d{4}-\d{2}-\d{2})\s+(\d{2})-(\d{2})-(\d{2})/', $filename, $m)) {
            return null;
        }
        return Carbon::createFromFormat('Y-m-d H:i:s', "{$m[1]} {$m[2]}:{$m[3]}:{$m[4]}", 'Asia/Manila');
    }

    /** "<phone> 2026-08-19 14-30-05.m4a" -> "Aug 19, 2:30 PM" for the popup's list. */
    private function recordingLabel(string $filename): string
    {
        return $this->parsedRecordingMoment($filename)?->format('M j, g:i A') ?? $filename;
    }

    /**
     * JSON feed for the Outcome search box — real tags from the shop's own
     * POS order-tag catalog (PancakeOrderTagApi — see its own doc comment
     * for why this, not the Messenger/conversation-scoped tags API, is the
     * real one: it's what a TSA actually sees in Pancake POS's own "Add
     * tag" popup, and what TSD Reports' own sync reads). Not a fixed local
     * list, so a TSA logs whatever the account is actually tagged with.
     * listTags() itself caches for 5 minutes since this gets hit on every
     * keystroke while typing.
     */
    public function searchTags(Request $request, Lead $lead, PancakeOrderTagApi $api)
    {
        $user = Auth::user();

        if (!$user->isAtLeastAdmin() && $lead->tsa_id !== $user->tsa_id) {
            abort(403);
        }

        if (!$lead->pancake_order_id) {
            return response()->json(['success' => false, 'tags' => [], 'error' => 'This lead has no linked Pancake order.']);
        }

        $q    = trim((string) $request->input('q', ''));
        $tags = collect($api->listTags());

        if ($q !== '') {
            $tags = $tags->filter(fn ($t) => stripos($t['name'] ?? '', $q) !== false)->values();
        }

        // The picker shows the full catalog as a scrollable checklist (not
        // just narrow-as-you-type results), so this caps at a generous
        // ceiling rather than a short "top few suggestions" list — a real
        // shop's whole catalog has run into the low hundreds in practice.
        // Normalized to {id, text} here (the POS API's own field is `name`)
        // so the frontend picker/chips code doesn't need to know which
        // Pancake API a tag came from.
        return response()->json(['success' => true, 'tags' => $tags->take(200)->values()->map(fn ($t) => [
            'id'    => $t['id'] ?? null,
            'text'  => $t['name'] ?? '',
            'color' => $t['color'] ?? null,
        ])]);
    }

    /**
     * JSON feed for the Add Upsell modal — same "search a real Pancake
     * catalog as you type" idea as searchTags() above, but against real
     * SELLABLE products+prices (PancakeProductApi::search()) instead of
     * order tags — the exact search a TSA would otherwise have to open
     * Pancake POS itself to do.
     */
    public function searchProducts(Request $request, Lead $lead, PancakeProductApi $api)
    {
        $user = Auth::user();

        if (!$user->isAtLeastAdmin() && $lead->tsa_id !== $user->tsa_id) {
            abort(403);
        }

        if (!$lead->pancake_order_id) {
            return response()->json(['success' => false, 'products' => [], 'error' => 'This lead has no linked Pancake order.']);
        }

        $q = trim((string) $request->input('q', ''));
        if ($q === '') {
            return response()->json(['success' => true, 'products' => []]);
        }

        return response()->json(['success' => true, 'products' => $api->search($q)]);
    }

    /**
     * Adds a real upsell product to the lead's linked Pancake order — see
     * PancakeOrderTagApi::addUpsellItem()'s own doc comment for the full
     * mechanics (a real line item + an "UPSELL TSD - <product>" tag, added
     * together in one GET/PUT cycle). This is what lets a TSA close an
     * upsell without ever opening Pancake POS itself. Logged locally as a
     * LeadActivity either way, same audit-trail convention as
     * updateDisposition() below, so what was added is visible on the lead's
     * own timeline even if the Pancake write is what actually matters for
     * TSD Reports.
     */
    public function addUpsell(Request $request, Lead $lead, PancakeOrderTagApi $api)
    {
        $user = Auth::user();

        if (!$user->isAtLeastAdmin() && $lead->tsa_id !== $user->tsa_id) {
            abort(403);
        }

        if (!$lead->pancake_order_id) {
            return response()->json(['success' => false, 'error' => 'This lead has no linked Pancake order.'], 422);
        }

        $data = $request->validate([
            'variation_id' => ['required', 'string'],
            'product_id'   => ['required', 'string'],
            'name'         => ['required', 'string', 'max:255'],
            'retail_price' => ['required', 'numeric', 'min:0'],
            'quantity'     => ['required', 'integer', 'min:1', 'max:99'],
        ]);

        // "UPSELL TSD - <name>" — the dash phrasing TSD Reports' own
        // remainingItemIsJustTheBase() recognizes most robustly (see that
        // method's doc comment, app/Models/Order.php), not one of the
        // looser "TSD UPSELL X" no-dash forms confirmed to sometimes slip
        // past it. Created in Pancake's real tag catalog first if this
        // exact product's never been upsold before.
        $upsellTagName = 'UPSELL TSD - ' . $data['name'];
        $api->createTagIfMissing($upsellTagName);

        $success = $api->addUpsellItem($lead->pancake_order_id, $data, $upsellTagName, $lead->tsa?->tsa_key);

        $description = "Added upsell \"{$data['name']}\" (₱" . number_format($data['retail_price'], 2) . " × {$data['quantity']}) by {$user->name}"
            . ($success ? '.' : ' — Pancake write failed, verify in POS.');
        // Only counted toward the Dashboard's "today's upsells" total when
        // the Pancake write actually succeeded — a failed attempt still
        // gets logged for the audit trail, but never happened for real.
        $amount = $success ? $data['retail_price'] * $data['quantity'] : null;
        LeadActivity::log($lead, 'upsell_added', $description, $user, $amount);

        if (!$success) {
            return response()->json(['success' => false, 'error' => 'Saved locally, but could not add this product to the order in Pancake — try again or add it directly in POS.'], 500);
        }

        return response()->json(['success' => true, 'message' => "Added \"{$data['name']}\" to order #{$lead->pancake_order_id}."]);
    }

    /**
     * Removes one line item from the lead's linked Pancake order — same
     * permission gate and audit-trail convention as addUpsell() above.
     * $variation_id (not a local id — Pancake's item shape carries none)
     * identifies which line, matching PancakeOrderTagApi::removeItem()'s
     * own matching key.
     */
    public function removeItem(Request $request, Lead $lead, PancakeOrderTagApi $api)
    {
        $user = Auth::user();

        if (!$user->isAtLeastAdmin() && $lead->tsa_id !== $user->tsa_id) {
            abort(403);
        }

        if (!$lead->pancake_order_id) {
            return response()->json(['success' => false, 'error' => 'This lead has no linked Pancake order.'], 422);
        }

        $data = $request->validate([
            'variation_id' => ['required', 'string'],
            'name'         => ['required', 'string', 'max:255'],
        ]);

        $success = $api->removeItem($lead->pancake_order_id, $data['variation_id']);

        $description = "Removed \"{$data['name']}\" from order by {$user->name}"
            . ($success ? '.' : ' — Pancake write failed, verify in POS.');
        LeadActivity::log($lead, 'item_removed', $description, $user);

        if (!$success) {
            return response()->json(['success' => false, 'error' => 'Could not remove this product from the order in Pancake — try again or remove it directly in POS.'], 500);
        }

        return response()->json(['success' => true, 'message' => "Removed \"{$data['name']}\" from order #{$lead->pancake_order_id}."]);
    }

    /**
     * Updates one line item's price (and/or quantity) on the lead's linked
     * Pancake order — same permission gate/audit-trail convention as
     * addUpsell()/removeItem() above.
     */
    public function updateItem(Request $request, Lead $lead, PancakeOrderTagApi $api)
    {
        $user = Auth::user();

        if (!$user->isAtLeastAdmin() && $lead->tsa_id !== $user->tsa_id) {
            abort(403);
        }

        if (!$lead->pancake_order_id) {
            return response()->json(['success' => false, 'error' => 'This lead has no linked Pancake order.'], 422);
        }

        $data = $request->validate([
            'variation_id' => ['required', 'string'],
            'name'         => ['required', 'string', 'max:255'],
            'retail_price' => ['required', 'numeric', 'min:0'],
            'quantity'     => ['nullable', 'integer', 'min:1', 'max:99'],
        ]);

        $success = $api->updateItem($lead->pancake_order_id, $data['variation_id'], $data['retail_price'], $data['quantity'] ?? null);

        $description = "Updated \"{$data['name']}\" to ₱" . number_format($data['retail_price'], 2)
            . ($data['quantity'] ? " × {$data['quantity']}" : '') . " by {$user->name}"
            . ($success ? '.' : ' — Pancake write failed, verify in POS.');
        LeadActivity::log($lead, 'item_updated', $description, $user);

        if (!$success) {
            return response()->json(['success' => false, 'error' => 'Could not update this product on the order in Pancake — try again or update it directly in POS.'], 500);
        }

        return response()->json(['success' => true, 'message' => "Updated \"{$data['name']}\" on order #{$lead->pancake_order_id}."]);
    }

    /**
     * Changes the order's status directly from Call Tracker's Leads tab — mirrors
     * Pancake POS's own Status dropdown (explicit request, 2026-08-22, from a
     * screenshot of that exact control). Same GET-then-PUT-whole-order write
     * PancakeOrderTagApi already uses for tags/notes/upsell items. $statusCode is
     * restricted to Order::STATUS_ASSIGNABLE — the same fixed set Pancake's own
     * dropdown showed, not every status_code Order::STATUS_LABELS knows about (see
     * that constant's own doc comment for why the two lists differ). On a successful
     * Pancake write, the locally-synced Order row is updated too so the Leads tab's
     * own pill (read from that local cache, not a live fetch — see LeadController::
     * index()) reflects the change immediately rather than waiting for the next sync.
     */
    public function updateStatus(Request $request, Lead $lead, PancakeOrderTagApi $api)
    {
        $user = Auth::user();

        if (!$user->isAtLeastAdmin() && $lead->tsa_id !== $user->tsa_id) {
            abort(403);
        }

        if (!$lead->pancake_order_id) {
            return response()->json(['success' => false, 'error' => 'This lead has no linked Pancake order.'], 422);
        }

        $data = $request->validate([
            'status_code' => ['required', 'integer', Rule::in(Order::STATUS_ASSIGNABLE)],
        ]);
        $statusCode = $data['status_code'];
        $label      = Order::STATUS_PILL[$statusCode]['label'] ?? (string) $statusCode;

        $success = $api->updateStatus($lead->pancake_order_id, $statusCode);

        if ($success) {
            Order::where('pancake_order_id', $lead->pancake_order_id)->update(['status_code' => $statusCode]);
        }

        LeadActivity::log(
            $lead, 'status_changed',
            "Changed order status to \"{$label}\" by {$user->name}" . ($success ? '.' : ' — Pancake write failed, verify in POS.'),
            $user
        );

        if (!$success) {
            return response()->json(['success' => false, 'error' => 'Could not update the order status in Pancake — try again or update it directly in POS.'], 500);
        }

        return response()->json(['success' => true, 'status_code' => $statusCode, 'label' => $label]);
    }

    /**
     * Removes a real tag from the order in Pancake — the write side of the Leads
     * tab's own "real tags" chip display (explicit request, 2026-08-22, from a
     * screenshot of Pancake POS's own tag chips + × remove button). Same GET-then-
     * PUT-whole-order write PancakeOrderTagApi already uses elsewhere. On success,
     * the locally-synced Order.raw_tags is updated too (same "keep the local cache
     * in step so the next poll shows it immediately" convention as updateStatus()
     * above) rather than waiting for the next full sync to drop it.
     */
    public function removeTag(Request $request, Lead $lead, PancakeOrderTagApi $api)
    {
        $user = Auth::user();

        if (!$user->isAtLeastAdmin() && $lead->tsa_id !== $user->tsa_id) {
            abort(403);
        }

        if (!$lead->pancake_order_id) {
            return response()->json(['success' => false, 'error' => 'This lead has no linked Pancake order.'], 422);
        }

        $data = $request->validate(['tag' => ['required', 'string', 'max:255']]);

        $success = $api->removeTagFromOrder($lead->pancake_order_id, $data['tag']);

        if ($success) {
            $order = Order::where('pancake_order_id', $lead->pancake_order_id)->first();
            if ($order) {
                $order->update(['raw_tags' => collect($order->raw_tags ?? [])
                    ->reject(fn ($t) => strcasecmp($t, $data['tag']) === 0)->values()->all()]);
            }
        }

        LeadActivity::log(
            $lead, 'tag_removed',
            "Removed tag \"{$data['tag']}\" by {$user->name}" . ($success ? '.' : ' — Pancake write failed, verify in POS.'),
            $user
        );

        if (!$success) {
            return response()->json(['success' => false, 'error' => 'Could not remove this tag in Pancake — try again or remove it directly in POS.'], 500);
        }

        return response()->json(['success' => true]);
    }

    /**
     * Adds a real tag to the order in Pancake directly — the write side of
     * the lead detail modal's own "POS Tags" panel (explicit follow-up
     * request, 2026-08-25: "there's add tag too like in the pos not log
     * like log outcome or upsell" — a real Pancake tag is its own concept,
     * distinct from updateDisposition()'s own tag-writing, which is really
     * about logging a call OUTCOME and only writes tags as a side effect of
     * that). Reuses searchTags() for the picker (same real Pancake tag
     * catalog, so a chosen tag is always guaranteed to exist and match
     * PancakeOrderTagApi::addTagsToOrder()'s own catalog lookup). Same
     * GET-then-PUT-whole-order write, same "keep the local Order.raw_tags
     * cache in step" convention as removeTag() above.
     */
    public function addTag(Request $request, Lead $lead, PancakeOrderTagApi $api)
    {
        $user = Auth::user();

        if (!$user->isAtLeastAdmin() && $lead->tsa_id !== $user->tsa_id) {
            abort(403);
        }

        if (!$lead->pancake_order_id) {
            return response()->json(['success' => false, 'error' => 'This lead has no linked Pancake order.'], 422);
        }

        $data = $request->validate(['tag' => ['required', 'string', 'max:255']]);

        $result  = $api->addTagsToOrder($lead->pancake_order_id, [$data['tag']]);
        $success = $result[$data['tag']] ?? false;

        if ($success) {
            $order = Order::where('pancake_order_id', $lead->pancake_order_id)->first();
            if ($order) {
                $order->update(['raw_tags' => collect($order->raw_tags ?? [])
                    ->push($data['tag'])->unique(fn ($t) => strtolower($t))->values()->all()]);
            }
        }

        LeadActivity::log(
            $lead, 'tag_added',
            "Added tag \"{$data['tag']}\" by {$user->name}" . ($success ? '.' : ' — Pancake write failed, verify in POS.'),
            $user
        );

        if (!$success) {
            return response()->json(['success' => false, 'error' => 'Could not add this tag in Pancake — try again or add it directly in POS.'], 500);
        }

        return response()->json(['success' => true]);
    }

    /**
     * Live read of the lead's real Pancake order notes (PancakeOrderTagApi::
     * getNotes() — see its own doc comment: `note`/`note_print`, the only
     * two note fields Pancake's API actually has). Explicit request
     * (2026-08-22): polled by the lead detail page (calls.js) so an edit
     * made directly in Pancake POS shows up here without a reload, the same
     * "must reflect Pancake's real current state" contract the Add Upsell
     * search already follows.
     */
    public function notes(Lead $lead, PancakeOrderTagApi $api)
    {
        $user = Auth::user();

        if (!$user->isAtLeastAdmin() && $lead->tsa_id !== $user->tsa_id) {
            abort(403);
        }

        if (!$lead->pancake_order_id) {
            return response()->json(['success' => false, 'note' => null, 'note_print' => null]);
        }

        return response()->json(['success' => true] + $api->getNotes($lead->pancake_order_id));
    }

    /**
     * Writes one or both Pancake note fields back to the real order — see
     * PancakeOrderTagApi::updateNotes()'s own doc comment for the GET-then-
     * PUT mechanics. No local LeadActivity entry (unlike addUpsell()/
     * updateDisposition() above): a note edit isn't a TSD Reports-tracked
     * event, it's a direct edit of Pancake's own order record — the
     * activity feed here is about this app's own actions, not everything
     * that ever touches the order.
     */
    public function updateNotes(Request $request, Lead $lead, PancakeOrderTagApi $api)
    {
        $user = Auth::user();

        if (!$user->isAtLeastAdmin() && $lead->tsa_id !== $user->tsa_id) {
            abort(403);
        }

        if (!$lead->pancake_order_id) {
            return response()->json(['success' => false, 'error' => 'This lead has no linked Pancake order.'], 422);
        }

        $data = $request->validate([
            'note'       => ['nullable', 'string', 'max:5000'],
            'note_print' => ['nullable', 'string', 'max:5000'],
        ]);

        if (!array_key_exists('note', $data) && !array_key_exists('note_print', $data)) {
            return response()->json(['success' => false, 'error' => 'Nothing to save.'], 422);
        }

        $success = $api->updateNotes(
            $lead->pancake_order_id,
            array_key_exists('note', $data) ? ($data['note'] ?? '') : null,
            array_key_exists('note_print', $data) ? ($data['note_print'] ?? '') : null,
        );

        if (!$success) {
            return response()->json(['success' => false, 'error' => 'Could not save this note in Pancake — try again or edit it directly in POS.'], 500);
        }

        return response()->json(['success' => true]);
    }

    /**
     * Feeds the Delivery card's real province → district → commune cascading
     * picker (explicit follow-up request, 2026-08-25: "make it editable like
     * in the POS") — the same real Pancake geo catalog its own Delivery form
     * uses (PancakeOrderTagApi::listProvinces()/listDistricts()/
     * listCommunes()). Scoped under /leads/{lead}/... and guarded the same
     * way as searchTags()/searchProducts() above even though the geo data
     * itself isn't lead-specific, so only someone who can already see this
     * lead can query it.
     */
    public function deliveryProvinces(Lead $lead, PancakeOrderTagApi $api)
    {
        $user = Auth::user();

        if (!$user->isAtLeastAdmin() && $lead->tsa_id !== $user->tsa_id) {
            abort(403);
        }

        return response()->json(['success' => true, 'provinces' => $api->listProvinces()]);
    }

    public function deliveryDistricts(Request $request, Lead $lead, PancakeOrderTagApi $api)
    {
        $user = Auth::user();

        if (!$user->isAtLeastAdmin() && $lead->tsa_id !== $user->tsa_id) {
            abort(403);
        }

        $data = $request->validate(['province_id' => ['required', 'string']]);

        return response()->json(['success' => true, 'districts' => $api->listDistricts($data['province_id'])]);
    }

    public function deliveryCommunes(Request $request, Lead $lead, PancakeOrderTagApi $api)
    {
        $user = Auth::user();

        if (!$user->isAtLeastAdmin() && $lead->tsa_id !== $user->tsa_id) {
            abort(403);
        }

        $data = $request->validate([
            'province_id' => ['required', 'string'],
            'district_id' => ['required', 'string'],
        ]);

        return response()->json(['success' => true, 'communes' => $api->listCommunes($data['province_id'], $data['district_id'])]);
    }

    /**
     * Writes the recipient/address fields back to the real Pancake order —
     * the write side of the Delivery card's own editable form. Courier/
     * tracking/shipping fee stay read-only (those are set by Pancake/the
     * courier itself once a shipment is actually booked, not something this
     * form collects). full_address is computed here the same way Pancake's
     * own real orders build it (confirmed live: "{street line}, {commune},
     * {district}, {province}") rather than trusting the client to send a
     * pre-built string.
     */
    public function updateDelivery(Request $request, Lead $lead, PancakeOrderTagApi $api)
    {
        $user = Auth::user();

        if (!$user->isAtLeastAdmin() && $lead->tsa_id !== $user->tsa_id) {
            abort(403);
        }

        if (!$lead->pancake_order_id) {
            return response()->json(['success' => false, 'error' => 'This lead has no linked Pancake order.'], 422);
        }

        $data = $request->validate([
            'full_name'     => ['required', 'string', 'max:255'],
            'phone_number'  => ['required', 'string', 'max:50'],
            'address'       => ['nullable', 'string', 'max:500'],
            'province_id'   => ['required', 'string'],
            'province_name' => ['required', 'string'],
            'district_id'   => ['required', 'string'],
            'district_name' => ['required', 'string'],
            'commune_id'    => ['nullable', 'string'],
            'commune_name'  => ['nullable', 'string'],
            'post_code'     => ['nullable', 'string', 'max:20'],
        ]);

        $addressLine = trim($data['address'] ?? '');
        $fullAddress = collect([$addressLine, $data['commune_name'] ?? null, $data['district_name'], $data['province_name']])
            ->filter()->implode(', ');

        $shippingAddress = [
            'full_name'     => $data['full_name'],
            'phone_number'  => $data['phone_number'],
            'address'       => $addressLine,
            'full_address'  => $fullAddress,
            'province_id'   => $data['province_id'],
            'province_name' => $data['province_name'],
            'district_id'   => $data['district_id'],
            'district_name' => $data['district_name'],
            'commune_id'    => $data['commune_id'] ?? null,
            'commune_name'  => $data['commune_name'] ?? null,
            'post_code'     => $data['post_code'] ?? '',
            'country_code'  => '63',
        ];

        $success = $api->updateShippingAddress($lead->pancake_order_id, $shippingAddress);

        LeadActivity::log(
            $lead, 'delivery_updated',
            "Updated delivery details by {$user->name}" . ($success ? '.' : ' — Pancake write failed, verify in POS.'),
            $user
        );

        if (!$success) {
            return response()->json(['success' => false, 'error' => 'Could not update delivery info in Pancake — try again or edit it directly in POS.'], 500);
        }

        return response()->json(['success' => true]);
    }

    /**
     * Explicit request: clicking a customer's phone number in My Leads/the
     * lead detail page should show up on TSA Logs, not just status changes.
     * Fire-and-forget from calls.js's existing tel: click handler — this
     * only records that the click happened, not whether the call itself
     * connected (the phone gives this page no way to know that; Call Log's
     * own MacroDroid-reported events are the authoritative record of a real
     * completed call).
     *
     * Logged as a LeadActivity (not a TsaStatusLog row — that table's
     * `status` column is constrained to real TsaShift::STATUSES values used
     * for round-robin eligibility, a genuinely different concept from a
     * momentary click) attributed to the LEAD's assigned TSA, not
     * necessarily whoever clicked — an admin calling on a TSA's behalf
     * should still show up under that TSA's own row. Skipped silently for
     * an unassigned lead (nothing meaningful to attribute it to).
     * TsaStatusController::index() merges these into TSA Logs by recency.
     *
     * Also flips that TSA to Calling (explicit request, 2026-08-20) —
     * Monitor TSA previously only ever set Calling via its own manual
     * button grid; this is the SAME real click-to-call moment that already
     * fires this exact request unconditionally on every tel: link click
     * (dial-host auto-dial or plain tel: fallback, doesn't matter), so
     * piggybacking here means "call clicked" and "went Calling" can never
     * disagree, with zero extra round trips added to the dial path. Applies
     * even when the TSA was on Break/Lunch/etc — clicking to call IS them
     * going on a call regardless of what they were doing a second ago.
     *
     * Also creates a placeholder CallEvent (explicit follow-up request,
     * 2026-09-03: "when they call in the leads it is automatically be has
     * data in the call log ... marisol tried to call one lead but it did not
     * display to the call log") — Call Log was previously 100% dependent on
     * each TSA's own phone's MacroDroid automation reporting the real call
     * back (see CallEventController's own doc comment); if that automation
     * isn't running/configured on a given phone, NOTHING ever showed up for
     * that TSA, with no visible error anywhere. This guarantees Call Log
     * always has at least a "call was attempted at this time" row —
     * direction is always 'outgoing' (a lead is always called, never the
     * reverse) and duration_seconds is always null (this page has no way to
     * know how long the call actually lasted or whether it even connected —
     * see CallLogController's own '—' fallback for a null duration). This
     * can double-count against a later real MacroDroid event for the exact
     * same call (accepted tradeoff, explicit decision — no reliable way to
     * de-duplicate the two independent signals).
     */
    public function logCallClick(Lead $lead)
    {
        $user = Auth::user();

        if (!$user->isAtLeastAdmin() && $lead->tsa_id !== $user->tsa_id) {
            abort(403);
        }

        if ($lead->tsa_id) {
            LeadActivity::log(
                $lead,
                'call_clicked',
                ($lead->tsa->display_name ?? 'A TSA') . ' clicked to call ' . ($lead->customer_name ?: 'this customer') . ' (' . ($lead->phone_number ?: $lead->dialable_number) . ').',
                $user
            );
            $lead->tsa?->applyStatusChange(TsaShift::STATUS_CALLING);

            // dialed_at (explicit request, 2026-08-22): a lighter, separate
            // signal from called_at — this fires on every click, well before
            // any disposition/outcome exists, so the Leads table can show
            // "this customer was dialed" without waiting for the call to be
            // wrapped up. A redial just moves it forward, same as any other
            // "most recent activity" timestamp on this model (assigned_at,
            // callback_at).
            $lead->update(['dialed_at' => now()]);

            CallEvent::create([
                'tsa_id'           => $lead->tsa_id,
                'lead_id'          => $lead->id,
                'phone_number'     => $lead->phone_number ?: $lead->dialable_number,
                'direction'        => 'outgoing',
                'duration_seconds' => null,
                'occurred_at'      => now(),
            ]);
        }

        return response()->json(['success' => true]);
    }

    /**
     * "End Call" in the Calling modal (explicit request, 2026-08-20) — flips
     * that TSA straight to Wrap Up the instant the button's clicked, rather
     * than only via CallEventController's webhook once the phone itself
     * reports the call ended. Both paths call the exact same
     * applyStatusChange(WRAP_UP) — this is just a second, faster trigger for
     * it, not a competing implementation: whichever fires first wins, the
     * other is a harmless no-op re-write of the same status. Only makes
     * sense while actually Calling (the End Call button only ever renders
     * when a dial-host is configured and a call was just placed) — silently
     * no-ops otherwise rather than erroring, since by the time this request
     * lands the webhook may have already moved them on.
     */
    public function endCall(Lead $lead)
    {
        $user = Auth::user();

        if (!$user->isAtLeastAdmin() && $lead->tsa_id !== $user->tsa_id) {
            abort(403);
        }

        if ($lead->tsa_id && $lead->tsa?->status === TsaShift::STATUS_CALLING) {
            $lead->tsa->applyStatusChange(TsaShift::STATUS_WRAP_UP);
        }

        return response()->json(['success' => true]);
    }

    /** Splits a comma-joined multi-tag disposition string (e.g. "Confirmed,
     *  Call Back") back into its individual tag names, trimmed and with any
     *  blanks dropped — the one place this splitting happens, shared by
     *  validation and the Pancake write-back so they can never disagree on
     *  what "the picked tags" means. */
    private static function splitTags(string $value): Collection
    {
        return collect(explode(',', $value))->map(fn ($t) => trim($t))->filter()->values();
    }

    /**
     * JSON feed for the conversation modal — fetches real message history
     * from Pancake server-side (never exposing the access token to the
     * browser) instead of trying to embed Pancake's own page, which its CSP
     * (frame-ancestors) blocks outright for any non-Pancake domain.
     */
    public function conversation(Lead $lead, PancakeConversationApi $api)
    {
        $user = Auth::user();

        if (!$user->isAtLeastAdmin() && $lead->tsa_id !== $user->tsa_id) {
            abort(403);
        }

        if (!$lead->pancake_page_id || !$lead->pancake_conversation_id) {
            return response()->json(['success' => false, 'messages' => [], 'error' => 'This lead has no linked Pancake conversation.']);
        }

        return response()->json($api->getMessages($lead->pancake_page_id, $lead->pancake_conversation_id));
    }

    public function updateDisposition(Request $request, Lead $lead, PancakeOrderTagApi $api)
    {
        $user = Auth::user();

        // A TSA can only log outcomes on their own leads; an admin can log
        // on behalf of any TSA (e.g. correcting a mis-logged call).
        if (!$user->isAtLeastAdmin() && $lead->tsa_id !== $user->tsa_id) {
            abort(403);
        }

        $data = $request->validate([
            // Outcome is now one or more real tags the TSA picked from the
            // shop's own POS order-tag catalog (see searchTags()/the
            // checklist Outcome picker in leads/_table.blade.php +
            // leads/show.blade.php) — a TSA can select several at once,
            // submitted here as one comma-joined string (e.g. "Confirmed,
            // Call Back"), not a fixed local list. Every picked tag is
            // validated against that same catalog so a stray free-typed
            // value never slips through. Skipped when the lead has no
            // linked Pancake order/catalog to check against (older rows,
            // or Pancake unreachable) — same "feature unavailable, not
            // fatal" convention as the tag write-back below.
            'disposition' => ['required', 'string', 'max:255', function ($attribute, $value, $fail) use ($lead, $api) {
                if (!$lead->pancake_order_id) return;
                $tags = collect($api->listTags());
                if ($tags->isEmpty()) return;

                $invalid = self::splitTags($value)->reject(
                    fn ($picked) => $tags->contains(fn ($t) => strcasecmp($t['name'] ?? '', $picked) === 0)
                );
                if ($invalid->isNotEmpty()) {
                    $fail('Pick outcome tags from the list — "' . $invalid->implode('", "') . '" isn\'t a real Pancake tag on this shop.');
                }
            }],
            'notes'       => ['nullable', 'string', 'max:1000'],
            'callback_at' => ['nullable', 'date'],
        ]);

        // An outcome that means "we didn't actually reach/confirm this lead"
        // needs a due time to ever show up on the Callbacks view — default to
        // +1 day if the TSA didn't pick one, rather than silently having no
        // due date at all. Explicit "Call Back" is the obvious case, but
        // "Unattended" and "Not Answering" mean exactly the same thing in
        // practice — nobody talked to the customer, so it still needs a
        // follow-up attempt. Case-insensitive substring match over the WHOLE
        // joined string, so this still fires when any of these is only one
        // of several tags picked alongside others — same keyword convention
        // TSD Reports itself uses for disposition matching
        // (ProductPerformance::count()).
        $callbackAt = null;
        $needsFollowUp = self::CALLBACK_TRIGGER_KEYWORDS;
        if (collect($needsFollowUp)->contains(fn ($kw) => stripos($data['disposition'], $kw) !== false)) {
            $callbackAt = !empty($data['callback_at'])
                ? Carbon::parse($data['callback_at'])
                : now()->addDay();
        }

        $lead->update([
            'disposition'       => $data['disposition'],
            'notes'             => $data['notes'] ?? null,
            'callback_at'       => $callbackAt,
            'status'            => 'called',
            'called_at'         => now(),
            'called_by_user_id' => $user->id,
        ]);

        LeadActivity::log($lead, 'called', "Logged \"{$data['disposition']}\" by {$user->name}.", $user);

        if ($callbackAt) {
            LeadActivity::log($lead, 'callback_scheduled', 'Callback set for ' . $callbackAt->format('M j, g:i A') . '.', $user);
        }

        $this->tagOutcomeInPancake($lead, $data['disposition'], $api);

        return back()->with('success', "Logged \"{$data['disposition']}\" for {$lead->customer_name}.");
    }

    /**
     * Writes every logged outcome tag back to the real POS order in Pancake
     * (e.g. "Confirmed" + "Call Back" if both were picked), alongside a
     * (re-)confirmed TSA tag — the real POS order-tags API (PancakeOrderTagApi
     * — see its own doc comment for why this, not the conversation-scoped
     * tags API, is the one that actually shows up in Pancake POS and reaches
     * TSD Reports' own sync). The TSA tag is gated by the
     * 'pos_auto_tagging_enabled' Setting (toggle lives on the TSA Management
     * tab) — off skips just that one tag, not the whole call. Delegates the
     * actual tag-push to tagTsaOnPancakeOrder() below, shared with every
     * other place a lead's tsa_id gets set (see that method's own doc
     * comment). Silently no-ops (with a logged warning) per-tag when there's
     * no linked order or a tag doesn't exist in the real catalog — same
     * "feature unavailable, not fatal" convention as elsewhere; a TSA's
     * outcome is still saved locally either way.
     */
    private function tagOutcomeInPancake(Lead $lead, string $disposition, PancakeOrderTagApi $api): void
    {
        self::tagTsaOnPancakeOrder($lead, $api, self::splitTags($disposition)->all());
    }

    /**
     * Pushes $lead's currently-assigned TSA's own POS name tag to the real
     * Pancake order, optionally alongside any $extraTags (e.g. outcome/
     * disposition tags from tagOutcomeInPancake() above) — extracted out of
     * that method (explicit follow-up request, 2026-09-03: "when there's new
     * leads it is auto tagging ... because it is their leads") so every place
     * a lead's tsa_id gets SET can push the same tag immediately on
     * assignment, not only once a call outcome is eventually logged.
     * Round-robin assignment itself (SyncPancakeLeads) used to be a
     * local-only signal with no Pancake tag at all — see this method's own
     * callers (SyncPancakeLeads, LogoutLeadRedistributor, transfer()/
     * bulkTransfer() below) for where that's since changed.
     *
     * Same silent-no-op conventions as before: no linked order yet, or the
     * 'pos_auto_tagging_enabled' Setting is off, or a tag doesn't exist in
     * the real catalog — none of these are fatal, a lead's local assignment
     * is never blocked on Pancake being reachable.
     */
    public static function tagTsaOnPancakeOrder(Lead $lead, PancakeOrderTagApi $api, array $extraTags = []): void
    {
        if (!$lead->pancake_order_id) {
            return;
        }

        $tsaTag = Setting::get('pos_auto_tagging_enabled', true) ? $lead->tsa?->tsa_key : null;

        $tagNames = collect($extraTags)->push($tsaTag)->filter()->unique()->values()->all();
        if (empty($tagNames)) {
            return;
        }

        $results = $api->addTagsToOrder($lead->pancake_order_id, $tagNames);

        foreach ($results as $tagName => $success) {
            if (!$success) {
                Log::warning("Could not tag \"{$tagName}\" on order {$lead->pancake_order_id} in Pancake.");
            }
        }
    }
}
