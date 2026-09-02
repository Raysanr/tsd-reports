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

    public function test_a_leads_phone_number_carries_their_tsas_dialer_host_when_set(): void
    {
        $gemma = TsaShift::where('tsa_key', 'Gemma')->first();
        $gemma->update(['dialer_host' => '192.168.1.42:8080']);
        $product = Product::where('display_name', 'SINUXYL')->first();

        Lead::create(['pancake_order_id' => '1', 'customer_name' => 'Juan', 'phone_number' => '09171234567', 'product_id' => $product->id, 'tsa_id' => $gemma->id, 'status' => 'assigned']);

        $response = $this->actingAs($this->admin())->get(route('calls.leads.index'));

        $response->assertOk();
        $response->assertSee('data-dial-host="192.168.1.42:8080"', false);
    }

    public function test_a_leads_phone_number_has_no_dialer_host_when_their_tsa_never_configured_one(): void
    {
        $gemma = TsaShift::where('tsa_key', 'Gemma')->first();
        $product = Product::where('display_name', 'SINUXYL')->first();

        Lead::create(['pancake_order_id' => '1', 'customer_name' => 'Juan', 'phone_number' => '09171234567', 'product_id' => $product->id, 'tsa_id' => $gemma->id, 'status' => 'assigned']);

        $response = $this->actingAs($this->admin())->get(route('calls.leads.index'));

        $response->assertOk();
        $response->assertSee('data-dial-host=""', false);
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

    /** Reversed 2026-08-15 (see commit c82cdb5, "Make Overdue/Callbacks
     *  follow the picked date range, not hardcoded today") — Overdue used to
     *  ignore date_from/date_to entirely; it now applies the same shared
     *  date window as every other view, scoped to assigned_at (see
     *  LeadController::index()'s own comment on the $view === 'overdue'
     *  branch). Renamed and rewritten 2026-08-29 to assert the current,
     *  intentional behavior instead of the pre-c82cdb5 one this test was
     *  never updated for. */
    public function test_the_overdue_view_is_scoped_to_the_picked_date_range(): void
    {
        $gemma = TsaShift::where('tsa_key', 'Gemma')->first();
        $product = Product::where('display_name', 'SINUXYL')->first();

        Lead::create([
            'pancake_order_id' => '1', 'customer_name' => 'Old Overdue', 'product_id' => $product->id,
            'tsa_id' => $gemma->id, 'status' => 'assigned', 'assigned_at' => now()->subHours(10),
            'pancake_created_at' => '2020-01-01 10:00:00',
        ]);

        $admin = User::create(['name' => 'Admin', 'email' => 'admin-date3@test.com', 'password' => bcrypt('x'), 'is_active' => true, 'role' => 'admin']);

        // A date range that doesn't cover assigned_at (10 hours ago, i.e.
        // today) excludes the lead from Overdue now.
        $excluded = $this->actingAs($admin)->get(route('calls.leads.index', ['view' => 'overdue', 'date_from' => '2026-08-01', 'date_to' => '2026-08-06']));
        $excluded->assertOk();
        $excluded->assertDontSee('Old Overdue');

        // A range that does cover assigned_at includes it.
        $today = today()->toDateString();
        $included = $this->actingAs($admin)->get(route('calls.leads.index', ['view' => 'overdue', 'date_from' => $today, 'date_to' => $today]));
        $included->assertOk();
        $included->assertSee('Old Overdue');
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
        // "Call Back" being one of several picked tags (not the only one)
        // still schedules a callback due date.
        $this->assertNotNull($lead->callback_at);

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
