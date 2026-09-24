<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One product's raw numbers for one day on the DSPPR - TSM Report page —
 *  see the create_dsppr_entries_table migration's own doc comment for why
 *  only these 6 fields are stored (every other sheet column is derived by
 *  DsPprCalculator, never persisted). */
class DsPprEntry extends Model
{
    // Laravel's snake_case inference reads "DsPprEntry" as ds_ppr_entries
    // (splitting DS/Ppr as two separate words), not dsppr_entries — stated
    // explicitly so the model always matches the migration's real table
    // name regardless of how the class name gets tokenized.
    protected $table = 'dsppr_entries';

    protected $fillable = [
        'product_id', 'entry_date', 'gross_sales', 'net_income', 'ads_spent',
        'total_orders', 'total_leads', 'catered_leads',
    ];

    protected $casts = [
        'entry_date'    => 'date',
        'gross_sales'   => 'float',
        'net_income'    => 'float',
        'ads_spent'     => 'float',
        'total_orders'  => 'integer',
        'total_leads'   => 'integer',
        'catered_leads' => 'integer',
    ];

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
