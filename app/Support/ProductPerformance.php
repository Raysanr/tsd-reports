<?php

namespace App\Support;

use App\Models\Order;
use App\Models\Product;
use App\Models\TsaShift;
use Illuminate\Support\Collection;

/**
 * Computes one Product's lead-count/disposition/rate row from a candidate orders
 * collection — the shared counting logic behind both the TSA Performance "ALL"
 * view (one row per product, whole day) and the Leads Report per-product hourly
 * breakdown (one row per product PER HOUR). Extracted so both call sites can
 * never drift into counting the same thing two different ways — exactly how the
 * Excess/Sales/Upselling Rate definition bugs earlier in this project happened.
 */
class ProductPerformance
{
    /** Display metadata for the disposition columns, shared by every view that
     *  renders a product/TSA performance row (excludes 'total', which always has
     *  its own fixed header). */
    public const METRIC_COLUMNS = [
        ['key' => 'confirmed_via_call',     'label' => 'Confirmed<br>via Call',        'group' => 'answered', 'min_width' => 72],
        ['key' => 'upsell_confirmation',    'label' => 'Upsell w/<br>Confirmation',    'group' => 'answered', 'min_width' => 72, 'highlight' => true],
        ['key' => 'call_back',              'label' => 'Call<br>Back',                 'group' => 'answered', 'min_width' => 72],
        ['key' => 'call_dropped',           'label' => 'Call<br>Dropped',              'group' => 'answered', 'min_width' => 72],
        ['key' => 'repeat_order_upsell',    'label' => 'Repeat Order<br>w/ Upsell',    'group' => 'answered', 'min_width' => 80],
        ['key' => 'rude_customer',          'label' => 'Rude<br>Customer',             'group' => 'answered', 'min_width' => 72],
        ['key' => 'relatives_confirmation', 'label' => 'Relatives<br>Confirmation',    'group' => 'answered', 'min_width' => 80],
        ['key' => 'dfr',                    'label' => 'Duplicate<br>(DFR)',           'group' => 'unanswered', 'min_width' => 72],
        ['key' => 'double_order',           'label' => 'Double Order<br>(System)',     'group' => 'unanswered', 'min_width' => 80],
        ['key' => 'fsd_uncleared',          'label' => 'FSD<br>Uncleared',             'group' => 'unanswered', 'min_width' => 72],
        ['key' => 'not_answering',          'label' => 'Not<br>Answering',             'group' => 'unanswered', 'min_width' => 72],
        ['key' => 'unattended',             'label' => 'Unat-<br>tended',              'group' => 'unanswered', 'min_width' => 72],
        ['key' => 'invalid_number',         'label' => 'Invalid<br>Number',            'group' => 'unanswered', 'min_width' => 72],
        // Excess = a lead swept "UNCATERED LEADS" that NO TSA ever claimed — see
        // buildRow()'s 'excess' line below for the full reasoning (confirmed against
        // real Pancake POS data: a null disposition is NOT uncatered, and a stale
        // "UNCATERED LEADS" tag on an order a TSA already worked is Catered).
        ['key' => 'excess',                 'label' => 'Excess<br>Leads',              'group' => 'excess', 'min_width' => 80],
    ];

    /** One product's row: matches orders to this product (team + tag/cart-item),
     *  then counts each disposition, upsell, excess, and rate. Stateless — call it
     *  once per whole-day total, or once per hour with that hour's order subset;
     *  either way it re-matches from scratch, so it's always correct regardless of
     *  what slice of orders it's given.
     *
     *  $teamProducts (optional): the full product list for $product's team, used to
     *  catch stale-tag mis-attribution — see the conflict check below. Every call
     *  site has this list in scope already; omit it only from throwaway/test calls
     *  where that safeguard doesn't matter. */
    public static function buildRow(Product $product, Collection $orders, ?Collection $teamProducts = null): array
    {
        $matching = self::matchingOrders($product, $orders, $teamProducts);

        $row = self::tally($matching);
        $row['product_id']   = $product->id;
        $row['display_name'] = $product->display_name;
        $row['team']         = $product->team;

        return $row;
    }

