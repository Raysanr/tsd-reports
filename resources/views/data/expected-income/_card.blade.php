{{--
    A read-only summary card for the range-summary row (Telesales Expected
    Performance) — $d is already a summed derived array (no raw entry to
    seed inputs from, no save action; this range total is never itself a
    persisted row, same as DsPprCalculator's own OVERALL TOTAL). Wraps
    _card-body with $editable = false.
--}}
<div class="ei-card bg-white dark:bg-slate-900 border border-line dark:border-slate-700 rounded-2xl shadow-panel overflow-hidden w-80 shrink-0">
    <div class="px-5 py-4" style="background:{{ $headerBg }};">
        <span class="font-mono font-bold text-sm uppercase tracking-wide text-ink truncate block">{{ $label }}</span>
    </div>
    @include('data.expected-income._card-body', ['d' => $d, 'sellingRows' => $sellingRows, 'operatingRows' => $operatingRows, 'fmtMoney' => $fmtMoney, 'fmtPct' => $fmtPct, 'editable' => false])
</div>
