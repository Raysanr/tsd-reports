<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** One named TSA row within a TsaSalesGroup — admin-managed free text,
 *  not bound to a real TsaShift (see create_tsa_sales_rows_table's own
 *  doc comment). */
class TsaSalesRow extends Model
{
    protected $fillable = ['tsa_sales_group_id', 'name', 'sort_order'];

    protected $casts = ['sort_order' => 'integer'];

    public function group(): BelongsTo
    {
        return $this->belongsTo(TsaSalesGroup::class, 'tsa_sales_group_id');
    }

    public function entries(): HasMany
    {
        return $this->hasMany(TsaSalesEntry::class);
    }
}
