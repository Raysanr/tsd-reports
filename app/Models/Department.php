<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** TSD Archiving System — one node of the org structure tree. */
class Department extends Model
{
    /** Tailwind palette names a department can be tagged with. Kept as
     *  full literal class strings so Tailwind's scanner emits every one. */
    public const COLORS = [
        'sky'     => ['dot' => 'bg-sky-500',     'soft' => 'bg-sky-50 text-sky-700 ring-sky-200 dark:bg-sky-500/10 dark:text-sky-300 dark:ring-sky-500/30',             'bar' => 'from-sky-500 to-sky-400', 'band' => 'border-sky-500', 'ring' => 'ring-sky-500'],
        'indigo'  => ['dot' => 'bg-indigo-500',  'soft' => 'bg-indigo-50 text-indigo-700 ring-indigo-200 dark:bg-indigo-500/10 dark:text-indigo-300 dark:ring-indigo-500/30', 'bar' => 'from-indigo-500 to-indigo-400', 'band' => 'border-indigo-500', 'ring' => 'ring-indigo-500'],
        'emerald' => ['dot' => 'bg-emerald-500', 'soft' => 'bg-emerald-50 text-emerald-700 ring-emerald-200 dark:bg-emerald-500/10 dark:text-emerald-300 dark:ring-emerald-500/30', 'bar' => 'from-emerald-500 to-emerald-400', 'band' => 'border-emerald-500', 'ring' => 'ring-emerald-500'],
        'amber'   => ['dot' => 'bg-amber-500',   'soft' => 'bg-amber-50 text-amber-700 ring-amber-200 dark:bg-amber-500/10 dark:text-amber-300 dark:ring-amber-500/30',     'bar' => 'from-amber-500 to-amber-400', 'band' => 'border-amber-500', 'ring' => 'ring-amber-500'],
        'rose'    => ['dot' => 'bg-rose-500',    'soft' => 'bg-rose-50 text-rose-700 ring-rose-200 dark:bg-rose-500/10 dark:text-rose-300 dark:ring-rose-500/30',           'bar' => 'from-rose-500 to-rose-400', 'band' => 'border-rose-500', 'ring' => 'ring-rose-500'],
        'violet'  => ['dot' => 'bg-violet-500',  'soft' => 'bg-violet-50 text-violet-700 ring-violet-200 dark:bg-violet-500/10 dark:text-violet-300 dark:ring-violet-500/30', 'bar' => 'from-violet-500 to-violet-400', 'band' => 'border-violet-500', 'ring' => 'ring-violet-500'],
        'slate'   => ['dot' => 'bg-slate-500',   'soft' => 'bg-slate-100 text-slate-700 ring-slate-200 dark:bg-slate-500/10 dark:text-slate-300 dark:ring-slate-500/30',   'bar' => 'from-slate-500 to-slate-400', 'band' => 'border-slate-500', 'ring' => 'ring-slate-500'],
    ];

    protected $fillable = ['parent_id', 'name', 'code', 'team_slug', 'chart_x', 'chart_y', 'description', 'color', 'head_employee_id', 'sort_order', 'archived_at'];

    protected $casts = ['archived_at' => 'datetime'];

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id')->orderBy('sort_order')->orderBy('name');
    }

    public function head(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'head_employee_id');
    }

    public function employees(): HasMany
    {
        return $this->hasMany(Employee::class);
    }

    public function documents(): HasMany
    {
        return $this->hasMany(ArchiveDocument::class);
    }

    public function scopeActive(Builder $q): Builder
    {
        return $q->whereNull('archived_at');
    }

    public function colorClasses(): array
    {
        return self::COLORS[$this->color] ?? self::COLORS['sky'];
    }

    /** Ids of this department and every department below it — used to stop
     *  a department being moved under its own descendant (a cycle). */
    public function descendantIds(): array
    {
        $ids = [$this->id];
        $frontier = [$this->id];
        while ($frontier) {
            $frontier = self::whereIn('parent_id', $frontier)->pluck('id')->all();
            $ids = array_merge($ids, $frontier);
        }

        return $ids;
    }
}
