{{--
    Telesales Expected Performance + per-team summary rows — extracted so
    ExpectedIncomeController::summary() can re-render just this fragment
    for a live AJAX refresh after every autosave (explicit request,
    2026-09-30: typing into ANY TSA's card should move this total without a
    full page reload — see summary()'s own doc comment for why no
    client-side re-sum can do this instead). Same markup as before the
    extraction, just wrapped in one #eiSummarySection root so the whole
    block can be swapped in one replaceWith().
--}}
<div id="eiSummarySection">
    <div class="mb-3 font-mono font-bold text-sm text-ink dark:text-slate-100">Telesales Expected Performance</div>
    <div class="overflow-x-auto ei-scroller -mx-4 md:-mx-8 px-4 md:px-8 pb-2 mb-8" id="eiSummaryScroller">
        <div class="flex items-start gap-5 w-max">
            @include('data.expected-income._card', ['d' => $summaryOverallTotal, 'label' => 'TELESALES', 'headerBg' => '#fde047', 'sellingRows' => $sellingRows, 'operatingRows' => $operatingRows, 'customRowKeys' => $customRowKeys, 'fmtMoney' => $fmtMoney, 'fmtPct' => $fmtPct])
            @foreach($summaryCards as $card)
            @include('data.expected-income._card', ['d' => $card['derived'], 'label' => $card['label'], 'headerBg' => '#d9ead3', 'sellingRows' => $sellingRows, 'operatingRows' => $operatingRows, 'customRowKeys' => $customRowKeys, 'fmtMoney' => $fmtMoney, 'fmtPct' => $fmtPct])
            @endforeach
        </div>
    </div>

    @if($selectedTeam === 'all')
    @foreach($teamSummaryRows as $teamRow)
    <div class="mb-3 font-mono font-bold text-sm text-ink dark:text-slate-100">{{ strtoupper($teamRow['label']) }}</div>
    <div class="overflow-x-auto ei-scroller -mx-4 md:-mx-8 px-4 md:px-8 pb-2 mb-8">
        <div class="flex items-start gap-5 w-max">
            @include('data.expected-income._card', ['d' => $teamRow['overallTotal'], 'label' => strtoupper($teamRow['label']), 'headerBg' => '#000000', 'headerText' => '#ffffff', 'sellingRows' => $sellingRows, 'operatingRows' => $operatingRows, 'customRowKeys' => $customRowKeys, 'fmtMoney' => $fmtMoney, 'fmtPct' => $fmtPct])
            @foreach($teamRow['cards'] as $card)
            @include('data.expected-income._card', ['d' => $card['derived'], 'label' => $card['label'], 'headerBg' => '#d9ead3', 'sellingRows' => $sellingRows, 'operatingRows' => $operatingRows, 'customRowKeys' => $customRowKeys, 'fmtMoney' => $fmtMoney, 'fmtPct' => $fmtPct])
            @endforeach
        </div>
    </div>
    @endforeach
    @endif
</div>
