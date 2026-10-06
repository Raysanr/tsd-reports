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
                 cards, summed together into one card (explicit request,
                 2026-10-05). Narrowed to ONLY ALL/the TIKTOK TEAM filter as
                 of 2026-10-06 (explicit follow-up, right after TikTok was
                 fully split into its own filter: "why is it there's still
                 tiktok total card in the opening and closing" — TikTok is
                 "separate now", so this card doesn't belong on a REAL
                 team's own summary row); then restored to ALL specifically
                 the same day (explicit follow-up: "in the expected income
                 ALL filter it should be have tiktok right?") — ALL is the
                 site-wide rollup, not a real team's own filter, so it
                 keeps every total including TikTok's. --}}
            @if($selectedTeam === 'all' || $selectedTeam === 'tiktok')
            @include('data.expected-income._card', ['d' => $tiktokOverallTotal, 'label' => 'TIKTOK TOTAL', 'headerBg' => '#c9daf8', 'sellingRows' => $sellingRows, 'operatingRows' => $operatingRows, 'customRowKeys' => $customRowKeys, 'fmtMoney' => $fmtMoney, 'fmtPct' => $fmtPct])
            @endif
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

    {{-- TIKTOK TEAM's own breakdown row (explicit follow-up, 2026-10-06:
         "in the expected income ALL filter it should be have tiktok
         right?" — ALL restores TIKTOK TOTAL above, and now also gets its
         OWN per-team breakdown row here, same shape as TEAM OPENING
         SHIFT/TEAM CLOSING SHIFT above, one block per TikTok-flagged TSA
         showing only her 2 TikTok cards — same $tiktokTsaRows the
         TIKTOK TEAM filter's own daily section already reads, always
         read-only here since this section is a summed rollup, not tied
         to any one date). --}}
    @if($tiktokTsaRows->isNotEmpty())
    <div class="flex items-center justify-between mb-3">
        <div class="font-mono font-bold text-sm text-ink dark:text-slate-100">TIKTOK TEAM</div>
        @include('partials.table-actions', ['target' => 'eiTeamScroller-tiktok', 'name' => 'tiktok-team-summary', 'title' => 'TikTok Team', 'subtitle' => $snapshotDateLabel ?? null, 'pngOnly' => true])
    </div>
    <div class="overflow-x-auto ei-scroller -mx-4 md:-mx-8 px-4 md:px-8 pb-2 mb-8" id="eiTeamScroller-tiktok">
        <div class="flex items-start gap-5 w-max">
            @foreach($tiktokTsaRows as $tsaId => $tsaRow)
            @php
                $tsa = $tsaRow['tsa'];
            @endphp
            <div class="ei-card bg-white dark:bg-slate-900 border border-line dark:border-slate-700 rounded-2xl shadow-panel overflow-hidden w-[26rem] shrink-0" data-out-scope="1">
                <div class="px-5 py-4" style="background:#fde047;">
                    <span class="font-mono font-bold text-sm uppercase tracking-wide text-ink truncate block">{{ $tsa->display_name }}</span>
                </div>
                @include('data.expected-income._card-body', ['d' => $tsaRow['overallTotal'], 'sellingRows' => $sellingRows, 'operatingRows' => $operatingRows, 'customRowKeys' => $customRowKeys, 'fmtMoney' => $fmtMoney, 'fmtPct' => $fmtPct, 'editable' => false])
            </div>
            @foreach($tsaRow['cards'] as $card)
            @include('data.expected-income._tiktok-card', ['card' => $card, 'tsa' => $tsa, 'fmtMoney' => $fmtMoney, 'fmtPct' => $fmtPct, 'editable' => false])
            @endforeach
            @endforeach
        </div>
    </div>
    @endif
    @endif
</div>
