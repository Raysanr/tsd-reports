<?php

namespace App\Support;

/**
 * TSD Overall Performance's Sales Summary, shaped like the sheet's SALES
 * SUMMARY REPORT: SH ECOMMERCE, each partner, and a TOTAL row, for the range
 * and for each day.
 *
 * SH E-COM earns Sales; partners earn Partners IB OPEX and TSD's Shared Income
 * of it. So NI % divides by Sales for SH E-COM, by Shared Income for a partner,
 * and by both on the TOTAL row, the same as the sheet. 'sales' is SH E-COM's
 * only; a partner's own gross_sales counts toward AOV but never toward Sales.
 *
 * Three things deliberately differ from the sheet's formulas, which are wrong
 * for a range: AOV is sales ÷ orders (the sheet adds each day's AOV), Pick-up
 * and Upselling Rate are pooled from call counts (the sheet divides by a typed
 * 2, or averages the four rows), and TOTAL's orders include SH E-COM (the
 * sheet's SUM starts at the first partner).
 */
class OverallSalesSummary
{
    public const SH_ECOM_LABEL = 'SH ECOMMERCE';

    /** Saved # of TSA rows are keyed by this for SH E-COM, by name for partners. */
    public const SH_ECOM_KEY = 'SH E-COM';

    /** Sheet order, keyed by each partner's name in config('partners'). */
    private const PARTNER_LABELS = ['M&J' => 'M&J ENTERPRISE', 'PEAK.CO' => 'PEAK ENTERPRISE', 'MLC' => 'MLC'];

    /**
     * @param  array  $tsd  TsaSalesReportController::overallByDate() on TSD
     * @param  array  $partnerTeams  Its 'teams' on the partners data set, or [] if they couldn't load
     */
    public static function build(array $tsd, array $partnerTeams): array
    {
        $dates = array_keys($tsd['days']);

        $departments = [self::shEcomDays($tsd)];
        foreach (self::orderedPartners($partnerTeams) as $key => $partner) {
            $departments[] = ['key' => $key, 'label' => $partner['label'], 'partner' => true, 'days' => $partner['days']];
        }

        $rows = array_map(fn (array $department) => [
            'key'     => $department['key'],
            'label'   => $department['label'],
            'partner' => $department['partner'],
            'range'   => self::finish(self::sum($department['days']), $department['partner']),
            'days'    => array_map(fn (array $day) => self::finish($day, $department['partner']), $department['days']),
        ], $departments);

        $totalFor = fn (array $days) => self::finishTotal(self::sum($days));

        return [
            'rows'  => $rows,
            'total' => [
                'range' => $totalFor(array_merge(...array_map(fn ($d) => array_values($d['days']), $departments))),
                'days'  => collect($dates)->mapWithKeys(fn ($date) => [
                    $date => $totalFor(array_map(fn ($d) => $d['days'][$date] ?? self::blank(), $departments)),
                ])->all(),
            ],
        ];
    }

    private static function shEcomDays(array $tsd): array
    {
        $days = [];
        foreach ($tsd['days'] as $date => $day) {
            $days[$date] = [
                'sales'              => (float) $day['gross_sales'],
                'gross_sales'        => (float) $day['gross_sales'],
                'income_before_opex' => 0.0,
                'shared_income'      => 0.0,
                'net_income'         => (float) $day['net_income'],
                'total_orders'       => (float) ($day['total_orders'] ?? 0),
                'total_leads'        => array_sum(array_map(fn ($team) => $team[$date]['total_leads'] ?? 0, $tsd['teams'] ?? [])),
                'catered_leads'      => array_sum(array_map(fn ($team) => $team[$date]['catered_leads'] ?? 0, $tsd['teams'] ?? [])),
                'excess_leads'       => array_sum(array_map(fn ($team) => $team[$date]['excess_leads'] ?? 0, $tsd['teams'] ?? [])),
                'call_counts'        => $day['call_counts'],
            ];
        }

        return ['key' => self::SH_ECOM_KEY, 'label' => self::SH_ECOM_LABEL, 'partner' => false, 'days' => $days];
    }

    private static function orderedPartners(array $partnerTeams): array
    {
        $ordered = [];
        foreach (self::PARTNER_LABELS as $name => $label) {
            if (isset($partnerTeams[$name])) {
                $ordered[$name] = ['label' => $label, 'days' => $partnerTeams[$name]];
            }
        }
        foreach (array_diff_key($partnerTeams, self::PARTNER_LABELS) as $name => $days) {
            $ordered[$name] = ['label' => strtoupper($name), 'days' => $days];
        }

        return $ordered;
    }

    private static function blank(): array
    {
        return [
            'sales' => 0.0, 'gross_sales' => 0.0, 'income_before_opex' => 0.0, 'shared_income' => 0.0, 'net_income' => 0.0,
            'total_orders' => 0.0, 'total_leads' => 0.0, 'catered_leads' => 0.0, 'excess_leads' => 0.0,
            'call_counts' => ['answered' => 0, 'unanswered' => 0, 'confirmed_via_call' => 0, 'upsell_confirmation' => 0],
        ];
    }

    private static function sum(array $days): array
    {
        $sum = self::blank();
        foreach ($days as $day) {
            foreach (['sales', 'gross_sales', 'income_before_opex', 'shared_income', 'net_income', 'total_orders', 'total_leads', 'catered_leads', 'excess_leads'] as $key) {
                $sum[$key] += (float) ($day[$key] ?? 0);
            }
            foreach ($sum['call_counts'] as $key => $count) {
                $sum['call_counts'][$key] = $count + ($day['call_counts'][$key] ?? 0);
            }
        }

        return $sum;
    }

    private static function finish(array $row, bool $partner): array
    {
        return self::derived($row, $partner ? $row['shared_income'] : $row['sales']);
    }

    private static function finishTotal(array $row): array
    {
        return self::derived($row, $row['sales'] + $row['shared_income']);
    }

    private static function derived(array $row, float $niBase): array
    {
        $rates = ProductPerformance::rates($row['call_counts']);

        return $row + [
            'ni_pct'         => $niBase != 0.0 ? $row['net_income'] / $niBase : 0.0,
            'aov'            => $row['total_orders'] > 0 ? $row['gross_sales'] / $row['total_orders'] : 0.0,
            'pickup_rate'    => ($rates['pick_up_rate'] ?? 0) / 100,
            'upselling_rate' => ($rates['upselling_rate'] ?? 0) / 100,
        ];
    }
}
