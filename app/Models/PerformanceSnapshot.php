<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Archiving System — one TSA's frozen numbers for one month. */
class PerformanceSnapshot extends Model
{
    protected $fillable = [
        'tsa_shift_id', 'month', 'tsa_name', 'team', 'leads', 'called', 'upsells',
        'upsell_sales', 'conversion_rate', 'delivered', 'rts', 'generated_by',
    ];

    protected $casts = [
        'month' => 'date', 'upsell_sales' => 'float', 'conversion_rate' => 'float',
        'delivered' => 'float', 'rts' => 'float',
    ];

    public function tsa(): BelongsTo
    {
        return $this->belongsTo(TsaShift::class, 'tsa_shift_id')->withoutGlobalScopes();
    }

    /** Delivered as a share of delivered + RTS, null when neither happened. */
    public function deliveryRate(): ?float
    {
        $base = $this->delivered + $this->rts;

        return $base > 0 ? round($this->delivered / $base * 100, 1) : null;
    }
}
