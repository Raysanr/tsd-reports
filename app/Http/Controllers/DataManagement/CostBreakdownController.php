<?php

namespace App\Http\Controllers\DataManagement;

use App\Http\Controllers\Controller;
use App\Models\CostBreakdownPool;
use App\Support\CostBreakdownMonths;
use App\Models\CostBreakdownRole;
use App\Models\CostBreakdownTsaEntry;
use App\Models\Product;
use App\Models\Setting;
use App\Models\TsaShift;
use App\Support\ActivityLogger;
use App\Support\CostBreakdownCalculator;
use App\Support\ProductGrouping;
use App\Support\TsaDailyRateService;
use Illuminate\Http\Request;

/**
 * TSD Data Management — Cost Breakdown (explicit request, 2026-09-29: "add
 * new page in data management (COST BREAKDOWN) ... like this sheets", a
 * real "TSD Payroll" spreadsheet). Two independent sections on one page:
 *
 *   1. A top salary/org section (CostBreakdownRole — CEO, Sales Director,
 *      Telesales Manager, QA Specialist, Junior AI Engineer, each shift's
 *      own Supervisor) — every field manual, "TOTAL SALARY OF TSD" sums
 *      only the real TSAs' own totals (confirmed against the sheet's own
 *      raw CSV: that figure excludes the CEO/Sales Director/Manager/QA/
 *      Junior AI/Supervisor rows entirely, matching only the 12 real
 *      Telesales Associate rows' own sum).
 *
 *   2. A bottom per-TSA cost-allocation table (CostBreakdownTsaEntry, keyed
 *      to the app's REAL TsaShift roster) — Days is the only field that
 *      drives a real formula here (her own % share of every shared cost
 *      pool); base_salary is manual (her RAW base salary only — her own
 *      displayed TOTAL in the top salary section is a separate LIVE
 *      FORMULA, base_salary + her applicable overhead refs, confirmed from
 *      the user's own formula-bar screenshot 2026-09-30 — see
 *      CostBreakdownCalculator::tsaTotal()'s own doc comment) but
 *      base_salary doesn't feed this bottom table's own formulas at all.
 *      The 21 shared monthly pools (CostBreakdownPool) split
 *      across every TSA by her own % share — see CostBreakdownCalculator's
 *      own doc comment for the full formula chain and the real sheet's own
 *      genuine off-by-one formula bug this app deliberately does NOT
 *      replicate.
 *
 * Uses the app's real TsaShift roster, not the sheet's own static 12-name
 * snapshot (explicit decision, 2026-09-29, same reasoning as Projections/
 * Expected Income/DSPPR's own real-roster/real-product convention) — a TSA
 * added later automatically gets her own row here with zero extra step.
 */
class CostBreakdownController extends Controller
{
    /** The 3 lockable tables on this page (explicit request, 2026-10-07:
     *  "add lock icon in every table ... like in the projections page") —
     *  one whole-table lock each, NOT per-row/per-card like Projections'
     *  own lock (this page is 3 dense editable grids, not a stack of
     *  cards, so a per-row lock would be a much bigger build for no
     *  requested benefit — confirmed scope, same conversation). Persisted
     *  via the shared Setting key-value store (same pattern as
     *  CallTracker\TsaManagementController's own autoTaggingEnabled
     *  toggle) under 'cost_breakdown_lock_{table}', so a locked table
     *  stays locked for everyone, across reloads, same as Projections'
     *  own is_locked column. */
    public const LOCK_TABLES = ['salary', 'pools', 'tsa'];

