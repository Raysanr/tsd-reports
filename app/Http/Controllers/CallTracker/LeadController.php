<?php

namespace App\Http\Controllers\CallTracker;

use App\Http\Controllers\Controller;
use App\Models\Lead;
use App\Models\LeadActivity;
use App\Models\Setting;
use App\Models\TsaShift;
use App\Support\PancakeConversationApi;
use App\Support\PancakeOrderTagApi;
use App\Support\PancakeProductApi;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

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

    public function index(Request $request)
    {
        $user = Auth::user();
        $view = $request->string('view')->toString(); // '', 'overdue', 'callbacks'

        $query = Lead::with(['product', 'tsa'])->latest('pancake_created_at');

        // A TSA only ever sees their own queue; an admin can optionally
        // narrow to one TSA via ?tsa=, defaulting to everyone's.
        if (!$user->isAtLeastAdmin()) {
            $query->where('tsa_id', $user->tsa_id);
        } elseif ($request->filled('tsa')) {
            $query->where('tsa_id', $request->integer('tsa'));
        }

        if ($view === 'overdue') {
            // Assigned but nobody's called it yet, and it's been sitting
            // long enough that this is no longer "hasn't gotten to it yet" —
            // exactly the gap that let a lead sit uncalled for hours before
            // anyone noticed.
            $query->where('status', 'assigned')
                ->where('assigned_at', '<=', now()->subHours(self::overdueThresholdHours()))
                ->orderBy('assigned_at');
        } elseif ($view === 'callbacks') {
            // A TSA promised to call back by a specific time — due now or
            // already past due, not "someday in the future".
            $query->whereNotNull('callback_at')
                ->where('callback_at', '<=', now())
                ->orderBy('callback_at');
        } elseif ($request->filled('status')) {
            $query->where('status', $request->string('status'));
        }

        if ($user->isAtLeastAdmin() && $request->filled('q')) {
            $q = trim($request->string('q'));
            $query->where(function ($sub) use ($q) {
                $sub->where('customer_name', 'like', "%{$q}%")
                    ->orWhere('phone_number', 'like', "%{$q}%")
                    ->orWhere('pancake_order_id', 'like', "%{$q}%");
            });
        }

        // Date range only applies to the default view — Overdue/Callbacks
        // already have their own date-based ordering (assigned_at/callback_at
        // thresholds), which a second, independent creation-date filter would
        // just conflict with. An empty date_from/date_to (the normal,
        // un-filtered state) shows every lead exactly as before — nothing
        // here narrows the result set unless a range was picked.
        $dateFrom = $request->string('date_from')->toString();
        $dateTo   = $request->string('date_to')->toString();
        if (!$view && $dateFrom && $dateTo) {
            $query->whereBetween('pancake_created_at', [
                Carbon::parse($dateFrom)->startOfDay(),
                Carbon::parse($dateTo)->endOfDay(),
            ]);
        }

        $leads = $query->paginate(30)->withQueryString();

        $data = [
            'leads'                 => $leads,
            'tsas'                  => $user->isAtLeastAdmin() ? TsaShift::orderBy('sort_order')->get() : collect(),
            'selectedTsa'           => $request->integer('tsa'),
            'selectedStatus'        => $request->string('status')->toString(),
            'q'                     => $request->string('q')->toString(),
            'view'                  => $view,
            'dateFrom'              => $dateFrom,
            'dateTo'                => $dateTo,
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
     * TSD Reports' own sync). This is the ONLY thing that writes a tag to
     * Pancake now — round-robin assignment itself (SyncPancakeLeads) is a
     * local-only signal, no automatic tag on a new lead. Silently no-ops
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
