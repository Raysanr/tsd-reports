<?php

namespace App\Support;

use App\Models\Order;
use App\Models\Product;
use App\Models\TsaShift;
use Illuminate\Support\Collection;

/**
 * TSD Archiving System's TSD Scoreboard (formerly Live TSA Board) — every active, non-hidden TSA's
 * current status plus today's numbers. Read-only. Sales numbers come from
 * the same ProductPerformance::tsaRows()/tally() the Dashboard leaderboard
 * uses (same COALESCE date basis, all teams, hidden products dropped), so
 * this board can't disagree with the Dashboard on what a TSA closed today.
 */
class LiveTsaBoard
{
    /** Pancake "Received" — same Delivered rule as RtsReportController. */
    public const DELIVERED_STATUS = 3;

    /** First month the Scoreboard takes from Data Management. Earlier months
     *  show only Google Sheet figures (scoreboard_imports) or nothing — the
     *  system's own numbers there are costs with no sales (explicit request,
     *  2026-10-21: "Oct onwards … auto from the data management"). */
    public const SYSTEM_FROM = '2026-10-01';

    public const WORKING = [
        TsaShift::STATUS_LOGIN, TsaShift::STATUS_READY_TO_CALL, TsaShift::STATUS_CALLING,
        TsaShift::STATUS_WRAP_UP, TsaShift::STATUS_CALL_BACKS,
    ];

    /** @return Collection<int, array> one row per TSA, working first. */
    /** @param string|null $orderTeam limit to one team (TsaShift::$team value), null = all */
    public static function rows(?string $orderTeam = null): Collection
    {
        $from = now()->startOfDay();
        $to   = now()->endOfDay();

        $shifts = TsaShift::with('user')
            ->where('active', true)
            ->where('is_hidden', false)
            ->when($orderTeam, fn ($q) => $q->where('team', $orderTeam))
            ->orderBy('sort_order')
            ->get();

        $orderTeams = collect(config('teams'))->pluck('order_team')->all();
        $orders = ProductPerformance::withoutHiddenProductOrders(
            Order::whereRaw('COALESCE(pancake_inserted_at, pancake_created_at) BETWEEN ? AND ?', [$from, $to])
                ->whereIn('team', $orderTeams)
                ->get(),
            Product::orderBy('sort_order')->get()
        );
        $tallies = ProductPerformance::tsaRows($orders, $shifts);
        $photos = self::photosFor($shifts);

        return $shifts->map(function (TsaShift $t) use ($tallies, $photos) {
            $tally  = $tallies->get($t->tsa_key, []);
            $status = $t->status ?: TsaShift::STATUS_LOGOUT;

            return [
                'id'           => $t->id,
                'name'         => $t->display_name ?: $t->tsa_key,
                'photo'        => $photos[$t->id] ?? null,
                'team'         => $t->team ? Teams::nameForOrderTeam($t->team, today()) : null,
                'shift'        => $t->shift_range,
                'status'       => $status,
                'status_label' => TsaShift::STATUSES[$status]['label'] ?? ucfirst(str_replace('_', ' ', $status)),
                'group'        => self::groupFor($status),
                'since'        => $t->status_changed_at,
                'online'       => (bool) $t->user?->isOnline(),
                'leads'        => (int) ($tally['total'] ?? 0),
                'called'       => (int) ($tally['total_called'] ?? 0),
                'upsells'      => (int) ($tally['upsell_confirmation'] ?? 0),
                'sales'        => (float) ($tally['upsell_sales'] ?? 0),
                'conversion'   => $tally['conversion_rate'] ?? null,
            ];
        })->sortBy(fn ($r) => [array_search($r['group'], ['working', 'away', 'offline'], true), -$r['sales']])->values();
    }

