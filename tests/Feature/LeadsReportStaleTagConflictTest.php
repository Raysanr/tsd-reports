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
}
