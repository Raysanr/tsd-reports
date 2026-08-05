<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\TsaShift;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Explicit request: Dashboard's "Total Leads" must equal Leads Report's Grand
 * Total for the same range/team, not just usually agree. Before this fix,
 * Dashboard tallied $leadOrders as a distinct-order count (ProductPerformance::
 * tally()), while Leads Report's Grand Total is the SUM of its per-product rows
 * (ProductPerformance::sumRows() — see its own doc comment) — the two
 * necessarily diverged by however many cross-team combo orders matched more
 * than one product in the range (confirmed live: Dashboard 600 vs Leads
 * Report's Grand Total 601 on 2026-08-04). Dashboard now builds the same
 * per-product rows Leads Report builds and sums those instead.
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

        // Cross-team combo: an Eyecare-owned order bundling a SINUXYL half — the
        // exact shape that makes a row-sum Grand Total run higher than a
        // distinct-order count of the same orders.
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

        $dashboard = $this->get(route('dashboard', ['date_from' => $today, 'date_to' => $today, 'team' => 'all']));
        $leadsReport = $this->get(route('leads-report', ['team' => 'all', 'range' => 'dates', 'date_from' => $today, 'date_to' => $today]));

        $dashboard->assertOk();
        $leadsReport->assertOk();

        $totalLeads = $dashboard->viewData('stats')['total_leads'];
        $grandTotal = $leadsReport->viewData('grandTotal')['total'];

        $this->assertSame(3, $totalLeads); // the combo order counts under both PTERYGIUM and SINUXYL
        $this->assertSame($grandTotal, $totalLeads);
    }

    public function test_dashboard_total_leads_equals_leads_reports_grand_total_on_a_single_team_view_with_a_combo_order(): void
    {
        $this->seedComboOrder();
        $today = now()->toDateString();

        $dashboard = $this->get(route('dashboard', ['date_from' => $today, 'date_to' => $today, 'team' => 'sh-naturals']));
        $leadsReport = $this->get(route('leads-report', ['team' => 'sh-naturals', 'range' => 'dates', 'date_from' => $today, 'date_to' => $today]));

        $dashboard->assertOk();
        $leadsReport->assertOk();

        $totalLeads = $dashboard->viewData('stats')['total_leads'];
        $grandTotal = $leadsReport->viewData('grandTotal')['total'];

        $this->assertSame(2, $totalLeads); // plain-sinuxyl-dash + the combo's Sinuxyl half
        $this->assertSame($grandTotal, $totalLeads);
    }
}
