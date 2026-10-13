<?php

namespace Tests\Unit;

use App\Models\Order;
use App\Models\Product;
use App\Support\ProductPerformance;
use Illuminate\Support\Collection;
use PHPUnit\Framework\TestCase;

/**
 * Explicit reversal, 2026-10-10 (order #1378313, Kathreena Borja — Scar
 * Cream + Rose Soap, tagged "UPSELL TSD - ROSE SOAP"): "it should be the
 * rose soap has no data because it is upsell, it should fall to the scar
 * cream card." Previously the deliberate, documented design
 * (ProductPerformance::matchingOrders()'s own class-level comment, "every
 * upsell add-on order carries its real base product's tag too") was the
 * opposite — an upsold add-on got its own card/count via its tag, separate
 * from the base product it was upsold onto. Confirmed scope with the user:
 * this is a full reversal, everywhere matchingOrders() is used (Leads
 * Report, TSA Performance, Dashboard, Expected Income), not just one page.
 */
class ProductPerformanceUpsellAddonAttributionTest extends TestCase
{
    private function order(array $attributes): Order
    {
        $order = new Order();
        $order->forceFill(array_merge([
            'status_code'                => 3,
            'excluded_upsell_seller'     => false,
            'is_duplicated_by_logistics' => false,
            'is_upsell'                  => false,
            'is_returned_upsell'         => false,
            'is_upsell_on_voided_order'  => false,
            'is_cancelled_upsell'        => false,
            'raw_tags'                   => [],
            'disposition'                => '',
            'amount'                     => 0,
            'pancake_product_ids'        => null,
        ], $attributes));
        return $order;
    }

    private function product(array $attributes): Product
    {
        $product = new Product();
        $product->forceFill(array_merge([
            'pancake_product_ids' => null,
        ], $attributes));
        return $product;
    }

    public function test_an_upsold_addon_no_longer_matches_its_own_product_card(): void
    {
        $scarCream = $this->product([
            'display_name'  => 'Scar Cream',
            'match_keyword' => 'SCAR CREAM',
            'team'          => 'SH Naturals',
        ]);
        $roseSoap = $this->product([
            'display_name'  => 'Rose Soap',
            'match_keyword' => 'ROSE SOAP',
            'team'          => 'SH Naturals',
        ]);

        $order = $this->order([
            'team'         => 'SH Naturals',
            'product'      => 'Rose Soap',
            'base_product' => 'Scar Cream',
            'raw_tags'     => ['GRACE', 'UPSELL TSD - ROSE SOAP', 'SCAR CREAM'],
            'is_upsell'    => true,
            'amount'       => 800,
        ]);

        $matchingRoseSoap = ProductPerformance::matchingOrders($roseSoap, collect([$order]), new Collection([$scarCream, $roseSoap]));
        $this->assertCount(0, $matchingRoseSoap, 'the upsold add-on (Rose Soap) must no longer get its own card for this order');

        $matchingScarCream = ProductPerformance::matchingOrders($scarCream, collect([$order]), new Collection([$scarCream, $roseSoap]));
        $this->assertCount(1, $matchingScarCream, 'the order must still count toward the base product (Scar Cream) it was actually sold/upsold against');
    }

    /** Same scenario, but the add-on also carries a bare "ROSE SOAP" tag
     *  (not just the "UPSELL TSD - ROSE SOAP" one) — the exclusion must
     *  still hold; it isn't enough to only block the explicit `product`
     *  field match and let the tag loop slip through underneath it. */
    public function test_a_bare_tag_naming_the_addon_does_not_bypass_the_exclusion(): void
    {
        $roseSoap = $this->product([
            'display_name'  => 'Rose Soap',
            'match_keyword' => 'ROSE SOAP',
            'team'          => 'SH Naturals',
        ]);

        $order = $this->order([
            'team'         => 'SH Naturals',
            'product'      => 'Rose Soap',
            'base_product' => 'Scar Cream',
            'raw_tags'     => ['UPSELL TSD - ROSE SOAP', 'ROSE SOAP'],
            'is_upsell'    => true,
            'amount'       => 800,
        ]);

        $matching = ProductPerformance::matchingOrders($roseSoap, collect([$order]), new Collection([$roseSoap]));
        $this->assertCount(0, $matching);
    }

    /** A NON-upsell order (e.g. a standalone Rose Soap purchase) must be
     *  completely unaffected — the exclusion only applies to a genuine
     *  upsell (Order::isBroadRealUpsell()). */
    public function test_a_standalone_non_upsell_order_still_matches_its_own_product(): void
    {
        $roseSoap = $this->product([
            'display_name'  => 'Rose Soap',
            'match_keyword' => 'ROSE SOAP',
            'team'          => 'SH Naturals',
        ]);

        $order = $this->order([
            'team'         => 'SH Naturals',
            'product'      => 'Rose Soap',
            'base_product' => 'Rose Soap',
            'raw_tags'     => ['ROSE SOAP'],
            'amount'       => 800,
        ]);

        $matching = ProductPerformance::matchingOrders($roseSoap, collect([$order]), new Collection([$roseSoap]));
        $this->assertCount(1, $matching);
    }