    public function index(Request $request)
    {
        // Month picker (explicit request, 2026-10-15: "like in the
        // projections page like monthly only and can add cost breakdown
        // too for this month"). A month with no Cost Breakdown of its own
        // shows the month it falls back to, read-only, with an "Add Cost
        // Breakdown" button — see CostBreakdownMonths.
        $month = $this->resolveSelectedMonth($request);
        $dataMonth = $this->enterMonth($month);
        $hasOwnMonth = $dataMonth === $month;

        // Self-heals an empty table the same way ProjectionColumn::
        // ensureSeeded() does — a migrated-but-empty table is a real
        // failure mode already seen in this app (a dev DB reset after the
        // migration already ran).
        CostBreakdownRole::ensureSeeded($dataMonth);
        CostBreakdownPool::ensureSeeded($dataMonth);
        CostBreakdownTsaEntry::ensureSeeded($dataMonth);

        $roles = CostBreakdownRole::where('month', $dataMonth)->orderBy('sort_order')->get();
        $pools = CostBreakdownPool::where('month', $dataMonth)->orderBy('sort_order')->get();

        $tsas = TsaShift::orderBy('team')->orderBy('sort_order')->get();
        $entriesByTsaId = CostBreakdownTsaEntry::where('month', $dataMonth)->whereIn('tsa_id', $tsas->pluck('id'))->get()->keyBy('tsa_id');
        // TSAs added after this month aren't part of it (2026-10-24).
        $tsas = $tsas->filter(fn (TsaShift $t) => TsaDailyRateService::belongsToMonth($t, $month, $entriesByTsaId))->values();

        // One row per real TSA, seeded from her own saved entry (or a
        // fresh all-zero one with the default 30 days — same "a cell with
        // nothing typed yet still renders, just at 0" convention as every
        // other page in this module).
        $tsaRows = $tsas->map(function (TsaShift $tsa) use ($entriesByTsaId) {
            // base_salary 0 (bug fix, 2026-10-23): without it the fallback's
            // base_salary was null and tsaTotal(float) 500'd the whole page
            // for any TSA with no entry yet this month (e.g. newly added).
            $entry = $entriesByTsaId->get($tsa->id) ?? new CostBreakdownTsaEntry(['tsa_id' => $tsa->id, 'base_salary' => 0, 'days' => 30]);
            return ['tsa' => $tsa, 'entry' => $entry];
        });

        // Overhead-per-TSA — confirmed a LIVE FORMULA in the real sheet
        // (explicit follow-up, 2026-09-29: "look at this formula ... it is
        // all divided of all 12 tsa"), not a manually-typed number as this
        // app originally modeled it. Grouped by overhead_group (roles
        // sharing the same group sum their own base_salary together before
        // dividing) — each Supervisor has her OWN overhead_group (not a
        // shared "supervisor" one), so her own merged cell never spans
        // into the other shift's own rows. Divisor is the sheet's OWN
        // fixed headcount (explicit decision, 2026-09-29: match the sheet
        // exactly, not this app's own smaller real roster) — 12 total for
        // 'total', 6 for 'team' — see
        // CostBreakdownRole::OVERHEAD_DIVISOR_COUNTS and
        // CostBreakdownCalculator::overheadPerTsa()'s own doc comment.
        $overheadByRoleId = $this->overheadByRoleId($roles);
        $overheadRefsByTeam = $this->overheadRefsByTeam($roles, $overheadByRoleId);

        // Salary section render list (explicit follow-up, 2026-09-29:
        // "the supervisor of opening and closing is in the rows of their
        // TSA's") — a flat sequence of ['type' => 'role'|'tsa', ...]
        // entries, built ONCE here so the view never has to know which
        // role owns which team's TSAs itself. A Supervisor role
        // (role->team set) is immediately followed by every real TsaShift
        // whose own `team` column matches hers, in that team's existing
        // roster order — every other role has no TSAs nested under it at
        // all, same as the real sheet.
        //
        // rowspan (role rows only): how many rows this role's own overhead
        // figure visually covers — every OTHER role sharing the same
        // overhead_group AFTER this one in sort order, +1 for itself (the
        // FIRST role in a group anchors the merged cell, same convention
        // as a real spreadsheet's own merged region). A role covered by an
        // EARLIER role's own rowspan renders no "Overhead / TSA" cell of
        // its own at all — tracked with a running counter as roles are
        // walked in order.
        $rolesByGroup = $roles->whereNotNull('overhead_group')->groupBy('overhead_group');
        $rowspanRemaining = 0;
        $seenGroups = [];

        // One supervisor left this month (2026-10-30: the other was
        // removed) — she covers EVERY TSA, but the teams themselves stay:
        // Opening and Closing each still get their own labelled block under
        // her. With none left, the TSAs still show, at the end.
        $soleSupervisor = CostBreakdownRole::soleSupervisor($roles);
        $teamLabelDate = \Illuminate\Support\Carbon::createFromFormat('Y-m-d', $month . '-01');
        $tsaRowsFor = function ($rows) use ($overheadRefsByTeam, $soleSupervisor, $teamLabelDate) {
            $makeRow = fn ($row) => [
                'type' => 'tsa',
                'tsa' => $row['tsa'],
                'entry' => $row['entry'],
                'total' => CostBreakdownCalculator::tsaTotal($row['entry']->base_salary, $overheadRefsByTeam->get($row['tsa']->team) ?? []),
            ];
            if (!$soleSupervisor && $rows->pluck('tsa.team')->unique()->count() <= 1) {
                return $rows->map($makeRow);
            }

            return $rows->groupBy(fn ($row) => $row['tsa']->team)->flatMap(fn ($teamRows, $team) => collect([[
                'type' => 'team',
                'label' => \App\Support\Teams::nameForOrderTeam((string) $team, $teamLabelDate),
            ]])->concat($teamRows->map($makeRow)));
        };

        $salaryRows = $roles->flatMap(function (CostBreakdownRole $role) use ($tsaRows, $overheadByRoleId, $rolesByGroup, $soleSupervisor, $tsaRowsFor, &$rowspanRemaining, &$seenGroups) {
            $coveredByRowspan = $rowspanRemaining > 0;
            $rowspan = 0;

            if (!$coveredByRowspan && $role->overhead_group && !in_array($role->overhead_group, $seenGroups, true)) {
                $seenGroups[] = $role->overhead_group;
                $rowspan = $rolesByGroup->get($role->overhead_group)->count();
                $rowspanRemaining = $rowspan - 1;
            } elseif ($rowspanRemaining > 0) {
                $rowspanRemaining--;
            }

            $rows = collect([[
                'type' => 'role',
                'role' => $role,
                'overhead' => $overheadByRoleId->get($role->id),
                'rowspan' => $rowspan,
                'covered_by_rowspan' => $coveredByRowspan,
                // Monthly/Daily Tax on a supervisor row: her shift's — or both
                // shifts' when she's the only supervisor left.
                'tax_shifts' => $soleSupervisor && $soleSupervisor->is($role) ? max(1, $tsaRows->pluck('tsa.team')->unique()->count()) : 1,
            ]]);

            if ($role->team) {
                $rows = $rows->concat($tsaRowsFor(
                    $soleSupervisor ? $tsaRows : $tsaRows->filter(fn ($row) => $row['tsa']->team === $role->team)
                ));
            }

            return $rows;
        })->values();

        // Both supervisors removed: no role row owns the TSAs any more.
        if ($roles->whereNotNull('team')->isEmpty() && !\App\Support\DataSet::isPartners()) {
            $salaryRows = $salaryRows->concat($tsaRowsFor($tsaRows))->values();
        }

        // group_end (view-only, purely visual): true on the LAST row of
        // each cluster — {CEO, Sales Director, Telesales Manager}, {QA
        // Specialist, Junior AI Engineer}, {each Supervisor + her own
        // team's real TSAs} — so the view can draw a gap AFTER it instead
        // of a divider line under every single row (explicit follow-up,
        // 2026-09-29: "no row line ... make it look clean like there's a
        // group"). A new cluster starts at the next 'role' row that isn't
        // itself covered by an earlier row's rowspan, so the row right
        // before it is that cluster's own last row.
        $salaryRows = $salaryRows->map(function ($row, $index) use ($salaryRows) {
            $next = $salaryRows->get($index + 1);
            $row['group_end'] = !$next || ($next['type'] === 'role' && !$next['covered_by_rowspan']);
            return $row;
        });

        // Product columns on the bottom cost-allocation table (explicit
        // request, 2026-09-30: "add the products ... analyze the formula
        // in the sheets") — every real product's own COLUMN always shows
        // (with its own checkbox), same roster Expected Income shows
        // (ProductGrouping-merged card count, not a bare Product::count()),
        // but only a FLAGGED product's own column gets a real dollar value
        // and counts toward the divisor (explicit follow-up, 2026-09-30:
        // "user only can identify what product that has cost" — matches
        // the real sheet's own template, which only ever filled in 7 of
        // its product columns, leaving the rest blank). Confirmed against
        // the real sheet's own xlsx formulas (AA45=Z45/7, AB45=Z45/7 for
        // Julie Francisco — see TsaDailyRateService's own doc comment):
        // every FLAGGED product's column shows the SAME figure, her own
        // Daily Rate ÷ flagged-product count — the sheet's own formula
        // never varies per product.
        // Hidden products get no column (2026-10-17, same roster as
        // TsaDailyRateService::flaggedProductRows()).
        $products = Product::where('is_hidden', false)->orderBy('team')->orderBy('sort_order')->get();
        $productRows = ProductGrouping::rows($products, fn () => null);
        $flaggedProductCount = TsaDailyRateService::productCount();

        $totalDays = $tsaRows->sum(fn ($row) => $row['entry']->days);
        $poolAmounts = $pools->pluck('amount', 'key')->all();

        // "Daily Cost per product" / "Daily Cost" mini-table sitting above
        // the per-TSA rows (explicit request, 2026-09-30, real sheet
        // screenshot) — NOT scoped to any specific TSA, each pool's own
        // amount ÷ the app's own REAL, ACTIVE TSA count (explicit
        // correction, 2026-09-30: "the 12 is number of the tsa" — not the
        // sheet's own fixed 12-TSA headcount; narrowed further 2026-10-06:
        // "if i make anne 0 days why is it the Daily Cost per product,
        // and Daily Cost is not changing" — a TSA with Days=0 no longer
        // counts toward this divisor either, same redistribution the
        // Days-based % column below already gives every OTHER TSA — see
        // TsaDailyRateService::activeTsaCount()'s own doc comment) ÷ 24
        // working days, then that same figure split across every FLAGGED
        // product. See CostBreakdownCalculator::dailyCostRow()'s own doc
        // comment for the confirmed-exact formula.
        $dailyCostRow = CostBreakdownCalculator::dailyCostRow($poolAmounts, TsaDailyRateService::activeTsaCount());
        $dailyCostPerProductRow = CostBreakdownCalculator::dailyCostPerProductRow($dailyCostRow, $flaggedProductCount);

        // Each TSA's own % share + her own dollar amount per pool + her
        // own row TOTAL — computed once here so the view never has to call
        // into the calculator itself (same "controller computes, view only
        // renders" separation as every other report page in this module).
        // Her own Daily Rate / Product (same figure repeated into every
        // FLAGGED product column only — see doc comment above) is appended
        // onto 'derived' under a 'products' key, keyed by that row's own
        // label; an unflagged product's own key is simply absent, and the
        // view shows a blank cell for it.
        $tsaRows = $tsaRows->map(function ($row) use ($totalDays, $poolAmounts, $productRows, $flaggedProductCount) {
            $share = CostBreakdownCalculator::shareOfDays($row['entry']->days, $totalDays);
            $row['share'] = $share;
            $derived = CostBreakdownCalculator::rowForShare($share, $poolAmounts);
            $dailyRate = CostBreakdownCalculator::tsaDailyRate($derived['total']);
            $dailyRatePerProduct = CostBreakdownCalculator::tsaDailyRatePerProduct($dailyRate, $flaggedProductCount);
            // A grouped product row's own checkbox (the view's own) only
            // ever toggles the group's FIRST member — same "the group's
            // first member owns the edit" convention Expected Income's own
            // grouped cards already use — so gate on that same member here
            // too, rather than "any member flagged," to keep the checkbox
            // and the column's own value from ever disagreeing.
            $derived['products'] = $productRows
                ->filter(fn ($row) => $row['products']->first()->has_cost_allocation)
                ->pluck('label')
                ->mapWithKeys(fn ($label) => [$label => $dailyRatePerProduct])
                ->all();
            $row['derived'] = $derived;
            return $row;
        });

        // The bottom plain totals list — Salaries here is NOT a manual
        // CostBreakdownPool row; it's TOTAL SALARY OF TSD's own real number
        // (see totalSalaryOfTsd() below), always in sync with the top
        // section rather than a second, independently-typed figure that
        // could drift from it.
        $totalSalaryOfTsd = $this->totalSalaryOfTsd($salaryRows);
        $poolTotalsRow = $pools->reduce(function ($carry, CostBreakdownPool $pool) {
            $carry[$pool->key] = $pool->amount;
            return $carry;
        }, []);
        $grandTotal = array_sum($poolTotalsRow);

        ['perShift' => $monthlyTaxPerShift, 'perTsaByTeam' => $monthlyTaxPerTsaByTeam] = $this->taxFigures($tsas, $entriesByTsaId);

        return view('data.cost-breakdown', [
            'roles' => $roles,
            'salaryRows' => $salaryRows,
            'pools' => $pools,
            'productRows' => $productRows,
            'tsaRows' => $tsaRows,
            'totalDays' => $totalDays,
            'totalSalaryOfTsd' => $totalSalaryOfTsd,
            'poolTotalsRow' => $poolTotalsRow,
            'grandTotal' => $grandTotal,
            'rowGrandTotal' => $tsaRows->sum(fn ($row) => $row['derived']['total']),
            'flaggedProductCount' => $flaggedProductCount,
            'dailyRatePerProductByTsaId' => TsaDailyRateService::perProductByTsaId(),
            'dailyCostRow' => $dailyCostRow,
            'dailyCostPerProductRow' => $dailyCostPerProductRow,
            'monthlyTaxPerShift' => $monthlyTaxPerShift,
            'monthlyTaxPerTsaByTeam' => $monthlyTaxPerTsaByTeam,
            'tableLocks' => $this->tableLocks($month),
            'month' => $month,
            'dataMonth' => $dataMonth,
            'hasOwnMonth' => $hasOwnMonth,
        ]);
    }

