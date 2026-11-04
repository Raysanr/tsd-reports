<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Archiving System — one coaching session or QA scorecard for a person. */
class CoachingRecord extends Model
{
    public const TYPES = [
        'coaching' => ['label' => 'Coaching',     'badge' => 'bg-yellow-50 text-yellow-800 ring-yellow-200 dark:bg-yellow-500/10 dark:text-yellow-300 dark:ring-yellow-500/30'],
        'qa'       => ['label' => 'QA scorecard', 'badge' => 'bg-sky-50 text-sky-700 ring-sky-200 dark:bg-sky-500/10 dark:text-sky-300 dark:ring-sky-500/30'],
    ];

    protected $fillable = ['employee_id', 'type', 'session_date', 'coach_id', 'topic', 'reference', 'score', 'notes', 'action_plan', 'acknowledged_at'];

    protected $casts = ['session_date' => 'date', 'acknowledged_at' => 'datetime', 'score' => 'float'];

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function coach(): BelongsTo
    {
        return $this->belongsTo(User::class, 'coach_id');
    }

    /** Score pill color: 90+ green, 75+ amber, below that red. */
    public function scoreClasses(): string
    {
        return match (true) {
            $this->score === null => 'bg-slate-100 text-slate-600 dark:bg-slate-800 dark:text-slate-300',
            $this->score >= 90    => 'bg-emerald-50 text-emerald-700 dark:bg-emerald-500/10 dark:text-emerald-300',
            $this->score >= 75    => 'bg-amber-50 text-amber-700 dark:bg-amber-500/10 dark:text-amber-300',
            default               => 'bg-rose-50 text-rose-700 dark:bg-rose-500/10 dark:text-rose-300',
        };
    }
}
