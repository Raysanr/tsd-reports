<?php

namespace App\Support;

use App\Models\ProjectionColumn;
use App\Models\TsaShift;
use Illuminate\Support\Carbon;

/**
 * "This month vs target" (explicit request, 2026-10-24) — each TSA's and
 * team's month-to-date NET INCOME against the monthly Net income target in
 * Data Management → Projections ("their net income every tsa should be
 * 100,000 so it is their target").
 *
 * Target: a TSA's = their shift's "Individual TSA: Monthly Target" Net
 * income target; a team's = the "Opening/Closing Shift: Monthly Target" one.
 * Actual: each TSA's month-to-date net income, the same figure as the TSD
 * Scoreboard / Expected Income (LiveTsaBoard::monthFigures()); a team's is
 * the sum of its TSAs. Status compares progress with how far into the
 * month we are.
 */
class SalesTargets
{
    public static function month(?string $month = null): string
    {
        return $month && preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $month) ? $month : now('Asia/Manila')->format('Y-m');
    }

    /** @return array{month:string, label:string, elapsed:float, daysLeft:int, tsas:\Illuminate\Support\Collection, teams:\Illuminate\Support\Collection} */
    public static function forMonth(?string $month = null, ?string $orderTeam = null): array
    {
        $month = self::month($month);
        $today = now('Asia/Manila')->startOfDay();
        $start = Carbon::createFromFormat('Y-m-d', $month . '-01', 'Asia/Manila')->startOfDay();
        $end = $start->copy()->endOfMonth()->startOfDay();
        $daysInMonth = $start->daysInMonth;
        $through = $today->lt($start) ? null : ($today->gt($end) ? $end : $today);
        // Share of the month already behind us (whole days before today), so
        // nobody shows "behind" in the morning just because today's sales
        // aren't in yet.
        $elapsed = match (true) {
            $today->lt($start) => 0.0,
            $today->gt($end) => 1.0,
            default => ($today->day - 1) / $daysInMonth,
        };
        // Selling days left, today included (it isn't over yet).
        $daysLeft = match (true) {
            $today->lt($start) => $daysInMonth,
            $today->gt($end) => 0,
            default => $end->day - $today->day + 1,
        };

        $tsas = TsaShift::where('active', true)->where('is_hidden', false)->orderBy('team')->orderBy('sort_order')->get();
        $plan = self::projectionTargets($month);
        $shiftOf = fn (string $orderTeam) => self::shiftFor($orderTeam, $start);
        // Month-to-date net income per TSA (cached 5 min for the current month).
        $net = $through ? LiveTsaBoard::monthFigures($start) : [];

        $rows = $tsas->map(fn (TsaShift $t) => self::row(
            $t->display_name ?: $t->tsa_key, (float) ($plan[$shiftOf((string) $t->team)]['tsa'] ?? 0), (float) ($net[$t->id]['net'] ?? 0), $elapsed, $daysLeft
        ) + ['id' => $t->id, 'team' => $t->team]);
        if ($orderTeam) {
            $rows = $rows->where('team', $orderTeam)->values();
        }

        $teams = collect(Teams::config())
            ->filter(fn ($cfg) => ! $orderTeam || $cfg['order_team'] === $orderTeam)
            ->map(function ($cfg) use ($rows, $elapsed, $daysLeft, $start, $plan, $shiftOf) {
                $members = $rows->where('team', $cfg['order_team']);
                $teamTarget = (float) ($plan[$shiftOf($cfg['order_team'])]['team'] ?? 0);

                return self::row(Teams::nameForOrderTeam($cfg['order_team'], $start), $teamTarget, $members->sum('actual'), $elapsed, $daysLeft)
                    + ['order_team' => $cfg['order_team']];
            })->values();

        return [
            'month' => $month,
            'label' => $start->format('F Y'),
            'elapsed' => $elapsed,
            'daysLeft' => $daysLeft,
            'tsas' => $rows->values(),
            'teams' => $teams,
        ];
    }

    /** One TSA's own figures for this month, or null if they have no target. */
    public static function forTsa(int $tsaId, ?string $month = null): ?array
    {
        $data = self::forMonth($month);
        $row = $data['tsas']->firstWhere('id', $tsaId);

        return $row && $row['target'] > 0 ? $row + ['daysLeft' => $data['daysLeft'], 'label' => $data['label']] : null;
    }

    private static function row(string $name, float $target, float $actual, float $elapsed, int $daysLeft): array
    {
        $pct = $target > 0 ? $actual / $target : 0.0;
        $remaining = max(0.0, $target - $actual);
        $status = match (true) {
            $target <= 0 => 'none',
            $actual >= $target => 'hit',
            $pct >= $elapsed - 0.02 => 'on_track',
            default => 'behind',
        };

        return [
            'name' => $name, 'target' => $target, 'actual' => $actual, 'pct' => $pct,
            'remaining' => $remaining, 'perDay' => $daysLeft > 0 ? $remaining / $daysLeft : $remaining,
            'status' => $status,
        ];
    }

    /** Net income targets from Projections for $month (or the latest earlier
     *  month that has Projections): ['opening' => ['team' => …, 'tsa' => …],
     *  'closing' => […]]. Read-only — never creates a Projections month. */
    public static function projectionTargets(string $month): array
    {
        $source = ProjectionColumn::where('month', '<=', $month)->max('month')
            ?? ProjectionColumn::min('month');
        if ($source === null) {
            return [];
        }

        return RequestMemo::remember('sales_targets.projection.' . $source, function () use ($source) {
            $target = fn (string $key) => (float) (ProjectionColumn::where('month', $source)->where('key', $key)->value('net_income_target') ?? 0);

            return [
                'opening' => ['team' => $target('opening_shift'), 'tsa' => $target('opening_individual_tsa_monthly')],
                'closing' => ['team' => $target('closing_shift'), 'tsa' => $target('closing_individual_tsa_monthly')],
            ];
        });
    }

    /** "opening" / "closing" from the team's name that month (Team Opening /
     *  Team Closing), else the default pairing; null for anything else. */
    private static function shiftFor(string $orderTeam, Carbon $date): ?string
    {
        $name = strtoupper(Teams::nameForOrderTeam($orderTeam, $date));
        if (str_contains($name, 'OPEN')) {
            return 'opening';
        }
        if (str_contains($name, 'CLOS')) {
            return 'closing';
        }
        // Names that don't say Opening/Closing: the default pairing
        // (SH Naturals = Team Opening, Eyecare = Team Closing).
        $slug = collect(Teams::config())->search(fn ($cfg) => $cfg['order_team'] === $orderTeam);

        return ['sh-naturals' => 'opening', 'eyecare' => 'closing'][$slug] ?? null;
    }
}
