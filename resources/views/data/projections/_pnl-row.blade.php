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

    $customRowId (optional): set only for a user-added row (explicit
    request, 2026-09-26: "add like + icon ... has modal") — renders a
    small × next to the label to remove it everywhere via
    data.projections.custom-rows.destroy, on every card the row appears on
    (not just the one showing the ×), same "shared definition" reasoning
    as the row itself.

    $rowColor (optional, 'red' or 'blue'): explicit request, 2026-09-28
    (real template screenshot) — Cancelled/Projected Returns/Projected
    Delivered in red, Tax Allocation/Geniusmakers Management Fee/HMO
    Expense in blue. Applies to both the label and the value (input or
    span), leaving the %-column always the same muted color.

    $rowKey/$section (optional — only passed for a row that participates
    in the shared drag-reorder, i.e. every Selling/Operating row from
    _column.blade.php's own 2 @foreach loops below, NOT the top-level pnl.*
    rows like Gross Sales/Cancelled that have a fixed position in the P&L
    chain): explicit request, 2026-10-02 ("can you make the row can be
    draggable and can change the position by other row") — every row here
    shares ONE order with Expected Income's own identical rows
    (App\Support\RowOrder), so dragging here reorders both pages. $section
    is just this row's CURRENT section, not a fixed home — 2026-10-06
    follow-up ("is it possible that row in the Selling And Marketing can
    change ... drag to Operating Costs ... vise versa") lets a row
    actually cross into the other section (except cod_fee/fulfillment_fee
    — see RowOrder::LOCKED_TO_SELLING), which also moves it into that
    section's own Total — see projections.blade.php's own drop handler
    for how that's handled (every card's fresh HTML swapped in, not a
    page reload).

    $locked (optional, passed by _column.blade.php as $isLockable &&
    $column->is_locked): explicit follow-up, 2026-10-02 ("and when it is
    locked it cant dragged too") — a locked card's own rows can't be
    reordered either, same "fully frozen, not just the dollar figures"
    meaning as $editable already applying to the <input>s above.

    $draggableCard (optional, passed by _column.blade.php as
    $isLockable): explicit follow-up, 2026-10-02 ("only the Opening Shift:
    Monthly Target can be draggable and other cards is not" — a derived
    card like Telesales Department/every Individual TSA Monthly/Daily
    isn't an independent cell at all, same reason it was never editable;
    its own rows showing a drag handle implied you could reorder FROM it,
    which was never the intent). Only Opening/Closing Shift (the 2
    lockable base cards) get a handle at all — every other card's rows
    still carry data-row-key/data-row-section (so a drag that happens on
    one of THOSE 2 cards still visually updates every other card's own
    row order too, same shared-order behavior as before), just with no
    handle/draggable attribute of their own to initiate one from.
--}}
@php
    $rowColorClass = match ($rowColor ?? null) {
        'red' => 'text-red-600 dark:text-red-400',
        'blue' => 'text-blue-600 dark:text-blue-400',
        default => null,
    };
    // $isOrderedRow: every card's own row still needs data-row-key/
    // data-row-section/the pj-row class (the shared-order JS queries
    // EVERY dropzone, including derived cards', to move the same row
    // there too when a drag happens elsewhere) — it just doesn't get the
    // handle/draggable=true UNLESS this specific card can actually
    // initiate a drag ($draggableCard, only true on Opening/Closing
    // Shift) and isn't locked.
    $isOrderedRow = isset($rowKey, $section);
    $isDraggable = $isOrderedRow && ($draggableCard ?? false) && !($locked ?? false);
@endphp
<div class="grid grid-cols-[1fr_6.5rem_3.5rem] gap-x-2 items-center py-1 {{ $isOrderedRow ? 'pj-row' : '' }}"
     @if($isOrderedRow) data-row-key="{{ $rowKey }}" data-row-section="{{ $section }}" @endif
     @if($isDraggable) draggable="true" @endif>
    <span class="{{ $rowColorClass ?? 'text-ink-muted dark:text-slate-400' }} inline-flex items-center gap-1">
        @if($isDraggable)
            <svg class="pj-row-handle w-3 h-3 shrink-0 cursor-grab text-ink-muted/40 hover:text-ink-muted/70" fill="currentColor" viewBox="0 0 16 16"><circle cx="5" cy="3" r="1.3"/><circle cx="11" cy="3" r="1.3"/><circle cx="5" cy="8" r="1.3"/><circle cx="11" cy="8" r="1.3"/><circle cx="5" cy="13" r="1.3"/><circle cx="11" cy="13" r="1.3"/></svg>
        @endif
        {{ $label }}
        @isset($customRowId)
            <button type="button" data-remove-custom-row="{{ $customRowId }}" title="Remove this row"
                    class="pj-remove-row shrink-0 w-3.5 h-3.5 inline-flex items-center justify-center rounded-full text-[10px] leading-none text-ink-muted/60 hover:text-red-600 hover:bg-red-50 dark:hover:bg-red-950/40 dark:hover:text-red-400">
                &times;
            </button>
        @endisset
    </span>
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
               class="pj-rate-field w-full text-right bg-slate-50 dark:bg-slate-800 border border-line dark:border-slate-700 rounded-md px-1.5 py-0.5 text-xs {{ $rowColorClass ?? 'text-ink dark:text-slate-100' }} focus:ring-2 focus:ring-primary/40 focus:border-primary outline-none">
    @else
        <span @if($pnlKey ?? null) data-pnl="{{ $pnlKey }}" @else data-line="{{ $lineKey }}" @endif
              class="text-right {{ $rowColorClass ?? 'text-ink dark:text-slate-100' }}">{{ number_format($value, 2) }}</span>
    @endif
    <span data-rate-pct="{{ $rateKey }}" class="text-right text-ink-muted dark:text-slate-500 text-xs">{{ number_format($ratePct, 2) }}%</span>
</div>
