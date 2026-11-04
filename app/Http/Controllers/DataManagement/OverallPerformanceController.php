<?php

namespace App\Http\Controllers\DataManagement;

use App\Http\Controllers\Controller;
use App\Models\ProjectionColumn;
use App\Support\DataSet;
use App\Support\DateRangeFilter;
use App\Support\ExpectedIncomeCalculator;
use App\Support\ProjectionCalculator;
use App\Support\ProjectionMonth;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * TSD Overall Performance (explicit request, 2026-11-04): SH E-COM (TSD's own
 * data) and the partners (PEAK.CO / M&J / MLC, Partners Data Management's
 * database) side by side. Read-only — every figure comes from the existing
 * page on its own dataset, never re-derived here:
 *  - projections():     Projections' Telesales Department card (SH E-COM) +
 *                       each partner's own monthly target card, plus a total.
 *  - salesSummary():    Summary Sales Report's OVERALL TOTAL per day, one row
 *                       for SH E-COM and one for PARTNERS.
 *  - expectedIncome():  Expected Income's overall card (SH E-COM), the
 *                       partners' overall card + each partner's card.
 * TSD Data Management only, admins only (partners data is admin-only).
 */
class OverallPerformanceController extends Controller
{
    private const SH_ECOM_LABEL = 'SH ECOMMERCE';

    private const SHEET_YELLOW = '#ffff00';

    /** Partners' sheet cards list only Shipping Fee + COD Fee under Selling. */
    private const PARTNER_SELLING_KEYS = ['shipping_fee', 'cod_fee'];

    public function projections(Request $request)
    {
        $this->guard();
        $month = $this->resolveMonth($request);

        $tsd = $this->projectionsFor($month);
        $partners = $this->onPartnersOrNull(fn () => $this->projectionsFor($month));

        $cards = [['label' => self::SH_ECOM_LABEL, 'pnl' => $tsd['telesales']]];
        foreach ($partners['shifts'] ?? [] as $label => $pnl) {
            $cards[] = ['label' => $label, 'pnl' => $pnl];
        }
        $total = array_reduce(array_slice($cards, 1), fn ($sum, $card) => ProjectionCalculator::sumPnl($sum, $card['pnl']), $cards[0]['pnl']);

        return view('data.overall.projections', [
            'month'         => $month,
            'total'         => $total,
            'cards'         => $cards,
            'sellingRows'   => ProjectionCalculator::sellingCostRows(),
            'operatingRows' => ProjectionCalculator::operatingCostRows(),
            'partnersError' => $partners === null,
        ]);
    }

    public function salesSummary(Request $request)
    {
        $this->guard();
        $range = DateRangeFilter::resolve($request, 'overall-sales-summary');
        [$dateFrom, $dateTo] = [$range['from'], $range['to']];

        $rows = [['label' => 'SH E-COM', 'data' => app(TsaSalesReportController::class)->overallByDate($dateFrom, $dateTo)]];
        $partners = $this->onPartnersOrNull(fn () => app(TsaSalesReportController::class)->overallByDate($dateFrom, $dateTo));
        $rows[] = ['label' => 'PARTNERS', 'data' => $partners ?? ['range' => [], 'days' => []]];

        return view('data.overall.sales-summary', [
            'rows'          => $rows,
            'dateFrom'      => $dateFrom,
            'dateTo'        => $dateTo,
            'dateChunks'    => collect(iterator_to_array(Carbon::parse($dateFrom)->daysUntil(Carbon::parse($dateTo))))->chunk(7)->values(),
            'partnersError' => $partners === null,
        ]);
    }

