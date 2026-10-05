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
    <div class="flex items-center justify-between mb-3">
        <div class="font-mono font-bold text-sm text-ink dark:text-slate-100">Telesales Expected Performance</div>
        @include('partials.table-actions', ['target' => 'eiSummaryScroller', 'name' => 'telesales-expected-performance', 'title' => 'Telesales Expected Performance', 'subtitle' => $snapshotDateLabel ?? null, 'pngOnly' => true])
    </div>
    <div class="overflow-x-auto ei-scroller -mx-4 md:-mx-8 px-4 md:px-8 pb-2 mb-8" id="eiSummaryScroller">
        <div class="flex items-start gap-5 w-max">
            @include('data.expected-income._card', ['d' => $summaryOverallTotal, 'label' => 'TELESALES', 'headerBg' => '#fde047', 'sellingRows' => $sellingRows, 'operatingRows' => $operatingRows, 'customRowKeys' => $customRowKeys, 'fmtMoney' => $fmtMoney, 'fmtPct' => $fmtPct])
            @foreach($summaryCards as $card)
            @include('data.expected-income._card', ['d' => $card['derived'], 'label' => $card['label'], 'headerBg' => '#d9ead3', 'sellingRows' => $sellingRows, 'operatingRows' => $operatingRows, 'customRowKeys' => $customRowKeys, 'fmtMoney' => $fmtMoney, 'fmtPct' => $fmtPct])
            @endforeach
            {{-- TikTok's own TOTAL — every TikTok-flagged TSA's own 2 fixed
                 cards, summed together into one card each (explicit
                 request, 2026-10-05), regardless of team filter, same as
                 TELESALES above. --}}
            @include('data.expected-income._card', ['d' => $tiktokOverallTotal, 'label' => 'TIKTOK TOTAL', 'headerBg' => '#c9daf8', 'sellingRows' => $sellingRows, 'operatingRows' => $operatingRows, 'customRowKeys' => $customRowKeys, 'fmtMoney' => $fmtMoney, 'fmtPct' => $fmtPct])
        </div>
    </div>

    @if($selectedTeam === 'all')
    @foreach($teamSummaryRows as $teamRow)
    <div class="flex items-center justify-between mb-3">
        <div class="font-mono font-bold text-sm text-ink dark:text-slate-100">{{ strtoupper($teamRow['label']) }}</div>
        @include('partials.table-actions', ['target' => 'eiTeamScroller-' . $loop->index, 'name' => \Illuminate\Support\Str::slug($teamRow['label']) . '-summary', 'title' => $teamRow['label'], 'subtitle' => $snapshotDateLabel ?? null, 'pngOnly' => true])
    </div>
    <div class="overflow-x-auto ei-scroller -mx-4 md:-mx-8 px-4 md:px-8 pb-2 mb-8" id="eiTeamScroller-{{ $loop->index }}">
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
