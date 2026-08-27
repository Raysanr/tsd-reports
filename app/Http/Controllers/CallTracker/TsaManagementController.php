<?php

namespace App\Http\Controllers\CallTracker;

use App\Http\Controllers\Concerns\PersistsCallTrackerFilters;
use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Models\Setting;
use App\Models\TsaShift;
use App\Models\User;
use App\Support\ActivityLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

/**
 * Ported from call-tracker (merged into one app 2026-08-12) as "Call
 * Rotation" (see routes/web.php's calls.tsa-management name) — disambiguates
 * from the existing /tsa-management (shift/rest-day roster) page; same
 * underlying tsa_shifts rows, different concern. Tsa -> TsaShift; the
 * hardcoded TEAMS const is replaced with config('teams') per the merge plan
 * (identical values, single source of truth instead of a second copy).
 *
 * NOTE: active/phone_number/dialer_host/api_token/status/status_changed_at/
 * status_locked_by and TsaShift::STATUSES/generateApiToken() don't exist on
 * TsaShift until Phase 4's schema-extending migration — this whole page
 * errors at runtime until then (flagged in the Phase 2 report).
 */
class TsaManagementController extends Controller
{
    use PersistsCallTrackerFilters;

    public function index(Request $request)
    {
        // Remembered across a tab-away-and-back navigation (explicit
        // request, 2026-08-24) — see PersistsCallTrackerFilters's own doc
        // comment. Falls back to '' (empty string, not the 'all' string
        // convention some other pages use), matching this page's own
        // existing "falsy = no filter applied" default.
        $team = $this->rememberedFilter($request, 'tsa-management', 'team', '') ?? '';

        $query = TsaShift::with('user')->orderBy('sort_order');
        if ($team) {
            $query->where('team', $team);
        }
        $tsas = $query->get();

        $products = Product::orderBy('sort_order')->get();

        // tsa_id => [product_id, ...] currently assigned — one query instead
        // of N+1'ing $tsa->products for every row in the view.
        $assignments = DB::table('product_tsa')->get()
            ->groupBy('tsa_id')
            ->map(fn ($rows) => $rows->pluck('product_id')->all());

        $data = [
            'tsas'         => $tsas,
            'products'     => $products,
            'assignments'  => $assignments,
            'teams'        => collect(config('teams'))->pluck('order_team')->all(),
            'selectedTeam' => $team,
            // Candidates for "Link to an existing account" below — explicit
            // request, 2026-08-26: TSAs already have real accounts (role=
            // 'normal', added via User Management the normal way) that were
            // just never connected to a tsa_shifts row. 'normal' only, not
            // 'admin'/'super_admin'/'guest' — an admin account showing up in
            // this picker and getting linked as a TSA would be a real
            // privilege mix-up, not a convenience. Already-linked users
            // excluded so a name that's ambiguous in User Management (two
            // real "Julie Francisco" accounts, confirmed live) narrows down
            // to just the one still unclaimed once the other's picked.
            'linkableUsers' => User::where('role', 'normal')->whereNull('tsa_id')->orderBy('name')->get(),
            // Explicit request (2026-08-24): the table's old "Active" column
            // (a manual admin toggle) is replaced with the TSA's real live
            // status (Login/Break/Logout/etc.) — status already tells you
            // whether someone's actually working, making the separate
            // active flag redundant to manage by hand.
            'statuses'     => TsaShift::STATUSES,
        ];

        // Same X-Table-Refresh convention as Leads Setup's own team-filter
        // pills (RoundRobinSetupController::index()) — the filter fetches
        // this same URL in the background and swaps in just the table with a
        // fade instead of a full page reload.
        if ($request->header('X-Table-Refresh')) {
            return view('calls.tsa-management._table', $data);
        }

        return view('calls.tsa-management', $data);
    }

    /**
     * Adds a brand-new TSA — same "search the real Pancake POS account
     * list, don't free-type a name" flow as TSD Reports' own Add TSA. A
     * fresh TSA starts with zero product assignments (nothing round-robins
     * to them until an admin checks boxes on their card below).
     */
    public function store(Request $request)
    {
        $teams = collect(config('teams'))->pluck('order_team')->all();

        $data = $request->validate([
            'display_name' => ['required', 'string', 'max:100'],
            'team'         => ['required', 'string', 'in:' . implode(',', $teams)],
            'pos_user_id'  => ['nullable', 'string', 'max:100'],
        ]);

        $tsaKey   = $this->generateUniqueKey($data['display_name']);
        $nextSort = (int) (TsaShift::max('sort_order') ?? 0) + 1;

        $tsa = TsaShift::create([
            'tsa_key'      => $tsaKey,
            'pos_user_id'  => $data['pos_user_id'] ?? null,
            'display_name' => $data['display_name'],
            'team'         => $data['team'],
            'active'       => true,
            'sort_order'   => $nextSort,
        ]);

        $message = "Added \"{$data['display_name']}\" to {$data['team']}.";
        ActivityLogger::log('tsa.created', $tsa, $message);

        return redirect()->route('calls.tsa-management')->with('success', $message);
    }

