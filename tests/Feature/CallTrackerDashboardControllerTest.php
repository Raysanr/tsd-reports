<?php

namespace Tests\Feature;

use App\Models\Lead;
use App\Models\LeadActivity;
use App\Models\Product;
use App\Models\TsaShift;
use App\Models\TsaStatusLog;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Ported from call-tracker (merged into one app 2026-08-12) as
 * CallTrackerDashboardControllerTest — call-tracker's own DashboardControllerTest,
 * renamed for human clarity per the merge plan (tsd-reports already has many
 * Dashboard*Test.php files for its own, unrelated reporting Dashboard).
 * Tsa -> TsaShift, routes -> calls.*.
 *
 * Explicit request (2026-08-08): a real Dashboard/home page for Call
 * Tracker — see DashboardController::index()'s own comment for why it
 * deliberately doesn't duplicate TSD Reports' analytics (call volume,
 * pick-up rate). Also covers the 'dashboard' route rename (was My Leads,
 * now the actual overview — My Leads moved to 'leads.index').
 *
 * NOTE (adapted, not verbatim): the original asserted `GET /` resolves to
 * Call Tracker's own dashboard/leads views (call-tracker was a standalone
 * app with no other '/' route). In the merged app, '/' is tsd-reports' own
 * pre-existing Dashboard (route('dashboard')) — a completely different
 * page — so those two assertions were rewritten to hit calls.dashboard /
 * calls.leads.index directly instead of '/' and route('leads.index').
 */
class CallTrackerDashboardControllerTest extends TestCase
{
    use RefreshDatabase;

    /**
     * NOTE (adapted, not verbatim): the merged app's product_tsa table is
     * deliberately NOT seeded by any migration (unlike call-tracker's
     * original, which owned fresh products/tsas tables it seeded directly)
     * — it's wired up by the one-time `calltracker:reconcile-roster`
     * command (Phase 4). The "at risk product" tests below read
     * Product::tsas(), so the reconciler needs to have run first.
     */
    protected function setUp(): void
    {
        parent::setUp();
        $this->artisan('calltracker:reconcile-roster');
    }

    private function admin(): User
    {
        return User::factory()->create(['role' => 'admin']);
    }

    private function tsaUser(string $tsaKey = 'Gemma'): User
    {
        $tsa = TsaShift::where('tsa_key', $tsaKey)->first();
        return User::create(['name' => $tsa->display_name, 'email' => strtolower($tsaKey) . '@test.com', 'password' => bcrypt('x'), 'is_active' => true, 'role' => 'tsa', 'tsa_id' => $tsa->id]);
    }

    public function test_calls_dashboard_is_a_real_page_distinct_from_tsd_reports_own_dashboard(): void
    {
        $response = $this->actingAs($this->admin())->get(route('calls.dashboard'));

        $response->assertOk();
        $response->assertViewIs('calls.dashboard');
    }

    public function test_my_leads_is_its_own_route(): void
    {
        $response = $this->actingAs($this->admin())->get(route('calls.leads.index'));

        $response->assertOk();
        $response->assertViewIs('calls.leads.index');
    }

    public function test_a_tsa_only_sees_their_own_funnel_counts(): void
    {
        $gemma  = TsaShift::where('tsa_key', 'Gemma')->first();
        $mariel = TsaShift::where('tsa_key', 'Mariel')->first();
        $product = Product::where('display_name', 'SINUXYL')->first();

        Lead::create(['pancake_order_id' => '1', 'product_id' => $product->id, 'tsa_id' => $gemma->id, 'status' => 'assigned', 'assigned_at' => now()]);
        Lead::create(['pancake_order_id' => '2', 'product_id' => $product->id, 'tsa_id' => $mariel->id, 'status' => 'assigned', 'assigned_at' => now()]);

        $user = $this->tsaUser('Gemma');
        $response = $this->actingAs($user)->get(route('calls.dashboard'));

        $response->assertOk();
        $this->assertSame(1, $response->viewData('funnel')['assigned']);
    }

    public function test_an_admin_sees_every_tsas_funnel_counts(): void
    {
        $gemma  = TsaShift::where('tsa_key', 'Gemma')->first();
        $mariel = TsaShift::where('tsa_key', 'Mariel')->first();
        $product = Product::where('display_name', 'SINUXYL')->first();

        Lead::create(['pancake_order_id' => '1', 'product_id' => $product->id, 'tsa_id' => $gemma->id, 'status' => 'assigned', 'assigned_at' => now()]);
        Lead::create(['pancake_order_id' => '2', 'product_id' => $product->id, 'tsa_id' => $mariel->id, 'status' => 'assigned', 'assigned_at' => now()]);

        $response = $this->actingAs($this->admin())->get(route('calls.dashboard'));

        $response->assertOk();
        $this->assertSame(2, $response->viewData('funnel')['assigned']);
    }

    /** Mirrors RoundRobinAssigner's own eligibility rule (active + status
     *  login) — a product flagged here is one that same rule would
     *  currently return null for. */
    public function test_flags_a_product_whose_whole_roster_is_not_logged_in(): void
    {
        $product = Product::where('display_name', 'SINUXYL')->first();
        TsaShift::whereIn('tsa_key', ['Gemma', 'Mariel', 'Kathleen'])->update(['status' => 'break']);

        $response = $this->actingAs($this->admin())->get(route('calls.dashboard'));

        $response->assertOk();
        $atRisk = $response->viewData('atRiskProducts');
        $this->assertTrue($atRisk->contains('id', $product->id));
    }

