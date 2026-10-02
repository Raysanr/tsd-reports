{{--
    One TSA-scoped product card for a single day — extracted out of
    expected-income.blade.php's own per-day @foreach (explicit request,
    2026-10-02: "can you create lock icon too in this, like in the
    projections ... has smooth transition too like in the projection") so
    the lock-toggle endpoint can re-render this ONE card server-side and
    the frontend can cross-fade the swap, same pattern as Projections' own
    _column.blade.php + its updateColumn() 'cardHtml' response.

    Required: $row (one ProductGrouping::rows() entry — 'label'/'products'/
    'derived'), $tsa, $dateStr, $entry (the real ExpectedIncomeEntry model,
    or null if never saved), $sellingRows, $operatingRows, $customRowKeys,
    $fmtMoney, $fmtPct.
--}}
@php
    $d = $row['derived'];
    $product = $row['products']->first();
    $locked = (bool) ($entry?->is_locked ?? false);
@endphp
<div class="ei-card bg-white dark:bg-slate-900 border border-line dark:border-slate-700 rounded-2xl shadow-panel overflow-hidden w-[26rem] shrink-0"
     data-product-id="{{ $product->id }}"
     data-action="{{ route('data.expected-income.update-tsa', ['product' => $product->id, 'tsaShift' => $tsa->id, 'date' => $dateStr]) }}"
     data-custom-action="{{ route('data.expected-income.update-custom-row-tsa', ['product' => $product->id, 'tsaShift' => $tsa->id, 'date' => $dateStr]) }}">
    <div class="px-5 py-4 flex items-center gap-2" style="background:#d9ead3;">
        <span class="font-mono font-bold text-sm uppercase tracking-wide text-ink truncate block flex-1 min-w-0">{{ $row['label'] }}</span>
        {{-- Lock toggle (explicit request, 2026-10-02) — one lock PER
             PRODUCT CARD, not per-TSA or page-wide (explicit decision,
             same day). Freezes every editable field on THIS card without
             touching its stored values; a grouped card's lock lives on
             its own first member product's entry, same "writes land on
             the first member" convention update()/updateCustomRow()
             already use for saves. --}}
        <button type="button" data-ei-lock-toggle data-locked="{{ $locked ? '1' : '0' }}"
                title="{{ $locked ? 'Unlock this card' : 'Lock this card' }}"
                aria-label="{{ $locked ? 'Unlock this card' : 'Lock this card' }}"
                class="shrink-0 p-1 rounded-md hover:bg-black/10 dark:hover:bg-white/10 transition-colors cursor-pointer">
            @if($locked)
            <svg class="w-4 h-4 text-ink" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" d="M16.5 10.5V6.75a4.5 4.5 0 10-9 0v3.75m-.75 11.25h10.5a2.25 2.25 0 002.25-2.25v-6.75a2.25 2.25 0 00-2.25-2.25H6.75a2.25 2.25 0 00-2.25 2.25v6.75a2.25 2.25 0 002.25 2.25z"/>
            </svg>
            @else
            <svg class="w-4 h-4 text-ink" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" d="M13.5 10.5V6.75a4.5 4.5 0 119 0v3.75M3.75 21.75h10.5a2.25 2.25 0 002.25-2.25v-6.75a2.25 2.25 0 00-2.25-2.25H3.75a2.25 2.25 0 00-2.25 2.25v6.75a2.25 2.25 0 002.25 2.25z"/>
            </svg>
            @endif
        </button>
    </div>
    @include('data.expected-income._card-body', ['d' => $d, 'entry' => $entry, 'sellingRows' => $sellingRows, 'operatingRows' => $operatingRows, 'customRowKeys' => $customRowKeys, 'fmtMoney' => $fmtMoney, 'fmtPct' => $fmtPct, 'editable' => true, 'tsaScoped' => true, 'locked' => $locked])
</div>
