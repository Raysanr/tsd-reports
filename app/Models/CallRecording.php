<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Ported from call-tracker (merged into one app 2026-08-12). */
class CallRecording extends Model
{
    protected $fillable = ['tsa_id', 'lead_id', 'call_event_id', 'disk_path', 'original_filename', 'file_size_bytes', 'recorded_at'];

    protected $casts = [
        'recorded_at' => 'datetime',
    ];

    public function tsa(): BelongsTo
    {
        return $this->belongsTo(TsaShift::class, 'tsa_id');
    }

    public function lead(): BelongsTo
    {
        return $this->belongsTo(Lead::class);
    }

    public function callEvent(): BelongsTo
    {
        return $this->belongsTo(CallEvent::class);
    }
}
