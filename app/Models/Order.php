<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Order extends Model
{
    protected $fillable = [
        'pancake_order_id',
        'team',
        'tsa_name',
        'disposition',
        'product',
        'amount',
        'raw_tags',
        'is_upsell',
        'status_code',
        'pancake_created_at',
        'synced_at',
    ];

    protected $casts = [
        'raw_tags'           => 'array',
        'is_upsell'          => 'boolean',
        'amount'             => 'decimal:2',
        'pancake_created_at' => 'datetime',
        'synced_at'          => 'datetime',
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

    public function getStatusLabelAttribute(): ?string
    {
        return self::STATUS_LABELS[$this->status_code] ?? null;
    }

    public function getIsVoidStatusAttribute(): bool
    {
        return in_array($this->status_code, self::VOID_STATUSES, true);
    }
}