    /** The distinct orders that make up sumRows()'s own 'total' across every
     *  product in $products — i.e. exactly the orders a Leads Report/TSA
     *  Performance Grand Total counts, matched then reduced to unique IDs
     *  (a cross-team combo order counted toward two products' rows is still
     *  ONE real order here). Added 2026-09-01 for InsightsGenerator's own
     *  EOD report — its Lead Capacity & Distribution section needs to
     *  bucket the SAME set of counted orders by hour (Opening vs Closing),
     *  and bucketing $orders directly (unmatched, exclusions un-applied)
     *  would double-count cross-team combos and include Deleted/Canceled/
     *  excluded-seller/duplicate orders tally() itself would drop — exactly
     *  the mismatch confirmed live (202+214=416 hour-bucketed leads vs. 227
     *  in sumRows()'s own total for the same day/team). Same exclusion
     *  filter tally() applies, kept in sync by hand (matches that method's
     *  own comment on why: Deleted (7) always dropped, Canceled (6) kept
     *  only when it's a genuine pre-cancellation upsell, excluded-seller/
     *  duplicated-by-logistics orders dropped). */
    public static function countedOrdersFor(Collection $products, Collection $orders): Collection
    {
        $ids = collect();
        foreach ($products as $product) {
            $matching = self::matchingOrders($product, $orders, $products)
                ->reject(fn ($o) => $o->status_code === 7
                    || ($o->status_code === 6 && !Order::isBroadRealUpsell($o))
                    || $o->excluded_upsell_seller
                    || $o->is_duplicated_by_logistics);
            $ids = $ids->merge($matching->pluck('id'));
        }
        $ids = $ids->unique();
        return $orders->whereIn('id', $ids)->values();
    }

