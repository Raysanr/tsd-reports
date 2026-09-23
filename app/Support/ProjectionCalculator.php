<?php

namespace App\Support;

use App\Models\ProjectionColumn;
use App\Models\Setting;

/**
 * TSD Data Management — Projections' own single source of truth for the
 * whole P&L + target-card formula chain (explicit request, 2026-09-23,
 * replicating a real Google Sheet's "PROJECTIONS" tab).
 *
 * CROSS-CARD STRUCTURE (root-caused 2026-09-23 after the user showed a
 * screenshot of the sheet's own formula bar reading "=F39/6" on Individual
 * TSA Monthly's Manager's Allowance cell — the 4 cards are NOT 4
 * independently-computed columns, confirmed by a full numeric audit of
 * every row against the live sheet):
 *
 *  - OPENING SHIFT is the only column actually computed from Orders × AOV
 *    × rate — the same formula chain this class always used.
 *  - TELESALES DEPARTMENT = Opening Shift's own figures × 2, EVERY line,
 *    verified via NET INCOME specifically (Opening × 2 = 1,247,219.34,
 *    exactly Telesales' own NET INCOME; Opening + the sheet's separate
 *    "Closing Shift" card would give 1,246,177.67 instead — ×2 confirmed
 *    correct, NOT a sum of Opening + Closing).
 *  - INDIVIDUAL TSA MONTHLY = Opening Shift's own figures ÷ Opening
 *    Shift's own "Current TSAs" (tsa_count) — LIVE, not a hardcoded 6
 *    (root-caused 2026-09-23, second finding: the user asked "if i edit
 *    this like the tsa is 8 why the other individual tsa is not
 *    changing," and a follow-up sheet audit confirmed it — TWO
 *    independent shift blocks in the sheet, Opening AND Closing, each
 *    with their OWN different NET INCOME total, both divide by exactly
 *    their own "Current TSAs: 6" to the cent; that only happens if
 *    Individual TSA Monthly's formula genuinely references the TSA-count
 *    cell, not two coincidentally-equal hardcoded 6s). Editing Opening
 *    Shift's own tsa_count now changes this divisor live.
 *  - INDIVIDUAL TSA DAILY = Individual TSA Monthly's own figures ÷ 24 for
 *    every dollar line — EXCEPT the sheet's own Daily column disagrees
 *    with itself on two rows, and the explicit decision (2026-09-23,
 *    asked directly after finding this) was to replicate the
 *    inconsistency exactly rather than unify it:
 *      · # of Orders uses ÷25, not ÷24 (400 ÷ 25 = 16, the sheet's own
 *        stated Daily order count — ÷24 would give 16.67).
 *      · Fulfillment Fee is a flat 3.00% of DAILY's own Gross Sales, not
 *        Monthly's Fulfillment Fee ÷ 24 (10,000 ÷ 24 = 416.67 ≠ the
 *        sheet's actual 400; 13,333.33 × 3% = 400 — matches exactly).
 *      · Every other dollar line (Gross Sales, Salaries, Rent, Total
 *        Operating Costs, NET INCOME, ...) is a clean ÷24 of Monthly.
 *
 * Every one of Opening Shift's own %-of-Gross-Sales rates (Cancelled 5%,
 * Returns 25%, Salaries 11.95%, etc.) was independently recomputed from
 * the sheet's own real dollar figures ÷ its own real Gross Sales and
 * confirmed to 2-4 decimal places before being hardcoded as this class's
 * own DEFAULT_RATES — all editable afterward via Settings. Editing a rate
 * changes Opening Shift's own P&L, which then cascades through the ×2/÷6/
 * ÷24 chain into the other 3 cards automatically, same as the real sheet.
 *
 * The target card ABOVE each P&L table (Net Income Target, AOV, Total
 * Orders Needed, ...) is a SEPARATE calculation per column — the sheet's
 * own reverse "target → orders needed" formula, driven by the shared
 * Target Margin/Conversion/Pickup rates — and does NOT participate in the
 * ×2/÷6/÷24 chain; each column keeps its own independent target inputs,
 * exactly as before.
 */
