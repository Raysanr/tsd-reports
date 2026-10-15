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

    /** The real stored tags (Tally Checker, 2026-10-14): no "TSD UPSELL -"
     *  tag at all — only a plain "GINSENG SERUM" one. */
    public function test_falls_back_to_a_plain_product_name_tag(): void
    {
        Product::where('display_name', 'GINSENG SERUM')->update(['match_exclude_keyword' => 'BELO']);
        $order = $this->beloOrder(TsaShift::first(), ['GINSENG SERUM', 'Unreconciled', 'SEPARATE PARCEL']);

        $this->assertSame(['GINSENG SERUM'], $this->matchedProducts($order));
    }

    public function test_tally_checker_names_the_exclude_keyword_blocking_a_non_separate_parcel(): void
    {
        $tsa = TsaShift::first();
        $date = today()->toDateString();
        Product::where('display_name', 'GINSENG SERUM')->update(['match_exclude_keyword' => 'BELO']);
        $this->beloOrder($tsa, ['GINSENG SERUM']);

        $row = collect($this->actingAs(User::factory()->create(['role' => 'admin']))
            ->getJson(route('data.tally-checker', ['date_from' => $date, 'date_to' => $date]))
            ->json('tsaProductColumns'))->firstWhere('tsa', $tsa->display_name);

        $this->assertStringContainsString('exclude keywords ("BELO")', $row['missing'][0]['reason']);
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

    /** 2026-10-15, order #1381155 (Marisol): "separate parcel" only in the
     *  note, item is the "Nutrigain" catalog entry, tags say NUTRILAY — the
     *  tag decides, even when another product claims the item name. */
    private function nutrigainParcel(TsaShift $tsa, array $extra = []): Order
    {
        return Order::create(array_merge([
            'pancake_order_id' => '1381155', 'team' => $tsa->team, 'tsa_name' => $tsa->tsa_key,
            'product' => 'Nutrigain', 'base_product' => 'Nutrigain', 'bundle_description' => '2 Nutrilay + 2 Nutrigain',
            'is_upsell' => true, 'amount' => 1300, 'status_code' => 3,
            'raw_tags' => [strtoupper($tsa->tsa_key), 'NUTRILAY', 'UPSELL TSD - NUTRILAY + NUTRIGAIN'],
            'note' => 'separate parcel',
            'pancake_created_at' => today()->toDateString() . ' 12:37:15', 'synced_at' => now(),
        ], $extra));
    }

    public function test_a_separate_parcel_note_lands_on_the_tagged_product(): void
    {
        $tsa = TsaShift::first();
        Product::forceCreate(['display_name' => 'NUTRILAY', 'team' => $tsa->team, 'sort_order' => 999]);

        $this->assertSame(['NUTRILAY'], $this->matchedProducts($this->nutrigainParcel($tsa)));
    }

    public function test_a_separate_parcel_tag_beats_a_product_matching_its_item_name(): void
    {
        $tsa = TsaShift::first();
        Product::forceCreate(['display_name' => 'NUTRIGAIN', 'team' => $tsa->team, 'sort_order' => 998]);
        Product::forceCreate(['display_name' => 'NUTRILAY', 'team' => $tsa->team, 'sort_order' => 999]);

        $this->assertSame(['NUTRILAY'], $this->matchedProducts($this->nutrigainParcel($tsa)));
    }

    public function test_without_a_separate_parcel_note_or_tag_the_base_product_rule_still_applies(): void
    {
        $tsa = TsaShift::first();
        Product::forceCreate(['display_name' => 'NUTRIGAIN', 'team' => $tsa->team, 'sort_order' => 998]);
        Product::forceCreate(['display_name' => 'NUTRILAY', 'team' => $tsa->team, 'sort_order' => 999]);

        $this->assertSame(['NUTRIGAIN'], $this->matchedProducts($this->nutrigainParcel($tsa, ['note' => null])));
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