    /** The order-matching filter buildRow() counts from, extracted so a drill-down
     *  (e.g. LeadsReportController::drilldown()) can list the exact orders behind a
     *  product's total instead of just the count — same matching, so the list can
     *  never drift from what buildRow() actually counted. */
    public static function matchingOrders(Product $product, Collection $orders, ?Collection $teamProducts = null): Collection
    {
        // Root-caused 2026-08-17 (production: 111 here vs 103 in Pancake POS's
        // own exact-product-ID filter, same day/team/products): bare keyword
        // matching below conflates genuinely separate Pancake products that
        // happen to share a word, e.g. "SINUXYL" sweeping in the real, distinct
        // products "Sinuxyl Steam Pack" and "Sinuxyl Nasal Spray" alongside the
        // real "Sinuxyl" — confirmed via GET /shops/{shopId}/products/variations
        // that each has its own real, stable UUID product_id (the same ID POS's
        // own Products filter panel picks by; its numeric badges like "643" are
        // that product's display_id). ID matching is authoritative and skips
        // every text heuristic below (team gate, stale-tag conflict guard)
        // entirely whenever BOTH sides have real IDs captured — there's no
        // "wrong team" or "stale tag" ambiguity left to guard against once
        // you're comparing the real catalog ID directly. Falls through to the
        // text-matching path per-order whenever either side lacks ID data yet
        // (product not mapped, or order synced/backfilled before this existed),
        // so older date ranges don't go blank pending a full backfill.
        $productIds = collect($product->pancake_product_ids ?? [])->filter()->values();

        // Team-scoped, then matched primarily via raw_tags — confirmed against real
        // POS data that this is the reliable signal: every "Clear Sight 3.0" order
        // carries a plain "CLEARSIGHT" tag, and every upsell add-on order (e.g.
        // "LUMICARE OIL") carries its real base product's tag too. The `product`
        // cart-item field is only a fallback for the rare order with no matching tag
        // at all — matching on it alone undercounts every upsold product and misses
        // CLEARSIGHT entirely, since "Clear Sight 3.0" (the cart item name, with a
        // space) never substring-matches "CLEARSIGHT".
        return $orders->filter(function ($o) use ($product, $teamProducts, $productIds) {
            // A SEPARATE PARCEL upsell order's own line item IS the upsold
            // add-on itself (e.g. "Turmeric Soap"), shipped as its own
            // sibling Pancake order — its real product ID genuinely belongs
            // to THAT add-on product, not to whatever base product the TSA
            // actually upsold onto (e.g. Ginseng Serum). Explicit follow-up
            // request, 2026-09-03: "when there's separate upsell ... this
            // 1000 will be in kathleen's upsell" — confirmed live, order
            // #1363274 (Kathleen): Turmeric Soap has no catalog entry/ID
            // mapping of its own at all, so the ID-priority check below would
            // return false for EVERY product regardless of which one is
            // being checked, making the order invisible from every
            // Per-Product column while it still correctly counted toward the
            // TSA's overall Dashboard upsell total (isBroadRealUpsell()/
            // realUpsellAmount() never depend on product-ID matching).
            // Skipping ID-matching for this order and falling straight to the
            // tag loop below lets its "TSD UPSELL - GINSENG SERUM" tag
            // correctly attribute it to Ginseng Serum — the base product this
            // upsell was actually made against, same "trust the tag" logic
            // extractUpsellProduct()/remainingItemIsJustTheBase() already
            // give a SEPARATE PARCEL order elsewhere for this exact reason.
            $isSeparateParcel = Order::hasSeparateParcelTag($o->raw_tags ?? []);

            if (!$isSeparateParcel && $productIds->isNotEmpty() && !empty($o->pancake_product_ids)) {
                return $productIds->intersect($o->pancake_product_ids)->isNotEmpty();
            }

            // Exclusion checked against the order's OWN item fields only — never
            // a tag. A stale/leftover tag naming $product can still sit on an
            // order whose real item is the excluded sibling (confirmed live,
            // order #1351171: tags included a bare "PTERYGIUM" tag, but its
            // actual item — product AND base_product both — was "Pterylief Eye
            // Drops", a genuinely separate real product). Checking this here,
            // before the tag loop below ever runs, closes that gap; checking it
            // per-tag inside matchesText() itself would not have, since the tag
            // text alone ("PTERYGIUM") never contains the exclude keyword
            // ("PTERYLIEF") — only the order's own item name does.
            if ($product->isExcludedByItemName($o->product) || $product->isExcludedByItemName($o->base_product) || $product->isExcludedByItemName($o->bundle_description)) {
                return false;
            }

            // bundle_description is the item's full combo text (e.g. "1 Ginseng
            // Serum + 5 Scar Cream") — `product` alone only ever holds the catalog
            // entry's generic name, which silently hid every other product bundled
            // into the same combo SKU (confirmed in production: a Ginseng Serum +
            // Scar Cream combo order never counted toward Scar Cream at all).
            //
            // base_product is the customer's ORIGINALLY-ordered item, independent of
            // `product` (which becomes the UPSOLD item's name once an order carries
            // an upsell). Without this, an order that started as e.g. AudiCure but
            // was upsold to Ear Relief Balm has no remaining signal anywhere
            // pointing back to AudiCure — confirmed in production: neither
            // `product`, `bundle_description`, nor `raw_tags` mention it for this
            // team/combo, silently dropping it from AudiCure's count even though
            // Pancake's own product search still finds it.
            //
            // Checked BEFORE the team gate below, and trusted across team lines:
            // an order only ever carries ONE team (assigned from its PRIMARY item),
            // but a combo can genuinely bundle products from two different teams
            // under that one order — e.g. a Pterygium order (Eyecare's own team)
            // bundling 10 Sinuxyl units (SH Naturals). Without this override, that
            // whole cross-team half of the bundle would be invisible everywhere,
            // since the order's single team column can never equal both products'
            // teams at once. An explicit product/base_product/bundle_description
            // text match is authoritative enough to trust regardless of the
            // order's own team — unlike a bare tag match below, which stays
            // team-gated since a tag alone is a weaker, more collision-prone signal.
            $explicitMatch = $product->matchesText($o->product) || $product->matchesText($o->base_product) || $product->matchesText($o->bundle_description);

            if ($o->team !== $product->team && !$explicitMatch) return false;

            // Stale-tag guard: ~1-3 times a week an order's actual cart item is a
            // DIFFERENT team product (confirmed in production: mostly Pterygium
            // orders still carrying a leftover CLEARSIGHT tag from an earlier stage
            // of the conversation) — the tag alone would double-count that order
            // under both products. See conflictingProduct() for the shared check
            // (also used by the tag-conflict review queue, which needs to know
            // WHICH other product it is, not just that there's a conflict).
            if ($teamProducts && self::conflictingProduct($product, $o, $teamProducts)) {
                return false;
            }

            foreach ($o->raw_tags ?? [] as $tag) {
                if ($product->matchesText($tag)) return true;
            }
            return $explicitMatch;
        });
    }

