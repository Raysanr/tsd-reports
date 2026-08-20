<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/** Ported from call-tracker (merged into one app 2026-08-12). */
class TsaStatusLog extends Model
{
    public $timestamps = false;

    protected $fillable = ['tsa_id', 'status', 'created_at'];

    protected $casts = ['created_at' => 'datetime'];

    public function tsa(): BelongsTo
    {
        return $this->belongsTo(TsaShift::class, 'tsa_id');
    }

    public static function log(TsaShift $tsa, string $status): void
    {
        static::create(['tsa_id' => $tsa->id, 'status' => $status, 'created_at' => now()]);
    }

    /**
     * How many seconds $tsa spent in each status within [$from, $to] —
     * extracted (2026-08-20) from AnalyticsController's own "Status Time"
     * computation so Monitor TSA's per-TSA/per-day "Daily minute record"
     * can reuse the exact same algorithm instead of a second, possibly
     * drifting implementation. Walks: find whatever status was active AT
     * $from (the most recent log at or before it, or the TSA's current
     * status if there's no history yet), then attribute the time BETWEEN
     * every consecutive log inside the range to whichever status was active
     * during that stretch, clipped to [$from, $to] (or now(), if $to is
     * still in the future — a still-ongoing status shouldn't get credited
     * for time that hasn't happened yet).
     *
     * $carryOverPriorStatus controls what happens BEFORE the first log
     * actually inside [$from, $to] (default true — Analytics' own existing
     * behavior, unchanged): true attributes that stretch to whatever status
     * was last touched before $from, even if that was a prior day/days ago;
     * false (explicit request, 2026-08-20, Monitor TSA's "Daily minute
     * record") leaves it unattributed instead — root-caused live on
     * production: a TSA whose only log today was a single Logout at 5:31pm
     * showed "Break: 17h 31m" for the entire overnight+morning stretch,
     * silently carried over from a status she'd last touched on an earlier
     * day and clearly wasn't actually sitting in for 17 straight hours.
     * "Not resetting daily" was the visible symptom; this carry-over
     * fallback across day boundaries was the actual cause. Analytics keeps
     * the old (default) behavior since a multi-day report genuinely does
     * want to know what a TSA was doing at the start of its own range, even
     * if that's a carried-over status — only Monitor's inherently-"today"
     * view needed the fix.
     *
     * Returns every key in TsaShift::STATUSES, defaulted to 0 — callers can
     * always safely index any status without an isset() check.
     */
    public static function secondsByStatus(TsaShift $tsa, Carbon $from, Carbon $to, bool $carryOverPriorStatus = true): array
    {
        $seconds  = array_fill_keys(array_keys(TsaShift::STATUSES), 0);
        $rangeEnd = $to->isFuture() ? now() : $to;

        if ($carryOverPriorStatus) {
            $priorLog     = static::where('tsa_id', $tsa->id)
                ->where('created_at', '<=', $from)
                ->orderByDesc('created_at')
                ->first();
            $cursorStatus = $priorLog->status ?? $tsa->status;
        } else {
            // null = "unknown, don't attribute yet" — the guards below skip
            // adding any seconds until the first real log inside the range
            // sets this to something real.
            $cursorStatus = null;
        }
        $cursor = $from->copy();

        $logsInRange = static::where('tsa_id', $tsa->id)
            ->whereBetween('created_at', [$from, $to])
            ->orderBy('created_at')
            ->get();

        foreach ($logsInRange as $log) {
            if ($cursorStatus !== null) {
                $seconds[$cursorStatus] = ($seconds[$cursorStatus] ?? 0) + $cursor->diffInSeconds($log->created_at);
            }
            $cursorStatus = $log->status;
            $cursor       = $log->created_at;
        }
        if ($cursorStatus !== null && $cursor->lt($rangeEnd)) {
            $seconds[$cursorStatus] = ($seconds[$cursorStatus] ?? 0) + $cursor->diffInSeconds($rangeEnd);
        }

        return $seconds;
    }
}