    /** ?month= (picked in the picker) wins and is remembered; else the last
     *  picked month; else the current month — same as Projections. */
    private function resolveSelectedMonth(Request $request): string
    {
        $month = $request->input('month');
        if ($month !== null && CostBreakdownMonths::isValid($month)) {
            session(['cost_breakdown.month' => $month]);
        } else {
            $month = session('cost_breakdown.month', CostBreakdownMonths::current());
        }

        return CostBreakdownMonths::isValid($month) ? $month : CostBreakdownMonths::current();
    }

    /** The month the page's AJAX saves belong to (they carry no ?month=). */
    private function sessionMonth(): string
    {
        $month = session('cost_breakdown.month', CostBreakdownMonths::current());

        return CostBreakdownMonths::isValid($month) ? $month : CostBreakdownMonths::current();
    }

    /** Points TsaDailyRateService (and the TSA roster) at $month — today for
     *  the current month, else the 1st — and returns the month whose saved
     *  figures apply (CostBreakdownMonths::effective()). */
    private function enterMonth(string $month): string
    {
        $date = $month === CostBreakdownMonths::current() ? now('Asia/Manila')->toDateString() : $month . '-01';
        TsaDailyRateService::setContextDate($date);
        TsaShift::showDeletedFrom($date);

        return CostBreakdownMonths::effective($month);
    }