    /** True when $product's own keyword does NOT match the order's cart item
     *  (`product` column) but a DIFFERENT product's keyword DOES — the stale-tag
     *  mismatch pattern: a TSA left an old/wrong tag on the order in Pancake POS
     *  itself (this app has no way to edit Pancake's tags), so the tag says one
     *  product while the actual cart item says another. Extracted so buildRow()'s
     *  counting guard and any future tag-conflict review queue share the exact
     *  same definition of "this is a conflict" and can never drift apart.
     *
     *  Not team-restricted — confirmed in production: order #1341848's real item
     *  was Pterygium (Eyecare), but it carried a leftover "Call in Progress
     *  (Sinuxyl Inhaler)" disposition tag from an earlier stage, AND the order's
     *  own team column was SH Naturals (team is resolved from whichever TSA
     *  claimed the lead, not from the product — a TSA can legitimately work a
     *  lead outside her own team's usual catalog). A same-team-only conflict
     *  check can never find Pterygium as the "real" product there, since it
     *  isn't in SH Naturals' own roster — so the stale Sinuxyl tag matched
     *  unopposed, double-counting the order under both products on the ALL
     *  view (Leads Report/TSA Performance's cross-team pages, which already
     *  pass every product here regardless of team — see indexAll() in both
     *  controllers). $teamProducts' name is a holdover from when every caller
     *  passed a same-team-only list; some now pass every product on purpose.
     *
     *  Returns the conflicting Product, or null when there's no conflict —
     *  either $product's own keyword DOES match the cart item (a real, if
     *  oddly-tagged, match), or no other product in $teamProducts matches it
     *  either (which still means same-team-only for callers that only pass
     *  same-team products, e.g. the per-team drill-down). */
    public static function conflictingProduct(Product $product, Order $order, Collection $teamProducts): ?Product
    {
        // Same bundle_description/base_product fallback as matchingOrders() above —
        // a combo SKU whose display_id reveals $product IS genuinely part of this
        // order, or $product IS the order's originally-ordered (pre-upsell) item,
        // is never a conflict, even though the generic `product` name alone
        // doesn't match it.
        if ($product->matchesText($order->product) || $product->matchesText($order->base_product) || $product->matchesText($order->bundle_description)) {
            return null;
        }

        return $teamProducts->first(fn ($other) => $other->id !== $product->id
            && ($other->matchesText($order->product) || $other->matchesText($order->base_product) || $other->matchesText($order->bundle_description)));
    }

