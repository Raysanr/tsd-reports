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
 * (see Order::DELETED_STATUSES's docblock).
 *
 * The plain (no-column) Total drilldown originally left those orders in on
 * purpose, as a diagnostic — see this docblock's own history. Reversed
 * 2026-08-18 (commit 6081df2, "Exclude deleted/canceled orders from the
 * Leads Report drilldown too"; see drilldown()'s own docblock): a visibly
 * excluded-looking row still read as a counting bug rather than a
 * diagnostic, so the Total drilldown now applies the same DELETED_STATUSES
 * exclusion the column-scoped path already did — both paths exclude now.
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
     * Reversed 2026-08-18 (see commit 6081df2, "Exclude deleted/canceled
     * orders from the Leads Report drilldown too", and drilldown()'s own
     * docblock) — a deleted/canceled order used to still be listed here as a
     * diagnostic aid, but that read as a counting bug rather than a
     * diagnostic, so the Total-cell drilldown now applies the same
     * DELETED_STATUSES exclusion every other column already did. Renamed and
     * rewritten 2026-08-29 to assert the current, intentional behavior
     * instead of the pre-6081df2 one this test was never updated for.
     */
    public function test_excludes_orders_with_a_deleted_or_cancelled_local_status(): void
    {
        $product = Product::create(['display_name' => 'SINUXYL', 'match_keyword' => 'SINUXYL', 'team' => 'SH Naturals', 'sort_order' => 0]);

        $this->order('dd-4', 'SH Naturals', '2026-07-24 09:00:00', ['SINUXYL'], statusCode: 9);   // active
        $this->order('dd-5', 'SH Naturals', '2026-07-24 10:00:00', ['SINUXYL'], statusCode: 7);   // "Deleted recently" locally

        $response = $this->getJson(route('leads-report.drilldown', [
            'product' => $product->id, 'date_from' => '2026-07-24', 'date_to' => '2026-07-24',
        ]));

        $response->assertOk();
        $ids = collect($response->json())->pluck('id');
        $this->assertCount(1, $ids);
        $this->assertFalse($ids->contains('dd-5'));
    }

    /** Once a 'column' is passed, this narrows to just the orders that specific
     *  disposition column counted — not every order matching the product. */
    public function test_a_column_param_narrows_to_that_disposition_only(): void
    {
        $product = Product::create(['display_name' => 'SINUXYL', 'match_keyword' => 'SINUXYL', 'team' => 'SH Naturals', 'sort_order' => 0]);

        Order::create(['pancake_order_id' => 'dd-6', 'team' => 'SH Naturals', 'raw_tags' => ['SINUXYL'], 'disposition' => 'Confirmed via Call', 'status_code' => 9, 'pancake_created_at' => '2026-07-24 09:00:00', 'pancake_inserted_at' => '2026-07-24 09:00:00', 'synced_at' => now()]);
        Order::create(['pancake_order_id' => 'dd-7', 'team' => 'SH Naturals', 'raw_tags' => ['SINUXYL'], 'disposition' => 'Call Back', 'status_code' => 9, 'pancake_created_at' => '2026-07-24 09:00:00', 'pancake_inserted_at' => '2026-07-24 09:00:00', 'synced_at' => now()]);

        $response = $this->getJson(route('leads-report.drilldown', [
            'product' => $product->id, 'date_from' => '2026-07-24', 'date_to' => '2026-07-24', 'column' => 'confirmed_via_call',
        ]));

        $response->assertOk();
        $ids = collect($response->json())->pluck('id');
        $this->assertSame(['dd-6'], $ids->all());
    }

    /** Column-scoped request excludes DELETED_STATUSES — matching what
     *  ProductPerformance::tally() actually counted into that column's
     *  number. The plain (no-column) Total drilldown above now applies the
     *  same exclusion (see that test's own comment for the 2026-08-18
     *  reversal) — this was originally the one path that did, before that
     *  change; the two are no longer a deliberate contrast. */
    public function test_a_column_param_excludes_deleted_statuses(): void
    {
        $product = Product::create(['display_name' => 'SINUXYL', 'match_keyword' => 'SINUXYL', 'team' => 'SH Naturals', 'sort_order' => 0]);

        Order::create(['pancake_order_id' => 'dd-8', 'team' => 'SH Naturals', 'raw_tags' => ['SINUXYL'], 'disposition' => 'Confirmed via Call', 'status_code' => 9, 'pancake_created_at' => '2026-07-24 09:00:00', 'pancake_inserted_at' => '2026-07-24 09:00:00', 'synced_at' => now()]);
        Order::create(['pancake_order_id' => 'dd-9', 'team' => 'SH Naturals', 'raw_tags' => ['SINUXYL'], 'disposition' => 'Confirmed via Call', 'status_code' => 7, 'pancake_created_at' => '2026-07-24 10:00:00', 'pancake_inserted_at' => '2026-07-24 10:00:00', 'synced_at' => now()]);

        $response = $this->getJson(route('leads-report.drilldown', [
            'product' => $product->id, 'date_from' => '2026-07-24', 'date_to' => '2026-07-24', 'column' => 'confirmed_via_call',
        ]));

        $response->assertOk();
        $ids = collect($response->json())->pluck('id');
        $this->assertSame(['dd-8'], $ids->all());
    }

    public function test_excess_column_returns_orders_never_called(): void
    {
        $product = Product::create(['display_name' => 'SINUXYL', 'match_keyword' => 'SINUXYL', 'team' => 'SH Naturals', 'sort_order' => 0]);

        Order::create(['pancake_order_id' => 'dd-10', 'team' => 'SH Naturals', 'raw_tags' => ['SINUXYL'], 'disposition' => 'Confirmed via Call', 'status_code' => 9, 'pancake_created_at' => '2026-07-24 09:00:00', 'pancake_inserted_at' => '2026-07-24 09:00:00', 'synced_at' => now()]);
        Order::create(['pancake_order_id' => 'dd-11', 'team' => 'SH Naturals', 'raw_tags' => ['SINUXYL'], 'disposition' => null, 'status_code' => 9, 'pancake_created_at' => '2026-07-24 10:00:00', 'pancake_inserted_at' => '2026-07-24 10:00:00', 'synced_at' => now()]);

        $response = $this->getJson(route('leads-report.drilldown', [
            'product' => $product->id, 'date_from' => '2026-07-24', 'date_to' => '2026-07-24', 'column' => 'excess',
        ]));

        $response->assertOk();
        $ids = collect($response->json())->pluck('id');
        $this->assertSame(['dd-11'], $ids->all());
    }

    /** The ALL view's disposition columns (Catered Leads + all 13 metric
     *  columns) used to be plain, non-clickable numbers — only the Total
     *  Leads column had drilldown wired up. Confirms a disposition cell now
     *  renders as clickable too. */
    public function test_the_all_view_wires_drilldown_onto_disposition_columns_too(): void
    {
        $product = Product::create(['display_name' => 'SINUXYL', 'match_keyword' => 'SINUXYL', 'team' => 'SH Naturals', 'sort_order' => 0]);
        Order::create(['pancake_order_id' => 'dd-12', 'team' => 'SH Naturals', 'raw_tags' => ['SINUXYL'], 'disposition' => 'Confirmed via Call', 'status_code' => 9, 'pancake_created_at' => '2026-07-24 09:00:00', 'pancake_inserted_at' => '2026-07-24 09:00:00', 'synced_at' => now()]);

        $response = $this->get(route('leads-report', ['team' => 'all', 'range' => 'dates', 'date_from' => '2026-07-24', 'date_to' => '2026-07-24']));

        $response->assertOk();
        $response->assertSee('data-dd-column="catered"', false);
        $response->assertSee('data-dd-cell-product="' . $product->id . '"', false);
    }

    /** The per-team hourly page's TOTAL row (bottom, whole-range) is now
     *  clickable — the individual hourly rows above it deliberately stay
     *  plain (shift-cutoff backlog-lumping makes their displayed numbers not
     *  correspond to a simple date+hour query — see the view's own comment). */
    public function test_the_per_team_pages_product_total_row_is_clickable(): void
    {
        $product = Product::create(['display_name' => 'SINUXYL', 'match_keyword' => 'SINUXYL', 'team' => 'SH Naturals', 'sort_order' => 0]);
        $this->order('dd-13', 'SH Naturals', '2026-07-24 09:00:00', ['SINUXYL']);

        $response = $this->get(route('leads-report', ['team' => 'sh-naturals', 'range' => 'dates', 'date_from' => '2026-07-24', 'date_to' => '2026-07-24']));

        $response->assertOk();
        $response->assertSee('data-dd-cell-product="' . $product->id . '"', false);
        $response->assertSee('data-dd-endpoint="' . route('leads-report.drilldown') . '"', false);
    }
}
