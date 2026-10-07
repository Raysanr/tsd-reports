<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** One locked date on DSPPR's daily-entry table (explicit request,
 *  2026-10-07: "i want to make it per date") — existence of a row for a
 *  given entry_date means that date is locked; no row means unlocked. See
 *  the create_dsppr_locked_dates_table migration's own doc comment for
 *  why this is a standalone table rather than a column on DsPprEntry. */
class DsPprLockedDate extends Model
{
    // Explicit override — Eloquent's default snake_case pluralization of
    // "DsPprLockedDate" splits "DsPpr" into "ds_ppr" (not "dsppr"), same
    // mismatch DsPprTiktokEntry's own doc comment already documents for
    // this app's "dsppr_*" naming convention.
    protected $table = 'dsppr_locked_dates';

    protected $fillable = ['entry_date'];

    protected $casts = [
        'entry_date' => 'date',
    ];
}