    /** The counting/rate logic on its own, with no product-tag matching — for
     *  team-level or company-wide aggregates (e.g. the Analytics tab's daily/hourly
     *  trends) where there's no single product to match against. buildRow() is just
     *  this plus a product-matching filter step beforehand. */
    public static function tally(Collection $orders): array
    {
        // Drop orders Pancake itself no longer has (Order::DELETED_STATUSES: Canceled
        // or Deleted recently) before counting anything. These rows only exist locally
        // because the sync never re-fetches an order once it's already saved unless a
        // later update touches it — a deletion in Pancake doesn't trigger that, so the
        // stale "last known live" status (often still Restocking/New) sat here forever
        // and got counted as an active lead. This is why Leads Report totals could run
        // HIGHER than Pancake's own order count for a product, not just lower.
        //
        // excluded_upsell_seller (2026-08-21, explicit follow-up request): an order
        // whose upsell item was closed by a known non-TSA account (config/
        // excluded_upsell_sellers.php) doesn't count as a lead at all here, not just
        // as a non-upsell — same "doesn't belong in this report's numbers" treatment
        // as a Cancelled/Deleted order gets above.
        //
        // is_duplicated_by_logistics (2026-08-22, explicit follow-up request):
        // confirmed live — a warehouse/logistics duplicate of an already-real order
        // (Pancake staff flag these via a "DUPLICATED BY LOGISTICS" note, see
        // SyncTodayOrders::isDuplicatedByLogistics()) is a second order record for
        // the SAME lead, not a second real lead — 215 such orders were inflating
        // Leads Report/TSA Performance before this exclusion.
        //
        // Canceled (6) is handled separately from Deleted (7) here (fixed
        // 2026-08-24, real gap: Marisol showed 11 here vs. the Dashboard's
        // correct 12) — Deleted means the order no longer exists in Pancake
        // at all, always dropped; but a Canceled order can still carry a
        // genuine upsell that happened before it was later canceled
        // (is_upsell_on_voided_order, see Order.php's own doc comment,
        // commit 78c5094) — blanket-dropping every Canceled order here was
        // silently undoing that fix for every page that flows through
        // tally(), even though the Dashboard's own Leaderboard (which
        // doesn't apply this exclusion) already counted it correctly.
        $orders = $orders->reject(fn($o) => $o->status_code === 7
            || ($o->status_code === 6 && !Order::isBroadRealUpsell($o))
            || $o->excluded_upsell_seller
            || $o->is_duplicated_by_logistics);

        // The 12 outcome columns count NON-upsell leads only: an upsell order often
        // still carries a disposition tag (e.g. is_upsell + "CONFIRMED VIA CALL", or
        // a stale "Not answering" from an earlier attempt), and counting it in both
        // its disposition column AND Upsell w/ Confirmation counted one lead twice —
        // letting Called Leads exceed New Leads (seen live: 3 new / 4 called). The
        // Upselling Rate formula (upsell ÷ (upsell + confirmed_via_call)) already
        // treats these columns as mutually exclusive; this makes the counts agree.
        //
        // "Real upsell" here is deliberately broader than the stored is_upsell
        // column — see Order::isBroadRealUpsell()'s own doc comment for the full
        // reasoning (a Restocking/void-status order genuinely tagged "Upsell TSD"
        // still counts; a known non-TSA seller account never does).
        $isRealUpsell = fn($o) => Order::isBroadRealUpsell($o);
        $nonUpsell = $orders->reject($isRealUpsell);

        $row = [
            'total'                  => $orders->count(),
            'confirmed_via_call'     => self::count($nonUpsell, 'confirmed via call'),
            'upsell_confirmation'    => $orders->filter($isRealUpsell)->count(),
            'call_back'              => self::count($nonUpsell, 'call back'),
            'call_dropped'           => self::count($nonUpsell, 'call dropped'),
            'repeat_order_upsell'    => self::count($nonUpsell, 'repeat order'),
            'rude_customer'          => self::count($nonUpsell, 'rude customer'),
            'relatives_confirmation' => self::count($nonUpsell, 'relatives'),
            'dfr'                    => self::count($nonUpsell, 'dfr'),
            'double_order'           => self::count($nonUpsell, 'double order'),
            'fsd_uncleared'          => self::count($nonUpsell, 'fsd'),
            'not_answering'          => self::count($nonUpsell, 'not answering'),
            'unattended'             => self::count($nonUpsell, 'unattended'),
            'invalid_number'         => self::count($nonUpsell, 'invalid number'),
        ];

        // Cross-sell/upsell revenue only — the Dashboard's "Total Cross-Sell Sales"
        // definition (add-on items' value), NOT full realized revenue. Same
        // convention already confirmed for the Analytics daily sales trend.
        //
        // $isRealUpsell, not a bare is_upsell=true (fixed 2026-08-11): this used
        // to miss is_returned_upsell orders entirely — an upsell later Returned/
        // Returning has is_upsell forced false (same as any void status), so its
        // revenue silently dropped out of this sum while upsell_confirmation
        // above (already $isRealUpsell-filtered) still counted it toward qty —
        // a product/hour cell could show e.g. "4" upsells with an amount that
        // only reflected 3 of them. Safe now that amount itself holds the
        // isolated add-on price for a returned-upsell row too, not the whole
        // order's total (see SyncTodayOrders::handle()'s own fix, same date).
        // A Restocking-status row recovered by $isRealUpsell's tag-fallback
        // branch still needs Order::realUpsellAmount() specifically — its
        // 'amount' column is the raw order total, not the isolated add-on
        // (see that method's own doc comment, fixed 2026-08-13).
        $row['upsell_sales'] = (float) $orders->filter($isRealUpsell)->sum(fn (Order $o) => $o->realUpsellAmount());

        $row['answered'] = $row['confirmed_via_call'] + $row['upsell_confirmation'] + $row['call_back'] + $row['call_dropped']
            + $row['repeat_order_upsell'] + $row['rude_customer'] + $row['relatives_confirmation'];
        $row['unanswered'] = $row['dfr'] + $row['double_order'] + $row['fsd_uncleared'] + $row['not_answering']
            + $row['unattended'] + $row['invalid_number'];
        // "Called Leads" — every lead actually called, i.e. Answered + Unanswered.
        $row['total_called'] = $row['answered'] + $row['unanswered'];

        // Catered = Answered + Unanswered (total_called) — a lead only counts as
        // catered once it has an actual recognized outcome, not just a TSA name tag
        // with no disposition yet.
        $row['catered'] = $row['total_called'];

        // Restocking — orders Pancake itself has parked in Restocking status
        // (status_code 11, Order::STATUS_LABELS) pending stock, not yet dispositioned
        // one way or the other. Broken out as its own bucket (2026-09-01, explicit
        // request) so it's visible instead of silently inflating Excess — before
        // this, a fully out-of-stock product (e.g. CanPro: 0 total, 0 called, 8
        // Restocking orders) showed a confusing negative Excess with no visible
        // cause on the Leads Report.
        //
        // Excludes an order already counted in $row['catered'] above — a
        // Restocking-status order CAN still carry a genuine upsell tag (see
        // Order::isBroadRealUpsell()'s tag-fallback branch for void statuses,
        // confirmed live: ExcludedUpsellSellerReportsTest's "real-restocking-1"
        // case) or a disposition tag, and that order is already correctly
        // reflected in Catered — bucketing it into Restocking too would double-
        // subtract it out of Excess.
        //
        // ordersForColumn('total_called') is run against ONLY the small
        // Restocking-status subset here, not the full $orders collection — it
        // re-derives every disposition column from scratch over whatever it's
        // given, and running that over the WHOLE order set on a full-month
        // ALL-teams range (~16k orders × 7 products) measured 6.5s -> 11.2s per
        // buildRow() call, enough to blow past production's request timeout
        // (confirmed live, 2026-09-01: /leads-report?date_from=2026-08-01
        // &date_to=2026-08-31 500'd after this was first added the expensive
        // way). Restricting to the Restocking subset keeps the exact same
        // called-orders definition at a fraction of the cost.
        $restockingStatus = $orders->where('status_code', 11);
        $calledRestockingIds = self::ordersForColumn($restockingStatus, 'total_called')->pluck('id');
        $row['restocking'] = $restockingStatus->whereNotIn('id', $calledRestockingIds)->count();

        // Excess = Total - Catered - Restocking: the reconciling remainder once both
        // known non-lead buckets are pulled out, so every row still adds up visibly
        // (total = catered + restocking + excess) with no unexplained bucket. This
        // deliberately folds any remaining mid-call/not-yet-dispositioned leads into
        // Excess rather than Catered — a stricter definition than the previous
        // tag-based one (Order::EXCESS_DISPOSITIONS is no longer read here at all).
        $row['excess'] = $row['total'] - $row['catered'] - $row['restocking'];

        return array_merge($row, self::rates($row));
    }

