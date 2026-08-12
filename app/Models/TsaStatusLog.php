<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Ported from call-tracker (merged into one app 2026-08-12). */
class TsaStatusLog extends Model
{
    public $timestamps = false;

    protected $fillable = ['tsa_id', 'status', 'created_at'];

    protected $casts = ['created_at' => 'datetime'];

    public function tsa(): BelongsTo
    {
        return $this->belongsTo(TsaShift::class, 'tsa_id');
    }

    public static function log(TsaShift $tsa, string $status): void
    {
        static::create(['tsa_id' => $tsa->id, 'status' => $status, 'created_at' => now()]);
    }
}