class ProjectionCalculator
{
    /** Every rate this calculator reads from Settings, with the exact
     *  default value independently verified against the real source sheet
     *  (see this class's own doc comment) — used both to seed Settings on
     *  first use and as the editable-rates form's own field list, so a new
     *  rate only ever needs adding in ONE place. Values are fractions
     *  (0.05 = 5%), not whole percents — matches how every formula below
     *  multiplies them directly against Gross Sales. */
    public const DEFAULT_RATES = [
        // Target-card assumptions (explicit request, 2026-09-23: "make all
        // 3 rates editable") — drive Total Orders Needed / Total Leads
        // Needed / Target Pick-up Rate for every column uniformly.
        'target_margin'      => 0.3125,   // Net Income % assumed when back-solving Orders Needed from a target
        'conversion_rate'    => 0.30,     // Orders ÷ this = Leads Needed
        'pickup_rate'        => 0.50,     // Leads Needed × this = Target Pick-up Rate

        // P&L table rates — all %-of-Gross-Sales.
        'cancelled'                   => 0.05,
        'returns'                     => 0.25,
        'delivered'                   => 0.70,
        'tax_allocation'              => 0.026042,
        'product_cost'                => 0.09,

        'shipping_fee'                => 0.0,
        'cod_fee'                     => 0.01568,
        'fulfillment_fee'             => 0.03125,
        'product_research'            => 0.001302,

        'salaries'                    => 0.119526,
        'communication_allowance'     => 0.002604,
        'thirteenth_month_allowance'  => 0.009961,
        'sil'                         => 0.004582,
        'government_benefits'         => 0.009065,
        'miscellaneous_expenses'      => 0.000781,
        'magic_fund'                  => 0.000868,
        'company_assets'              => 0.005256,
        'executive_benefits'          => 0.005035,
        'office_miscellaneous'        => 0.002535,
        'maintenance_expenses'        => 0.004670,
        'consultants'                 => 0.001345,
        'managers_allowance'          => 0.002604,
        'birthday_cake_allowance'     => 0.000156,
        'water_bill'                  => 0.000053,
        'internet'                    => 0.001180,
        'rent'                        => 0.009805,
        'electricity'                 => 0.009375,
        'geniusmakers_management_fee' => 0.008681,
        'business_development_fund'   => 0.006510,
        'hmo_expense'                 => 0.006337,
    ];

    /** Row keys that make up "Selling And Marketing", in the source
     *  sheet's own display order — shared by the calculator (to sum Total
     *  Selling Costs) and the view (to render each row without hand-typing
     *  the list twice, same "one definition, not two hand-kept-in-sync
     *  copies" convention as ProductPerformance::DISPOSITION_KEYWORDS). */
    public const SELLING_COST_ROWS = [
        // Advertising Cost, Ads VAT, AI Expense, and Ad Account Rental Fee
        // all removed (explicit request, 2026-09-23: "remove the ads
        // cost" then "the ads remove that") — every one of them was always
        // 0.00 in the source sheet's own columns anyway.
        'shipping_fee'           => 'Shipping Fee',
        'cod_fee'                => 'COD Fee',
        'fulfillment_fee'        => 'Fulfillment Fee',
        'product_research'       => 'Product Research',
    ];

    /** Same idea, for "Operating Costs". */
    public const OPERATING_COST_ROWS = [
        'salaries'                    => 'Salaries',
        'communication_allowance'     => 'Communication Allowance',
        'thirteenth_month_allowance'  => '13th Month Allowance',
        'sil'                         => 'SIL',
        'government_benefits'         => 'Government Benefits',
        'miscellaneous_expenses'      => 'Miscellaneous expenses',
        'magic_fund'                  => 'Magic Fund',
        'company_assets'              => 'Company Assets',
        'executive_benefits'          => 'Executive Benefits',
        'office_miscellaneous'        => 'Office Miscellaneous',
        'maintenance_expenses'        => 'Maintenance Expenses',
        'consultants'                 => 'Consultants',
        'managers_allowance'          => "Manager's Allowance",
        'birthday_cake_allowance'     => 'Birthday Cake Allowance',
        'water_bill'                  => 'Water Bill',
        'internet'                    => 'Internet',
        'rent'                        => 'Rent',
        'electricity'                 => 'Electricity',
        'geniusmakers_management_fee' => 'Geniusmakers Management Fee',
        'business_development_fund'   => 'Business Development Fund',
        'hmo_expense'                 => 'HMO Expense',
    ];

    /** Reads one rate from Settings, seeding it from DEFAULT_RATES the
     *  first time it's ever asked for — so a fresh row in DEFAULT_RATES
     *  (a rate added later) still works immediately without a separate
     *  migration/seeder to backfill Settings for it. */
    public static function rate(string $key): float
    {
        $stored = Setting::get("projection_rate.{$key}");
        return $stored !== null ? (float) $stored : (self::DEFAULT_RATES[$key] ?? 0.0);
    }

