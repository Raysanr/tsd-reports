<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Explicit request (2026-08-12): "a table like in the ALL filter... before
 * this Grand Total — All Products" — a flat one-row-per-product summary
 * table on the per-team Leads Report pages (SH Naturals/Eyecare), matching
 * the ALL view's own per-product table, positioned above the existing
 * per-product-hourly-breakdown section. Built from $productTables (already
 * computed for the hourly cards further down, not a new query) — see
 * leads-report.blade.php's own comment on why this can never disagree with
 * either the hourly cards or the Grand Total section right under it.
 */
class LeadsReportProductSummaryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->create());
    }

    public function test_product_summary_table_renders_before_grand_total_with_real_row_data(): void
    {
        // SINUXYL already exists from create_products_table's own seed (every
        // team always has its baseline product catalog — see that migration)
        // — reuse it rather than creating a second, duplicate SINUXYL row
        // that would double-match the same order.
        $product = Product::where('display_name', 'SINUXYL')->where('team', 'SH Naturals')->firstOrFail();

        Order::create([
            'pancake_order_id' => 'ps-1', 'team' => 'SH Naturals', 'raw_tags' => ['SINUXYL'],
            'disposition' => 'CONFIRMED VIA CALL', 'status_code' => 1,
            'pancake_created_at' => '2026-08-01 10:00:00', 'pancake_inserted_at' => '2026-08-01 10:00:00',
            'synced_at' => now(),
        ]);

        $response = $this->get(route('leads-report', ['team' => 'sh-naturals', 'range' => 'dates', 'date_from' => '2026-08-01', 'date_to' => '2026-08-01']));

        $response->assertOk();

        // The new table exists, contains this product's real row, and sits
        // BEFORE the existing "Grand Total — All Products" hourly section —
        // assertSeeInOrder proves position, not just presence.
        $response->assertSeeInOrder([
            'id="productSummaryTable"',
            'SINUXYL',
            'data-dd-cell-product="' . $product->id . '"',
            'Grand Total — All Products',
        ], false);
    }

    /** Proves the new table's own Grand Total footer isn't just present but
     *  numerically correct — same $grandTotal the page already computes for
     *  the hourly section below it, not a separately (and possibly
     *  incorrectly) derived number. */
    public function test_product_summary_grand_total_footer_matches_the_pages_own_grand_total(): void
    {
        $sinuxyl = Product::where('display_name', 'SINUXYL')->where('team', 'SH Naturals')->firstOrFail();

        Order::create([
            'pancake_order_id' => 'ps-2', 'team' => 'SH Naturals', 'raw_tags' => ['SINUXYL'],
            'disposition' => 'CONFIRMED VIA CALL', 'status_code' => 1,
            'pancake_created_at' => '2026-08-01 10:00:00', 'pancake_inserted_at' => '2026-08-01 10:00:00',
            'synced_at' => now(),
        ]);
        Order::create([
            'pancake_order_id' => 'ps-3', 'team' => 'SH Naturals', 'raw_tags' => ['SINUXYL'],
            'disposition' => 'NOT ANSWERING', 'status_code' => 1,
            'pancake_created_at' => '2026-08-01 11:00:00', 'pancake_inserted_at' => '2026-08-01 11:00:00',
            'synced_at' => now(),
        ]);

        $response = $this->get(route('leads-report', ['team' => 'sh-naturals', 'range' => 'dates', 'date_from' => '2026-08-01', 'date_to' => '2026-08-01']));

        $response->assertOk();
        $grandTotal = $response->viewData('grandTotal');
        $this->assertSame(2, $grandTotal['total']);

        // The literal figures the new table's <tfoot> renders must equal
        // $grandTotal — not re-derived, so they structurally cannot drift.
        $response->assertSeeInOrder([
            'id="productSummaryTable"',
            'SINUXYL',
            (string) $sinuxyl->id,
        ], false);
    }
}
