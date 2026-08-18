<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\Product;
use App\Models\TsaShift;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Confirmed in production (2026-08-18, order #1351808): TO-01 and TO-02 are
 * two separate real Pancake catalog products, but neither had a real
 * pancake_product_ids mapping (see the seed_pancake_product_ids_for_to_01_
 * to_02 migration), so ProductPerformance::matchingOrders() fell back to
 * text-matching for both. TO-01 sells in price-tier variations, one of which
 * is literally labeled "1 TO-01 + 1 TO-02" (a bundle/promo tier — still ONE
 * catalog line item under TO-01's own product_id, not a second real TO-02
 * line item). The bundle_description text fallback saw "TO-02" inside that
 * label and wrongly counted the order toward TO-02's row too, inflating its
 * Leads Report total to roughly double Pancake's own "Products: TO-02"
 * filter count for the same day.
 *
 * Once both products carry their real pancake_product_ids, ID matching is
 * authoritative and skips every text heuristic for any order whose own
 * pancake_product_ids is already populated (see matchingOrders()'s own doc
 * comment) — this is what actually fixes it, not a text-matching tweak.
 */
class LeadsReportProductIdMatchTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->create());
    }

    public function test_id_matching_stops_a_bundle_labels_text_from_double_counting_a_sibling_product(): void
    {
        Product::create([
            'display_name'        => 'TO-01',
            'pancake_product_ids' => ['6fd93f83-855a-444c-81df-060cd4eed35b'],
            'team'                => 'Eyecare Team',
            'sort_order'          => 90,
        ]);
        Product::create([
            'display_name'        => 'TO-02',
            'pancake_product_ids' => ['dbfad3a8-5591-47c7-a62a-a21e5866675c'],
            'team'                => 'Eyecare Team',
            'sort_order'          => 91,
        ]);

        $shift = TsaShift::where('team', 'Eyecare Team')->first();

        // Mirrors order #1351808: one line item, product_id = TO-01's own real
        // UUID, variation label "1 TO-01 + 1 TO-02" — no second line item, no
        // real TO-02 product_id anywhere on the order.
        Order::create([
            'pancake_order_id'    => 'to-bundle-1',
            'team'                => 'Eyecare Team',
            'tsa_name'            => $shift->tsa_key,
            'disposition'         => 'CONFIRMED VIA CALL',
            'product'             => 'TO-01',
            'bundle_description'  => '1 TO-01 + 1 TO-02',
            'pancake_product_ids' => ['6fd93f83-855a-444c-81df-060cd4eed35b'],
            'raw_tags'            => [strtoupper($shift->tsa_key), 'CONFIRMED VIA CALL'],
            'is_upsell'           => false,
            'status_code'         => 1,
            'pancake_created_at'  => now(),
            'synced_at'           => now(),
        ]);

        $today = now()->toDateString();
        $response = $this->get(route('leads-report', [
            'team' => 'eyecare', 'range' => 'dates', 'date_from' => $today, 'date_to' => $today,
        ]));

        $response->assertOk();
        $response->assertViewHas('productTables', function ($tables) {
            $to01 = $tables->firstWhere(fn($t) => $t['product']->display_name === 'TO-01');
            $to02 = $tables->firstWhere(fn($t) => $t['product']->display_name === 'TO-02');

            return $to01['total']['total'] === 1 && $to02['total']['total'] === 0;
        });
    }

    public function test_an_order_still_missing_its_own_product_ids_falls_back_to_the_old_text_match(): void
    {
        // Same product setup as above, but the ORDER itself predates the
        // pancake_product_ids backfill (or hasn't been re-synced yet) — the
        // known, documented fallback window, not a regression: matchingOrders()
        // only trusts ID matching once BOTH sides have real IDs captured.
        Product::create([
            'display_name'        => 'TO-01',
            'pancake_product_ids' => ['6fd93f83-855a-444c-81df-060cd4eed35b'],
            'team'                => 'Eyecare Team',
            'sort_order'          => 90,
        ]);
        Product::create([
            'display_name'        => 'TO-02',
            'pancake_product_ids' => ['dbfad3a8-5591-47c7-a62a-a21e5866675c'],
            'team'                => 'Eyecare Team',
            'sort_order'          => 91,
        ]);

        $shift = TsaShift::where('team', 'Eyecare Team')->first();

        Order::create([
            'pancake_order_id'   => 'to-bundle-no-ids-1',
            'team'               => 'Eyecare Team',
            'tsa_name'           => $shift->tsa_key,
            'disposition'        => 'CONFIRMED VIA CALL',
            'product'            => 'TO-01',
            'bundle_description' => '1 TO-01 + 1 TO-02',
            'raw_tags'           => [strtoupper($shift->tsa_key), 'CONFIRMED VIA CALL'],
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
            $to01 = $tables->firstWhere(fn($t) => $t['product']->display_name === 'TO-01');
            $to02 = $tables->firstWhere(fn($t) => $t['product']->display_name === 'TO-02');

            return $to01['total']['total'] === 1 && $to02['total']['total'] === 1;
        });
    }
}
