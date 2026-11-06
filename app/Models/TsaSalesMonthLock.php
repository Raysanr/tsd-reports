<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

/**
 * A closed month (explicit request, 2026-11-06): Summary Sales Report figures
 * are recalculated from live orders and costs, so a past month kept shifting.
 * Locking saves that month's figures once; from then on its days read from the
 * saved copy, here and on Overall Sales Summary, until someone unlocks it.
 * Each section is keyed "<id or team>:<Y-m-d>", like the maps it replaces.
 */
class TsaSalesMonthLock extends Model
{
    protected $fillable = ['month', 'snapshot', 'locked_by'];

    protected $casts = ['snapshot' => 'array'];

    public static function isLocked(string $date): bool
    {
        return self::where('month', substr($date, 0, 7))->exists();
    }

    /** $live with every locked month's days swapped for the saved section. */
    public static function overlay(Collection $live, string $section, string $dateFrom, string $dateTo): Collection
    {
        $locks = self::whereIn('month', self::monthsBetween($dateFrom, $dateTo))->get();
        if ($locks->isEmpty()) {
            return $live;
        }

        $months = $locks->pluck('month')->all();
        $result = $live->reject(fn ($value, $key) => in_array(substr((string) $key, -10, 7), $months, true));
        foreach ($locks as $lock) {
            foreach ($lock->snapshot[$section] ?? [] as $key => $value) {
                $date = substr((string) $key, -10);
                if ($date >= $dateFrom && $date <= $dateTo) {
                    $result->put($key, $value);
                }
            }
        }

        return $result;
    }

    private static function monthsBetween(string $dateFrom, string $dateTo): array
    {
        $months = [];
        for ($month = substr($dateFrom, 0, 7); $month <= substr($dateTo, 0, 7); $month = date('Y-m', strtotime("{$month}-01 +1 month"))) {
            $months[] = $month;
        }

        return $months;
    }
}