    /** A same-product "self upsell" (repeat order of the identical product
     *  as an add-on) must still match normally — the exclusion only fires
     *  when $product is specifically the ADD-ON, not also the base. */
    public function test_an_upsell_of_the_same_product_as_the_base_still_matches(): void
    {
        $roseSoap = $this->product([
            'display_name'  => 'Rose Soap',
            'match_keyword' => 'ROSE SOAP',
            'team'          => 'SH Naturals',
        ]);

        $order = $this->order([
            'team'         => 'SH Naturals',
            'product'      => 'Rose Soap',
            'base_product' => 'Rose Soap',
            'raw_tags'     => ['UPSELL TSD - ROSE SOAP'],
            'is_upsell'    => true,
            'amount'       => 1600,
        ]);

        $matching = ProductPerformance::matchingOrders($roseSoap, collect([$order]), new Collection([$roseSoap]));
        $this->assertCount(1, $matching);
    }

    /** Production gap caught live right after the first version of this fix
     *  shipped — order #1378313 still showed on Rose Soap's own card even
     *  after the `product`-only guard was deployed. Cause: bundle_description
     *  is set from the SAME upsold-item variation_info display_id `product`
     *  itself comes from (SyncTodayOrders::extractUpsellProduct()), so
     *  $explicitMatch's own bundle_description term (and the tag loop) could
     *  still match Rose Soap even when the product-only check had already
     *  excluded it. The guard must also check bundle_description. */
    public function test_an_upsold_addon_matching_only_via_bundle_description_is_still_excluded(): void
    {
        $scarCream = $this->product([
            'display_name'  => 'Scar Cream',
            'match_keyword' => 'SCAR CREAM',
            'team'          => 'SH Naturals',
        ]);
        $roseSoap = $this->product([
            'display_name'  => 'Rose Soap',
            'match_keyword' => 'ROSE SOAP',
            'team'          => 'SH Naturals',
        ]);

        $order = $this->order([
            'team'               => 'SH Naturals',
            'product'            => 'Rose Soap',
            'base_product'       => 'Scar Cream',
            'bundle_description' => 'Rose Soap',
            'raw_tags'           => ['GRACE', 'UPSELL TSD - ROSE SOAP', 'SCAR CREAM'],
            'is_upsell'          => true,
            'amount'             => 800,
        ]);

        $matchingRoseSoap = ProductPerformance::matchingOrders($roseSoap, collect([$order]), new Collection([$scarCream, $roseSoap]));
        $this->assertCount(0, $matchingRoseSoap, 'bundle_description naming the add-on must not bypass the exclusion');

        $matchingScarCream = ProductPerformance::matchingOrders($scarCream, collect([$order]), new Collection([$scarCream, $roseSoap]));
        $this->assertCount(1, $matchingScarCream);
    }

    /** A genuine, non-upsell multi-product combo SKU (e.g. "Ginseng Serum +
     *  Scar Cream", both legitimately purchased together, no upsell tag at
     *  all) must still let BOTH products count — the exclusion only ever
     *  fires for a genuine upsell (Order::isBroadRealUpsell()), which a
     *  plain combo purchase is not. */
    public function test_a_non_upsell_combo_order_still_counts_toward_both_products(): void
    {
        $ginsengSerum = $this->product([
            'display_name'  => 'Ginseng Serum',
            'match_keyword' => 'GINSENG',
            'team'          => 'SH Naturals',
        ]);
        $scarCream = $this->product([
            'display_name'  => 'Scar Cream',
            'match_keyword' => 'SCAR CREAM',
            'team'          => 'SH Naturals',
        ]);

        $comboOrder = $this->order([
            'team'               => 'SH Naturals',
            'product'            => 'Ginseng Serum',
            'base_product'       => 'Ginseng Serum',
            'bundle_description' => '1 Ginseng Serum + 5 Scar Cream',
            'raw_tags'           => ['GINSENG', 'SCAR CREAM'],
            'amount'             => 2500,
        ]);

        $matchingGinseng = ProductPerformance::matchingOrders($ginsengSerum, collect([$comboOrder]), new Collection([$ginsengSerum, $scarCream]));
        $this->assertCount(1, $matchingGinseng);

        $matchingScarCream = ProductPerformance::matchingOrders($scarCream, collect([$comboOrder]), new Collection([$ginsengSerum, $scarCream]));
        $this->assertCount(1, $matchingScarCream, 'a genuine non-upsell combo must still count toward both bundled products');
    }

