<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Archiving System — one disciplinary record (NTE, warning, suspension…). */
class Incident extends Model
{
    public const TYPES = [
        'verbal_warning'  => 'Verbal warning',
        'nte'             => 'Notice to Explain (NTE)',
        'written_warning' => 'Written warning',
        'final_warning'   => 'Final warning',
        'suspension'      => 'Suspension',
        'attendance'      => 'Attendance violation',
    ];

    public const STATUSES = [
        'open'     => ['label' => 'Awaiting reply', 'badge' => 'bg-amber-50 text-amber-700 ring-amber-200 dark:bg-amber-500/10 dark:text-amber-300 dark:ring-amber-500/30'],
        'replied'  => ['label' => 'Replied',        'badge' => 'bg-sky-50 text-sky-700 ring-sky-200 dark:bg-sky-500/10 dark:text-sky-300 dark:ring-sky-500/30'],
        'resolved' => ['label' => 'Resolved',       'badge' => 'bg-emerald-50 text-emerald-700 ring-emerald-200 dark:bg-emerald-500/10 dark:text-emerald-300 dark:ring-emerald-500/30'],
    ];

    /** Severity tint for the type pill. */
    public const SEVERITY = [
        'verbal_warning' => 'bg-slate-100 text-slate-700 dark:bg-slate-800 dark:text-slate-300',
        'attendance'     => 'bg-slate-100 text-slate-700 dark:bg-slate-800 dark:text-slate-300',
        'nte'            => 'bg-amber-100 text-amber-800 dark:bg-amber-500/15 dark:text-amber-300',
        'written_warning'=> 'bg-orange-100 text-orange-800 dark:bg-orange-500/15 dark:text-orange-300',
        'final_warning'  => 'bg-rose-100 text-rose-800 dark:bg-rose-500/15 dark:text-rose-300',
        'suspension'     => 'bg-rose-600 text-white',
    ];

    protected $fillable = [
        'employee_id', 'type', 'incident_date', 'title', 'description', 'reply_due_on', 'reply',
        'replied_on', 'decision', 'status', 'resolved_on', 'created_by',
    ];

    protected $casts = ['incident_date' => 'date', 'reply_due_on' => 'date', 'replied_on' => 'date', 'resolved_on' => 'date'];

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    /** Files attached to this incident (2026-10-22). */
    public function attachments(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(ArchiveDocument::class)->latest();
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function isOverdue(): bool
    {
        return $this->status === 'open' && $this->reply_due_on && $this->reply_due_on->isPast() && ! $this->reply_due_on->isToday();
    }
}
