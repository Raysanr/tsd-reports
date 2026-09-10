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
 * not once — since that's exactly what "sum of the visible rows" means
 * (2026-09-07, fifth revision — see LeadsReportController's own
 * shift-window comment for the full history of a team-grouped, no-double-
 * count detour and back to this plain-sum definition).
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

        // SH Naturals' own single-team pool no longer sees the combo order at
        // all (2026-09-07, sixth revision: the pool is bounded to this team's
        // own hour window, same as Leads Report's own per-team page) — the
        // order's own `team` is Eyecare, so only plain-sinuxyl (1) counts.
        $this->assertSame(1, $totalLeads);
        $this->assertSame($grandTotal, $totalLeads);
    }

    /**
     * Real production bug, root-caused 2026-09-10: Team Opening's Dashboard
     * Total Leads showed 91 for a day/team where Leads Report's own Grand
     * Total showed 158 — a 67-lead gap. Cause: DashboardController::index()
     * used to scope $allProducts to `Product::where('team', $orderTeams[0])`
     * on a single-team view, on the false assumption (see the old comment
     * this test replaces) that a foreign-team product could never match an
     * order sitting inside this team's own hour-scoped pool. It can:
     * ProductPerformance::matchingOrders() lets an explicit product/
     * base_product/bundle_description text match override the team check
     * entirely — so a genuinely SH-Naturals-branded product (e.g. Sinuxyl)
     * sold during Team Opening's own hours (before 3pm, TeamShiftWindow)
     * DOES belong in that day's Eyecare-team order pool and DOES match,
     * exactly like Leads Report's own (correct, unscoped) product list
     * already counted it — Dashboard's narrowed product list just never
     * considered Sinuxyl as a candidate at all on Team Opening's page.
     *
     * Neither of the two existing tests above catches this: both seed every
     * order under a product that already belongs to that same order's own
     * team, so the narrowed $allProducts list was never missing a genuine
     * match — this test is the one case (foreign product + own-team hour)
     * those two don't construct. Checked on BOTH teams (not just Opening,
     * since the bug report was specific to Opening but the fix is
     * symmetric) and on a non-today date, since the original two tests only
     * ever used now().
     */
    public function test_dashboard_total_leads_equals_leads_reports_grand_total_for_a_foreign_team_product_sold_in_this_teams_hours(): void
    {
        $yesterday = now()->subDay();

        $eyeShift = TsaShift::where('team', 'Eyecare Team')->first();
        $shShift  = TsaShift::where('team', 'SH Naturals')->first();

        // Sinuxyl (an SH Naturals product) sold at 9am — Opening's own hour
        // window (TeamShiftWindow::forHour(9) === 'Eyecare Team') — so this
        // order's own `team` column is Eyecare Team even though the product
        // is nominally SH Naturals'.
        Order::create([
            'pancake_order_id' => 'foreign-product-opening-hour', 'team' => 'Eyecare Team', 'tsa_name' => $eyeShift->tsa_key,
            'disposition' => 'CONFIRMED VIA CALL', 'product' => 'Sinuxyl',
            'raw_tags' => [strtoupper($eyeShift->tsa_key), 'CONFIRMED VIA CALL'],
            'is_upsell' => false, 'status_code' => 1,
            'pancake_created_at' => $yesterday->copy()->setTime(9, 0), 'synced_at' => now(),
        ]);

        // Mirror case for Closing: Pterygium (an Eyecare product) sold at
        // 8pm — Closing's own hour window — order's own `team` is SH Naturals.
        Order::create([
            'pancake_order_id' => 'foreign-product-closing-hour', 'team' => 'SH Naturals', 'tsa_name' => $shShift->tsa_key,
            'disposition' => 'CONFIRMED VIA CALL', 'product' => 'Pterygium',
            'raw_tags' => [strtoupper($shShift->tsa_key), 'CONFIRMED VIA CALL'],
            'is_upsell' => false, 'status_code' => 1,
            'pancake_created_at' => $yesterday->copy()->setTime(20, 0), 'synced_at' => now(),
        ]);

        $date = $yesterday->toDateString();

        foreach (['eyecare' => 1, 'sh-naturals' => 1] as $team => $expected) {
            $dashboard   = $this->get(route('dashboard', ['date_from' => $date, 'date_to' => $date, 'team' => $team]));
            $leadsReport = $this->get(route('leads-report', ['team' => $team, 'range' => 'dates', 'date_from' => $date, 'date_to' => $date]));

            $dashboard->assertOk();
            $leadsReport->assertOk();

            $totalLeads = $dashboard->viewData('stats')['total_leads'];
            $grandTotal = $leadsReport->viewData('grandTotal')['total'];

            $this->assertSame($expected, $totalLeads, "Dashboard total_leads mismatch for team={$team}");
            $this->assertSame($grandTotal, $totalLeads, "Dashboard/LeadsReport disagree for team={$team}");
        }
    }
}
