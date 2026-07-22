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
        'bundle_description',
        'amount',
        'raw_tags',
        'is_upsell',
        'is_cancelled_upsell',
        'cancelled_upsell_amount',
        'is_returned_upsell',
        'returned_upsell_amount',
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
        'amount'                  => 'decimal:2',
        'cancelled_upsell_amount' => 'decimal:2',
        'returned_upsell_amount'  => 'decimal:2',
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
