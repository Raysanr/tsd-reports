<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Ported from call-tracker (merged into one app 2026-08-12). */
class Lead extends Model
{
    protected $fillable = [
        'pancake_order_id', 'customer_name', 'phone_number', 'conversation_link',
        'pancake_page_id', 'pancake_conversation_id',
        'product_id', 'tsa_id', 'assigned_at',
        'status', 'disposition', 'callback_at', 'notes', 'called_at', 'called_by_user_id',
        'dialed_at',
        'pancake_created_at', 'synced_at', 'pinned_at',
    ];

    protected $casts = [
        'assigned_at'        => 'datetime',
        'called_at'          => 'datetime',
        'dialed_at'          => 'datetime',
        'callback_at'        => 'datetime',
        'pancake_created_at' => 'datetime',
        'synced_at'          => 'datetime',
        'pinned_at'          => 'datetime',
    ];

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function tsa(): BelongsTo
    {
        return $this->belongsTo(TsaShift::class, 'tsa_id');
    }

    public function calledBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'called_by_user_id');
    }

    public function activities(): HasMany
    {
        return $this->hasMany(LeadActivity::class)->orderBy('created_at');
    }

    /** tel: links need a bare, dialable number — strips everything but
     *  digits and a leading +, so a number stored as e.g. "0917 123 4567"
     *  or "(0917) 123-4567" still produces a working tel: href. */
    public function getDialableNumberAttribute(): ?string
    {
        if (!$this->phone_number) return null;
        $stripped = preg_replace('/[^0-9+]/', '', $this->phone_number);
        return $stripped ?: null;
    }
}
