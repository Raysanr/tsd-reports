<?php

namespace App\Models;

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
        'status', 'status_changed_at', 'status_locked_by',
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
    public const STATUS_BREAK       = 'break';
    public const STATUS_DNA_HUDDLE  = 'dna_huddle';
    public const STATUS_COACHING    = 'coaching';
    public const STATUS_LOGOUT      = 'logout';
    public const STATUS_LOCKED      = 'locked';

    /** Every real status, in display order — icon key used by the status
     *  panel partial to render Pancake's own icon-per-row look. */
    public const STATUSES = [
        self::STATUS_LOGIN      => ['label' => 'Login',      'description' => 'Ready to receive round-robin leads',            'icon' => 'available'],
        self::STATUS_BREAK      => ['label' => 'Break',      'description' => "Stepped away, can't receive leads right now",  'icon' => 'away'],
        self::STATUS_DNA_HUDDLE => ['label' => 'DNA Huddle', 'description' => "In a team huddle, can't receive leads",         'icon' => 'away'],
        self::STATUS_COACHING   => ['label' => 'Coaching',   'description' => "In a coaching session, can't receive leads",    'icon' => 'away'],
        self::STATUS_LOGOUT     => ['label' => 'Logout',     'description' => "Shift ended, can't receive leads",              'icon' => 'away'],
        self::STATUS_LOCKED     => ['label' => 'Lock',       'description' => 'Admin feature — lock a TSA out of receiving leads and changing this status', 'icon' => 'lock'],
    ];

    /** Options a TSA can pick for THEMSELVES on the topbar dropdown — every
     *  real status except Lock, which only an admin can set. */
    public const SELF_SERVICE_STATUSES = [
        self::STATUS_LOGIN, self::STATUS_BREAK, self::STATUS_DNA_HUDDLE, self::STATUS_COACHING, self::STATUS_LOGOUT,
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

    public function statusLogs(): HasMany
    {
        return $this->hasMany(TsaStatusLog::class, 'tsa_id')->orderByDesc('created_at')->orderByDesc('id');
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