    /** Every rate at once, keyed the same as DEFAULT_RATES — what the
     *  Rates settings form reads to pre-fill its own inputs, and what
     *  forColumn() below uses so a single page render only touches
     *  Settings once per rate, not once per rate PER column. */
    public static function allRates(): array
    {
        return collect(array_keys(self::DEFAULT_RATES))
            ->mapWithKeys(fn ($key) => [$key => self::rate($key)])
            ->all();
    }

    /** Telesales Department's own multiplier against Opening Shift's P&L
     *  — the one derived-column ratio that's a true fixed constant (2, not
     *  driven by any editable field: Telesales = 2 shifts' worth). See
     *  this class's own doc comment for how it was verified. Individual
     *  TSA Monthly/Daily's own divisors are NOT constants — see
     *  forAllColumns() below, which reads Opening Shift's live tsa_count
     *  instead. */
    public const TELESALES_DEPARTMENT_MULTIPLIER = 2.0;

    /** Individual TSA Daily = Individual TSA Monthly ÷ this many working
     *  days/month, for every dollar line — confirmed against the sheet
     *  (320,000 ÷ 24 = 13,333.33, the sheet's own real Daily Gross Sales).
     *  Unlike the Monthly ÷ tsa_count divisor above, no sheet evidence
     *  linked this to any OTHER editable cell, so it stays a constant. */
    public const DAILY_WORKING_DAYS = 24;

    /** # of Orders specifically uses a DIFFERENT divisor for the Daily
     *  column than every other dollar line (25, not 24) — confirmed
     *  against the sheet's own stated Daily order count (400 ÷ 25 = 16)
     *  and kept as the sheet's own inconsistency rather than unified
     *  (explicit decision, 2026-09-23, asked directly after finding it). */
    public const DAILY_ORDERS_DIVISOR = 25;

    /** Fulfillment Fee on the Daily column is a flat rate against DAILY's
     *  OWN Gross Sales, not Monthly's Fulfillment Fee ÷ 24 like every
     *  other cost line — same sheet-inconsistency-preserved reasoning as
     *  DAILY_ORDERS_DIVISOR above; verified exact (13,333.33 × 3% = 400,
     *  the sheet's own real Daily Fulfillment Fee). */
    public const DAILY_FULFILLMENT_FEE_RATE = 0.03;

    /** The target card (Net Income Target, AOV, Total Orders Needed, ...)
     *  for one column — independent per column, NOT part of the ×2/÷6/÷24
     *  chain (see this class's own doc comment). Every column, including
     *  the 3 derived ones, keeps its own separate target inputs. */
    private static function targetCard(ProjectionColumn $column, array $rates): array
    {
        $target = (float) $column->net_income_target;
        $aov    = (float) $column->average_order_value;

        // Total Orders Needed = Net Income Target ÷ (AOV × Target Margin
        // %) — verified exact against the real sheet for every column.
        $ordersNeeded = ($aov > 0 && $rates['target_margin'] > 0)
            ? $target / ($aov * $rates['target_margin'])
            : 0.0;

        $leadsNeeded = $rates['conversion_rate'] > 0 ? $ordersNeeded / $rates['conversion_rate'] : 0.0;
        $pickupRate  = $leadsNeeded * $rates['pickup_rate'];

        return [
            'net_income_target' => $target,
            'average_order_value' => $aov,
            'tsa_count' => $column->tsa_count,
            'orders_needed' => $ordersNeeded,
            'leads_needed' => $leadsNeeded,
            'pickup_rate' => $pickupRate,
            // 1:1 with Orders Needed — verified against the real sheet.
            'upselling_rate' => $ordersNeeded,
        ];
    }

    /** Opening Shift's own P&L — the ONE column actually computed from
     *  Orders × AOV × rate (see this class's own doc comment); every
     *  other column's P&L is derived FROM this one, never computed this
     *  way itself. orders_override lives here too (explicit request,
     *  2026-09-23: "the Gross Sales is editable and the number of
     *  orders") — overriding Opening Shift's own # of Orders cascades
     *  into the other 3 cards exactly like editing a rate does, matching
     *  how the real sheet's own formulas would recalculate downstream
     *  cells from an upstream one. */
    private static function basePnl(ProjectionColumn $openingShift, array $rates): array
    {
        $aov = (float) $openingShift->average_order_value;
        $target = (float) $openingShift->net_income_target;

        $ordersNeeded = ($aov > 0 && $rates['target_margin'] > 0)
            ? $target / ($aov * $rates['target_margin'])
            : 0.0;

        $orders     = $openingShift->orders_override ?? $ordersNeeded;
        $grossSales = $orders * $aov;

        return self::pnlFromOrders($orders, $grossSales, $rates);
    }