    /** A save into a month with no Cost Breakdown of its own is refused —
     *  that month is shown read-only until someone adds it. */
    private function ensureEditableMonth(string $month): void
    {
        CostBreakdownMonths::effective($month); // seeds the current month on an empty install
        abort_unless(CostBreakdownMonths::exists($month), 422, 'Add a Cost Breakdown for this month first.');
    }

    /** "Add Cost Breakdown" — copies the month it currently falls back to
     *  (explicit decision, 2026-10-15: "Copy last month"), then opens it. */
    public function storeMonth(Request $request)
    {
        $data = $request->validate(['month' => ['required', 'string', 'regex:/^\d{4}-(0[1-9]|1[0-2])$/']]);
        $existed = CostBreakdownMonths::exists($data['month']);
        CostBreakdownMonths::create($data['month']);
        if (!$existed) {
            $label = \Illuminate\Support\Carbon::createFromFormat('Y-m', $data['month'])->format('F Y');
            ActivityLogger::log('cost_breakdown.month_added', null, "Added the Cost Breakdown for {$label}.");
        }

        return redirect()->route('data.cost-breakdown', ['month' => $data['month']]);
    }

    /** Current locked/unlocked state of all 3 tables, keyed by the same
     *  short table key the lock button/toggleLock() route both use (see
     *  LOCK_TABLES's own doc comment). */
    private function tableLocks(string $month): array
    {
        return collect(self::LOCK_TABLES)
            ->mapWithKeys(fn (string $table) => [$table => CostBreakdownMonths::isLocked($table, $month)])
            ->all();
    }

    /** Flips one table's own lock on/off (explicit request, 2026-10-07) —
     *  {table} is the short key (salary/pools/tsa), not a model, since
     *  this isn't scoped to any one row. Validated against LOCK_TABLES so
     *  an unknown key 404s instead of silently creating a stray Setting
     *  row. The view disables every .cb-field input inside the matching
     *  table client-side the instant this returns — no table markup needs
     *  re-rendering (unlike Projections/Expected Income's own per-card
     *  lock, which swaps the card's own HTML because disabling an input
     *  there also means re-deriving its displayed value; every Cost
     *  Breakdown cell already shows its live server value regardless of
     *  lock state, so only the `disabled` attribute itself needs to
     *  change). */
    public function toggleLock(Request $request, string $table)
    {
        abort_unless(in_array($table, self::LOCK_TABLES, true), 404);

        $month = $this->sessionMonth();
        $this->ensureEditableMonth($month);
        $locked = $request->boolean('locked');
        Setting::set(CostBreakdownMonths::lockKey($table, $month), $locked ? '1' : '');
        ActivityLogger::log($locked ? 'cost_breakdown.table_locked' : 'cost_breakdown.table_unlocked', null, ($locked ? 'Locked' : 'Unlocked') . " the \"{$table}\" table.");

        return response()->json([
            'success' => true,
            'table' => $table,
            'locked' => $locked,
        ]);
    }

    /** "Monthly Tax" / "Daily Tax" columns on the Salary Breakdown table
     *  (explicit request, 2026-10-02, confirmed DIRECTLY against the real
     *  sheet's own formula bar — "50,000 is divided by 6 (6 tsa per team)
     *  so when they add new tsa it will be 7" / "the DAILY TAX 50,000 is
     *  divided by 24") — sourced from Projections' own "Telesales
     *  Department" card's Tax Allocation line (Gross Sales × the shared
     *  tax_allocation rate, same figure the Expected Income P&L already
     *  calls "Tax Allocation"), NOT a fresh manually-typed amount here.
     *  Cost Breakdown ONLY (explicit scope confirmation, same as the Tax
     *  pool this replaces) — reads Projections' own already-computed
     *  figure but writes nothing back to it or to Expected Income.
     *
     *  Confirmed formula: the department-wide Tax Allocation (e.g.
     *  100,000) splits evenly ACROSS THE 2 SHIFTS first (50,000 each —
     *  Opening Shift's own tab and Closing Shift's own tab both show the
     *  SAME 50,000, not two different figures), then each shift's own
     *  50,000 splits across THAT TEAM'S OWN real TSA count (confirmed
     *  live, 2026-10-02 — production showed 4,166.67 = 50,000 ÷ 12
     *  company-wide instead of the sheet's own 8,333.33 = 50,000 ÷ 6,
     *  since production has 6 real TSAs PER TEAM, 12 total — the earlier
     *  "company-wide" reading was wrong, root-caused by this app's dev DB
     *  coincidentally having only 6 TSAs TOTAL at the time). Dynamic, not
     *  a fixed sheet headcount — "when they add new tsa it will be 7"
     *  means a team's own real TsaShift count, recounted fresh, same
     *  "real roster, not the sheet's static snapshot" convention every
     *  other Cost Breakdown figure already follows (explicit exception:
     *  this is NOT the same as CostBreakdownRole::OVERHEAD_DIVISOR_COUNTS,
     *  which deliberately stays fixed at the sheet's own 12/6 to match ITS
     *  own numbers exactly — that one was never meant to track the real
     *  roster). Returns ['perShift' => 50,000-style figure,
     *  'perTsaByTeam' => [order_team => that team's own per-TSA figure]].
     *  Daily Tax (either figure ÷ 24) is derived in the view itself, same
     *  "controller returns the monthly figure, view divides by 24 inline"
     *  split the existing Daily Rate (÷24) column already uses.
     *
     *  $perShift sources TsaDailyRateService::departmentTaxAllocationPerShift()
     *  — shared with Expected Income's own locked Tax Allocation line
     *  (explicit request, 2026-10-02), so both pages can never drift apart
     *  on this figure. $perTsaByTeam is computed locally here (not also
     *  extracted) since only this page needs a BY-TEAM breakdown for the
     *  Salary Breakdown table's own interleaved rows — Expected Income's
     *  own TsaDailyRateService::taxAllocationByTsaId() already resolves
     *  straight to one tsa_id, no by-team grouping needed there. */
    private function taxFigures($tsas, $entriesByTsaId): array
    {
        $perShift = TsaDailyRateService::departmentTaxAllocationPerShift();

        // A TikTok-flagged TSA (TsaShift.tiktok_upsell) doesn't count
        // toward her team's own tax divisor, and gets no tax figure of
        // her own at all (explicit request, 2026-10-07: "when tsa is on
        // the tiktok she is not included to the divided tax so the tax
        // will be only to 5 tsa only ... so anne will be no tax") — same
        // "excluded from the real-team view entirely" treatment Expected
        // Income's own buildTeamDailyRows()/buildSummary() already give a
        // TikTok TSA (see those methods' own doc comments, 2026-10-07).
        // Her own Monthly Tax/Daily Tax cells render blank in the view
        // (same "no figure" convention as every non-Supervisor role row),
        // not just a 0.00 that would misread as her own computed share.
        // A TSA with 0 base salary takes no tax share either, so the
        // shift's tax is split only among TSAs with a salary (2026-10-23)
        // — same rule as TsaDailyRateService::taxAllocationByTsaId().
        $perTsaByTeam = $tsas->where('tiktok_upsell', false)
            ->filter(fn ($t) => (float) ($entriesByTsaId->get($t->id)?->base_salary ?? 0) > 0)
            ->groupBy('team')->map(
            fn ($teamTsas) => $teamTsas->count() > 0 ? $perShift / $teamTsas->count() : 0.0
        )->all();

        return ['perShift' => $perShift, 'perTsaByTeam' => $perTsaByTeam];
    }

