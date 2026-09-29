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
 * - Each TSA's Days is the only manually-typed number that feeds a real
 *   formula chain here (base_salary/tsa_bonus are manual too, but don't
 *   drive anything past their own row — see CostBreakdownTsaEntry's own
 *   doc comment). Her % share = her own Days ÷ SUM of every TSA's Days —
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

    /** A real TSA's own daily rate — her own base_salary ÷ 24 working days
     *  — confirmed exact against the sheet's own real numbers (e.g. Julie
     *  Francisco: 40,388.75 ÷ 24 = 1,682.86 daily, matching to the cent).
     *  base_salary here already IS her real full monthly total (explicit
     *  follow-up, 2026-09-29: "there's no bonus on the sheets so it should
     *  no bonus in that" — Julie's own base_salary is typed as 40,388.75
     *  directly, not built from a separate 19,500 + 20,888.75 bonus split
     *  anywhere in this app). 24, not `days` from the cost-allocation
     *  table below — this divisor is a fixed working-days-per-month
     *  assumption for the DAILY RATE display only, completely independent
     *  of how many days she actually logged this month for the
     *  shared-pool split above. */
    public static function tsaDailyRate(float $baseSalary): float
    {
        return $baseSalary / 24;
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
     *  $tsaCount is passed in rather than hardcoded 12/6 (explicit
     *  decision, 2026-09-29) — this app's own real TsaShift roster is
     *  smaller than the sheet's own 12-person snapshot, and the figure
     *  should track whatever headcount is genuinely live right now rather
     *  than a frozen sheet-era number; returns 0.0 if $tsaCount is 0
     *  (division-by-zero guard, same convention as shareOfDays() above). */
    public static function overheadPerTsa(array $groupBaseSalaries, int $tsaCount): float
    {
        return $tsaCount > 0 ? array_sum($groupBaseSalaries) / $tsaCount : 0.0;
    }
}
