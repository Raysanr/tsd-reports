<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\Setting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * extractTsaInfo() now checks the upsell item's assigning_seller (a real Pancake
 * account) BEFORE scanning tags — see that method's own doc comment. Tags remain
 * structurally vulnerable to an order carrying two different TSAs' name tags at
 * once (whichever tag Pancake lists first used to win, not necessarily the TSA
 * who actually closed the upsell); the account field can't have that ambiguity,
 * since Pancake records exactly one assigning_seller per item.
 */
class SyncTodayOrdersAccountBasedTsaAttributionTest extends TestCase
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

    public function test_assigning_seller_on_the_addon_item_wins_over_a_conflicting_tsa_name_tag(): void
    {
        Setting::set('pancake_api_key', 'test-key');
        Setting::set('shop_id', '30037101');

        // Tagged KATHLEEN, but the item the customer actually bought was closed by
        // Gemma's own Pancake account — the account is the ground truth.
        $this->fakeOnePage([
            'id'          => 9001,
            'status'      => 2,
            'total_price' => 1800,
            'inserted_at' => '2026-08-02T11:13:00',
            'updated_at'  => '2026-08-02T11:13:00',
            'tags'        => [
                ['id' => 1, 'name' => 'KATHLEEN'],
                ['id' => 2, 'name' => 'UPSELL TSD - Belo Set'],
            ],
            'items' => [
                ['variation_info' => ['name' => 'Ginseng Serum', 'retail_price' => 1000], 'quantity' => 1, 'assigning_seller' => ['name' => 'Francis Gonzales']],
                ['variation_info' => ['name' => 'Belo Set', 'retail_price' => 800], 'quantity' => 1, 'assigning_seller' => ['name' => 'Gemma Diaz']],
            ],
        ]);

        Artisan::call('pancake:sync-today', ['--date' => '2026-08-02']);

        $order = Order::where('pancake_order_id', '9001')->first();

        $this->assertNotNull($order);
        $this->assertSame('Gemma', $order->tsa_name);
        $this->assertTrue($order->is_upsell);
    }

    public function test_assigning_seller_resolves_tsa_when_no_name_tag_present_at_all(): void
    {
        Setting::set('pancake_api_key', 'test-key');
        Setting::set('shop_id', '30037101');

        $this->fakeOnePage([
            'id'          => 9002,
            'status'      => 2,
            'total_price' => 1200,
            'inserted_at' => '2026-08-02T11:13:00',
            'updated_at'  => '2026-08-02T11:13:00',
            'tags'        => [
                ['id' => 1, 'name' => 'UPSELL TSD - Ear Relief Balm'],
            ],
            'items' => [
                ['variation_info' => ['name' => 'AudiCure', 'retail_price' => 800], 'quantity' => 1, 'assigning_seller' => ['name' => 'Francis Gonzales']],
                ['variation_info' => ['name' => 'Ear Relief Balm', 'retail_price' => 400], 'quantity' => 1, 'assigning_seller' => ['name' => 'SH Kathleen Peji Santilleses']],
            ],
        ]);

        Artisan::call('pancake:sync-today', ['--date' => '2026-08-02']);

        $order = Order::where('pancake_order_id', '9002')->first();

        $this->assertNotNull($order);
        $this->assertSame('Kathleen', $order->tsa_name);
    }

    public function test_falls_back_to_tag_when_single_item_order_has_no_addon_to_check_seller_on(): void
    {
        Setting::set('pancake_api_key', 'test-key');
        Setting::set('shop_id', '30037101');

        // No second item at all — nothing for the account check to look at, same
        // as any ordinary (non-upsell) base lead. Must still resolve via tag.
        $this->fakeOnePage([
            'id'          => 9003,
            'status'      => 1,
            'total_price' => 1000,
            'inserted_at' => '2026-08-02T11:13:00',
            'updated_at'  => '2026-08-02T11:13:00',
            'tags'        => [
                ['id' => 1, 'name' => 'GEMMA'],
            ],
            'items' => [
                ['variation_info' => ['name' => 'Ginseng Serum', 'retail_price' => 1000], 'quantity' => 1, 'assigning_seller' => ['name' => 'Francis Gonzales']],
            ],
        ]);

        Artisan::call('pancake:sync-today', ['--date' => '2026-08-02']);

        $order = Order::where('pancake_order_id', '9003')->first();

        $this->assertNotNull($order);
        $this->assertSame('Gemma', $order->tsa_name);
    }
}
