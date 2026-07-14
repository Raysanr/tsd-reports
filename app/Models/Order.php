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
        'amount',
        'raw_tags',
        'is_upsell',
        'is_cancelled_upsell',
        'cancelled_upsell_amount',
        'is_returned_upsell',
        'returned_upsell_amount',
        'status_code',
        'pancake_created_at',
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

    public function getIsVoidStatusAttribute(): bool
    {
        return in_array($this->status_code, self::VOID_STATUSES, true);
    }
}
