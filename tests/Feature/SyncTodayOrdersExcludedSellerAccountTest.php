<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\Setting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Root-caused 2026-08-21 (user report, order #1352836): a "UPSELL TSD -
 * Sinuxyl Inhaler" tag made is_upsell true even though the add-on item's
 * assigning_seller was "Ralph Cruz" — not a TSA (not in tsa_shifts, no
 * seller_keywords match), just an internal/admin account that happened to
 * touch the order in Pancake directly. hasUpsellTag()'s tag-text check
 * doesn't care who applied the tag, so the ₱800 counted as real cross-sell
 * revenue with no TSA to credit it to (excluded from the Leaderboard, still
 * counted on the Dashboard's Total Cross-Sell Sales card). Fix:
 * config/excluded_upsell_sellers.php lists known non-TSA accounts; any
 * order whose upsell item(s) were closed by one of them never counts as an
 * upsell at all (is_upsell/is_cancelled_upsell/is_returned_upsell/
 * is_restocking_upsell all stay false), regardless of what tag is present —
 * an ongoing rule, not a one-off correction, so every report tab that reads
 * those flags is automatically clean going forward.
 */
class SyncTodayOrdersExcludedSellerAccountTest extends TestCase
{
    use RefreshDatabase;

    private function fakeOnePage(array $order): void
    {
        Http::fake([
            'pos.pages.fm/api/v1/shops/*/orders*' => Http::sequence()
                ->push(['data' => [$order]])
                ->push(['data' => []]),
        ]);
    }

    public function test_an_upsell_tag_closed_by_an_excluded_non_tsa_account_never_counts_as_a_real_upsell(): void
    {
        Setting::set('pancake_api_key', 'test-key');
        Setting::set('shop_id', '30037101');

        $this->fakeOnePage([
            'id'          => 1352836,
            'status'      => 2,
            'total_price' => 1300,
            'inserted_at' => '2026-08-19T05:54:30',
            'updated_at'  => '2026-08-19T05:54:30',
            'tags'        => [
                ['id' => 1, 'name' => 'Call in Progress (Sinuxyl Inhaler)'],
                ['id' => 2, 'name' => 'Sinuxyl Inhaler Instruction'],
                ['id' => 3, 'name' => 'UPSELL TSD - Sinuxyl Inhaler'],
                ['id' => 4, 'name' => 'Picked up'],
            ],
            'items' => [
                ['variation_info' => ['name' => 'Sinuxyl', 'retail_price' => 500], 'quantity' => 1, 'assigning_seller' => ['name' => 'Ralph Cruz']],
                ['variation_info' => ['name' => 'Sinuxyl Nasal Inhaler', 'retail_price' => 800], 'quantity' => 1, 'assigning_seller' => ['name' => 'Ralph Cruz']],
            ],
        ]);

        Artisan::call('pancake:sync-today', ['--date' => '2026-08-19']);

        $order = Order::where('pancake_order_id', '1352836')->first();

        $this->assertNotNull($order);
        $this->assertFalse($order->is_upsell);
        $this->assertFalse($order->is_cancelled_upsell);
        $this->assertFalse($order->is_returned_upsell);
        $this->assertFalse($order->is_restocking_upsell);
        $this->assertNull($order->tsa_name);
    }

    public function test_matching_is_case_insensitive_and_tolerates_a_middle_name_or_extra_text(): void
    {
        Setting::set('pancake_api_key', 'test-key');
        Setting::set('shop_id', '30037101');

        $this->fakeOnePage([
            'id'          => 1352837,
            'status'      => 2,
            'total_price' => 900,
            'inserted_at' => '2026-08-19T06:00:00',
            'updated_at'  => '2026-08-19T06:00:00',
            'tags'        => [
                ['id' => 1, 'name' => 'UPSELL TSD - Ear Relief Balm'],
            ],
            'items' => [
                ['variation_info' => ['name' => 'AudiCure', 'retail_price' => 500], 'quantity' => 1, 'assigning_seller' => ['name' => 'RALPH CRUZ']],
                ['variation_info' => ['name' => 'Ear Relief Balm', 'retail_price' => 400], 'quantity' => 1, 'assigning_seller' => ['name' => 'ralph cruz']],
            ],
        ]);

        Artisan::call('pancake:sync-today', ['--date' => '2026-08-19']);

        $order = Order::where('pancake_order_id', '1352837')->first();

        $this->assertNotNull($order);
        $this->assertFalse($order->is_upsell);
    }

    public function test_a_real_tsa_seller_on_the_same_kind_of_order_is_unaffected(): void
    {
        Setting::set('pancake_api_key', 'test-key');
        Setting::set('shop_id', '30037101');

        $this->fakeOnePage([
            'id'          => 1352838,
            'status'      => 2,
            'total_price' => 900,
            'inserted_at' => '2026-08-19T06:00:00',
            'updated_at'  => '2026-08-19T06:00:00',
            'tags'        => [
                ['id' => 1, 'name' => 'UPSELL TSD - Ear Relief Balm'],
            ],
            'items' => [
                ['variation_info' => ['name' => 'AudiCure', 'retail_price' => 500], 'quantity' => 1, 'assigning_seller' => ['name' => 'Francis Gonzales']],
                ['variation_info' => ['name' => 'Ear Relief Balm', 'retail_price' => 400], 'quantity' => 1, 'assigning_seller' => ['name' => 'SH Kathleen Peji Santilleses']],
            ],
        ]);

        Artisan::call('pancake:sync-today', ['--date' => '2026-08-19']);

        $order = Order::where('pancake_order_id', '1352838')->first();

        $this->assertNotNull($order);
        $this->assertTrue($order->is_upsell);
        $this->assertSame('Kathleen', $order->tsa_name);
    }
}
