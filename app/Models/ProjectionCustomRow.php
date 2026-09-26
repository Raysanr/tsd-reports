<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** One user-added row DEFINITION under Selling And Marketing or Operating
 *  Costs, shared across every Projections column — see the
 *  create_projection_custom_rows_table migration's own doc comment for why
 *  this only stores the row's shape (label/section/is_fixed), never a
 *  dollar value: each column's own figure for this row is a %-of-Gross-
 *  Sales rate in Settings (key "projection_rate.{$key}"), exactly like a
 *  built-in row. */
class ProjectionCustomRow extends Model
{
    protected $fillable = ['key', 'section', 'label', 'is_fixed', 'sort_order'];

    protected $casts = [
        'is_fixed'   => 'boolean',
        'sort_order' => 'integer',
    ];
}
