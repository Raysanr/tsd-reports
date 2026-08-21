<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\TsaShift;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Explicit request (2026-08-21): Dashboard's "Total Leads" must equal Leads
 * Report's Grand Total for the same range/team — both now call
 * ProductPerformance::sumRows() over the same per-product rows (see
 * DashboardController::index()'s and LeadsReportController::index()/
 * indexAll()'s own matching comments for the full same-day back-and-forth
 * that landed here). A cross-team combo order (e.g. a Pterygium order
 * bundling Sinuxyl units) counts once per product it matches — TWICE here,
 * not once — since that's exactly what "sum of the visible rows" means.
 *
 * This replaces an earlier version of this test (then named
 * DashboardTotalLeadsMatchesTsaPerformanceTest) that asserted the opposite:
 * a distinct-order tally matching TSA Performance instead. TSA Performance's
 * own Grand Total was never brought into this later reconciliation and can
 * still disagree with both of these whenever a combo or untracked-product
 * order exists in range — a known, accepted gap, not an oversight.
 */
class DashboardTotalLeadsMatchesLeadsReportTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->create());
    }

    private function seedComboOrder(): void
    {
        $shShift  = TsaShift::where('team', 'SH Naturals')->first();
        $eyeShift = TsaShift::where('team', 'Eyecare Team')->first();

        Order::create([
            'pancake_order_id' => 'plain-sinuxyl-dash', 'team' => 'SH Naturals', 'tsa_name' => $shShift->tsa_key,
            'disposition' => 'CONFIRMED VIA CALL', 'product' => 'Sinuxyl',
            'raw_tags' => [strtoupper($shShift->tsa_key), 'CONFIRMED VIA CALL'],
            'is_upsell' => false, 'status_code' => 1, 'pancake_created_at' => now(), 'synced_at' => now(),
        ]);

        // Cross-team combo: an Eyecare-owned order bundling a SINUXYL half —
        // counts once under PTERYGIUM and once under SINUXYL, so it adds 2 to
        // sumRows(), not 1.
        Order::create([
            'pancake_order_id' => 'combo-order-dash', 'team' => 'Eyecare Team', 'tsa_name' => $eyeShift->tsa_key,
            'disposition' => 'CONFIRMED VIA CALL', 'product' => 'Pterygium',
            'bundle_description' => '10 Pterygium Drops + 10 Sinuxyl',
            'raw_tags' => [strtoupper($eyeShift->tsa_key), 'CONFIRMED VIA CALL'],
            'is_upsell' => false, 'status_code' => 1, 'pancake_created_at' => now(), 'synced_at' => now(),
        ]);
    }

    public function test_dashboard_total_leads_equals_leads_reports_grand_total_on_the_all_view_with_a_combo_order(): void
    {
        $this->seedComboOrder();
        $today = now()->toDateString();

        $dashboard   = $this->get(route('dashboard', ['date_from' => $today, 'date_to' => $today, 'team' => 'all']));
        $leadsReport = $this->get(route('leads-report', ['team' => 'all', 'range' => 'dates', 'date_from' => $today, 'date_to' => $today]));

        $dashboard->assertOk();
        $leadsReport->assertOk();

        $totalLeads = $dashboard->viewData('stats')['total_leads'];
        $grandTotal = $leadsReport->viewData('grandTotal')['total'];

        $this->assertSame(3, $totalLeads); // sum of rows: the combo order counts twice
        $this->assertSame($grandTotal, $totalLeads);
    }

    public function test_dashboard_total_leads_equals_leads_reports_grand_total_on_a_single_team_view_with_a_combo_order(): void
    {
        $this->seedComboOrder();
        $today = now()->toDateString();

        $dashboard   = $this->get(route('dashboard', ['date_from' => $today, 'date_to' => $today, 'team' => 'sh-naturals']));
        $leadsReport = $this->get(route('leads-report', ['team' => 'sh-naturals', 'range' => 'dates', 'date_from' => $today, 'date_to' => $today]));

        $dashboard->assertOk();
        $leadsReport->assertOk();

        $totalLeads = $dashboard->viewData('stats')['total_leads'];
        $grandTotal = $leadsReport->viewData('grandTotal')['total'];

        // SH Naturals' own SINUXYL row finds the combo order too (cross-team
        // match pool, not team-scoped) even though the order's own `team` is
        // Eyecare — same as the plain SH Naturals order, so 2 total.
        $this->assertSame(2, $totalLeads);
        $this->assertSame($grandTotal, $totalLeads);
    }
}
