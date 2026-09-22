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
use App\Support\Teams;
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
    /** Disposition keyword that puts a lead in the shared Callbacks queue —
     *  "Call Back" ONLY (explicit request, 2026-09-22: "make the call backs
     *  should be only will have a call back tag" — a full reversal of the
     *  2026-09-17 decision below, now that Unattended/Not Answering/Invalid
     *  Number have their own dedicated page, Unanswered Calls, see
     *  UNANSWERED_CALLS_TRIGGER_KEYWORDS just below. Keeping both on
     *  Callbacks too would have meant the exact same lead showing on both
     *  pages at once).
     *
     *  Previously (2026-09-17: "do not include call back only unattended,
     *  not answering") this was Unattended/Not Answering ONLY, deliberately
     *  excluding "Call Back" — the reasoning then was that Call Back means
     *  a TSA DID reach the customer and is promising a follow-up at their
     *  own request, a different case from never having reached them at
     *  all. That distinction is now moot: Unattended/Not Answering get
     *  their own page instead of sharing this one.
     *
     *  Matched case-insensitively as a substring. Public (not private):
     *  SyncPancakeLeads::backfillCallbackFromTags() reuses this exact same
     *  list so a lead whose callback-worthy state came from a real Pancake
     *  TAG (not a TSA's own logged Outcome) is still recognized by the
     *  identical keyword set — one definition, not two hand-kept-in-sync
     *  copies. */
    public const CALLBACK_TRIGGER_KEYWORDS = ['call back'];

    /** Disposition keywords for the Unanswered Calls view (explicit
     *  request, 2026-09-22: "add new page next to callbacks 'UNANSWERED
     *  CALLS' but all of the leads in there is has NOT ANSWERING,
     *  UNATTENDED, INVALID NUMBER tags") — deliberately its OWN 3-keyword
     *  list, not a reuse of CALLBACK_TRIGGER_KEYWORDS above (which is only
     *  2 of these 3 — Invalid Number was explicitly excluded there since a
     *  callback reminder makes no sense for a number that can't be
     *  called), and not the 6-keyword ProductPerformance::UNANSWERED_COLUMNS
     *  either (confirmed scope, 2026-09-22: DFR/Double Order/FSD Uncleared
     *  are explicitly NOT part of this page). Matched case-insensitively
     *  as a substring, same convention as CALLBACK_TRIGGER_KEYWORDS. */
    public const UNANSWERED_CALLS_TRIGGER_KEYWORDS = ['unattended', 'not answering', 'invalid number'];

    /** How long an assigned-but-uncatered lead (no dial, no disposition —
     *  same "catered" definition the Leads tab's own status filter uses,
     *  see this method's callers) sits before Overdue surfaces it. Changed
     *  from a 4-HOUR default to a 20-MINUTE one (explicit request,
     *  2026-09-17: "leads that not will be catered like no green checkmark
     *  within 20minutes should be reflect to the overdue") — renamed from
     *  overdueThresholdHours()/overdue_threshold_hours to make the new unit
     *  impossible to miss at every call site, not just here. */
    public static function overdueThresholdMinutes(): int
    {
        return max(1, (int) Setting::get('overdue_threshold_minutes', 20));
    }

    /**
     * The one access check for every per-lead action in this controller —
     * viewing (show/history) AND every write (disposition, tags, delivery,
     * upsell, recordings, etc.). Explicit report, 2026-09-14: opening a
     * lead from the Callbacks page threw "Could not load this lead — try
     * again." for a normal TSA whenever the callback belonged to a
     * DIFFERENT TSA (first fixed for viewing only, f7761b4); immediate
     * follow-up — "it should be like this view in callbacks in all tsa,"
     * confirmed explicitly to mean full edit access too, not just
     * read-only — extended the same exception to every write action.
     *
     * The plain "admin or the owning TSA" rule correctly protects a TSA's
     * own private Leads queue, but directly contradicted Callbacks being
     * deliberately shared team knowledge across every TSA (2026-09-08
     * decision, reinforced by 1a85e7c's own TSA-filter removal on the same
     * reasoning): whoever picks up a shared callback needs to be able to
     * actually log the call, add tags, and edit delivery on it, not just
     * look at it. A lead currently carrying a due callback_at (same "due
     * now or already past due" definition index()'s own Callbacks branch
     * uses) is now fully manageable by any TSA; every other lead stays
     * scoped to its owner, same as before. _detail.blade.php's own
     * $canManage flag mirrors this exact same rule so the modal's edit
     * controls and the backend that actually enforces them never disagree.
     */
    private function canAccess(Lead $lead, $user): bool
    {
        if ($user->isAtLeastAdmin() || $lead->tsa_id === $user->tsa_id) {
            return true;
        }

        if ($lead->callback_at !== null && $lead->callback_at->lte(now())) {
            return true;
        }

        // Unanswered Calls (added 2026-09-22) is shared the same way — a
        // lead matching UNANSWERED_CALLS_TRIGGER_KEYWORDS can genuinely
        // have no callback_at at all (see index()'s own 'unanswered'
        // branch doc comment: it can arrive already tagged straight from
        // Pancake before this app's own callback-set logic ever runs on
        // it), so the callback_at check above alone would list a lead on
        // this shared page for every TSA to see, then 403 any non-owner
        // who actually clicked into it to work it — listed but
        // unmanageable is worse than not shown at all.
        if ($lead->disposition !== null) {
            foreach (self::UNANSWERED_CALLS_TRIGGER_KEYWORDS as $keyword) {
                if (stripos($lead->disposition, $keyword) !== false) {
                    return true;
                }
            }
        }

        return false;
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
        // Explicit request, 2026-09-04: "i want only the leads will be only
        // the product in the product management and when there are no in
        // the product management it will not occur in the leads tab" — a
        // lead whose item never matched a real Product Management entry
        // (product_id null) is excluded from this tab entirely, not just
        // shown with a blank/fallback product name. Confirmed, accepted
        // consequence: round-robin already never assigns these (see the
        // product-filter branch below's own doc comment on the "no product
        // matched" case), so hiding them here too means nobody sees or
        // calls these customers until the item is added to Product
        // Management.
        $query = Lead::with(['product', 'tsa'])
            ->whereNotNull('product_id')
            ->orderByRaw('pinned_at IS NULL')
            ->latest('pancake_created_at');

        // A TSA only ever sees their own queue on the default Leads/Overdue
        // views; an admin can optionally narrow to one TSA via ?tsa=,
        // defaulting to everyone's.
        //
        // Callbacks is the one exception (explicit request, 2026-09-08: "i
        // want to make it like it is visible to all of the TSA's the
        // callbacks") — a promised follow-up call is shared team knowledge,
        // not one TSA's private queue: if Gemma is out and a customer she
        // promised to call back is due, any other TSA logged in should be
        // able to see and pick it up, not just Gemma herself. The ?tsa=
        // narrowing itself is now REMOVED for this view too (explicit
        // follow-up, 2026-09-14: "the callbacks should be no tsa filter
        // because it should be visible to all users so you can remove the
        // tsa filter in the callbacks") — an admin could previously still
        // narrow the shared queue down to one TSA via ?tsa=, which
        // contradicts the whole point: an admin (or a stale URL) leaving
        // ?tsa=Gemma selected would silently hide every OTHER TSA's due
        // callbacks, the exact problem the 2026-09-08 fix was meant to
        // solve in the first place for the plain default view. $request's
        // tsa param is simply ignored on this view now, not just hidden
        // from the UI.
        // Unanswered Calls (added 2026-09-22) is the SAME kind of shared
        // team knowledge as Callbacks — a customer nobody reached yet
        // isn't any one TSA's private problem, any TSA logged in should be
        // able to see and act on it — so it gets the identical carve-out
        // from the per-TSA scoping below.
        if ($view !== 'callbacks' && $view !== 'unanswered') {
            if (!$user->isAtLeastAdmin()) {
                $query->where('tsa_id', $user->tsa_id);
            } elseif ($request->filled('tsa')) {
                $query->where('tsa_id', $request->integer('tsa'));
            }
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
            // Assigned but not yet catered to (no dial, no disposition —
            // same "catered" definition the default Leads view's own
            // status=catered/uncatered filter uses below: status='called'
            // OR dialed_at set), and it's been sitting long enough that
            // this is no longer "hasn't gotten to it yet" — exactly the gap
            // that let a lead sit uncalled for hours before anyone noticed.
            //
            // dialed_at exclusion added (explicit request, 2026-09-17,
            // alongside lowering the threshold from 4 hours to 20 minutes —
            // see overdueThresholdMinutes()'s own doc comment): this used
            // to check status='assigned' alone, so a lead a TSA had
            // already dialed (the table's own green checkmark) but not yet
            // logged an outcome for still counted as Overdue. Barely
            // mattered at a 4-HOUR threshold (most calls get dispositioned
            // well within 4 hours), but at 20 minutes a dialed-not-yet-
            // dispositioned lead is a common, not rare, state — without
            // this it would flood Overdue with leads that already have
            // their green checkmark, the opposite of what Overdue means.
            //
            // ALSO scoped to today's own pancake_created_at (explicit
            // report, 2026-09-14: "why the overdue is not today? it should
            // be today only") — without this, a WEEKS-old order (created
            // 2026-08-11) that only got assigned_at stamped today via the
            // catch-up sweep (working again since b44cfc9's cap-bypass fix)
            // showed up in Overdue once 4+ hours had passed, e.g. #1347666/
            // #1347664/#1347663. Same class of bug as the default Leads
            // view's own pancake_created_at fix (3059cdc) and the Callbacks
            // view's (d8c9def) — assigned_at answers "how long has this
            // TSA had it," pancake_created_at answers "is this actually
            // today's order," and this view needs both, not just the
            // first. Still fails open for a lead with no creation date at
            // all, same convention as those two fixes.
            $query->where('status', 'assigned')
                ->whereNull('dialed_at')
                ->whereBetween('assigned_at', [$rangeFrom, $rangeTo])
                ->where('assigned_at', '<=', now()->subMinutes(self::overdueThresholdMinutes()))
                ->where(function ($q) use ($rangeFrom, $rangeTo) {
                    $q->whereBetween('pancake_created_at', [$rangeFrom, $rangeTo])
                        ->orWhereNull('pancake_created_at');
                })
                ->orderBy('assigned_at');
        } elseif ($view === 'callbacks') {
            // A TSA promised to call back by a specific time — due now or
            // already past due, not "someday in the future". This view
            // just reads callback_at regardless of which tag set it, so it
            // needed no change for the 2026-09-22 CALLBACK_TRIGGER_KEYWORDS
            // reversal (Call Back only, no longer Unattended/Not
            // Answering/Invalid Number — see that constant's own doc
            // comment) — only updateDisposition() and
            // backfillCallbackFromTags() (what SETS callback_at) changed.
            //
            // ALSO scoped to today's own pancake_created_at (explicit
            // report, 2026-09-14: "the callbacks page will be leads today
            // the is has tag of not answering and unattended") — without
            // this, an order created YESTERDAY (or longer ago) that only
            // got its Not Answering/Unattended tag noticed on today's sync
            // tick still showed up here, since backfillCallbackFromTags()
            // stamps callback_at = now() the moment it notices the tag, not
            // backdated to the order's own real date. Confirmed live:
            // #1367768 (created 2026-09-13 17:04) had callback_at =
            // 2026-09-14 06:54, landing it in "today's" Callbacks a full
            // day after the actual order. Same class of bug as the default
            // Leads view's own pancake_created_at fix just above (this
            // file's git history) — callback_at answers "when is this due",
            // pancake_created_at answers "is this actually today's order",
            // and this view needs both, not just the first.
            // Fails open for a lead with no pancake_created_at at all (not
            // yet synced, or manually created) — same "no data one way or
            // the other is never treated the same as confirmed no longer
            // relevant" convention as the default Leads view's own fix.
            $query->whereNotNull('callback_at')
                ->whereBetween('callback_at', [$rangeFrom, $rangeTo])
                ->where('callback_at', '<=', now())
                ->where(function ($q) use ($rangeFrom, $rangeTo) {
                    $q->whereBetween('pancake_created_at', [$rangeFrom, $rangeTo])
                        ->orWhereNull('pancake_created_at');
                })
                ->orderBy('callback_at');
        } elseif ($view === 'unanswered') {
            // Explicit request, 2026-09-22: "add new page next to callbacks
            // 'UNANSWERED CALLS' but all of the leads in there is has NOT
            // ANSWERING, UNATTENDED, INVALID NUMBER tags and still
            // accessible in all TSA" — a real logged disposition matching
            // any of UNANSWERED_CALLS_TRIGGER_KEYWORDS, case-insensitive
            // substring, same match style updateDisposition() itself
            // already uses for CALLBACK_TRIGGER_KEYWORDS. Deliberately NOT
            // scoped to callback_at at all (unlike the Callbacks view
            // above) — this is every lead CARRYING one of these 3 tags
            // right now, not specifically the ones with a due follow-up
            // reminder attached; a lead can genuinely have the tag without
            // callback_at ever getting set (e.g. it arrived already tagged
            // straight from Pancake before this app's own callback-set
            // logic ever ran on it — see SyncPancakeLeads::
            // backfillCallbackFromTags()'s own doc comment for that exact
            // class of gap).
            //
            // Scoped to today's own pancake_created_at, same convention as
            // Overdue/Callbacks above — orderBy('pancake_created_at')
            // (not assigned_at/callback_at, neither of which this view's
            // own membership rule depends on) so the newest orders show
            // first, matching the default Leads view's own ordering.
            // LOWER(disposition) LIKE, not a bare 'like' (real bug caught
            // live before this ever shipped, 2026-09-22): production runs
            // Postgres, whose LIKE is case-sensitive — a plain
            // ->where('disposition', 'like', '%not answering%') matched
            // ZERO of the 737 real rows containing "NOT ANSWERING" in
            // various cases ("Not answering ", "NOT ANSWERING - EYECARE",
            // etc. — confirmed live via tinker), which would have shipped
            // as a silently-empty page. Tests alone wouldn't have caught
            // this either: phpunit.xml runs SQLite, whose LIKE is
            // case-INsensitive by default, so the exact same code passes
            // there while returning nothing on the real database. LOWER()
            // on both sides works identically and correctly on every
            // driver this app runs on (sqlite for tests, pgsql in
            // production) — no driver-specific ILIKE/whereRaw branching
            // needed.
            $query->whereNotNull('disposition')
                ->where(function ($q) {
                    foreach (self::UNANSWERED_CALLS_TRIGGER_KEYWORDS as $keyword) {
                        $q->orWhereRaw('LOWER(disposition) LIKE ?', ['%' . strtolower($keyword) . '%']);
                    }
                })
                ->where(function ($q) use ($rangeFrom, $rangeTo) {
                    $q->whereBetween('pancake_created_at', [$rangeFrom, $rangeTo])
                        ->orWhereNull('pancake_created_at');
                })
                ->orderByDesc('pancake_created_at');
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
        //
        // Widened to also include dialed_at 2026-09-16 (explicit report,
        // with a screenshot: "why there's a checkmark but still it is in
        // the uncatered... it should be in the catered right?" — the
        // checkmark is the table's own dialed indicator; a TSA who's
        // already clicked to call a lead reads as having catered to it,
        // even before she's logged a final disposition, so this filter no
        // longer waits for that). Doesn't touch STATUS_FILTER_VALUES above
        // (the real Assigned/Called/Unassigned dropdown values) or the
        // Call Tracker Dashboard's own Catered KPI — those are separate,
        // unrelated to this one report.
        $status = $request->string('status')->toString();
        if (!$view && in_array($status, self::STATUS_FILTER_VALUES, true)) {
            $query->where('status', $status);
        } elseif (!$view && $status === 'catered') {
            $query->where(fn ($q) => $q->where('status', 'called')->orWhereNotNull('dialed_at'));
        } elseif (!$view && $status === 'uncatered') {
            $query->where('status', '!=', 'called')->whereNull('dialed_at');
        }

        // Order status filter (explicit request, 2026-09-08: "can you make
        // it there's a status filter too in this" — asked about the real
        // Pancake order-status pill column, e.g. New/Confirmed/Shipped, NOT
        // the $status filter just above, which is Lead's own local status
        // — 'order_status', a different query param, so the two never
        // collide). Applies on every view (Leads, Overdue, Callbacks alike)
        // — unlike $status above, a Pancake order's real status is an
        // independent fact about the order regardless of which queue view
        // is showing it, so there's no view-specific reason to gate this
        // one the way the Lead-status filter is.
        //
        // Lead/Order have no real relationship (see this controller's
        // $orderStatuses comment further below — just a shared
        // pancake_order_id string), so this is a subquery against the
        // locally-synced orders table rather than a join or scope on Lead
        // itself — same "trust the periodic sync, no live Pancake fetch
        // here" convention that table's own Status column already uses.
        $orderStatus = $request->string('order_status')->toString();
        if ($orderStatus !== '' && is_numeric($orderStatus)) {
            $query->whereIn('pancake_order_id', Order::where('status_code', (int) $orderStatus)->pluck('pancake_order_id'));
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
        // Filters on plain pancake_created_at, NOT COALESCE(assigned_at,
        // pancake_created_at) — regression fix, 2026-09-14: "i want only to
        // make it only today leads in every day... that is working before."
        // The COALESCE version (introduced 56f3b36, kept through f0dc3c9's
        // own rewrite despite that commit's stated rule being creation date
        // only) let the round-robin catch-up sweep (SyncPancakeLeads, "leads
        // that piled up as unassigned get automatically swept up... as soon
        // as someone logs in") make a WEEKS-old order reappear in today's
        // queue the moment it finally got assigned — confirmed live:
        // #1347666/#1347647, created 2026-08-11, assigned to Gemma on
        // 2026-09-14, several already Received/Returned in Pancake. That
        // directly contradicted f0dc3c9's own rule ("all of the newly
        // created order in the POS should be only in today") which this
        // COALESCE fallback had quietly overridden. The badge/Leads Setup
        // mismatch 56f3b36 fixed is a SEPARATE concern (how many leads got
        // assigned today, for a counter) from this queue (which orders a TSA
        // should be working right now) — conflating the two was the bug.
        //
        // Still fails open for a lead with NO pancake_created_at at all
        // (same convention as f0dc3c9's own "no data one way or the other is
        // never treated the same as confirmed no longer relevant") — this is
        // distinct from the bug above: a genuinely-null creation date means
        // "we don't know", whereas the bug was treating a known-old date as
        // if it were today just because assigned_at happened to be today.
        if (!$view) {
            $query->where(function ($q) use ($rangeFrom, $rangeTo) {
                $q->whereBetween('pancake_created_at', [$rangeFrom, $rangeTo])
                    ->orWhereNull('pancake_created_at');
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

        // "Currently being called by" indicator (explicit request,
        // 2026-09-18: Callbacks is shared across every TSA — "how will
        // they know that it is currently calling by other tsa" — with no
        // signal at all, two TSAs could both dial the same due callback
        // at once). Only computed for the Callbacks view: the plain Leads/
        // Overdue views are already scoped to one TSA's own queue, so
        // there's no "someone ELSE is on this" case to show there.
        //
        // "In progress" = the lead's most recent call_clicked activity
        // (LeadActivity, same source the Recently Called panel already
        // reads — user_id is the VIEWER who clicked, not the lead's own
        // tsa_id, so this correctly attributes a shared-queue pickup to
        // whoever actually dialed) landed within the last 10 minutes AND
        // that clicking TSA is still in Calling/Wrap Up right now.
        // Both conditions matter, not just one: TsaShift.status alone
        // can't say WHICH lead someone's mid-call on (it's a per-TSA, not
        // per-lead, field — logCallClick() flips it on the LEAD'S OWNER,
        // not the clicker, a separate existing quirk on a shared callback
        // — see that method's own comment), and a bare recent click alone
        // can't tell a genuine still-in-progress call apart from one that
        // already ended a few minutes ago. The 10-minute window is
        // deliberately generous (a real call + wrap-up easily runs that
        // long) but still short enough that a stale click from hours
        // earlier never falsely shows as "in progress."
        // Unanswered Calls (added 2026-09-22) is shared across every TSA
        // the same way Callbacks is (see this view's own scoping comment
        // above), so the same double-dial risk applies — any TSA can pick
        // up any lead here, meaning two TSAs really can both be dialing
        // the same customer at once with no other signal warning either
        // of them.
        $callingByLead = collect();
        if (in_array($view, ['callbacks', 'unanswered'], true) && $leads->isNotEmpty()) {
            $recentClicks = LeadActivity::whereIn('lead_id', $leads->pluck('id'))
                ->where('type', 'call_clicked')
                ->where('created_at', '>=', now()->subMinutes(10))
                ->whereNotNull('user_id')
                ->orderByDesc('id')
                ->with('user.tsa')
                ->get()
                ->unique('lead_id'); // newest click per lead only (already ordered desc).

            $callingByLead = $recentClicks
                ->filter(fn ($a) => in_array($a->user?->tsa?->status, [TsaShift::STATUS_CALLING, TsaShift::STATUS_WRAP_UP], true))
                ->mapWithKeys(fn ($a) => [$a->lead_id => $a->user->name]);
        }

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
            'callingByLead'         => $callingByLead,
            'tagColors'             => $tagColors,
            'tsas'                  => $user->isAtLeastAdmin() ? TsaShift::orderBy('sort_order')->get() : collect(),
            'selectedTsa'           => $request->integer('tsa'),
            // Renamed-team-aware (explicit follow-up request, 2026-09-04: "in
            // the settings when rename team i want in the call tracker is
            // will be change too") — order_team (the array KEY here) stays
            // the fixed, never-editable string every filter/query already
            // matches against; 'name' (the array VALUE) is the admin-editable
            // display label from Teams::config(), same pattern the main app's
            // own DashboardController already uses for this exact purpose.
            'teams'                 => collect(Teams::config())->pluck('name', 'order_team')->all(),
            'selectedTeam'          => $selectedTeam,
            // Options narrow to the picked team (all products when no team
            // is picked) — same "the dropdown can never offer something the
            // query itself would reject" guarantee STATUS_FILTER_VALUES
            // already gives the status filter above. A TSA (explicit
            // follow-up, 2026-09-02: "add product, status, search in the
            // tsa(normal user) in leads") gets a narrower list.
            //
            // Scoped to TsaShift::products() — TSA Management's own
            // "Handles" checkboxes (product_tsa pivot) — NOT "products
            // appearing on their own past leads" (regression fix,
            // 2026-09-14: "when this all product has check in tsa
            // management it should be like the filter of product in tsa
            // view is the only checked product") — the old
            // Lead-distinct-pluck version only ever reflected products a
            // TSA had ALREADY received a lead for, so checking a brand-new
            // product for a TSA in TSA Management had no effect on their
            // own Product filter until round-robin happened to hand them
            // one — a real, avoidable lag for something that should be
            // authoritative and immediate the moment an admin ticks the
            // checkbox.
            // ?->products() (not ->tsa->products()): a normal user's own
            // tsa_id can point at a TsaShift row that no longer resolves —
            // soft-deleted, or re-pointed at a stale id after that TSA's
            // record was deleted and re-added under a new id (confirmed
            // live, 2026-09-15: Hannah's User row still pointed at her old,
            // by-then-soft-deleted TsaShift id after hers was recreated,
            // and this line's unguarded ->tsa->products() 500'd her entire
            // Leads page — "Call to a member function products() on
            // null"). Falls back to an empty product list rather than
            // crashing the whole page; the real fix for a stale tsa_id is
            // still re-pointing that User row (done for Hannah), not
            // silently limping along forever, but this at least keeps the
            // page itself alive for whoever hits it next before that's
            // noticed and fixed.
            'products'              => $user->isAtLeastAdmin()
                ? Product::orderBy('sort_order')->when($selectedTeam, fn ($q) => $q->where('team', $selectedTeam))->get()
                : ($user->tsa?->products()->orderBy('sort_order')->get() ?? collect()),
            'selectedProduct'       => $request->integer('product'),
            'q'                     => $request->string('q')->toString(),
            'view'                  => $view,
            'selectedStatus'        => $status,
            // Order status filter dropdown options — Order::STATUS_PILL
            // itself, the exact same source of truth the Status pill column
            // already renders from, so the filter can never offer a status
            // the column itself wouldn't recognize.
            'orderStatusOptions'    => Order::STATUS_PILL,
            'selectedOrderStatus'   => $orderStatus !== '' && is_numeric($orderStatus) ? (int) $orderStatus : null,
            'dateFrom'              => $dateFromInput ?: $rangeFrom->toDateString(),
            'dateTo'                => $dateToInput ?: $rangeTo->toDateString(),
            'overdueThresholdMinutes' => self::overdueThresholdMinutes(),
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

        if (!$this->canAccess($lead, $user)) {
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

        if (!$this->canAccess($lead, $user)) {
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

        if (!$this->canAccess($lead, $user)) {
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

        // No new POS name tag pushed on a transfer (explicit request,
        // 2026-09-16: "is it possible that in call tracker it will not
        // have auto tagging when it's redistribute like that") — used to
        // call tagTsaOnPancakeOrder() here the same as a fresh round-robin
        // assignment; a lead CHANGING HANDS after that first assignment no
        // longer writes a new owner tag to the real Pancake order at all.

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

            // No new POS name tag pushed on a transfer — same 2026-09-16
            // change as the single-row transfer() above; see this
            // controller's own tagTsaOnPancakeOrder() doc comment.

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

        if (!$this->canAccess($lead, $user)) {
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

        if (!$this->canAccess($lead, $user)) {
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

        if (!$this->canAccess($lead, $user)) {
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
     * JSON feed for the Assignee picker — same "search a real Pancake
     * catalog as you type" idea as searchTags() above, but against the
     * shop's real staff directory (PancakeOrderTagApi::listStaff()) instead
     * of order tags, matching Pancake POS's own Assignee dropdown search
     * (explicit request, 2026-09-18: "in the leads modal has this icon too
     * like can assign the asignee too like in the pos").
     */
    public function searchStaff(Request $request, Lead $lead, PancakeOrderTagApi $api)
    {
        $user = Auth::user();

        if (!$this->canAccess($lead, $user)) {
            abort(403);
        }

        if (!$lead->pancake_order_id) {
            return response()->json(['success' => false, 'staff' => [], 'error' => 'This lead has no linked Pancake order.']);
        }

        $q     = trim((string) $request->input('q', ''));
        $staff = collect($api->listStaff());

        if ($q !== '') {
            $staff = $staff->filter(fn ($s) => stripos($s['name'] ?? '', $q) !== false)->values();
        }

        return response()->json(['success' => true, 'staff' => $staff->take(50)->values()]);
    }

    /**
     * Sets the real POS order's Assignee — the write side of searchStaff()
     * above (explicit request, 2026-09-18: same as that method's own doc
     * comment). $staffId null clears it back to Pancake's own "Choose a
     * staff member…" empty state, matching updateAssignee()'s own null
     * handling.
     */
    public function updateAssignee(Request $request, Lead $lead, PancakeOrderTagApi $api)
    {
        $user = Auth::user();

        if (!$this->canAccess($lead, $user)) {
            abort(403);
        }

        if (!$lead->pancake_order_id) {
            return response()->json(['success' => false, 'error' => 'This lead has no linked Pancake order.'], 422);
        }

        $data = $request->validate([
            'staff_id'     => ['nullable', 'string'],
            'staff_name'   => ['nullable', 'string', 'max:255'],
            'variation_id' => ['nullable', 'string'],
        ]);

        // Per-item when variation_id is given (the normal path from the
        // Products card's own per-row icon — explicit report, 2026-09-18:
        // "i want the assignee is the per product like this because it is
        // like in the pos ... but in the call tracker the 2 product
        // including upsell it has no assignee in that"), order-level
        // otherwise — see PancakeOrderTagApi::updateItemAssignee()'s own
        // doc comment for why both exist.
        $success = !empty($data['variation_id'])
            ? $api->updateItemAssignee($lead->pancake_order_id, $data['variation_id'], $data['staff_id'] ?? null)
            : $api->updateAssignee($lead->pancake_order_id, $data['staff_id'] ?? null);

        $label = $data['staff_name'] ?? ($data['staff_id'] ? $data['staff_id'] : 'No assigned staff');
        LeadActivity::log(
            $lead, 'assignee_changed',
            "Set Pancake assignee to \"{$label}\" by {$user->name}" . ($success ? '.' : ' — Pancake write failed, verify in POS.'),
            $user
        );

        if (!$success) {
            return response()->json(['success' => false, 'error' => 'Could not set the assignee in Pancake — try again or set it directly in POS.'], 500);
        }

        return response()->json([
            'success' => true,
            'assignee' => $data['staff_id'] ? ['id' => $data['staff_id'], 'name' => $data['staff_name']] : null,
        ]);
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

        if (!$this->canAccess($lead, $user)) {
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

        if (!$this->canAccess($lead, $user)) {
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

        if ($success) {
            $order = Order::where('pancake_order_id', $lead->pancake_order_id)->first();
            $order?->trackAppAddedTags(array_filter([$upsellTagName, $lead->tsa?->tsa_key]));
        }

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

        if (!$this->canAccess($lead, $user)) {
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

        if (!$this->canAccess($lead, $user)) {
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

        if (!$this->canAccess($lead, $user)) {
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

        if (!$this->canAccess($lead, $user)) {
            abort(403);
        }

        if (!$lead->pancake_order_id) {
            return response()->json(['success' => false, 'error' => 'This lead has no linked Pancake order.'], 422);
        }

        $data = $request->validate(['tag' => ['required', 'string', 'max:255']]);

        $success = $api->removeTagFromOrder($lead->pancake_order_id, $data['tag']);

        $remainingTags = collect();
        if ($success) {
            $order = Order::where('pancake_order_id', $lead->pancake_order_id)->first();
            if ($order) {
                $remainingTags = collect($order->raw_tags ?? [])
                    ->reject(fn ($t) => strcasecmp($t, $data['tag']) === 0)->values();
                $order->update(['raw_tags' => $remainingTags->all()]);
                $order->untrackAppAddedTag($data['tag']);
            }
        }

        LeadActivity::log(
            $lead, 'tag_removed',
            "Removed tag \"{$data['tag']}\" by {$user->name}" . ($success ? '.' : ' — Pancake write failed, verify in POS.'),
            $user
        );

        // Same "ownership follows whoever actually resolves a shared
        // Callbacks pickup" rule updateDisposition() already applies when a
        // TSA logs an Outcome (see that method's own doc comment, and the
        // 2026-09-16 request it quotes). Removing the tag straight from the
        // POS Tags chip panel is a second, equally real way a TSA resolves
        // one — confirmed live, 2026-09-18: Angel called Marisol's
        // callback-trigger-tagged lead, upsold it, and manually removed the
        // tag here instead of going through Log Outcome, and ownership
        // never followed her (at the time this was an "unattended" tag —
        // CALLBACK_TRIGGER_KEYWORDS has since changed to "call back" only,
        // 2026-09-22, but the same reassignment logic still applies to
        // whatever that constant currently matches). Only reassigns when:
        // (1) the tag actually removed was itself a callback trigger
        // (removing an unrelated tag says nothing about resolving the
        // callback); (2) the lead was genuinely in the shared queue
        // (callback_at was set); (3) no OTHER remaining real Pancake tag
        // still matches a trigger keyword (removing just one of several
        // matching tags hasn't actually resolved it yet); (4) the acting
        // TSA isn't already the owner; (5) not an admin override, same
        // reasoning as updateDisposition()'s own check.
        if ($success && $lead->callback_at !== null
            && collect(self::CALLBACK_TRIGGER_KEYWORDS)->contains(fn ($kw) => stripos($data['tag'], $kw) !== false)
            && !$remainingTags->contains(fn ($t) => collect(self::CALLBACK_TRIGGER_KEYWORDS)->contains(fn ($kw) => stripos($t, $kw) !== false))
            && !$user->isAtLeastAdmin() && $lead->tsa_id !== $user->tsa_id && $user->tsa_id) {
            $fromLabel = $lead->tsa?->display_name ?? 'Unassigned';
            $lead->update(['tsa_id' => $user->tsa_id, 'disposition' => null, 'callback_at' => null]);
            LeadActivity::log($lead, 'transferred', "Picked up from the shared Callbacks queue — reassigned from {$fromLabel} to {$user->name}.", $user);
        }

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

        if (!$this->canAccess($lead, $user)) {
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
                $order->trackAppAddedTags([$data['tag']]);
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

        if (!$this->canAccess($lead, $user)) {
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

        if (!$this->canAccess($lead, $user)) {
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

        // Tag-loss self-healing, extended to Notes (explicit request,
        // 2026-09-18: "even notes and the address when they edit or add
        // it should be reflect to the pos" — see the app_added_tags
        // migration's own doc comment for the underlying race this
        // protects against). Overwrite fields, not a merge, so this
        // records this app's own last-saved VALUE verbatim, only for the
        // field(s) actually submitted this time — a save that only sent
        // `note` must never overwrite the tracked note_print with a
        // stale/absent value.
        if ($success) {
            $order = Order::where('pancake_order_id', $lead->pancake_order_id)->first();
            if ($order) {
                $updates = [];
                if (array_key_exists('note', $data)) $updates['app_note'] = $data['note'] ?? '';
                if (array_key_exists('note_print', $data)) $updates['app_note_print'] = $data['note_print'] ?? '';
                if (!empty($updates)) $order->update($updates);
            }
        }

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

        if (!$this->canAccess($lead, $user)) {
            abort(403);
        }

        return response()->json(['success' => true, 'provinces' => $api->listProvinces()]);
    }

    public function deliveryDistricts(Request $request, Lead $lead, PancakeOrderTagApi $api)
    {
        $user = Auth::user();

        if (!$this->canAccess($lead, $user)) {
            abort(403);
        }

        $data = $request->validate(['province_id' => ['required', 'string']]);

        return response()->json(['success' => true, 'districts' => $api->listDistricts($data['province_id'])]);
    }

    public function deliveryCommunes(Request $request, Lead $lead, PancakeOrderTagApi $api)
    {
        $user = Auth::user();

        if (!$this->canAccess($lead, $user)) {
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

        if (!$this->canAccess($lead, $user)) {
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

        // Tag-loss self-healing, extended to Delivery/Address (explicit
        // request, 2026-09-18: "even notes and the address when they
        // edit or add it should be reflect to the pos" — see the
        // app_added_tags migration's own doc comment for the underlying
        // race). Records this app's own last-saved shipping address
        // verbatim, restored if a later live check shows Pancake no
        // longer matches it.
        if ($success) {
            $order = Order::where('pancake_order_id', $lead->pancake_order_id)->first();
            $order?->update(['app_shipping_address' => $shippingAddress]);
        }

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

        if (!$this->canAccess($lead, $user)) {
            abort(403);
        }

        if ($lead->tsa_id) {
            LeadActivity::log(
                $lead,
                'call_clicked',
                ($lead->tsa->display_name ?? 'A TSA') . ' clicked to call ' . ($lead->customer_name ?: 'this customer') . ' (' . ($lead->phone_number ?: $lead->dialable_number) . ').',
                $user
            );

            // Never flip a logged-out TSA back to Calling — real bug,
            // explicit report 2026-09-19 (Hannah, Marisol, Marsha all showed
            // LOGOUT on TSA Logs followed by later CALL/CALLING rows for the
            // same TSA). Same root cause TsaShift::resolveActiveOfPair()'s
            // own doc comment already root-caused for the MacroDroid
            // call-ended webhook (2026-09-18, "hannah just logout earlier
            // but it is still calling") — two TSAs sharing one physical
            // phone, and this click-to-call path never got the same fix:
            // it always flips $lead->tsa specifically, but on a shared
            // phone the person who actually clicked dial may be the
            // partner, working through the queue after the lead's assigned
            // TSA already logged out for the day. Logging out is a
            // deliberate "I'm done" signal that a click on a lead still
            // sitting in their queue must never silently override.
            $tsaToFlip = $lead->tsa;
            if ($tsaToFlip && $tsaToFlip->status === TsaShift::STATUS_LOGOUT) {
                $partner = $tsaToFlip->pairPartnerEitherSide();
                $tsaToFlip = ($partner && $partner->status !== TsaShift::STATUS_LOGOUT)
                    ? $partner
                    : null;
            }
            $tsaToFlip?->applyStatusChange(TsaShift::STATUS_CALLING);

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
     * JSON feed for the topbar's "Recently Called" panel (explicit
     * request, 2026-09-18: "they still will search to the main leads
     * still" — a follow-up to the earlier fix that auto-opens a lead's
     * detail modal right after a call; that alone still lost the lead if
     * the TSA closed the modal too quickly, back to exactly the same
     * "search the main Leads list" problem the original feedback was
     * about — "when they call in the overdue the that lead will be gone
     * and they will search it again manually in the leads"). A small,
     * persistent, always-reachable list of THIS viewer's own last few
     * dials — one click back into any of them, from anywhere in the app,
     * no typing/searching required even after closing every modal.
     *
     * Sourced from LeadActivity (type 'call_clicked', user_id = the
     * VIEWER, not the lead's own tsa_id) rather than Lead.dialed_at
     * directly — dialed_at is one shared column per lead with no record
     * of WHO clicked it, so it can't answer "what did *I* personally
     * just call" the way an admin covering multiple TSAs' leads needs.
     * LeadActivity::log()'s own user_id already captures exactly that,
     * every time logCallClick() fires.
     */
    public function recentlyCalled()
    {
        $user = Auth::user();

        // orderByDesc('id'), not created_at: this column is a plain
        // second-precision timestamp (no explicit microsecond precision),
        // so two clicks landing in the same second — confirmed live in
        // testing, two calls placed back-to-back — are indistinguishable
        // by created_at alone and can come back in the wrong order. id is
        // already a strictly-increasing insertion order, immune to that.
        //
        // Pulls a wider window (20) before deduping/limiting to 5 — a
        // redial of the same lead creates a SECOND 'call_clicked' row, so
        // limiting to 5 BEFORE dedup could consume the whole page on
        // repeat clicks of one or two leads and leave fewer than 5 (or
        // even 0) distinct leads in the final list.
        $leadIds = LeadActivity::where('user_id', $user->id)
            ->where('type', 'call_clicked')
            ->orderByDesc('id')
            ->limit(20)
            ->pluck('lead_id')
            ->unique()
            ->take(5);

        // Re-fetched by id (not just the activity rows) so this always
        // reflects the lead's CURRENT customer_name/phone/disposition —
        // an activity log entry is a frozen sentence from the moment it
        // was written, the lead itself may have moved on since.
        $leads = Lead::whereIn('id', $leadIds)->get()->sortBy(fn ($lead) => $leadIds->search($lead->id));

        return response()->json([
            'success' => true,
            'leads'   => $leads->values()->map(fn (Lead $lead) => [
                'id'           => $lead->id,
                'customerName' => $lead->customer_name ?: 'this customer',
                'phoneNumber'  => $lead->phone_number,
                'calledAt'     => $lead->dialed_at?->format('M j, g:i A'),
            ]),
        ]);
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

        if (!$this->canAccess($lead, $user)) {
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

        if (!$this->canAccess($lead, $user)) {
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
        if (!$this->canAccess($lead, $user)) {
            abort(403);
        }

        // The callback picker submits a bare "HH:MM" now, not a full
        // datetime (explicit request, 2026-09-22: "there's no date should
        // be only time" — see leads/_table.blade.php's own comment on the
        // <input type="time"> that replaced type="datetime-local"). Real
        // bug caught by this method's own test before it ever shipped:
        // Laravel's 'date' validation rule uses strtotime()/DateTime, NOT
        // Carbon::parse() — strtotime('14:30') actually fails validation
        // even though Carbon::parse('14:30') (used just below, after
        // validation) resolves it to today just fine. Left as a bare
        // "HH:MM" the whole request 422'd, silently dropping every
        // callback a TSA tried to schedule. Normalized to today's full
        // datetime HERE, before validation ever runs, so 'date' always
        // sees a value it actually accepts — same value Carbon::parse()
        // below would have produced anyway, just computed a few lines
        // earlier so validation doesn't reject it first.
        if ($request->filled('callback_at') && preg_match('/^\d{1,2}:\d{2}$/', $request->input('callback_at'))) {
            $request->merge(['callback_at' => today()->format('Y-m-d') . ' ' . $request->input('callback_at')]);
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

        // "Call Back" ONLY needs a due time to ever show up on the
        // Callbacks view (see CALLBACK_TRIGGER_KEYWORDS' own doc comment —
        // reversed 2026-09-22, previously Unattended/Not Answering) —
        // default to +1 day if the TSA didn't pick one via the datetime
        // input the frontend reveals for this exact same tag (calls.js'
        // setSelectedTags(), gated on 'call back' too), rather than
        // silently having no due date at all. Case-insensitive substring
        // match over the WHOLE joined string, so this still fires when
        // "Call Back" is only one of several tags picked alongside others
        // — same keyword convention TSD Reports itself uses for
        // disposition matching (ProductPerformance::count()).
        $callbackAt = null;
        $needsFollowUp = self::CALLBACK_TRIGGER_KEYWORDS;
        if (collect($needsFollowUp)->contains(fn ($kw) => stripos($data['disposition'], $kw) !== false)) {
            $callbackAt = !empty($data['callback_at'])
                ? Carbon::parse($data['callback_at'])
                : now()->addDay();
        }

        // Ownership follows whoever actually resolves a shared Callbacks
        // pickup (explicit request, 2026-09-16: "gemma leads has unattended
        // but julie call that unattended and removed the unattended...i
        // want to make it like it will automatically julie's leads that")
        // — Callbacks is the one queue where canAccess() already lets a TSA
        // act on a lead she doesn't own (any TSA, once callback_at is due,
        // see this controller's own canAccess() comment). Only reassigns
        // when: (1) the logging TSA genuinely isn't the current owner —
        // never touches the normal same-TSA case; (2) she got in through
        // that due-callback door, not an admin override — an admin logging
        // on someone's behalf is correcting a record, not picking up the
        // call herself, so ownership must stay put; (3) the NEW disposition
        // actually resolves it ($callbackAt is null here) — logging another
        // Unattended/Not Answering is still an unresolved attempt, not a
        // real pickup, so the lead stays shared/up for grabs rather than
        // quietly reassigning on every failed re-attempt.
        $pickedUpViaCallbacksQueue = !$user->isAtLeastAdmin() && $lead->tsa_id !== $user->tsa_id;
        $reassignFromLabel = null;
        if ($pickedUpViaCallbacksQueue && $callbackAt === null && $user->tsa_id) {
            $reassignFromLabel = $lead->tsa?->display_name ?? 'Unassigned';
            $lead->tsa_id = $user->tsa_id;
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

        if ($reassignFromLabel !== null) {
            LeadActivity::log($lead, 'transferred', "Picked up from the shared Callbacks queue — reassigned from {$reassignFromLabel} to {$user->name}.", $user);
        }

        if ($callbackAt) {
            LeadActivity::log($lead, 'callback_scheduled', 'Callback set for ' . $callbackAt->format('M j, g:i A') . '.', $user);
        }

        $this->tagOutcomeInPancake($lead, $data['disposition'], $api);

        // JSON branch (explicit request, 2026-09-22: "i want to make it
        // like this in my dial modal" — the Calling modal's own new
        // Callback quick-pick section submits here via fetch, not a plain
        // form POST, since the modal itself stays open/gets replaced by
        // the resume banner rather than navigating away — same
        // wantsJson() convention TsaStatusController::update() already
        // uses). The plain-form path (Leads table row's own
        // disposition-form) is completely unaffected — back() with a
        // flash message, unchanged.
        if ($request->wantsJson()) {
            return response()->json([
                'success'     => true,
                'disposition' => $data['disposition'],
                'callback_at' => $callbackAt?->toIso8601String(),
                'message'     => "Logged \"{$data['disposition']}\" for {$lead->customer_name}.",
            ]);
        }

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
     * leads it is auto tagging ... because it is their leads") so a fresh
     * round-robin assignment (SyncPancakeLeads) can push the same tag
     * immediately, not only once a call outcome is eventually logged.
     *
     * Deliberately NOT called anymore on a REDISTRIBUTION — transfer(),
     * bulkTransfer(), and LogoutLeadRedistributor all used to call this
     * too, same as a fresh assignment, but that's reversed as of
     * 2026-09-16 (explicit request: "is it possible that in call tracker
     * it will not have auto tagging when it's redistribute like that") —
     * a lead CHANGING HANDS after its first assignment no longer pushes a
     * new owner tag to the real Pancake order at all. Only SyncPancakeLeads
     * (the very first assignment) and tagOutcomeInPancake() (a real logged
     * call outcome, not a hand-off) still call this.
     *
     * Same silent-no-op conventions as before: no linked order yet, or the
     * 'pos_auto_tagging_enabled' Setting is off — not fatal, a lead's local
     * assignment is never blocked on Pancake being reachable.
     *
     * The TSA tag actually pushed is resolved through her tag_keywords
     * aliases first, not always her bare tsa_key (explicit report,
     * 2026-09-16, with a screenshot: "why now there's a leads that is not
     * auto tagging like tsa tag name like that but there's sometimes that
     * is auto tagging" — first looked like Kathleen/Grace Olivo/Angel
     * Margallo's tsa_key just had no matching Pancake tag at all, but a
     * follow-up screenshot of the real POS tag search ("there's angel in
     * the POS... it should be angel") corrected that: their real Pancake
     * tag is a DIFFERENT alias than tsa_key — GoogleDriveClient::
     * folderBelongsToTsa() already root-caused this exact shape on
     * 2026-09-08 for Drive folder matching (Angel Margallo's tsa_key
     * "Angelica" has 851 real orders already attributed under that exact
     * string, so it can't just be renamed — her REAL Drive folder, and her
     * real Pancake tag, is "ANGEL", her tag_keywords' other entry; Grace
     * Olivo/"Joanna" is the identical shape, real tag "GRACE"). Reusing
     * tag_keywords here the same way, rather than tsa_key, so this picks
     * the SAME real tag Drive folder-matching already resolves to.
     * createTagIfMissing() on the bare tsa_key is now only a last resort
     * for a TSA who genuinely has no matching tag under ANY alias yet.
     */
    public static function tagTsaOnPancakeOrder(Lead $lead, PancakeOrderTagApi $api, array $extraTags = []): void
    {
        if (!$lead->pancake_order_id) {
            return;
        }

        $tsa = $lead->tsa;
        $tsaTag = Setting::get('pos_auto_tagging_enabled', true) && $tsa ? self::resolveTsaTagName($tsa, $api) : null;

        $tagNames = collect($extraTags)->push($tsaTag)->filter()->unique()->values()->all();
        if (empty($tagNames)) {
            return;
        }

        $results = $api->addTagsToOrder($lead->pancake_order_id, $tagNames);

        $succeeded = [];
        foreach ($results as $tagName => $success) {
            if ($success) {
                $succeeded[] = $tagName;
            } else {
                Log::warning("Could not tag \"{$tagName}\" on order {$lead->pancake_order_id} in Pancake.");
            }
        }

        if (!empty($succeeded)) {
            Order::where('pancake_order_id', $lead->pancake_order_id)->first()?->trackAppAddedTags($succeeded);
        }
    }

    /**
     * Picks which of $tsa's own aliases (tsa_key, then each tag_keywords
     * entry, in that order) actually exists as a real tag in Pancake's
     * catalog — see tagTsaOnPancakeOrder()'s own doc comment for why
     * tsa_key alone isn't always the right one to push. Falls through to
     * creating tsa_key itself as a brand-new tag only when NONE of her
     * aliases match anything real yet.
     */
    private static function resolveTsaTagName(TsaShift $tsa, PancakeOrderTagApi $api): string
    {
        $catalog = collect($api->listTags())->pluck('name');

        foreach ([$tsa->tsa_key, ...$tsa->tag_keywords_array] as $alias) {
            $match = $catalog->first(fn ($t) => strcasecmp(trim($t), trim($alias)) === 0);
            if ($match !== null) {
                return $match;
            }
        }

        $api->createTagIfMissing($tsa->tsa_key);

        return $tsa->tsa_key;
    }
}
