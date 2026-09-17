<?php

namespace Tests\Feature;

use App\Models\Lead;
use App\Models\Order;
use App\Models\Product;
use App\Models\Setting;
use App\Models\TsaShift;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** Ported from call-tracker (merged into one app 2026-08-12): Tsa -> TsaShift, routes -> calls.*. */
class LeadControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_tsa_only_sees_their_own_leads(): void
    {
        $gemma = TsaShift::where('tsa_key', 'Gemma')->first();
        $mariel = TsaShift::where('tsa_key', 'Mariel')->first();
        $product = Product::where('display_name', 'SINUXYL')->first();

        Lead::create(['pancake_order_id' => '1', 'customer_name' => 'Gemma Lead', 'product_id' => $product->id, 'tsa_id' => $gemma->id, 'status' => 'assigned']);
        Lead::create(['pancake_order_id' => '2', 'customer_name' => 'Mariel Lead', 'product_id' => $product->id, 'tsa_id' => $mariel->id, 'status' => 'assigned']);

        $user = User::create(['name' => 'Gemma User', 'email' => 'gemma@test.com', 'password' => bcrypt('x'), 'is_active' => true, 'role' => 'tsa', 'tsa_id' => $gemma->id]);

        $response = $this->actingAs($user)->get(route('calls.leads.index'));

        $response->assertOk();
        $response->assertSee('Gemma Lead');
        $response->assertDontSee('Mariel Lead');
    }

    /**
     * Confirmed live, 2026-09-15 (Hannah Ascano): a normal user's tsa_id can
     * point at a TsaShift row that no longer resolves — soft-deleted, or
     * re-pointed at a stale id after that TSA's record was deleted and
     * re-added under a new id. index()'s 'products' key used to call
     * $user->tsa->products() with no null guard, which fataled with "Call to
     * a member function products() on null" and 500'd the entire Leads page
     * for that TSA. Now falls back to an empty product list instead — the
     * real fix for a stale tsa_id is still re-pointing that User row, but
     * this keeps the page itself alive in the meantime.
     */
    public function test_a_normal_user_with_a_stale_tsa_id_does_not_crash_the_leads_page(): void
    {
        // Same real-world shape as Hannah's account: her User row points at
        // a TsaShift id that was later soft-deleted (the record was removed
        // and re-added under a new id), leaving tsa_id referencing a row
        // TsaShift::find() can no longer see.
        $shift = TsaShift::where('tsa_key', 'Gemma')->first();
        $user = User::create([
            'name' => 'Orphaned User', 'email' => 'orphaned@test.com', 'password' => bcrypt('x'),
            'is_active' => true, 'role' => 'normal', 'tsa_id' => $shift->id,
        ]);
        $shift->delete();

        $response = $this->actingAs($user)->get(route('calls.leads.index'));

        $response->assertOk();
    }

    /**
     * Reversed for the Callbacks view specifically (explicit request,
     * 2026-09-08: "i want to make it like it is visible to all of the TSA's
     * the callbacks") — a promised follow-up call is shared team knowledge,
     * not one TSA's private queue: if the TSA who originally promised the
     * callback is out, any other logged-in TSA should still be able to see
     * and pick it up. Every OTHER view (bare Leads, Overdue) is unchanged —
     * see test_a_tsa_only_sees_their_own_leads() above, still passing.
     */
    public function test_callbacks_view_is_shared_across_every_tsa_not_just_the_viewers_own(): void
    {
        $gemma  = TsaShift::where('tsa_key', 'Gemma')->first();
        $mariel = TsaShift::where('tsa_key', 'Mariel')->first();
        $product = Product::where('display_name', 'SINUXYL')->first();

        Lead::create([
            'pancake_order_id' => 'cb-1', 'customer_name' => 'Gemma Callback',
            'product_id' => $product->id, 'tsa_id' => $gemma->id, 'status' => 'assigned',
            'callback_at' => now()->subHour(),
        ]);
        Lead::create([
            'pancake_order_id' => 'cb-2', 'customer_name' => 'Mariel Callback',
            'product_id' => $product->id, 'tsa_id' => $mariel->id, 'status' => 'assigned',
            'callback_at' => now()->subHour(),
        ]);

        $gemmaUser = User::create(['name' => 'Gemma User', 'email' => 'gemma-cb@test.com', 'password' => bcrypt('x'), 'is_active' => true, 'role' => 'tsa', 'tsa_id' => $gemma->id]);

        $response = $this->actingAs($gemmaUser)->get(route('calls.leads.index', ['view' => 'callbacks']));

        $response->assertOk();
        $response->assertSee('Gemma Callback');
        // The real fix — before this, a TSA's own Callbacks view was
        // silently scoped to just their own tsa_id, same as every other
        // view.
        $response->assertSee('Mariel Callback');
    }

    /**
     * Regression test, 2026-09-14: "the callbacks should be no tsa filter
     * because it should be visible to all users so you can remove the tsa
     * filter in the callbacks" — an admin could previously still narrow
     * the shared Callbacks queue down to one TSA via ?tsa=, silently
     * hiding every other TSA's due callbacks, the exact problem the
     * 2026-09-08 fix above was meant to solve for the default view in the
     * first place. ?tsa= is now ignored entirely on this view.
     */
    public function test_the_tsa_param_is_ignored_on_the_callbacks_view(): void
    {
        $gemma  = TsaShift::where('tsa_key', 'Gemma')->first();
        $mariel = TsaShift::where('tsa_key', 'Mariel')->first();
        $product = Product::where('display_name', 'SINUXYL')->first();

        Lead::create([
            'pancake_order_id' => 'cb-3', 'customer_name' => 'Gemma Callback Two',
            'product_id' => $product->id, 'tsa_id' => $gemma->id, 'status' => 'assigned',
            'callback_at' => now()->subHour(),
        ]);
        Lead::create([
            'pancake_order_id' => 'cb-4', 'customer_name' => 'Mariel Callback Two',
            'product_id' => $product->id, 'tsa_id' => $mariel->id, 'status' => 'assigned',
            'callback_at' => now()->subHour(),
        ]);

        // An admin explicitly picking ?tsa=Gemma must still see BOTH — the
        // param is ignored, not honored, on this view.
        $response = $this->actingAs($this->admin())->get(route('calls.leads.index', ['view' => 'callbacks', 'tsa' => $gemma->id]));

        $response->assertOk();
        $response->assertSee('Gemma Callback Two');
        $response->assertSee('Mariel Callback Two');

        // The admin TSA filter dropdown itself is gone from the page too,
        // not just non-functional — see index.blade.php's own comment on
        // why leaving it visible-but-inert would still be misleading.
        $response->assertDontSee('name="tsa"', false);
    }

    /**
     * Regression test, 2026-09-14: "the callbacks page will be leads today
     * the is has tag of not answering and unattended" — an order created
     * YESTERDAY whose Not Answering/Unattended tag only got noticed on
     * TODAY's sync tick was showing up in today's Callbacks, since
     * backfillCallbackFromTags() stamps callback_at = now() regardless of
     * the order's own real date. Confirmed live with real order #1367768
     * (created the day before, callback_at backfilled the next morning).
     */
    public function test_a_callback_backfilled_today_on_a_lead_created_yesterday_does_not_show(): void
    {
        $gemma = TsaShift::where('tsa_key', 'Gemma')->first();
        $product = Product::where('display_name', 'SINUXYL')->first();

        Lead::create([
            'pancake_order_id' => 'cb-old', 'customer_name' => 'Backfilled From Yesterdays Order',
            'product_id' => $product->id, 'tsa_id' => $gemma->id, 'status' => 'assigned',
            'pancake_created_at' => now()->subDay(), 'callback_at' => now()->subHour(),
        ]);
        Lead::create([
            'pancake_order_id' => 'cb-new', 'customer_name' => 'Todays Own Callback',
            'product_id' => $product->id, 'tsa_id' => $gemma->id, 'status' => 'assigned',
            'pancake_created_at' => now(), 'callback_at' => now()->subHour(),
        ]);

        $response = $this->actingAs($this->admin())->get(route('calls.leads.index', ['view' => 'callbacks']));

        $response->assertOk();
        $response->assertDontSee('Backfilled From Yesterdays Order');
        $response->assertSee('Todays Own Callback');
    }

    /**
     * Regression test, 2026-09-14: "the call backs has no tsa filter
     * because it should be visible to all tsa" — a normal TSA user saw
     * their own name/avatar pinned as a static badge on the Callbacks
     * page, implying the list was scoped to just them, even though
     * LeadController::index() already returns every TSA's due callbacks
     * on this view (2026-09-08 decision). The badge is correct on the
     * default Leads view (a TSA genuinely only sees their own queue
     * there) but was misleading on Callbacks specifically.
     */
    public function test_a_tsas_own_name_badge_does_not_show_on_the_callbacks_view(): void
    {
        $gemma = TsaShift::where('tsa_key', 'Gemma')->first();
        $gemmaUser = User::create(['name' => 'Gemma User', 'email' => 'gemma-badge@test.com', 'password' => bcrypt('x'), 'is_active' => true, 'role' => 'tsa', 'tsa_id' => $gemma->id]);

        $callbacksResponse = $this->actingAs($gemmaUser)->get(route('calls.leads.index', ['view' => 'callbacks']));
        $callbacksResponse->assertOk();
        $callbacksResponse->assertDontSee('data-own-tsa-badge', false);

        // Still shows on the default Leads view — this view genuinely is
        // scoped to just this TSA's own queue, so the badge stays accurate.
        $leadsResponse = $this->actingAs($gemmaUser)->get(route('calls.leads.index'));
        $leadsResponse->assertOk();
        $leadsResponse->assertSee('data-own-tsa-badge', false);
    }

    /**
     * Regression test, 2026-09-15: "in the callbacks it can't call any
     * leads... it is only displaying sending to your phone" — the dial
     * link used to carry the LEAD'S ASSIGNED TSA's own dialer_host, so
     * viewing (or picking up, via canAccess()'s own shared-callback
     * exception) a lead assigned to someone else always tried dialing
     * through the wrong phone — or, worse, an unassigned lead (a real
     * Callbacks case: an unclaimed order already tagged Not Answering/
     * Unattended in Pancake) had no tsa at all to pull a dialer_host from,
     * so auto-dial silently failed and fell back to the generic "Sent to
     * your phone" message on every single click. Confirmed fix: the dial
     * link now always carries the VIEWING user's own dialer_host,
     * regardless of who the lead is assigned to.
     */
    public function test_a_leads_phone_number_carries_the_viewers_own_dialer_host_not_the_leads_owner(): void
    {
        $gemma  = TsaShift::where('tsa_key', 'Gemma')->first();
        $mariel = TsaShift::where('tsa_key', 'Mariel')->first();
        $mariel->update(['dialer_host' => '192.168.1.99:8080']);
        $gemma->update(['dialer_host' => '192.168.1.42:8080']);
        $product = Product::where('display_name', 'SINUXYL')->first();

        // Lead is assigned to Gemma, but Mariel is the one viewing it.
        Lead::create(['pancake_order_id' => '1', 'customer_name' => 'Juan', 'phone_number' => '09171234567', 'product_id' => $product->id, 'tsa_id' => $gemma->id, 'status' => 'assigned', 'callback_at' => now()->subHour()]);

        $marielUser = User::create(['name' => 'Mariel User', 'email' => 'mariel-dial@test.com', 'password' => bcrypt('x'), 'is_active' => true, 'role' => 'tsa', 'tsa_id' => $mariel->id]);

        $response = $this->actingAs($marielUser)->get(route('calls.leads.index', ['view' => 'callbacks']));

        $response->assertOk();
        $response->assertSee('data-dial-host="192.168.1.99:8080"', false);
        $response->assertDontSee('data-dial-host="192.168.1.42:8080"', false);
    }

    public function test_a_leads_phone_number_has_no_dialer_host_when_the_viewer_never_configured_one(): void
    {
        $gemma = TsaShift::where('tsa_key', 'Gemma')->first();
        $gemma->update(['dialer_host' => '192.168.1.42:8080']);
        $product = Product::where('display_name', 'SINUXYL')->first();

        Lead::create(['pancake_order_id' => '1', 'customer_name' => 'Juan', 'phone_number' => '09171234567', 'product_id' => $product->id, 'tsa_id' => $gemma->id, 'status' => 'assigned']);

        // Admin viewer has no tsa/dialer_host at all — falls back to no
        // dial host regardless of the lead's own owner having one.
        $response = $this->actingAs($this->admin())->get(route('calls.leads.index'));

        $response->assertOk();
        $response->assertSee('data-dial-host=""', false);
    }

    /** An unassigned lead (a real Callbacks case: an unclaimed order
     *  already tagged Not Answering/Unattended in Pancake) has no tsa at
     *  all — the viewer's own dialer_host must still be used, not silently
     *  fall back to nothing just because the lead itself has no owner. */
    public function test_an_unassigned_leads_phone_number_still_uses_the_viewers_own_dialer_host(): void
    {
        $mariel = TsaShift::where('tsa_key', 'Mariel')->first();
        $mariel->update(['dialer_host' => '192.168.1.99:8080']);
        $product = Product::where('display_name', 'SINUXYL')->first();

        Lead::create(['pancake_order_id' => '1', 'customer_name' => 'Juan', 'phone_number' => '09171234567', 'product_id' => $product->id, 'tsa_id' => null, 'status' => 'unassigned', 'callback_at' => now()->subHour()]);

        $marielUser = User::create(['name' => 'Mariel User', 'email' => 'mariel-dial2@test.com', 'password' => bcrypt('x'), 'is_active' => true, 'role' => 'tsa', 'tsa_id' => $mariel->id]);

        $response = $this->actingAs($marielUser)->get(route('calls.leads.index', ['view' => 'callbacks']));

        $response->assertOk();
        $response->assertSee('data-dial-host="192.168.1.99:8080"', false);
    }

    private function admin(): User
    {
        return User::factory()->create(['role' => 'admin']);
    }

    public function test_a_date_range_filters_leads_by_pancake_created_at(): void
    {
        $gemma = TsaShift::where('tsa_key', 'Gemma')->first();
        $product = Product::where('display_name', 'SINUXYL')->first();

        Lead::create(['pancake_order_id' => '1', 'customer_name' => 'In Range', 'product_id' => $product->id, 'tsa_id' => $gemma->id, 'status' => 'assigned', 'pancake_created_at' => '2026-08-05 10:00:00']);
        Lead::create(['pancake_order_id' => '2', 'customer_name' => 'Out Of Range', 'product_id' => $product->id, 'tsa_id' => $gemma->id, 'status' => 'assigned', 'pancake_created_at' => '2026-08-01 10:00:00']);

        $admin = User::create(['name' => 'Admin', 'email' => 'admin-date@test.com', 'password' => bcrypt('x'), 'is_active' => true, 'role' => 'admin']);

        $response = $this->actingAs($admin)->get(route('calls.leads.index', ['date_from' => '2026-08-04', 'date_to' => '2026-08-06']));

        $response->assertOk();
        $response->assertSee('In Range');
        $response->assertDontSee('Out Of Range');
    }

    /**
     * Explicit request, 2026-08-26: "all of the newly created order in the
     * POS should be only in today" — real examples (#1347599 and others,
     * created days earlier) were sitting in today's queue purely because
     * this view had no default cutoff. No date range picked now defaults
     * to today, same as Overdue/Callbacks already do.
     */
    public function test_no_date_range_defaults_to_today_only_same_as_overdue_and_callbacks(): void
    {
        $gemma = TsaShift::where('tsa_key', 'Gemma')->first();
        $product = Product::where('display_name', 'SINUXYL')->first();

        Lead::create(['pancake_order_id' => '1', 'customer_name' => 'Old Lead', 'product_id' => $product->id, 'tsa_id' => $gemma->id, 'status' => 'assigned', 'pancake_created_at' => '2020-01-01 10:00:00']);
        Lead::create(['pancake_order_id' => '2', 'customer_name' => 'Created Today', 'product_id' => $product->id, 'tsa_id' => $gemma->id, 'status' => 'assigned', 'pancake_created_at' => now()]);

        $admin = User::create(['name' => 'Admin', 'email' => 'admin-date2@test.com', 'password' => bcrypt('x'), 'is_active' => true, 'role' => 'admin']);

        $response = $this->actingAs($admin)->get(route('calls.leads.index'));

        $response->assertOk();
        $response->assertDontSee('Old Lead');
        $response->assertSee('Created Today');
    }

    /**
     * Regression test, 2026-09-14: "i want only to make it only today
     * leads in every day, fix it. that is working before" — a weeks-old
     * order (created 2026-08-11) that only got ROUND-ROBIN ASSIGNED today,
     * via SyncPancakeLeads' catch-up sweep of previously-unassigned leads,
     * was reappearing in the default Leads view because the filter used
     * COALESCE(assigned_at, pancake_created_at) instead of plain
     * pancake_created_at — confirmed live with real orders #1347666/
     * #1347647, several already Received/Returned in Pancake. This
     * directly contradicted f0dc3c9's own stated rule ("all of the newly
     * created order in the POS should be only in today"), which that
     * COALESCE fallback had quietly overridden for any lead that happened
     * to get assigned today regardless of how old it actually was.
     */
    public function test_a_lead_only_assigned_today_but_created_long_ago_does_not_show(): void
    {
        $gemma = TsaShift::where('tsa_key', 'Gemma')->first();
        $product = Product::where('display_name', 'SINUXYL')->first();

        Lead::create([
            'pancake_order_id' => '1', 'customer_name' => 'Old Order Assigned Today',
            'product_id' => $product->id, 'tsa_id' => $gemma->id, 'status' => 'assigned',
            'pancake_created_at' => '2026-08-11 10:00:00', 'assigned_at' => now(),
        ]);

        $admin = User::create(['name' => 'Admin', 'email' => 'admin-date4@test.com', 'password' => bcrypt('x'), 'is_active' => true, 'role' => 'admin']);

        $response = $this->actingAs($admin)->get(route('calls.leads.index'));

        $response->assertOk();
        $response->assertDontSee('Old Order Assigned Today');
    }

    /**
     * A TSA changing an order's status after a call (Ordered, Awaiting
     * Stock, Confirmed, etc.) must never make the lead disappear from
     * their own queue — explicit correction, 2026-08-26, of an earlier
     * (reverted) attempt that hid leads by order status_code instead of
     * creation date. Only the date matters here, never status.
     */
    public function test_a_leads_order_status_never_affects_whether_it_shows_in_the_queue(): void
    {
        $gemma = TsaShift::where('tsa_key', 'Gemma')->first();
        $product = Product::where('display_name', 'SINUXYL')->first();

        Lead::create(['pancake_order_id' => '1', 'customer_name' => 'Confirmed Today', 'product_id' => $product->id, 'tsa_id' => $gemma->id, 'status' => 'assigned', 'pancake_created_at' => now()]);
        Order::create(['pancake_order_id' => '1', 'status_code' => 5, 'pancake_created_at' => now(), 'pancake_inserted_at' => now(), 'synced_at' => now()]);

        $response = $this->actingAs($this->admin())->get(route('calls.leads.index'));

        $response->assertOk();
        $response->assertSee('Confirmed Today');
    }

    /**
     * Explicit request (2026-09-04: "i want only the leads will be only the
     * product in the product management and when there are no in the
     * product management it will not occur in the leads tab") — a lead
     * whose item never matched a real Product Management entry (product_id
     * null, e.g. a real Pancake product like "NutriLay" that was never
     * added to the catalog) is excluded from this tab entirely, not just
     * shown with a blank product name. Accepted, confirmed consequence:
     * round-robin already never assigns these, and now nobody sees them in
     * the Leads tab either — Unmatched Orders is this app's own dedicated
     * place for reviewing them instead.
     */
    public function test_a_lead_with_no_matched_product_never_shows_in_the_leads_tab(): void
    {
        $product = Product::where('display_name', 'SINUXYL')->first();

        Lead::create(['pancake_order_id' => '1', 'customer_name' => 'Matched Product Lead', 'product_id' => $product->id, 'status' => 'unassigned']);
        Lead::create(['pancake_order_id' => '2', 'customer_name' => 'Unmatched Product Lead', 'product_id' => null, 'status' => 'unassigned']);

        $response = $this->actingAs($this->admin())->get(route('calls.leads.index'));

        $response->assertOk();
        $response->assertSee('Matched Product Lead');
        $response->assertDontSee('Unmatched Product Lead');
    }

    /** Reversed 2026-08-15 (see commit c82cdb5, "Make Overdue/Callbacks
     *  follow the picked date range, not hardcoded today") — Overdue used to
     *  ignore date_from/date_to entirely; it now applies the same shared
     *  date window as every other view, scoped to assigned_at (see
     *  LeadController::index()'s own comment on the $view === 'overdue'
     *  branch). Renamed and rewritten 2026-08-29 to assert the current,
     *  intentional behavior instead of the pre-c82cdb5 one this test was
     *  never updated for.
     *
     *  Rewritten AGAIN 2026-09-14 ("why the overdue is not today? it
     *  should be today only") — the lead in this test is deliberately
     *  created with pancake_created_at 2020-01-01, and used to prove Old
     *  Overdue DID show once its assigned_at fell in the picked range,
     *  which is exactly the bug the new pancake_created_at scoping (this
     *  same $view === 'overdue' branch) now fixes. This test now asserts
     *  the opposite: an old order stays excluded from Overdue regardless
     *  of assigned_at, and a genuinely today-created order is what shows.
     */
    public function test_the_overdue_view_is_scoped_to_the_picked_date_range(): void
    {
        $gemma = TsaShift::where('tsa_key', 'Gemma')->first();
        $product = Product::where('display_name', 'SINUXYL')->first();

        Lead::create([
            'pancake_order_id' => '1', 'customer_name' => 'Old Overdue', 'product_id' => $product->id,
            'tsa_id' => $gemma->id, 'status' => 'assigned', 'assigned_at' => now()->subHours(10),
            'pancake_created_at' => '2020-01-01 10:00:00',
        ]);
        Lead::create([
            'pancake_order_id' => '2', 'customer_name' => 'Today Overdue', 'product_id' => $product->id,
            'tsa_id' => $gemma->id, 'status' => 'assigned', 'assigned_at' => now()->subHours(10),
            'pancake_created_at' => now(),
        ]);

        $admin = User::create(['name' => 'Admin', 'email' => 'admin-date3@test.com', 'password' => bcrypt('x'), 'is_active' => true, 'role' => 'admin']);

        // A date range that doesn't cover assigned_at (10 hours ago, i.e.
        // today) excludes both leads from Overdue.
        $excluded = $this->actingAs($admin)->get(route('calls.leads.index', ['view' => 'overdue', 'date_from' => '2026-08-01', 'date_to' => '2026-08-06']));
        $excluded->assertOk();
        $excluded->assertDontSee('Old Overdue');
        $excluded->assertDontSee('Today Overdue');

        // A range that covers assigned_at includes only the lead whose
        // ORDER is also actually from today — the old one stays excluded
        // regardless of when it was assigned.
        $today = today()->toDateString();
        $included = $this->actingAs($admin)->get(route('calls.leads.index', ['view' => 'overdue', 'date_from' => $today, 'date_to' => $today]));
        $included->assertOk();
        $included->assertDontSee('Old Overdue');
        $included->assertSee('Today Overdue');
    }

    /**
     * Explicit request, 2026-08-26: "the one tsa can only see their name
     * and has no dropdown" for the TEAM/TSA picker specifically — updated
     * 2026-09-02 (explicit follow-up: "add product, status, search in the
     * tsa(normal user) in leads") to reopen Product/Status/Search for a
     * TSA, while the Team/TSA picker (inherently admin-only — a TSA
     * already only ever sees their own queue) stays a plain name badge.
     */
    public function test_a_tsa_sees_their_own_name_with_no_team_dropdown_but_has_product_status_search_on_the_leads_page(): void
    {
        $gemma = TsaShift::where('tsa_key', 'Gemma')->first();
        $user  = User::create(['name' => 'Gemma User', 'email' => 'gemma-name@test.com', 'password' => bcrypt('x'), 'is_active' => true, 'role' => 'tsa', 'tsa_id' => $gemma->id]);

        $response = $this->actingAs($user)->get(route('calls.leads.index'));

        $response->assertOk();
        $response->assertSee('Gemma De Guzman');
        // Product/Status filter triggers are now present for a TSA.
        $response->assertSee('data-filter-trigger', false);
        $response->assertSee('All Products');
        $response->assertSee('All Statuses');
        $response->assertSee('Search name, phone, order ID');
        // Team picker (an admin-only concept) is still absent — its
        // distinguishing markers ("All Teams" label, team= hidden input)
        // never render for a non-admin.
        $response->assertDontSee('All Teams');
        $response->assertDontSee('name="team"', false);
    }

    /**
     * Regression test, 2026-09-14: "when this all product has check in
     * tsa management it should be like the filter of product in tsa view
     * is the only checked product" — the Product filter's own option list
     * for a TSA used to come from Lead::where('tsa_id',...)->distinct()
     * ->pluck('product_id') (products they'd ALREADY received a lead
     * for), not TsaShift::products() (TSA Management's own "Handles"
     * checkboxes, the product_tsa pivot). A product freshly checked for a
     * TSA in TSA Management had no effect on their own filter until
     * round-robin happened to hand them a lead for it — confirmed here
     * that the filter now reflects Handles immediately, with zero leads
     * received yet.
     */
    public function test_a_tsas_product_filter_matches_their_tsa_management_handles_not_their_past_leads(): void
    {
        $gemma = TsaShift::where('tsa_key', 'Gemma')->first();
        $sinuxyl = Product::where('display_name', 'SINUXYL')->first();
        $scarCream = Product::where('display_name', 'SCAR CREAM')->first();

        // Gemma is only checked for Sinuxyl in TSA Management — Scar Cream
        // is a different TSA's product, never hers.
        $gemma->products()->sync([$sinuxyl->id]);

        // Zero leads exist at all — the old distinct-pluck version would
        // have offered NO products here regardless of Handles.
        $user = User::create(['name' => 'Gemma User', 'email' => 'gemma-handles@test.com', 'password' => bcrypt('x'), 'is_active' => true, 'role' => 'tsa', 'tsa_id' => $gemma->id]);

        $response = $this->actingAs($user)->get(route('calls.leads.index'));

        $response->assertOk();
        $products = collect($response->viewData('products'));
        $this->assertTrue($products->contains('id', $sinuxyl->id));
        $this->assertFalse($products->contains('id', $scarCream->id));
    }

    /**
     * Explicit request, 2026-08-26: "can you make this can filter catered
     * leads or uncatered leads" — same "Catered" language the Call Tracker
     * Dashboard KPI already uses (Lead::where('status', 'called')), added
     * alongside the original Unassigned/Assigned/Called values rather than
     * replacing them.
     */
    public function test_status_filter_catered_shows_only_called_leads(): void
    {
        $gemma = TsaShift::where('tsa_key', 'Gemma')->first();
        $product = Product::where('display_name', 'SINUXYL')->first();

        Lead::create(['pancake_order_id' => '1', 'customer_name' => 'Called Lead', 'product_id' => $product->id, 'tsa_id' => $gemma->id, 'status' => 'called', 'disposition' => 'Confirmed']);
        Lead::create(['pancake_order_id' => '2', 'customer_name' => 'Assigned Lead', 'product_id' => $product->id, 'tsa_id' => $gemma->id, 'status' => 'assigned']);

        $response = $this->actingAs($this->admin())->get(route('calls.leads.index', ['status' => 'catered']));

        $response->assertOk();
        $response->assertSee('Called Lead');
        $response->assertDontSee('Assigned Lead');
    }

    public function test_status_filter_uncatered_shows_everything_not_yet_called(): void
    {
        $gemma = TsaShift::where('tsa_key', 'Gemma')->first();
        $product = Product::where('display_name', 'SINUXYL')->first();

        Lead::create(['pancake_order_id' => '1', 'customer_name' => 'Called Lead', 'product_id' => $product->id, 'tsa_id' => $gemma->id, 'status' => 'called', 'disposition' => 'Confirmed']);
        Lead::create(['pancake_order_id' => '2', 'customer_name' => 'Assigned Lead', 'product_id' => $product->id, 'tsa_id' => $gemma->id, 'status' => 'assigned']);
        Lead::create(['pancake_order_id' => '3', 'customer_name' => 'Unassigned Lead', 'product_id' => $product->id, 'status' => 'unassigned']);

        $response = $this->actingAs($this->admin())->get(route('calls.leads.index', ['status' => 'uncatered']));

        $response->assertOk();
        $response->assertDontSee('Called Lead');
        $response->assertSee('Assigned Lead');
        $response->assertSee('Unassigned Lead');
    }

    /**
     * Regression test, 2026-09-16: "why there's a checkmark but still it
     * is in the uncatered... it should be in the catered right?" —
     * confirmed a dialed lead (dialed_at set, the Leads table's green
     * checkmark) should count as Catered even without a logged
     * disposition yet, not just a lead whose status is already 'called'.
     */
    public function test_status_filter_catered_also_shows_dialed_but_not_yet_dispositioned_leads(): void
    {
        $gemma = TsaShift::where('tsa_key', 'Gemma')->first();
        $product = Product::where('display_name', 'SINUXYL')->first();

        Lead::create(['pancake_order_id' => '1', 'customer_name' => 'Dialed Lead', 'product_id' => $product->id, 'tsa_id' => $gemma->id, 'status' => 'assigned', 'dialed_at' => now()]);
        Lead::create(['pancake_order_id' => '2', 'customer_name' => 'Untouched Lead', 'product_id' => $product->id, 'tsa_id' => $gemma->id, 'status' => 'assigned']);

        $response = $this->actingAs($this->admin())->get(route('calls.leads.index', ['status' => 'catered']));

        $response->assertOk();
        $response->assertSee('Dialed Lead');
        $response->assertDontSee('Untouched Lead');
    }

    /** The counterpart to the test above — a dialed lead must also drop
     *  out of Uncatered now, not just show up under Catered. */
    public function test_status_filter_uncatered_excludes_dialed_leads(): void
    {
        $gemma = TsaShift::where('tsa_key', 'Gemma')->first();
        $product = Product::where('display_name', 'SINUXYL')->first();

        Lead::create(['pancake_order_id' => '1', 'customer_name' => 'Dialed Lead', 'product_id' => $product->id, 'tsa_id' => $gemma->id, 'status' => 'assigned', 'dialed_at' => now()]);
        Lead::create(['pancake_order_id' => '2', 'customer_name' => 'Untouched Lead', 'product_id' => $product->id, 'tsa_id' => $gemma->id, 'status' => 'assigned']);

        $response = $this->actingAs($this->admin())->get(route('calls.leads.index', ['status' => 'uncatered']));

        $response->assertOk();
        $response->assertDontSee('Dialed Lead');
        $response->assertSee('Untouched Lead');
    }

    /**
     * Explicit request, 2026-09-08: "can you make it there's a status
     * filter too in this" — the real Pancake order-status pill column
     * (New/Confirmed/Shipped/etc, Order::STATUS_PILL), a different concept
     * from the $status filter above (Lead's own local status). Uses
     * 'order_status', a different query param, so the two never collide —
     * see LeadController::index()'s own comment.
     */
    public function test_order_status_filter_narrows_to_leads_whose_real_pancake_order_matches(): void
    {
        $gemma   = TsaShift::where('tsa_key', 'Gemma')->first();
        $product = Product::where('display_name', 'SINUXYL')->first();

        Lead::create(['pancake_order_id' => 'os-1', 'customer_name' => 'New Order Lead', 'product_id' => $product->id, 'tsa_id' => $gemma->id, 'status' => 'assigned']);
        Lead::create(['pancake_order_id' => 'os-2', 'customer_name' => 'Confirmed Order Lead', 'product_id' => $product->id, 'tsa_id' => $gemma->id, 'status' => 'assigned']);

        Order::create(['pancake_order_id' => 'os-1', 'status_code' => 0, 'synced_at' => now()]);
        Order::create(['pancake_order_id' => 'os-2', 'status_code' => 1, 'synced_at' => now()]);

        // 1 = Confirmed, per Order::STATUS_PILL.
        $response = $this->actingAs($this->admin())->get(route('calls.leads.index', ['order_status' => 1]));

        $response->assertOk();
        $response->assertDontSee('New Order Lead');
        $response->assertSee('Confirmed Order Lead');
    }

    /** Works on Overdue/Callbacks too, unlike the Lead-status filter above —
     *  an order's real Pancake status is an independent fact regardless of
     *  which queue view is showing it. */
    public function test_order_status_filter_also_works_on_the_callbacks_view(): void
    {
        $gemma   = TsaShift::where('tsa_key', 'Gemma')->first();
        $product = Product::where('display_name', 'SINUXYL')->first();

        Lead::create(['pancake_order_id' => 'os-3', 'customer_name' => 'New Callback', 'product_id' => $product->id, 'tsa_id' => $gemma->id, 'status' => 'assigned', 'callback_at' => now()->subHour()]);
        Lead::create(['pancake_order_id' => 'os-4', 'customer_name' => 'Confirmed Callback', 'product_id' => $product->id, 'tsa_id' => $gemma->id, 'status' => 'assigned', 'callback_at' => now()->subHour()]);

        Order::create(['pancake_order_id' => 'os-3', 'status_code' => 0, 'synced_at' => now()]);
        Order::create(['pancake_order_id' => 'os-4', 'status_code' => 1, 'synced_at' => now()]);

        $response = $this->actingAs($this->admin())->get(route('calls.leads.index', ['view' => 'callbacks', 'order_status' => 1]));

        $response->assertOk();
        $response->assertDontSee('New Callback');
        $response->assertSee('Confirmed Callback');
    }

    public function test_an_admin_sees_every_tsas_leads(): void
    {
        $gemma = TsaShift::where('tsa_key', 'Gemma')->first();
        $mariel = TsaShift::where('tsa_key', 'Mariel')->first();
        $product = Product::where('display_name', 'SINUXYL')->first();

        Lead::create(['pancake_order_id' => '1', 'customer_name' => 'Gemma Lead', 'product_id' => $product->id, 'tsa_id' => $gemma->id, 'status' => 'assigned']);
        Lead::create(['pancake_order_id' => '2', 'customer_name' => 'Mariel Lead', 'product_id' => $product->id, 'tsa_id' => $mariel->id, 'status' => 'assigned']);

        $admin = User::create(['name' => 'Admin', 'email' => 'admin2@test.com', 'password' => bcrypt('x'), 'is_active' => true, 'role' => 'admin']);

        $response = $this->actingAs($admin)->get(route('calls.leads.index'));

        $response->assertOk();
        $response->assertSee('Gemma Lead');
        $response->assertSee('Mariel Lead');
    }

    /**
     * Explicit request, 2026-08-28: a per-product filter on the Leads tab
     * that's scoped by team — Product::team is the same literal order_team
     * string TsaShift::team already uses (config('teams')'s own doc
     * comment), so "which products does this team's TSAs handle" is a
     * direct Product::where('team', ...), no product_tsa join needed.
     */
    public function test_the_product_filter_narrows_to_only_that_products_leads(): void
    {
        $gemma = TsaShift::where('tsa_key', 'Gemma')->first();
        $sinuxyl = Product::where('display_name', 'SINUXYL')->first();
        $scarCream = Product::where('display_name', 'SCAR CREAM')->first();

        Lead::create(['pancake_order_id' => '1', 'customer_name' => 'Sinuxyl Lead', 'product_id' => $sinuxyl->id, 'tsa_id' => $gemma->id, 'status' => 'assigned']);
        Lead::create(['pancake_order_id' => '2', 'customer_name' => 'Scar Cream Lead', 'product_id' => $scarCream->id, 'tsa_id' => $gemma->id, 'status' => 'assigned']);

        $response = $this->actingAs($this->admin())->get(route('calls.leads.index', ['product' => $sinuxyl->id]));

        $response->assertOk();
        $response->assertSee('Sinuxyl Lead');
        $response->assertDontSee('Scar Cream Lead');
    }

    public function test_the_team_filter_narrows_to_only_that_teams_products(): void
    {
        $gemma = TsaShift::where('tsa_key', 'Gemma')->first();
        $shNaturalsProduct = Product::where('display_name', 'SINUXYL')->first();
        $eyecareProduct = Product::where('display_name', 'CLEARSIGHT')->first();

        Lead::create(['pancake_order_id' => '1', 'customer_name' => 'SH Naturals Lead', 'product_id' => $shNaturalsProduct->id, 'tsa_id' => $gemma->id, 'status' => 'assigned']);
        Lead::create(['pancake_order_id' => '2', 'customer_name' => 'Eyecare Lead', 'product_id' => $eyecareProduct->id, 'tsa_id' => $gemma->id, 'status' => 'assigned']);

        $response = $this->actingAs($this->admin())->get(route('calls.leads.index', ['team' => 'SH Naturals']));

        $response->assertOk();
        $response->assertSee('SH Naturals Lead');
        $response->assertDontSee('Eyecare Lead');
        // The dropdown itself must not offer the other team's products either.
        $products = collect($response->viewData('products'));
        $this->assertTrue($products->contains('id', $shNaturalsProduct->id));
        $this->assertFalse($products->contains('id', $eyecareProduct->id));
    }

    /** A specific product implies its own team already — it must win
     *  outright over a stale/mismatched team param rather than ANDing both
     *  and silently returning zero rows. */
    public function test_a_specific_product_wins_over_a_mismatched_team_param(): void
    {
        $gemma = TsaShift::where('tsa_key', 'Gemma')->first();
        $eyecareProduct = Product::where('display_name', 'CLEARSIGHT')->first();

        Lead::create(['pancake_order_id' => '1', 'customer_name' => 'Eyecare Lead', 'product_id' => $eyecareProduct->id, 'tsa_id' => $gemma->id, 'status' => 'assigned']);

        $response = $this->actingAs($this->admin())->get(route('calls.leads.index', [
            'team' => 'SH Naturals', 'product' => $eyecareProduct->id,
        ]));

        $response->assertOk();
        $response->assertSee('Eyecare Lead');
    }

    public function test_a_tsa_can_log_an_outcome_on_their_own_lead(): void
    {
        $gemma = TsaShift::where('tsa_key', 'Gemma')->first();
        $product = Product::where('display_name', 'SINUXYL')->first();
        $lead = Lead::create(['pancake_order_id' => '1', 'customer_name' => 'Test', 'product_id' => $product->id, 'tsa_id' => $gemma->id, 'status' => 'assigned']);
        $user = User::create(['name' => 'Gemma User', 'email' => 'gemma3@test.com', 'password' => bcrypt('x'), 'is_active' => true, 'role' => 'tsa', 'tsa_id' => $gemma->id]);

        $response = $this->actingAs($user)->post(route('calls.leads.disposition', $lead), ['disposition' => 'Confirmed']);

        $response->assertRedirect();
        $lead->refresh();
        $this->assertSame('called', $lead->status);
        $this->assertSame('Confirmed', $lead->disposition);
        $this->assertNotNull($lead->called_at);
        $this->assertSame($user->id, $lead->called_by_user_id);
    }

    public function test_a_tsa_cannot_log_an_outcome_on_someone_elses_lead(): void
    {
        $gemma = TsaShift::where('tsa_key', 'Gemma')->first();
        $mariel = TsaShift::where('tsa_key', 'Mariel')->first();
        $product = Product::where('display_name', 'SINUXYL')->first();
        $lead = Lead::create(['pancake_order_id' => '1', 'customer_name' => 'Test', 'product_id' => $product->id, 'tsa_id' => $mariel->id, 'status' => 'assigned']);
        $user = User::create(['name' => 'Gemma User', 'email' => 'gemma4@test.com', 'password' => bcrypt('x'), 'is_active' => true, 'role' => 'tsa', 'tsa_id' => $gemma->id]);

        $response = $this->actingAs($user)->post(route('calls.leads.disposition', $lead), ['disposition' => 'Confirmed']);

        $response->assertForbidden();
    }

    /**
     * Regression test, 2026-09-14: "why the callbacks view TSA when they
     * click the leads name it is like this" (screenshot: "Could not load
     * this lead — try again.") — opening a lead's detail from the
     * Callbacks page threw a 403 whenever the callback belonged to a
     * DIFFERENT TSA, contradicting Callbacks being deliberately shared
     * team knowledge across every TSA. First fixed for viewing only
     * (f7761b4); immediate follow-up the same day — "it should be like
     * this view in callbacks in all tsa," confirmed explicitly to mean
     * full edit access too — extended canAccess() to every write action as
     * well, so a TSA can now both view AND fully manage (log an outcome
     * on) any lead with a currently-due callback_at, even when it's not
     * theirs.
     */
    public function test_a_tsa_can_view_and_edit_another_tsas_due_callback(): void
    {
        $gemma = TsaShift::where('tsa_key', 'Gemma')->first();
        $mariel = TsaShift::where('tsa_key', 'Mariel')->first();
        $product = Product::where('display_name', 'SINUXYL')->first();
        $lead = Lead::create([
            'pancake_order_id' => 'cb-view', 'customer_name' => 'Mariels Callback',
            'product_id' => $product->id, 'tsa_id' => $mariel->id, 'status' => 'assigned',
            'callback_at' => now()->subHour(),
        ]);
        $gemmaUser = User::create(['name' => 'Gemma User', 'email' => 'gemma-view@test.com', 'password' => bcrypt('x'), 'is_active' => true, 'role' => 'tsa', 'tsa_id' => $gemma->id]);

        $showResponse = $this->actingAs($gemmaUser)->get(route('calls.leads.show', $lead));
        $showResponse->assertOk();
        $showResponse->assertSee('Mariels Callback');

        $editResponse = $this->actingAs($gemmaUser)->post(route('calls.leads.disposition', $lead), ['disposition' => 'Confirmed']);
        $editResponse->assertRedirect();
        $this->assertSame('Confirmed', $lead->fresh()->disposition);
    }

    /** A lead with no due callback (plain unassigned-to-this-TSA lead, not
     *  a shared callback) stays fully blocked from viewing too — the
     *  relaxed rule only applies to a genuinely due callback_at, not every
     *  lead belonging to another TSA. */
    public function test_a_tsa_still_cannot_view_a_plain_lead_belonging_to_another_tsa(): void
    {
        $gemma = TsaShift::where('tsa_key', 'Gemma')->first();
        $mariel = TsaShift::where('tsa_key', 'Mariel')->first();
        $product = Product::where('display_name', 'SINUXYL')->first();
        $lead = Lead::create(['pancake_order_id' => 'no-cb', 'customer_name' => 'Test', 'product_id' => $product->id, 'tsa_id' => $mariel->id, 'status' => 'assigned']);
        $gemmaUser = User::create(['name' => 'Gemma User', 'email' => 'gemma-noview@test.com', 'password' => bcrypt('x'), 'is_active' => true, 'role' => 'tsa', 'tsa_id' => $gemma->id]);

        $this->actingAs($gemmaUser)->get(route('calls.leads.show', $lead))->assertForbidden();
    }

    public function test_a_tsa_cannot_reach_settings(): void
    {
        $gemma = TsaShift::where('tsa_key', 'Gemma')->first();
        $user = User::create(['name' => 'Gemma User', 'email' => 'gemma5@test.com', 'password' => bcrypt('x'), 'is_active' => true, 'role' => 'tsa', 'tsa_id' => $gemma->id]);

        $this->actingAs($user)->get(route('settings'))->assertForbidden();
    }

    public function test_a_table_refresh_request_returns_only_the_table_fragment_not_the_full_layout(): void
    {
        $gemma = TsaShift::where('tsa_key', 'Gemma')->first();
        $product = Product::where('display_name', 'SINUXYL')->first();
        Lead::create(['pancake_order_id' => '1', 'customer_name' => 'Gemma Lead', 'product_id' => $product->id, 'tsa_id' => $gemma->id, 'status' => 'assigned']);
        $user = User::create(['name' => 'Gemma User', 'email' => 'gemma6@test.com', 'password' => bcrypt('x'), 'is_active' => true, 'role' => 'tsa', 'tsa_id' => $gemma->id]);

        $response = $this->actingAs($user)->get(route('calls.leads.index'), ['X-Table-Refresh' => '1']);

        $response->assertOk();
        $response->assertSee('Gemma Lead');
        $response->assertDontSee('Call Tracker', false);
        $response->assertDontSee('id="leads-table-container"', false);
    }

    public function test_a_table_refresh_request_still_respects_the_same_tsa_scoping_as_a_normal_request(): void
    {
        $gemma = TsaShift::where('tsa_key', 'Gemma')->first();
        $mariel = TsaShift::where('tsa_key', 'Mariel')->first();
        $product = Product::where('display_name', 'SINUXYL')->first();
        Lead::create(['pancake_order_id' => '1', 'customer_name' => 'Gemma Lead', 'product_id' => $product->id, 'tsa_id' => $gemma->id, 'status' => 'assigned']);
        Lead::create(['pancake_order_id' => '2', 'customer_name' => 'Mariel Lead', 'product_id' => $product->id, 'tsa_id' => $mariel->id, 'status' => 'assigned']);
        $user = User::create(['name' => 'Gemma User', 'email' => 'gemma7@test.com', 'password' => bcrypt('x'), 'is_active' => true, 'role' => 'tsa', 'tsa_id' => $gemma->id]);

        $response = $this->actingAs($user)->get(route('calls.leads.index'), ['X-Table-Refresh' => '1']);

        $response->assertOk();
        $response->assertSee('Gemma Lead');
        $response->assertDontSee('Mariel Lead');
    }

    private function fakePosTags(array $tags, array $overrides = []): void
    {
        Setting::set('pancake_api_key', 'fake-api-key');
        Setting::set('shop_id', '4');
        Http::fake(array_merge([
            'pos.pages.fm/api/v1/shops/4/orders/tags*' => Http::response(['success' => true, 'data' => $tags], 200),
            'pos.pages.fm/api/v1/shops/4/orders/1*' => Http::response(['success' => true, 'data' => ['id' => 1, 'tags' => []]], 200),
        ], $overrides));
    }

    public function test_logging_an_outcome_tags_both_the_disposition_and_the_tsa_on_the_real_pos_order(): void
    {
        $this->fakePosTags([
            ['id' => 10, 'name' => 'Confirmed'],
            ['id' => 11, 'name' => 'Gemma'],
        ]);

        $gemma = TsaShift::where('tsa_key', 'Gemma')->first();
        $product = Product::where('display_name', 'SINUXYL')->first();
        $lead = Lead::create(['pancake_order_id' => '1', 'customer_name' => 'Test', 'product_id' => $product->id, 'tsa_id' => $gemma->id, 'status' => 'assigned']);
        $user = User::create(['name' => 'Gemma User', 'email' => 'gemma8@test.com', 'password' => bcrypt('x'), 'is_active' => true, 'role' => 'tsa', 'tsa_id' => $gemma->id]);

        $this->actingAs($user)->post(route('calls.leads.disposition', $lead), ['disposition' => 'Confirmed']);

        Http::assertSent(function ($r) {
            if ($r->method() !== 'PUT') return false;
            $tagIds = collect($r['tags'])->pluck('id')->all();
            return in_array(10, $tagIds, true) && in_array(11, $tagIds, true); // Confirmed + Gemma
        });
    }

    /** Explicit request, 2026-08-28: the TSA Management tab's global switch
     *  gates only the TSA name tag half of this write — the disposition tag
     *  itself must still reach Pancake with the toggle off. */
    public function test_logging_an_outcome_with_auto_tagging_disabled_still_tags_the_disposition_but_skips_the_tsa(): void
    {
        Setting::set('pos_auto_tagging_enabled', false);

        $this->fakePosTags([
            ['id' => 10, 'name' => 'Confirmed'],
            ['id' => 11, 'name' => 'Gemma'],
        ]);

        $gemma = TsaShift::where('tsa_key', 'Gemma')->first();
        $product = Product::where('display_name', 'SINUXYL')->first();
        $lead = Lead::create(['pancake_order_id' => '1', 'customer_name' => 'Test', 'product_id' => $product->id, 'tsa_id' => $gemma->id, 'status' => 'assigned']);
        $user = User::create(['name' => 'Gemma User', 'email' => 'gemma9@test.com', 'password' => bcrypt('x'), 'is_active' => true, 'role' => 'tsa', 'tsa_id' => $gemma->id]);

        $this->actingAs($user)->post(route('calls.leads.disposition', $lead), ['disposition' => 'Confirmed']);

        Http::assertSent(function ($r) {
            if ($r->method() !== 'PUT') return false;
            $tagIds = collect($r['tags'])->pluck('id')->all();
            return in_array(10, $tagIds, true) && !in_array(11, $tagIds, true); // Confirmed only, no Gemma
        });
    }

    public function test_logging_an_outcome_with_multiple_picked_tags_writes_every_one_of_them_plus_the_tsa_to_pancake(): void
    {
        $this->fakePosTags([
            ['id' => 10, 'name' => 'Confirmed'],
            ['id' => 20, 'name' => 'Call Back'],
            ['id' => 11, 'name' => 'Gemma'],
        ]);

        $gemma = TsaShift::where('tsa_key', 'Gemma')->first();
        $product = Product::where('display_name', 'SINUXYL')->first();
        $lead = Lead::create(['pancake_order_id' => '1', 'customer_name' => 'Test', 'product_id' => $product->id, 'tsa_id' => $gemma->id, 'status' => 'assigned']);
        $user = User::create(['name' => 'Gemma User', 'email' => 'gemma15@test.com', 'password' => bcrypt('x'), 'is_active' => true, 'role' => 'tsa', 'tsa_id' => $gemma->id]);

        $response = $this->actingAs($user)->post(route('calls.leads.disposition', $lead), ['disposition' => 'Confirmed, Call Back']);

        $response->assertRedirect();
        $lead->refresh();
        $this->assertSame('Confirmed, Call Back', $lead->disposition);
        // "Call Back" is no longer a callback trigger (explicit request,
        // 2026-09-17 — see CALLBACK_TRIGGER_KEYWORDS' own doc comment), so
        // picking it alongside another tag still doesn't schedule one.
        $this->assertNull($lead->callback_at);

        Http::assertSent(function ($r) {
            if ($r->method() !== 'PUT') return false;
            $tagIds = collect($r['tags'])->pluck('id')->all();
            return in_array(10, $tagIds, true) && in_array(20, $tagIds, true) && in_array(11, $tagIds, true);
        });
    }

    public function test_logging_an_outcome_is_rejected_when_any_one_of_several_picked_tags_isnt_real(): void
    {
        $this->fakePosTags([
            ['id' => 10, 'name' => 'Confirmed'],
        ]);

        $gemma = TsaShift::where('tsa_key', 'Gemma')->first();
        $product = Product::where('display_name', 'SINUXYL')->first();
        $lead = Lead::create(['pancake_order_id' => '1', 'customer_name' => 'Test', 'product_id' => $product->id, 'tsa_id' => $gemma->id, 'status' => 'assigned']);
        $user = User::create(['name' => 'Gemma User', 'email' => 'gemma16@test.com', 'password' => bcrypt('x'), 'is_active' => true, 'role' => 'tsa', 'tsa_id' => $gemma->id]);

        // 'Confirmed' is real but 'Made Up Tag' isn't — the whole submission
        // is rejected, not just the invalid half of it.
        $response = $this->actingAs($user)->post(route('calls.leads.disposition', $lead), ['disposition' => 'Confirmed, Made Up Tag']);

        $response->assertSessionHasErrors('disposition');
        $this->assertNull($lead->refresh()->disposition);
    }

    public function test_logging_an_outcome_on_a_lead_still_saves_locally_when_pancake_isnt_connected(): void
    {
        Setting::set('pancake_api_key', '');
        Setting::set('shop_id', '');
        Http::fake(); // no stubs — any real call fails the test

        $gemma = TsaShift::where('tsa_key', 'Gemma')->first();
        $product = Product::where('display_name', 'SINUXYL')->first();
        $lead = Lead::create(['pancake_order_id' => '1', 'customer_name' => 'Test', 'product_id' => $product->id, 'tsa_id' => $gemma->id, 'status' => 'assigned']);
        $user = User::create(['name' => 'Gemma User', 'email' => 'gemma9@test.com', 'password' => bcrypt('x'), 'is_active' => true, 'role' => 'tsa', 'tsa_id' => $gemma->id]);

        $response = $this->actingAs($user)->post(route('calls.leads.disposition', $lead), ['disposition' => 'Confirmed']);

        $response->assertRedirect();
        $lead->refresh();
        $this->assertSame('Confirmed', $lead->disposition);
        Http::assertNothingSent();
    }

    public function test_logging_an_outcome_is_rejected_when_the_text_isnt_a_real_tag_on_the_shop(): void
    {
        $this->fakePosTags([
            ['id' => 1, 'name' => 'Someone Else'],
        ]);

        $gemma = TsaShift::where('tsa_key', 'Gemma')->first();
        $product = Product::where('display_name', 'SINUXYL')->first();
        $lead = Lead::create(['pancake_order_id' => '1', 'customer_name' => 'Test', 'product_id' => $product->id, 'tsa_id' => $gemma->id, 'status' => 'assigned']);
        $user = User::create(['name' => 'Gemma User', 'email' => 'gemma10@test.com', 'password' => bcrypt('x'), 'is_active' => true, 'role' => 'tsa', 'tsa_id' => $gemma->id]);

        // 'Confirmed' was never really picked from the search box's real
        // suggestions (the only real tag on this shop is 'Someone Else') —
        // the request is rejected rather than silently saved as junk.
        $response = $this->actingAs($user)->post(route('calls.leads.disposition', $lead), ['disposition' => 'Confirmed']);

        $response->assertSessionHasErrors('disposition');
        $lead->refresh();
        $this->assertNull($lead->disposition);
        Http::assertNotSent(fn ($r) => $r->method() === 'PUT');
    }

    public function test_logging_an_outcome_is_accepted_when_pancakes_tag_catalog_cant_be_fetched(): void
    {
        Setting::set('pancake_api_key', 'fake-api-key');
        Setting::set('shop_id', '4');
        Http::fake([
            // Catalog fetch itself fails (e.g. Pancake down / API key
            // revoked) — no real list to validate against, so the
            // disposition is trusted as-is rather than blocking the TSA's
            // save entirely.
            'pos.pages.fm/api/v1/shops/4/orders/tags*' => Http::response(['message' => 'error'], 500),
        ]);

        $gemma = TsaShift::where('tsa_key', 'Gemma')->first();
        $product = Product::where('display_name', 'SINUXYL')->first();
        $lead = Lead::create(['pancake_order_id' => '1', 'customer_name' => 'Test', 'product_id' => $product->id, 'tsa_id' => $gemma->id, 'status' => 'assigned']);
        $user = User::create(['name' => 'Gemma User', 'email' => 'gemma11@test.com', 'password' => bcrypt('x'), 'is_active' => true, 'role' => 'tsa', 'tsa_id' => $gemma->id]);

        $response = $this->actingAs($user)->post(route('calls.leads.disposition', $lead), ['disposition' => 'Confirmed']);

        $response->assertRedirect();
        $lead->refresh();
        $this->assertSame('Confirmed', $lead->disposition);
    }

    public function test_searching_tags_returns_the_shops_real_pos_tag_catalog_filtered_by_query(): void
    {
        $this->fakePosTags([
            ['id' => 10, 'name' => 'Confirmed'],
            ['id' => 11, 'name' => 'Call Back'],
            ['id' => 12, 'name' => 'Not Interested'],
        ]);

        $gemma = TsaShift::where('tsa_key', 'Gemma')->first();
        $product = Product::where('display_name', 'SINUXYL')->first();
        $lead = Lead::create(['pancake_order_id' => '1', 'customer_name' => 'Test', 'product_id' => $product->id, 'tsa_id' => $gemma->id, 'status' => 'assigned']);
        $user = User::create(['name' => 'Gemma User', 'email' => 'gemma12@test.com', 'password' => bcrypt('x'), 'is_active' => true, 'role' => 'tsa', 'tsa_id' => $gemma->id]);

        $response = $this->actingAs($user)->get(route('calls.leads.tags', $lead) . '?q=call');

        $response->assertOk();
        $response->assertJson(['success' => true, 'tags' => [['id' => 11, 'text' => 'Call Back', 'color' => null]]]);
    }

    public function test_searching_tags_on_a_lead_with_no_pancake_order_returns_an_empty_list(): void
    {
        $gemma = TsaShift::where('tsa_key', 'Gemma')->first();
        $product = Product::where('display_name', 'SINUXYL')->first();
        $lead = Lead::create(['pancake_order_id' => '1', 'customer_name' => 'Test', 'product_id' => $product->id, 'tsa_id' => $gemma->id, 'status' => 'assigned']);
        // pancake_order_id is a required, unique column on every real synced
        // lead — an empty string is the only DB-valid way to exercise this
        // "somehow has none" guard without violating that constraint.
        $lead->forceFill(['pancake_order_id' => ''])->save();
        $user = User::create(['name' => 'Gemma User', 'email' => 'gemma13@test.com', 'password' => bcrypt('x'), 'is_active' => true, 'role' => 'tsa', 'tsa_id' => $gemma->id]);

        $response = $this->actingAs($user)->get(route('calls.leads.tags', $lead));

        $response->assertOk();
        $response->assertJson(['success' => false, 'tags' => []]);
    }

    public function test_a_tsa_cannot_search_tags_on_someone_elses_lead(): void
    {
        $gemma = TsaShift::where('tsa_key', 'Gemma')->first();
        $mariel = TsaShift::where('tsa_key', 'Mariel')->first();
        $product = Product::where('display_name', 'SINUXYL')->first();
        $lead = Lead::create(['pancake_order_id' => '1', 'customer_name' => 'Test', 'product_id' => $product->id, 'tsa_id' => $mariel->id, 'status' => 'assigned']);
        $user = User::create(['name' => 'Gemma User', 'email' => 'gemma14@test.com', 'password' => bcrypt('x'), 'is_active' => true, 'role' => 'tsa', 'tsa_id' => $gemma->id]);

        $this->actingAs($user)->get(route('calls.leads.tags', $lead))->assertForbidden();
    }
}