    /** Pick-up / Conversion / Upselling rates from a row with 'answered',
     *  'unanswered', 'upsell_confirmation', and 'confirmed_via_call' keys. */
    public static function rates(array $row): array
    {
        $totalCalled = $row['answered'] + $row['unanswered'];

        return [
            'pick_up_rate'    => $totalCalled > 0 ? round($row['answered'] / $totalCalled * 100, 1) : null,
            // Denominator is Answered only, NOT Answered + Unanswered — confirmed
            // against the "TSD Updated Formula Base" reference ("Total Answered Called Leads").
            'conversion_rate' => $row['answered'] > 0 ? round($row['upsell_confirmation'] / $row['answered'] * 100, 1) : null,
            'upselling_rate'  => self::upsellingRate($row),
        ];
    }

    /** Upsell w/ Confirmation as a % of (Upsell w/ Confirmation + Confirmed via Call) —
     *  the official Upselling Rate formula (TSD Updated Formula Base, May 2026). Null
     *  when both are zero (nothing to compute a rate from). */
    public static function upsellingRate(array $columns): ?float
    {
        $denominator = $columns['upsell_confirmation'] + $columns['confirmed_via_call'];
        if ($denominator <= 0) return null;
        return round($columns['upsell_confirmation'] / $denominator * 100, 1);
    }

