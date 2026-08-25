<?php

namespace App\Models;

use App\Support\LogoutLeadRedistributor;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class TsaShift extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'tsa_key', 'pos_user_id', 'display_name', 'team', 'tag_keywords', 'seller_keywords',
        'shift_start', 'shift_end', 'sort_order', 'rest_day_of_week',
        // Ported from call-tracker's own `Tsa` model (merged into one app
        // 2026-08-12) — see the add_call_tracker_columns_to_tsa_shifts_table
        // migration.
        'phone_number', 'dialer_host', 'api_token', 'active',
        'status', 'status_changed_at', 'status_locked_by', 'daily_lead_cap',
    ];

    protected $casts = [
        'active' => 'boolean',
        'status_changed_at' => 'datetime',
    ];

    /** Real-time availability states a TSA switches between via the topbar
     *  dropdown — distinct from `active` (an admin-controlled, rarely-
     *  changed "is this TSA enabled at all" flag). Only 'login' makes a TSA
     *  eligible for round-robin assignment — see RoundRobinAssigner::next().
     *  LOCKED mirrors Pancake's own conversation-receive-mode "Lock" option
     *  — admin-only to set, and while set, the TSA's own topbar dropdown
     *  can't change it away (see TsaStatusController::update()'s guard). */
    public const STATUS_LOGIN       = 'login';
    public const STATUS_CALLING     = 'calling';
    public const STATUS_WRAP_UP     = 'wrap_up';
    public const STATUS_BREAK       = 'break';
    public const STATUS_LUNCH       = 'lunch';
    public const STATUS_DNA_HUDDLE  = 'dna_huddle';
    public const STATUS_HUDDLE      = 'huddle';
    public const STATUS_COACHING    = 'coaching';
    public const STATUS_OTHERS      = 'others';
    public const STATUS_LOGOUT      = 'logout';
    public const STATUS_LOCKED      = 'locked';

    /** Every real status, in display order — icon key used by the status
     *  panel partial to render Pancake's own icon-per-row look. Huddle is a
     *  distinct status from DNA Huddle, not a rename of it (explicit
     *  request, 2026-08-19) — a plain/generic team huddle, sitting right
     *  before Logout. Logout also gets its own 'logout' icon now instead of
     *  sharing 'away' with Break/Coaching/DNA Huddle/Huddle, matching the
     *  slate (not amber) dot color this same partial already gives it
     *  below.
     *
     *  Calling/Wrap Up/Lunch/Others added for Monitor TSA (explicit request,
     *  2026-08-20). Calling and Wrap Up are both system-only — Calling is
     *  set the moment a lead's phone number is clicked
     *  (LeadController::logCallClick()), Wrap Up when that call ends (see
     *  applyStatusChange()'s callers — CallEventController on a real call
     *  ending, and the ExpireWrapUpStatuses command 60s later) — neither is
     *  ever a button a TSA or admin clicks, and TsaStatusController::update()
     *  explicitly rejects a manual attempt to set either one. */
    public const STATUSES = [
        self::STATUS_LOGIN      => ['label' => 'Login',      'description' => 'Ready to receive round-robin leads',            'icon' => 'available'],
        self::STATUS_CALLING    => ['label' => 'Calling',    'description' => 'On a call right now — set automatically when a lead\'s number is clicked, not clickable', 'icon' => 'available'],
        self::STATUS_WRAP_UP    => ['label' => 'Wrap Up',    'description' => 'After-call wrap-up — set automatically, not clickable', 'icon' => 'wrap_up'],
        self::STATUS_BREAK      => ['label' => 'Break',      'description' => "Stepped away, can't receive leads right now",  'icon' => 'break'],
        self::STATUS_LUNCH      => ['label' => 'Lunch',      'description' => "On lunch, can't receive leads",                 'icon' => 'lunch'],
        self::STATUS_COACHING   => ['label' => 'Coaching',   'description' => "In a coaching session, can't receive leads",    'icon' => 'coaching'],
        self::STATUS_DNA_HUDDLE => ['label' => 'DNA Huddle', 'description' => "In a team huddle, can't receive leads",         'icon' => 'dna_huddle'],
        self::STATUS_HUDDLE     => ['label' => 'Huddle',     'description' => "In a huddle, can't receive leads",              'icon' => 'huddle'],
        self::STATUS_OTHERS     => ['label' => 'Others',     'description' => 'Away for any other reason',                     'icon' => 'others'],
        self::STATUS_LOGOUT     => ['label' => 'Logout',     'description' => "Shift ended, can't receive leads",              'icon' => 'logout'],
        self::STATUS_LOCKED     => ['label' => 'Lock',       'description' => 'Admin feature — lock a TSA out of receiving leads and changing this status', 'icon' => 'lock'],
    ];

    /** Options a TSA can pick for THEMSELVES on the topbar dropdown — every
     *  real status except Lock, which only an admin can set. Also reused
     *  by the Leads tab's admin-facing status control (explicit request,
     *  2026-08-20: this used to be TSA Management's own per-row dropdown,
     *  moved here so an admin can change whichever TSA they've selected
     *  right where they're already working, instead of a separate page —
     *  same component, options, and target=<tsa id> shape either way, see
     *  leads/index.blade.php). Monitor TSA stays a pure live display — its
     *  own button grid was removed the same day once it became clear every
     *  status change already shows up there automatically, via the same
     *  tsa_shifts.status column, within its own poll.
     *
     *  Lunch and Others added (explicit request, 2026-08-20) — Logout stays
     *  last, same position it's always had. */
    public const SELF_SERVICE_STATUSES = [
        self::STATUS_LOGIN, self::STATUS_BREAK, self::STATUS_LUNCH, self::STATUS_COACHING,
        self::STATUS_DNA_HUDDLE, self::STATUS_HUDDLE, self::STATUS_OTHERS, self::STATUS_LOGOUT,
    ];

    /** Every status Monitor TSA's legend row, summary count cards, and each
     *  card's own "Daily minute record" list show a live count/duration
     *  for — including Calling and Wrap Up, which a TSA can end up in even
     *  though neither is ever set by hand (see STATUSES' own doc comment
     *  above). */
    public const MONITOR_LEGEND_STATUSES = [
        self::STATUS_LOGIN, self::STATUS_CALLING, self::STATUS_WRAP_UP, self::STATUS_BREAK, self::STATUS_LUNCH,
        self::STATUS_COACHING, self::STATUS_DNA_HUDDLE, self::STATUS_HUDDLE, self::STATUS_OTHERS,
    ];


    public function restDays()
    {
        return $this->hasMany(TsaRestDay::class);
    }

    public function statusLockedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'status_locked_by');
    }

    public function products(): BelongsToMany
    {
        return $this->belongsToMany(Product::class, 'product_tsa', 'tsa_id', 'product_id')->withPivot('position');
    }

    public function leads(): HasMany
    {
        return $this->hasMany(Lead::class, 'tsa_id');
    }

    /** How many leads round-robin has assigned this TSA today — computed
     *  live off leads.assigned_at rather than a stored counter, so the cap
     *  resets itself at midnight with no scheduled job needed. Always the
     *  REAL today, regardless of what range Leads Setup's own date picker
     *  (see leadsAssignedBetween() below) happens to be showing — this is
     *  what RoundRobinAssigner::next() actually enforces, so it can never be
     *  pointed at a different day/range. */
    public function leadsAssignedToday(): int
    {
        return $this->leads()->whereDate('assigned_at', today())->count();
    }

    /** Same idea as leadsAssignedToday(), for an arbitrary date range — Leads
     *  Setup's own date picker (explicit request, 2026-08-24; upgraded from a
     *  single date to a real two-calendar range the same day, "like the
     *  Dashboard"), for reviewing a past day/range's assignment volume
     *  against the TSA's current cap. $from/$to are both inclusive whole
     *  days regardless of whatever time-of-day they carry in. Never used for
     *  actual round-robin enforcement (that's always leadsAssignedToday()/
     *  hasReachedDailyCap(), hardcoded to today) — this is a read-only
     *  historical view only. */
    public function leadsAssignedBetween(\Illuminate\Support\Carbon $from, \Illuminate\Support\Carbon $to): int
    {
        return $this->leads()
            ->whereBetween('assigned_at', [$from->copy()->startOfDay(), $to->copy()->endOfDay()])
            ->count();
    }

    /** Whether round-robin should skip this TSA for the rest of today —
     *  null daily_lead_cap means unlimited, see RoundRobinAssigner::next(). */
    public function hasReachedDailyCap(): bool
    {
        return $this->daily_lead_cap !== null && $this->leadsAssignedToday() >= $this->daily_lead_cap;
    }

    public function statusLogs(): HasMany
    {
        return $this->hasMany(TsaStatusLog::class, 'tsa_id')->orderByDesc('created_at')->orderByDesc('id');
    }

    /** The one write path for changing a TSA's current status — updates the
     *  denormalized status/status_changed_at/status_locked_by columns AND
     *  appends a TsaStatusLog row, together, every time. Extracted
     *  (2026-08-20) so a manual change (TsaStatusController::update()) and
     *  the two automatic ones Monitor TSA introduces (CallEventController
     *  flipping Calling -> Wrap Up when a call ends, ExpireWrapUpStatuses
     *  flipping Wrap Up -> Login 60s later) can never drift apart on how a
     *  status change is actually recorded — Analytics' own per-status
     *  duration math (TsaStatusLog::secondsByStatus()) depends on every
     *  change showing up here, automatic or not. */
    public function applyStatusChange(string $status, ?int $lockedByUserId = null): void
    {
        $wasLoggedOut = $this->status === self::STATUS_LOGOUT;

        $this->update([
            'status'            => $status,
            'status_changed_at' => now(),
            'status_locked_by'  => $lockedByUserId,
        ]);
        TsaStatusLog::log($this, $status);

        // "Smart rotation" (explicit request, 2026-08-25) — a fresh
        // transition INTO logout (not an already-logged-out TSA getting a
        // redundant logout write) hands off whatever's still uncalled in
        // this TSA's queue to her teammates — see
        // LogoutLeadRedistributor's own doc comment for the full reasoning.
        if ($status === self::STATUS_LOGOUT && !$wasLoggedOut) {
            LogoutLeadRedistributor::redistribute($this);
        }
    }

    public function callEvents(): HasMany
    {
        return $this->hasMany(CallEvent::class, 'tsa_id');
    }

    public function callRecordings(): HasMany
    {
        return $this->hasMany(CallRecording::class, 'tsa_id');
    }

    /** A fresh random secret for this TSA's phone-side call automation
     *  (MacroDroid) to authenticate its webhook with. Not saved here; the
     *  caller decides when to persist it (e.g. only on an explicit
     *  "Regenerate" action, not as a side effect of an unrelated update). */
    public static function generateApiToken(): string
    {
        return bin2hex(random_bytes(24));
    }

    /**
     * Whether this TSA is off on $date. An explicit tsa_rest_days row (either an
     * extra day off, or an override back to working) always wins over the
     * recurring rule; otherwise falls back to whether $date's weekday matches
     * rest_day_of_week.
     *
     * Deliberately does NOT use Collection::firstWhere('date', ...) — the `date`
     * attribute is Carbon-cast, and firstWhere's loose `==` comparison against a
     * plain date string compares Carbon's default __toString() ("Y-m-d H:i:s")
     * against a "Y-m-d" string, which never matches. Compares toDateString()
     * explicitly instead.
     */
    public function isOffOn(\Illuminate\Support\Carbon $date): bool
    {
        $override = $this->restDays->first(
            fn (TsaRestDay $r) => $r->date->toDateString() === $date->toDateString()
        );

        if ($override !== null) {
            return $override->is_off;
        }

        return $this->rest_day_of_week !== null
            && strtolower($date->format('l')) === $this->rest_day_of_week;
    }

    /** Normalizes rest_day_of_week to a lowercase, trimmed full day name (or null)
     *  on write, so isOffOn()'s strtolower(date-weekday) comparison can never be
     *  silently defeated by a caller storing e.g. "Sunday" instead of "sunday". */
    public function setRestDayOfWeekAttribute(?string $value): void
    {
        $this->attributes['rest_day_of_week'] = $value !== null && $value !== ''
            ? strtolower(trim($value))
            : null;
    }

    public function getShiftRangeAttribute(): string
    {
        if (!$this->shift_start && !$this->shift_end) return '—';
        $fmt  = fn($t) => date('g:iA', strtotime($t));
        $parts = array_filter([$this->shift_start, $this->shift_end]);
        return implode(' - ', array_map($fmt, $parts));
    }

    /** Comma-separated tag_keywords -> trimmed array, e.g. "KATH,KATHLEEN" -> ['KATH','KATHLEEN']. */
    public function getTagKeywordsArrayAttribute(): array
    {
        return self::splitKeywords($this->tag_keywords);
    }

    public function getSellerKeywordsArrayAttribute(): array
    {
        return self::splitKeywords($this->seller_keywords);
    }

    /** tag_keywords minus the auto-included base (uppercased tsa_key) — what the
     *  "Also matches" field should show/edit, since the base is always re-added. */
    public function getExtraTagKeywordsAttribute(): string
    {
        $extra = array_diff($this->tag_keywords_array, [strtoupper($this->tsa_key)]);
        return implode(', ', $extra);
    }

    private static function splitKeywords(?string $csv): array
    {
        if (!$csv) return [];
        return array_values(array_filter(array_map('trim', explode(',', $csv))));
    }
}
