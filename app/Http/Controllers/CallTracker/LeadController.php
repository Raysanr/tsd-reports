<?php

namespace App\Http\Controllers\CallTracker;

use App\Http\Controllers\Controller;
use App\Models\Lead;
use App\Models\LeadActivity;
use App\Models\Order;
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
        $status = $request->string('status')->toString();
        if (!$view && in_array($status, self::STATUS_FILTER_VALUES, true)) {
            $query->where('status', $status);
        }

        if ($user->isAtLeastAdmin() && $request->filled('q')) {
            $q = trim($request->string('q'));
            $query->where(function ($sub) use ($q) {
                $sub->where('customer_name', 'like', "%{$q}%")
                    ->orWhere('phone_number', 'like', "%{$q}%")
                    ->orWhere('pancake_order_id', 'like', "%{$q}%");
            });
        }

        // The default view only applies the window when a range was
        // actually picked — an empty date_from/date_to (the normal,
        // un-filtered state) shows every lead exactly as before, unlike
        // Overdue/Callbacks above which always have a window (defaulting to
        // today). Filters on COALESCE(assigned_at, pancake_created_at)
        // rather than plain pancake_created_at (root-caused 2026-08-15: the
        // sidebar badge and Leads Setup both count "assigned today"
        // via assigned_at, but this list was filtering by creation date — a
        // lead created yesterday and picked up by round-robin TODAY showed
        // in the badge's "17" but not in this filtered list's "7"). Falls
        // back to pancake_created_at for a still-unassigned lead, which has
        // no assigned_at yet.
        if (!$view && $dateFromInput && $dateToInput) {
            $query->whereRaw('COALESCE(assigned_at, pancake_created_at) BETWEEN ? AND ?', [$rangeFrom, $rangeTo]);
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
            ->get(['pancake_order_id', 'status_code', 'raw_tags'])
            ->keyBy('pancake_order_id');
        $orderStatuses = $orders->map->status_code;
        $orderTags     = $orders->map(fn ($o) => $o->raw_tags ?? []);

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
            'tagColors'             => $tagColors,
            'tsas'                  => $user->isAtLeastAdmin() ? TsaShift::orderBy('sort_order')->get() : collect(),
            'selectedTsa'           => $request->integer('tsa'),
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

    public function show(Lead $lead)
    {
        $user = Auth::user();

        if (!$user->isAtLeastAdmin() && $lead->tsa_id !== $user->tsa_id) {
            abort(403);
        }

        $lead->load(['product', 'tsa', 'calledBy', 'activities.user']);

        return view('calls.leads.show', ['lead' => $lead]);
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
    public function transfer(Request $request, Lead $lead)
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

        return response()->json(['success' => true, 'message' => "Transferred to {$newTsa->display_name}."]);
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
    public function streamRecording(Lead $lead, string $fileId, GoogleDriveClient $drive)
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
        $bytes = $token ? $drive->downloadFile($token, $fileId) : null;
        if ($bytes === null) {
            abort(404);
        }

        return response($bytes, 200, [
            'Content-Type'   => 'audio/mp4',
            'Content-Length' => (string) strlen($bytes),
        ]);
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

        $folder = $drive->resolveTsaFolder($token, $lead->tsa);
        if (!$folder) {
            return [];
        }

        $digits = preg_replace('/[^0-9]/', '', $lead->phone_number);
        $last9  = substr($digits, -9);
        if (strlen($last9) < 9) {
            return [];
        }

        return collect($drive->listChildren($token, $folder['id']))
            ->filter(fn ($f) => str_ends_with(strtolower($f['name']), '.m4a')
                && str_contains(preg_replace('/[^0-9]/', '', $f['name']), $last9))
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
     * TSD Reports' own sync). This and removeTag() above are the only things
     * that write a tag to Pancake now — round-robin assignment itself
     * (SyncPancakeLeads) is a local-only signal, no automatic tag on a new
     * lead. Silently no-ops
     * (with a logged warning) per-tag when there's no linked order or a tag
     * doesn't exist in the real catalog — same "feature unavailable, not
     * fatal" convention as elsewhere; a TSA's outcome is still saved
     * locally either way.
     */
    private function tagOutcomeInPancake(Lead $lead, string $disposition, PancakeOrderTagApi $api): void
    {
        if (!$lead->pancake_order_id) {
            return;
        }

        $tagNames = self::splitTags($disposition)->push($lead->tsa?->tsa_key)->filter()->unique()->values()->all();
        $results  = $api->addTagsToOrder($lead->pancake_order_id, $tagNames);

        foreach ($results as $tagName => $success) {
            if (!$success) {
                Log::warning("Could not tag \"{$tagName}\" on order {$lead->pancake_order_id} in Pancake.");
            }
        }
    }
}
