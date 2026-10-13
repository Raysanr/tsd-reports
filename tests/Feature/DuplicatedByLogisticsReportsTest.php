<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use App\Support\ProductPerformance;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Explicit request (2026-08-22): a warehouse/logistics duplicate of an
 * already-real order (Pancake staff flag these via a "DUPLICATED BY
 * LOGISTICS" note — see SyncTodayOrdersDuplicatedByLogisticsTest) must not
 * count as a lead anywhere — Leads Report, TSA Performance, and Dashboard
 * were all named explicitly.
 *
 * REVERSED 2026-10-13 (explicit request, same session as the AJ Dele Cruz
 * excluded-seller addition): "when there's now duplicated by logistics
 * note make it included to the data but AJ and RALPH assignee is still as
 * is" — root-caused live via screenshot: 2 real, tagged upsell orders for
 * Marsha Arnecilla (#1375013/#1375008) were invisible on her TSA
 * Performance/Expected Income cards specifically because of this flag, not
 * their Cancelled status. ProductPerformance::tally()/countedOrdersFor()/
 * ordersForColumn(), TsaPerformanceController::buildRow(), and
 * LeadsReportController's Total-cell drilldown branch no longer reject an
 * order for this reason — this file now asserts a duplicated-by-logistics
 * order counts exactly like any other real order. The Order.
 * is_duplicated_by_logistics column/flag itself is UNCHANGED (still set by
 * SyncTodayOrders the same way, still visible for history/reference) —
 * only the exclusion at read time is gone. excluded_upsell_seller is a
 * SEPARATE, UNCHANGED exclusion (see ExcludedUpsellSellerReportsTest) —
 * AJ Dele Cruz/Ralph Cruz still never count.
 */
class DuplicatedByLogisticsReportsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->create());
    }

    private function duplicateOrder(array $overrides = []): Order
    {
        return Order::create(array_merge([
            'pancake_order_id'           => 'duplicate-1',
            'team'                       => 'SH Naturals',
            'tsa_name'                   => null,
            'product'                    => 'Sinuxyl',
            'raw_tags'                   => ['Call in Progress (Sinuxyl Inhaler)', 'UPSELL TSD - Sinuxyl Inhaler'],
            'is_upsell'                  => false,
            'is_cancelled_upsell'        => false,
            'is_returned_upsell'         => false,
            'is_restocking_upsell'       => false,
            'is_duplicated_by_logistics' => true,
            'amount'                     => 1300.0,
            'status_code'                => 1,
            'pancake_created_at'         => now(),
            'synced_at'                  => now(),
        ], $overrides));
    }

    public function test_product_performance_tally_still_counts_it(): void
    {
        $order = $this->duplicateOrder();

        $row = ProductPerformance::tally(collect([$order]));

        $this->assertSame(1, $row['total'], 'still counts as a New Lead');
        // raw_tags carries a genuine "UPSELL TSD - ..." tag and
        // is_cancelled_upsell is false, so Order::isBroadRealUpsell()'s own
        // hasUpsellTag() fallback recognizes it as a real upsell — same as
        // it always did; this assertion is about the duplicated-by-
        // logistics reversal not accidentally changing THIS unrelated
        // upsell-detection behavior, not about the reversal itself.
        $this->assertSame(1, $row['upsell_confirmation']);
        $this->assertSame(1300.0, $row['upsell_sales']);
    }

    public function test_leads_report_grand_total_includes_it(): void
    {
        $this->duplicateOrder();

        $today = now()->toDateString();
        $response = $this->get(route('leads-report', [
            'team' => 'sh-naturals', 'range' => 'dates', 'date_from' => $today, 'date_to' => $today,
        ]));

        $response->assertOk();
        $grandTotal = $response->viewData('grandTotal');

        $this->assertSame(1, $grandTotal['total'], 'now counts as a New Lead');
    }

    public function test_tsa_performance_unassigned_row_includes_it(): void
    {
        // A second, ordinary unclaimed order alongside it — proves the
        // duplicate now contributes ON TOP of the ordinary one, not that
        // the row is merely present for an unrelated reason.
        Order::create([
            'pancake_order_id' => 'ordinary-unclaimed-1', 'team' => 'SH Naturals', 'tsa_name' => null,
            'product' => 'Sinuxyl', 'disposition' => 'NOT ANSWERING', 'is_upsell' => false,
            'status_code' => 1, 'pancake_created_at' => now(), 'synced_at' => now(),
        ]);
        $this->duplicateOrder();

        $today = now()->toDateString();
        $response = $this->get(route('tsa-performance', ['team' => 'sh-naturals', 'date_from' => $today, 'date_to' => $today]));

        $response->assertOk();
        $tsaRows = $response->viewData('tsaRows');
        $unassigned = collect($tsaRows)->firstWhere('tsa_key', 'unassigned');

        $this->assertNotNull($unassigned);
        $this->assertSame(2, $unassigned['total'], 'both the ordinary unclaimed order AND the duplicated-by-logistics order count');
    }

    public function test_leads_report_total_cell_drilldown_lists_it(): void
    {
        $product = Product::create(['display_name' => 'Sinuxyl', 'match_keyword' => 'Sinuxyl', 'team' => 'SH Naturals', 'sort_order' => 0]);
        $this->duplicateOrder(['product' => 'Sinuxyl']);

        $today = now()->toDateString();
        $response = $this->getJson(route('leads-report.drilldown', [
            'product' => $product->id, 'date_from' => $today, 'date_to' => $today,
        ]));

        $response->assertOk();
        $ids = collect($response->json())->pluck('id');
        $this->assertTrue($ids->contains('duplicate-1'), 'the duplicated-by-logistics order now appears in the drilldown');
    }

    /** The duplicated-by-logistics flag staying on the model, unused for
     *  exclusion, must never be confused with excluded_upsell_seller — a
     *  SEPARATE, still-active exclusion (AJ Dele Cruz/Ralph Cruz). An
     *  order carrying BOTH flags must still be excluded, for the
     *  seller reason alone. */
    public function test_an_excluded_seller_order_is_still_excluded_even_if_also_flagged_duplicated(): void
    {
        $order = $this->duplicateOrder([
            'pancake_order_id' => 'duplicate-and-excluded-seller-1',
            'excluded_upsell_seller' => true,
        ]);

        $row = ProductPerformance::tally(collect([$order]));

        $this->assertSame(0, $row['total'], 'excluded_upsell_seller still drops it entirely, independent of the duplicated-by-logistics reversal');
    }
}
