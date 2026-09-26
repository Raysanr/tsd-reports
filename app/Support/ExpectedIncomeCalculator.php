<?php

namespace App\Support;

/**
 * TSD Data Management — Expected Income 2026's own derived-figure math
 * (explicit request, 2026-09-26). Every formula below was confirmed against
 * the real "EXPECTED INCOME 2026" tab's own Clearsight row (Leads 647,
 * Orders 112, AOV 804.46 → Gross Sales 90,100, Cancelled 4,505/5%, Returns
 * 22,525/25%, Delivered 63,070/70%) before being hardcoded here — same
 * Cancelled/Returns/Delivered rates as ProjectionCalculator, and the same
 * Gross Profit/COD Fee/Fulfillment Fee formulas, reused as-is since those
 * are shared, already-verified P&L mechanics, not sheet-specific numbers.
 *
 * Tax Allocation, Product Cost, ROAS, Actual Cost Per Lead, and every
 * Selling & Marketing/Operating Costs row are plain manual inputs, NOT
 * derived — see create_expected_income_entries_table's own doc comment for
 * why (both diverged from Projections' shared rates when checked against
 * real sheet numbers, and the rest were never verified at all).
 *
 * Takes a plain array of raw fields (not an ExpectedIncomeEntry model
 * directly) so the same math works for a single product row AND a summed
 * "TEAM" row (Team Eyecare = sum of its own products), which is never a
 * real persisted entry — see sum() below, same convention as
 * DsPprCalculator::sum().
 */
class ExpectedIncomeCalculator
{
    /** Same two row lists as ProjectionCalculator, minus cod_fee/
     *  fulfillment_fee (computed here, not stored — see this class's own
     *  doc comment) — shared display order for the view to render without
     *  hand-typing the list twice. */
    public const SELLING_COST_ROWS = [
        'advertising_cost'      => 'Advertising Cost',
        'ads_vat'                => 'Ads VAT',
        'ai_expense'             => 'AI Expense',
        'ad_account_rental_fee'  => 'Ad Account Rental Fee',
        'shipping_fee'           => 'Shipping Fee',
        'product_research'       => 'Product Research',
    ];

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

    /** Cancelled/Returns/Delivered rates confirmed exact against the real
     *  Clearsight row — same values as ProjectionCalculator::DEFAULT_RATES,
     *  duplicated (not shared) since this page's rates are never meant to
     *  be independently editable here the way Projections' are. */
    public const CANCELLED_RATE = 0.05;
    public const RETURNS_RATE = 0.25;
    public const DELIVERED_RATE = 0.70;

    /** Same two confirmed real formulas as ProjectionCalculator — see that
     *  class's own COD_FEE_RATE_OF_DELIVERED/FULFILLMENT_FEE_PER_ORDER doc
     *  comments. Reused as-is per explicit decision (courier/logistics
     *  fees, not product-specific costs, so unlikely to differ per page). */
    public const COD_FEE_RATE_OF_DELIVERED = 0.0224;
    public const FULFILLMENT_FEE_PER_ORDER = 25.0;