    /** TOTAL SALARY OF TSD — sum of every real TSA's own freshly-computed
     *  TOTAL (base_salary + her applicable overhead refs — see
     *  CostBreakdownCalculator::tsaTotal()'s own doc comment), NOT her raw
     *  base_salary alone (explicit reversal, 2026-09-30, from the user's
     *  own formula-bar screenshot proving base_salary is her raw base
     *  only). Confirmed against the sheet's own raw CSV to exclude the
     *  CEO/Sales Director/Telesales Manager/QA Specialist/Junior AI
     *  Engineer/Supervisor rows entirely — those are shown on the same
     *  page for context, but this one figure is Telesales-ASSOCIATE-only,
     *  same "TSD" (Telesales Department staff, not the wider leadership/
     *  support team) framing the sheet's own label implies. $salaryRows is
     *  the SAME flat sequence the top section renders from — reused here
     *  rather than recomputed so this total can never drift from what's
     *  actually displayed. */
    private function totalSalaryOfTsd($salaryRows): float
    {
        return $salaryRows->where('type', 'tsa')->sum('total');
    }

    /** Auto-save for one role's own base_salary (explicit request,
     *  2026-09-29, same debounced-PATCH-per-field convention as every other
     *  page in this module). overhead_group/overhead_divisor are read-only
     *  from this endpoint's own perspective (structural fields with no UI
     *  control — see rework_shared_bonus_into_overhead_group_on_cost_
     *  breakdown_roles_table's own doc comment). Editing base_salary here
     *  can change OTHER roles' own overhead-per-TSA figure too (a role
     *  sharing this one's own overhead_group sums their base_salary
     *  together before dividing) — returns every role's own freshly-
     *  recomputed overhead figure, same "one shared value change, every
     *  dependent row refreshed" convention as updatePool() below. */
    public function updateRole(Request $request, CostBreakdownRole $costBreakdownRole)
    {
        $data = $request->validate([
            // 'filled', not just 'string' (explicit request, 2026-10-03:
            // "i want to make it roles is editable like role and name") —
            // label is now a real editable field, so an empty save must be
            // rejected rather than leaving a role with no name at all.
            'label' => ['sometimes', 'required', 'string', 'max:255'],
            'person_name' => ['sometimes', 'nullable', 'string', 'max:255'],
            'base_salary' => ['sometimes', 'numeric', 'min:0'],
        ]);

        $month = $costBreakdownRole->month;
        $this->enterMonth($month);
        $old = $costBreakdownRole->only(array_keys($data));
        $costBreakdownRole->update($data);

        foreach ($data as $field => $newValue) {
            if ((string) ($old[$field] ?? '') === (string) $newValue) continue;
            $formatter = $field === 'base_salary' ? fn ($v) => number_format((float) $v, 2) : null;
            ActivityLogger::logFieldUpdate('cost_breakdown.field_updated', $costBreakdownRole, $field, $old[$field], $newValue, $costBreakdownRole->label, $formatter);
        }

        return response()->json([
            'success' => true,
            'total' => $costBreakdownRole->base_salary,
            'recomputedOverhead' => $this->recomputeAllRoleOverhead($month),
        ]);
    }

