<?php

namespace App\Support;

use App\Models\ProjectionCustomRow;

/**
 * TSD Data Management — Expected Income 2026's own derived-figure math
 * (explicit request, 2026-09-26). Gross Profit/COD Fee/Fulfillment Fee
 * below are the same confirmed-real formulas as ProjectionCalculator,
 * reused as-is since those are shared, already-verified P&L mechanics, not
 * sheet-specific numbers.
 *
 * Gross Sales, Cancelled, Projected Returns, and Projected Delivered used
 * to all be derived — Gross Sales = Orders × AOV, then Cancelled/Returns/
 * Delivered as fixed 5%/25%/70% rates of that. Changed to plain manual
 * inputs (explicit request, 2026-09-28: "in the expected i want you to
 * make all is manually input"), then PARTIALLY reverted (explicit
 * correction, 2026-10-01, confirmed against the real sheet's own live
 * formulas: "the only auto is Projected Returns / Projected Delivered") —
 * Gross Sales and Cancelled stay plain manual inputs, but Projected
 * Returns (Gross Sales × 25%) and Projected Delivered (Gross Sales −
 * Cancelled − Returns) are derived again, same rates as before. Tax
 * Allocation/Product Cost/ROAS/Actual Cost Per Lead/every Selling &
 * Marketing & Operating Costs row stay manual — only Returns/Delivered
 * came back. Average Order Value is still a plain display-only stat with
 * nothing downstream reading it.
 *
 * Takes a plain array of raw fields (not an ExpectedIncomeEntry model
 * directly) so the same math works for a single product row AND a summed
 * "TEAM" row (Team Eyecare = sum of its own products), which is never a
 * real persisted entry — see sum() below, same convention as
 * DsPprCalculator::sum().
 */
class ExpectedIncomeCalculator
{
    /** Same row list as ProjectionCalculator — cod_fee/fulfillment_fee
     *  INCLUDED here too as of 2026-10-02 (explicit request: "can you make
     *  the row can be draggable" — confirmed to cover every row including
     *  these 2, so they needed to join the same orderable list instead of
     *  rendering as 2 permanently-last hardcoded rows outside it). Their
     *  own VALUE is still always computed (Delivered × 2.24%, Orders ×
     *  ₱25 flat — see COD_FEE_RATE_OF_DELIVERED/FULFILLMENT_FEE_PER_ORDER
     *  below), never a manual entry — nonEditableRows() below is what
     *  keeps them non-editable now that they're inside the same loop as
     *  every real manual-rate row. */
    public const SELLING_COST_ROWS = [
        'advertising_cost'      => 'Advertising Cost',
        'ads_vat'                => 'Ads VAT',
        'ai_expense'             => 'Projected Botcake AI Expense',
        'shipping_fee'           => 'Shipping Fee',
        'cod_fee'                => 'COD Fee',
        'fulfillment_fee'        => 'Fulfillment Fee',
    ];

    /** cod_fee/fulfillment_fee are computed formulas, never a manual $
     *  input — same meaning as ProjectionCalculator::NON_EDITABLE_SELLING_ROWS,
     *  duplicated here rather than shared since the two classes' own
     *  $d['selling_lines'] are independently computed (see this class's
     *  own doc comment on why COD_FEE_RATE_OF_DELIVERED/
     *  FULFILLMENT_FEE_PER_ORDER are reused as CONSTANTS but not as a
     *  shared code path). */
    public const NON_EDITABLE_SELLING_ROWS = ['cod_fee', 'fulfillment_fee'];

