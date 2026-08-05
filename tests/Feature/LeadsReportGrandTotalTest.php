<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\TsaShift;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Explicit request: Grand Total must always equal the sum of the rows shown
 * above it. Before this fix, Grand Total was a separately-tallied distinct-
 * order count (ProductPerformance::tally() over the raw order set), which ran
 * LOWER than the row sum whenever a cross-team combo order legitimately
 * counted toward more than one product's row (see ProductPerformance::
 * matchingOrders()'s $explicitMatch comment, and order #1343222 in
 * production: "10 Pterygium Drops + 10 Sinuxyl" summed to 333 across the
 * product rows but Grand Total showed 332). Grand Total is now
 * ProductPerformance::sumRows() over the exact same rows the page renders.
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
}
