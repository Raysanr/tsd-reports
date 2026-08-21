<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\TsaShift;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * History: Grand Total was originally a distinct-order tally() (ran LOWER
 * than the visible product-row sum whenever a cross-team combo order
 * legitimately counted toward more than one product's row — order #1343222
 * in production, "10 Pterygium Drops + 10 Sinuxyl" summed to 333 across the
 * product rows but Grand Total showed 332). Fixed 2026-08-XX by switching
 * Grand Total to ProductPerformance::sumRows() over the visible rows, so
 * this page's own numbers always tallied internally.
 *
 * Reverted 2026-08-21 (explicit request): that fix never touched TSA
 * Performance's own Grand Total, which stayed a distinct-order tally() the
 * whole time — so Dashboard/Leads Report started disagreeing with TSA
 * Performance instead, by exactly the count of combo orders in range.
 * Grand Total is a distinct-order tally() again (same definition Dashboard's
 * total_leads and TsaPerformanceController's Grand Totals use), restoring
 * 3-way consistency across Dashboard/Leads Report/TSA Performance at the
 * cost of this page's OWN internal consistency for a range containing a
 * combo order — Grand Total can once again run lower than the sum of the
 * product rows shown above it. That trade-off is what these tests now
 * document, not guard against.
 */
class LeadsReportGrandTotalTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->create());
    }

    private function sumOfProductTotals($tables): int
    {
        return $tables->sum(fn ($t) => $t['total']['total']);
    }

    public function test_per_team_grand_total_is_this_teams_own_distinct_order_count_not_the_cross_team_product_row_sum(): void
    {
        $shShift = TsaShift::where('team', 'SH Naturals')->first();
        $eyeShift = TsaShift::where('team', 'Eyecare Team')->first();

        // A normal same-team order...
        Order::create([
            'pancake_order_id' => 'plain-sinuxyl', 'team' => 'SH Naturals', 'tsa_name' => $shShift->tsa_key,
            'disposition' => 'CONFIRMED VIA CALL', 'product' => 'Sinuxyl',
            'raw_tags' => [strtoupper($shShift->tsa_key), 'CONFIRMED VIA CALL'],
            'is_upsell' => false, 'status_code' => 1, 'pancake_created_at' => now(), 'synced_at' => now(),
        ]);

        // ...plus a cross-team combo order OWNED by Eyecare (its own `team`
        // column) that bundles a SINUXYL half into it. SINUXYL's own product
        // row still finds it via the cross-team match pool (matchingOrders()
        // deliberately isn't team-scoped), but Grand Total is now this team's
        // own distinct orders only — the combo order's `team` is Eyecare, not
        // SH Naturals, so it doesn't belong to SH Naturals' own count.
        Order::create([
            'pancake_order_id' => 'combo-order', 'team' => 'Eyecare Team', 'tsa_name' => $eyeShift->tsa_key,
            'disposition' => 'CONFIRMED VIA CALL', 'product' => 'Pterygium',
            'bundle_description' => '10 Pterygium Drops + 10 Sinuxyl',
            'raw_tags' => [strtoupper($eyeShift->tsa_key), 'CONFIRMED VIA CALL'],
            'is_upsell' => false, 'status_code' => 1, 'pancake_created_at' => now(), 'synced_at' => now(),
        ]);

        $today = now()->toDateString();

        $response = $this->get(route('leads-report', [
            'team' => 'sh-naturals', 'range' => 'dates', 'date_from' => $today, 'date_to' => $today,
        ]));

        $response->assertOk();
        $response->assertViewHas('productTables', function ($tables) use ($response) {
            $grandTotal = $response->viewData('grandTotal');
            // SINUXYL's own row still shows both orders (2) — the cross-team
            // pool finds the combo order via its Sinuxyl content — but Grand
            // Total (1) reflects only the ONE order whose own `team` is SH
            // Naturals. The two deliberately disagree here.
            return $this->sumOfProductTotals($tables) === 2
                && $grandTotal['total'] === 1;
        });
    }

    public function test_per_team_hourly_grand_total_matches_that_hours_team_scoped_orders(): void
    {
        $shift = TsaShift::where('team', 'SH Naturals')->first();

        Order::create([
            'pancake_order_id' => 'hourly-1', 'team' => 'SH Naturals', 'tsa_name' => $shift->tsa_key,
            'disposition' => 'CONFIRMED VIA CALL', 'product' => 'Sinuxyl',
            'raw_tags' => [strtoupper($shift->tsa_key), 'CONFIRMED VIA CALL'],
            'is_upsell' => false, 'status_code' => 1, 'pancake_created_at' => now(), 'synced_at' => now(),
        ]);

        $today = now()->toDateString();

        $response = $this->get(route('leads-report', [
            'team' => 'sh-naturals', 'range' => 'dates', 'date_from' => $today, 'date_to' => $today,
        ]));

        $response->assertOk();
        $grandTotalHourlyRows = $response->viewData('grandTotalHourlyRows');

        // No combo order in this scenario, so the team-scoped tally() and the
        // product-row sum coincide — one real order shows up as exactly 1.
        $this->assertSame(1, collect($grandTotalHourlyRows)->sum('row.total'));
    }

    public function test_all_view_grand_total_is_the_cross_team_distinct_order_count_not_the_product_row_sum(): void
    {
        $shShift = TsaShift::where('team', 'SH Naturals')->first();
        $eyeShift = TsaShift::where('team', 'Eyecare Team')->first();

        Order::create([
            'pancake_order_id' => 'plain-sinuxyl-all', 'team' => 'SH Naturals', 'tsa_name' => $shShift->tsa_key,
            'disposition' => 'CONFIRMED VIA CALL', 'product' => 'Sinuxyl',
            'raw_tags' => [strtoupper($shShift->tsa_key), 'CONFIRMED VIA CALL'],
            'is_upsell' => false, 'status_code' => 1, 'pancake_created_at' => now(), 'synced_at' => now(),
        ]);

        Order::create([
            'pancake_order_id' => 'combo-order-all', 'team' => 'Eyecare Team', 'tsa_name' => $eyeShift->tsa_key,
            'disposition' => 'CONFIRMED VIA CALL', 'product' => 'Pterygium',
            'bundle_description' => '10 Pterygium Drops + 10 Sinuxyl',
            'raw_tags' => [strtoupper($eyeShift->tsa_key), 'CONFIRMED VIA CALL'],
            'is_upsell' => false, 'status_code' => 1, 'pancake_created_at' => now(), 'synced_at' => now(),
        ]);

        $today = now()->toDateString();

        $response = $this->get(route('leads-report', [
            'team' => 'all', 'range' => 'dates', 'date_from' => $today, 'date_to' => $today,
        ]));

        $response->assertOk();
        $productRows = $response->viewData('productRows');
        $grandTotal  = $response->viewData('grandTotal');

        // The combo order counts under both PTERYGIUM and SINUXYL, so the row
        // sum (3) is one more than the true distinct-order count (2) — Grand
        // Total is now the latter, matching Dashboard's own 'all'-team total
        // and TsaPerformanceController::indexAll()'s Grand Total, both of
        // which tally() this exact same team+date-scoped order set.
        $this->assertSame(3, $productRows->sum('total'));
        $this->assertSame(2, $grandTotal['total']);
    }
}