    public const OPERATING_COST_ROWS = [
        'salaries'                    => 'Salaries',
        'communication_allowance'     => 'Communication Allowance',
        'thirteenth_month_allowance'  => '13th Month Allowance',
        'sil'                         => 'SIL',
        'government_benefits'         => 'Government Benefits',
        // Moved here from Selling & Marketing (explicit request, 2026-09-28,
        // real template screenshot) — sits right after Government Benefits.
        'product_research'            => 'Product Research',
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

    /** Same two confirmed real formulas as ProjectionCalculator — see that
     *  class's own COD_FEE_RATE_OF_DELIVERED/FULFILLMENT_FEE_PER_ORDER doc
     *  comments. Reused as-is per explicit decision (courier/logistics
     *  fees, not product-specific costs, so unlikely to differ per page). */
    public const COD_FEE_RATE_OF_DELIVERED = 0.0224;
    public const FULFILLMENT_FEE_PER_ORDER = 25.0;

    /** Projected Returns' own confirmed-exact rate — the real sheet's own
     *  J306 formula ('=J304*K306', K306=0.25). Projected Delivered has no
     *  rate of its own; it's Gross Sales − Cancelled − Returns (a plain
     *  subtraction, confirmed J307='=J304-J305-J306'). */
    public const PROJECTED_RETURNS_RATE = 0.25;

    /** Every row added via the + icon on Projections (projection_custom_rows
     *  — a SHARED definition table, not scoped to that page) — explicit
     *  request, 2026-09-28: "when i add in the + icon in the projections it
     *  should be automatically added to the expected income rows." Reads
     *  the identical table ProjectionCalculator::sellingCostRows()/
     *  operatingCostRows() already read, so a row created on Projections
     *  shows up here automatically with zero extra step. Deliberately just
     *  the row's NAME/section (its label, merged into the same key=>label
     *  shape as the built-in constants) — its actual dollar VALUE here is
     *  a separate manual per-product-per-day entry
     *  (ExpectedIncomeCustomValue), never synced from Projections' own
     *  %-of-Gross-Sales rate, since the two pages' value models are
     *  fundamentally different (a shared rate vs. a typed-in amount per
     *  product per day) — explicit decision confirmed with the user rather
     *  than guessing a conversion between the two.
     *
     *  Not cached on a static property, same reasoning as
     *  ProjectionCalculator::customRows()'s own doc comment — this table
     *  is tiny and a stale cache would risk a freshly-added row missing
     *  from a summed row within the same request. */
    private static function customRows(): \Illuminate\Support\Collection
    {
        return ProjectionCustomRow::orderBy('sort_order')->get();
    }

    /** Same meaning as ProjectionCalculator::LOCKED_TO_SELLING — defined
     *  once on RowOrder, delegated here so both calculators read the one
     *  real list. */
    public const LOCKED_TO_SELLING = RowOrder::LOCKED_TO_SELLING;

    /** SELLING_COST_ROWS plus every custom row from Projections, in the
     *  same key => label shape — the view and every derive()/sum() call
     *  below use this instead of the bare constant so a custom row
     *  automatically participates in Total Selling Costs / Net Income,
     *  with zero other code changes (same "one definition, every consumer
     *  reads it fresh" reasoning as ProjectionCalculator's own
     *  sellingCostRows()).
     *
     *  Ordering delegated to RowOrder (explicit request, 2026-10-02: "can
     *  you make the row can be draggable and can change the position by
     *  other row") — the SAME shared order ProjectionCalculator's own
     *  sellingCostRows() reads, so dragging a row on either page reorders
     *  both.
     *
     *  2026-10-06 passes OPERATING_COST_ROWS as $otherBuiltinRows — same
     *  reasoning as ProjectionCalculator's own identical change, see that
     *  method's own doc comment. */
    public static function sellingCostRows(): array
    {
        return RowOrder::rows(self::SELLING_COST_ROWS, 'selling', self::OPERATING_COST_ROWS);
    }

    public static function operatingCostRows(): array
    {
        return RowOrder::rows(self::OPERATING_COST_ROWS, 'operating', self::SELLING_COST_ROWS);
    }

    /** NON_EDITABLE_SELLING_ROWS plus every custom row marked is_fixed —
     *  same meaning/shape as ProjectionCalculator::nonEditableRows(). */
    public static function nonEditableRows(): array
    {
        $fixedCustom = self::customRows()->where('is_fixed', true)->pluck('key')->all();

        return array_merge(self::NON_EDITABLE_SELLING_ROWS, $fixedCustom);
    }

    /** Every custom row's own key, both sections combined — used by
     *  ExpectedIncomeController to know which extra fields to merge an
     *  ExpectedIncomeCustomValue onto a raw entry array for before it
     *  reaches derive()/sum(), same idea as ProjectionCalculator's own
     *  customRowKeys(). */
    public static function customRowKeys(): array
    {
        return self::customRows()->pluck('key')->all();
    }

    /** One row's full set of derived figures from its manual inputs.
     *
     *  $sellingKeys/$operatingKeys default to the plain built-in constants
     *  — deliberately NOT sellingCostRows()/operatingCostRows() (which
     *  query ProjectionCustomRow) called from inside this method itself.
     *  derive()/sum() are pure functions with no DB access by design (see
     *  ExpectedIncomeCalculatorTest, a plain PHPUnit\Framework\TestCase
     *  with zero framework/DB bootstrap on purpose, verifying this math
     *  against real spreadsheet numbers) — a caller that needs custom rows
     *  included (ExpectedIncomeController) computes sellingCostRows()/
     *  operatingCostRows() ONCE itself (it already has DB access) and
     *  passes the resulting key lists in explicitly, same "controller
     *  fetches, calculator only computes" separation the rest of this
     *  class already follows for row VALUES. */
    public static function derive(array $row, ?array $sellingKeys = null, ?array $operatingKeys = null): array
    {
        $sellingKeys ??= array_keys(self::SELLING_COST_ROWS);
        $operatingKeys ??= array_keys(self::OPERATING_COST_ROWS);

        $roas               = (float) ($row['roas'] ?? 0);
        $standardCostPerMessage = (float) ($row['standard_cost_per_message'] ?? 0);
        $actualCostPerLead  = (float) ($row['actual_cost_per_lead'] ?? 0);
        $leads              = (float) ($row['number_of_leads'] ?? 0);
        $orders             = (float) ($row['number_of_orders'] ?? 0);
        $aov                = (float) ($row['average_order_value'] ?? 0);
        $taxAllocation      = (float) ($row['tax_allocation'] ?? 0);
        $productCost        = (float) ($row['product_cost'] ?? 0);

        // Gross Sales and Cancelled are plain manual inputs (explicit
        // request, 2026-09-28: "in the expected i want you to make all is
        // manually input"). Projected Returns and Projected Delivered are
        // back to being DERIVED from Gross Sales (explicit correction,
        // 2026-10-01, confirmed against the real sheet's own formulas:
        // "the only auto is Projected Returns / Projected Delivered") —
        // Returns = Gross Sales × 25%, Delivered = Gross Sales − Cancelled
        // − Returns (= Gross Sales × 70% when Cancelled is also at its own
        // 5%-of-Gross-Sales rate, but Delivered's own formula is a plain
        // subtraction, not its own independent rate — confirmed exact
        // against the real "TEAM CLOSING SHIFT" tab's own cell formulas,
        // J306='=J304*K306' (25%), J307='=J304-J305-J306'). See this
        // class's own doc comment for the confirmed-exact 5%/25% rates.
        $conversionRate = $leads > 0 ? $orders / $leads : 0.0;
        $grossSales     = (float) ($row['gross_sales'] ?? 0);
        $cancelled      = (float) ($row['cancelled'] ?? 0);
        $returns        = $grossSales * self::PROJECTED_RETURNS_RATE;
        $delivered      = $grossSales - $cancelled - $returns;
        $grossProfit    = $grossSales - $cancelled - $returns - $taxAllocation - $productCost;

        // Safe to unconditionally ->put() these 2 keys below regardless of
        // $sellingKeys' actual contents — same reasoning as
        // ProjectionCalculator::pnlFromOrders()'s identical comment:
        // RowOrder::moveAfter() refuses to let cod_fee/fulfillment_fee
        // (LOCKED_TO_SELLING) cross into 'operating', so they're always
        // among sellingCostRows()'s own keys (and SELLING_COST_ROWS' own,
        // the default $sellingKeys falls back to below).
        $sellingLines = collect($sellingKeys)
            ->mapWithKeys(fn ($key) => [$key => (float) ($row[$key] ?? 0)])
            ->put('cod_fee', $delivered * self::COD_FEE_RATE_OF_DELIVERED)
            ->put('fulfillment_fee', $orders * self::FULFILLMENT_FEE_PER_ORDER);
        $totalSellingCosts = $sellingLines->sum();

        $operatingLines = collect($operatingKeys)
            ->mapWithKeys(fn ($key) => [$key => (float) ($row[$key] ?? 0)]);
        $totalOperatingCosts = $operatingLines->sum();

        // Explicit request, 2026-09-28 (real template screenshot): a
        // subtotal between Total Selling Costs and Operating Costs —
        // Gross Profit minus Selling & Marketing costs only, before
        // Operating Costs are subtracted.
        $incomeBeforeOpex = $grossProfit - $totalSellingCosts;

        $netIncome = $incomeBeforeOpex - $totalOperatingCosts;

        return [
            'roas' => $roas,
            'standard_cost_per_message' => $standardCostPerMessage,
            'actual_cost_per_lead' => $actualCostPerLead,
            'number_of_leads' => $leads,
            'conversion_rate' => $conversionRate,
            'number_of_orders' => $orders,
            'average_order_value' => $aov,

            'gross_sales' => $grossSales,
            'gross_sales_pct' => $grossSales > 0 ? 1.0 : 0.0,
            'cancelled' => $cancelled,
            'cancelled_pct' => $grossSales > 0 ? $cancelled / $grossSales : 0.0,
            'returns' => $returns,
            'returns_pct' => $grossSales > 0 ? $returns / $grossSales : 0.0,
            'delivered' => $delivered,
            'delivered_pct' => $grossSales > 0 ? $delivered / $grossSales : 0.0,
            'tax_allocation' => $taxAllocation,
            'tax_allocation_pct' => $grossSales > 0 ? $taxAllocation / $grossSales : 0.0,
            'product_cost' => $productCost,
            'product_cost_pct' => $grossSales > 0 ? $productCost / $grossSales : 0.0,
            'gross_profit' => $grossProfit,
            'gross_profit_pct' => $grossSales > 0 ? $grossProfit / $grossSales : 0.0,

            'selling_lines' => $sellingLines,
            'total_selling_costs' => $totalSellingCosts,
            'total_selling_costs_pct' => $grossSales > 0 ? $totalSellingCosts / $grossSales : 0.0,
            'income_before_opex' => $incomeBeforeOpex,
            'income_before_opex_pct' => $grossSales > 0 ? $incomeBeforeOpex / $grossSales : 0.0,
            'operating_lines' => $operatingLines,
            'total_operating_costs' => $totalOperatingCosts,
            'total_operating_costs_pct' => $grossSales > 0 ? $totalOperatingCosts / $grossSales : 0.0,

            'net_income' => $netIncome,
            'net_income_pct' => $grossSales > 0 ? $netIncome / $grossSales : 0.0,
        ];
    }

    /** Combines two already-derive()d/sum()d rows into one, as if every raw
     *  row behind both had been summed together from the start — used to
     *  fold TikTok's own site-wide total into TELESALES' overall total
     *  (explicit request, 2026-10-06: "it will be remove this card TIKTOK
     *  TOTAL because the overall total is will be TELESALES" — TikTok no
     *  longer gets its own separate TOTAL card; TELESALES becomes the
     *  true site-wide figure, real products + TikTok combined).
     *
     *  Can't just pass $a/$b straight into sum() — sum()'s own
     *  $row[$key] ?? 0 lookups only find Selling/Operating lines as
     *  TOP-LEVEL keys, but a derive()d row nests those under
     *  selling_lines/operating_lines instead (same root cause
     *  buildSummaryRow()'s own doc comment already documents for an
     *  identical bug) — so each row's own lines are flattened back to
     *  top-level first. */
    public static function addDerivedTotals(array $a, array $b, ?array $sellingKeys = null, ?array $operatingKeys = null): array
    {
        $sellingKeys ??= array_keys(self::SELLING_COST_ROWS);
        $operatingKeys ??= array_keys(self::OPERATING_COST_ROWS);

        // selling_lines/operating_lines are Collections on a derive()d row
        // (not plain arrays) — ->toArray() first or array_merge() blows up.
        $flatten = fn (array $row) => array_merge(
            $row,
            collect($row['selling_lines'] ?? [])->toArray(),
            collect($row['operating_lines'] ?? [])->toArray()
        );

        return self::sum([$flatten($a), $flatten($b)], $sellingKeys, $operatingKeys);
    }

    /** Overrides an already-derived row's own Operating Costs lines with
     *  figures computed elsewhere (Cost Breakdown's own per-TSA Daily Rate
     *  / Product for Salaries, and its "Daily Cost per product" mini-table
     *  for every other shared-pool row — explicit request, 2026-09-30:
     *  "the salaries row is based to the Daily Rate / Product", then
     *  "the daily cost is it is this [Communication Allowance, 13th Month
     *  Allowance, SIL, ...]"), then recomputes every figure that cascades
     *  from Total Operating Costs: Net Income and every affected _pct
     *  (Income Before OPEX and the Selling side of the P&L are unaffected
     *  — Operating Costs sits entirely downstream of those). Takes an
     *  already-derive()d/sum()d row rather than re-deriving from raw
     *  inputs, same "controller overrides, calculator only recomputes the
     *  cascade" separation as the rest of this class.
     *
     *  $overrides: ['operating_key' => value, ...] — any subset of
     *  operating_lines' own keys; a key not present here keeps its
     *  existing (manually-entered) value untouched. Keys in $overrides
     *  that AREN'T already one of operating_lines' own keys are silently
     *  ignored (e.g. a caller passing through CostBreakdownCalculator::
     *  dailyCostRow()'s own 'total' key by mistake) rather than injecting
     *  a bogus new line into the P&L. */
    public static function withOverriddenOperatingCosts(array $derived, array $overrides): array
    {
        $operatingLines = collect($derived['operating_lines']);
        $overrides = array_intersect_key($overrides, $operatingLines->all());
        $operatingLines = $operatingLines->merge($overrides);
        $totalOperatingCosts = $operatingLines->sum();

        $grossSales = $derived['gross_sales'];
        $netIncome = $derived['income_before_opex'] - $totalOperatingCosts;

        return array_merge($derived, [
            'operating_lines' => $operatingLines,
            'total_operating_costs' => $totalOperatingCosts,
            'total_operating_costs_pct' => $grossSales > 0 ? $totalOperatingCosts / $grossSales : 0.0,
            'net_income' => $netIncome,
            'net_income_pct' => $grossSales > 0 ? $netIncome / $grossSales : 0.0,
        ]);
    }

    /** Overrides an already-derived row's own Tax Allocation with a figure
     *  computed elsewhere (Cost Breakdown's own per-TSA Daily Tax —
     *  explicit request, 2026-10-02: "the tax allocation in tsa cards is
     *  should be not editable" — same lock Salaries/the 20 shared pools
     *  already have on a TSA-scoped card), then recomputes every figure
     *  that cascades from it: Gross Profit, Income Before OPEX, Net Income,
     *  and each one's own _pct (unlike Operating Costs, which sits
     *  downstream of Income Before OPEX and never touches it, Tax
     *  Allocation sits UPSTREAM, inside Gross Profit itself, so overriding
     *  it has to walk the WHOLE rest of the chain, not just Net Income).
     *  Total Selling Costs/Operating Costs themselves are unaffected (both
     *  are independent of Tax Allocation). Same "controller overrides,
     *  calculator only recomputes the cascade" separation as
     *  withOverriddenOperatingCosts() above. */
    public static function withOverriddenTaxAllocation(array $derived, float $taxAllocation): array
    {
        $grossSales = $derived['gross_sales'];
        $grossProfit = $grossSales - $derived['cancelled'] - $derived['returns'] - $taxAllocation - $derived['product_cost'];
        $incomeBeforeOpex = $grossProfit - $derived['total_selling_costs'];
        $netIncome = $incomeBeforeOpex - $derived['total_operating_costs'];

        return array_merge($derived, [
            'tax_allocation' => $taxAllocation,
            'tax_allocation_pct' => $grossSales > 0 ? $taxAllocation / $grossSales : 0.0,
            'gross_profit' => $grossProfit,
            'gross_profit_pct' => $grossSales > 0 ? $grossProfit / $grossSales : 0.0,
            'income_before_opex' => $incomeBeforeOpex,
            'income_before_opex_pct' => $grossSales > 0 ? $incomeBeforeOpex / $grossSales : 0.0,
            'net_income' => $netIncome,
            'net_income_pct' => $grossSales > 0 ? $netIncome / $grossSales : 0.0,
        ]);
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
     *  meaningfully "summed", only averaged).
     *
     *  $sellingKeys/$operatingKeys: same optional pure-function override as
     *  derive()'s own — see that method's own doc comment for why this
     *  never queries ProjectionCustomRow itself. */
    public static function sum(array $rows, ?array $sellingKeys = null, ?array $operatingKeys = null): array
    {
        $sellingKeys ??= array_keys(self::SELLING_COST_ROWS);
        $operatingKeys ??= array_keys(self::OPERATING_COST_ROWS);

        $sumKeys = array_merge(
            ['number_of_leads', 'number_of_orders', 'gross_sales', 'cancelled', 'returns', 'delivered', 'tax_allocation', 'product_cost'],
            $sellingKeys,
            $operatingKeys
        );

        $totals = array_fill_keys($sumKeys, 0.0);
        foreach ($rows as $row) {
            foreach ($sumKeys as $key) {
                $totals[$key] += (float) ($row[$key] ?? 0);
            }
        }

        // Average Order Value is now a plain display-only stat (Gross Sales
        // is a manual field of its own as of 2026-09-28, no longer Orders ×
        // AOV) — a summed row averages it across its own member rows, same
        // "not meaningfully summed" reasoning as ROAS/Actual Cost Per Lead
        // below, rather than back-solving it from Gross Sales ÷ Orders.
        $summed = self::derive($totals, $sellingKeys, $operatingKeys);

        $rowCount = count($rows);
        if ($rowCount > 0) {
            $perRow = array_map(fn ($row) => self::derive($row, $sellingKeys, $operatingKeys), $rows);
            $summed['roas'] = array_sum(array_column($perRow, 'roas')) / $rowCount;
            $summed['standard_cost_per_message'] = array_sum(array_column($perRow, 'standard_cost_per_message')) / $rowCount;
            $summed['actual_cost_per_lead'] = array_sum(array_column($perRow, 'actual_cost_per_lead')) / $rowCount;
            $summed['average_order_value'] = array_sum(array_column($perRow, 'average_order_value')) / $rowCount;
        }

        return $summed;
    }
}
