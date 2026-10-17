<?php

namespace App\Http\Controllers\DataManagement;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\Product;
use App\Models\TsaShift;
use App\Support\DateRangeFilter;
use App\Support\ProductPerformance;
use App\Support\Teams;
use App\Support\TiktokUpsellTotals;
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
 *
 * Per-PRODUCT (DSPPR - TSM Report), added 2026-10-12 as an explicit
 * follow-up request ("include the dsppr to the tally checker like for
 * every products") — see checkProducts()'s own doc comment for the full
 * per-product reasoning (Number of Leads/Number of Orders checked the
 * same two-independent-paths way; Gross Sales/Net Income skipped
 * per-product for the same "already the same shared source, not a
 * second code path" reason Net Income is skipped at the team level).
 */
class TallyCheckerController extends Controller
{
    public function check(Request $request)
    {
        $range = DateRangeFilter::resolve($request, 'tally-checker');
        $dateFrom = $range['from'];
        \App\Models\TsaShift::showDeletedFrom($dateFrom); // removed TSAs still show for days up to their removal
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
            'products' => $this->checkProducts($dateFrom, $dateTo),
            'unmatchedOrders' => $this->checkUnmatchedOrders($dateFrom, $dateTo),
            'tsaProductColumns' => $this->checkTsaProductColumns($dateFrom, $dateTo),
            'tiktok' => $this->checkTiktok($dateFrom, $dateTo),
        ]);
    }

    /** Surfaces real upsell orders that matched ZERO configured products at
     *  all — explicit follow-up request, 2026-10-13, root-caused live via
     *  order #1374667 (Joana Caluag, Oct 1): a genuine Haplunas Healing Eye
     *  Cream SEPARATE PARCEL order never showed on her Expected Income or
     *  TSA Performance cards at all, because Clear Sight's own Product
     *  record only had ONE configured keyword ("Clear Sight 3.0"), and this
     *  order's real tag was the shorter "CLEARSIGHT" (no "3.0") — since
     *  matching checks whether the TAG contains the KEYWORD,
     *  "CLEARSIGHT" never contains "CLEARSIGHT30", so it silently matched
     *  nothing anywhere in the app, on every page. A missing/too-narrow
     *  Product keyword is the single most common cause of this class of
     *  silent data loss (see ProductPerformance::matchingOrders()'s own
     *  doc comment history — nearly every entry there is a different
     *  variant of "this text didn't match any configured product").
     *
     *  Unlike every other check in this controller, this one has no
     *  "shared vs independent" comparison — there's nothing to compare
     *  against; an order either matched something or it didn't. It's a
     *  pure visibility tool: lists exactly which real orders are
     *  currently invisible to every report page in the app for the
     *  selected range, with enough fields (product/base_product/
     *  bundle_description/tags) to see WHY at a glance and go fix the
     *  right product's own keyword list. Not scoped to a team, since an
     *  unmatched order is unmatched everywhere regardless of which team's
     *  TSA worked it. */
    /** TSA totals vs product columns (explicit request, 2026-10-14: "fix any
     *  product that like that ... and can you add that to the tally
     *  checker") — per TSA, every real upsell her own totals count (TSA
     *  Performance's Upsell w/ Confirmation, Expected Income's Number of
     *  Orders) vs. how many of those land in a VISIBLE product column (TSA
     *  Performance's Per-Product Hourly Breakdown / Expected Income's
     *  product cards). Any order counted in the total but in no visible
     *  column is listed with the reason, e.g. order #1376764 (Lika, Oct 5,
     *  SEPARATE PARCEL) before ProductPerformance's separate-parcel tag rule. */
    private function checkTsaProductColumns(string $dateFrom, string $dateTo): array
    {
        $visible = Product::where('is_hidden', false)->orderBy('sort_order')->get();
        $all = Product::orderBy('sort_order')->get();
        $tsas = TsaShift::orderBy('sort_order')->get();

        $orders = Order::whereRaw(
            'DATE(COALESCE(pancake_inserted_at, pancake_created_at)) BETWEEN ? AND ?',
            [$dateFrom, $dateTo]
        )->whereIn('tsa_name', $tsas->pluck('tsa_key'))->get()->pipe(fn ($orders) => Order::withoutTiktokUpsells($orders))
            ->reject(fn (Order $o) => $o->status_code === 7 || $o->excluded_upsell_seller)
            ->filter(fn (Order $o) => Order::isBroadRealUpsell($o));

        $inColumnIds = ProductPerformance::countedOrdersFor($visible, $orders)->pluck('id')->flip();

        return $tsas->map(function (TsaShift $tsa) use ($orders, $inColumnIds, $visible, $all) {
            $tsaOrders = $orders->where('tsa_name', $tsa->tsa_key);
            if ($tsaOrders->isEmpty()) return null;

            $missing = $tsaOrders->reject(fn (Order $o) => $inColumnIds->has($o->id));

            return [
                'tsa' => $tsa->display_name,
                'totalOrders' => $tsaOrders->count(),
                'inColumns' => $tsaOrders->count() - $missing->count(),
                'tallies' => $missing->isEmpty(),
                'missing' => $missing->map(fn (Order $o) => [
                    'orderId' => $o->pancake_order_id,
                    'amount' => round($o->realUpsellAmount(), 2),
                    'product' => $o->product,
                    'tags' => $o->raw_tags ?? [],
                    'createdAt' => optional($o->pancake_inserted_at ?? $o->pancake_created_at)->toDateTimeString(),
                    'reason' => $this->missingColumnReason($o, $visible, $all),
                ])->values()->all(),
            ];
        })->filter()->values()->all();
    }

    /** TikTok (explicit request, 2026-10-15: "so the tally check will now
     *  include tiktok too?" → "yes") — the "shared" figure every TikTok
     *  surface reads (Expected Income's TikTok cards = TSA Sales Report's
     *  TikTok Upsell rows = DSPPR's TIKTOK ORDERS row, all via
     *  ExpectedIncomeController::tiktokTotalsByTsaAndDate()) vs. an
     *  independent direct sum of every TikTok-tagged real upsell order.
     *  They differ exactly when a tagged order reaches no TikTok card —
     *  e.g. its TSA isn't flagged tiktok_upsell in TSA Management — and
     *  each such order is listed. Automated days only
     *  (TiktokUpsellTotals::AUTOMATED_FROM on); earlier days were typed. */
    private function checkTiktok(string $dateFrom, string $dateTo): array
    {
        $from = max($dateFrom, TiktokUpsellTotals::AUTOMATED_FROM);
        if ($from > $dateTo) {
            return ['checked' => false, 'from' => TiktokUpsellTotals::AUTOMATED_FROM];
        }

        $shared = ExpectedIncomeController::tiktokTotalsByTsaAndDate($from, $dateTo);
        $sharedGross = (float) $shared->sum('gross_sales');
        $sharedOrders = (int) $shared->sum('total_orders');

        $tagged = Order::whereRaw(
            'DATE(COALESCE(pancake_inserted_at, pancake_created_at)) BETWEEN ? AND ?',
            [$from, $dateTo]
        )->get()
            ->filter(fn (Order $o) => Order::isTiktokUpsell($o) && Order::isBroadRealUpsell($o));
        $independentGross = (float) $tagged->sum(fn (Order $o) => $o->realUpsellAmount());

        $flaggedKeys = TsaShift::where('tiktok_upsell', true)->pluck('tsa_key')->flip();
        $knownKeys = TsaShift::pluck('display_name', 'tsa_key');
        $missing = $tagged->reject(fn (Order $o) => $flaggedKeys->has($o->tsa_name))->map(fn (Order $o) => [
            'orderId' => $o->pancake_order_id,
            'tsaName' => $knownKeys->get($o->tsa_name) ?? ($o->tsa_name ?: 'no TSA'),
            'amount' => round($o->realUpsellAmount(), 2),
            'card' => \App\Models\ExpectedIncomeTiktokEntry::CARDS[Order::tiktokCardKey($o->raw_tags ?? [])] ?? '',
            'createdAt' => optional($o->pancake_inserted_at ?? $o->pancake_created_at)->toDateTimeString(),
            'reason' => $knownKeys->has($o->tsa_name)
                ? 'Its TSA isn\'t flagged as a TikTok TSA in TSA Management, so she has no TikTok card.'
                : 'Its TSA tag doesn\'t match any TSA.',
        ])->values()->all();

        return [
            'checked' => true,
            'from' => $from,
            'grossSales' => ['shared' => round($sharedGross, 2), 'independent' => round($independentGross, 2), 'tallies' => abs($sharedGross - $independentGross) < 0.01],
            'numberOfOrders' => ['shared' => $sharedOrders, 'independent' => $tagged->count(), 'tallies' => $sharedOrders === $tagged->count()],
            'missing' => $missing,
        ];
    }

    private function missingColumnReason(Order $o, $visible, $all): string
    {
        $hiddenHits = $all->where('is_hidden', true)
            ->filter(fn (Product $p) => ProductPerformance::matchingOrders($p, collect([$o]), $all)->isNotEmpty());
        if ($hiddenHits->isNotEmpty()) {
            return 'Matches only hidden product(s): ' . $hiddenHits->pluck('display_name')->implode(', ') . ' — unhide it or move its keyword to a visible product.';
        }

        // Name the exact rule blocking any product its tags DO name.
        $tagged = $visible->filter(fn (Product $p) => collect($o->raw_tags ?? [])->contains(fn ($t) => $p->matchesText((string) $t)));
        foreach ($tagged as $p) {
            if ($p->isExcludedByItemName($o->product) || $p->isExcludedByItemName($o->base_product) || $p->isExcludedByItemName($o->bundle_description)) {
                return "Its tags name {$p->display_name}, but {$p->display_name}'s exclude keywords (\"{$p->match_exclude_keyword}\") block its item name \"{$o->product}\"" . (Order::isSeparateParcel($o) ? '.' : ' — and it isn\'t a SEPARATE PARCEL, so no tag fallback applies.');
            }
            $conflict = ProductPerformance::conflictingProduct($p, $o, $visible);
            if ($conflict !== null) {
                return "Its tags name {$p->display_name}, but its item name \"{$o->product}\" matches {$conflict->display_name} instead.";
            }
        }
        if ($tagged->isNotEmpty() && !Order::isSeparateParcel($o)) {
            return 'Its tags name ' . $tagged->pluck('display_name')->implode(', ') . ", but its item name/catalog ID (\"{$o->product}\") points elsewhere and it isn't a SEPARATE PARCEL — add a keyword or catalog ID in Product Management.";
        }

        return 'No product keyword matches its item name or any of its tags — add a keyword in Product Management.';
    }

    private function checkUnmatchedOrders(string $dateFrom, string $dateTo): array
    {
        $products = Product::all();
        $rangeStart = Carbon::parse($dateFrom)->startOfDay();
        $rangeEnd = Carbon::parse($dateTo)->endOfDay();

        $orders = Order::whereRaw(
            'COALESCE(pancake_inserted_at, pancake_created_at) BETWEEN ? AND ?',
            [$rangeStart, $rangeEnd]
        )->get()->pipe(fn ($orders) => Order::withoutTiktokUpsells($orders))
            ->reject(fn (Order $o) => $o->status_code === 7 || $o->excluded_upsell_seller)
            ->filter(fn (Order $o) => Order::isBroadRealUpsell($o));

        $matchedOrderIds = ProductPerformance::countedOrdersFor($products, $orders)->pluck('id');
        $unmatched = $orders->whereNotIn('id', $matchedOrderIds);

        return $unmatched->map(fn (Order $o) => [
            'orderId' => $o->pancake_order_id,
            'tsaName' => $o->tsa_name,
            'team' => $o->team,
            'amount' => round($o->realUpsellAmount(), 2),
            'product' => $o->product,
            'baseProduct' => $o->base_product,
            'bundleDescription' => $o->bundle_description,
            'tags' => $o->raw_tags ?? [],
            'createdAt' => optional($o->pancake_inserted_at ?? $o->pancake_created_at)->toDateTimeString(),
        ])->values()->all();
    }

    /** Per-PRODUCT extension (explicit follow-up request, 2026-10-12:
     *  "include the dsppr to the tally checker like for every products")
     *  — compares Expected Income's own per-product Number of
     *  Leads/Number of Orders against DSPPR - TSM Report's own per-
     *  product Total Leads/Total Orders, for the SAME product across the
     *  WHOLE page (every real team pooled, matching how DSPPR itself
     *  renders one row per product with no team split — unlike the
     *  team-level checks above, which are deliberately per-team).
     *
     *  Gross Sales/Net Income are deliberately NOT compared per product
     *  — DsPprReportController::index() already reads BOTH of those
     *  directly from ExpectedIncomeController::
     *  grossSalesAndNetIncomeByProductAndDate() (confirmed via that
     *  controller's own $realByRowKeyAndDate merge), so DSPPR's own
     *  Gross Sales/Net Income for a product is not a second code path —
     *  it's the literal same number by construction, same reasoning
     *  Net Income already has at the team level above.
     *
     *  Number of Leads: shared = leadCountsByProductAndDate()'s own
     *  summed output for this product across every team (the exact
     *  basis Expected Income's own product cards read). Independent =
     *  a fresh per-product ProductPerformance::matchingOrders() pass
     *  built here from scratch, pooling every real team's own orders for
     *  the whole range — mirroring DsPprReportController::index()'s own
     *  $ordersByDate/ProductPerformance::dsPprRow() call shape (one
     *  tally() over every matched order for this product, this is what
     *  DSPPR's own "Total Leads" column literally counts — $tally['total'],
     *  not a disposition-filtered subset) without calling dsPprRow()
     *  itself, so the two sides never share code.
     *
     *  Number of Orders: same shared/independent split, reading
     *  'upsell_confirmation' instead of 'total' — the exact basis
     *  DSPPR's own "Total Orders" column uses (dsPprRow()'s own
     *  $tally['upsell_confirmation']) and the same field Expected
     *  Income's own grossSalesByProductAndDate() already names
     *  'numberOfOrders'.
     *
     *  Hidden products (Product.is_hidden) are skipped — DSPPR's own
     *  index() excludes a hidden product with zero activity in range
     *  (see that controller's own doc comment), so including one here
     *  with a trivial 0-vs-0 "pass" would just be noise. */
    private function checkProducts(string $dateFrom, string $dateTo): array
    {
        $products = Product::orderBy('team')->orderBy('sort_order')->get();
        $dates = collect(iterator_to_array(Carbon::parse($dateFrom)->daysUntil(Carbon::parse($dateTo))));

        ['leadCounts' => $leadCounts, 'grossSalesAndOrders' => $grossSalesAndOrders] =
            \App\Http\Controllers\DataManagement\ExpectedIncomeController::leadCountsAndGrossSalesForRange($dateFrom, $dateTo);
        $numberOfOrdersMap = $grossSalesAndOrders['numberOfOrders'];

        $orderTeams = collect(Teams::config())->pluck('order_team')->unique()->values();
        $rangeStart = Carbon::parse($dateFrom)->startOfDay();
        $rangeEnd = Carbon::parse($dateTo)->endOfDay();
        $allOrders = Order::whereRaw(
            'COALESCE(pancake_inserted_at, pancake_created_at) BETWEEN ? AND ?',
            [$rangeStart, $rangeEnd]
        )->whereIn('team', $orderTeams)->get()->pipe(fn ($orders) => Order::withoutTiktokUpsells($orders));

        $results = [];
        foreach ($products as $product) {
            if ($product->is_hidden) {
                continue;
            }

            $sharedLeads = (int) $leadCounts->filter(function ($count, $key) use ($product, $dateFrom, $dateTo) {
                [, , $productId, $dateStr] = explode(':', $key, 4);
                return $productId === (string) $product->id && $dateStr >= $dateFrom && $dateStr <= $dateTo;
            })->sum();

            $sharedOrders = (int) $numberOfOrdersMap->filter(function ($count, $key) use ($product, $dateFrom, $dateTo) {
                [, , $productId, $dateStr] = explode(':', $key, 4);
                return $productId === (string) $product->id && $dateStr >= $dateFrom && $dateStr <= $dateTo;
            })->sum();

            $matched = ProductPerformance::matchingOrders($product, $allOrders, $products)
                ->reject(fn (Order $o) => $o->status_code === 7 || $o->excluded_upsell_seller);
            $independentLeads = $matched->count();
            $independentOrders = $matched->filter(fn (Order $o) => Order::isBroadRealUpsell($o))->count();

            if ($sharedLeads === 0 && $independentLeads === 0 && $sharedOrders === 0 && $independentOrders === 0) {
                continue;
            }

            $results[] = [
                'product' => $product->display_name,
                'numberOfLeads' => [
                    'shared' => $sharedLeads,
                    'independent' => $independentLeads,
                    'tallies' => $sharedLeads === $independentLeads,
                ],
                'numberOfOrders' => [
                    'shared' => $sharedOrders,
                    'independent' => $independentOrders,
                    'tallies' => $sharedOrders === $independentOrders,
                ],
            ];
        }

        return $results;
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
        // Hidden products' orders dropped on this side too (2026-10-17) —
        // the shared path above already excludes them, so this checks the
        // same scope instead of flagging every hidden product as a gap.
        )->whereIn('tsa_name', $tsaKeys)->get()->pipe(fn ($orders) => ProductPerformance::withoutHiddenProductOrders(Order::withoutTiktokUpsells($orders)))
            ->reject(fn (Order $o) => $o->status_code === 7 || $o->excluded_upsell_seller)
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
        )->where('team', $orderTeam)->get()->pipe(fn ($orders) => ProductPerformance::withoutHiddenProductOrders(Order::withoutTiktokUpsells($orders), $products));

        // Visible products only, same scope as the shared path (2026-10-17).
        $independentTotal = 0;
        foreach ($products->where('is_hidden', false) as $product) {
            $matched = ProductPerformance::matchingOrders($product, $dayOrders, $products)
                ->reject(fn (Order $o) => $o->status_code === 7 || $o->excluded_upsell_seller);
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
