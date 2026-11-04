<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Collection;

/** A subfolder inside a TSD Documented Process folder (2026-10-21). */
class ProcessSubfolder extends Model
{
    protected $fillable = ['folder', 'parent_id', 'name', 'created_by'];

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id');
    }

    public function documents(): HasMany
    {
        return $this->hasMany(ArchiveDocument::class, 'process_subfolder_id');
    }

    /** This subfolder and every one above it, top first (bounded walk). */
    public function trail(): Collection
    {
        $trail = collect([$this]);
        for ($p = $this->parent; $p !== null && $trail->count() < 50; $p = $p->parent) {
            $trail->prepend($p);
        }

        return $trail;
    }

    /** "Scripts / 2026" — the path inside its Documented Process folder. */
    public function path(): string
    {
        return $this->trail()->pluck('name')->join(' / ');
    }
}
