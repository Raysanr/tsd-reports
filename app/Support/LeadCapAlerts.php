<?php

namespace App\Support;

use App\Models\TsaShift;
use Illuminate\Support\Facades\Cache;

/**
 * Active TSAs who have reached today's daily lead limit (Leads Setup), for
 * the "!" warning next to Leads Setup in the Call Tracker sidebar
 * (2026-11-25). Same rule round-robin skips them by
 * (TsaShift::hasReachedDailyCap()). Cached a minute because the sidebar is
 * on every Call Tracker page; saving a limit clears it (forget()).
 */
class LeadCapAlerts
{
    private const TTL_SECONDS = 60;

    /** @return list<string> display names */
    public static function reachedToday(): array
    {
        return Cache::remember(self::key(), self::TTL_SECONDS, fn () => TsaShift::where('active', true)
            ->whereNotNull('daily_lead_cap')
            ->orderBy('sort_order')
            ->get()
            ->filter(fn (TsaShift $tsa) => $tsa->hasReachedDailyCap())
            ->map(fn (TsaShift $tsa) => $tsa->display_name)
            ->values()
            ->all());
    }

    public static function forget(): void
    {
        Cache::forget(self::key());
    }

    private static function key(): string
    {
        return 'lead_cap_alerts:' . today()->toDateString();
    }
}