    /** Adds a new row to the Salary Breakdown table (explicit request,
     *  2026-10-03: "i want you to add role" — + icon next to the table's
     *  own header, same "admin-added, deletable" pattern Projections' own
     *  custom rows already use). seed_key stays null — only the 7 fixed
     *  SEED_ROLES rows carry one, so CostBreakdownRole::ensureSeeded()
     *  never touches a custom role's own structural fields on a later
     *  page load the way it does the 7 fixed ones.
     *
     *  overhead_group (explicit decision, same request: "full featured —
     *  can also join a shared overhead group") lets a new role join an
     *  EXISTING group (merges its own base_salary into that group's
     *  shared Ref. figure, same as CEO/Sales Director/Telesales Manager
     *  already share one) — blank/omitted means standalone, no Shared
     *  Ref. column at all, same as every role with overhead_group = null
     *  already renders. overhead_divisor is REQUIRED only when joining a
     *  group (meaningless otherwise) — 'total' (÷12, company-wide) or
     *  'team' (÷6, per-team), matching CostBreakdownRole::
     *  OVERHEAD_DIVISOR_COUNTS exactly; deliberately NOT independently
     *  settable from the group it joins, since every role already in that
     *  group shares the identical divisor (picking a different one here
     *  would silently split one "shared" figure into two disagreeing
     *  ones). team (TSA-nesting) is explicitly OUT of scope for a custom
     *  role — never accepted here, so a custom role can never accidentally
     *  claim a team's real TSA roster out from under its own Supervisor
     *  row. */
    public function storeRole(Request $request)
    {
        $data = $request->validate([
            'label' => ['required', 'string', 'max:255'],
            'person_name' => ['sometimes', 'nullable', 'string', 'max:255'],
            'base_salary' => ['sometimes', 'numeric', 'min:0'],
            'overhead_group' => ['sometimes', 'nullable', 'string', 'max:255'],
            'overhead_divisor' => ['required_with:overhead_group', 'nullable', 'string', \Illuminate\Validation\Rule::in(\App\Support\DataSet::isPartners() ? ['total', 'team', 'partner'] : ['total', 'team'])],
        ]);

        $month = $this->sessionMonth();
        $this->ensureEditableMonth($month);
        $this->enterMonth($month);
        $overheadGroup = $data['overhead_group'] ?? null;

        // A role joining an existing group MUST land adjacent to that
        // group's own other members — root-caused live, 2026-10-03: the
        // view's own rowspan logic assumes a group's rows are physically
        // CONTIGUOUS (the first member's own merged cell spans N rows
        // straight down), so simply appending the new role to the very
        // end of the whole table (its own far-away sort_order, same
        // group) silently swallowed the UNRELATED role sitting right
        // after the group's old last member into that merged region
        // instead (confirmed live: QA Specialist's own row visually
        // merged into the executive group's shared cell after adding a
        // new executive-group role at the end of the table). Inserting
        // immediately after the group's current last member, and shifting
        // every role from there on down by one, keeps every group
        // contiguous regardless of where a new member is added.
        if ($overheadGroup) {
            $lastGroupMember = CostBreakdownRole::where('month', $month)->where('overhead_group', $overheadGroup)->max('sort_order');
            $insertAt = $lastGroupMember !== null ? $lastGroupMember + 1 : CostBreakdownRole::where('month', $month)->max('sort_order') + 1;
            CostBreakdownRole::where('month', $month)->where('sort_order', '>=', $insertAt)->increment('sort_order');
        } else {
            $insertAt = CostBreakdownRole::where('month', $month)->max('sort_order') + 1;
        }

        $role = CostBreakdownRole::create([
            'month' => $month,
            'seed_key' => null,
            'label' => $data['label'],
            'person_name' => $data['person_name'] ?? null,
            'base_salary' => $data['base_salary'] ?? 0,
            'overhead_group' => $overheadGroup,
            'overhead_divisor' => $overheadGroup ? $data['overhead_divisor'] : null,
            'sort_order' => $insertAt,
        ]);
        ActivityLogger::log('cost_breakdown.role_created', $role, "Added role \"{$data['label']}\" to the Salary Breakdown table.");

        return response()->json([
            'success' => true,
            'role' => $role,
            'recomputedOverhead' => $this->recomputeAllRoleOverhead($month),
        ]);
    }

    /** Removes a role from ITS month only (explicit request, 2026-10-30:
     *  "can be remove supervisor or any other Role ... but last month is
     *  still the same 2 supervisor") — fixed roles included now. Soft-
     *  deleted, so CostBreakdownRole::ensureSeeded() doesn't put a fixed
     *  role back and "Add Cost Breakdown" carries the removal forward;
     *  every earlier month keeps its own row. With one supervisor left she
     *  covers every TSA (CostBreakdownRole::soleSupervisor()). */
    public function destroyRole(CostBreakdownRole $costBreakdownRole)
    {
        $month = $costBreakdownRole->month;
        $this->ensureEditableMonth($month);
        $this->enterMonth($month);
        $label = $costBreakdownRole->label;
        $costBreakdownRole->delete();
        $monthLabel = \Illuminate\Support\Carbon::createFromFormat('Y-m', $month)->format('F Y');
        ActivityLogger::log('cost_breakdown.role_deleted', null, "Removed role \"{$label}\" from the {$monthLabel} Salary Breakdown table.");

        return response()->json([
            'success' => true,
            'recomputedOverhead' => $this->recomputeAllRoleOverhead($month),
        ]);
    }

    /** Product count for the salary table's own "Daily Rate / Product"
     *  column (explicit request, 2026-09-30: "divided be all product like
     *  how many product in the cards") — same card count Expected Income's
    /** Every role's own freshly-recomputed overhead-per-TSA figure, keyed
     *  by role id — shared by updateRole() above since editing one role's
     *  base_salary can change every OTHER role in the same overhead_group's
     *  own displayed figure too (they all show the SAME summed-then-divided
     *  number, per the real sheet's own merged cell). */
    private function recomputeAllRoleOverhead(string $month): array
    {
        return $this->overheadByRoleId(CostBreakdownRole::where('month', $month)->get())->all();
    }

    /** Every role's own overhead-per-TSA figure, keyed by role id — null
     *  for a role with no overhead_group at all (CostBreakdownRole::
     *  OVERHEAD_DIVISOR_COUNTS' own doc comment has the full formula).
     *  Shared by index() and recomputeAllRoleOverhead() so this app never
     *  computes it two different ways. */
    private function overheadByRoleId($roles)
    {
        return CostBreakdownRole::overheadByRoleId($roles);
    }

    /** Every team's own applicable overhead refs for CostBreakdownCalculator
     *  ::tsaTotal() — the executive group's figure (company-wide), the
     *  support group's figure (company-wide), and THAT team's own
     *  Supervisor's figure, keyed by team. Confirmed a LIVE FORMULA from
     *  the user's own formula-bar screenshot, 2026-09-30:
     *  "=D5+D9+D12+C13" for Julie Francisco — every TSA on the SAME team
     *  sees the SAME 3 refs, only her own base_salary differs. */
    private function overheadRefsByTeam($roles, $overheadByRoleId)
    {
        if (\App\Support\DataSet::isPartners()) {
            return \App\Support\TsaDailyRateService::partnerOverheadRefsByTeam($roles, $overheadByRoleId);
        }

        return CostBreakdownRole::overheadRefsByTeam($roles, $overheadByRoleId, TsaShift::query()->distinct()->pluck('team'));
    }