    /**
     * TSD Scoreboard cards: each TSA's Total Sales and Total Net Income for
     * $from..$to — the SAME figures as Data Management's Summary Sales
     * Report, not re-derived here:
     *  - Total Sales = its Gross Sales (ProductPerformance::tally()'s
     *    upsell_sales on the COALESCE date basis, TikTok upsells and hidden
     *    products excluded — exactly TsaSalesReportController::
     *    perTsaPerDayPerformance()'s order set, summed over the range).
     *  - Total Net Income = ExpectedIncomeController::netIncomeByTsaAndDate()
     *    summed over the range (Expected Income is the basis, per that
     *    report's own 2026-10-10 decision).
     *
     * @return array<int, array{sales: float, net: float}> keyed by tsa_shift id
     */
    public static function salesAndNetIncome(Collection $shifts, \Illuminate\Support\Carbon $from, \Illuminate\Support\Carbon $to): array
    {
        if ($shifts->isEmpty()) {
            return [];
        }
        [$dateFrom, $dateTo] = [$from->toDateString(), $to->toDateString()];

        $orders = Order::whereRaw('DATE(COALESCE(pancake_inserted_at, pancake_created_at)) BETWEEN ? AND ?', [$dateFrom, $dateTo])
            ->whereIn('tsa_name', $shifts->pluck('tsa_key'))->get()
            ->pipe(fn ($o) => ProductPerformance::withoutHiddenProductOrders(Order::withoutTiktokUpsells($o)));
        $byKey = $orders->groupBy('tsa_name');

        $net = [];
        foreach (\App\Http\Controllers\DataManagement\ExpectedIncomeController::netIncomeByTsaAndDate($dateFrom, $dateTo) as $key => $value) {
            $tsaId = (int) strtok((string) $key, ':');
            $net[$tsaId] = ($net[$tsaId] ?? 0) + (float) $value;
        }

        return $shifts->mapWithKeys(fn (TsaShift $t) => [$t->id => [
            'sales' => (float) ProductPerformance::tally($byKey->get($t->tsa_key, collect()))['upsell_sales'],
            'net'   => round($net[$t->id] ?? 0, 2),
        ]])->all();
    }

    /** tsa_shift id => profile photo URL, from each TSA's linked person. */
    private static function photosFor(Collection $shifts): array
    {
        return \App\Models\Employee::whereIn('tsa_shift_id', $shifts->pluck('id'))->whereNotNull('photo_path')->get()
            ->mapWithKeys(fn ($e) => [$e->tsa_shift_id => $e->photoUrl()])->all();
    }

    public static function groupFor(string $status): string
    {
        if (in_array($status, self::WORKING, true)) {
            return 'working';
        }

        return in_array($status, TsaShift::UNPRODUCTIVE_STATUSES, true) ? 'away' : 'offline';
    }

    public static function summary(Collection $rows): array
    {
        return [
            'working' => $rows->where('group', 'working')->count(),
            'away'    => $rows->where('group', 'away')->count(),
            'offline' => $rows->where('group', 'offline')->count(),
            'leads'   => $rows->sum('leads'),
            'upsells' => $rows->sum('upsells'),
            'sales'   => $rows->sum('sales'),
        ];
    }

    /**
     * One month's Total Sales + Total Net Income for EVERY active, non-hidden
     * TSA (salesAndNetIncome(), month-to-date for the current month).
     * Cached — net income runs the full Expected Income calculation per day:
     * 5 minutes for the current month, 12 hours for past months.
     *
     * @return array<int, array{sales: float, net: float}> keyed by tsa_shift id
     */
    public static function monthFigures(\Illuminate\Support\Carbon $month): array
    {
        $from = $month->copy()->startOfMonth();
        if ($from->isFuture() || $from->lt(self::SYSTEM_FROM)) {
            return [];
        }
        $isCurrent = $from->isSameMonth(now());
        $to = $isCurrent ? now() : $from->copy()->endOfMonth();

        return \Illuminate\Support\Facades\Cache::remember(
            'scoreboard:figures:' . $from->format('Y-m'),
            $isCurrent ? now()->addMinutes(5) : now()->addHours(12),
            fn () => self::salesAndNetIncome(
                TsaShift::where('active', true)->where('is_hidden', false)->get(), $from, $to
            ),
        );
    }