    /** ROOT CAUSE, found via /systematic-debugging after 2 failed fix
     *  attempts both targeted the wrong branch: matchingOrders()'s own
     *  ID-priority check (line ~189, "ID matching is authoritative and
     *  skips every text heuristic below entirely") `return`s BEFORE the
     *  upsold-add-on exclusion guard is ever reached, whenever BOTH the
     *  product's own catalog pancake_product_ids AND the order's own
     *  pancake_product_ids are non-empty. SyncTodayOrders populates an
     *  order's pancake_product_ids from EVERY line item's own product_id
     *  (array_column($raw['items'], 'product_id')) — for a 2-item order
     *  (Scar Cream + Rose Soap), that's BOTH items' real IDs, regardless
     *  of which one is the base and which is the upsold add-on. Checking
     *  Rose Soap's own ID against that list finds a match (Rose Soap's ID
     *  IS one of the order's 2 item IDs) and returns true immediately —
     *  completely bypassing the text-based exclusion guard below it. Both
     *  prior fixes (product-only, then +bundle_description) only ever
     *  touched that unreachable text-matching path; neither could work
     *  for an order whose add-on has its own real mapped catalog ID
     *  (exactly order #1378313's case, confirmed live in production both
     *  times). The fix must live in the ID-matching branch itself. */
    public function test_an_upsold_addon_with_its_own_mapped_catalog_id_is_still_excluded(): void
    {
        $scarCream = $this->product([
            'display_name'        => 'Scar Cream',
            'match_keyword'       => 'SCAR CREAM',
            'team'                => 'SH Naturals',
            'pancake_product_ids' => ['scar-cream-real-id'],
        ]);
        $roseSoap = $this->product([
            'display_name'        => 'Rose Soap',
            'match_keyword'       => 'ROSE SOAP',
            'team'                => 'SH Naturals',
            'pancake_product_ids' => ['rose-soap-real-id'],
        ]);

        $order = $this->order([
            'team'                => 'SH Naturals',
            'product'             => 'Rose Soap',
            'base_product'        => 'Scar Cream',
            'bundle_description'  => 'Rose Soap',
            'raw_tags'            => ['GRACE', 'UPSELL TSD - ROSE SOAP', 'SCAR CREAM'],
            'is_upsell'           => true,
            'amount'              => 800,
            // Both line items' own real catalog IDs, same as
            // SyncTodayOrders::array_column($raw['items'], 'product_id')
            // produces for a real 2-item order.
            'pancake_product_ids' => ['scar-cream-real-id', 'rose-soap-real-id'],
        ]);

        $matchingRoseSoap = ProductPerformance::matchingOrders($roseSoap, collect([$order]), new Collection([$scarCream, $roseSoap]));
        $this->assertCount(0, $matchingRoseSoap, 'an upsold add-on with its own mapped catalog ID must still be excluded from its own card');

        $matchingScarCream = ProductPerformance::matchingOrders($scarCream, collect([$order]), new Collection([$scarCream, $roseSoap]));
        $this->assertCount(1, $matchingScarCream, 'the base product must still match via its own ID');
    }

    /** An upsell order with NO identifiable base_product at all (e.g. a
     *  SEPARATE PARCEL order whose own item IS the add-on, see
     *  ProductPerformanceCanceledUpsellTest's own equivalent case) must NOT
     *  be excluded — there's nowhere to redirect it to, so blackholing it
     *  would just drop its revenue from every card instead of moving it to
     *  the right one. */
    public function test_an_upsell_with_no_base_product_data_still_matches_its_own_tag(): void
    {
        $roseSoap = $this->product([
            'display_name'  => 'Rose Soap',
            'match_keyword' => 'ROSE SOAP',
            'team'          => 'SH Naturals',
        ]);

        $order = $this->order([
            'team'         => 'SH Naturals',
            'product'      => 'Rose Soap',
            'base_product' => null,
            'raw_tags'     => ['UPSELL TSD - ROSE SOAP', 'SEPARATE PARCEL'],
            'is_upsell'    => true,
            'amount'       => 800,
        ]);

        $matching = ProductPerformance::matchingOrders($roseSoap, collect([$order]), new Collection([$roseSoap]));
        $this->assertCount(1, $matching);
    }

