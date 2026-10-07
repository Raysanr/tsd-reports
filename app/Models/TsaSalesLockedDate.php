<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** One locked date on Summary Sales Report's own daily-entry table
 *  (explicit request, 2026-10-07: "add lock icon like in the dsppr") —
 *  existence of a row for a given entry_date means that date is locked;
 *  no row means unlocked. Same mechanism as DsPprLockedDate — see its own
 *  doc comment / the create_tsa_sales_locked_dates_table migration's own
 *  doc comment for why this is a standalone table. */
class TsaSalesLockedDate extends Model
{
    protected $fillable = ['entry_date'];

    protected $casts = [
        'entry_date' => 'date',
    ];
}
