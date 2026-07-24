<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CallRecordingHour extends Model
{
    protected $fillable = ['tsa_key', 'date', 'hour', 'total_seconds', 'call_count', 'synced_at'];

    protected $casts = [
        'date'      => 'date',
        'synced_at' => 'datetime',
    ];
}
