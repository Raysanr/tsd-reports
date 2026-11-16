<?php

namespace App\Support;

use App\Http\Controllers\DataManagement\TsaSalesReportController;
use App\Models\TelesalesSummary;
use Illuminate\Support\Facades\Cache;

/**
 * Telesales Department card, filled automatically (2026-11-16) from TSD
 * Overall Performance's Sales Summary (SH E-COM and partners):
 * - gross sales: SH ECOMMERCE's Sales;
 * - net income: OVERALL TOTAL's Net Income (SH E-COM + partners);
 * - top seller: the TSA with the highest net income, SH E-COM and partner
 *   TSAs together; top team: SH E-COM TEAM vs PARTNERS TEAM (M&J,
 *   PEAK.CO and MLC added together), higher net income;
 * - TSA counts: SH ECOM and PARTNERS, each TSA with gross sales that day
 *   counting 1; Working TSAs is their sum.
 *
 * The card is read-only (2026-11-16): always these figures, never typed or
 * saved ones. Built for a range at once and kept 5 minutes.
 */
class TelesalesCardAuto
{
    private const CACHE_SECONDS = 300;

    /** The card's two TSA-count slots. */
    public const COUNT_SLOTS = [['slug' => 'sh_ecom', 'name' => 'SH ECOM'], ['slug' => 'partners', 'name' => 'PARTNERS']];

    /** The automatic summary for $date, or null when there's nothing yet (or it can't be built). */
    public static function summaryFor(string $date): ?TelesalesSummary
    {
        try {
            return self::forDates([$date])[$date] ?? null;
        } catch (\Throwable $e) {
            report($e);

            return null;
        }
    }

    /** Automatic (unsaved) summaries keyed by date; a date with no figures is left out. */
    public static function forDates(array $dates): array
    {
        sort($dates);
        $from = reset($dates);
        $to = end($dates);

        $built = Cache::remember("telesales-card-auto:v3:{$from}:{$to}", self::CACHE_SECONDS, fn () => self::build($from, $to));

        return collect($built)->only($dates)->filter()
            ->map(fn (array $attributes) => new TelesalesSummary($attributes))->all();
    }

    private static function build(string $from, string $to): array
    {
        $tsd = app(TsaSalesReportController::class)->overallByDate($from, $to);
        try {
            $partners = DataSet::onPartners(fn () => app(TsaSalesReportController::class)->overallByDate($from, $to));
        } catch (\Throwable $e) {
            report($e);
            $partners = ['teams' => [], 'tsas' => []];
        }
        $summary = OverallSalesSummary::build($tsd, $partners['teams'] ?? []);

        $out = [];
        foreach (array_keys($tsd['days']) as $date) {
            $gross = (float) ($summary['rows'][0]['days'][$date]['sales'] ?? 0);
            $net = (float) ($summary['total']['days'][$date]['net_income'] ?? 0);
            $hasFigures = fn (array $row) => $row['gross_sales'] != 0.0 || $row['net_income'] != 0.0;

            $shTsas = collect($tsd['tsas'][$date] ?? []);
            $topSeller = $shTsas->merge($partners['tsas'][$date] ?? [])
                ->filter($hasFigures)->sortByDesc('net_income')->first();

            // Two teams: SH E-COM, and the partners added together.
            $day = fn (array $row, string $key) => (float) ($row['days'][$date][$key] ?? 0);
            $rows = collect($summary['rows']);
            $teams = collect([
                ['partner' => false, 'name' => 'SH E-COM TEAM'],
                ['partner' => true, 'name' => 'PARTNERS TEAM'],
            ])->map(fn (array $team) => [
                'name'        => $team['name'],
                'gross_sales' => $rows->where('partner', $team['partner'])->sum(fn ($r) => $day($r, 'gross_sales')),
                'net_income'  => $rows->where('partner', $team['partner'])->sum(fn ($r) => $day($r, 'net_income')),
            ]);
            $topTeam = $teams->filter($hasFigures)->sortByDesc('net_income')->first();

            // TSAs with gross sales that day: 1 each, SH E-COM and partners.
            $selling = fn ($tsas) => collect($tsas)->filter(fn (array $t) => $t['gross_sales'] > 0)->count();
            $counts = [
                ['slug' => 'sh_ecom', 'name' => 'SH ECOM', 'count' => $selling($shTsas)],
                ['slug' => 'partners', 'name' => 'PARTNERS', 'count' => $selling($partners['tsas'][$date] ?? [])],
            ];

            if ($gross == 0.0 && $net == 0.0 && $topSeller === null) {
                $out[$date] = null; // nothing yet that day

                continue;
            }

            $out[$date] = [
                'summary_date'           => $date,
                'gross_sales'            => round($gross, 2),
                'net_income'             => round($net, 2),
                'top_seller_name'        => $topSeller['name'] ?? null,
                'top_seller_gross_sales' => $topSeller ? round($topSeller['gross_sales'], 2) : null,
                'top_seller_net_income'  => $topSeller ? round($topSeller['net_income'], 2) : null,
                'top_team_name'          => $topTeam['name'] ?? null,
                'top_team_gross_sales'   => $topTeam ? round($topTeam['gross_sales'], 2) : null,
                'top_team_net_income'    => $topTeam ? round($topTeam['net_income'], 2) : null,
                'sub_team_counts'        => $counts,
                'overall_working_tsas'   => array_sum(array_column($counts, 'count')),
            ];
        }

        return $out;
    }
}
