<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Ported from call-tracker (merged into one app 2026-08-12). */
class RoundRobinState extends Model
{
    protected $fillable = ['product_id', 'last_tsa_id'];

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function lastTsa(): BelongsTo
    {
        return $this->belongsTo(TsaShift::class, 'last_tsa_id');
    }
}
