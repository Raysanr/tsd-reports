<?php

namespace Tests\Unit;

use App\Models\Order;
use App\Support\ProductPerformance;
use PHPUnit\Framework\TestCase;

/**
 * Explicit request (2026-08-24): Marisol showed 11 "Upsell w/ Confirmation"
 * on the TSA Performance page but 12 on the Dashboard Leaderboard for the
 * same day — root-caused to tally()'s own DELETED_STATUSES rejection
 * dropping a Canceled (status_code 6) order before ever checking whether it
 * was a genuine upsell. is_upsell_on_voided_order (commit 78c5094) exists
 * specifically so a real upsell that happened before an order was later
 * canceled still counts — this blanket rejection was silently undoing that
 * fix for every page going through tally(), even though the Dashboard's own
 * Leaderboard (which never applied this exclusion) already counted it
 * correctly.
 */
class ProductPerformanceCanceledUpsellTest extends TestCase
{
    private function order(array $attributes): Order
    {
        $order = new Order();
        $order->forceFill(array_merge([
            'status_code'               => 3,
            'excluded_upsell_seller'    => false,
            'is_duplicated_by_logistics' => false,
            'is_upsell'                 => false,
            'is_returned_upsell'        => false,
            'is_upsell_on_voided_order' => false,
            'is_cancelled_upsell'       => false,
            'raw_tags'                  => [],
            'disposition'               => '',
            'amount'                    => 0,
        ], $attributes));
        return $order;
    }

    public function test_a_canceled_orders_genuine_upsell_still_counts(): void
    {
        $canceledUpsell = $this->order([
            'status_code'               => 6,
            'is_upsell_on_voided_order' => true,
            'amount'                    => 500,
        ]);

        $row = ProductPerformance::tally(collect([$canceledUpsell]));

        $this->assertSame(1, $row['total']);
        $this->assertSame(1, $row['upsell_confirmation']);
    }

    /** Same case, but relying purely on the live tag re-check (the OTHER
     *  half of isBroadRealUpsell()'s OR) rather than a pre-set column — the
     *  fix must hold even when nothing wrote is_upsell_on_voided_order. */
    public function test_a_canceled_order_with_only_a_live_upsell_tag_still_counts(): void
    {
        $canceledUpsell = $this->order([
            'status_code' => 6,
            'raw_tags'    => ['UPSELL TSD (Ear Relief Balm)'],
        ]);

        $row = ProductPerformance::tally(collect([$canceledUpsell]));

        $this->assertSame(1, $row['total']);
        $this->assertSame(1, $row['upsell_confirmation']);
    }

    /** A Canceled order that is NOT a real upsell must still be dropped
     *  entirely (the original anti-inflation behavior this exclusion was
     *  built for) — the fix only carves out genuine upsells, nothing else. */
    public function test_a_canceled_non_upsell_order_is_still_excluded(): void
    {
        $staleCanceled = $this->order([
            'status_code' => 6,
            'disposition' => 'confirmed via call',
        ]);

        $row = ProductPerformance::tally(collect([$staleCanceled]));

        $this->assertSame(0, $row['total']);
        $this->assertSame(0, $row['upsell_confirmation']);
        $this->assertSame(0, $row['confirmed_via_call']);
    }

    /** Deleted (7) — the order no longer exists in Pancake at all — must
     *  always be dropped regardless of tags, unlike Canceled (6). */
    public function test_a_deleted_order_is_excluded_even_with_a_genuine_upsell_tag(): void
    {
        $deletedUpsell = $this->order([
            'status_code'               => 7,
            'is_upsell_on_voided_order' => true,
        ]);

        $row = ProductPerformance::tally(collect([$deletedUpsell]));

        $this->assertSame(0, $row['total']);
        $this->assertSame(0, $row['upsell_confirmation']);
    }

    public function test_ordersforcolumn_matches_the_same_carve_out(): void
    {
        $canceledUpsell = $this->order([
            'status_code'               => 6,
            'is_upsell_on_voided_order' => true,
        ]);

        $matched = ProductPerformance::ordersForColumn(collect([$canceledUpsell]), 'upsell_confirmation');

        $this->assertCount(1, $matched);
    }
}
