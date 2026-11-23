<?php

namespace App\Support;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Lock closed months in Data Management (2026-11-23). A locked month can't be
 * edited on any Data Management page (Projections, DSPPR, TSA Sales, Expected /
 * Actual Income, Cost Breakdown) — enforced for every save by
 * App\Http\Middleware\DataManagementMonthLock. Separate per dataset (TSD vs
 * Partners), stored on the main database either way.
 */
class DmMonthLock
{
    public static function dataset(): string
    {
        return DataSet::isPartners() ? 'partners' : 'data';
    }

    private static function table(): Builder
    {
        return DB::connection(DataSet::mainConnection())->table('dm_month_locks')->where('dataset', self::dataset());
    }

    public static function isLocked(?string $month): bool
    {
        if ($month === null) {
            return false;
        }
        $locked = RequestMemo::remember('dm-month-locks:' . self::dataset(), fn () => self::table()->pluck('month')->all());

        return in_array($month, $locked, true);
    }

    /** @return Collection<int, object{month: string, locked_by: ?int, created_at: string}> newest first */
    public static function all(): Collection
    {
        return self::table()->orderByDesc('month')->get();
    }

    public static function lock(string $month, ?int $userId): void
    {
        DB::connection(DataSet::mainConnection())->table('dm_month_locks')->updateOrInsert(
            ['dataset' => self::dataset(), 'month' => $month],
            ['locked_by' => $userId, 'created_at' => now(), 'updated_at' => now()]
        );
        RequestMemo::flush();
    }

    public static function unlock(string $month): void
    {
        self::table()->where('month', $month)->delete();
        RequestMemo::flush();
    }

    public static function label(string $month): string
    {
        return \Illuminate\Support\Carbon::createFromFormat('Y-m-d', $month . '-01')->format('F Y');
    }
}
