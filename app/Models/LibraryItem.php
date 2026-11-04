<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Archiving System — a call script, rebuttal, product sheet or FAQ. */
class LibraryItem extends Model
{
    public const KINDS = [
        'script'   => ['label' => 'Call script',   'badge' => 'bg-yellow-50 text-yellow-800 ring-yellow-200 dark:bg-yellow-500/10 dark:text-yellow-300 dark:ring-yellow-500/30'],
        'rebuttal' => ['label' => 'Rebuttal',      'badge' => 'bg-rose-50 text-rose-700 ring-rose-200 dark:bg-rose-500/10 dark:text-rose-300 dark:ring-rose-500/30'],
        'product'  => ['label' => 'Product sheet', 'badge' => 'bg-emerald-50 text-emerald-700 ring-emerald-200 dark:bg-emerald-500/10 dark:text-emerald-300 dark:ring-emerald-500/30'],
        'faq'      => ['label' => 'FAQ',           'badge' => 'bg-sky-50 text-sky-700 ring-sky-200 dark:bg-sky-500/10 dark:text-sky-300 dark:ring-sky-500/30'],
    ];

    protected $fillable = ['kind', 'folder', 'title', 'product_id', 'department_id', 'body', 'version', 'updated_by', 'archived_at'];

    protected $casts = ['archived_at' => 'datetime'];

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class)->withTrashed();
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    public function editor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    public function versions(): HasMany
    {
        return $this->hasMany(LibraryVersion::class)->orderByDesc('version');
    }
}
