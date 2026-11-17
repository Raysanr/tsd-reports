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
 *
 * Operating costs (salaries, shared pools, tax) come from Actual Cost
 * Breakdown and are split over the month's real days, not 24, so a whole
 * month adds up to that month's Actual Cost Breakdown (2026-11-10).
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
        // Costs come from Actual Cost Breakdown (2026-11-10) once it has a
        // month on or before this one; until then, from Cost Breakdown.
        $useActual = \App\Support\CostBreakdownVariant::using(\App\Support\CostBreakdownVariant::ACTUAL,
            fn () => collect(\App\Support\CostBreakdownMonths::existing())->contains(fn ($m) => $m <= $month));
        \App\Support\CostBreakdownVariant::set($useActual ? \App\Support\CostBreakdownVariant::ACTUAL : \App\Support\CostBreakdownVariant::BUDGET);
        \App\Support\RequestMemo::flush();
        try {
            // Rendered inside the try: the views call derive() too.
            $view = parent::index($request);

            return response($view instanceof \Illuminate\Contracts\View\View ? $view->render() : $view);
        } finally {
            ExpectedIncomeCalculator::$actual = false;
            \App\Support\CostBreakdownVariant::reset();
            \App\Support\RequestMemo::flush();
        }
    }

    /** Saves one product card's typed Number of Orders / Product Cost /
     *  Fulfillment Fee (explicit request, 2026-11-17: "automated but
     *  editable"). A blank value clears that override (back to automatic);
     *  a card with no overrides left has its row deleted. Stored only in
     *  actual_income_overrides — Expected Income never sees these. */
    public function updateOverride(Request $request, \App\Models\Product $product, string $date, \App\Models\TsaShift $tsaShift)
    {
        abort_unless(preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) === 1, 404);
        // One override per TSA × product × month (Actual Income shows whole
        // months), stored on the month's first day.
        $date = substr($date, 0, 7) . '-01';
        // One override per TSA × product × month (Actual Income shows whole
        // months), stored on the month's first day.
        $date = substr($date, 0, 7) . '-01';
        $fields = \App\Models\ActualIncomeOverride::FIELDS;
        if (\App\Support\DataSet::isPartners()) {
            $fields = array_values(array_diff($fields, ['fulfillment_fee']));
        }
        $data = $request->validate(collect($fields)->mapWithKeys(fn ($f) => [$f => ['sometimes', 'nullable', 'numeric', 'min:0']])->all());

        $override = \App\Models\ActualIncomeOverride::where('product_id', $product->id)->where('tsa_id', $tsaShift->id)->whereDate('entry_date', $date)->first()
            ?? new \App\Models\ActualIncomeOverride(['product_id' => $product->id, 'tsa_id' => $tsaShift->id, 'entry_date' => $date]);
        $old = $override->only(array_keys($data));
        $override->fill($data);

        $context = $product->display_name . ' — ' . $tsaShift->display_name . ' for ' . \Illuminate\Support\Carbon::parse($date)->format('F Y') . ' (Actual Income)';
        foreach ($data as $field => $new) {
            if (($old[$field] ?? null) === null && $new === null) continue;
            if (($old[$field] ?? null) !== null && $new !== null && (float) $old[$field] == (float) $new) continue;
            \App\Support\ActivityLogger::logFieldUpdate('actual_income.field_updated', $product, $field,
                $old[$field] ?? 'automatic', $new ?? 'automatic', $context,
                fn ($v) => is_numeric($v) ? number_format((float) $v, 2) : (string) $v);
        }

        if (collect(\App\Models\ActualIncomeOverride::FIELDS)->every(fn ($f) => $override->{$f} === null)) {
            if ($override->exists) {
                $override->delete();
            }
        } else {
            $override->save();
        }

        return response()->json(['success' => true]);
    }
}
