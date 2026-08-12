<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Ported from call-tracker (merged into one app 2026-08-12). */
class LeadActivity extends Model
{
    public $timestamps = false;

    protected $fillable = ['lead_id', 'type', 'description', 'amount', 'user_id', 'created_at'];

    protected $casts = [
        'created_at' => 'datetime',
        'amount'     => 'decimal:2',
    ];

    public function lead(): BelongsTo
    {
        return $this->belongsTo(Lead::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** $amount is only ever set for 'upsell_added' (LeadController::
     *  addUpsell()) — a real number the Dashboard can sum for "today's
     *  upsells," distinct from $description's human-readable sentence. */
    public static function log(Lead $lead, string $type, string $description, ?User $user = null, ?float $amount = null): void
    {
        static::create([
            'lead_id'     => $lead->id,
            'type'        => $type,
            'description' => $description,
            'amount'      => $amount,
            'user_id'     => $user?->id,
            'created_at'  => now(),
        ]);
    }
}
