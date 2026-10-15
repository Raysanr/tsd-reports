<?php

namespace Tests\Feature;

use App\Http\Controllers\DataManagement\ExpectedIncomeController;
use App\Models\CostBreakdownPool;
use App\Models\CostBreakdownRole;
use App\Models\CostBreakdownTsaEntry;
use App\Models\Order;
use App\Models\Product;
use App\Models\TsaShift;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Explicit report (2026-10-15): Summary Sales Report's Team Closing TSA rows
 * summed to 61,214.56 Net Income but its TEAM TOTAL read 60,945.15 — the
 * pooled team total counted orders no product card matched (e.g. SEPARATE
 * PARCELs), the per-TSA rows (netIncomeByTsaAndDate()) did not.
 */
class SummarySalesTeamTotalSumTest extends TestCase
{
    use RefreshDatabase;

    public function test_team_rows_sum_to_the_pooled_team_total_with_unmatched_orders(): void
    {
        CostBreakdownRole::ensureSeeded();
        CostBreakdownPool::ensureSeeded();
        CostBreakdownTsaEntry::ensureSeeded();
        CostBreakdownTsaEntry::query()->update(['base_salary' => 20000]);

        $date = today()->toDateString();
        $tsa = TsaShift::first();
        $product = Product::where('team', $tsa->team)->first();
        $base = ['team' => $tsa->team, 'tsa_name' => $tsa->tsa_key, 'pancake_created_at' => "$date 10:00:00", 'synced_at' => now()];

        Order::create($base + ['pancake_order_id' => 'matched', 'product' => $product->display_name, 'is_upsell' => true, 'amount' => 1000, 'status_code' => 3, 'raw_tags' => [$product->display_name]]);
        Order::create($base + ['pancake_order_id' => 'unmatched', 'product' => 'Mystery', 'base_product' => 'Mystery', 'is_upsell' => true, 'amount' => 1700, 'status_code' => 3, 'raw_tags' => ['SEPARATE PARCEL']]);
        Order::create($base + ['pancake_order_id' => 'awaiting', 'product' => 'Mystery', 'base_product' => 'Mystery', 'is_upsell' => false, 'is_restocking_upsell' => true, 'restocking_upsell_amount' => 900, 'amount' => 900, 'status_code' => 11, 'raw_tags' => ['SEPARATE PARCEL']]);

        $byTsa = ExpectedIncomeController::netIncomeByTsaAndDate($date, $date);
        $rowsSum = TsaShift::where('team', $tsa->team)->get()->sum(fn ($t) => $byTsa->get($t->id . ':' . $date) ?? 0);
        $pooled = ExpectedIncomeController::teamNetIncomeByDate($date, $date)->get($tsa->team . ':' . $date);

        $this->assertEqualsWithDelta($pooled, $rowsSum, 0.02, 'TSA rows must add up to the TEAM TOTAL row');
    }
}
