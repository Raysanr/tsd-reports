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
 * were all named explicitly. Same "reject before counting anything" +
 * "check every hand-rolled path, not just the shared one" treatment
 * excluded_upsell_seller already got (see ExcludedUpsellSellerReportsTest,
 * this file's own template) — TsaPerformanceController::buildRow() and
 * LeadsReportController's Total-cell drilldown branch each keep their own
 * separate reject() that would otherwise silently drift out of sync with
 * ProductPerformance::tally()/ordersForColumn().
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

    public function test_product_performance_tally_does_not_count_it_at_all(): void
    {
        $order = $this->duplicateOrder();

        $row = ProductPerformance::tally(collect([$order]));

        $this->assertSame(0, $row['total'], 'not counted as a New Lead either');
        $this->assertSame(0, $row['upsell_confirmation']);
        $this->assertSame(0.0, $row['upsell_sales']);
    }

    public function test_leads_report_grand_total_excludes_it_entirely(): void
    {
        $this->duplicateOrder();

        $today = now()->toDateString();
        $response = $this->get(route('leads-report', [
            'team' => 'sh-naturals', 'range' => 'dates', 'date_from' => $today, 'date_to' => $today,
        ]));

        $response->assertOk();
        $grandTotal = $response->viewData('grandTotal');

        $this->assertSame(0, $grandTotal['total'], 'no longer shows up as a New Lead');
        $this->assertSame(0, $grandTotal['upsell_confirmation']);
        $this->assertSame(0.0, $grandTotal['upsell_sales']);
    }

    public function test_tsa_performance_unassigned_row_excludes_it_entirely(): void
    {
        // A second, ordinary unclaimed order keeps the "unassigned" row itself
        // present so this proves the duplicate specifically contributed
        // nothing, rather than the row just being absent for an unrelated
        // reason.
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
        $this->assertSame(1, $unassigned['total'], 'only the ordinary unclaimed order counts');
        $this->assertSame(0, $unassigned['upsell_confirmation']);
    }

    public function test_leads_report_total_cell_drilldown_does_not_list_it_either(): void
    {
        $product = Product::create(['display_name' => 'Sinuxyl', 'match_keyword' => 'Sinuxyl', 'team' => 'SH Naturals', 'sort_order' => 0]);
        $this->duplicateOrder(['product' => 'Sinuxyl']);

        $today = now()->toDateString();
        $response = $this->getJson(route('leads-report.drilldown', [
            'product' => $product->id, 'date_from' => $today, 'date_to' => $today,
        ]));

        $response->assertOk();
        $this->assertSame([], $response->json());
    }
}
