<?php

namespace App\Support;

/**
 * Philippine national holidays for the Hub calendar (explicit request,
 * 2026-11-17: "can display still the holidays fixed"). Shown to everyone,
 * never editable.
 *
 * Fixed-date ones every year; Holy Week from Easter; National Heroes Day on
 * the last Monday of August. Chinese New Year and the two Eids move with the
 * lunar calendars and are proclaimed each year, so they come from the table
 * below — add a year there when its proclamation is out.
 */
class PhHolidays
{
    private const FIXED = [
        '01-01' => ["New Year's Day", 'regular'],
        '02-25' => ['EDSA People Power Anniversary', 'special'],
        '04-09' => ['Araw ng Kagitingan', 'regular'],
        '05-01' => ['Labor Day', 'regular'],
        '06-12' => ['Independence Day', 'regular'],
        '08-21' => ['Ninoy Aquino Day', 'special'],
        '11-01' => ["All Saints' Day", 'special'],
        '11-02' => ["All Souls' Day", 'special'],
        '11-30' => ['Bonifacio Day', 'regular'],
        '12-08' => ['Feast of the Immaculate Conception', 'special'],
        '12-24' => ['Christmas Eve', 'special'],
        '12-25' => ['Christmas Day', 'regular'],
        '12-30' => ['Rizal Day', 'regular'],
        '12-31' => ["New Year's Eve", 'special'],
    ];

    /** Lunar-calendar holidays by year (dates as proclaimed / expected). */
    private const LUNAR = [
        2025 => ['01-29' => ['Chinese New Year', 'special'], '04-01' => ["Eid'l Fitr", 'regular'], '06-06' => ["Eid'l Adha", 'regular']],
        2026 => ['02-17' => ['Chinese New Year', 'special'], '03-20' => ["Eid'l Fitr", 'regular'], '05-27' => ["Eid'l Adha", 'regular']],
        2027 => ['02-06' => ['Chinese New Year', 'special'], '03-10' => ["Eid'l Fitr", 'regular'], '05-17' => ["Eid'l Adha", 'regular']],
        2028 => ['01-26' => ['Chinese New Year', 'special'], '02-27' => ["Eid'l Fitr", 'regular'], '05-05' => ["Eid'l Adha", 'regular']],
    ];

    /** ['Y-m-d' => [['name' => ..., 'type' => 'regular'|'special'], ...]] for $years. */
    public static function forYears(array $years): array
    {
        $out = [];
        foreach ($years as $year) {
            foreach (self::forYear((int) $year) as $date => $holiday) {
                $out[$date][] = $holiday;
            }
        }
        ksort($out);

        return $out;
    }

    private static function forYear(int $year): array
    {
        $list = [];
        $add = function (string $date, string $name, string $type) use (&$list) {
            $list[] = [$date, ['name' => $name, 'type' => $type]];
        };

        foreach (self::FIXED as $md => [$name, $type]) {
            $add("{$year}-{$md}", $name, $type);
        }
        foreach (self::LUNAR[$year] ?? [] as $md => [$name, $type]) {
            $add("{$year}-{$md}", $name, $type);
        }

        $easter = self::easter($year);
        $add($easter->copy()->subDays(3)->toDateString(), 'Maundy Thursday', 'regular');
        $add($easter->copy()->subDays(2)->toDateString(), 'Good Friday', 'regular');
        $add($easter->copy()->subDay()->toDateString(), 'Black Saturday', 'special');

        $heroes = \Illuminate\Support\Carbon::create($year, 8, 31);
        while (!$heroes->isMonday()) {
            $heroes->subDay();
        }
        $add($heroes->toDateString(), 'National Heroes Day', 'regular');

        $byDate = [];
        foreach ($list as [$date, $holiday]) {
            $byDate[$date] = $holiday;
        }

        return $byDate;
    }

    /** Western Easter Sunday (anonymous Gregorian algorithm). */
    private static function easter(int $year): \Illuminate\Support\Carbon
    {
        $a = $year % 19;
        $b = intdiv($year, 100);
        $c = $year % 100;
        $d = intdiv($b, 4);
        $e = $b % 4;
        $f = intdiv($b + 8, 25);
        $g = intdiv($b - $f + 1, 3);
        $h = (19 * $a + $b - $d - $g + 15) % 30;
        $i = intdiv($c, 4);
        $k = $c % 4;
        $l = (32 + 2 * $e + 2 * $i - $h - $k) % 7;
        $m = intdiv($a + 11 * $h + 22 * $l, 451);
        $month = intdiv($h + $l - 7 * $m + 114, 31);
        $day = (($h + $l - 7 * $m + 114) % 31) + 1;

        return \Illuminate\Support\Carbon::create($year, $month, $day);
    }
}
