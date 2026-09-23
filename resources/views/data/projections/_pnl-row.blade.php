{{--
    One P&L line. $editable (passed by _column.blade.php — true only for
    Opening Shift) decides everything below: Opening Shift is the ONE
    column actually computed from Orders × AOV × rate in the real sheet;
    Telesales Department / Individual TSA Monthly / Individual TSA Daily
    are pure =F39/6-style FORMULAS copied from Opening Shift there, not
    independent cells — so making them look editable would be misleading.
    Explicit decision, 2026-09-23 (asked directly after the cross-card
    formula chain was discovered): those 3 cards' P&L rows are read-only
    displays; only Opening Shift's stay typeable. See
    ProjectionCalculator's own doc comment for the full chain.

    EDITABLE (Opening Shift): a $ input (explicit request, 2026-09-23:
    "the number are editable not the percentage") that back-solves the
    underlying shared rate as (typed amount ÷ Opening Shift's own Gross
    Sales) and PATCHes it via data.projections.update-rates — saving it
    recomputes every column via the ×2/÷6/÷24 chain, not just this one.

    READ-ONLY (the other 3): plain spans, refreshed by applyComputed()
    the same way every other derived total on the page already is.

    $label, $rateKey, $value, $ratePct, $editable required. $pnlKey
    (top-level pnl.* rows) XOR $lineKey (selling_lines.*/operating_lines.*
    rows) selects which data-attribute applyComputed() refreshes.
--}}
<div class="grid grid-cols-[1fr_6.5rem_3.5rem] gap-x-2 items-center py-1">
    <span class="text-ink-muted dark:text-slate-400">{{ $label }}</span>
    @if($editable)
        {{-- type="text" not "number" (explicit request, 2026-09-23: "i
             want has comma... like for example 1,000") — a native number
             input can't display thousands separators at all; pj.js
             formats/parses the commas around it instead. inputmode
             ="decimal" still gets a phone the numeric keypad. --}}
        <input type="text" inputmode="decimal"
               value="{{ number_format($value, 2) }}"
               data-rate="{{ $rateKey }}"
               data-mode="dollar"
               @if($pnlKey ?? null) data-pnl-input="{{ $pnlKey }}" @else data-line-input="{{ $lineKey }}" @endif
               class="pj-rate-field w-full text-right bg-slate-50 dark:bg-slate-800 border border-line dark:border-slate-700 rounded-md px-1.5 py-0.5 text-xs text-ink dark:text-slate-100 focus:ring-2 focus:ring-primary/40 focus:border-primary outline-none">
    @else
        <span @if($pnlKey ?? null) data-pnl="{{ $pnlKey }}" @else data-line="{{ $lineKey }}" @endif
              class="text-right text-ink dark:text-slate-100">{{ number_format($value, 2) }}</span>
    @endif
    <span data-rate-pct="{{ $rateKey }}" class="text-right text-ink-muted dark:text-slate-500 text-xs">{{ number_format($ratePct, 2) }}%</span>
</div>
