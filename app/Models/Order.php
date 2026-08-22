<?php

namespace App\Models;

use Database\Factories\OrderFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Order extends Model
{
    /** @use HasFactory<OrderFactory> */
    use HasFactory;

    protected $fillable = [
        'pancake_order_id',
        'customer_phone',
        'team',
        'tsa_name',
        'disposition',
        'product',
        'base_product',
        'bundle_description',
        'pancake_product_ids',
        'amount',
        'raw_tags',
        'is_upsell',
        'is_cancelled_upsell',
        'cancelled_upsell_amount',
        'is_returned_upsell',
        'returned_upsell_amount',
        'is_restocking_upsell',
        'restocking_upsell_amount',
        'excluded_upsell_seller',
        'is_upsell_on_voided_order',
        'status_code',
        'pancake_created_at',
        'pancake_inserted_at',
        'pancake_updated_at',
        'synced_at',
    ];

    protected $casts = [
        'raw_tags'                => 'array',
        'pancake_product_ids'     => 'array',
        'is_upsell'               => 'boolean',
        'is_cancelled_upsell'     => 'boolean',
        'is_returned_upsell'      => 'boolean',
        'is_restocking_upsell'    => 'boolean',
        'excluded_upsell_seller'  => 'boolean',
        'is_upsell_on_voided_order' => 'boolean',
        'amount'                  => 'decimal:2',
        'cancelled_upsell_amount' => 'decimal:2',
        'returned_upsell_amount'  => 'decimal:2',
        'restocking_upsell_amount' => 'decimal:2',
        'pancake_created_at'      => 'datetime',
        'pancake_inserted_at'     => 'datetime',
        'pancake_updated_at'      => 'datetime',
        'synced_at'               => 'datetime',
    ];

    /** Pancake's numeric order status → display label. Source: api-docs.pancake.vn/openapi.json,
     *  components.schemas.Order.properties.status (enum / x-enum-descriptions). */
    public const STATUS_LABELS = [
        0  => 'New',
        17 => 'Waiting for confirmation',
        11 => 'Restocking',
        12 => 'Wait for printing',
        13 => 'Printed',
        20 => 'Purchased',
        1  => 'Confirmed',
        8  => 'Packaging',
        9  => 'Waiting for pick up',
        2  => 'Shipped',
        3  => 'Received',
        16 => 'Collected money',
        4  => 'Returning',
        15 => 'Partial return',
        5  => 'Returned',
        6  => 'Canceled',
        7  => 'Deleted recently',
    ];

    /** Statuses treated as terminal/void — never counted as a confirmed sale. */
    public const VOID_STATUSES = [4, 15, 5, 6, 7, 11];

    /** 6 = Canceled, 7 = Deleted recently — the order no longer exists in Pancake
     *  at all (unlike Restocking/Returning/Returned, which Pancake still lists as
     *  real orders). A synced row can go stale here: TSD Reports only re-syncs an
     *  order when its own updated_at falls inside a later sync window, so a row
     *  deleted in Pancake after being synced keeps its last-known live status
     *  forever unless something re-fetches it. Counting these as leads produced
     *  phantom leads that inflated the Leads Report above Pancake's own order
     *  count (confirmed live: orders 1332068/1332209/1332122 showed status 7 in
     *  Pancake's API while still sitting as Restocking/New locally) — see
     *  ProductPerformance::tally(), which excludes these before counting anything. */
    public const DELETED_STATUSES = [6, 7];

    /** Legacy disposition value for a swept, never-claimed lead — the tag Pancake's
     *  nightly sweep applied through 2026-07-17. The team stopped applying any
     *  replacement tag starting 2026-07-21 (confirmed with the supervisor): an
     *  unprocessed lead is now identified the same way Pancake's own order-tag
     *  filter finds one — by having NO tag at all — rather than by a specific tag
     *  name. This constant only still matters for old rows that already have
     *  'UNCATERED LEADS' stored as their disposition, so that history doesn't
     *  silently change; see ProductPerformance::tally()'s excess count, which
     *  counts a TSA-less order as Excess when EITHER this legacy disposition is
     *  set OR raw_tags is completely empty. */
    public const EXCESS_DISPOSITIONS = ['UNCATERED LEADS'];

    /** Not-yet-a-sale statuses: 0 = New, 17 = Waiting for confirmation. Still a raw/open
     *  lead the customer hasn't committed to — excluded from "realized sales" alongside
     *  VOID_STATUSES. Everything else (Confirmed, Purchased, Packaging, Shipped, Received,
     *  Collected money, …) counts as a sold order. */
    public const PENDING_STATUSES = [0, 17];

    /** Statuses that are NOT a realized sale (open leads + voided/returned). */
    public const NON_SALE_STATUSES = [0, 17, 4, 15, 5, 6, 7, 11];

    public function getStatusLabelAttribute(): ?string
    {
        return self::STATUS_LABELS[$this->status_code] ?? null;
    }

    /** True if any tag matches "UPSELL TSD" or "TSD UPSELL" (case-insensitive) — the
     *  tag marking a real upsell/cross-sell add-on, distinct from "Follow up -
     *  Upsell" (a disposition, not a new upsell). Single source of truth for
     *  SyncTodayOrders' is_upsell detection at sync time AND
     *  ProductPerformance::tally()'s catered-count check — the latter reads raw tag
     *  text directly instead of trusting the stored is_upsell column alone, because
     *  that column is deliberately forced false for a Restocking/void-status order
     *  even when it genuinely carries this tag (confirmed in production: an
     *  AudiCure order sitting in Restocking, tagged "Upsell TSD (Ear Relief Balm)",
     *  counted as neither a real disposition nor an upsell — invisible as Catered
     *  work that clearly happened). */
    public static function hasUpsellTag(array $tagNames): bool
    {
        foreach ($tagNames as $tag) {
            if (preg_match('/\bUPSELL\s+TSD\b|\bTSD\s+UPSELL\b/i', $tag)) {
                return true;
            }
        }
        return false;
    }

    /** Explicit request (2026-08-13): a real Pancake tag (not the free-text
     *  "note" field some orders already carry a version of, e.g. "SEPERATE
     *  PARCEL TSD") marking an order as one of several parcels from the same
     *  customer/same call — e.g. a base product and an upsell add-on that
     *  had to ship as two separate Pancake orders. Tolerates the
     *  SEPARATE/SEPERATE misspelling already seen in production notes, same
     *  spirit as hasUpsellTag()'s own word-order tolerance. See
     *  LinkSeparateParcelOrders, which uses this to find sibling orders (same
     *  customer_phone, same calendar day) and fill in a missing tsa_name/team
     *  from whichever sibling DOES have one — never overwrites an order that
     *  already has its own attribution. */
    public static function hasSeparateParcelTag(array $tagNames): bool
    {
        foreach ($tagNames as $tag) {
            if (preg_match('/\bSEP[EA]RATE\s+PARCEL\b/i', $tag)) {
                return true;
            }
        }
        return false;
    }

    /** is_upsell alone undercounts: SyncTodayOrders resets it to false once a
     *  genuine upsell is later returned/cancelled in Pancake (is_returned_upsell
     *  is set instead, preserving that it WAS a real upsell — see that flag's own
     *  doc comment). Confirmed live (2026-08-07): Gemma showed 18 upsells on the
     *  Dashboard's TSA Leaderboard for a date real Pancake POS and the logistics
     *  system both counted as 20 — the 3-order gap was exactly her returned
     *  upsells that day. TsaPerformanceController::buildRow() and
     *  ProductPerformance::tally() already had this right; this is the single
     *  shared definition every other "how many/how much did this TSA/team
     *  upsell" query should use instead of re-deriving its own is_upsell-only
     *  version and quietly drifting out of sync with Pancake again. Deliberately
     *  does NOT also fold in ProductPerformance's tag-fallback branch (catches a
     *  genuinely different, rarer case — an order with an upsell tag but neither
     *  flag set — and that fallback has its own known edge case letting a
     *  Restocking-status order slip through, so it isn't safe to blindly copy
     *  here). Use realUpsell()/scopeRealUpsell() for a query builder, this for
     *  an already-fetched Order/Collection.
     *
     *  Also includes is_upsell_on_voided_order (2026-08-22) — a Canceled-
     *  status order (Pancake status_code 6) whose upsell genuinely happened,
     *  confirmed live on order #1353632. Deliberately scoped to Canceled
     *  only, not every VOID_STATUSES code: Deleted-recently (7) means the
     *  order no longer exists in Pancake at all (see VOID_STATUSES' own doc
     *  comment), and Partial-return (15) has no confirmed real-world case
     *  yet — extend this the same evidence-first way if one turns up. */
    public static function isRealUpsell(self $order): bool
    {
        return $order->is_upsell || $order->is_returned_upsell || $order->is_upsell_on_voided_order;
    }

    /** Query-builder counterpart to isRealUpsell() above — same definition,
     *  usable directly in a ->where()/->sum()/->count() chain instead of having
     *  to ->get() first just to filter in PHP. */
    public function scopeRealUpsell($query)
    {
        return $query->where(fn ($q) => $q->where('is_upsell', true)
            ->orWhere('is_returned_upsell', true)
            ->orWhere('is_upsell_on_voided_order', true));
    }

    /**
     * The "broad" real-upsell definition isRealUpsell()'s own doc comment
     * deliberately excludes — is_upsell OR is_returned_upsell, PLUS a bare
     * Order::hasUpsellTag($raw_tags) tag-text fallback for an order whose
     * is_upsell was forced false purely by VOID_STATUSES (e.g. a genuinely
     * tagged upsell sitting in Restocking status). Centralized here
     * (2026-08-21) — this exact closure used to be hand-copied in
     * ProductPerformance::tally(), ProductPerformance::ordersForColumn(),
     * and TsaPerformanceController's per-TSA breakdown, each commented
     * "kept in sync by hand"; the excluded_upsell_seller gate below
     * (root-caused same day, order #1352836 — an "UPSELL TSD - ..." tag
     * applied under a known non-TSA account, see
     * config/excluded_upsell_sellers.php) needed adding to all three at
     * once, which is exactly the drift risk those comments warned about.
     */
    public static function isBroadRealUpsell(self $order): bool
    {
        if ($order->excluded_upsell_seller) return false;

        return $order->is_upsell
            || $order->is_returned_upsell
            || $order->is_upsell_on_voided_order
            || (!$order->is_cancelled_upsell && self::hasUpsellTag($order->raw_tags ?? []));
    }

    /** The correct isolated add-on price for a row ProductPerformance::tally()'s
     *  (and TsaPerformanceController::buildRow()'s identical) $isRealUpsell
     *  fallback branch recovers — the "known edge case" flagged in isRealUpsell()'s
     *  own doc comment above, now actually fixed. 'amount' already holds the
     *  isolated add-on price for is_upsell/is_returned_upsell rows (see
     *  SyncTodayOrders::flushOrders()), but a Restocking-status order recovered
     *  only via the bare hasUpsellTag() fallback has 'amount' sitting at the raw
     *  order total instead — the isolated figure lives in restocking_upsell_amount
     *  instead (see SyncTodayOrders' $isRestockingUpsell branch). Confirmed live
     *  (2026-08-13, order #1348150, Julie): a ₱1,800 Clear Sight + Lumicare Oil/
     *  Haplunas order in Restocking status showed ₱1,800 upsell revenue in the
     *  Per-Product Hourly Breakdown instead of the real ₱1,000 add-on value,
     *  already sitting correctly in restocking_upsell_amount the whole time. */
    public function realUpsellAmount(): float
    {
        return $this->is_restocking_upsell ? (float) $this->restocking_upsell_amount : (float) $this->amount;
    }

    /**
     * True when an order's tags say it's an upsell but only ONE item remains on
     * it — the add-on was removed (upsell cancelled), and that sole item is NOT
     * itself the add-on. Shared by SyncTodayOrders (applied at sync time) and
     * ReconcileOrderStatuses (re-checks orders already marked is_upsell=true
     * against Pancake's current live data, catching cases synced before this
     * detection existed or before a later edit removed the add-on).
     *
     * Handles every upsell-tag naming convention seen in production:
     *   "UPSELL TSD - Base + Addon"  — sole item matching the named BASE proves
     *                                  the add-on half was removed.
     *   "UPSELL TSD - Addon"         — that name IS the add-on (no base named);
     *   "Upsell TSD (Addon)"           sole item NOT matching it means the
     *                                  add-on itself is missing.
     *
     * Confirmed in production (order #1341487): most real upsell tags use the
     * single-name forms, not the "Base + Addon" one — a ₱500 order whose only
     * remaining item was the base "Sinuxyl", tagged "UPSELL TSD - Sinuxyl
     * Inhaler" (the add-on), kept counting as a live upsell for months before
     * this handled that format too.
     */
    public static function remainingItemIsJustTheBase(array $raw): bool
    {
        $items = $raw['items'] ?? [];
        if (count($items) !== 1) return false;

        $tags     = $raw['tags'] ?? [];
        $tagNames = array_map(fn($t) => \is_array($t) ? ($t['name'] ?? '') : (string)$t, $tags);

        $vi   = $items[0]['variation_info'] ?? [];
        $name = strtoupper(preg_replace('/[^A-Z0-9]/i', '', $vi['name'] ?? $items[0]['product_name'] ?? ''));
        if ($name === '') return false;

        foreach ($tagNames as $tag) {
            if (preg_match('/(?:UPSELL\s+TSD|TSD\s+UPSELL)\s*-\s*(.+)/i', $tag, $m)) {
                $parts = array_map('trim', explode('+', $m[1]));

                if (count($parts) >= 2) {
                    $base = strtoupper(preg_replace('/[^A-Z0-9]/i', '', $parts[0]));
                    if ($base !== '' && str_contains($name, $base)) {
                        return true;
                    }
                    continue;
                }

                $addon = strtoupper(preg_replace('/[^A-Z0-9]/i', '', $parts[0]));
                if ($addon !== '' && !str_contains($name, $addon)) {
                    return true;
                }
                continue;
            }

            if (preg_match('/UPSELL\s+TSD\s*\(([^)]+)\)/i', $tag, $m)) {
                $addon = strtoupper(preg_replace('/[^A-Z0-9]/i', '', $m[1]));
                if ($addon !== '' && !str_contains($name, $addon)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * The order's isolated upsell add-on price — NEVER total_price, which
     * includes the customer's original item too (Fix #2, moved here
     * 2026-08-10 from SyncTodayOrders so ReconcileOrderStatuses' amount-
     * refresh pass — reconcileUpsellAmounts() — computes the exact same
     * value sync time did, not a reimplementation that could drift from it).
     *
     * A single remaining item (base was voided) IS the add-on, unless
     * remainingItemIsJustTheBase() proves it's actually the reverse (the
     * add-on itself was removed, leaving the original — see that method's
     * own docblock, Fix #8). Multiple items: a "(Product Name)" tag names
     * the exact add-on by name (Fix #7 — Pancake doesn't guarantee the
     * add-on is listed last); otherwise every item after index 0 is summed,
     * assuming positional order (see findItemIndexByTagHint()'s own
     * docblock for when this positional fallback is genuinely ambiguous).
     *
     * Explicit request (2026-08-12), confirmed live on order #1347336
     * (Joana): remainingItemIsJustTheBase() is deliberately skipped here
     * when the order carries the SEPARATE PARCEL tag — same reasoning as
     * SyncTodayOrders' is_cancelled_upsell check (see that assignment's own
     * comment). This is the single source of truth for is_upsell's amount
     * at BOTH sync time and ReconcileOrderStatuses::reconcileUpsellAmounts()
     * (per the "moved here" note above), so fixing it here alone keeps a
     * split-parcel order's amount from being zeroed at sync, or from
     * drifting back to 0 on the next amount-reconcile pass.
     */
    public static function extractUpsellAmount(array $raw): float
    {
        $items = $raw['items'] ?? [];

        if (count($items) < 2) {
            $tags     = $raw['tags'] ?? [];
            $tagNames = array_map(fn ($t) => \is_array($t) ? ($t['name'] ?? '') : (string) $t, $tags);

            if (self::remainingItemIsJustTheBase($raw) && !self::hasSeparateParcelTag($tagNames)) {
                return 0.0;
            }
            $vi = $items[0]['variation_info'] ?? [];
            return (float) ($vi['retail_price'] ?? $raw['total_price'] ?? $raw['cod'] ?? 0);
        }

        $hintIndex = self::findItemIndexByTagHint($raw);
        if ($hintIndex !== null) {
            $vi    = $items[$hintIndex]['variation_info'] ?? [];
            $price = (float) ($vi['retail_price'] ?? 0);
            $qty   = (int) ($items[$hintIndex]['quantity'] ?? 1);
            return $price * $qty;
        }

        $upsellAmount = 0.0;
        foreach (\array_slice($items, 1) as $item) {
            $vi    = $item['variation_info'] ?? [];
            $price = (float) ($vi['retail_price'] ?? 0);
            $qty   = (int) ($item['quantity'] ?? 1);
            $upsellAmount += $price * $qty;
        }

        // Never fall back to total_price — that includes item 0 (original
        // product). If retail_price is missing/zero on every upsell item,
        // return 0 rather than inflating the total with the base's value.
        return $upsellAmount;
    }

    /**
     * "Upsell TSD (Product Name)" tags name one exact product — find the
     * item whose name matches it rather than trusting item array order
     * (order #1325787 had the customer's original repeat item listed AFTER
     * the actual TSA upsell, which a bare index-1 assumption records
     * backwards). Returns null when no such tag exists, in which case
     * extractUpsellAmount() falls back to its positional assumption.
     */
    public static function findItemIndexByTagHint(array $raw): ?int
    {
        $tags     = $raw['tags'] ?? [];
        $tagNames = array_map(fn ($t) => \is_array($t) ? ($t['name'] ?? '') : (string) $t, $tags);

        $hint = null;
        foreach ($tagNames as $tag) {
            if (preg_match('/UPSELL\s+TSD\s*\(([^)]+)\)/i', $tag, $m)) {
                $hint = trim($m[1]);
                break;
            }
        }
        if ($hint === null) return null;

        $items    = $raw['items'] ?? [];
        $hintNorm = strtoupper(preg_replace('/[^A-Z0-9]/i', '', $hint));

        foreach ($items as $i => $item) {
            $vi       = $item['variation_info'] ?? [];
            $name     = $vi['name'] ?? $item['product_name'] ?? '';
            $nameNorm = strtoupper(preg_replace('/[^A-Z0-9]/i', '', $name));
            if ($nameNorm !== '' && str_contains($nameNorm, $hintNorm)) {
                return $i;
            }
        }

        return null;
    }

    /**
     * True when Pancake's own histories log shows this order's item list has
     * only ever contained one DISTINCT product, ever — not just currently.
     * remainingItemIsJustTheBase() above only recognizes two specific tag
     * phrasings ("UPSELL TSD - Base + Addon" and "Upsell TSD (Addon)"); a tag
     * like "TSD UPSELL SCAR CREAM" (no dash, no parens) names a product that,
     * for a genuine remains-after-void order, is definitionally identical to
     * the sole remaining item's own name — so no name-based check can ever
     * tell that case apart from an order that was simply mistagged despite
     * never having a real base-plus-addon relationship at all. History can:
     * a genuine void leaves a removed item whose variation_id never resurfaces;
     * a same-item price/edit correction does not. Confirmed live (order
     * #1341759, 2026-08-07): tagged "TSD UPSELL SCAR CREAM", sole item "Scar
     * Cream" — the only items history entry is that exact variation_id being
     * removed and re-added at a corrected price (₱499 -> ₱500), never a
     * different product. Counted as a live ₱500 upsell for days despite never
     * being one. Only meaningful with a live per-order lookup (needs
     * `histories`, which the regular per-page sync never fetches) — see
     * ReconcileOrderStatuses::reconcileStaleUpsellTags(), which already pulls
     * the full order for its own check and can reuse the same response here.
     */
    public static function historyShowsOnlyOneDistinctItemEverExisted(array $raw): bool
    {
        $ids = collect($raw['items'] ?? [])->pluck('variation_id')->filter()->values()->all();

        foreach ($raw['histories'] ?? [] as $history) {
            foreach ($history['items'] ?? [] as $change) {
                if (($change['old']['variation_info'] ?? null) !== null) {
                    $ids[] = $change['variation_id'] ?? null;
                }
            }
        }

        return count(array_unique(array_filter($ids))) <= 1;
    }

    /**
     * True when Pancake's items history shows a DIFFERENT product (not the
     * current sole item) was genuinely added and later fully removed, while
     * the current sole item's own variation_id was never touched at all —
     * meaning it's been on the order since creation (the real base), and the
     * add-then-removed item was the genuine upsell add-on that got
     * cancelled. Distinct from historyShowsOnlyOneDistinctItemEverExisted()
     * above, which catches the OPPOSITE failure (no second product ever
     * existed at all) — this one catches a genuine base+addon relationship
     * whose tag just doesn't fit either phrasing remainingItemIsJustTheBase()
     * recognizes. Confirmed live (order #1342174, 2026-08-07): tagged "TSD
     * UPSELL - GINSENG SERUM", naming the BASE product in the slot that
     * phrasing normally reserves for the addon (a tagging mistake, not a
     * parsing gap) — so the name-based check concluded the genuinely-base
     * "Ginseng Serum" that remained WAS the addon, when the real addon (Belo
     * Set, ₱1200, added then fully removed) is what the history shows.
     */
    public static function historyShowsADifferentItemWasAddedThenRemoved(array $raw): bool
    {
        $items = $raw['items'] ?? [];
        if (count($items) !== 1) return false;

        $currentId = $items[0]['variation_id'] ?? null;
        if ($currentId === null) return false;

        $currentItemTouched = false;
        $otherAdded   = false;
        $otherRemoved = false;

        foreach ($raw['histories'] ?? [] as $history) {
            foreach ($history['items'] ?? [] as $change) {
                if (($change['variation_id'] ?? null) === $currentId) {
                    $currentItemTouched = true;
                    continue;
                }
                if (($change['old']['variation_info'] ?? null) === null && ($change['new']['variation_info'] ?? null) !== null) {
                    $otherAdded = true;
                }
                if (($change['old']['variation_info'] ?? null) !== null && ($change['new']['variation_info'] ?? null) === null) {
                    $otherRemoved = true;
                }
            }
        }

        return !$currentItemTouched && $otherAdded && $otherRemoved;
    }

    public function getIsVoidStatusAttribute(): bool
    {
        return in_array($this->status_code, self::VOID_STATUSES, true);
    }

    /** Pancake's real order-creation date when we have it, falling back to the
     *  business-adjusted "worked at" timestamp for older rows synced before
     *  pancake_inserted_at existed (see that column's migration). Leads Report
     *  uses this so its per-day totals match what POS's own Created-At filter
     *  would show; TSA Performance/Charts intentionally keep reading
     *  pancake_created_at directly instead — see SyncTodayOrders::resolveWorkedAt(). */
    public function getEffectiveCreatedAtAttribute(): ?\Illuminate\Support\Carbon
    {
        return $this->pancake_inserted_at ?? $this->pancake_created_at;
    }
}