    /** Sums multiple buildRow()/tally() rows into one combined row — for a Grand
     *  Total defined as literally "the sum of the rows shown above it", not a
     *  separately-tallied distinct-order count. The two definitions necessarily
     *  diverge whenever an order legitimately counts toward more than one
     *  product's row (a cross-team combo SKU — see matchingOrders()'s
     *  $explicitMatch comment): tally() counts that order once, but summing the
     *  per-product rows counts it once per product it matched. Confirmed live:
     *  Leads Report's per-product totals added up to 1 more than tally()'s Grand
     *  Total on a day with exactly one such combo order (#1343222, "10 Pterygium
     *  Drops + 10 Sinuxyl", counted in both PTERYGIUM's and SINUXYL's rows).
     *  Every additive count/amount field is summed; rate fields are recomputed
     *  from the summed totals rather than averaged (averaging percentages across
     *  rows of different sizes is meaningless). */
    public static function sumRows(Collection $rows): array
    {
        $keys = [
            'total', 'confirmed_via_call', 'upsell_confirmation', 'call_back', 'call_dropped',
            'repeat_order_upsell', 'rude_customer', 'relatives_confirmation', 'dfr', 'double_order',
            'fsd_uncleared', 'not_answering', 'unattended', 'invalid_number', 'upsell_sales',
            'answered', 'unanswered', 'total_called', 'catered', 'restocking', 'excess',
        ];

        $summed = array_fill_keys($keys, 0);
        foreach ($rows as $row) {
            foreach ($keys as $key) {
                $summed[$key] += $row[$key] ?? 0;
            }
        }

        return array_merge($summed, self::rates($summed));
    }

    /** One tally() row per TSA (keyed by tsa_key), built the SAME way
     *  TsaPerformanceController::indexAll() builds its own $tsaRows — group
     *  by each order's own team column FIRST, then by tsa_name within that
     *  team's own subset, and ONLY if tsa_name matches a REAL, CURRENT
     *  roster key for that exact team (a stale/renamed/wrong-team tsa_name
     *  falls out entirely here, same as indexAll()'s own Unassigned
     *  fallback). Extracted 2026-09-02 so any other page needing a per-TSA
     *  breakdown (the Dashboard's own TSA Leaderboard, in particular) calls
     *  this SAME function instead of reconstructing the grouping by hand —
     *  a hand-rolled copy of "group by team first" drifted out of sync with
     *  this one twice already (Dashboard leaderboard vs TSA Performance:
     *  Katherine 16 vs 15, then Grace 8 vs 7, both confirmed live,
     *  2026-09-02) even though both were built to the same intent, because
     *  matching intent in two separate implementations doesn't guarantee
     *  matching behavior on every real edge case. $shifts: every TsaShift
     *  row to build a row for (already filtered to the teams in scope by
     *  the caller). Returns tsa_key => tally() row (no Unassigned row —
     *  callers that need it build that separately from whatever's left
     *  over, same as indexAll() does). */
    public static function tsaRows(Collection $orders, Collection $shifts): Collection
    {
        $ordersByTeam = $orders->groupBy('team');

        return $shifts->mapWithKeys(function (TsaShift $shift) use ($ordersByTeam) {
            $teamOrders = $ordersByTeam->get($shift->team, collect());
            $tsaOrders  = $teamOrders->where('tsa_name', $shift->tsa_key);

            return [$shift->tsa_key => self::tally($tsaOrders)];
        });
    }

    /** Diagnostic only: given a product and one order matchingOrders() already
     *  matched, explains WHICH signal caused it — a real Pancake product-ID
     *  match, the order's own cart/base item text, a combo's bundle
     *  description, or a leftover tag. Powers the drill-down popover's "why
     *  did this count?" line, so a false positive (e.g. a short/generic
     *  keyword catching an unrelated order) is visible directly instead of
     *  requiring a manual look-up in Pancake for every suspicious row. Checks
     *  in the exact same priority order as matchingOrders() itself, so the
     *  reason shown is always the one that actually decided it there. */
    public static function matchReason(Product $product, Order $order): string
    {
        $productIds = collect($product->pancake_product_ids ?? [])->filter()->values();
        if ($productIds->isNotEmpty() && !empty($order->pancake_product_ids)) {
            $shared = $productIds->intersect($order->pancake_product_ids);
            if ($shared->isNotEmpty()) return 'Pancake product ID match';
        }

        if ($product->matchesText($order->product)) return "cart item: \"{$order->product}\"";
        if ($product->matchesText($order->base_product)) return "base item: \"{$order->base_product}\"";
        if ($product->matchesText($order->bundle_description)) return "combo/bundle: \"{$order->bundle_description}\"";

        foreach ($order->raw_tags ?? [] as $tag) {
            if ($product->matchesText($tag)) return "tag: \"{$tag}\"";
        }

        return 'unknown';
    }

