<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\Product;
use App\Models\TsaShift;
use App\Models\User;
use App\Support\ProductPerformance;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Explicit request (2026-10-14): "fix any product that like that, it should
 * be reflect all accurately and can you add that to the tally checker" —
 * SEPARATE PARCEL order #1376764 (Lika, Oct 5, ₱1,700 "Belo Set", tagged
 * "TSD UPSELL - GINSENG SERUM") counted in TSA Performance's Upsell w/
 * Confirmation but in no product column.
 */
class UpsellTagFallbackMatchingTest extends TestCase
{
    use RefreshDatabase;

    private function beloOrder(TsaShift $tsa, array $tags = ['GINSENG SERUM', 'TSD UPSELL - GINSENG SERUM', 'SEPARATE PARCEL']): Order
    {
        return Order::create([
            'pancake_order_id' => '1376764', 'team' => $tsa->team, 'tsa_name' => $tsa->tsa_key,
            'product' => 'BELO SET', 'base_product' => 'BELO SET',
            'is_upsell' => true, 'amount' => 1700, 'status_code' => 3,
            'raw_tags' => array_merge([strtoupper($tsa->tsa_key)], $tags),
            'pancake_created_at' => today()->toDateString() . ' 20:16:00', 'synced_at' => now(),
        ]);
    }

    private function matchedProducts(Order $order): array
    {
        $all = Product::orderBy('sort_order')->get();

        return $all->filter(fn (Product $p) => ProductPerformance::matchingOrders($p, collect([$order]), $all)->isNotEmpty())
            ->pluck('display_name')->values()->all();
    }

    public function test_an_upsell_no_product_claims_falls_back_to_its_tag_product(): void
    {
        // Simulate whatever blocks it in production: Ginseng Serum's own
        // exclude keyword rejecting the "BELO SET" item name.
        Product::where('display_name', 'GINSENG SERUM')->update(['match_exclude_keyword' => 'BELO']);
        $order = $this->beloOrder(TsaShift::first());

        $this->assertSame(['GINSENG SERUM'], $this->matchedProducts($order), 'exactly one column — the product its upsell tag names');
    }

    public function test_the_fallback_never_double_counts_a_normally_matched_order(): void
    {
        $tsa = TsaShift::first();
        $order = Order::create([
            'pancake_order_id' => 'normal-1', 'team' => $tsa->team, 'tsa_name' => $tsa->tsa_key,
            'product' => 'Audicure', 'base_product' => 'Audicure',
            'is_upsell' => true, 'amount' => 500, 'status_code' => 3,
            'raw_tags' => ['AUDICURE', 'TSD UPSELL - GINSENG SERUM'],
            'pancake_created_at' => today()->toDateString() . ' 10:00:00', 'synced_at' => now(),
        ]);

        $this->assertNotContains('GINSENG SERUM', $this->matchedProducts($order));
        $this->assertContains('AUDICURE', $this->matchedProducts($order));
    }

    public function test_a_two_product_tag_falls_back_to_the_base_product(): void
    {
        $this->assertSame('NUTRILAY', Order::upsellTagProductName(['UPSELL TSD - NUTRILAY + NUTRIGAIN']));
        $this->assertSame('GINSENG SERUM', Order::upsellTagProductName(['TSD UPSELL - GINSENG SERUM']));
        $this->assertSame('Ear Relief Balm', Order::upsellTagProductName(['Upsell TSD (Ear Relief Balm)']));
        $this->assertNull(Order::upsellTagProductName(['SEPARATE PARCEL', 'LIKA']));
    }

    public function test_tally_checker_lists_tsa_orders_missing_from_every_product_column(): void
    {
        $tsa = TsaShift::first();
        $date = today()->toDateString();
        // No product keyword and no TSD UPSELL tag — nothing to fall back on.
        Order::create([
            'pancake_order_id' => 'orphan-1', 'team' => $tsa->team, 'tsa_name' => $tsa->tsa_key,
            'product' => 'Mystery Bundle', 'base_product' => 'Mystery Bundle',
            'is_upsell' => true, 'amount' => 900, 'status_code' => 3,
            'raw_tags' => [strtoupper($tsa->tsa_key), 'SEPARATE PARCEL'],
            'pancake_created_at' => $date . ' 11:00:00', 'synced_at' => now(),
        ]);
        Product::where('display_name', 'GINSENG SERUM')->update(['match_exclude_keyword' => 'BELO']);
        $this->beloOrder($tsa);

        $rows = collect($this->actingAs(User::factory()->create(['role' => 'admin']))
            ->getJson(route('data.tally-checker', ['date_from' => $date, 'date_to' => $date]))
            ->assertOk()->json('tsaProductColumns'));

        $row = $rows->firstWhere('tsa', $tsa->display_name);
        $this->assertSame(2, $row['totalOrders']);
        $this->assertSame(1, $row['inColumns'], 'the Belo Set order now lands in Ginseng Serum via its tag; the orphan does not');
        $this->assertFalse($row['tallies']);
        $this->assertSame(['orphan-1'], array_column($row['missing'], 'orderId'));
        $this->assertStringContainsString('Product Management', $row['missing'][0]['reason']);
    }
}