    /** The actual %-of-Gross-Sales P&L math, factored out so both
     *  basePnl() (Opening Shift) and the Daily column's own
     *  Fulfillment-Fee exception (which needs this against a DIFFERENT
     *  Gross Sales than the one its other lines derive from) can share
     *  it without duplicating the cost-line loop. */
    private static function pnlFromOrders(float $orders, float $grossSales, array $rates): array
    {
        $cancelled   = $grossSales * $rates['cancelled'];
        $returns     = $grossSales * $rates['returns'];
        $delivered   = $grossSales * $rates['delivered']; // informational only — never subtracted
        $tax         = $grossSales * $rates['tax_allocation'];
        $productCost = $grossSales * $rates['product_cost'];
        $grossProfit = $grossSales - $cancelled - $returns - $tax - $productCost;

        $sellingLines = collect(self::SELLING_COST_ROWS)->keys()
            ->mapWithKeys(fn ($key) => [$key => $grossSales * ($rates[$key] ?? 0)]);
        $totalSellingCosts = $sellingLines->sum();

        $operatingLines = collect(self::OPERATING_COST_ROWS)->keys()
            ->mapWithKeys(fn ($key) => [$key => $grossSales * ($rates[$key] ?? 0)]);
        $totalOperatingCosts = $operatingLines->sum();

        $netIncome = $grossProfit - $totalSellingCosts - $totalOperatingCosts;

        return [
            'orders' => $orders,
            'gross_sales' => $grossSales,
            'cancelled' => $cancelled,
            'returns' => $returns,
            'delivered' => $delivered,
            'tax_allocation' => $tax,
            'product_cost' => $productCost,
            'gross_profit' => $grossProfit,
            'gross_profit_pct' => $grossSales > 0 ? $grossProfit / $grossSales : 0.0,
            'selling_lines' => $sellingLines,
            'total_selling_costs' => $totalSellingCosts,
            'total_selling_costs_pct' => $grossSales > 0 ? $totalSellingCosts / $grossSales : 0.0,
            'operating_lines' => $operatingLines,
            'total_operating_costs' => $totalOperatingCosts,
            'total_operating_costs_pct' => $grossSales > 0 ? $totalOperatingCosts / $grossSales : 0.0,
            'net_income' => $netIncome,
            'net_income_pct' => $grossSales > 0 ? $netIncome / $grossSales : 0.0,
        ];
    }

    /** Multiplies every dollar line in a P&L array by a flat factor — the
     *  ×2 (Telesales) / ÷6 (Individual Monthly) derivation. Percentages
     *  are untouched (multiplying numerator and denominator by the same
     *  factor leaves a ratio unchanged) and 'orders' scales right along
     *  with every dollar line since it's ALSO fixed to that same
     *  multiplier for these two columns (only Daily's own # of Orders
     *  breaks from its P&L's own multiplier — handled separately in
     *  forAllColumns()). */
    private static function scalePnl(array $pnl, float $factor): array
    {
        return [
            'orders' => $pnl['orders'] * $factor,
            'gross_sales' => $pnl['gross_sales'] * $factor,
            'cancelled' => $pnl['cancelled'] * $factor,
            'returns' => $pnl['returns'] * $factor,
            'delivered' => $pnl['delivered'] * $factor,
            'tax_allocation' => $pnl['tax_allocation'] * $factor,
            'product_cost' => $pnl['product_cost'] * $factor,
            'gross_profit' => $pnl['gross_profit'] * $factor,
            'gross_profit_pct' => $pnl['gross_profit_pct'],
            'selling_lines' => $pnl['selling_lines']->map(fn ($v) => $v * $factor),
            'total_selling_costs' => $pnl['total_selling_costs'] * $factor,
            'total_selling_costs_pct' => $pnl['total_selling_costs_pct'],
            'operating_lines' => $pnl['operating_lines']->map(fn ($v) => $v * $factor),
            'total_operating_costs' => $pnl['total_operating_costs'] * $factor,
            'total_operating_costs_pct' => $pnl['total_operating_costs_pct'],
            'net_income' => $pnl['net_income'] * $factor,
            'net_income_pct' => $pnl['net_income_pct'],
        ];
    }

