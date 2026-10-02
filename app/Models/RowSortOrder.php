<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** One row key's own display position within its section ('selling' or
 *  'operating') — see the create_row_sort_orders_table migration's own
 *  doc comment for why this is a separate table from
 *  ProjectionCustomRow, and App\Support\RowOrder for the class that
 *  actually reads/writes this. */
class RowSortOrder extends Model
{
    protected $fillable = ['row_key', 'section', 'sort_order'];

    protected $casts = [
        'sort_order' => 'integer',
    ];
}
