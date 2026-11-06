<?php

namespace App\Support;

use App\Models\Scopes\TsaMonthScope;
use App\Models\TsaMonthSetting;
use App\Models\TsaShift;
use Illuminate\Support\Facades\Schema;

/** Shift per month (explicit request, 2026-11-03). Each month someone
 *  "added" on TSA Management owns its own copy of every TSA's team, shift
 *  hours, rest day, TikTok flag and report eye, so editing November never rewrites
 *  October's reports. Same month rules as CostBreakdownMonths: a month with
 *  no set of its own uses the latest EARLIER month that has one, else the
 *  earliest month there is. Until the first month is added nothing changes
 *  — TsaShift's own columns are used everywhere, exactly as before.
 *
 *  Reads go through TsaMonthScope (every TsaShift query sees the month's
 *  values); writes go through write(). */
class TsaMonths
{
    public const FIELDS = ['team', 'shift_start', 'shift_end', 'rest_day_of_week', 'tiktok_upsell', 'is_hidden'];

    private static ?\WeakMap $tableExists = null;

    public static function current(): string
    {
        return now('Asia/Manila')->format('Y-m');
    }

    public static function isValid(?string $month): bool
    {
        return is_string($month) && preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $month) === 1;
    }

    /** Every month with its own saved set, ascending. */
    public static function existing(): array
    {
        if (!self::tableExists()) {
            return [];
        }

        $connection = (new TsaMonthSetting)->getConnectionName() ?? config('database.default');

        return RequestMemo::remember('tsa_months.existing.' . $connection, fn () => TsaMonthSetting::query()
            ->distinct()->orderBy('month')->pluck('month')->all());
    }

    public static function exists(string $month): bool
    {
        return in_array($month, self::existing(), true);
    }

    /** The month whose set applies to $month, or null before any month was added. */
    public static function effective(string $month): ?string
    {
        $months = self::existing();
        if ($months === []) {
            return null;
        }

        $earlier = array_filter($months, fn ($m) => $m <= $month);

        return $earlier !== [] ? max($earlier) : min($months);
    }

    /** The month TsaShift reads resolve against: TSA Management's selected
     *  month, else a report page's range start (TsaShift::showDeletedFrom()),
     *  else this month (live pages, syncs, round-robin). */
    public static function contextMonth(): string
    {
        if (app()->bound('request')) {
            $viewing = app('request')->attributes->get('tsa_month');
            if ($viewing) {
                return $viewing;
            }
        }

        $from = TsaShift::rosterFrom();

        return $from ? substr($from, 0, 7) : self::current();
    }

    /** TSA Management shows and edits $month's values for the rest of this request. */
    public static function view(string $month): void
    {
        if (app()->bound('request')) {
            app('request')->attributes->set('tsa_month', $month);
        }
    }

    /** "Add Shift Per Month" — $month gets a copy of the set it currently
     *  falls back to. The very first add also saves the month before it from
     *  today's values, so every earlier month stays exactly as it is now. */
    public static function create(string $month): void
    {
        if (self::exists($month)) {
            return;
        }

        $first = self::existing() === [];
        $source = self::effective($month);
        $shifts = self::liveShifts();

        if ($first) {
            $previous = \Illuminate\Support\Carbon::createFromFormat('Y-m', $month)->startOfMonth()->subMonthNoOverflow()->format('Y-m');
            self::saveRows($previous, $shifts->map(fn (TsaShift $s) => self::liveValues($s)));
        }

        $sourceRows = $source ? TsaMonthSetting::where('month', $source)->get()->keyBy('tsa_shift_id') : collect();
        self::saveRows($month, $shifts->map(fn (TsaShift $s) => $sourceRows->has($s->id)
            ? $sourceRows->get($s->id)->only(self::FIELDS)
            : self::liveValues($s)));

        RequestMemo::flush();
    }

    /** Saves $values for one TSA. Month fields go to $month's own set
     *  (created from its fallback first if needed) and, for the current
     *  month, to the TSA's own columns too; every other field is saved on
     *  the TSA directly. Before any month exists, everything is saved on the
     *  TSA, as before. */
    public static function write(TsaShift $shift, array $values, ?string $month = null): void
    {
        $month ??= self::current();
        $monthValues = array_intersect_key($values, array_flip(self::FIELDS));
        $other = array_diff_key($values, $monthValues);

        $live = TsaShift::withoutGlobalScope(TsaMonthScope::class)->withTrashed()->findOrFail($shift->id);

        if ($monthValues === [] || self::existing() === []) {
            $live->update($values);
            $shift->forceFill($values)->syncOriginal();

            return;
        }

        self::create($month);

        // A month that has no row for her yet keeps what she has today,
        // before her own columns change below.
        foreach (self::existing() as $m) {
            if ($m !== $month) {
                TsaMonthSetting::firstOrCreate(['month' => $m, 'tsa_shift_id' => $live->id], self::liveValues($live));
            }
        }

        $row = TsaMonthSetting::firstOrNew(['month' => $month, 'tsa_shift_id' => $live->id]);
        if (!$row->exists) {
            $row->fill(self::liveValues($live));
        }
        $row->fill($monthValues)->save();

        $liveUpdate = $other + ($month === self::effective(self::current()) ? $monthValues : []);
        if ($liveUpdate !== []) {
            $live->update($liveUpdate);
        }

        $shift->forceFill($values)->syncOriginal();
        RequestMemo::flush();
    }

    /** A new TSA wasn't working in the months already saved, so she stays off
     *  those months' reports. Months with no set of their own follow these. */
    public static function hideFromEarlierMonths(TsaShift $shift): void
    {
        foreach (self::existing() as $month) {
            if ($month < self::current()) {
                TsaMonthSetting::updateOrCreate(
                    ['month' => $month, 'tsa_shift_id' => $shift->id],
                    ['is_hidden' => true] + self::liveValues($shift)
                );
            }
        }
        RequestMemo::flush();
    }

    /** False only before the migration has run (deploy order, or the
     *  partners database not migrated yet); a true result is cached per
     *  live connection. */
    public static function tableExists(): bool
    {
        $pdo = \Illuminate\Support\Facades\DB::connection()->getPdo();
        self::$tableExists ??= new \WeakMap();

        if (!isset(self::$tableExists[$pdo]) && Schema::hasTable('tsa_month_settings')) {
            self::$tableExists[$pdo] = true;
        }

        return self::$tableExists[$pdo] ?? false;
    }

    private static function liveShifts()
    {
        return TsaShift::withoutGlobalScope(TsaMonthScope::class)->withTrashed()->get()->keyBy('id');
    }

    private static function liveValues(TsaShift $shift): array
    {
        return [
            'team'             => $shift->getRawOriginal('team'),
            'shift_start'      => $shift->getRawOriginal('shift_start'),
            'shift_end'        => $shift->getRawOriginal('shift_end'),
            'rest_day_of_week' => $shift->getRawOriginal('rest_day_of_week'),
            'tiktok_upsell'    => (bool) $shift->getRawOriginal('tiktok_upsell'),
            'is_hidden'        => (bool) $shift->getRawOriginal('is_hidden'),
        ];
    }

    private static function saveRows(string $month, $valuesById): void
    {
        foreach ($valuesById as $id => $values) {
            TsaMonthSetting::updateOrCreate(['month' => $month, 'tsa_shift_id' => $id], $values);
        }
    }
}