    /** Same categorization rules as tally() above, but returning the actual
     *  matching Order models for one column instead of just a count — powers
     *  every drill-down popover (TsaPerformanceController::drilldown(),
     *  LeadsReportController::drilldown()) that shows "which orders made up
     *  this number" when a table cell is clicked. Kept as a separate method
     *  (not a tally() refactor) so this addition can't accidentally change
     *  tally()'s own well-tested counts. Originally lived as a private method
     *  on TsaPerformanceController; moved here so Leads Report's drilldown
     *  can use the exact same categorization instead of a second hand-copied
     *  version that could drift out of sync with it. */
    public static function ordersForColumn(Collection $orders, string $column): Collection
    {
        // Same exclusions as tally() above, including the Canceled-but-a-
        // genuine-upsell carve-out (2026-08-24) — see that method's own
        // comment for why.
        $orders = $orders->reject(fn($o) => $o->status_code === 7
            || ($o->status_code === 6 && !Order::isBroadRealUpsell($o))
            || $o->excluded_upsell_seller
            || $o->is_duplicated_by_logistics);

        $isRealUpsell = fn($o) => Order::isBroadRealUpsell($o);
        $nonUpsell = $orders->reject($isRealUpsell);

        if ($column === 'upsell_confirmation') {
            return $orders->filter($isRealUpsell)->values();
        }

        $keywordMap = [
            'confirmed_via_call'     => ['confirmed via call'],
            'call_back'              => ['call back'],
            'call_dropped'           => ['call dropped'],
            'repeat_order_upsell'    => ['repeat order'],
            'rude_customer'          => ['rude customer'],
            'relatives_confirmation' => ['relatives'],
            'dfr'                    => ['dfr'],
            'double_order'           => ['double order'],
            'fsd_uncleared'          => ['fsd'],
            'not_answering'          => ['not answering'],
            'unattended'             => ['unattended'],
            'invalid_number'         => ['invalid number'],
        ];

        if (isset($keywordMap[$column])) {
            return $nonUpsell->filter(function ($o) use ($keywordMap, $column) {
                $disposition = str_replace("'", '', $o->disposition ?? '');
                foreach ($keywordMap[$column] as $kw) {
                    if (stripos($disposition, $kw) !== false) return true;
                }
                return false;
            })->values();
        }

        // 'catered' is Leads Report's own label for the exact same union this
        // app calls 'total_called' everywhere else (see tally()'s 'catered' =>
        // 'total_called' line above) — accepted as an alias so a Leads Report
        // cell can pass either name without the view needing to know which
        // internal key its own controller happens to use.
        if ($column === 'total_called' || $column === 'catered') {
            $calledIds = collect([self::ordersForColumn($orders, 'upsell_confirmation')->pluck('id')]);
            foreach (array_keys($keywordMap) as $key) {
                $calledIds->push(self::ordersForColumn($orders, $key)->pluck('id'));
            }
            return $orders->whereIn('id', $calledIds->flatten()->unique())->values();
        }

        // Restocking — see tally()'s 'restocking' line: orders Pancake has parked
        // in Restocking status (status_code 11), pulled out of Excess as their own
        // bucket.
        if ($column === 'restocking') {
            return $orders->where('status_code', 11)->values();
        }

        // Excess = Total - Catered - Restocking (see tally()'s 'excess' line) — the
        // reconciling remainder, so its own "which orders" answer is simply
        // whichever of these orders didn't land in the Called union or Restocking above.
        if ($column === 'excess') {
            $calledIds     = self::ordersForColumn($orders, 'total_called')->pluck('id');
            $restockingIds = self::ordersForColumn($orders, 'restocking')->pluck('id');
            return $orders->whereNotIn('id', $calledIds->merge($restockingIds))->values();
        }

        return collect();
    }

    private static function count(Collection $orders, string $keyword): int
    {
        return self::countAny($orders, [$keyword]);
    }

    /** True if disposition matches ANY of the given keywords (case-insensitive substring). */
    private static function countAny(Collection $orders, array $keywords): int
    {
        // SH Naturals' "RELATIVE'S CONFIRMATION-<product>" tags include an apostrophe
        // that would otherwise break this substring match against the apostrophe-free
        // keyword — strip apostrophes before matching.
        return $orders->filter(function ($o) use ($keywords) {
            $disposition = str_replace("'", '', $o->disposition ?? '');
            foreach ($keywords as $kw) {
                if (stripos($disposition, $kw) !== false) return true;
            }
            return false;
        })->count();
    }
}