    public function test_does_not_flag_a_product_with_at_least_one_tsa_logged_in(): void
    {
        $product = Product::where('display_name', 'SINUXYL')->first();
        // Seeded default is already 'login' for every TSA — explicit here for clarity.
        TsaShift::where('tsa_key', 'Gemma')->update(['status' => 'login']);
        TsaShift::whereIn('tsa_key', ['Mariel', 'Kathleen'])->update(['status' => 'break']);

        $response = $this->actingAs($this->admin())->get(route('calls.dashboard'));

        $atRisk = $response->viewData('atRiskProducts');
        $this->assertFalse($atRisk->contains('id', $product->id));
    }

    public function test_a_product_with_no_tsas_configured_at_all_is_not_flagged(): void
    {
        $orphan = Product::create(['display_name' => 'ORPHAN PRODUCT', 'match_keyword' => 'ORPHAN', 'team' => 'SH Naturals']);

        $response = $this->actingAs($this->admin())->get(route('calls.dashboard'));

        $atRisk = $response->viewData('atRiskProducts');
        $this->assertFalse($atRisk->contains('id', $orphan->id));
    }

    /** 2026-08-10: explicit request — every KPI card must move with the
     *  topbar date-range picker, not just Called/Upsells. Assigned/Overdue/
     *  Unassigned/Callbacks each get scoped by their own natural timestamp
     *  (assigned_at, assigned_at, pancake_created_at, callback_at). */
    public function test_kpi_cards_are_scoped_to_the_picked_date_range_not_just_today(): void
    {
        $gemma = TsaShift::where('tsa_key', 'Gemma')->first();
        $product = Product::where('display_name', 'SINUXYL')->first();

        Lead::create(['pancake_order_id' => '1', 'product_id' => $product->id, 'tsa_id' => $gemma->id, 'status' => 'assigned', 'assigned_at' => now()->subDays(5)]);
        Lead::create(['pancake_order_id' => '2', 'product_id' => $product->id, 'tsa_id' => $gemma->id, 'status' => 'assigned', 'assigned_at' => now()]);
        Lead::create(['pancake_order_id' => '3', 'product_id' => $product->id, 'status' => 'unassigned', 'pancake_created_at' => now()->subDays(5)]);
        Lead::create(['pancake_order_id' => '4', 'product_id' => $product->id, 'status' => 'unassigned', 'pancake_created_at' => now()]);

        // Default (no date params) — only today's leads count.
        $today = $this->actingAs($this->admin())->get(route('calls.dashboard'));
        $this->assertSame(1, $today->viewData('funnel')['assigned']);
        $this->assertSame(1, $today->viewData('funnel')['unassigned']);

        // Picking the range covering 5 days ago pulls in the older lead instead.
        $pastDay = now()->subDays(5)->toDateString();
        $ranged = $this->actingAs($this->admin())->get(route('calls.dashboard', ['date_from' => $pastDay, 'date_to' => $pastDay]));
        $this->assertSame(1, $ranged->viewData('funnel')['assigned']);
        $this->assertSame(1, $ranged->viewData('funnel')['unassigned']);

        // A range spanning both picks up both.
        $wide = $this->actingAs($this->admin())->get(route('calls.dashboard', ['date_from' => $pastDay, 'date_to' => now()->toDateString()]));
        $this->assertSame(2, $wide->viewData('funnel')['assigned']);
        $this->assertSame(2, $wide->viewData('funnel')['unassigned']);
    }

    public function test_todays_upsells_are_counted_and_summed(): void
    {
        $gemma = TsaShift::where('tsa_key', 'Gemma')->first();
        $product = Product::where('display_name', 'SINUXYL')->first();
        $lead = Lead::create(['pancake_order_id' => '1', 'product_id' => $product->id, 'tsa_id' => $gemma->id, 'status' => 'assigned']);

        LeadActivity::log($lead, 'upsell_added', 'Added upsell "Haplunas Balm" (₱499.00 × 1).', null, 499.0);
        LeadActivity::log($lead, 'upsell_added', 'Added upsell "Scar Cream" (₱700.00 × 1).', null, 700.0);
        // A failed write is logged with no amount — must not count.
        LeadActivity::log($lead, 'upsell_added', 'Added upsell "Ginseng" — Pancake write failed, verify in POS.', null, null);

        $response = $this->actingAs($this->admin())->get(route('calls.dashboard'));

        $response->assertOk();
        $this->assertSame(2, $response->viewData('upsellStats')['count']);
        $this->assertSame(1199.0, (float) $response->viewData('upsellStats')['amount']);
    }

    public function test_recent_activity_combines_lead_and_status_events_by_recency(): void
    {
        $gemma = TsaShift::where('tsa_key', 'Gemma')->first();
        $product = Product::where('display_name', 'SINUXYL')->first();
        $lead = Lead::create(['pancake_order_id' => '1', 'product_id' => $product->id, 'tsa_id' => $gemma->id, 'status' => 'assigned']);

        LeadActivity::create(['lead_id' => $lead->id, 'type' => 'assigned', 'description' => 'Round-robin assigned to Gemma.', 'created_at' => now()->subMinutes(10)]);
        TsaStatusLog::create(['tsa_id' => $gemma->id, 'status' => 'break', 'created_at' => now()->subMinutes(5)]);

        $response = $this->actingAs($this->admin())->get(route('calls.dashboard'));

        $activity = $response->viewData('recentActivity');
        $this->assertCount(2, $activity);
        // Most recent (the status change) first.
        $this->assertStringContainsString('Break', $activity->first()['description']);
    }
}
