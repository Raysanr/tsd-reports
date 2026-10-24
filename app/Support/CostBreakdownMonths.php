<?php

namespace App\Support;

use App\Models\CostBreakdownPool;
use App\Models\CostBreakdownRole;
use App\Models\CostBreakdownTsaEntry;
use App\Models\Setting;
use Illuminate\Support\Facades\DB;

/** Monthly Cost Breakdown (explicit request, 2026-10-15). Each month that
 *  someone "added" owns its own full set of roles / pools / TSA entries /
 *  table locks. A month with no set of its own uses effective(): the latest
 *  EARLIER month that has one, else the earliest month there is — so a past
 *  day never changes when a later month is added or edited, and a future
 *  month keeps using the latest real figures until someone adds it. */
class CostBreakdownMonths
{
    public static function isValid(?string $month): bool
    {
        return is_string($month) && preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $month) === 1;
    }

    public static function current(): string
    {
        return now('Asia/Manila')->format('Y-m');
    }

    /** Every month with its own saved Cost Breakdown, ascending. Pools are
     *  the marker — every month always has the full fixed pool list. */
    public static function existing(): array
    {
        return RequestMemo::remember('cost_breakdown.months', fn () => CostBreakdownPool::query()
            ->distinct()->orderBy('month')->pluck('month')->all());
    }

    public static function exists(string $month): bool
    {
        return in_array($month, self::existing(), true);
    }

    /** The month whose figures apply to $month (see class doc comment).
     *  Seeds the current month on a completely empty install. */
    public static function effective(string $month): string
    {
        $months = self::existing();
        if ($months === []) {
            self::seed(self::current());
            $months = self::existing();
        }

        $earlier = array_filter($months, fn ($m) => $m <= $month);

        return $earlier !== [] ? max($earlier) : min($months);
    }

    public static function effectiveForDate(string $date): string
    {
        return self::effective(substr($date, 0, 7));
    }

    /** Fresh default seed for one month (empty install / tests). */
    public static function seed(string $month): void
    {
        CostBreakdownRole::ensureSeeded($month);
        CostBreakdownPool::ensureSeeded($month);
        CostBreakdownTsaEntry::ensureSeeded($month);
        RequestMemo::flush();
    }

    /** "Add Cost Breakdown" for $month — a copy of the month it currently
     *  falls back to (explicit decision, 2026-10-15: "Copy last month").
     *  No-op if $month already has its own set. Locks start unlocked. */
    public static function create(string $month): void
    {
        if (self::exists($month)) {
            return;
        }
        $source = self::effective($month);

        DB::transaction(function () use ($source, $month) {
            foreach ([CostBreakdownRole::class, CostBreakdownPool::class, CostBreakdownTsaEntry::class, \App\Models\CostBreakdownProductFlag::class] as $model) {
                foreach ($model::where('month', $source)->get() as $row) {
                    $copy = $row->replicate();
                    $copy->month = $month;
                    $copy->save();
                }
            }
        });
        RequestMemo::flush();
    }

    public static function lockKey(string $table, string $month): string
    {
        return "cost_breakdown_lock_{$table}_{$month}";
    }

    public static function isLocked(string $table, string $month): bool
    {
        return Setting::get(self::lockKey($table, $month)) === '1';
    }
}
