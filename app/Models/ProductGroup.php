<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/** A display-only combining of 2+ real Products (explicit request,
 *  2026-09-26: drag TO-01 onto TO-02, name the combo "TO") — see the
 *  create_product_groups_table migration's own doc comment for why this
 *  never touches the real Product table itself, only how
 *  DsPprReportController/ExpectedIncomeController render their own rows. */
class ProductGroup extends Model
{
    protected $fillable = ['label', 'sort_order'];

    protected $casts = [
        'sort_order' => 'integer',
    ];

    public function products(): BelongsToMany
    {
        return $this->belongsToMany(Product::class, 'product_group_members');
    }
}
