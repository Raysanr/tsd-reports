<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Explicit request: investigating why production's Leads Report showed more
 * SINUXYL leads than Pancake POS's own order search. LeadsReportController::
 * drilldown() lists the exact orders ProductPerformance::buildRow() counted
 * toward a product's total, so they can be checked order-by-order against
 * Pancake's live data — a known, previously-confirmed failure mode being an
 * order deleted/cancelled in Pancake whose local copy never got the memo
 * (see Order::DELETED_STATUSES's docblock). Deliberately does NOT exclude
 * those statuses here — showing every order that's actually being counted,
 * including one whose local status secretly disagrees with Pancake, is the
 * point.
 */
class LeadsReportDrilldownTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->create());
    }

    private function order(string $id, string $team, string $time, array $tags = [], ?string $product = null, int $statusCode = 9): void
    {
        Order::create([
            'pancake_order_id'    => $id,
            'team'                => $team,
            'raw_tags'            => $tags,
            'product'             => $product,
            'status_code'         => $statusCode,
            'pancake_created_at'  => $time,
            'pancake_inserted_at' => $time,
            'synced_at'           => now(),
        ]);
    }

    public function test_returns_the_orders_matching_the_products_tag(): void
    {
        $product = Product::create(['display_name' => 'SINUXYL', 'match_keyword' => 'SINUXYL', 'team' => 'SH Naturals', 'sort_order' => 0]);

        $this->order('dd-1', 'SH Naturals', '2026-07-24 09:00:00', ['SINUXYL']);
        $this->order('dd-2', 'SH Naturals', '2026-07-24 10:00:00', ['AUDICURE']); // different product — must not appear

        $response = $this->getJson(route('leads-report.drilldown', [
            'product' => $product->id, 'date_from' => '2026-07-24', 'date_to' => '2026-07-24',
        ]));

        $response->assertOk();
        $ids = collect($response->json())->pluck('id');
        $this->assertSame(['dd-1'], $ids->all());
    }

    public function test_includes_the_orders_local_status_label(): void
    {
        $product = Product::create(['display_name' => 'SINUXYL', 'match_keyword' => 'SINUXYL', 'team' => 'SH Naturals', 'sort_order' => 0]);

        $this->order('dd-3', 'SH Naturals', '2026-07-24 09:00:00', ['SINUXYL'], statusCode: 9);

        $response = $this->getJson(route('leads-report.drilldown', [
            'product' => $product->id, 'date_from' => '2026-07-24', 'date_to' => '2026-07-24',
        ]));

        $response->assertOk();
        $this->assertSame('Waiting for pick up', $response->json()[0]['status']);
    }

    /**
     * The whole point of this drilldown: an order Pancake has since cancelled
     * or deleted, whose local copy never got re-synced, must still show up
     * here (with whatever stale status it has) — that's the row worth
     * checking against Pancake directly, and hiding it would defeat the
     * diagnostic entirely.
     */
    public function test_does_not_exclude_orders_with_a_deleted_or_cancelled_local_status(): void
    {
        $product = Product::create(['display_name' => 'SINUXYL', 'match_keyword' => 'SINUXYL', 'team' => 'SH Naturals', 'sort_order' => 0]);

        $this->order('dd-4', 'SH Naturals', '2026-07-24 09:00:00', ['SINUXYL'], statusCode: 9);   // active
        $this->order('dd-5', 'SH Naturals', '2026-07-24 10:00:00', ['SINUXYL'], statusCode: 7);   // "Deleted recently" locally

        $response = $this->getJson(route('leads-report.drilldown', [
            'product' => $product->id, 'date_from' => '2026-07-24', 'date_to' => '2026-07-24',
        ]));

        $response->assertOk();
        $ids = collect($response->json())->pluck('id');
        $this->assertCount(2, $ids);
        $this->assertTrue($ids->contains('dd-5'));
    }
}
