<?php

namespace Tests\Unit;

use App\Models\Order;
use App\Models\Product;
use App\Support\ProductPerformance;
use Illuminate\Support\Collection;
use PHPUnit\Framework\TestCase;

/**
 * Originally added 2026-08-24 for a narrower fix (a Canceled order was only
 * spared from exclusion when it carried a genuine pre-cancellation upsell).
 * Superseded 2026-10-06 (explicit request: "like for example there's
 * confirmation w/ upsell but cancelled status. it will still counts") —
 * Canceled (status_code 6) is no longer excluded from tally()/
 * ordersForColumn()/countedOrdersFor() at all; a Cancelled order counts
 * under whatever disposition it actually carries, same as any other order,
 * on Leads Report, TSA Performance (aggregate + individual), and Dashboard.
 * Deleted (status_code 7) is a different case — the order no longer exists
 * in Pancake at all — and stays excluded everywhere.
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

    /** A Canceled order that is NOT a real upsell must still count under its
     *  own disposition, same as any other order (2026-10-06 reversal,
     *  explicit request: "like for example there's confirmation w/ upsell
     *  but cancelled status. it will still counts" — Cancelled is no longer
     *  excluded from Leads Report/TSA Performance/Dashboard at all; only
     *  Deleted (7) still is, since that order no longer exists in Pancake). */
    public function test_a_canceled_non_upsell_order_still_counts_under_its_disposition(): void
    {
        $staleCanceled = $this->order([
            'status_code' => 6,
            'disposition' => 'confirmed via call',
        ]);

        $row = ProductPerformance::tally(collect([$staleCanceled]));

        $this->assertSame(1, $row['total']);
        $this->assertSame(0, $row['upsell_confirmation']);
        $this->assertSame(1, $row['confirmed_via_call']);
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

    private function product(array $attributes): Product
    {
        $product = new Product();
        $product->forceFill(array_merge([
            'pancake_product_ids' => null,
        ], $attributes));
        return $product;
    }

    /**
     * Explicit follow-up request (2026-09-03: "when there's separate upsell
     * ... this 1000 will be in kathleen's upsell") — confirmed live, order
     * #1363274 (Kathleen Santilleses): a SEPARATE PARCEL-tagged upsell order
     * whose own line item is the upsold add-on itself ("Turmeric Soap",
     * shipped as its own sibling Pancake order), with no catalog entry/
     * product-ID mapping of its own. Before this fix, matchingOrders()'s
     * ID-priority check (comparing the order's real product ID against
     * GINSENG SERUM's) always returned false for every product regardless of
     * which one was being checked, since Turmeric Soap's ID matches nothing
     * — silently dropping this real upsell from every Per-Product Hourly
     * Breakdown column while it still correctly counted toward the TSA's
     * overall Dashboard upsell total (isBroadRealUpsell()/realUpsellAmount()
     * never depend on product-ID matching at all). A SEPARATE PARCEL order
     * now skips ID-matching and falls through to its "TSD UPSELL - GINSENG
     * SERUM" tag instead, correctly attributing it to Ginseng Serum — the
     * base product this upsell was actually made against.
     */
    public function test_a_separate_parcel_upsell_order_matches_via_its_tags_named_base_product(): void
    {
        $ginsengSerum = $this->product([
            'display_name'        => 'GINSENG SERUM',
            'match_keyword'       => 'GINSENG',
            'team'                => 'SH Naturals',
            // A real Pancake product ID that belongs to Ginseng Serum
            // itself — deliberately does NOT match the order's own product
            // ID below, same as in production.
            'pancake_product_ids' => ['772458fd-ebd7-492a-96f4-fdc1865d9db4'],
        ]);

        $separateParcelUpsell = $this->order([
            'team'                => 'SH Naturals',
            'product'             => 'TURMERIC SOAP',
            'base_product'        => 'TURMERIC SOAP',
            'raw_tags'            => ['KATH', 'TSD UPSELL - GINSENG SERUM', 'SEPARATE PARCEL'],
            'is_upsell'           => true,
            // Turmeric Soap's own real ID — genuinely different from Ginseng
            // Serum's, and not mapped to any catalog product at all.
            'pancake_product_ids' => ['7d00f666-adb2-4b31-8b23-d023af886bae'],
        ]);

        $matching = ProductPerformance::matchingOrders($ginsengSerum, collect([$separateParcelUpsell]), new Collection([$ginsengSerum]));

        $this->assertCount(1, $matching, 'A SEPARATE PARCEL upsell order must still match its tag-named base product even when its own product ID belongs to a different, unmapped item');
    }

    /** Same order, but WITHOUT the SEPARATE PARCEL tag — the ID-priority path
     *  must still win here (this is the normal, correct behavior for every
     *  other upsell order whose real item genuinely has its own mapped ID) —
     *  proves the fix is scoped to SEPARATE PARCEL only, not a blanket
     *  "ignore IDs whenever they don't match" change. */
    public function test_a_non_separate_parcel_order_with_an_unmapped_product_id_does_not_fall_back_to_tag_matching(): void
    {
        $ginsengSerum = $this->product([
            'display_name'        => 'GINSENG SERUM',
            'match_keyword'       => 'GINSENG',
            'team'                => 'SH Naturals',
            'pancake_product_ids' => ['772458fd-ebd7-492a-96f4-fdc1865d9db4'],
        ]);

        $unrelatedOrder = $this->order([
            'team'                => 'SH Naturals',
            'product'             => 'TURMERIC SOAP',
            'base_product'        => 'TURMERIC SOAP',
            'raw_tags'            => ['KATH', 'TSD UPSELL - GINSENG SERUM'], // no SEPARATE PARCEL tag
            'is_upsell'           => true,
            'pancake_product_ids' => ['7d00f666-adb2-4b31-8b23-d023af886bae'],
        ]);

        $matching = ProductPerformance::matchingOrders($ginsengSerum, collect([$unrelatedOrder]), new Collection([$ginsengSerum]));

        $this->assertCount(0, $matching);
    }
}
