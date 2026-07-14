<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TsaRestDay extends Model
{
    protected $fillable = ['tsa_shift_id', 'date', 'is_off'];

    protected $casts = [
        // Explicit :Y-m-d format — without it, Eloquent serializes this attribute
        // for storage using the connection grammar's date format (SQLite's is the
        // full "Y-m-d H:i:s"), so the DB ends up with a spurious time component
        // even though the migration declares a genuine DATE column. Harmless for
        // isOffOn() (which compares via Carbon's toDateString()), but it breaks
        // any exact-string assertDatabaseHas() check against the raw `date` value.
        'date'   => 'date:Y-m-d',
        'is_off' => 'boolean',
    ];

    public function tsaShift()
    {
        return $this->belongsTo(TsaShift::class);
    }
}
