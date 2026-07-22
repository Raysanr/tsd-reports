<?php

namespace App\Support;

use App\Models\Order;
use App\Models\Product;
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
        // Team-scoped, then matched primarily via raw_tags — confirmed against real
        // POS data that this is the reliable signal: every "Clear Sight 3.0" order
        // carries a plain "CLEARSIGHT" tag, and every upsell add-on order (e.g.
        // "LUMICARE OIL") carries its real base product's tag too. The `product`
        // cart-item field is only a fallback for the rare order with no matching tag
        // at all — matching on it alone undercounts every upsold product and misses
        // CLEARSIGHT entirely, since "Clear Sight 3.0" (the cart item name, with a
        // space) never substring-matches "CLEARSIGHT".
        $matching = $orders->filter(function ($o) use ($product, $teamProducts) {
            if ($o->team !== $product->team) return false;

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
            return $product->matchesText($o->product);
        });

        $row = self::tally($matching);
        $row['display_name'] = $product->display_name;
        $row['team']         = $product->team;

        return $row;
    }

    /** True when $product's own keyword does NOT match the order's cart item
     *  (`product` column) but a DIFFERENT same-team product's keyword DOES —
     *  the stale-tag mismatch pattern: a TSA left an old/wrong tag on the order
     *  in Pancake POS itself (this app has no way to edit Pancake's tags), so
     *  the tag says one product while the actual cart item says another.
     *  Extracted so buildRow()'s counting guard and the tag-conflict review
     *  queue (Support\TagConflicts) share the exact same definition of "this is
     *  a conflict" and can never drift apart.
     *
     *  Returns the conflicting Product, or null when there's no conflict —
     *  either $product's own keyword DOES match the cart item (a real, if
     *  oddly-tagged, match), or no other same-team product matches it either. */
    public static function conflictingProduct(Product $product, Order $order, Collection $teamProducts): ?Product
    {
        if ($product->matchesText($order->product)) return null;

        return $teamProducts->first(fn ($other) => $other->id !== $product->id
            && $other->team === $product->team
            && $other->matchesText($order->product));
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
        $orders = $orders->reject(fn($o) => in_array($o->status_code, Order::DELETED_STATUSES, true));

        // The 12 outcome columns count NON-upsell leads only: an upsell order often
        // still carries a disposition tag (e.g. is_upsell + "CONFIRMED VIA CALL", or
        // a stale "Not answering" from an earlier attempt), and counting it in both
        // its disposition column AND Upsell w/ Confirmation counted one lead twice —
        // letting Called Leads exceed New Leads (seen live: 3 new / 4 called). The
        // Upselling Rate formula (upsell ÷ (upsell + confirmed_via_call)) already
        // treats these columns as mutually exclusive; this makes the counts agree.
        $nonUpsell = $orders->where('is_upsell', false);

        $row = [
            'total'                  => $orders->count(),
            'confirmed_via_call'     => self::count($nonUpsell, 'confirmed via call'),
            'upsell_confirmation'    => $orders->where('is_upsell', true)->count(),
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
        $row['upsell_sales'] = (float) $orders->where('is_upsell', true)->sum('amount');

        $row['answered'] = $row['confirmed_via_call'] + $row['upsell_confirmation'] + $row['call_back'] + $row['call_dropped']
            + $row['repeat_order_upsell'] + $row['rude_customer'] + $row['relatives_confirmation'];
        $row['unanswered'] = $row['dfr'] + $row['double_order'] + $row['fsd_uncleared'] + $row['not_answering']
            + $row['unattended'] + $row['invalid_number'];
        // "Called Leads" — every lead actually called, i.e. Answered + Unanswered.
        $row['total_called'] = $row['answered'] + $row['unanswered'];

        // Catered = Answered + Unanswered (total_called) — a lead only counts as
        // catered once it has an actual recognized outcome, not just a TSA name tag
        // with no disposition yet. Excess = Total - Catered: the reconciling
        // remainder, so every row adds up visibly (total = catered + excess) with no
        // third bucket. This deliberately folds mid-call/not-yet-dispositioned leads
        // into Excess rather than Catered — a stricter definition than the previous
        // tag-based one (Order::EXCESS_DISPOSITIONS is no longer read here at all).
        $row['catered'] = $row['total_called'];
        $row['excess']  = $row['total'] - $row['catered'];

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
