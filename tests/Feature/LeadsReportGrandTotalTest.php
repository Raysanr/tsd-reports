<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\TsaShift;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * History (2026-08-21, same day, three iterations): Grand Total was
 * originally a distinct-order tally() (ran LOWER than the visible
 * product-row sum whenever a cross-team combo order legitimately counted
 * toward more than one product's row). Fixed by switching to
 * ProductPerformance::sumRows() over the visible rows, so this page's own
 * numbers always tallied internally. Reverted to a distinct-order tally()
 * to match Dashboard/TSA Performance instead (see the git history on this
 * file for that attempt) — but that broke this page's OWN internal
 * consistency (row sum ≠ Grand Total whenever an order matched no tracked
 * product at all), and adding an "Other/Unmatched Product" row to
 * reconcile that was explicitly rejected ("no i dont want this... i want
 * only will be the total will be like all of the added in this data").
 * Landed back on sumRows() — Grand Total IS the sum of the visible product
 * rows, full stop; an order matching no tracked product simply isn't
 * counted anywhere on this page (fix: add the missing product in Product
 * Management, not reconcile it here). DashboardController's total_leads/
 * catered_leads were brought back to this same sumRows() definition too
 * (explicit follow-up: "it will be tally too to the dashboard") — see that
 * controller's own matching comment.
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

    public function test_per_team_grand_total_equals_the_sum_of_the_product_rows_with_a_cross_team_combo_order(): void
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

        // ...plus a cross-team combo order that bundles a SINUXYL half into an
        // Eyecare-owned order — exactly the shape that caused the production gap.
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
            return $grandTotal['total'] === $this->sumOfProductTotals($tables)
                && $grandTotal['total'] === 2; // plain-sinuxyl + the combo's Sinuxyl half
        });
    }

    public function test_per_team_hourly_grand_total_rows_sum_to_the_same_hours_product_rows(): void
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
        $productTables = $response->viewData('productTables');
        $grandTotalHourlyRows = $response->viewData('grandTotalHourlyRows');

        // For every hour Grand Total shows a row, its total must equal that
        // same hour's sum across every product's hourly row.
        foreach ($grandTotalHourlyRows as $ghRow) {
            $sumThatHour = $productTables->sum(function ($table) use ($ghRow) {
                $match = collect($table['hourlyRows'])->firstWhere('label', $ghRow['label']);
                return $match['row']['total'] ?? 0;
            });
            $this->assertSame($sumThatHour, $ghRow['row']['total'], "Mismatch at hour {$ghRow['label']}");
        }
    }

    public function test_all_view_grand_total_equals_the_sum_of_the_product_rows_with_a_cross_team_combo_order(): void
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

        $this->assertSame($productRows->sum('total'), $grandTotal['total']);
        // The combo order counts under both PTERYGIUM and SINUXYL, so the true
        // row sum (3) is one more than the distinct-order count (2) would be.
        $this->assertSame(3, $grandTotal['total']);
    }

    /** Explicit request: SH Naturals' Grand Total + Eyecare's Grand Total must
     *  equal the ALL view's Grand Total — holds by construction under
     *  sumRows(), since every product belongs to exactly one team and every
     *  view matches against the identical cross-team order pool. */
    public function test_sh_naturals_plus_eyecare_grand_totals_equal_the_all_views_grand_total(): void
    {
        $shShift = TsaShift::where('team', 'SH Naturals')->first();
        $eyeShift = TsaShift::where('team', 'Eyecare Team')->first();

        Order::create([
            'pancake_order_id' => 'sh-only', 'team' => 'SH Naturals', 'tsa_name' => $shShift->tsa_key,
            'disposition' => 'CONFIRMED VIA CALL', 'product' => 'Sinuxyl',
            'raw_tags' => [strtoupper($shShift->tsa_key), 'CONFIRMED VIA CALL'],
            'is_upsell' => false, 'status_code' => 1, 'pancake_created_at' => now(), 'synced_at' => now(),
        ]);
        Order::create([
            'pancake_order_id' => 'eye-only', 'team' => 'Eyecare Team', 'tsa_name' => $eyeShift->tsa_key,
            'disposition' => 'CONFIRMED VIA CALL', 'product' => 'Pterygium',
            'raw_tags' => [strtoupper($eyeShift->tsa_key), 'CONFIRMED VIA CALL'],
            'is_upsell' => false, 'status_code' => 1, 'pancake_created_at' => now(), 'synced_at' => now(),
        ]);
        // Cross-team combo — the exact case that could break additivity if
        // matched against a team-scoped pool instead of the shared one.
        Order::create([
            'pancake_order_id' => 'combo-sum', 'team' => 'Eyecare Team', 'tsa_name' => $eyeShift->tsa_key,
            'disposition' => 'CONFIRMED VIA CALL', 'product' => 'Pterygium',
            'bundle_description' => '10 Pterygium Drops + 10 Sinuxyl',
            'raw_tags' => [strtoupper($eyeShift->tsa_key), 'CONFIRMED VIA CALL'],
            'is_upsell' => false, 'status_code' => 1, 'pancake_created_at' => now(), 'synced_at' => now(),
        ]);

        $today = now()->toDateString();

        $sh  = $this->get(route('leads-report', ['team' => 'sh-naturals', 'range' => 'dates', 'date_from' => $today, 'date_to' => $today]));
        $eye = $this->get(route('leads-report', ['team' => 'eyecare', 'range' => 'dates', 'date_from' => $today, 'date_to' => $today]));
        $all = $this->get(route('leads-report', ['team' => 'all', 'range' => 'dates', 'date_from' => $today, 'date_to' => $today]));

        $sh->assertOk();
        $eye->assertOk();
        $all->assertOk();

        $shTotal  = $sh->viewData('grandTotal')['total'];
        $eyeTotal = $eye->viewData('grandTotal')['total'];
        $allTotal = $all->viewData('grandTotal')['total'];

        $this->assertSame($allTotal, $shTotal + $eyeTotal);
    }
}
