{{--
    One of Expected Income's 2 fixed TikTok cards (explicit request,
    2026-10-05) — "TIKTOK: SH NATURALS" / "TIKTOK: NATUREVA", shown only
    in a TikTok-flagged TSA's own card stack. Deliberately NOT given
    data-product-id (unlike _product-card.blade.php) so the page's own
    refreshDayOverall() JS — which sums every `.ei-card[data-product-id]`
    into the TSA's own overview rollup — skips these 2 cards entirely;
    TikTok's own totals are tracked completely separately (see
    ExpectedIncomeController::buildTiktokRows()'s own doc comment) and
    must never bleed into a TSA's real P&L overview card.

    No lock toggle (manual-only card, nothing to lock to) — a plain
    .ei-card wrapper is enough for the existing saveField()/applyDerived()
    JS to pick up via its generic `.ei-field`/`data-action` convention,
    zero JS changes needed.

    Required: $card ('key'/'label'/'derived', plus 'entry' only in the
    editable 1-day case), $tsa, $dateStr, $fmtMoney, $fmtPct, $editable.

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
<div class="ei-card bg-white dark:bg-slate-900 border border-line dark:border-slate-700 rounded-2xl shadow-panel overflow-hidden w-[26rem] shrink-0"
     @if($editable ?? false)
     data-action="{{ route('data.expected-income.update-tiktok', ['cardKey' => $card['key'], 'tsaShift' => $tsa->id, 'date' => $dateStr]) }}"
     @endif>
    <div class="px-5 py-4" style="background:#c9daf8;">
        <span class="font-mono font-bold text-sm uppercase tracking-wide text-ink truncate block">{{ $card['label'] }}</span>
    </div>
    @include('data.expected-income._card-body', ['d' => $card['derived'], 'entry' => $card['entry'] ?? null, 'sellingRows' => \App\Support\ExpectedIncomeCalculator::SELLING_COST_ROWS, 'operatingRows' => \App\Support\ExpectedIncomeCalculator::OPERATING_COST_ROWS, 'customRowKeys' => [], 'fmtMoney' => $fmtMoney, 'fmtPct' => $fmtPct, 'editable' => $editable ?? false, 'tsaScoped' => false])
</div>
