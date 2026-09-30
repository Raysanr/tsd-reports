<?php

namespace App\Support;

/**
 * TSD Data Management — Cost Breakdown (explicit request, 2026-09-29: "add
 * new page in data management (COST BREAKDOWN) ... like this sheets", a
 * real "TSD Payroll" spreadsheet — see create_cost_breakdown_roles_table's
 * own doc comment for the page's full context).
 *
 * Formulas confirmed against the sheet's own raw CSV export, not a
 * screenshot read alone (a screenshot-only read of the per-TSA table
 * looked inconsistent at first — turned out the real sheet has a genuine
 * off-by-one formula bug of its own starting at "Consultants," where every
 * column from there on shows the PREVIOUS pool's own amount ÷ headcount
 * instead of its own. Confirmed explicitly with the user this app should
 * NOT replicate that bug — every column here always uses its own correct
 * pool):
 *
 * - Each TSA's Days is the only manually-typed number that feeds this
 *   file's own per-TSA COST-ALLOCATION table below (her own base_salary
 *   feeds a separate formula chain instead — her own salary-section TOTAL,
 *   see tsaTotal() below — and does not affect this cost-allocation
 *   table at all). Her % share = her own Days ÷ SUM of every TSA's Days —
 *   confirmed against the sheet's own uniform 30-days/8.33% starting state
 *   (30 ÷ (30×12) = 8.33% exactly). A TSA who worked fewer days this month
 *   gets a smaller % share of every shared cost pool below, and everyone
 *   else's % share rises to make up the difference automatically (they
 *   still sum to 100%), same "shares of a fixed pie" reasoning as
 *   DsPprCalculator's own Pick-up/Conversion/Upselling Rate handling.
 *
 * - Each of the 21 shared monthly cost pools (Communication Allowance
 *   through HMO Expense — CostBreakdownPool::SEED_POOLS, NOT Salaries,
 *   which is computed separately, see below) splits across every TSA by
 *   her own % share: her own dollar amount for that pool = pool's own
 *   total amount × her % share. Confirmed exact against the sheet's own
 *   real numbers for the FIRST 11 columns (Communication Allowance through
 *   Maintenance Expenses, before its own formula bug kicks in) — e.g.
 *   Communication Allowance 500.42 × 8.33% = 41.70, matching exactly.
 *
 * - A TSA's own row TOTAL = the sum of all 21 of her own per-pool amounts
 *   above (does NOT include her own salary — that's the top section's own
 *   separate concern, confirmed the sheet's per-TSA cost table and the
 *   top salary section are two independent totals, never added together
 *   anywhere on the page).
 */
class CostBreakdownCalculator
{
    /** One TSA's own % share of every shared cost pool, from her own Days
     *  and the group's total Days worked. Returns 0.0 for every TSA if
     *  nobody has any days logged at all (division-by-zero guard, same
     *  "no data yet" convention as every other %-of-total calc in this
     *  app). */
    public static function shareOfDays(int $days, int $totalDays): float
    {
        return $totalDays > 0 ? $days / $totalDays : 0.0;
    }

    /** One TSA's own dollar amount for every shared pool, keyed the same
     *  way $pools is (pool key => pool amount), plus her own row 'total'
     *  (the sum of every pool's own amount for her). $pools is the plain
     *  key => amount map from CostBreakdownPool::SEED_POOLS' own keys —
     *  passed in rather than queried here, same "controller fetches,
     *  calculator only computes" separation ExpectedIncomeCalculator's own
     *  doc comment already establishes for this app. */
    public static function rowForShare(float $share, array $pools): array
    {
        $row = [];
        $total = 0.0;

        foreach ($pools as $key => $amount) {
            $value = $amount * $share;
            $row[$key] = $value;
            $total += $value;
        }

        $row['total'] = $total;

        return $row;
    }

    /** The "Daily Cost per product" / "Daily Cost" mini-table sitting above
     *  the Cost Allocation Per TSA table's own per-TSA rows (explicit
     *  request, 2026-09-30, from a real sheet screenshot) — NOT scoped to
     *  any specific TSA, unlike rowForShare() above. Each pool's own amount
     *  split across the app's own REAL TSA count (explicit correction,
     *  2026-09-30: "the 12 is number of the tsa" — deliberately NOT the
     *  sheet's own fixed 12-TSA headcount the overhead-per-TSA figures
     *  elsewhere on this page use; this row scales automatically as real
     *  TSAs are added/removed instead), then ÷ 24 working days — confirmed
     *  exact against the real sheet's own xlsx formulas at its own
     *  then-current 12-TSA roster (row 43: amount÷12÷24, e.g.
     *  Communication Allowance 500.42 ÷ 12 ÷ 24 = 1.74, matching to the
     *  cent — the app's own real roster will produce different figures
     *  once it differs from 12). Returns the same
     *  ['pool_key' => value, ..., 'total' => sum] shape as rowForShare(),
     *  so the view renders it with identical markup.
     *
     *  $tsaCount: the app's own real TsaShift::count() — 0 renders every
     *  figure as 0 (division-by-zero guarded here, since unlike
     *  tsaDailyRatePerProduct()'s $productCount this divisor sits INSIDE
     *  the same expression as the ÷24). */
    public static function dailyCostRow(array $pools, int $tsaCount): array
    {
        $row = [];
        $total = 0.0;
        $divisor = $tsaCount * 24;

        foreach ($pools as $key => $amount) {
            $value = $divisor > 0 ? $amount / $divisor : 0.0;
            $row[$key] = $value;
            $total += $value;
        }

        $row['total'] = $total;

        return $row;
    }

