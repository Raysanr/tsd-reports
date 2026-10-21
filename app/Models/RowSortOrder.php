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
    protected $fillable = ['month', 'row_key', 'section', 'sort_order'];

    /** Rows belong to one Projections month (2026-10-21) — every query is
     *  scoped to ProjectionMonth::current(), and new rows get that month. */
    protected static function booted(): void
    {
        static::addGlobalScope('month', fn ($q) => $q->where($q->getModel()->getTable() . '.month', \App\Support\ProjectionMonth::current()));
        static::creating(fn ($row) => $row->month ??= \App\Support\ProjectionMonth::current());
    }

    protected $casts = [
        'sort_order' => 'integer',
    ];
}
