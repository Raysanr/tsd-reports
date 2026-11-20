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
    public static function salesAndNetIncome(Collection $shifts, \Illuminate\Support\Carbon $from, \Illuminate\Support\Carbon $to, bool $actual = false): array
    {
        if ($shifts->isEmpty()) {
            return [];
        }
        [$dateFrom, $dateTo] = [$from->toDateString(), $to->toDateString()];

        $orders = Order::whereRaw('DATE(COALESCE(pancake_inserted_at, pancake_created_at)) BETWEEN ? AND ?', [$dateFrom, $dateTo])
            ->whereIn('tsa_name', $shifts->pluck('tsa_key'))->get()
            ->pipe(fn ($o) => ProductPerformance::withoutHiddenProductOrders(Order::withoutTiktokUpsells($o)));
        $byKey = $orders->groupBy('tsa_name');

        // A TSA removed during the range still has her figures up to her
        // last day (same roster window the Summary Sales Report uses).
        $attrs = app()->bound('request') ? app('request')->attributes : null;
        $was = [$attrs?->get('tsa_roster_from'), $attrs?->get('tsa_roster_to')];
        TsaShift::showDeletedFrom($dateFrom, $dateTo);
        // $actual: Actual Income's figures — real returns by each order's
        // current status, costs from Actual Cost Breakdown — the same
        // switches ActualIncomeController::index() flips.
        if ($actual) {
            ExpectedIncomeCalculator::$actual = true;
            $month = $from->format('Y-m');
            $useActual = CostBreakdownVariant::using(CostBreakdownVariant::ACTUAL,
                fn () => collect(CostBreakdownMonths::existing())->contains(fn ($m) => $m <= $month));
            CostBreakdownVariant::set($useActual ? CostBreakdownVariant::ACTUAL : CostBreakdownVariant::BUDGET);
            RequestMemo::flush();
        }
        try {
            $net = [];
            foreach (\App\Http\Controllers\DataManagement\ExpectedIncomeController::netIncomeByTsaAndDate($dateFrom, $dateTo) as $key => $value) {
                $tsaId = (int) strtok((string) $key, ':');
                $net[$tsaId] = ($net[$tsaId] ?? 0) + (float) $value;
            }
        } finally {
            $attrs?->set('tsa_roster_from', $was[0]);
            $attrs?->set('tsa_roster_to', $was[1]);
            if ($actual) {
                ExpectedIncomeCalculator::$actual = false;
                CostBreakdownVariant::reset();
                RequestMemo::flush();
            }
        }

        return $shifts->mapWithKeys(fn (TsaShift $t) => [$t->id => [
            'sales' => (float) ProductPerformance::tally($byKey->get($t->tsa_key, collect()))['upsell_sales'],
            'net'   => round($net[$t->id] ?? 0, 2),
        ]])->all();
    }

    /** Active, non-hidden TSAs plus any removed on or after $since. A
     *  removed TSA counts even if hidden: TSAs who moved to a partner were
     *  hidden after leaving (2026-11-19: Julie, Katherine, Lika, Angel,
     *  Marisol), and hiding is display-only, never a reason to drop the
     *  months she worked. */
    private static function shiftsFor(\Illuminate\Support\Carbon $since): Collection
    {
        return TsaShift::withTrashed()
            ->where(fn ($q) => $q->where(fn ($q) => $q->whereNull('deleted_at')->where('active', true)->where('is_hidden', false))
                ->orWhere('deleted_at', '>=', $since->copy()->startOfDay()))
            ->orderBy('sort_order')->get();
    }

    /** Prefix for a partner TSA's id, so it never collides with a TSD one. */
    public const PARTNER_ID = 'partner:';

    /**
     * Partner systems on the Scoreboard (explicit request, 2026-11-19:
     * "make it included the partner's system" — some TSAs moved from TSD to
     * a partner). The same figures, read from Partners Data Management's
     * synced data set; ids prefixed with PARTNER_ID. A partners database
     * that can't be read leaves just TSD's figures rather than an error.
     */
    public static function onPartners(\Closure $callback, mixed $fallback): mixed
    {
        try {
            return DataSet::onPartners($callback);
        } catch (\Illuminate\Database\QueryException $e) {
            report($e);

            return $fallback;
        }
    }

    public static function partnerRows(?string $orderTeam = null): Collection
    {
        return self::onPartners(fn () => self::rows($orderTeam), collect())
            ->map(fn (array $r) => ['id' => self::PARTNER_ID . $r['id'], 'partner' => true] + $r);
    }

    public static function partnerMonthFigures(\Illuminate\Support\Carbon $month): array
    {
        return collect(self::onPartners(fn () => self::monthFigures($month), []))
            ->mapWithKeys(fn ($v, $id) => [self::PARTNER_ID . $id => $v])->all();
    }

    public static function partnerYearFigures(int $year, ?string $orderTeam = null): array
    {
        $figures = self::onPartners(fn () => self::yearFigures($year, $orderTeam), null)
            ?? self::emptyYear();
        $figures['rows'] = $figures['rows']->map(fn (array $r) => ['id' => self::PARTNER_ID . $r['id'], 'partner' => true] + $r);

        return $figures;
    }

    private static function emptyYear(): array
    {
        $zero = array_fill(1, 12, ['sales' => 0.0, 'net' => 0.0]);

        return ['rows' => collect(), 'sheetMonths' => [], 'monthTotals' => $zero, 'yearTotal' => ['sales' => 0.0, 'net' => 0.0],
            'bestMonth' => ['sales' => null, 'net' => null], 'cellMax' => ['sales' => 1, 'net' => 1]];
    }

    /** Same person in TSD and a partner? Names are often written
     *  differently there ("Lika Trocio" / "Trocio LIKA", "Jhun Ematong" /
     *  "Jhun"), so: every word of the shorter name appears in the longer. */
    public static function sameName(string $a, string $b): bool
    {
        $words = fn (string $n) => array_values(array_filter(preg_split('/[^\p{L}\p{N}]+/u', mb_strtolower($n))));
        [$x, $y] = [$words($a), $words($b)];
        if ($x === [] || $y === []) {
            return false;
        }
        [$short, $long] = count($x) <= count($y) ? [$x, $y] : [$y, $x];

        return array_diff($short, $long) === [];
    }

    /** Same person written under names with no word in common (2026-11-20:
     *  "SH MARI AND MARISOL IS THE SAME PERSON"). Lowercase name => the
     *  name to match on instead. */
    public const NAME_ALIASES = [
        'sh mari' => 'Marisol Lagarde',
    ];

    private static function matchName(string $name): string
    {
        return self::NAME_ALIASES[mb_strtolower(trim(preg_replace('/\s+/u', ' ', $name)))] ?? $name;
    }

    /**
     * Rows that are the same person across systems (TSD, each partner),
     * as groups of row indexes. Two rows join when they come from different
     * systems, sameName() matches (after NAME_ALIASES), and each is the only
     * match for the other in its system. Google Sheet-only rows ("former:")
     * never join.
     *
     * @param  array<int, array>  $rows  each with 'id', 'name', 'team', 'partner'
     * @return array<int, int[]>
     */
    public static function personGroups(array $rows): array
    {
        $source = fn (array $r) => empty($r['partner']) ? 'tsd' : 'p:' . ($r['team'] ?? '');
        $names = array_map(fn ($r) => self::matchName($r['name']), $rows);
        $eligible = array_map(fn ($r) => ! str_starts_with((string) $r['id'], 'former:'), $rows);
        $matchesIn = function (int $i, string $src) use ($rows, $names, $eligible, $source) {
            return array_keys(array_filter($rows, fn ($r, $j) => $j !== $i && $eligible[$j] && $source($r) === $src && self::sameName($names[$i], $names[$j]), ARRAY_FILTER_USE_BOTH));
        };

        $parent = array_keys($rows);
        $find = function (int $i) use (&$parent, &$find) { return $parent[$i] === $i ? $i : ($parent[$i] = $find($parent[$i])); };
        foreach ($rows as $i => $a) {
            if (! $eligible[$i]) {
                continue;
            }
            foreach ($rows as $j => $b) {
                if ($j <= $i || ! $eligible[$j] || $source($a) === $source($b) || ! self::sameName($names[$i], $names[$j])) {
                    continue;
                }
                if (count($matchesIn($i, $source($b))) === 1 && count($matchesIn($j, $source($a))) === 1) {
                    $parent[$find($j)] = $find($i);
                }
            }
        }

        $groups = [];
        foreach (array_keys($rows) as $i) {
            $groups[$find($i)][] = $i;
        }

        return array_values($groups);
    }

    /** The row a merged person is shown as: a current partner row first,
     *  then any partner row, then TSD — the same pick for cards and table,
     *  so a card opens the matching table row. */
    private static function primaryOf(array $rows, array $group): int
    {
        return collect($group)->sortBy(fn ($i) => [empty($rows[$i]['partner']) ? 1 : 0, empty($rows[$i]['former']) ? 0 : 1, (string) $rows[$i]['id']])->first();
    }

    private static function teamsOf(array $parts): string
    {
        $current = array_filter($parts, fn ($r) => empty($r['former']));

        return collect($current ?: $parts)->pluck('team')->filter()->unique()->join(' · ');
    }

    /**
     * TSD + partner year tables as one: a person on several rosters (moved
     * from TSD to a partner, or working two partners) is ONE row with their
     * months added up. See personGroups().
     */
    public static function mergeYearFigures(array $tsd, array $partner): array
    {
        $all = array_merge($tsd['rows']->values()->all(), $partner['rows']->values()->all());
        $rows = [];
        foreach (self::personGroups($all) as $group) {
            $parts = array_map(fn ($i) => $all[$i], $group);
            $r = $all[self::primaryOf($all, $group)];
            if (count($parts) > 1) {
                foreach ($r['months'] as $m => $_) {
                    $r['months'][$m] = [
                        'sales' => array_sum(array_map(fn ($p) => $p['months'][$m]['sales'], $parts)),
                        'net' => array_sum(array_map(fn ($p) => $p['months'][$m]['net'], $parts)),
                    ];
                }
                $r['name'] = collect($parts)->pluck('name')->sortByDesc(fn ($n) => mb_strlen($n))->first();
                $r['photo'] = collect($parts)->pluck('photo')->filter()->first();
                $r['team'] = self::teamsOf($parts);
                $r['former'] = collect($parts)->every(fn ($p) => ! empty($p['former']));
            }
            $rows[] = $r;
        }

        return self::withTotals(collect($rows), $tsd['sheetMonths']);
    }

    /**
     * Cards as one per person (2026-11-20): same grouping as the table.
     * The merged card keeps the primary row's id, adds the totals, and
     * lists each system's share in 'parts'.
     *
     * @return array{0: Collection, 1: array} [rows, totals keyed by row id]
     */
    public static function mergeCards(Collection $rows, array $totals): array
    {
        $all = $rows->values()->all();
        $zero = ['sales' => 0.0, 'net' => 0.0];
        $out = collect();
        foreach (self::personGroups($all) as $group) {
            $r = $all[self::primaryOf($all, $group)];
            if (count($group) > 1) {
                $parts = array_map(fn ($i) => $all[$i], $group);
                $r['parts'] = array_map(fn ($p) => ['label' => empty($p['partner']) ? 'TSD' : ($p['team'] ?: 'Partner')] + ($totals[$p['id']] ?? $zero), $parts);
                $totals[$r['id']] = [
                    'sales' => array_sum(array_column($r['parts'], 'sales')),
                    'net' => array_sum(array_column($r['parts'], 'net')),
                ];
                $r['name'] = collect($parts)->pluck('name')->sortByDesc(fn ($n) => mb_strlen($n))->first();
                $r['photo'] = collect($parts)->pluck('photo')->filter()->first();
                $r['team'] = self::teamsOf($parts);
            }
            $out->push($r);
        }

        return [$out, $totals];
    }

    /** Year rows with their year totals, month totals, best months and heat scale. */
    public static function withTotals(Collection $rows, array $sheetMonths): array
    {
        $rows = $rows->map(function ($r) {
            $r['year'] = [
                'sales' => array_sum(array_column($r['months'], 'sales')),
                'net' => array_sum(array_column($r['months'], 'net')),
            ];

            return $r;
        })->sortByDesc(fn ($r) => $r['year']['net'])->values();

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

    /** tsa_shift id => profile photo URL, from each TSA's linked person. */
    private static function photosFor(Collection $shifts): array
    {
        if (DataSet::isPartners()) {
            return []; // people/photos live in the main Archive only
        }

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
            (DataSet::isPartners() ? 'scoreboard:partners:figures:' : 'scoreboard:figures:') . 'v4:' . $from->format('Y-m'),
            $isCurrent ? now()->addMinutes(5) : now()->addHours(12),
            // A finished month uses Actual Income (2026-11-19: real returns
            // and Actual Cost Breakdown — Katherine's October is 89,117.81
            // there vs 25,007.11 projected); the running month stays on
            // Expected Income until its returns are known.
            fn () => self::salesAndNetIncome(self::shiftsFor($from), $from, $to, ! $isCurrent),
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
        // Current roster plus anyone removed since the Scoreboard took over
        // from the sheet (2026-11-19: Julie worked October, then moved to a
        // partner — her October must still show).
        $shifts = self::shiftsFor(\Illuminate\Support\Carbon::parse(self::SYSTEM_FROM))
            ->when($orderTeam, fn ($c) => $c->where('team', $orderTeam))->sortBy('sort_order')->values();
        $photos = self::photosFor($shifts);
        $zero = ['sales' => 0.0, 'net' => 0.0];

        // Google Sheet months are TSD's own history only.
        $imports = (DataSet::isPartners() ? \App\Models\ScoreboardImport::query()->whereRaw('1 = 0') : \App\Models\ScoreboardImport::whereYear('month', $year))->get()
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
                'former' => $t->trashed(),
                'removed' => $t->trashed(),
                'months' => $months,
            ];
        })
            // A removed TSA with nothing to show (e.g. a test entry) is left out.
            ->reject(fn ($r) => $r['removed'] && collect($r['months'])->every(fn ($v) => $v['sales'] == 0 && $v['net'] == 0))
            ->values();

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
