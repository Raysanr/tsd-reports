<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A Hub announcement (2026-11-23). Shows until its last day (ends_on) or until removed. */
class Announcement extends Model
{
    protected $fillable = ['title', 'body', 'important', 'ends_on', 'created_by'];

    protected $casts = ['important' => 'boolean', 'ends_on' => 'date'];

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function scopeShowing(Builder $q): Builder
    {
        return $q->where(fn ($w) => $w->whereNull('ends_on')->orWhereDate('ends_on', '>=', today()));
    }
}
