<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A Pancake order the lead sync is holding back because it has no
 *  Assignee yet (2026-10-29) — not a Lead until it gets one or its
 *  release_at passes. Shown on Monitor TSA's "Leads waiting" panel.
 *  Rewritten every sync tick; a row the tick no longer holds is removed. */
class PendingAssigneeOrder extends Model
{
    protected $fillable = ['pancake_order_id', 'customer_name', 'product_id', 'pancake_created_at', 'release_at'];

    protected $casts = ['pancake_created_at' => 'datetime', 'release_at' => 'datetime'];

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