    /** The full computed P&L + target card for EVERY column at once — the
     *  cross-card chain (see this class's own doc comment) means the 3
     *  derived columns can't be computed one at a time in isolation
     *  anymore; Opening Shift's own P&L has to exist first. $columns is
     *  every ProjectionColumn keyed by its own `key` column. Returns the
     *  same shape keyed the same way, each entry shaped exactly like the
     *  old forColumn()'s own return value (so the view/controller layer
     *  didn't need to change). */
    public static function forAllColumns(\Illuminate\Support\Collection $columns, array $rates): array
    {
        $byKey = $columns->keyBy('key');
        $openingShift = $byKey->get('opening_shift');

        $basePnl = $openingShift ? self::basePnl($openingShift, $rates) : self::pnlFromOrders(0, 0, $rates);

        // Individual TSA Monthly's own divisor is Opening Shift's LIVE
        // tsa_count (see this class's own doc comment for the evidence),
        // not a hardcoded 6 — falls back to 1 (no-op divide) only in the
        // pathological case of tsa_count being 0/missing, same guard
        // style as every other divide-by-zero check in this class.
        $tsaCount = ($openingShift?->tsa_count ?? 0) > 0 ? $openingShift->tsa_count : 1;

        $derivedFactors = [
            'telesales_department'   => self::TELESALES_DEPARTMENT_MULTIPLIER,
            'individual_tsa_monthly' => 1 / $tsaCount,
            'individual_tsa_daily'   => 1 / $tsaCount / self::DAILY_WORKING_DAYS,
        ];

        return $byKey->mapWithKeys(function (ProjectionColumn $column, string $key) use ($basePnl, $rates, $derivedFactors, $tsaCount) {
            if ($key === 'opening_shift') {
                $pnl = $basePnl;
            } else {
                $factor = $derivedFactors[$key] ?? 1.0;
                $pnl = self::scalePnl($basePnl, $factor);

                // The Daily column's own two sheet-inconsistencies (see
                // this class's own doc comment) — applied AFTER the
                // uniform ÷tsaCount÷24 scale above, overwriting just these
                // two lines rather than folding them into scalePnl()'s own
                // flat multiplier, since they don't follow it.
                if ($key === 'individual_tsa_daily') {
                    $pnl['orders'] = $basePnl['orders'] / $tsaCount / self::DAILY_ORDERS_DIVISOR;

                    $fulfillmentFee = $pnl['gross_sales'] * self::DAILY_FULFILLMENT_FEE_RATE;
                    $delta = $fulfillmentFee - ($pnl['selling_lines']['fulfillment_fee'] ?? 0.0);
                    $pnl['selling_lines'] = $pnl['selling_lines']->put('fulfillment_fee', $fulfillmentFee);
                    $pnl['total_selling_costs'] += $delta;
                    $pnl['total_selling_costs_pct'] = $pnl['gross_sales'] > 0 ? $pnl['total_selling_costs'] / $pnl['gross_sales'] : 0.0;
                    $pnl['net_income'] -= $delta;
                    $pnl['net_income_pct'] = $pnl['gross_sales'] > 0 ? $pnl['net_income'] / $pnl['gross_sales'] : 0.0;
                }
            }

            return [$key => [
                'column' => $column,
                'target_card' => self::targetCard($column, $rates),
                'pnl' => $pnl,
            ]];
        })->all();
    }

    /** Back-compat single-column accessor — used where only one column's
     *  figures are needed (e.g. updateColumn()'s own JSON response after
     *  a single field edit). Recomputes ALL columns internally (the chain
     *  means there's no way to get one column's true figures without
     *  Opening Shift's own base P&L existing first) and returns just the
     *  requested one. */
    public static function forColumn(ProjectionColumn $column, array $rates, ?\Illuminate\Support\Collection $allColumns = null): array
    {
        $columns = $allColumns ?? ProjectionColumn::orderBy('sort_order')->get();
        $all = self::forAllColumns($columns, $rates);

        return $all[$column->key] ?? [
            'column' => $column,
            'target_card' => self::targetCard($column, $rates),
            'pnl' => self::pnlFromOrders(0, 0, $rates),
        ];
    }
}