    /** ROOT CAUSE, found via live screenshot 2026-10-13, order #1375393
     *  (Katherine Chua): a NARROWER variant of the bug the ID-matching fix
     *  above was built for. This order's product/base_product fields BOTH
     *  correctly say "Rose Soap" (the real base), and its tag explicitly
     *  says "UPSELL TSD - ROSE SOAP" — Ginseng Serum was the upsold add-on,
     *  never named anywhere in the order's OWN text fields at all (unlike
     *  #1378313 above, where the add-on's name WAS the order's own
     *  `product` field). But Pancake's raw item list still carries Ginseng
     *  Serum's own real catalog ID alongside Rose Soap's in
     *  pancake_product_ids (every line item's ID is stored, base AND
     *  add-on alike). The 2026-10-10 fix only ever excludes a product that
     *  the order's OWN product/bundle_description TEXT names as the
     *  add-on — it never considered a product whose ONLY connection to the
     *  order is a co-occurring ID with no textual mention anywhere. Fixed:
     *  whenever the order has a real base_product that is a DIFFERENT
     *  product than $product (by text), $product can never match via ID
     *  co-occurrence — confirmed this closes the gap without needing
     *  Ginseng Serum's name to appear in product/bundle_description at
     *  all. */
    public function test_a_co_occurring_id_with_no_textual_mention_does_not_steal_the_order(): void
    {
        $roseSoap = $this->product([
            'display_name'        => 'Rose Soap',
            'match_keyword'       => 'ROSE SOAP',
            'team'                => 'SH Naturals',
            'pancake_product_ids' => ['7ec60d97-c212-43b3-b166-b7048d2f26cc'],
        ]);
        $ginsengSerum = $this->product([
            'display_name'        => 'Ginseng Serum',
            'match_keyword'       => 'GINSENG',
            'team'                => 'SH Naturals',
            'pancake_product_ids' => ['772458fd-ebd7-492a-96f4-fdc1865d9db4'],
        ]);

        $order = $this->order([
            'team'                => 'SH Naturals',
            'product'             => 'Rose Soap',
            'base_product'        => 'Rose Soap',
            'bundle_description'  => '1 - Rose Soap',
            'raw_tags'            => ['KATHERINE', 'ROSE SOAP', 'Unreconciled', 'UPSELL TSD - ROSE SOAP'],
            'is_upsell'           => false,
            'amount'              => 500.00,
            // Both line items' own real catalog IDs — Rose Soap (the
            // base) AND Ginseng Serum (the upsold add-on, never named in
            // product/base_product/bundle_description/raw_tags anywhere).
            'pancake_product_ids' => ['7ec60d97-c212-43b3-b166-b7048d2f26cc', '772458fd-ebd7-492a-96f4-fdc1865d9db4'],
        ]);

        $matchingGinseng = ProductPerformance::matchingOrders($ginsengSerum, collect([$order]), new Collection([$roseSoap, $ginsengSerum]));
        $this->assertCount(0, $matchingGinseng, 'Ginseng Serum must not claim this order just because its ID co-occurs on it — the order was never textually attributed to it');

        $matchingRoseSoap = ProductPerformance::matchingOrders($roseSoap, collect([$order]), new Collection([$roseSoap, $ginsengSerum]));
        $this->assertCount(1, $matchingRoseSoap, 'Rose Soap — the real base product — must still match via its own ID');
    }

    /** Guards against the fix above being backwards: a genuine non-upsell
     *  multi-product combo order (no upsell tag/signal at all) with real
     *  mapped IDs for BOTH its bundled products must still let both of
     *  them match via ID co-occurrence — the new guard is gated on
     *  Order::isBroadRealUpsell(), so it must never fire for a plain
     *  combo purchase. */
    public function test_a_non_upsell_combo_order_with_mapped_ids_still_matches_both_via_id(): void
    {
        $ginsengSerum = $this->product([
            'display_name'        => 'Ginseng Serum',
            'match_keyword'       => 'GINSENG',
            'team'                => 'SH Naturals',
            'pancake_product_ids' => ['ginseng-real-id'],
        ]);
        $scarCream = $this->product([
            'display_name'        => 'Scar Cream',
            'match_keyword'       => 'SCAR CREAM',
            'team'                => 'SH Naturals',
            'pancake_product_ids' => ['scar-cream-real-id'],
        ]);

        $comboOrder = $this->order([
            'team'                => 'SH Naturals',
            'product'             => 'Ginseng Serum',
            'base_product'        => 'Ginseng Serum',
            'bundle_description'  => '1 Ginseng Serum + 5 Scar Cream',
            'raw_tags'            => ['GINSENG', 'SCAR CREAM'],
            'amount'              => 2500,
            'pancake_product_ids' => ['ginseng-real-id', 'scar-cream-real-id'],
        ]);

        $matchingGinseng = ProductPerformance::matchingOrders($ginsengSerum, collect([$comboOrder]), new Collection([$ginsengSerum, $scarCream]));
        $this->assertCount(1, $matchingGinseng);

        $matchingScarCream = ProductPerformance::matchingOrders($scarCream, collect([$comboOrder]), new Collection([$ginsengSerum, $scarCream]));
        $this->assertCount(1, $matchingScarCream, 'a genuine non-upsell combo with mapped IDs must still count toward both bundled products via ID matching');
    }
}
