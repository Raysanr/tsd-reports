<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** TSD Archiving System — one person in the people directory. */
class Employee extends Model
{
    public const STATUSES = [
        'active'   => ['label' => 'Active',   'badge' => 'bg-emerald-50 text-emerald-700 ring-emerald-200 dark:bg-emerald-500/10 dark:text-emerald-300 dark:ring-emerald-500/30'],
        'resigned' => ['label' => 'Resigned', 'badge' => 'bg-slate-100 text-slate-600 ring-slate-200 dark:bg-slate-500/10 dark:text-slate-300 dark:ring-slate-500/30'],
    ];

    protected $fillable = [
        'department_id', 'manager_id', 'user_id', 'tsa_shift_id', 'full_name', 'position',
        'email', 'phone', 'status', 'hired_at', 'left_at', 'notes', 'chart_x', 'chart_y', 'photo_path',
        'birthday', 'employment_status', 'shift_schedule', 'manual_status',
    ];

    protected $casts = ['hired_at' => 'date', 'left_at' => 'date', 'birthday' => 'date'];

    /** Manpower sheet's STATUS dropdown, same colors as the sheet (2026-10-21).
     *  'former' = picking it moves the person to Former TSAs. */
    public const EMPLOYMENT_STATUSES = [
        'trainee'    => ['label' => 'Trainee',    'pill' => 'bg-blue-800 text-white',          'former' => false],
        'probi'      => ['label' => 'Probi',      'pill' => 'bg-amber-200 text-amber-900',     'former' => false],
        'regular'    => ['label' => 'Regular',    'pill' => 'bg-purple-200 text-purple-900',   'former' => false],
        'tenured'    => ['label' => 'Tenured',    'pill' => 'bg-green-800 text-white',         'former' => false],
        'terminated' => ['label' => 'Terminated', 'pill' => 'bg-slate-700 text-white',         'former' => true],
        'resigned'   => ['label' => 'Resigned',   'pill' => 'bg-red-700 text-white',           'former' => true],
    ];

    public const SHIFT_SCHEDULES = ['opening' => 'Opening', 'midshift' => 'Midshift', 'closing' => 'Closing'];

    /** Shift schedule chip colors on the People table (2026-10-22). */
    public const SHIFT_PILLS = [
        'opening'  => 'bg-amber-100 text-amber-800 dark:bg-amber-500/15 dark:text-amber-300',
        'midshift' => 'bg-sky-100 text-sky-800 dark:bg-sky-500/15 dark:text-sky-300',
        'closing'  => 'bg-indigo-100 text-indigo-800 dark:bg-indigo-500/15 dark:text-indigo-300',
    ];

    protected static function booted(): void
    {
        // departments.head_employee_id has no DB FK (see its migration).
        static::deleting(function (self $e) {
            Department::where('head_employee_id', $e->id)->update(['head_employee_id' => null]);
            if ($e->photo_path) {
                \Illuminate\Support\Facades\Storage::disk(ArchiveDocument::DISK)->delete($e->photo_path);
            }
            // org_links has no FK (it's shared by two node types)
            OrgLink::where('type', 'employee')->where(fn ($q) => $q->where('parent_id', $e->id)->orWhere('child_id', $e->id))->delete();
        });

        // Lifecycle timeline: record joins and team/manager/position/status
        // changes automatically, however they happen (form, drag-drop, sync).
        static::created(function (self $e) {
            $e->events()->create([
                'type' => 'hired',
                'title' => 'Joined' . ($e->department ? ' ' . $e->department->name : ' the team') . ($e->position ? " as {$e->position}" : ''),
                'occurred_on' => $e->hired_at ?? today(),
                'user_id' => auth()->id(),
            ]);
        });
        static::updated(fn (self $e) => $e->recordLifecycleChanges());
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    public function manager(): BelongsTo
    {
        return $this->belongsTo(self::class, 'manager_id');
    }

    public function reports(): HasMany
    {
        return $this->hasMany(self::class, 'manager_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function tsa(): BelongsTo
    {
        return $this->belongsTo(TsaShift::class, 'tsa_shift_id');
    }

    public function documents(): HasMany
    {
        return $this->hasMany(ArchiveDocument::class);
    }

    public function events(): HasMany
    {
        return $this->hasMany(EmployeeEvent::class);
    }

    public function incidents(): HasMany
    {
        return $this->hasMany(Incident::class)->latest('incident_date');
    }

    private function recordLifecycleChanges(): void
    {
        $log = fn (string $type, string $title, $on = null) => $this->events()->create([
            'type' => $type, 'title' => $title, 'occurred_on' => $on ?? today(), 'user_id' => auth()->id(),
        ]);

        if ($this->wasChanged('department_id')) {
            $from = Department::find($this->getOriginal('department_id'))?->name;
            $to = $this->department_id ? Department::find($this->department_id)?->name : null;
            $log('team', $to ? 'Moved to ' . $to . ($from ? " (from {$from})" : '') : 'Removed from ' . ($from ?? 'team'));
        }
        if ($this->wasChanged('manager_id')) {
            $to = $this->manager_id ? self::find($this->manager_id)?->full_name : null;
            $log('manager', $to ? "Now reports to {$to}" : 'No longer reports to anyone');
        }
        if ($this->wasChanged('position') && $this->position) {
            $from = $this->getOriginal('position');
            $log('position', ($from ? "{$from} → " : 'Position: ') . $this->position);
        }
        if ($this->wasChanged('status')) {
            $from = $this->getOriginal('status');
            match (true) {
                $this->status === 'resigned' => $log('resigned', 'Resigned', $this->left_at),
                $from === 'resigned'         => $log('status', 'Reinstated'),
                default                      => null,
            };
        }
    }

    public function coachingRecords(): HasMany
    {
        return $this->hasMany(CoachingRecord::class)->latest('session_date');
    }

    public function scopeCurrent(Builder $q): Builder
    {
        return $q->where('status', '!=', 'resigned');
    }

    /** Cache-busted URL of the profile photo, or null when there isn't one. */
    public function photoUrl(): ?string
    {
        return $this->photo_path
            ? route('archive.people.photo', ['employee' => $this, 'v' => substr(md5($this->photo_path), 0, 8)])
            : null;
    }

    public function initials(): string
    {
        $parts = preg_split('/\s+/', trim($this->full_name)) ?: [];
        $first = mb_substr($parts[0] ?? '?', 0, 1);
        $last  = count($parts) > 1 ? mb_substr(end($parts), 0, 1) : '';

        return mb_strtoupper($first . $last);
    }

    public function statusMeta(): array
    {
        return self::STATUSES[$this->status] ?? self::STATUSES['active'];
    }
}
