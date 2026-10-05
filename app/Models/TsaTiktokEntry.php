<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One real TsaShift's raw numbers for one day, TikTok Upsell section —
 *  see the create_tsa_tiktok_entries_table migration's own doc comment
 *  for why every field here is manual (unlike TsaSalesEntry's own
 *  automated total_orders/catered_leads/pickup_rate/upselling_rate). */
class TsaTiktokEntry extends Model
{
    protected $fillable = [
        'tsa_shift_id', 'entry_date', 'gross_sales', 'net_income',
        'total_orders', 'catered_leads', 'pickup_rate', 'upselling_rate',
    ];

    protected $casts = [
        'entry_date'     => 'date',
        'gross_sales'    => 'float',
        'net_income'     => 'float',
        'total_orders'   => 'integer',
        'catered_leads'  => 'integer',
        'pickup_rate'    => 'float',
        'upselling_rate' => 'float',
    ];

    public function tsa(): BelongsTo
    {
        return $this->belongsTo(TsaShift::class, 'tsa_shift_id');
    }
}
