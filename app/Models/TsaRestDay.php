<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TsaRestDay extends Model
{
    protected $fillable = ['tsa_shift_id', 'date', 'is_off'];

    protected $casts = [
        'date'   => 'date',
        'is_off' => 'boolean',
    ];

    public function tsaShift()
    {
        return $this->belongsTo(TsaShift::class);
    }
}
