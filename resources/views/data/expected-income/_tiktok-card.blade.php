{{--
    One of Expected Income's 2 fixed TikTok cards (explicit request,
    2026-10-05) — "TIKTOK: SH NATURALS" / "TIKTOK: NATUREVA", shown only
    in a TikTok-flagged TSA's own card stack. Deliberately NOT given
    data-product-id (unlike _product-card.blade.php) — a TikTok card is
    never a real Product, so it must never bleed into a TSA's REAL
    product-card rollup (see buildTeamDailyRows()'s own overview, scoped
    to her real cards only). Tagged data-tiktok-card="1" instead
    (2026-10-06 fix — real bug, screenshot: "why is it in the tsa card in
    tiktok it is not totalling?" — the TSA's own TikTok overview card kept
    getting zeroed out by refreshDayOverall() after any TikTok field
    autosaved, since that JS only ever summed `[data-product-id]` cards
    and found none in a TikTok scroller; it now also sums
    `[data-tiktok-card]` cards, scoped the same way since a scroller only
    ever holds one kind or the other, never both).

    Lock toggle (explicit request, 2026-10-07: "i want to have like lock
    icon too" — reverses this file's own original "no lock toggle"
    decision, written before Salaries became a computed figure here) —
    one lock PER CARD, same granularity/markup/180ms cross-fade animation
    as _product-card.blade.php's own (data-ei-lock-toggle, picked up by
    the SAME wireLockToggles() in expected-income.blade.php — no new JS
    needed, that handler already re-renders via data.cardHtml and cross-
    fades generically). Only shown in the editable 1-day case ($card['locked']
    is only ever set there — see buildTiktokRows()'s own doc comment);
    the read-only overview/range-summed cases pass no lock button at all,
    same "overview/range view never shows a lock, only a real editable
    card does" rule _product-card.blade.php already follows.

    Required: $card ('key'/'label'/'derived', plus 'entry'/'locked' only
    in the editable 1-day case), $tsa, $dateStr, $fmtMoney, $fmtPct, $editable.

    Deliberately uses the plain built-in SELLING_COST_ROWS/
    OPERATING_COST_ROWS constants, NOT sellingCostRows()/operatingCostRows()
    (which merge in every row added via Projections' + icon) — a custom
    row has no column on ExpectedIncomeTiktokEntry and no save endpoint
    of its own here (explicit scope: just these 2 fixed cards, nothing
    configurable), so including it would render a silently-broken input
    with no data-custom-action to save to. $sellingRows/$operatingRows/
    $customRowKeys are intentionally NOT accepted as params for this
    reason — always ignore whatever the page-level fetch passed in.
--}}
@php($locked = (bool) ($card['locked'] ?? false))
<div class="ei-card bg-white dark:bg-slate-900 border border-line dark:border-slate-700 rounded-2xl shadow-panel overflow-hidden w-[26rem] shrink-0"
     data-tiktok-card="1"
     @if($editable ?? false)
     data-action="{{ route('data.expected-income.update-tiktok', ['cardKey' => $card['key'], 'tsaShift' => $tsa->id, 'date' => $dateStr]) }}"
     @endif>
    <div class="px-5 py-4 flex items-center gap-2" style="background:#c9daf8;">
        <span class="font-mono font-bold text-sm uppercase tracking-wide text-ink truncate block flex-1 min-w-0">{{ $card['label'] }}</span>
        @if($editable ?? false)
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
        @endif
    </div>
    @include('data.expected-income._card-body', ['d' => $card['derived'], 'entry' => $card['entry'] ?? null, 'sellingRows' => \App\Support\ExpectedIncomeCalculator::SELLING_COST_ROWS, 'operatingRows' => \App\Support\ExpectedIncomeCalculator::OPERATING_COST_ROWS, 'customRowKeys' => [], 'fmtMoney' => $fmtMoney, 'fmtPct' => $fmtPct, 'editable' => $editable ?? false, 'tsaScoped' => false, 'lockedOperatingKeys' => ['salaries'], 'locked' => $locked])
</div>