    public function update(Request $request, TsaShift $tsaShift)
    {
        $data = $request->validate([
            'products'     => ['array'],
            'products.*'   => ['integer', 'exists:products,id'],
            'phone_number' => ['nullable', 'string', 'max:32'],
            'dialer_host'  => ['nullable', 'string', 'max:64'],
        ]);

        // No 'active' here (explicit request, 2026-08-24 — the checkbox that
        // used to control it is gone) — every TSA created via store() is
        // already active=true and stays that way; this form no longer
        // touches the column at all, so a save here can never silently
        // deactivate someone the way it would have if this still read
        // $request->boolean('active') against a form with no such field.
        $tsaShift->update([
            'phone_number' => $data['phone_number'] ?? null,
            'dialer_host'  => $data['dialer_host'] ?? null,
        ]);

        $selected = collect($data['products'] ?? [])->map(fn ($id) => (int) $id);
        $current  = $tsaShift->products()->pluck('products.id');

        $toRemove = $current->diff($selected);
        if ($toRemove->isNotEmpty()) {
            // Safe even mid-rotation — RoundRobinAssigner::next() already
            // treats a last-picked TSA that fell out of the roster as "start
            // over from the top" rather than erroring.
            $tsaShift->products()->detach($toRemove->all());
        }

        // Newly checked products: append to the END of that product's
        // existing rotation (not position 0) — a TSA just added to a queue
        // shouldn't jump ahead of everyone already waiting their turn.
        $toAdd = $selected->diff($current);
        foreach ($toAdd as $productId) {
            $maxPosition = DB::table('product_tsa')->where('product_id', $productId)->max('position');
            $tsaShift->products()->attach($productId, [
                'position' => $maxPosition === null ? 0 : $maxPosition + 1,
            ]);
        }

        $productNames = Product::whereIn('id', $selected)->pluck('display_name')->implode(', ') ?: 'none';
        ActivityLogger::log('tsa.updated', $tsaShift, "Updated {$tsaShift->display_name} — handles: {$productNames}.");

        $message = "Updated {$tsaShift->display_name}.";

        // AJAX save (explicit request, 2026-08-18) — the Save form
        // (calls/tsa-management/_table.blade.php) submits via fetch with
        // Accept: application/json so the row's expanded panel and scroll
        // position stay exactly as the admin left them, confirmed with a
        // toast instead of the old full-page redirect. wantsJson() keeps a
        // plain (no-JS) form POST working the same as before, unaffected.
        if ($request->wantsJson()) {
            return response()->json(['success' => true, 'message' => $message]);
        }

        return redirect()->route('calls.tsa-management')->with('success', $message);
    }

    /**
     * Issues a fresh api_token for this TSA's phone-side call automation
     * (MacroDroid — see CallEventController's own doc comment) — a
     * deliberate, separate action rather than a side effect of the general
     * update() above, since regenerating silently invalidates whatever's
     * already pasted into their phone and would break call logging without
     * anyone noticing until reimbursement numbers looked wrong.
     */
    public function regenerateApiToken(Request $request, TsaShift $tsaShift)
    {
        $tsaShift->update(['api_token' => TsaShift::generateApiToken()]);

        ActivityLogger::log('tsa.token_regenerated', $tsaShift, "Regenerated {$tsaShift->display_name}'s call-automation token.");

        $message = "New token generated for {$tsaShift->display_name} — update it in their phone's automation app.";

        // AJAX regenerate (explicit request, 2026-08-27: "i want when in
        // every generate token it is not resetting the whole page ... a
        // small pop up") — same convention as update() above: re-render
        // just the token card (now showing the fresh api_token/webhook
        // fields and setup guide) and hand it back as HTML for calls.js to
        // swap in, confirmed with a toast instead of the old full-page
        // redirect that collapsed the expanded row panel every time.
        if ($request->wantsJson()) {
            return response()->json([
                'success' => true,
                'message' => $message,
                'html' => view('calls.tsa-management._token-card', ['tsa' => $tsaShift])->render(),
            ]);
        }

        return redirect()->route('calls.tsa-management')->with('success', $message);
    }

