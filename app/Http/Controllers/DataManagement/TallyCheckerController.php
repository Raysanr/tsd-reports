<?php

namespace App\Http\Controllers\DataManagement;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\Product;
use App\Models\TsaShift;
use App\Support\DateRangeFilter;
use App\Support\ProductPerformance;
use App\Support\Teams;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * Data Management — Tally Checker (explicit request, 2026-10-12: "add
 * TALLY CHECKER BUTTON ... when it click that it will show is all tally
 * in the expected income, summary sales report, leads report module and
 * all of the data is accurate like sum of overall"). Triggered from
 * Expected Income's own topbar, AJAX JSON endpoint, rendered into a
 * modal by the view's own JS (see expected-income.blade.php).
 *
 * Scope, settled explicitly during design (see this session's own
 * conversation): a naive "does the sum of every product/TSA card equal
 * the team total" check would FALSELY flag Net Income/Operating-Costs-
 * derived fields as broken, since those are INTENTIONALLY divided per
 * product/TSA on their own cards but applied once, undivided, on the
 * team rollup (see ExpectedIncomeController::addActiveTsasOverviewOperatingCosts()'s
 * own 2026-10-01 doc comment for the full reasoning — summing the cards
 * would double/N-tuple-count a TSA's shared Salaries/Operating
 * Costs/Tax Allocation). This checker therefore does NOT do that —
 * every check here compares the SAME real-world figure (a team's own
 * Gross Sales, Number of Orders, or Number of Leads for one date range)
 * computed two GENUINELY INDEPENDENT ways, so a real data-matching bug
 * (like the Oct-12 Gross Sales cross-hour-window exclusion bug this
 * session root-caused and fixed) shows up as a real mismatch, while the
 * intentional per-card-division design never does.
 *
 * Gross Sales/Number of Orders: compares teamGrossSalesAndOrders() (the
 * shared ProductPerformance::tally() basis every page's own team
 * rollup already reads) against a SEPARATE raw query here that never
 * calls tally() at all — genuinely independent code paths computing
 * the same real number.
 *
 * Number of Leads: compares leadCountsByProductAndDate()'s own summed
 * output (the shared basis Expected Income's own cards read) against a
 * fresh per-product ProductPerformance::matchingOrders() pass scoped by
 * Order.team directly, mirroring LeadsReportController::index()'s own
 * single-team candidate-pool query shape (Order.team IS the correct
 * scope here — Number of Leads deliberately mirrors Leads Report's own
 * hour-windowed convention, unlike Gross Sales — see
 * ExpectedIncomeController::ordersByTsaTeamAndDate()'s own doc comment
 * for why the two metrics use different team-scoping rules).
 *
 * Net Income: NOT independently re-derived — Summary Sales Report's own
 * team Net Income literally calls the exact same
 * ExpectedIncomeController::teamNetIncomeByDate() Expected Income reads,
 * summed per date (TsaSalesReportController::index(), 'net_income'
 * override). There is no second real code path to compare against; a
 * second "independent" implementation would just be ExpectedIncomeCalculator's
 * own ~50-line P&L formula copy-pasted, itself a future drift risk.
 * Shown as informational ("single shared source — both pages read this
 * directly") rather than a pass/fail, so the checker never claims to
 * have verified something it structurally can't.
 */
class TallyCheckerController extends Controller
{
    public function check(Request $request)
    {
        $range = DateRangeFilter::resolve($request, 'tally-checker');
        $dateFrom = $range['from'];
        $dateTo   = $range['to'];

        $results = [];
        foreach (Teams::config() as $teamConfig) {
            $orderTeam = $teamConfig['order_team'];

            $results[] = [
                'team' => $teamConfig['name'],
                'orderTeam' => $orderTeam,
                'grossSales' => $this->checkGrossSales($orderTeam, $dateFrom, $dateTo),
                'numberOfLeads' => $this->checkNumberOfLeads($orderTeam, $dateFrom, $dateTo),
                'netIncome' => $this->sharedNetIncome($orderTeam, $dateFrom, $dateTo),
            ];
        }

        return response()->json([
            'dateFrom' => $dateFrom,
            'dateTo' => $dateTo,
            'teams' => $results,
        ]);
    }

    /** Path 1: the shared ProductPerformance::tally() basis every page's
     *  own team rollup already reads (ExpectedIncomeController::
     *  teamGrossSalesAndOrders(), itself a thin public wrapper around
     *  teamUpsellTotals() — see that method's own doc comment).
     *  Path 2: a raw query built here from scratch, never calling
     *  tally()/matchingOrders() at all — sums Order::realUpsellAmount()
     *  directly over every order worked by this team's own TSA roster
     *  that Order::isBroadRealUpsell() recognizes, after the same
     *  Deleted/excluded-seller/duplicated-by-logistics exclusion tally()
     *  itself applies (kept in sync by hand, same convention
     *  countedOrdersFor()'s own doc comment already documents — these 3
     *  exclusions are a stable, rarely-changed rule, not the part of the
     *  codebase that's been drifting). A real mismatch here means the
     *  TWO INDEPENDENT ways of counting "this team's real upsell
     *  revenue" disagree — exactly the shape of bug this session's own
     *  Oct-12 fix (orders arriving during the other team's hour window
     *  silently excluded from product cards) would have been caught by,
     *  had this checker existed first. */
    private function checkGrossSales(string $orderTeam, string $dateFrom, string $dateTo): array
    {
        ['grossSales' => $sharedGrossSales, 'orders' => $sharedOrders] =
            \App\Http\Controllers\DataManagement\ExpectedIncomeController::teamGrossSalesAndOrders($orderTeam, $dateFrom, $dateTo);

        $tsaKeys = TsaShift::where('team', $orderTeam)->pluck('tsa_key');

        $orders = Order::whereRaw(
            'DATE(COALESCE(pancake_inserted_at, pancake_created_at)) BETWEEN ? AND ?',
            [$dateFrom, $dateTo]
        )->whereIn('tsa_name', $tsaKeys)->get()
            ->reject(fn (Order $o) => $o->status_code === 7 || $o->excluded_upsell_seller || $o->is_duplicated_by_logistics)
            ->filter(fn (Order $o) => Order::isBroadRealUpsell($o));

        $independentGrossSales = (float) $orders->sum(fn (Order $o) => $o->realUpsellAmount());
        $independentOrders = $orders->count();

        return [
            'shared' => round($sharedGrossSales, 2),
            'independent' => round($independentGrossSales, 2),
            'sharedOrders' => $sharedOrders,
            'independentOrders' => $independentOrders,
            'tallies' => abs($sharedGrossSales - $independentGrossSales) < 0.01 && $sharedOrders === $independentOrders,
        ];
    }

    /** Path 1: leadCountsByProductAndDate()'s own summed output — the
     *  shared basis Expected Income's own product/team cards read.
     *  Path 2: a fresh per-product ProductPerformance::matchingOrders()
     *  pass built here, candidates pre-filtered by Order.team directly
     *  (NOT the TSA roster — Number of Leads deliberately mirrors Leads
     *  Report's own hour-windowed per-team pooling, confirmed via
     *  LeadsReportController::index()'s own 2026-09-07 doc comment,
     *  unlike Gross Sales above). Mirrors indexAll()'s own
     *  $teamTables-style single-pool-per-team query shape, just scoped
     *  to the one team being checked here instead of looping every team. */
    private function checkNumberOfLeads(string $orderTeam, string $dateFrom, string $dateTo): array
    {
        $products = Product::orderBy('team')->orderBy('sort_order')->get();
        $dates = collect(iterator_to_array(Carbon::parse($dateFrom)->daysUntil(Carbon::parse($dateTo))));

        ['leadCounts' => $leadCounts] = \App\Http\Controllers\DataManagement\ExpectedIncomeController::leadCountsAndGrossSalesForRange($dateFrom, $dateTo);
        $sharedTotal = (int) $leadCounts->filter(function ($count, $key) use ($orderTeam, $dateFrom, $dateTo) {
            [$keyOrderTeam, , , $dateStr] = explode(':', $key, 4);
            return $keyOrderTeam === $orderTeam && $dateStr >= $dateFrom && $dateStr <= $dateTo;
        })->sum();

        $dayOrders = Order::whereRaw(
            'COALESCE(pancake_inserted_at, pancake_created_at) BETWEEN ? AND ?',
            [Carbon::parse($dateFrom)->startOfDay(), Carbon::parse($dateTo)->endOfDay()]
        )->where('team', $orderTeam)->get();

        $independentTotal = 0;
        foreach ($products as $product) {
            $matched = ProductPerformance::matchingOrders($product, $dayOrders, $products)
                ->reject(fn (Order $o) => $o->status_code === 7 || $o->excluded_upsell_seller || $o->is_duplicated_by_logistics);
            $independentTotal += $matched->count();
        }

        return [
            'shared' => $sharedTotal,
            'independent' => $independentTotal,
            'tallies' => $sharedTotal === $independentTotal,
        ];
    }

    /** Informational only — see this class's own doc comment for why
     *  Net Income has no genuine second code path to compare against. */
    private function sharedNetIncome(string $orderTeam, string $dateFrom, string $dateTo): array
    {
        $teamNetIncomeByDate = \App\Http\Controllers\DataManagement\ExpectedIncomeController::teamNetIncomeByDate($dateFrom, $dateTo);
        $dates = collect(iterator_to_array(Carbon::parse($dateFrom)->daysUntil(Carbon::parse($dateTo))));

        $netIncome = (float) $dates->sum(fn ($date) => $teamNetIncomeByDate->get("{$orderTeam}:{$date->toDateString()}", 0.0));

        return ['value' => round($netIncome, 2)];
    }
}
