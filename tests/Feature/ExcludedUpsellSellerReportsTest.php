<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\TsaShift;
use App\Models\User;
use App\Support\ProductPerformance;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Root-caused 2026-08-21 (user question: "so even in leads report data like
 * counts will be excluded that ralph?"): SyncTodayOrders forcing is_upsell
 * false for an excluded seller account (see
 * SyncTodayOrdersExcludedSellerAccountTest) was NOT enough on its own —
 * ProductPerformance::tally()/ordersForColumn() and
 * TsaPerformanceController's per-TSA breakdown all independently fall back
 * to a bare Order::hasUpsellTag($raw_tags) tag-text scan for a separate,
 * legitimate edge case (a genuinely-tagged upsell sitting in Restocking
 * status, whose is_upsell is ALSO forced false) — that fallback doesn't
 * know about excluded sellers, so it silently kept counting the order as
 * an upsell in Leads Report / TSA Performance / Analytics even after the
 * Dashboard card and TSA Leaderboard were already fixed.
 *
 * Explicit follow-up request (same day): an excluded-seller order should
 * ALSO drop out of the plain lead/order count itself ("New Leads"), not
 * just the upsell classification — the opposite of the reasoning
 * originally applied here. Same mechanism the codebase already uses to
 * drop Cancelled/Deleted orders from every count (Order::DELETED_STATUSES,
 * rejected up front in tally()/ordersForColumn()/buildRow()), extended to
 * also reject excluded_upsell_seller orders in those same three places.
 */
class ExcludedUpsellSellerReportsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->create());
    }

    private function excludedSellerOrder(array $overrides = []): Order
    {
        return Order::create(array_merge([
            'pancake_order_id'       => 'excluded-1',
            'team'                   => 'SH Naturals',
            'tsa_name'               => null,
            'product'                => 'Sinuxyl',
            'raw_tags'               => ['Call in Progress (Sinuxyl Inhaler)', 'UPSELL TSD - Sinuxyl Inhaler'],
            'is_upsell'              => false,
            'is_cancelled_upsell'    => false,
            'is_returned_upsell'     => false,
            'is_restocking_upsell'   => false,
            'excluded_upsell_seller' => true,
            'amount'                 => 1300.0,
            'status_code'            => 1,
            'pancake_created_at'     => now(),
            'synced_at'              => now(),
        ], $overrides));
    }

    public function test_product_performance_tally_does_not_count_it_at_all(): void
    {
        $order = $this->excludedSellerOrder();

        $row = ProductPerformance::tally(collect([$order]));

        $this->assertSame(0, $row['total'], 'not counted as a New Lead either');
        $this->assertSame(0, $row['upsell_confirmation']);
        $this->assertSame(0.0, $row['upsell_sales']);
    }

    public function test_leads_report_grand_total_excludes_it_entirely(): void
    {
        $this->excludedSellerOrder();

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
        // present (it only renders when the bucket has real orders — see
        // TsaPerformanceUnassignedRowTest) so this proves the excluded order
        // specifically contributed nothing, rather than the row just being
        // absent for an unrelated reason.
        Order::create([
            'pancake_order_id' => 'ordinary-unclaimed-1', 'team' => 'SH Naturals', 'tsa_name' => null,
            'product' => 'Sinuxyl', 'disposition' => 'NOT ANSWERING', 'is_upsell' => false,
            'status_code' => 1, 'pancake_created_at' => now(), 'synced_at' => now(),
        ]);
        // tsa_name null lands this order in the "unassigned" bucket, same as any
        // other order nobody claimed — same shape production order #1352836 had.
        $this->excludedSellerOrder();

        $today = now()->toDateString();
        $response = $this->get(route('tsa-performance', ['team' => 'sh-naturals', 'date_from' => $today, 'date_to' => $today]));

        $response->assertOk();
        $tsaRows = $response->viewData('tsaRows');
        $unassigned = collect($tsaRows)->firstWhere('tsa_key', 'unassigned');

        $this->assertNotNull($unassigned);
        $this->assertSame(1, $unassigned['total'], 'only the ordinary unclaimed order counts');
        $this->assertSame(0, $unassigned['upsell_confirmation']);
    }

    public function test_a_genuinely_tagged_restocking_order_from_a_real_tsa_still_counts_as_upsell(): void
    {
        // Guards against an overly-broad fix: this is the exact edge case
        // Order::isBroadRealUpsell()'s tag-fallback branch exists for — is_upsell
        // is forced false (Restocking is a VOID_STATUS) but it's a genuine upsell
        // from a real TSA (excluded_upsell_seller stays false), so it must still
        // count.
        $shift = TsaShift::where('team', 'SH Naturals')->first();
        $order = $this->excludedSellerOrder([
            'pancake_order_id'       => 'real-restocking-1',
            'tsa_name'               => $shift->tsa_key,
            'status_code'            => 11,
            'excluded_upsell_seller' => false,
        ]);

        $row = ProductPerformance::tally(collect([$order]));

        $this->assertSame(1, $row['upsell_confirmation']);
    }
}