    /** One row's full set of derived figures from its manual inputs. */
    public static function derive(array $row): array
    {
        $roas               = (float) ($row['roas'] ?? 0);
        $actualCostPerLead  = (float) ($row['actual_cost_per_lead'] ?? 0);
        $leads              = (float) ($row['number_of_leads'] ?? 0);
        $orders             = (float) ($row['number_of_orders'] ?? 0);
        $aov                = (float) ($row['average_order_value'] ?? 0);
        $taxAllocation      = (float) ($row['tax_allocation'] ?? 0);
        $productCost        = (float) ($row['product_cost'] ?? 0);

        $conversionRate = $leads > 0 ? $orders / $leads : 0.0;
        $grossSales     = $orders * $aov;
        $cancelled      = $grossSales * self::CANCELLED_RATE;
        $returns        = $grossSales * self::RETURNS_RATE;
        $delivered      = $grossSales * self::DELIVERED_RATE;
        $grossProfit    = $grossSales - $cancelled - $returns - $taxAllocation - $productCost;

        $sellingLines = collect(self::SELLING_COST_ROWS)->keys()
            ->mapWithKeys(fn ($key) => [$key => (float) ($row[$key] ?? 0)])
            ->put('cod_fee', $delivered * self::COD_FEE_RATE_OF_DELIVERED)
            ->put('fulfillment_fee', $orders * self::FULFILLMENT_FEE_PER_ORDER);
        $totalSellingCosts = $sellingLines->sum();

        $operatingLines = collect(self::OPERATING_COST_ROWS)->keys()
            ->mapWithKeys(fn ($key) => [$key => (float) ($row[$key] ?? 0)]);
        $totalOperatingCosts = $operatingLines->sum();

        $netIncome = $grossProfit - $totalSellingCosts - $totalOperatingCosts;

        return [
            'roas' => $roas,
            'actual_cost_per_lead' => $actualCostPerLead,
            'number_of_leads' => $leads,
            'conversion_rate' => $conversionRate,
            'number_of_orders' => $orders,
            'average_order_value' => $aov,

            'gross_sales' => $grossSales,
            'cancelled' => $cancelled,
            'cancelled_pct' => self::CANCELLED_RATE,
            'returns' => $returns,
            'returns_pct' => self::RETURNS_RATE,
            'delivered' => $delivered,
            'delivered_pct' => self::DELIVERED_RATE,
            'tax_allocation' => $taxAllocation,
            'tax_allocation_pct' => $grossSales > 0 ? $taxAllocation / $grossSales : 0.0,
            'product_cost' => $productCost,
            'product_cost_pct' => $grossSales > 0 ? $productCost / $grossSales : 0.0,
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

    /** Sums N raw rows' own inputs, then derives the summed row's own
     *  figures fresh from those totals — confirmed-exact "recompute the
     *  ratio from summed dollars" convention, same as
     *  ProjectionCalculator::sumPnl() / DsPprCalculator::sum(). Used for a
     *  TEAM row (e.g. Team Eyecare = Clearsight + Pterygium + every other
     *  Eyecare product), verified exact against the real sheet (Team
     *  Eyecare's Gross Sales/Tax Allocation both matched the sum of its own
     *  3 real products to the cent).
     *
     *  ROAS, Actual Cost Per Lead, and Conversion Rate are the exception —
     *  plain averages of each row's own value, not a ratio recomputed from
     *  summed totals, same reasoning as DsPprCalculator::sum()'s own
     *  pickup_rate/conversion_rate/upselling_rate handling (these 3 aren't
     *  meaningfully "summed", only averaged). */
    public static function sum(array $rows): array
    {
        $sumKeys = array_merge(
            ['number_of_leads', 'number_of_orders', 'tax_allocation', 'product_cost'],
            array_keys(self::SELLING_COST_ROWS),
            array_keys(self::OPERATING_COST_ROWS)
        );

        $totals = array_fill_keys($sumKeys, 0.0);
        foreach ($rows as $row) {
            foreach ($sumKeys as $key) {
                $totals[$key] += (float) ($row[$key] ?? 0);
            }
        }

        // Average Order Value for the summed row: total Gross Sales ÷ total
        // Orders, so downstream Gross Sales = Orders × AOV still reproduces
        // the real summed Gross Sales exactly (a plain average of each
        // row's own AOV would not, since orders differ per product).
        $totalGrossSales = 0.0;
        foreach ($rows as $row) {
            $totalGrossSales += (float) ($row['number_of_orders'] ?? 0) * (float) ($row['average_order_value'] ?? 0);
        }
        $totals['average_order_value'] = $totals['number_of_orders'] > 0
            ? $totalGrossSales / $totals['number_of_orders']
            : 0.0;

        $summed = self::derive($totals);

        $rowCount = count($rows);
        if ($rowCount > 0) {
            $perRow = array_map(fn ($row) => self::derive($row), $rows);
            $summed['roas'] = array_sum(array_column($perRow, 'roas')) / $rowCount;
            $summed['actual_cost_per_lead'] = array_sum(array_column($perRow, 'actual_cost_per_lead')) / $rowCount;
        }

        return $summed;
    }
}
