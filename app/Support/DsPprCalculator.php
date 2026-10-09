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

        // TIKTOK ORDERS' own manual rate overrides (explicit request,
        // 2026-10-07 — see DsPprTiktokEntry::toRawRowWithOverrides()'s own
        // doc comment) — if $row already carries one of these 4 keys
        // directly (an override was set), it wins over the formula below;
        // every real product row never has these keys pre-set, so this is
        // a no-op for them, same "array_key_exists on the incoming row
        // wins" convention sum()'s own rateOf() already uses.
        $hasOverride = fn (string $key) => array_key_exists($key, $row);

        return [
            'gross_sales'   => $grossSales,
            'net_income'    => $netIncome,
            'ads_spent'     => $adsSpent,
            'total_orders'  => $totalOrders,
            'total_leads'   => $totalLeads,
            'catered_leads' => $cateredLeads,
            'excess_leads'  => $hasOverride('excess_leads') ? (int) $row['excess_leads'] : $excessLeads,

            // NI% = Net Income ÷ Gross Sales — confirmed exact.
            'ni_pct' => $grossSales > 0 ? $netIncome / $grossSales : 0.0,
            // AOV = Gross Sales ÷ Total Orders.
            'aov' => $totalOrders > 0 ? $grossSales / $totalOrders : 0.0,
            // Actual Cost Per Lead = Ads Spent ÷ Total Leads.
            'actual_cost_per_lead' => $totalLeads > 0 ? $adsSpent / $totalLeads : 0.0,
            // Pick-up Rate = Catered Leads ÷ Total Leads.
            'pickup_rate' => $hasOverride('pickup_rate') ? (float) $row['pickup_rate'] : ($totalLeads > 0 ? $cateredLeads / $totalLeads : 0.0),
            // Conversion Rate = Total Orders ÷ Catered Leads.
            'conversion_rate' => $hasOverride('conversion_rate') ? (float) $row['conversion_rate'] : ($cateredLeads > 0 ? $totalOrders / $cateredLeads : 0.0),
            // Upselling Rate: share of catered leads that convert AND
            // upsell isn't separately tracked on this manual-entry table
            // (no per-order upsell flag here, unlike Orders' own
            // is_upsell tag elsewhere in this app) — treated as 1:1 with
            // Conversion Rate until a real upsell count is captured on
            // this table, same placeholder convention
            // ProjectionCalculator's own target_card uses for
            // upselling_rate (1:1 with orders_needed).
            'upselling_rate' => $hasOverride('upselling_rate') ? (float) $row['upselling_rate'] : ($cateredLeads > 0 ? $totalOrders / $cateredLeads : 0.0),
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
     *  here.
     *
     *  Real bug, root-caused 2026-10-05: this averaged self::derive($row)
     *  — i.e. it RE-DERIVED each row's rate from raw counts via this
     *  class's own (wrong/placeholder) formula above, discarding the
     *  correct Leads-Report-matching rate (Answered÷Total,
     *  Upsell-Confirmation÷Answered, from ProductPerformance::dsPprRow())
     *  that's already sitting on each incoming $row once the controller/
     *  view has merged it in — the exact same correct value the daily
     *  table already displays per-row. That silent re-derive is why the
     *  summary table's Pick-up/Conversion/Upselling Rate never matched
     *  the daily table for the same date despite identical raw counts.
     *  Now reads each row's own already-present rate (falling back to
     *  derive() only for a row that never got the real merge, e.g. a
     *  pure future/manual-only row with no matching orders yet). */
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

        // Only rows with REAL activity (total_leads > 0) count toward the
        // Pick-up/Conversion/Upselling Rate average (explicit follow-up,
        // 2026-10-09, same day as the averaging convention itself was
        // re-confirmed: "when 0.00% it is not included to the total
        // percentage") — a row with zero total_leads never had any real
        // calls that day/range, so its "0.00%" is an absence of data, not
        // a genuine 0% rate, and including it drags the average down.
        // Almost certainly what this method's own earlier "8 REAL
        // per-product Pick-up Rates" verified evidence already meant (see
        // this method's own class-level doc comment) — "REAL" implying
        // only the active ones were ever counted, not every row in the
        // full product catalog passed in. Every product now gets a card
        // regardless of activity (2026-10-06 decision), which is what
        // made this gap newly visible — $rows used to only ever contain
        // rows with real data to begin with.
        $activeRateRows = array_values(array_filter($rows, function (array $row) {
            $totalLeads = array_key_exists('total_leads', $row) ? (float) $row['total_leads'] : self::derive($row)['total_leads'];
            return $totalLeads > 0;
        }));
        $rateRowCount = count($activeRateRows);
        if ($rateRowCount > 0) {
            $rateOf = fn (array $row, string $key) => array_key_exists($key, $row)
                ? (float) $row[$key]
                : self::derive($row)[$key];

            $summed['pickup_rate']     = array_sum(array_map(fn ($row) => $rateOf($row, 'pickup_rate'), $activeRateRows)) / $rateRowCount;
            $summed['conversion_rate'] = array_sum(array_map(fn ($row) => $rateOf($row, 'conversion_rate'), $activeRateRows)) / $rateRowCount;
            $summed['upselling_rate']  = array_sum(array_map(fn ($row) => $rateOf($row, 'upselling_rate'), $activeRateRows)) / $rateRowCount;
        }

        $rowCount = count($rows);
        if ($rowCount > 0) {

            // Excess Leads (explicit request, 2026-10-07, TIKTOK ORDERS'
            // own manual rate-override columns — see
            // DsPprTiktokEntry::toRawRowWithOverrides()'s own doc comment)
            // — an overridden per-row count ADDS into the range total,
            // same additive convention as total_orders/total_leads above
            // (a count, not a ratio) — unlike the 3 rate percentages
            // (averaged) just above. A row with no override falls back to
            // derive()'s own formula for its own share, same $rateOf
            // pattern the 3 rates already use.
            $excessLeadsOf = fn (array $row) => array_key_exists('excess_leads', $row)
                ? (float) $row['excess_leads']
                : self::derive($row)['excess_leads'];
            $summed['excess_leads'] = array_sum(array_map($excessLeadsOf, $rows));
        }

        return $summed;
    }
}
