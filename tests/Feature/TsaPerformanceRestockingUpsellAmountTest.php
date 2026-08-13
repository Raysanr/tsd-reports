<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\Product;
use App\Models\TsaShift;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Confirmed live (2026-08-13, order #1348150, Julie/Eyecare Team): a ₱1,800
 * order (Clear Sight ₱800 + Lumicare Oil/Haplunas bundle ₱1,000) sitting in
 * Restocking status (status_code 11, a void status) still carries a real
 * "UPSELL TSD - ..." tag. Both ProductPerformance::tally() and
 * TsaPerformanceController::buildRow() deliberately recover it into
 * upsell_confirmation (Order::isRealUpsell()'s own doc comment already
 * flagged this as a "known edge case") via their broader $isRealUpsell
 * tag-fallback, correctly counting the TSA's work. But both then summed the
 * order's plain 'amount' column for revenue — which SyncTodayOrders only
 * ever isolates to the add-on price for is_upsell/is_returned_upsell rows,
 * NOT for a Restocking row (that isolated value lives in
 * restocking_upsell_amount instead) — so the Per-Product Hourly Breakdown
 * and the per-TSA AOV/upsell_sales figures showed the full ₱1,800 order
 * total instead of the real ₱1,000 add-on value.
 */
class TsaPerformanceRestockingUpsellAmountTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->create());
    }

    private function makeRestockingUpsellOrder(string $tsaKey, string $productName, $createdAt): Order
    {
        return Order::create([
            'pancake_order_id'         => '1348150',
            'team'                     => 'Eyecare Team',
            'tsa_name'                 => $tsaKey,
            'disposition'              => null,
            'product'                  => $productName,
            'amount'                   => 1800.0, // raw order total — never the isolated add-on for this status
            'raw_tags'                 => ['UPSELL TSD - CLEARSIGHT + LUMICARE + HAPLUNAS'],
            'is_upsell'                => false, // forced false: status 11 is a void status
            'is_cancelled_upsell'      => false,
            'is_returned_upsell'       => false,
            'is_restocking_upsell'     => true,
            'restocking_upsell_amount' => 1000.0, // the real isolated add-on price
            'status_code'              => 11, // Restocking
            'pancake_created_at'       => $createdAt,
            'synced_at'                => now(),
        ]);
    }

    public function test_per_product_hourly_breakdown_shows_the_isolated_addon_amount_not_the_full_order_total(): void
    {
        $product = Product::where('team', 'Eyecare Team')->where('display_name', 'CLEARSIGHT')->first();
        $hour    = now('Asia/Manila')->setTime(19, 0)->startOfHour();

        $this->makeRestockingUpsellOrder('Julie', $product->display_name, $hour->copy()->addMinutes(4));

        $response = $this->get(route('tsa-performance.individual', [
            'team' => 'eyecare', 'tsaKey' => 'Julie',
            'date_from' => $hour->toDateString(), 'date_to' => $hour->toDateString(),
        ]));

        $response->assertOk();
        $response->assertViewHas('productHourlyRows', function ($rows) use ($product) {
            $row = collect($rows)->first();
            if (!$row) return false;

            return ($row['amounts'][$product->id] ?? null) === 1000.0;
        });
    }

    public function test_team_page_upsell_sales_uses_the_isolated_addon_amount_not_the_full_order_total(): void
    {
        $hour = now('Asia/Manila')->setTime(19, 0)->startOfHour();

        $this->makeRestockingUpsellOrder('Julie', 'CLEARSIGHT', $hour->copy()->addMinutes(4));

        $response = $this->get(route('tsa-performance', [
            'team' => 'eyecare',
            'date_from' => $hour->toDateString(), 'date_to' => $hour->toDateString(),
        ]));

        $response->assertOk();
        $response->assertViewHas('hourBlocks', function ($blocks) {
            $block = collect($blocks)->first();
            if (!$block) return false;

            $julieRow = collect($block['rows'])->firstWhere('tsa_key', 'Julie');

            return $julieRow && $julieRow['upsell_sales'] === 1000.0;
        });
    }
}