    /**
     * Connects an EXISTING account to this TSA — explicit request,
     * 2026-08-26: confirmed live (User Management screenshot) that every
     * TSA already has a real account, added the normal way, that just was
     * never linked to their tsa_shifts row. Deliberately not account
     * creation (that path was tried and reverted the same day — real
     * accounts already existed, a second "give login" flow would only
     * have created duplicates). role/is_active/password on the User row
     * are untouched — only tsa_id changes, so whatever main-app reporting
     * access a 'normal' role already carries is unaffected either way.
     *
     * $request->user_id is constrained to the exact same eligible set the
     * dropdown was built from (see index()'s own comment) — a plain
     * TsaShift-side foreign key check would let someone submit an admin's
     * id by hand.
     */
    public function linkUser(Request $request, TsaShift $tsaShift)
    {
        abort_if($tsaShift->user, 409, "{$tsaShift->display_name} is already linked to an account.");

        $data = $request->validate([
            'user_id' => ['required', 'integer'],
        ]);

        $user = User::where('role', 'normal')->whereNull('tsa_id')->findOrFail($data['user_id']);
        $user->update(['tsa_id' => $tsaShift->id]);

        $message = "Linked {$user->name} ({$user->email}) to {$tsaShift->display_name}.";
        ActivityLogger::log('tsa.user_linked', $tsaShift, $message);

        return redirect()->route('calls.tsa-management')->with('success', $message);
    }

    /** Clears the link without touching the account itself (role, is_active,
     *  password, etc. all untouched) — a safety valve for the wrong account
     *  getting picked, e.g. one of the two real "Julie Francisco" accounts
     *  confirmed live in User Management. */
    public function unlinkUser(TsaShift $tsaShift)
    {
        $user = $tsaShift->user;
        abort_unless($user, 404);

        $user->update(['tsa_id' => null]);

        $message = "Unlinked {$user->name} from {$tsaShift->display_name}.";
        ActivityLogger::log('tsa.user_unlinked', $tsaShift, $message);

        return redirect()->route('calls.tsa-management')->with('success', $message);
    }

    /** AJAX — search the real Pancake POS user list for the Add TSA picker. */
    public function searchPosUsers(Request $request): JsonResponse
    {
        $query = trim((string) $request->input('q', ''));
        $users = $this->fetchPosUsers();

        if ($query !== '') {
            $users = $users->filter(fn ($u) => stripos($u['name'], $query) !== false)->values();
        }

        return response()->json($users->take(25)->values());
    }

    /** Fetch + cache the shop's full POS user list (name/id pairs), noisy
     *  system rows filtered out — same convention as TSD Reports' own
     *  fetchPosUsers(). */
    private function fetchPosUsers()
    {
        return Cache::remember('pancake_pos_users', 600, function () {
            $apiKey = Setting::get('pancake_api_key', '');
            $shopId = Setting::get('shop_id', '');

            if (empty($apiKey) || empty($shopId)) {
                return collect();
            }

            $response = Http::withHeaders(['Accept' => 'application/json'])
                ->timeout(15)
                ->get("https://pos.pages.fm/api/v1/shops/{$shopId}/users", ['api_key' => $apiKey]);

            if (!$response->successful()) {
                return collect();
            }

            return collect($response->json('data', []))
                ->map(fn ($row) => [
                    'id'   => $row['id'] ?? null,
                    'name' => trim($row['user']['name'] ?? ''),
                ])
                ->filter(fn ($u) => $u['id'] && $u['name'] !== '' && !str_contains(strtoupper($u['name']), 'API_CONNECTION'))
                ->unique('id')
                ->sortBy('name')
                ->values();
        });
    }

    /** First word of the name, uniquified against existing tsa_key values —
     *  same derivation TSD Reports uses, so a TSA's key means the same
     *  thing in both apps if the same name is ever added to both. */
    private function generateUniqueKey(string $displayName): string
    {
        $base = preg_replace('/[^A-Za-z]/', '', strtok(trim($displayName), ' ')) ?: 'Tsa';
        $base = ucfirst(strtolower($base));

        $key = $base;
        $i   = 2;
        while (TsaShift::where('tsa_key', $key)->exists()) {
            $key = $base . $i;
            $i++;
        }

        return $key;
    }
}
