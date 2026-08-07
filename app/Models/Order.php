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
        'team',
        'tsa_name',
        'disposition',
        'product',
        'base_product',
        'bundle_description',
        'amount',
        'raw_tags',
        'is_upsell',
        'is_cancelled_upsell',
        'cancelled_upsell_amount',
        'is_returned_upsell',
        'returned_upsell_amount',
        'is_restocking_upsell',
        'restocking_upsell_amount',
        'status_code',
        'pancake_created_at',
        'pancake_inserted_at',
        'pancake_updated_at',
        'synced_at',
    ];

    protected $casts = [
        'raw_tags'                => 'array',
        'is_upsell'               => 'boolean',
        'is_cancelled_upsell'     => 'boolean',
        'is_returned_upsell'      => 'boolean',
        'is_restocking_upsell'    => 'boolean',
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
     *  an already-fetched Order/Collection. */
    public static function isRealUpsell(self $order): bool
    {
        return $order->is_upsell || $order->is_returned_upsell;
    }

    /** Query-builder counterpart to isRealUpsell() above — same definition,
     *  usable directly in a ->where()/->sum()/->count() chain instead of having
     *  to ->get() first just to filter in PHP. */
    public function scopeRealUpsell($query)
    {
        return $query->where(fn ($q) => $q->where('is_upsell', true)->orWhere('is_returned_upsell', true));
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
