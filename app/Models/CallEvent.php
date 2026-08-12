<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Ported from call-tracker (merged into one app 2026-08-12). */
class CallEvent extends Model
{
    protected $fillable = ['tsa_id', 'lead_id', 'phone_number', 'direction', 'duration_seconds', 'occurred_at'];

    protected $casts = [
        'occurred_at' => 'datetime',
    ];

    public function tsa(): BelongsTo
    {
        return $this->belongsTo(TsaShift::class, 'tsa_id');
    }

    public function lead(): BelongsTo
    {
        return $this->belongsTo(Lead::class);
    }

    /** Strips everything but digits and keeps the last 10 — a Philippine
     *  mobile number reported by a phone's own call log (e.g. "09171234567")
     *  and the same number as stored on a Lead (e.g. "+639171234567" or
     *  "0917 123 4567") normalize to the identical value this way, letting
     *  matchLead() compare them reliably regardless of formatting. */
    public static function normalizePhone(?string $number): ?string
    {
        if (!$number) return null;
        $digits = preg_replace('/\D/', '', $number);
        return $digits !== '' ? substr($digits, -10) : null;
    }
}