    /** Same "Daily Cost" row from dailyCostRow() above, each figure split
     *  further across every FLAGGED product (same tsaDailyRatePerProduct()
     *  formula, applied per pool instead of to one TSA's own total) —
     *  confirmed exact against the real sheet's own xlsx (row 42: e.g.
     *  Communication Allowance 1.74 ÷ 7 = 0.25, matching to the cent). */
    public static function dailyCostPerProductRow(array $dailyCostRow, int $productCount): array
    {
        $row = [];

        foreach ($dailyCostRow as $key => $value) {
            $row[$key] = self::tsaDailyRatePerProduct($value, $productCount);
        }

        return $row;
    }

    /** A real TSA's own daily rate — her own TOTAL (see tsaTotal() below,
     *  NOT her own raw base_salary alone) ÷ 24 working days — confirmed
     *  exact against the sheet's own real numbers (e.g. Julie Francisco:
     *  40,388.75 ÷ 24 = 1,682.86 daily, matching to the cent). 24, not
     *  `days` from the cost-allocation table below — this divisor is a
     *  fixed working-days-per-month assumption for the DAILY RATE display
     *  only, completely independent of how many days she actually logged
     *  this month for the shared-pool split above. */
    public static function tsaDailyRate(float $total): float
    {
        return $total / 24;
    }

    /** Her own Daily Rate split evenly across every product CARD (explicit
     *  request, 2026-09-30: "add anothet column next to Daily Rate (÷24)
     *  is like divided be all product ... how many product in the
     *  cards") — $productCount is the same card count Expected Income's
     *  own product cards show (a grouped pair counts as ONE card there,
     *  via ProductGrouping::rows() — see CostBreakdownController::index()'s
     *  own doc comment on $productCount). 0 when there are no products yet
     *  (nothing to divide by) rather than a division-by-zero. */
    public static function tsaDailyRatePerProduct(float $dailyRate, int $productCount): float
    {
        return $productCount > 0 ? $dailyRate / $productCount : 0.0;
    }

    /** One real TSA's own full monthly TOTAL — confirmed a LIVE FORMULA in
     *  the real sheet from the user's own formula-bar screenshot,
     *  2026-09-30: "=D5+D9+D12+C13" for Julie Francisco — her own row's
     *  total is the SUM of every overhead group's own per-TSA reference
     *  figure (executive + support + her OWN team's Supervisor, all 3
     *  from overheadPerTsa() above) PLUS her own raw base_salary. This
     *  REVERSES the earlier 2026-09-29 conclusion that base_salary alone
     *  already was her real folded total with "no bonus" — that reading
     *  was wrong; base_salary is her raw base ONLY, confirmed exact:
     *  Julie 19,500.00 + (10,341.13 + 4,833.33 + 5,714.29) = 40,388.75.
     *  $overheadRefs is every applicable group figure for her (just the 3
     *  values, in any order — summed here). */
    public static function tsaTotal(float $baseSalary, array $overheadRefs): float
    {
        return $baseSalary + array_sum($overheadRefs);
    }

    /** One overhead group's own per-TSA figure — confirmed a LIVE FORMULA
     *  in the real sheet, not a manually-typed number as this app
     *  originally (wrongly) modeled it (explicit follow-up, 2026-09-29:
     *  "look at this formula ... it is all divided of all 12 tsa"):
     *  $groupBaseSalaries summed, divided by $tsaCount. Confirmed exact
     *  against the sheet's own 3 real cells:
     *    - CEO+Sales Director+Telesales Manager ÷ 12 (total TSAs) = 10,341.13
     *    - QA Specialist+Junior AI Engineer ÷ 12 (total TSAs) = 4,833.33
     *    - Each Supervisor alone ÷ 6 (her OWN team's TSAs) = 5,714.285
     *  $tsaCount is the sheet's OWN fixed divisor (12 total, 6 per team —
     *  see CostBreakdownRole::OVERHEAD_DIVISOR_COUNTS), not this app's own
     *  real live TsaShift headcount (explicit reversal, 2026-09-29: an
     *  earlier version divided by the real roster's own count instead so
     *  the figure would track a growing team automatically, but the user
     *  wants this figure to always equal the sheet's own numbers exactly,
     *  not drift from them while the real roster is still smaller than
     *  the sheet's own 12-person snapshot). Returns 0.0 if $tsaCount is 0
     *  (division-by-zero guard, same convention as shareOfDays() above). */
    public static function overheadPerTsa(array $groupBaseSalaries, int $tsaCount): float
    {
        return $tsaCount > 0 ? array_sum($groupBaseSalaries) / $tsaCount : 0.0;
    }
}