    /** Auto-save for one shared pool's own monthly amount. Returns every
     *  TSA's own freshly-recomputed row (a pool amount change affects
     *  everyone's own dollar figure for that column, not just one row) —
     *  same "one shared value change, every dependent row refreshed"
     *  convention as ProjectionController::updateRates(). */
    public function updatePool(Request $request, CostBreakdownPool $costBreakdownPool)
    {
        $data = $request->validate([
            'amount' => ['required', 'numeric', 'min:0'],
        ]);

        $month = $costBreakdownPool->month;
        $this->enterMonth($month);
        $oldAmount = $costBreakdownPool->amount;
        $costBreakdownPool->update($data);
        if ((float) $oldAmount != (float) $data['amount']) {
            ActivityLogger::logFieldUpdate('cost_breakdown.field_updated', $costBreakdownPool, 'amount', $oldAmount, $data['amount'], $costBreakdownPool->label, fn ($v) => number_format((float) $v, 2));
        }

        // "Daily Cost per product"/"Daily Cost" mini-table — NOT scoped to
        // any TSA (see that table's own doc comment in the view), so it
        // was never included in recomputeAllTsaRows()'s own per-TSA-keyed
        // shape and silently stayed stale after a live pool edit (real bug
        // caught live, 2026-10-06: "why is it when i change in the Shared
        // Monthly Cost Pools ... [this table] why is it not changing?").
        // Same two calls index() itself uses to build these rows on a
        // fresh page load.
        // Active (Days > 0) TSA count, not the plain roster count —
        // TsaDailyRateService::activeTsaCount()'s own doc comment for why
        // (2026-10-06 follow-up, right after this same mini-table's own
        // live-update fix).
        $poolAmounts = CostBreakdownPool::where('month', $month)->pluck('amount', 'key')->all();
        $dailyCostRow = CostBreakdownCalculator::dailyCostRow($poolAmounts, TsaDailyRateService::activeTsaCount());
        $dailyCostPerProductRow = CostBreakdownCalculator::dailyCostPerProductRow($dailyCostRow, TsaDailyRateService::productCount());

        return response()->json([
            'success' => true,
            'pool' => ['key' => $costBreakdownPool->key, 'amount' => $costBreakdownPool->amount],
            'recomputed' => $this->recomputeAllTsaRows(),
            'dailyCostRow' => $dailyCostRow,
            'dailyCostPerProductRow' => $dailyCostPerProductRow,
        ]);
    }

    /** Auto-save for one TSA's own Days/base_salary — base_salary is her
     *  RAW base salary only (her own displayed TOTAL is computed fresh from
     *  base_salary + her applicable overhead refs — see
     *  CostBreakdownCalculator::tsaTotal()'s own doc comment). Days is the
     *  only field here that changes every OTHER TSA's own % share too (see
     *  CostBreakdownCalculator's own doc comment) — same "shared total,
     *  every row recomputed" reasoning as updatePool() above.
     *
     *  Days ALSO changes the "Daily Cost per product"/"Daily Cost"
     *  mini-table now (2026-10-06, explicit follow-up: "if i make anne 0
     *  days why is it the Daily Cost per product, and Daily Cost is not
     *  changing ... it should be reflect" — a 0-day TSA no longer counts
     *  toward that table's own TSA-count divisor either, see
     *  TsaDailyRateService::activeTsaCount()'s own doc comment) — this
     *  table isn't scoped to any TSA at all (same reason updatePool()
     *  needed the identical fix), so it was never included in
     *  recomputeAllTsaRows()'s own per-TSA-keyed shape and previously
     *  stayed stale here until a full page reload, same gap updatePool()
     *  had before its own 2026-10-06 fix. */
    public function updateTsaEntry(Request $request, TsaShift $tsaShift)
    {
        $data = $request->validate([
            'base_salary' => ['sometimes', 'numeric', 'min:0'],
            'days' => ['sometimes', 'integer', 'min:0'],
        ]);

        $month = $this->sessionMonth();
        $this->ensureEditableMonth($month);
        $this->enterMonth($month);
        $entry = CostBreakdownTsaEntry::where('month', $month)->where('tsa_id', $tsaShift->id)->first()
            ?? new CostBreakdownTsaEntry(['month' => $month, 'tsa_id' => $tsaShift->id, 'base_salary' => 0, 'days' => 30]);
        $old = $entry->only(array_keys($data));
        $entry->fill($data);
        $entry->save();

        foreach ($data as $field => $newValue) {
            if ((float) ($old[$field] ?? 0) == (float) $newValue) continue;
            $formatter = $field === 'base_salary' ? fn ($v) => number_format((float) $v, 2) : null;
            ActivityLogger::logFieldUpdate('cost_breakdown.field_updated', $entry, $field, $old[$field] ?? 0, $newValue, $tsaShift->display_name, $formatter);
        }

        $roles = CostBreakdownRole::where('month', $month)->get();
        $overheadRefs = $this->overheadRefsByTeam($roles, $this->overheadByRoleId($roles))->get($tsaShift->team) ?? [];
        $total = CostBreakdownCalculator::tsaTotal($entry->base_salary, $overheadRefs);
        $dailyRate = CostBreakdownCalculator::tsaDailyRate($total);

        // Daily Rate / Product = $dailyRate above ($total ÷ 24) split
        // across every CHECKED product — see TsaDailyRateService's own doc
        // comment. Recomputed fresh here (not reused from before this
        // save) since editing base_salary just moved $total/$dailyRate.
        $dailyRatePerProduct = TsaDailyRateService::perProductByTsaId()[$tsaShift->id] ?? 0.0;

        $poolAmounts = CostBreakdownPool::where('month', $month)->pluck('amount', 'key')->all();
        $dailyCostRow = CostBreakdownCalculator::dailyCostRow($poolAmounts, TsaDailyRateService::activeTsaCount());
        $dailyCostPerProductRow = CostBreakdownCalculator::dailyCostPerProductRow($dailyCostRow, TsaDailyRateService::productCount());

        return response()->json([
            'success' => true,
            'total' => $total,
            'dailyRate' => $dailyRate,
            'dailyRatePerProduct' => $dailyRatePerProduct,
            'recomputed' => $this->recomputeAllTsaRows(),
            'monthlyTaxByTsaId' => $this->monthlyTaxByTsaId($month),
            'dailyCostRow' => $dailyCostRow,
            'dailyCostPerProductRow' => $dailyCostPerProductRow,
        ]);
    }

