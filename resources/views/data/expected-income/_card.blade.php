{{--
    A read-only summary card for the range-summary row (Telesales Expected
    Performance) — $d is already a summed derived array (no raw entry to
    seed inputs from, no save action; this range total is never itself a
    persisted row, same as DsPprCalculator's own OVERALL TOTAL). Wraps
    _card-body with $editable = false.
--}}
<div class="ei-card bg-white dark:bg-slate-900 border border-line dark:border-slate-700 rounded-2xl shadow-panel overflow-hidden w-[26rem] shrink-0"@isset($cardId) id="{{ $cardId }}"@endisset>
    <div class="px-5 py-4 {{ !empty($labelEditUrl) ? 'flex items-center gap-2' : '' }}" style="background:{{ $headerBg }};">
        @if(!empty($labelEditUrl))
        {{-- Rename the overall card (2026-11-04). data-html2canvas-ignore
             keeps the pencil out of the PNG snapshot. Placed before the label in the
             DOM (order-last puts it right) so tests reading a fixed window
             after ">TELESALES<" still reach the card body. --}}
        <button type="button" data-ei-label-edit="{{ $labelEditUrl }}" data-html2canvas-ignore title="Rename card" aria-label="Rename card"
                class="order-last shrink-0 p-1 rounded text-ink/60 hover:text-ink hover:bg-black/5">
            <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M15.232 5.232l3.536 3.536M4 20h4l10.5-10.5a2.5 2.5 0 00-3.536-3.536L4 16.5V20z"/></svg>
        </button>
        @endif
        <span class="font-mono font-bold text-sm uppercase tracking-wide truncate block min-w-0 {{ isset($headerText) ? '' : 'text-ink' }}" @if(isset($headerText)) style="color:{{ $headerText }};" @endif @if(!empty($labelEditUrl)) data-ei-label @endif>{{ $label }}</span>
    </div>
    @include('data.expected-income._card-body', ['d' => $d, 'sellingRows' => $sellingRows, 'operatingRows' => $operatingRows, 'customRowKeys' => $customRowKeys ?? [], 'fmtMoney' => $fmtMoney, 'fmtPct' => $fmtPct, 'editable' => false])
</div>
