<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\Product;
use App\Models\TsaShift;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Confirmed in production: an order's cart item is sometimes a DIFFERENT
 * Eyecare product than its tags say (mostly a Pterygium order still carrying
 * a leftover CLEARSIGHT tag from earlier in the conversation) — ~1-3 times a
 * week. Before this fix, ProductPerformance::buildRow's tag-first matching
 * counted that single order under BOTH products' per-product tables, inflating
 * each one's total. See ProductPerformance::buildRow's $teamProducts guard.
 */
class LeadsReportStaleTagConflictTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->create());
    }

    public function test_an_order_carrying_another_products_stale_tag_is_not_double_counted(): void
    {
        $shift = TsaShift::where('team', 'Eyecare Team')->first();

        // Cart item is literally Pterygium, but the CLEARSIGHT tag is still
        // attached — exactly the production pattern (order #1332043/#1332044).
        Order::create([
            'pancake_order_id'   => 'stale-tag-1',
            'team'               => 'Eyecare Team',
            'tsa_name'           => $shift->tsa_key,
            'disposition'        => 'CONFIRMED VIA CALL',
            'product'            => 'Pterygium',
            'raw_tags'           => [strtoupper($shift->tsa_key), 'CLEARSIGHT', 'CONFIRMED VIA CALL'],
            'is_upsell'          => false,
            'status_code'        => 1,
            'pancake_created_at' => now(),
            'synced_at'          => now(),
        ]);

        $today = now()->toDateString();
        $response = $this->get(route('leads-report', [
            'team' => 'eyecare', 'range' => 'dates', 'date_from' => $today, 'date_to' => $today,
        ]));

        $response->assertOk();
        $response->assertViewHas('productTables', function ($tables) {
            $clearSight = $tables->firstWhere(fn($t) => $t['product']->display_name === 'CLEARSIGHT');
            $pterygium  = $tables->firstWhere(fn($t) => $t['product']->display_name === 'PTERYGIUM');

            return $clearSight['total']['total'] === 0 && $pterygium['total']['total'] === 1;
        });
    }

    public function test_a_genuine_upsell_addon_still_counts_toward_its_base_product_via_tag(): void
    {
        // LUMICARE OIL isn't its own team product — it never matches any OTHER
        // product's keyword — so the conflict guard must not touch it.
        $shift = TsaShift::where('team', 'Eyecare Team')->first();

        Order::create([
            'pancake_order_id'   => 'real-upsell-1',
            'team'               => 'Eyecare Team',
            'tsa_name'           => $shift->tsa_key,
            'disposition'        => 'CONFIRMED VIA CALL',
            'product'            => 'LUMICARE OIL',
            'raw_tags'           => [strtoupper($shift->tsa_key), 'CLEARSIGHT', 'UPSELL TSD - CLEARSIGHT + LUMICARE OIL'],
            'is_upsell'          => true,
            'status_code'        => 1,
            'pancake_created_at' => now(),
            'synced_at'          => now(),
        ]);

        $today = now()->toDateString();
        $response = $this->get(route('leads-report', [
            'team' => 'eyecare', 'range' => 'dates', 'date_from' => $today, 'date_to' => $today,
        ]));

        $response->assertOk();
        $response->assertViewHas('productTables', function ($tables) {
            $clearSight = $tables->firstWhere(fn($t) => $t['product']->display_name === 'CLEARSIGHT');

            return $clearSight['total']['total'] === 1;
        });
    }

    /**
     * Confirmed in production (2026-07-21, order #1333005): a combo SKU's
     * `product` column only ever holds the catalog entry's generic name
     * ("GINSENG SERUM"), even when the item is really a bundle — Pancake's own
     * variation_info.display_id for that same item reads "1 Ginseng Serum + 5
     * Scar Cream". Before this fix, Scar Cream never counted this order at all,
     * even though its POS search (which does read the fuller description) showed
     * it. See ProductPerformance::buildRow()'s bundle_description fallback.
     */
    public function test_a_combo_orders_bundled_product_counts_via_bundle_description(): void
    {
        $shift = TsaShift::where('team', 'SH Naturals')->first();

        Order::create([
            'pancake_order_id'   => 'combo-1',
            'team'               => 'SH Naturals',
            'tsa_name'           => $shift->tsa_key,
            'disposition'        => 'CONFIRMED VIA CALL',
            'product'            => 'GINSENG SERUM',
            'bundle_description' => '1 Ginseng Serum + 5 Scar Cream',
            'raw_tags'           => [strtoupper($shift->tsa_key), 'CONFIRMED VIA CALL'],
            'is_upsell'          => false,
            'status_code'        => 1,
            'pancake_created_at' => now(),
            'synced_at'          => now(),
        ]);

        $today = now()->toDateString();
        $response = $this->get(route('leads-report', [
            'team' => 'sh-naturals', 'range' => 'dates', 'date_from' => $today, 'date_to' => $today,
        ]));

        $response->assertOk();
        $response->assertViewHas('productTables', function ($tables) {
            $ginseng   = $tables->firstWhere(fn($t) => $t['product']->display_name === 'GINSENG SERUM');
            $scarCream = $tables->firstWhere(fn($t) => $t['product']->display_name === 'SCAR CREAM');

            return $ginseng['total']['total'] === 1 && $scarCream['total']['total'] === 1;
        });
    }
}
