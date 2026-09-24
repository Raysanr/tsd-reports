<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** One of the Summary Sales Report's 3 top-level groups (Team Opening
 *  Shift, Team Closing Shift, Tiktok Upsell) — see the
 *  create_tsa_sales_groups_table migration's own doc comment. */
class TsaSalesGroup extends Model
{
    protected $fillable = ['label', 'sort_order'];

    protected $casts = ['sort_order' => 'integer'];

    public function rows(): HasMany
    {
        return $this->hasMany(TsaSalesRow::class)->orderBy('sort_order');
    }
}