    /**
     * TSD Scoreboard's "by month" table for one year: per TSA, Jan..Dec
     * Total Sales and Net Income, year totals, month totals and best month.
     *
     * A month that has Google Sheet figures (scoreboard_imports — Jan–Apr
     * 2026) uses THOSE instead of the system's (explicit request,
     * 2026-10-20). Sheet TSAs who aren't on the current roster (removed, or
     * never in the app) get their own row, flagged 'former'.
     */
    public static function yearFigures(int $year, ?string $orderTeam = null): array
    {
        $shifts = TsaShift::where('active', true)->where('is_hidden', false)
            ->when($orderTeam, fn ($q) => $q->where('team', $orderTeam))
            ->orderBy('sort_order')->get();
        $photos = self::photosFor($shifts);
        $zero = ['sales' => 0.0, 'net' => 0.0];

        $imports = \App\Models\ScoreboardImport::whereYear('month', $year)->get()
            ->groupBy(fn ($i) => (int) $i->month->format('n'));
        $byMonth = [];
        for ($m = 1; $m <= 12; $m++) {
            $byMonth[$m] = $imports->has($m) ? null : self::monthFigures(\Illuminate\Support\Carbon::create($year, $m, 1));
        }
        $fromSheet = fn (\Illuminate\Support\Collection $list) => [
            'sales' => (float) $list->sum('sales'), 'net' => (float) $list->sum('net'),
        ];

        $rows = $shifts->map(function (TsaShift $t) use ($byMonth, $imports, $photos, $zero, $fromSheet) {
            $months = [];
            foreach ($byMonth as $m => $figures) {
                $months[$m] = $figures === null
                    ? $fromSheet($imports[$m]->where('tsa_shift_id', $t->id))
                    : ($figures[$t->id] ?? $zero);
            }

            return [
                'id' => $t->id,
                'name' => $t->display_name ?: $t->tsa_key,
                'photo' => $photos[$t->id] ?? null,
                'team' => $t->team ? Teams::nameForOrderTeam($t->team, today()) : null,
                'former' => false,
                'months' => $months,
            ];
        });

        // Sheet rows for anyone NOT on the current roster → one "former" row per name.
        $current = $shifts->pluck('id')->all();
        $teams = config('teams');
        $formerRows = $imports->flatten()
            ->filter(fn ($i) => ! in_array($i->tsa_shift_id, $current, true))
            ->filter(fn ($i) => ! $orderTeam || ($teams[$i->team_slug]['order_team'] ?? null) === $orderTeam)
            ->groupBy('name')
            ->map(function ($list, $name) use ($byMonth, $zero, $fromSheet) {
                $months = [];
                foreach (array_keys($byMonth) as $m) {
                    $inMonth = $list->filter(fn ($i) => (int) $i->month->format('n') === $m);
                    $months[$m] = $inMonth->isEmpty() ? $zero : $fromSheet($inMonth);
                }
                $latestTeam = $list->sortByDesc('month')->first()->team_slug;

                return [
                    'id' => 'former:' . $name,
                    'name' => \App\Support\ScoreboardSheetImport::displayName($name),
                    'photo' => null,
                    'team' => $latestTeam ? Teams::nameFor($latestTeam, today()) : null,
                    'former' => true,
                    'months' => $months,
                ];
            });

        $rows = $rows->concat($formerRows->values())->map(function ($r) {
            $r['year'] = [
                'sales' => array_sum(array_column($r['months'], 'sales')),
                'net' => array_sum(array_column($r['months'], 'net')),
            ];

            return $r;
        })->sortByDesc(fn ($r) => $r['year']['net'])->values();

        $sheetMonths = $imports->keys()->map(fn ($m) => (int) $m)->sort()->values()->all();

        $monthTotals = [];
        for ($m = 1; $m <= 12; $m++) {
            $monthTotals[$m] = [
                'sales' => $rows->sum(fn ($r) => $r['months'][$m]['sales']),
                'net' => $rows->sum(fn ($r) => $r['months'][$m]['net']),
            ];
        }
        $best = fn (string $k) => collect($monthTotals)->filter(fn ($t) => $t[$k] != 0)->sortByDesc($k)->keys()->first();

        return [
            'rows' => $rows,
            'sheetMonths' => $sheetMonths,
            'monthTotals' => $monthTotals,
            'yearTotal' => [
                'sales' => array_sum(array_column($monthTotals, 'sales')),
                'net' => array_sum(array_column($monthTotals, 'net')),
            ],
            'bestMonth' => ['sales' => $best('sales'), 'net' => $best('net')],
            'cellMax' => [
                'sales' => max(1, (float) $rows->flatMap(fn ($r) => array_column($r['months'], 'sales'))->max()),
                'net' => max(1, (float) $rows->flatMap(fn ($r) => array_map('abs', array_column($r['months'], 'net')))->max()),
            ],
        ];
    }
}
