<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\TsaShift;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Explicit request (2026-08-08): Dashboard's "Total Leads" must equal TSA
 * Performance's Grand Total for the same range/team — replaces the earlier
 * "must equal Leads Report's Grand Total" requirement (see the now-removed
 * DashboardTotalLeadsMatchesLeadsReportTest, and DashboardController::
 * index()'s $leadTally comment for the full reasoning). Both now call
 * ProductPerformance::tally() on the same $dayOrders shape TsaPerformance
 * Controller::indexAll()/index() build, so a cross-team combo order (e.g. a
 * Pterygium order bundling Sinuxyl units) counts ONCE here — a distinct-order
 * tally, not the per-product row-sum Leads Report uses, which would have
 * counted it twice.
 */
class DashboardTotalLeadsMatchesTsaPerformanceTest extends TestCase
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
        // would double-count under Leads Report's per-product row sum, but a
        // distinct-order tally() (what both pages use now) counts it once.
        Order::create([
            'pancake_order_id' => 'combo-order-dash', 'team' => 'Eyecare Team', 'tsa_name' => $eyeShift->tsa_key,
            'disposition' => 'CONFIRMED VIA CALL', 'product' => 'Pterygium',
            'bundle_description' => '10 Pterygium Drops + 10 Sinuxyl',
            'raw_tags' => [strtoupper($eyeShift->tsa_key), 'CONFIRMED VIA CALL'],
            'is_upsell' => false, 'status_code' => 1, 'pancake_created_at' => now(), 'synced_at' => now(),
        ]);
    }

    public function test_dashboard_total_leads_equals_tsa_performances_grand_total_on_the_all_view_with_a_combo_order(): void
    {
        $this->seedComboOrder();
        $today = now()->toDateString();

        $dashboard = $this->get(route('dashboard', ['date_from' => $today, 'date_to' => $today, 'team' => 'all']));
        $tsaPerformance = $this->get(route('tsa-performance', ['team' => 'all', 'range' => 'dates', 'date_from' => $today, 'date_to' => $today]));

        $dashboard->assertOk();
        $tsaPerformance->assertOk();

        $totalLeads = $dashboard->viewData('stats')['total_leads'];
        $grandTotal = $tsaPerformance->viewData('grandTotal')['total'];

        $this->assertSame(2, $totalLeads); // distinct-order count, not the combo order counted twice
        $this->assertSame($grandTotal, $totalLeads);
    }

    public function test_dashboard_total_leads_equals_tsa_performances_grand_total_on_a_single_team_view_with_a_combo_order(): void
    {
        $this->seedComboOrder();
        $today = now()->toDateString();

        $dashboard = $this->get(route('dashboard', ['date_from' => $today, 'date_to' => $today, 'team' => 'sh-naturals']));
        $tsaPerformance = $this->get(route('tsa-performance', ['team' => 'sh-naturals', 'range' => 'dates', 'date_from' => $today, 'date_to' => $today]));

        $dashboard->assertOk();
        $tsaPerformance->assertOk();

        $totalLeads = $dashboard->viewData('stats')['total_leads'];
        // Single-team TSA Performance builds its Grand Total via hourly
        // buildRow() blocks summed into 'totals' (not a bare tally() call
        // like indexAll()'s 'grandTotal') — see index()'s own $totals
        // accumulator, using the same per-order classification rules.
        $grandTotal = $tsaPerformance->viewData('totals')['total'];

        // Only the SH Naturals-owned order — the combo order's team column is
        // Eyecare Team, so it's out of scope here regardless of its bundled content.
        $this->assertSame(1, $totalLeads);
        $this->assertSame($grandTotal, $totalLeads);
    }
}