    public function expectedIncome(Request $request)
    {
        $this->guard();
        $range = DateRangeFilter::resolve($request, 'overall-expected-income');
        [$dateFrom, $dateTo] = [$range['from'], $range['to']];

        // Same card row as the sheet (2026-11-04): black TSD OVERALL (SH E-COM
        // + Partners), yellow SH E-COM, yellow TELESALES PARTNERSHIP (all
        // partners), then each partner. Each card is rendered on its own
        // dataset so _card-body picks that dataset's P&L shape.
        $tsdTotals = app(ExpectedIncomeController::class)->overallTotals($dateFrom, $dateTo);
        $partnerTotals = $this->onPartnersOrNull(fn () => app(ExpectedIncomeController::class)->overallTotals($dateFrom, $dateTo) + [
            // TSAs on the partners roster that belong to no partner: their
            // costs land on TELESALES PARTNERSHIP but on no partner card.
            'orphanTsas' => \App\Models\TsaShift::whereNotIn('team', array_column(\App\Support\Teams::config(), 'order_team'))
                ->orderBy('sort_order')->get()->map(fn ($t) => $t->display_name . ' (' . $t->team . ')')->all(),
        ]);

        $combined = $partnerTotals === null ? $tsdTotals['overall'] : $this->combineIncome($tsdTotals['overall'], $partnerTotals['overall']);
        $cards = [
            $this->renderIncomeCard($combined, 'TSD OVERALL : EXPECTED PERFORMANCE', '#000000', '#ffffff', 'eiOverallCardAll'),
            $this->renderIncomeCard($tsdTotals['overall'], 'TSD ' . self::SH_ECOM_LABEL . ' : EXPECTED PERFORMANCE', self::SHEET_YELLOW, '#000000', 'eiOverallCardTsd'),
        ];

        $partnerCards = $partnerTotals === null ? null : DataSet::onPartners(function () use ($partnerTotals) {
            $html = [$this->renderIncomeCard($partnerTotals['overall'], 'TELESALES PARTNERSHIP : EXPECTED PERFORMANCE', self::SHEET_YELLOW, '#000000', 'eiOverallCardPartners')];
            foreach ($partnerTotals['teams'] as $team) {
                $html[] = $this->renderIncomeCard($team['derived'], strtoupper($team['label']), '#d9ead3', '#000000', null);
            }

            return $html;
        });

        $tally = [[
            'title' => 'TSD OVERALL = SH E-COM + TELESALES PARTNERSHIP',
            'rows'  => $this->tallyRows($combined, $partnerTotals === null ? [$tsdTotals['overall']] : [$tsdTotals['overall'], $partnerTotals['overall']]),
        ]];
        if ($partnerTotals !== null) {
            $tally[] = [
                'title' => 'TELESALES PARTNERSHIP = ' . implode(' + ', array_map(fn ($t) => strtoupper($t['label']), $partnerTotals['teams'])),
                'rows'  => $this->tallyRows($partnerTotals['overall'], array_column($partnerTotals['teams'], 'derived')),
                'note'  => $partnerTotals['orphanTsas'] === [] ? null
                    : 'TSAs in Partners Data Management that belong to no partner (their costs count in TELESALES PARTNERSHIP only): ' . implode(', ', $partnerTotals['orphanTsas']),
            ];
        }

        return view('data.overall.expected-income', [
            'tally'            => $tally,
            // Snapshot covers only the 3 overall cards (2026-11-04), not each partner.
            'topCardsHtml'     => array_merge($cards, array_slice($partnerCards ?? [], 0, 1)),
            'partnerCardsHtml' => array_slice($partnerCards ?? [], 1),
            'dateFrom'      => $dateFrom,
            'dateTo'        => $dateTo,
            'partnersError' => $partnerCards === null,
        ]);
    }

    private function guard(): void
    {
        abort_if(DataSet::isPartners(), 404);
    }

    /** ?month= wins and is remembered; else the last picked; else this month. */
    private function resolveMonth(Request $request): string
    {
        $month = collect([$request->query('month'), session('overall-projections.month')])
            ->first(fn ($m) => is_string($m) && preg_match('/^\d{4}-\d{2}$/', $m)) ?? now()->format('Y-m');
        session(['overall-projections.month' => $month]);

        return $month;
    }

