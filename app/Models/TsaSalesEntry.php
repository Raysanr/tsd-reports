<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One real TsaShift's raw numbers for one day — see the
 *  create_tsa_sales_entries_table migration's own doc comment for which
 *  fields are raw vs. derived. */
class TsaSalesEntry extends Model
{
    protected $fillable = [
        'tsa_shift_id', 'entry_date', 'gross_sales', 'net_income', 'ads_spent',
        'total_orders', 'catered_leads', 'pickup_rate', 'upselling_rate',
    ];

    protected $casts = [
        'entry_date'      => 'date',
        'gross_sales'     => 'float',
        'net_income'      => 'float',
        'ads_spent'       => 'float',
        'total_orders'    => 'integer',
        'catered_leads'   => 'integer',
        'pickup_rate'     => 'float',
        'upselling_rate'  => 'float',
    ];

    public function tsa(): BelongsTo
    {
        return $this->belongsTo(TsaShift::class, 'tsa_shift_id');
    }
}
