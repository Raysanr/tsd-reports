<?php

namespace App\Support;

/**
 * TSD Data Management — Summary Sales Report's own derived-figure math
 * (explicit request, 2026-09-24: "add page summary sales report ... make
 * it same as in the sheets ... analyze the all formula"). Confirmed
 * against the real source sheet's own Tue Sep 1 row (Marisol Lagarde:
 * Gross Sales 5,500.00, Net Income -1,379.95, Total Orders 6 → NI%
 * -25.09%, AOV 916.67 — both matched to the cent).
 *
 * Pick-up Rate and Upselling Rate are NOT derived here — they're raw
 * manual-entry fields (see create_tsa_sales_entries_table migration's
 * own doc comment for the full evidence that neither can be reconstructed
 * from Gross Sales/Net Income/Ads Spent/Orders/Catered Leads on this
 * sheet OR on DSPPR - TSM Report's own separate sheet).
 */
class TsaSalesCalculator
{
    /** One row's full set of derived figures from its raw inputs. */
    public static function derive(array $row): array
    {
        $grossSales    = (float) ($row['gross_sales'] ?? 0);
        $netIncome     = (float) ($row['net_income'] ?? 0);
        $adsSpent      = (float) ($row['ads_spent'] ?? 0);
        $totalOrders   = (float) ($row['total_orders'] ?? 0);
        $cateredLeads  = (float) ($row['catered_leads'] ?? 0);
        $pickupRate    = (float) ($row['pickup_rate'] ?? 0);
        $upsellingRate = (float) ($row['upselling_rate'] ?? 0);

        return [
            'gross_sales'    => $grossSales,
            'net_income'     => $netIncome,
            'ads_spent'      => $adsSpent,
            'total_orders'   => $totalOrders,
            'catered_leads'  => $cateredLeads,
            'pickup_rate'    => $pickupRate,
            'upselling_rate' => $upsellingRate,

            // NI% = Net Income ÷ Gross Sales — confirmed exact.
            'ni_pct' => $grossSales > 0 ? $netIncome / $grossSales : 0.0,
            // AOV = Gross Sales ÷ Total Orders — confirmed exact.
            'aov' => $totalOrders > 0 ? $grossSales / $totalOrders : 0.0,
        ];
    }

    /** Sums N raw rows' own inputs, then derives NI%/AOV fresh from those
     *  totals — never averages a %, same "recompute the ratio from
     *  summed dollars" convention as ProjectionCalculator/DsPprCalculator.
     *  Pick-up Rate / Upselling Rate ARE averaged across rows instead
     *  (same root-caused exception as DsPprCalculator::sum() — the real
     *  sheet's own TOTAL/OVERALL TOTAL rows for these 2 fields are a
     *  plain average of each row's own typed-in percentage, not a ratio
     *  recomputed from summed dollars/counts, since neither has a summed
     *  denominator to recompute against here anyway — Pick-up Rate and
     *  Upselling Rate are raw fields with no underlying count columns on
     *  this sheet at all). */
    public static function sum(array $rows): array
    {
        $totals = [
            'gross_sales' => 0, 'net_income' => 0, 'ads_spent' => 0,
            'total_orders' => 0, 'catered_leads' => 0,
        ];

        foreach ($rows as $row) {
            $totals['gross_sales']    += (float) ($row['gross_sales'] ?? 0);
            $totals['net_income']     += (float) ($row['net_income'] ?? 0);
            $totals['ads_spent']      += (float) ($row['ads_spent'] ?? 0);
            $totals['total_orders']   += (float) ($row['total_orders'] ?? 0);
            $totals['catered_leads']  += (float) ($row['catered_leads'] ?? 0);
        }

        $summed = self::derive($totals);

        $rowCount = count($rows);
        if ($rowCount > 0) {
            $summed['pickup_rate']    = array_sum(array_column($rows, 'pickup_rate')) / $rowCount;
            $summed['upselling_rate'] = array_sum(array_column($rows, 'upselling_rate')) / $rowCount;
        }

        return $summed;
    }
}
