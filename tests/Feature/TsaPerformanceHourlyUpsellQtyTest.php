<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\Product;
use App\Models\TsaShift;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Explicit request (2026-08-11): the Per-Product Hourly Breakdown's per-cell
 * qty must reflect how many of that hour's leads for a product were an
 * actual upsell, not every catered (called) lead for it — a product with
 * real call volume but zero upsells that hour used to show a bare qty with
 * no ₱ amount next to it, reading as "4 upsells" when it was really "4
 * leads called, 0 upsold". The separate "Total Catered Leads" column keeps
 * its original, unrelated meaning.
 */
class TsaPerformanceHourlyUpsellQtyTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->create());
    }

    public function test_per_product_cell_qty_counts_only_upsells_not_every_catered_lead(): void
    {
        $shift   = TsaShift::where('team', 'SH Naturals')->first();
        $product = Product::where('display_name', 'SINUXYL')->first();
        $hour    = now('Asia/Manila')->setTime(10, 0)->startOfHour();

        // 3 catered (called, resolved) leads for this product this hour —
        // only 1 of them a real upsell.
        Order::create([
            'pancake_order_id' => 'up-1', 'team' => 'SH Naturals', 'tsa_name' => $shift->tsa_key,
            'disposition' => 'CONFIRMED VIA CALL', 'product' => $product->display_name,
            'is_upsell' => true, 'amount' => 500.0, 'status_code' => 2,
            'pancake_created_at' => $hour->copy()->addMinutes(5), 'synced_at' => now(),
        ]);
        Order::create([
            'pancake_order_id' => 'no-upsell-1', 'team' => 'SH Naturals', 'tsa_name' => $shift->tsa_key,
            'disposition' => 'CONFIRMED VIA CALL', 'product' => $product->display_name,
            'is_upsell' => false, 'amount' => 0.0, 'status_code' => 2,
            'pancake_created_at' => $hour->copy()->addMinutes(10), 'synced_at' => now(),
        ]);
        Order::create([
            'pancake_order_id' => 'no-upsell-2', 'team' => 'SH Naturals', 'tsa_name' => $shift->tsa_key,
            'disposition' => 'NOT ANSWERING', 'product' => $product->display_name,
            'is_upsell' => false, 'amount' => 0.0, 'status_code' => 1,
            'pancake_created_at' => $hour->copy()->addMinutes(15), 'synced_at' => now(),
        ]);

        $team = collect(config('teams'))->search(fn ($t) => $t['order_team'] === 'SH Naturals');

        $response = $this->get(route('tsa-performance.individual', [
            'team' => $team, 'tsaKey' => $shift->tsa_key,
            'date_from' => $hour->toDateString(), 'date_to' => $hour->toDateString(),
        ]));

        $response->assertOk();
        $response->assertViewHas('productHourlyRows', function ($rows) use ($product) {
            $row = collect($rows)->first();
            if (!$row) return false;

            // Qty shown for this product this hour: just the 1 real upsell,
            // not all 3 catered leads.
            $qtyCorrect = ($row['counts'][$product->id] ?? null) === 1;
            // "Total Catered Leads" (a different column entirely) still
            // reflects all 3 called leads, unaffected by the qty change above.
            $rowTotalCorrect = $row['row_total'] === 3;

            return $qtyCorrect && $rowTotalCorrect;
        });
    }
}
