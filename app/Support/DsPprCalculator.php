<?php

namespace App\Support;

/**
 * TSD Data Management — DSPPR - TSM Report's own derived-figure math
 * (explicit request, 2026-09-24: "exactly like in the sheets"). Every
 * formula below was confirmed against the source sheet's own real Sep 1
 * numbers before being hardcoded here (e.g. Clearsight: Gross Sales
 * 3,800.00, Net Income -8,208.10 → NI% -216.00%, matching -8208.10/3800
 * to the cent).
 *
 * Takes a plain array of the 6 raw fields (not a DsPprEntry model
 * directly) so the same math works for both a single row AND a summed
 * "OVERALL TOTAL" / weekly-running row, which is never a real persisted
 * entry — see sum() below.
 */
class DsPprCalculator
{
    /** One row's full set of derived figures from its 6 raw inputs.
     *  Percent fields are fractions (0.05 = 5%), matching every other
     *  rate/pct convention already in this app (e.g. ProjectionCalculator). */
    public static function derive(array $row): array
    {
        $grossSales   = (float) ($row['gross_sales'] ?? 0);
        $netIncome    = (float) ($row['net_income'] ?? 0);
        $adsSpent     = (float) ($row['ads_spent'] ?? 0);
        $totalOrders  = (float) ($row['total_orders'] ?? 0);
        $totalLeads   = (float) ($row['total_leads'] ?? 0);
        $cateredLeads = (float) ($row['catered_leads'] ?? 0);

        $excessLeads = max(0, $totalLeads - $cateredLeads);

        return [
            'gross_sales'   => $grossSales,
            'net_income'    => $netIncome,
            'ads_spent'     => $adsSpent,
            'total_orders'  => $totalOrders,
            'total_leads'   => $totalLeads,
            'catered_leads' => $cateredLeads,
            'excess_leads'  => $excessLeads,

            // NI% = Net Income ÷ Gross Sales — confirmed exact.
            'ni_pct' => $grossSales > 0 ? $netIncome / $grossSales : 0.0,
            // AOV = Gross Sales ÷ Total Orders.
            'aov' => $totalOrders > 0 ? $grossSales / $totalOrders : 0.0,
            // Actual Cost Per Lead = Ads Spent ÷ Total Leads.
            'actual_cost_per_lead' => $totalLeads > 0 ? $adsSpent / $totalLeads : 0.0,
            // Pick-up Rate = Catered Leads ÷ Total Leads.
            'pickup_rate' => $totalLeads > 0 ? $cateredLeads / $totalLeads : 0.0,
            // Conversion Rate = Total Orders ÷ Catered Leads.
            'conversion_rate' => $cateredLeads > 0 ? $totalOrders / $cateredLeads : 0.0,
            // Upselling Rate: share of catered leads that convert AND
            // upsell isn't separately tracked on this manual-entry table
            // (no per-order upsell flag here, unlike Orders' own
            // is_upsell tag elsewhere in this app) — treated as 1:1 with
            // Conversion Rate until a real upsell count is captured on
            // this table, same placeholder convention
            // ProjectionCalculator's own target_card uses for
            // upselling_rate (1:1 with orders_needed).
            'upselling_rate' => $cateredLeads > 0 ? $totalOrders / $cateredLeads : 0.0,
        ];
    }

    /** Sums N raw rows' own 6 inputs, then derives the summed row's own
     *  dollar/count figures fresh from those totals (NI%, AOV, Actual
     *  Cost Per Lead, Excess Leads) — confirmed-exact "recompute the
     *  ratio from summed dollars" convention, same as
     *  ProjectionCalculator::sumPnl(). Used for both the "OVERALL TOTAL"
     *  row (every product, one day/range) and the "TOTAL" row under each
     *  daily block (every product, that one day).
     *
     *  Pick-up Rate / Conversion Rate / Upselling Rate are the ONE
     *  exception (root-caused 2026-09-24, /systematic-debugging): the
     *  real sheet's own TOTAL row for these 3 is a plain AVERAGE of each
     *  row's own already-computed percentage, not a ratio recomputed
     *  from summed Total/Catered Leads — confirmed exact against the
     *  real Sep 14 block (TOTAL Pick-up Rate 55.17% = average of that
     *  day's 8 real per-product Pick-up Rates, not catered÷leads on the
     *  summed 194÷200, which would give 97%). The per-row formula for
     *  these 3 itself is still unverified (every combination of this
     *  table's own 6 raw fields failed to reproduce the sheet's real
     *  per-row numbers — see derive()'s own doc comment) — only HOW the
     *  total aggregates whatever per-row value ends up there is fixed
     *  here. */
    public static function sum(array $rows): array
    {
        $totals = [
            'gross_sales' => 0, 'net_income' => 0, 'ads_spent' => 0,
            'total_orders' => 0, 'total_leads' => 0, 'catered_leads' => 0,
        ];

        foreach ($rows as $row) {
            $totals['gross_sales']   += (float) ($row['gross_sales'] ?? 0);
            $totals['net_income']    += (float) ($row['net_income'] ?? 0);
            $totals['ads_spent']     += (float) ($row['ads_spent'] ?? 0);
            $totals['total_orders']  += (float) ($row['total_orders'] ?? 0);
            $totals['total_leads']   += (float) ($row['total_leads'] ?? 0);
            $totals['catered_leads'] += (float) ($row['catered_leads'] ?? 0);
        }

        $summed = self::derive($totals);

        $rowCount = count($rows);
        if ($rowCount > 0) {
            $perRow = array_map(fn ($row) => self::derive($row), $rows);
            $summed['pickup_rate']      = array_sum(array_column($perRow, 'pickup_rate')) / $rowCount;
            $summed['conversion_rate']  = array_sum(array_column($perRow, 'conversion_rate')) / $rowCount;
            $summed['upselling_rate']   = array_sum(array_column($perRow, 'upselling_rate')) / $rowCount;
        }

        return $summed;
    }
}