    /** Telesales Department P&L + each shift card's P&L (keyed by team label). */
    private function projectionsFor(string $month): array
    {
        ProjectionMonth::set($month);
        ProjectionColumn::ensureSeededForMonth($month);
        $columns = ProjectionColumn::where('month', $month)->orderBy('sort_order')->get();
        $computed = ProjectionCalculator::forAllColumns($columns, ProjectionCalculator::allRates());

        $shifts = [];
        foreach (ProjectionColumn::shiftKeyLabels() as $key => $label) {
            if (isset($computed[$key])) {
                $shifts[strtoupper($label)] = $computed[$key]['pnl'];
            }
        }

        return ['telesales' => $computed['telesales_department']['pnl'], 'shifts' => $shifts];
    }

    /** Runs on the partners database; null if it isn't reachable/migrated,
     *  so the SH E-COM half of the page still renders. */
    private function onPartnersOrNull(\Closure $callback): mixed
    {
        try {
            return DataSet::onPartners($callback);
        } catch (\Illuminate\Database\QueryException $e) {
            report($e);

            return null;
        }
    }

    /** Tally Checker (2026-11-04): a total card's key figures vs the sum of
     *  the cards it's made of. */
    private const TALLY_FIELDS = [
        'number_of_leads'       => ['Number of Leads', true],
        'number_of_orders'      => ['Number of Orders', true],
        'gross_sales'           => ['Gross Sales', false],
        'cancelled'             => ['Cancelled', false],
        'product_cost'          => ['Product Cost', false],
        'total_selling_costs'   => ['Total Selling Costs', false],
        'tax_allocation'        => ['Tax Allocation', false],
        'total_operating_costs' => ['Total Operating Costs', false],
        'net_income'            => ['Net Income', false],
    ];

    private function tallyRows(array $total, array $parts): array
    {
        return collect(self::TALLY_FIELDS)->map(function (array $field, string $key) use ($total, $parts) {
            [$label, $isCount] = $field;
            $card = round((float) ($total[$key] ?? 0), 2);
            $sum = round(array_sum(array_map(fn ($p) => (float) ($p[$key] ?? 0), $parts)), 2);

            return [
                'label'   => $label,
                'card'    => $isCount ? number_format($card) : number_format($card, 2),
                'sum'     => $isCount ? number_format($sum) : number_format($sum, 2),
                'tallies' => abs($card - $sum) < 0.01,
            ];
        })->values()->all();
    }

    /** SH E-COM + Partners on the TSD card shape. Line items are summed;
     *  Net Income is the two real Net Incomes added (the partners' own
     *  shared-income rule can't be re-derived on the TSD shape). */
    private function combineIncome(array $tsd, array $partners): array
    {
        $sum = ExpectedIncomeCalculator::addDerivedTotals($tsd, $partners);
        $sum['net_income'] = (float) $tsd['net_income'] + (float) $partners['net_income'];
        $sum['net_income_pct'] = $sum['gross_sales'] > 0 ? $sum['net_income'] / $sum['gross_sales'] : 0.0;

        return $sum;
    }

    private function renderIncomeCard(array $derived, string $label, string $headerBg, ?string $headerText, ?string $cardId): string
    {
        return view('data.expected-income._card', [
            'd'             => $derived,
            'label'         => $label,
            'headerBg'      => $headerBg,
            'headerText'    => $headerText,
            'cardId'        => $cardId,
            'sellingRows'   => DataSet::isPartners()
                ? array_intersect_key(ExpectedIncomeCalculator::sellingCostRows(), array_flip(self::PARTNER_SELLING_KEYS))
                : ExpectedIncomeCalculator::sellingCostRows(),
            'operatingRows' => ExpectedIncomeCalculator::operatingCostRows(),
            'customRowKeys' => ExpectedIncomeCalculator::customRowKeys(),
            'fmtMoney'      => fn ($n) => number_format((float) $n, 2),
            'fmtPct'        => fn ($n) => number_format(((float) $n) * 100, 2) . '%',
        ])->render();
    }
}
