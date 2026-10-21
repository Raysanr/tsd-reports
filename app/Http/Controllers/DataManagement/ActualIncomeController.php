<?php

namespace App\Http\Controllers\DataManagement;

use App\Support\ExpectedIncomeCalculator;
use Illuminate\Http\Request;

/**
 * Actual Income (explicit request, 2026-10-21: "create new page, ACTUAL
 * INCOME PAGE … same as like this like in the expected income but it is
 * all actual income" / "the cards design is like expected income").
 *
 * The SAME page, cards and pipeline as Expected Income — every figure
 * reuses ExpectedIncomeController, so Gross Sales, Orders, Leads, Tax,
 * Product Cost and every Selling/Operating cost can never drift between
 * the two. The only difference is the revenue split: instead of projected
 * 25% Returns, each real upsell order lands in Cancelled & Waiting /
 * Unfulfilled / Fulfilled / Returns / Delivered by its CURRENT Pancake
 * status (ExpectedIncomeCalculator::ACTUAL_STATUS_BUCKETS). Read-only:
 * costs are typed on Expected Income.
 */
class ActualIncomeController extends ExpectedIncomeController
{
    protected const RANGE_SESSION_KEY = 'actual-income';

    public function index(Request $request)
    {
        // Month picker only, no per-day range (explicit request,
        // 2026-10-21). ?month=Y-m wins; a link carrying date_from (team
        // filter) keeps that month; else the last picked month, else this
        // month. The current month runs through today only.
        $month = collect([$request->input('month'), substr((string) $request->input('date_from'), 0, 7), session('actual-income.month')])
            ->first(fn ($m) => is_string($m) && preg_match('/^\d{4}-\d{2}$/', $m)) ?? now()->format('Y-m');
        session(['actual-income.month' => $month]);
        $start = \Illuminate\Support\Carbon::createFromFormat('Y-m-d', $month . '-01')->startOfDay();
        $end = $start->copy()->endOfMonth();
        if ($start->isSameMonth(now())) {
            $end = today();
        }
        $request->merge(['date_from' => $start->toDateString(), 'date_to' => max($start, $end)->toDateString()]);

        ExpectedIncomeCalculator::$actual = true;
        try {
            // Rendered inside the try: the views call derive() too.
            $view = parent::index($request);

            return response($view instanceof \Illuminate\Contracts\View\View ? $view->render() : $view);
        } finally {
            ExpectedIncomeCalculator::$actual = false;
        }
    }
}