    /** Every TSA's Monthly Tax after a salary edit (bug fix, 2026-10-30:
     *  Jhun's salary went 0 → 15,600 but her tax stayed 0.00 and her team
     *  stayed split 6 ways until a reload) — a salary crossing 0 changes
     *  her team's divisor, so the whole team's share changes. Same rule as
     *  the page's own render (taxFigures()); null = TikTok TSA, no figure. */
    private function monthlyTaxByTsaId(string $month): array
    {
        $tsas = TsaShift::orderBy('team')->orderBy('sort_order')->get();
        $entriesByTsaId = CostBreakdownTsaEntry::where('month', $month)->whereIn('tsa_id', $tsas->pluck('id'))->get()->keyBy('tsa_id');
        $tsas = $tsas->filter(fn (TsaShift $t) => TsaDailyRateService::belongsToMonth($t, $month, $entriesByTsaId))->values();
        $perTsaByTeam = $this->taxFigures($tsas, $entriesByTsaId)['perTsaByTeam'];

        return $tsas->mapWithKeys(fn (TsaShift $tsa) => [$tsa->id => $tsa->tiktok_upsell ? null
            : ((float) ($entriesByTsaId->get($tsa->id)?->base_salary ?? 0) > 0 ? (float) ($perTsaByTeam[$tsa->team] ?? 0.0) : 0.0)])->all();
    }

    /** Auto-save for one product's own "has cost" checkbox (explicit
     *  request, 2026-09-30: "is it possible that can be select which
     *  product will be divided? ... user only can identify what product
     *  that has cost") — toggling it changes every REAL TSA's own Daily
     *  Rate / Product at once (the divisor itself changed), same "one
     *  shared value change, every dependent row refreshed" convention as
     *  updatePool()/updateTsaEntry() above, just for the product columns
     *  instead of the pool columns. */
    public function updateProductHasCostAllocation(Request $request, Product $product)
    {
        $data = $request->validate([
            'has_cost_allocation' => ['required', 'boolean'],
        ]);

        // Saved for the month being viewed only (per month, 2026-10-24) —
        // earlier months keep their own checkboxes.
        $month = $this->sessionMonth();
        $this->ensureEditableMonth($month);
        $this->enterMonth($month);
        $wasChecked = $product->has_cost_allocation;
        \App\Models\CostBreakdownProductFlag::updateOrCreate(
            ['month' => $month, 'product_id' => $product->id],
            ['has_cost_allocation' => (bool) $data['has_cost_allocation']]
        );
        \App\Support\RequestMemo::flush();
        if ((bool) $wasChecked !== (bool) $data['has_cost_allocation']) {
            ActivityLogger::log('cost_breakdown.product_cost_allocation_toggled', $product, ($data['has_cost_allocation'] ? 'Checked' : 'Unchecked') . " \"{$product->display_name}\" in Cost Allocation Per TSA.");
        }

        return response()->json([
            'success' => true,
            'hasCostAllocation' => $product->has_cost_allocation,
            'recomputed' => $this->recomputeAllTsaRows(),
        ]);
    }

    /** Every real TSA's own freshly-recomputed % share + per-pool row +
     *  row TOTAL + product columns, keyed by tsa_id — shared by
     *  updatePool()/updateTsaEntry()/updateProductHasCostAllocation() above
     *  since any of those changes every row's own figures, not just the
     *  one just-saved cell's row. Also carries each TSA's own TOP salary
     *  table "Daily Rate / Product" figure under 'salaryDailyRatePerProduct'
     *  (explicit request, 2026-09-30: "i want it will auto to that changed
     *  like i should not reload whole page to reflect" — a product
     *  checkbox toggle changes the divisor for BOTH tables at once, but
     *  they're deliberately different SOURCE totals — see
     *  TsaDailyRateService's own doc comment — so this key is intentionally
     *  separate from 'derived.products' above, never conflated). */
    private function recomputeAllTsaRows(): array
    {
        $month = TsaDailyRateService::contextMonth();
        $tsas = TsaShift::orderBy('team')->orderBy('sort_order')->get();
        $entriesByTsaId = CostBreakdownTsaEntry::where('month', $month)->whereIn('tsa_id', $tsas->pluck('id'))->get()->keyBy('tsa_id');
        $tsas = $tsas->filter(fn (TsaShift $t) => TsaDailyRateService::belongsToMonth($t, substr(TsaDailyRateService::contextDate(), 0, 7), $entriesByTsaId))->values();
        $poolAmounts = CostBreakdownPool::where('month', $month)->pluck('amount', 'key')->all();
        $flaggedProductRows = TsaDailyRateService::flaggedProductRows();
        $flaggedProductCount = $flaggedProductRows->count();
        $salaryDailyRatePerProductByTsaId = TsaDailyRateService::perProductByTsaId();

        $totalDays = $tsas->sum(fn (TsaShift $tsa) => ($entriesByTsaId->get($tsa->id)?->days) ?? 30);

        return $tsas->mapWithKeys(function (TsaShift $tsa) use ($entriesByTsaId, $totalDays, $poolAmounts, $flaggedProductRows, $flaggedProductCount, $salaryDailyRatePerProductByTsaId) {
            $entry = $entriesByTsaId->get($tsa->id);
            $days = $entry?->days ?? 30;
            $share = CostBreakdownCalculator::shareOfDays($days, $totalDays);
            $derived = CostBreakdownCalculator::rowForShare($share, $poolAmounts);
            $dailyRate = CostBreakdownCalculator::tsaDailyRate($derived['total']);
            $dailyRatePerProduct = CostBreakdownCalculator::tsaDailyRatePerProduct($dailyRate, $flaggedProductCount);
            $derived['products'] = $flaggedProductRows->pluck('label')->mapWithKeys(fn ($label) => [$label => $dailyRatePerProduct])->all();

            return [$tsa->id => [
                'days' => $days,
                'share' => $share,
                'derived' => $derived,
                'salaryDailyRatePerProduct' => $salaryDailyRatePerProductByTsaId[$tsa->id] ?? 0.0,
            ]];
        })->all();
    }
}
