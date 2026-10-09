@extends('layouts.data')
@section('title', 'Expected Income')
@section('subtitle', 'Daily P&L per product — every figure auto-saves as you type')

@section('content')

<style>
    .ei-scroller { cursor: grab; }
    .ei-scroller.cursor-grabbing { cursor: grabbing; }
</style>

@php
    $fmtMoney = fn ($n) => number_format((float) $n, 2);
    $fmtPct   = fn ($n) => number_format(((float) $n) * 100, 2) . '%';
    // Snapshot-only date label for every table-actions PNG export on this
    // page (explicit request, 2026-10-05) — same convention as Leads
    // Report's own $snapshotDateLabel. This page is card-based, not
    // table-based (see table-actions.blade.php's own 'pngOnly' doc
    // comment) — PNG-only, no CSV icon here.
    $snapshotDateLabel = \Illuminate\Support\Carbon::parse($dateFrom)->format('F j, Y')
        . ($dateFrom === $dateTo ? '' : ' – ' . \Illuminate\Support\Carbon::parse($dateTo)->format('F j, Y'));
    // sellingCostRows()/operatingCostRows(), not the bare constants —
    // explicit request, 2026-09-28: a row added via the + icon on
    // Projections "should be automatically added to the expected income
    // rows," and these two methods already merge in every row from that
    // same shared projection_custom_rows table (see
    // ExpectedIncomeCalculator's own doc comment on them).
    $sellingRows = \App\Support\ExpectedIncomeCalculator::sellingCostRows();
    $operatingRows = \App\Support\ExpectedIncomeCalculator::operatingCostRows();
    $customRowKeys = \App\Support\ExpectedIncomeCalculator::customRowKeys();
@endphp

<div class="mb-6 flex items-end justify-between gap-4 flex-wrap">
    <form method="GET" class="flex items-end gap-3 flex-wrap">
        <input type="hidden" name="team" value="{{ $selectedTeam }}">
        @include('data._date-range-filter', ['fromName' => 'date_from', 'toName' => 'date_to', 'fromValue' => $dateFrom, 'toValue' => $dateTo])

        {{-- Team filter (explicit request, 2026-09-30: "i want to add team
             filter like this ... ALL / TEAM CLOSING / TEAM OPENING") — ALL
             keeps the page's own original product-level cards + both
             teams' own auto-summed rollup cards; picking a real team
             swaps in that team's own real TSAs, one full row of product
             cards each, no separate product-level cards. --}}
        <div class="flex rounded-lg border border-slate-200 dark:border-slate-700 overflow-hidden">
            @foreach($teams as $key => $label)
            <button type="submit" name="team" value="{{ $key }}" data-filter-btn
                    class="px-3 py-1.5 text-xs font-semibold font-mono cursor-pointer transition-colors duration-200 motion-reduce:transition-none
                           {{ $selectedTeam === $key ? 'bg-primary text-white' : 'bg-white dark:bg-slate-900 text-slate-500 dark:text-slate-400 hover:bg-slate-50 dark:hover:bg-slate-800' }}">
                {{ strtoupper($label) }}
            </button>
            @endforeach
        </div>

        {{-- TSA name filter (explicit request, 2026-10-08: "when they
             filter their name, the only card will be display is their tsa
             card and product") — placed directly beside the team buttons
             it depends on, INSIDE the same form/flex row (moved here,
             2026-10-08, after it first rendered as its own flex child of
             the OUTER justify-between row — "make the positioning is user
             friendly" — which pushed it into the leftover middle space
             between the form and the "Drag/scroll" hint on the far right,
             nowhere near the team buttons it filters). Only meaningful
             once a real team is picked, since that's the only state with
             one data-tsa-block per TSA in the DOM to show/hide — ALL/
             TikTok render pooled summary cards instead, no per-TSA blocks
             to filter. Pure client-side show/hide (the filter listener
             near this file's bottom) — no page reload, no new backend
             query, since every TSA on the selected team is already
             server-rendered. Not a submit button, so it doesn't need to
             be inside <form> for any functional reason — kept there
             anyway purely so flexbox lays it out in the same row as the
             controls it visually belongs with. --}}
        @if($selectedTeam !== 'all' && $selectedTeam !== 'tiktok')
        <input type="text" id="eiTsaNameFilter" placeholder="Filter by TSA name…" autocomplete="off"
               class="w-56 text-xs font-mono px-3 py-1.5 rounded-lg border border-slate-200 dark:border-slate-700 bg-white dark:bg-slate-900 text-ink dark:text-slate-100 placeholder:text-slate-400 dark:placeholder:text-slate-500 focus:ring-2 focus:ring-primary/40 focus:border-primary outline-none">
        @endif
    </form>
    <div class="flex items-center gap-3">
        <span class="hidden sm:inline-flex items-center gap-1.5 text-[11px] font-mono text-ink-muted dark:text-slate-400">
            <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M8 9l-5 5m0 0l5 5m-5-5h18m-5-9l5 5m0 0l-5 5"/></svg>
            Drag/scroll right to see more products
        </span>
        <span id="eiSaveStatus" class="text-xs font-mono text-slate-400 dark:text-slate-500 min-h-[1.25rem]"></span>
    </div>
</div>

{{-- Range summary row + per-team summary rows — extracted into
     _summary-section so the same markup can be re-rendered as an AJAX
     fragment (ExpectedIncomeController::summary()) and live-refreshed
     after every autosave, no full page reload needed. See that partial's
     own doc comment. --}}
@include('data.expected-income._summary-section')

{{-- Daily rows — one row of cards PER calendar day, stacking downward
     (explicit request, 2026-09-26: "in the top there's expected sales and
     after that it is dates going down"), each independently editable and
     independently drag-scrollable. Same overall-card + product-cards
     pattern as the summary row above, just for that one day's own figures.
     No heading above each day's own row, and no date inside the overall
     card's own title either — explicit follow-up, 2026-09-30: "there will
     be no dates in all" then, after the standalone heading removal alone
     didn't cover it, "the only will be gone is this in the down part"
     pointing at "TELESALES — SEPTEMBER 30, 2026" still showing inside
     that card's own title. The overall card is now titled plain
     "TELESALES", same as every other card on the page never showing a
     date in its own title.

     Team filter (explicit request, 2026-09-30, confirmed live via
     screenshot exactly which part of the page this touches: "the top is
     still like that [unaffected] ... in the down the yellow is only TSA
     NAMES"): selectedTeam === 'all' keeps the ORIGINAL single "TELESALES"
     overall card + every product's own card, tsa_id NULL, unchanged from
     before this feature; a real team swaps that one block for ONE block
     PER REAL TSA on that team, her own name where the overall card's
     title used to be, each followed by her own product cards — for EVERY
     date, same per-day stacking either way. --}}
{{-- TIKTOK TEAM (explicit request, 2026-10-06: "separate the tiktok team
     so it will be like next to the team opening is TIKTOK TEAM") — one
     block per TikTok-flagged TSA, same scroller/header shape as a real
     team's own per-TSA block above, but showing ONLY her 2 TikTok cards
     (explicit scope: not her normal overview/product cards — those
     already show under her real team's own filter) — reads $tiktokTsaRows
     directly (already computed for the TOTAL section regardless of
     filter) rather than needing its own builder/dailyRows shape. --}}
@if($selectedTeam === 'tiktok')
@if($dates->count() > 1)
    @foreach($tiktokTsaRows as $tsaId => $tsaRow)
    @php
        $tsa = $tsaRow['tsa'];
    @endphp
    <div class="flex items-center justify-end mb-2">
        @include('partials.table-actions', ['target' => 'eiTiktokScroller-range-' . $tsa->id, 'name' => \Illuminate\Support\Str::slug($tsa->display_name) . '-tiktok-expected-income', 'title' => $tsa->display_name . ' (TikTok)', 'subtitle' => $snapshotDateLabel, 'pngOnly' => true])
    </div>
    <div class="overflow-x-auto ei-scroller -mx-4 md:-mx-8 px-4 md:px-8 pb-2 mb-8 ei-day-scroller" id="eiTiktokScroller-range-{{ $tsa->id }}">
        <div class="flex items-start gap-5 w-max">
            {{-- Her own "[TSA NAME]" overview card (explicit follow-up,
                 2026-10-06: "in the tiktok it should be have tsa card too")
                 — same visual anchor every real team's own per-TSA block
                 already has, read-only rollup of HER OWN 2 TikTok cards
                 only (not her real product cards — out of scope here). --}}
            <div class="ei-card bg-white dark:bg-slate-900 border border-line dark:border-slate-700 rounded-2xl shadow-panel overflow-hidden w-[26rem] shrink-0" data-out-scope="1">
                <div class="px-5 py-4" style="background:#fde047;">
                    <span class="font-mono font-bold text-sm uppercase tracking-wide text-ink truncate block">{{ $tsa->display_name }}</span>
                </div>
                @include('data.expected-income._card-body', ['d' => $tsaRow['overallTotal'], 'sellingRows' => $sellingRows, 'operatingRows' => $operatingRows, 'customRowKeys' => $customRowKeys, 'fmtMoney' => $fmtMoney, 'fmtPct' => $fmtPct, 'editable' => false])
            </div>
            @foreach($tsaRow['cards'] as $card)
            @include('data.expected-income._tiktok-card', ['card' => $card, 'tsa' => $tsa, 'fmtMoney' => $fmtMoney, 'fmtPct' => $fmtPct, 'editable' => false])
            @endforeach
        </div>
    </div>
    @endforeach
@else
    @foreach($dates as $date)
    @php
        $dateStr = $date->toDateString();
    @endphp
    @foreach($tiktokTsaRows as $tsaId => $tsaRow)
    @php
        $tsa = $tsaRow['tsa'];
    @endphp
    <div class="flex items-center justify-end mb-2">
        @include('partials.table-actions', ['target' => 'eiTiktokScroller-' . $tsa->id . '-' . $dateStr, 'name' => \Illuminate\Support\Str::slug($tsa->display_name) . '-tiktok-expected-income-' . $dateStr, 'title' => $tsa->display_name . ' (TikTok)', 'subtitle' => \Illuminate\Support\Carbon::parse($dateStr)->format('F j, Y'), 'pngOnly' => true])
    </div>
    <div class="overflow-x-auto ei-scroller -mx-4 md:-mx-8 px-4 md:px-8 pb-2 mb-8 ei-day-scroller" data-date="{{ $dateStr }}" id="eiTiktokScroller-{{ $tsa->id }}-{{ $dateStr }}">
        <div class="flex items-start gap-5 w-max">
            {{-- Her own "[TSA NAME]" overview card — same role as above,
                 just read-only even on a 1-day selection (same "overview
                 never editable, only the real cards are" rule every other
                 team's own overview card already follows). --}}
            <div class="ei-card bg-white dark:bg-slate-900 border border-line dark:border-slate-700 rounded-2xl shadow-panel overflow-hidden w-[26rem] shrink-0" data-out-scope="1">
                <div class="px-5 py-4" style="background:#fde047;">
                    <span class="font-mono font-bold text-sm uppercase tracking-wide text-ink truncate block">{{ $tsa->display_name }}</span>
                </div>
                @include('data.expected-income._card-body', ['d' => $tsaRow['overallTotal'], 'sellingRows' => $sellingRows, 'operatingRows' => $operatingRows, 'customRowKeys' => $customRowKeys, 'fmtMoney' => $fmtMoney, 'fmtPct' => $fmtPct, 'editable' => false])
            </div>
            @foreach($tsaRow['cards'] as $card)
            @include('data.expected-income._tiktok-card', ['card' => $card, 'tsa' => $tsa, 'dateStr' => $dateStr, 'fmtMoney' => $fmtMoney, 'fmtPct' => $fmtPct, 'editable' => true])
            @endforeach
        </div>
    </div>
    @endforeach
    @endforeach
@endif
@endif

{{-- ALL-view daily rows removed (explicit request, 2026-09-30: ALL should
     only show Telesales Expected Performance + TEAM 1 + TEAM 2 summary
     rows above, no per-date card rows) — daily per-date cards now only
     render once a real team is picked. --}}
@if($selectedTeam !== 'all' && $selectedTeam !== 'tiktok')
@if($isRangeSummed)
    {{-- Multi-day range: ONE read-only block per TSA summing the whole
         selected range, instead of a stack repeated per calendar day
         (explicit decision, 2026-10-02: "it should be adding oct 1 and 2
         data right?" — picking a >1-day range previously still rendered
         one full per-day stack per date, each showing only that single
         day's own numbers, which looked like the range filter wasn't
         doing anything on a mostly-empty day). Read-only because every
         input below normally autosaves to one specific (product, tsa,
         DATE) row — summed across days there's no single date left to
         save an edit into, same reason the top range-summary row has
         always been read-only. Pick a 1-day range for editable inputs. --}}
    @foreach($tsaRows as $tsaRow)
    @php
        $tsa = $tsaRow['tsa'];
    @endphp
    {{-- data-tsa-block + data-tsa-name: the TSA name filter below (explicit
         request, 2026-10-08: "when they filter their name, the only card
         will be display is their tsa card and product") shows/hides this
         whole wrapper as one unit — purely a client-side display toggle
         over what the team filter already rendered, no new backend query,
         since every TSA on the selected team is already in the DOM. --}}
    <div data-tsa-block data-tsa-name="{{ strtolower($tsa->display_name) }}">
    <div class="flex items-center justify-end mb-2">
        @include('partials.table-actions', ['target' => 'eiTsaCard-range-' . $tsa->id, 'name' => \Illuminate\Support\Str::slug($tsa->display_name) . '-expected-income', 'title' => $tsa->display_name, 'subtitle' => $snapshotDateLabel, 'pngOnly' => true])
    </div>
    <div class="overflow-x-auto ei-scroller -mx-4 md:-mx-8 px-4 md:px-8 pb-2 mb-8 ei-day-scroller" id="eiTsaScroller-range-{{ $tsa->id }}">
        <div class="flex items-start gap-5 w-max">
            {{-- id'd so the snapshot button above (target=eiTsaCard-range-N)
                 captures ONLY her own rollup card, not the whole scroller's
                 product cards too (explicit request, 2026-10-08: "the
                 snapshot is only tsa card only will be saved"). --}}
            <div class="ei-card bg-white dark:bg-slate-900 border border-line dark:border-slate-700 rounded-2xl shadow-panel overflow-hidden w-[26rem] shrink-0" id="eiTsaCard-range-{{ $tsa->id }}" data-out-scope="1">
                <div class="px-5 py-4" style="background:#fde047;">
                    <span class="font-mono font-bold text-sm uppercase tracking-wide text-ink truncate block">{{ $tsa->display_name }}</span>
                </div>
                @include('data.expected-income._card-body', ['d' => $tsaRow['rangeOverallTotal'], 'sellingRows' => $sellingRows, 'operatingRows' => $operatingRows, 'customRowKeys' => $customRowKeys, 'fmtMoney' => $fmtMoney, 'fmtPct' => $fmtPct, 'editable' => false])
            </div>

            @foreach($tsaRow['rangeRows'] as $row)
            @php
                $d = $row['derived'];
                $product = $row['products']->first();
            @endphp
            <div class="ei-card bg-white dark:bg-slate-900 border border-line dark:border-slate-700 rounded-2xl shadow-panel overflow-hidden w-[26rem] shrink-0"
                 data-product-id="{{ $product->id }}">
                <div class="px-5 py-4" style="background:#d9ead3;">
                    <span class="font-mono font-bold text-sm uppercase tracking-wide text-ink truncate block">{{ $row['label'] }}</span>
                </div>
                @include('data.expected-income._card-body', ['d' => $d, 'sellingRows' => $sellingRows, 'operatingRows' => $operatingRows, 'customRowKeys' => $customRowKeys, 'fmtMoney' => $fmtMoney, 'fmtPct' => $fmtPct, 'editable' => false])
            </div>
            @endforeach
        </div>
    </div>
    </div>
    @endforeach
@else
@foreach($dates as $date)
@php
    $dateStr = $date->toDateString();
@endphp

    @foreach($tsaRows as $tsaRow)
    @php
        $tsa = $tsaRow['tsa'];
        $tsaDailyRows = $tsaRow['dailyRows'][$dateStr];
        $tsaDailyByKey = $tsaRow['dailyByKey'];
        $tsaDayOverallTotal = $tsaRow['dailyOverallTotals'][$dateStr];
    @endphp
    <div data-tsa-block data-tsa-name="{{ strtolower($tsa->display_name) }}">
    <div class="flex items-center justify-end mb-2">
        @include('partials.table-actions', ['target' => 'eiTsaCard-' . $tsa->id . '-' . $dateStr, 'name' => \Illuminate\Support\Str::slug($tsa->display_name) . '-expected-income-' . $dateStr, 'title' => $tsa->display_name, 'subtitle' => \Illuminate\Support\Carbon::parse($dateStr)->format('F j, Y'), 'pngOnly' => true])
    </div>
    <div class="overflow-x-auto ei-scroller -mx-4 md:-mx-8 px-4 md:px-8 pb-2 mb-8 ei-day-scroller" data-date="{{ $dateStr }}" id="eiTsaScroller-{{ $tsa->id }}-{{ $dateStr }}">
        <div class="flex items-start gap-5 w-max">
            {{-- Her own name where the plain "TELESALES" card title used
                 to be — a read-only rollup of HER OWN product cards for this day
                 (confirmed live, 2026-09-30: "the yellow is stil has this,
                 it is over all of the individual tsa ... but it is not
                 editable"), same role and full P&L body as the overall
                 card in the ALL view, just scoped to her own products.
                 id'd so the snapshot button above (target=eiTsaCard-N-date)
                 captures ONLY this card, not the whole scroller's product
                 cards too (explicit request, 2026-10-08: "the snapshot is
                 only tsa card only will be saved"). --}}
            <div class="ei-card bg-white dark:bg-slate-900 border border-line dark:border-slate-700 rounded-2xl shadow-panel overflow-hidden w-[26rem] shrink-0" id="eiTsaCard-{{ $tsa->id }}-{{ $dateStr }}" data-out-scope="1">
                <div class="px-5 py-4" style="background:#fde047;">
                    <span class="font-mono font-bold text-sm uppercase tracking-wide text-ink truncate block">{{ $tsa->display_name }}</span>
                </div>
                @include('data.expected-income._card-body', ['d' => $tsaDayOverallTotal, 'sellingRows' => $sellingRows, 'operatingRows' => $operatingRows, 'customRowKeys' => $customRowKeys, 'fmtMoney' => $fmtMoney, 'fmtPct' => $fmtPct, 'editable' => false])
            </div>

            @foreach($tsaDailyRows as $row)
            @php
                $entry = $tsaDailyByKey->get($row['products']->first()->id . ':' . $dateStr);
            @endphp
            @include('data.expected-income._product-card', ['row' => $row, 'tsa' => $tsa, 'dateStr' => $dateStr, 'entry' => $entry, 'sellingRows' => $sellingRows, 'operatingRows' => $operatingRows, 'customRowKeys' => $customRowKeys, 'fmtMoney' => $fmtMoney, 'fmtPct' => $fmtPct])
            @endforeach
        </div>
    </div>
    </div>
    @endforeach
@endforeach
@endif
@endif

@push('scripts')
<script>
(function () {
    const csrfToken = document.querySelector('meta[name="csrf-token"]')?.content || '';
    const globalStatus = document.getElementById('eiSaveStatus');
    const saveTimers = new WeakMap();

    const SELLING_KEYS = @json(array_keys($sellingRows));
    const OPERATING_KEYS = @json(array_keys($operatingRows));
    const COD_FEE_RATE_OF_DELIVERED = 0.0224, FULFILLMENT_FEE_PER_ORDER = 25.0;
    const PROJECTED_RETURNS_RATE = {{ \App\Support\ExpectedIncomeCalculator::PROJECTED_RETURNS_RATE }};

    function fmtMoney(n) { return Number(n).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 }); }
    function fmtInt(n) { return Number(n).toLocaleString('en-US', { maximumFractionDigits: 0 }); }
    function fmtPct(n) { return (Number(n) * 100).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 }) + '%'; }
    function parseMoney(str) { return Number(String(str).replace(/,/g, '')) || 0; }

    function liveFormatMoney(input) {
        const raw = input.value;
        const caretFromEnd = raw.length - (input.selectionStart ?? raw.length);
        const cleaned = raw.replace(/[^0-9.]/g, '');
        const firstDot = cleaned.indexOf('.');
        const intPart = firstDot === -1 ? cleaned : cleaned.slice(0, firstDot);
        const fracPart = firstDot === -1 ? '' : cleaned.slice(firstDot);
        const grouped = intPart.replace(/\B(?=(\d{3})+(?!\d))/g, ',');
        const formatted = grouped + fracPart;
        if (formatted === raw) return;
        input.value = formatted;
        const pos = Math.max(0, formatted.length - caretFromEnd);
        input.setSelectionRange(pos, pos);
    }

    function flashStatus(text, isError) {
        if (!globalStatus) return;
        globalStatus.textContent = text;
        globalStatus.classList.toggle('text-red-500', !!isError);
        globalStatus.classList.toggle('text-slate-400', !isError);
        clearTimeout(flashStatus._t);
        flashStatus._t = setTimeout(() => { if (globalStatus.textContent === text) globalStatus.textContent = ''; }, 2000);
    }

    // Same formula chain as ExpectedIncomeCalculator::derive() — mirrors it
    // client-side so a live edit's card repaint matches what a fresh page
    // load would show, same convention as pj.js's own applyComputed().
    function derive(row) {
        const leads = Number(row.number_of_leads) || 0;
        const orders = Number(row.number_of_orders) || 0;
        const aov = Number(row.average_order_value) || 0;
        const taxAllocation = Number(row.tax_allocation) || 0;
        const productCost = Number(row.product_cost) || 0;

        const conversionRate = leads > 0 ? orders / leads : 0;
        // Gross Sales/Cancelled are plain manual inputs (explicit request,
        // 2026-09-28). Projected Returns/Projected Delivered are NOT —
        // corrected 2026-10-01 ("the only auto is Projected Returns /
        // Projected Delivered") to be DERIVED from Gross Sales again, same
        // as ExpectedIncomeCalculator::derive()'s own PHP side: Returns =
        // Gross Sales × 25%, Delivered = Gross Sales − Cancelled − Returns.
        // Reading row.returns/row.delivered as raw inputs here (the bug
        // this comment replaces) silently zeroed both out on every
        // refreshDayOverall() repaint, since neither field has a
        // data-field input to read from any more (_card-body.blade.php
        // always renders them as read-only spans) — confirmed live,
        // 2026-10-08: a TSA's own rollup card went stale/wrong after any
        // product-card edit until a full page reload re-fetched the
        // server's own correct figures.
        const grossSales = Number(row.gross_sales) || 0;
        const cancelled = Number(row.cancelled) || 0;
        const returns = grossSales * PROJECTED_RETURNS_RATE;
        const delivered = grossSales - cancelled - returns;
        const grossProfit = grossSales - cancelled - returns - taxAllocation - productCost;

        // cod_fee/fulfillment_fee are both already inside SELLING_KEYS
        // (SELLING_COST_ROWS includes them server-side too — see
        // ExpectedIncomeCalculator::derive()'s own ->put() comment) but
        // are computed formulas, never a raw row[key] value — summing
        // totalSellingCosts DURING the SELLING_KEYS loop below (the bug
        // this comment replaces) added each once from its stale row[key]
        // reading, then added the correct formula value AGAIN right
        // after, double-counting both into every TSA rollup card's Total
        // Selling Costs (and everything downstream of it) on every
        // refreshDayOverall() repaint — confirmed live, 2026-10-08.
        // Mirrors the PHP side's own ->put()-then-->sum() order exactly:
        // build the complete sellingLines object first (raw values, then
        // the 2 formulas OVERWRITING their own keys), sum it only once
        // after every key's final value is settled.
        const sellingLines = {};
        SELLING_KEYS.forEach((key) => { sellingLines[key] = Number(row[key]) || 0; });
        sellingLines.cod_fee = delivered * COD_FEE_RATE_OF_DELIVERED;
        sellingLines.fulfillment_fee = orders * FULFILLMENT_FEE_PER_ORDER;
        const totalSellingCosts = Object.values(sellingLines).reduce((sum, v) => sum + v, 0);

        const operatingLines = {};
        let totalOperatingCosts = 0;
        OPERATING_KEYS.forEach((key) => { operatingLines[key] = Number(row[key]) || 0; totalOperatingCosts += operatingLines[key]; });

        const incomeBeforeOpex = grossProfit - totalSellingCosts;
        const netIncome = incomeBeforeOpex - totalOperatingCosts;

        return {
            roas: Number(row.roas) || 0, standard_cost_per_message: Number(row.standard_cost_per_message) || 0, actual_cost_per_lead: Number(row.actual_cost_per_lead) || 0,
            number_of_leads: leads, conversion_rate: conversionRate, number_of_orders: orders, average_order_value: aov,
            gross_sales: grossSales, gross_sales_pct: grossSales > 0 ? 1 : 0,
            cancelled, cancelled_pct: grossSales > 0 ? cancelled / grossSales : 0,
            returns, returns_pct: grossSales > 0 ? returns / grossSales : 0,
            delivered, delivered_pct: grossSales > 0 ? delivered / grossSales : 0,
            tax_allocation: taxAllocation, tax_allocation_pct: grossSales > 0 ? taxAllocation / grossSales : 0,
            product_cost: productCost, product_cost_pct: grossSales > 0 ? productCost / grossSales : 0,
            gross_profit: grossProfit, gross_profit_pct: grossSales > 0 ? grossProfit / grossSales : 0,
            selling_lines: sellingLines, total_selling_costs: totalSellingCosts,
            total_selling_costs_pct: grossSales > 0 ? totalSellingCosts / grossSales : 0,
            income_before_opex: incomeBeforeOpex, income_before_opex_pct: grossSales > 0 ? incomeBeforeOpex / grossSales : 0,
            operating_lines: operatingLines, total_operating_costs: totalOperatingCosts,
            total_operating_costs_pct: grossSales > 0 ? totalOperatingCosts / grossSales : 0,
            net_income: netIncome, net_income_pct: grossSales > 0 ? netIncome / grossSales : 0,
        };
    }

    function applyDerived(card, derived) {
        card.querySelectorAll('[data-out]').forEach((el) => {
            const key = el.dataset.out;
            let value;
            if (key in derived) value = derived[key];
            else if (derived.selling_lines && key in derived.selling_lines) value = derived.selling_lines[key];
            else if (derived.operating_lines && key in derived.operating_lines) value = derived.operating_lines[key];
            else return;
            const isPct = key === 'conversion_rate' || key.endsWith('_pct');
            const isInt = key === 'number_of_leads' || key === 'number_of_orders';
            el.textContent = isPct ? fmtPct(value) : (isInt ? fmtInt(value) : fmtMoney(value));
            const isLoss = (key === 'gross_profit' || key === 'net_income' || key === 'income_before_opex') && value < 0;
            el.classList.toggle('text-red-600', isLoss);
            el.classList.toggle('dark:text-red-400', isLoss);
            // Net Income (only — Gross Profit stays plain ink when positive,
            // same as its own static server-render) also turns green when
            // positive, not just red when negative — explicit request,
            // 2026-09-28: "the net income it should be green if positive
            // ... and if negative ... red." Toggled independently of isLoss
            // above since they're mutually exclusive states of the SAME
            // value, not two different flags.
            if (key === 'net_income') {
                el.classList.toggle('text-green-600', value >= 0);
                el.classList.toggle('dark:text-green-400', value >= 0);
            }
        });

        card.querySelectorAll('[data-out-pct]').forEach((el) => {
            const key = el.dataset.outPct;
            const value = (derived.selling_lines || {})[key] ?? (derived.operating_lines || {})[key];
            if (value === undefined) return;
            el.textContent = derived.gross_sales > 0 ? fmtPct(value / derived.gross_sales) : '0.00%';
        });
    }

    // Recomputes and repaints ONE day's own overall rollup card from every
    // product card's own currently-saved inputs IN THAT SAME DAY'S ROW —
    // scoped to $scroller so editing one day never touches another day's
    // rollup card, same convention as dsppr.blade.php's own refreshDayTotal().
    // Also sums `[data-tiktok-card]` cards (2026-10-06 fix — a TikTok
    // scroller has no `[data-product-id]` cards at all, only her 2
    // TikTok cards, so this used to find nothing and zero out her own
    // TikTok overview card after any field autosaved; a scroller only
    // ever contains one kind or the other, never both, so summing both
    // selectors together is safe).
    function refreshDayOverall(scroller) {
        const overallCard = scroller.querySelector('.ei-card[data-out-scope="1"]');
        if (!overallCard) return;

        const productCards = scroller.querySelectorAll('.ei-card[data-product-id], .ei-card[data-tiktok-card]');
        const rawRows = [];
        let roasSum = 0, costPerLeadSum = 0;

        productCards.forEach((card) => {
            const raw = {};
            // 'returns'/'delivered' deliberately NOT read here — see
            // derive()'s own doc comment above: both are always derived
            // from gross_sales/cancelled, never a raw data-field input.
            ['roas', 'actual_cost_per_lead', 'number_of_orders', 'average_order_value', 'gross_sales', 'cancelled', 'product_cost']
                .forEach((key) => {
                    const el = card.querySelector(`[data-field="${key}"]`);
                    raw[key] = el ? (el.dataset.money === '1' ? parseMoney(el.value) : Number(el.value) || 0) : 0;
                });
            // Number of Leads is no longer a raw data-field input anywhere
            // on this page (automated 2026-10-09 — see
            // ExpectedIncomeController::leadCountsByProductAndDate()'s own
            // doc comment) — always read its server-rendered read-only
            // span instead, same "no input, fall back to the span" pattern
            // Tax Allocation already established right below.
            (() => {
                const out = card.querySelector('[data-out="number_of_leads"]');
                raw.number_of_leads = out ? (Number(out.textContent.replace(/,/g, '')) || 0) : 0;
            })();
            // Tax Allocation on a TSA-scoped card is LOCKED (explicit
            // request, 2026-10-02: "the tax allocation in tsa cards is
            // should be not editable") — no data-field input at all there,
            // same "read the server-rendered read-only span instead of
            // silently treating it as 0" fallback the locked Operating
            // Costs rows below already use. Root-caused live, 2026-10-02:
            // this card's own overview rollup kept resetting Tax Allocation
            // to 0.00 after ANY field on the page was edited, since this
            // used to ALWAYS read data-field (never present once locked)
            // with no fallback, unlike every other locked row.
            (() => {
                const el = card.querySelector('[data-field="tax_allocation"]');
                if (el) { raw.tax_allocation = parseMoney(el.value); return; }
                const out = card.querySelector('[data-out="tax_allocation"]');
                raw.tax_allocation = out ? parseMoney(out.textContent) : 0;
            })();
            SELLING_KEYS.concat(OPERATING_KEYS).forEach((key) => {
                const el = card.querySelector(`[data-field="${key}"]`);
                if (el) { raw[key] = parseMoney(el.value); return; }
                // Every BUILT-IN Operating Costs row on a TSA-scoped card
                // has no input at all (locked to Cost Breakdown's own
                // figures — explicit request, 2026-09-30) — read its
                // server-rendered read-only span instead of silently
                // treating it as 0.
                const out = card.querySelector(`[data-out="${key}"]`);
                raw[key] = out ? parseMoney(out.textContent) : 0;
            });
            rawRows.push(raw);
            const d = derive(raw);
            roasSum += d.roas;
            costPerLeadSum += d.actual_cost_per_lead;
        });

        const totals = { number_of_leads: 0, number_of_orders: 0, gross_sales: 0, cancelled: 0, tax_allocation: 0, product_cost: 0 };
        SELLING_KEYS.concat(OPERATING_KEYS).forEach((key) => { totals[key] = 0; });
        let aovSum = 0;
        rawRows.forEach((raw) => {
            totals.number_of_leads += raw.number_of_leads;
            totals.number_of_orders += raw.number_of_orders;
            totals.gross_sales += raw.gross_sales;
            totals.cancelled += raw.cancelled;
            totals.tax_allocation += raw.tax_allocation;
            totals.product_cost += raw.product_cost;
            SELLING_KEYS.concat(OPERATING_KEYS).forEach((key) => { totals[key] += raw[key]; });
            aovSum += raw.average_order_value;
        });

        const summed = derive(totals);
        const rowCount = rawRows.length;
        if (rowCount > 0) {
            summed.roas = roasSum / rowCount;
            summed.actual_cost_per_lead = costPerLeadSum / rowCount;
            summed.average_order_value = aovSum / rowCount;
        }

        applyDerived(overallCard, summed);
    }

    function saveField(input) {
        clearTimeout(saveTimers.get(input));
        saveTimers.delete(input);

        const card = input.closest('.ei-card');
        const status = card?.querySelector('.ei-card-status');
        if (!card) return;
        const scroller = card.closest('.ei-day-scroller');

        const field = input.dataset.field;
        const value = input.dataset.money === '1' ? parseMoney(input.value) : (Number(input.value) || 0);

        if (status) status.textContent = 'Saving…';
        flashStatus('Saving…', false);

        const body = new URLSearchParams();
        body.set('_method', 'PATCH');

        // A row added via the + icon on Projections (data-custom="1" — see
        // _card-body.blade.php's own doc comment) has no fixed column on
        // ExpectedIncomeEntry, so it PATCHes a separate key/value endpoint
        // instead of update()'s named-field one, same "generic key/value,
        // not a hardcoded field name" convention as Projections' own
        // updateRates() endpoint.
        const isCustom = input.dataset.custom === '1';
        const action = isCustom ? card.dataset.customAction : card.dataset.action;
        if (isCustom) {
            body.set('key', field);
            body.set('value', value);
        } else {
            body.set(field, value);
        }

        fetch(action, {
            method: 'POST',
            headers: {
                Accept: 'application/json',
                'Content-Type': 'application/x-www-form-urlencoded',
                'X-CSRF-TOKEN': csrfToken,
            },
            body: body.toString(),
        })
            .then((res) => (res.ok ? res.json() : Promise.reject(res)))
            .then((data) => {
                if (status) { status.textContent = 'Saved'; setTimeout(() => { if (status.textContent === 'Saved') status.textContent = ''; }, 1500); }
                flashStatus('Saved', false);
                if (input.dataset.money === '1' && document.activeElement !== input) {
                    input.value = fmtMoney(value);
                }
                if (data?.derived) applyDerived(card, data.derived);
                if (scroller) refreshDayOverall(scroller);
                scheduleSummaryRefresh();
            })
            .catch(() => {
                if (status) status.textContent = 'Failed';
                flashStatus('Could not save — try again.', true);
                window.showToast?.('Could not save — try again.', 'error');
            });
    }

    // Refreshes "Telesales Expected Performance" + the per-team summary
    // rows from the server after every autosave (explicit request,
    // 2026-09-30: "why should i fully reload the page to reflect that" —
    // that row pools every TSA/team's own entries, so no in-scroller
    // re-sum like refreshDayOverall() above can keep it live). Debounced
    // and coalesced (one in-flight fetch at a time, one more queued behind
    // it) so a burst of field saves doesn't fire a request per keystroke.
    let summaryRefreshTimer = null, summaryRefreshInFlight = false, summaryRefreshQueued = false;
    function scheduleSummaryRefresh() {
        clearTimeout(summaryRefreshTimer);
        summaryRefreshTimer = setTimeout(runSummaryRefresh, 400);
    }
    function runSummaryRefresh() {
        if (summaryRefreshInFlight) { summaryRefreshQueued = true; return; }
        summaryRefreshInFlight = true;
        fetch('{{ route('data.expected-income.summary') }}?' + new URLSearchParams(window.location.search), {
            headers: { Accept: 'text/html' },
        })
            .then((res) => (res.ok ? res.text() : Promise.reject(res)))
            .then((html) => {
                const wrapper = document.createElement('div');
                wrapper.innerHTML = html.trim();
                const fresh = wrapper.firstElementChild;
                const current = document.getElementById('eiSummarySection');
                if (fresh && current) {
                    current.replaceWith(fresh);
                    wireScroller(fresh.querySelectorAll('.ei-scroller'));
                }
            })
            .catch(() => { /* best-effort refresh — the daily card the user is typing into already saved fine */ })
            .finally(() => {
                summaryRefreshInFlight = false;
                if (summaryRefreshQueued) { summaryRefreshQueued = false; runSummaryRefresh(); }
            });
    }

    // Lock toggle (explicit request, 2026-10-02: "can you create lock icon
    // too in this, like in the projections", then "has smooth transition
    // too like in the projection") — one lock PER PRODUCT CARD. Locking
    // swaps every one of this card's own editable <input>s for the same
    // input, now `disabled` — a structural change applyDerived() can't
    // express in place, so the server renders the card's own fresh
    // _product-card partial (update()'s own 'cardHtml', only present on an
    // is_locked save) and this cross-fades the OLD card out, swaps the
    // DOM, then fades the NEW one in — same convention as pj.js's own
    // lock-toggle handler, no page reload at all.
    function wireLockToggles(scroller) {
        scroller.addEventListener('click', (e) => {
            const lockBtn = e.target.closest('[data-ei-lock-toggle]');
            if (!lockBtn) return;
            const card = lockBtn.closest('.ei-card');
            if (!card) return;
            const nowLocked = lockBtn.dataset.locked !== '1';
            const body = new URLSearchParams();
            body.set('is_locked', nowLocked ? '1' : '0');
            body.set('_method', 'PATCH');
            lockBtn.disabled = true;
            card.style.transition = 'opacity 180ms ease';
            card.style.opacity = '0.25';
            fetch(card.dataset.action, {
                method: 'POST',
                headers: {
                    Accept: 'application/json',
                    'Content-Type': 'application/x-www-form-urlencoded',
                    'X-CSRF-TOKEN': csrfToken,
                },
                body: body.toString(),
            })
                .then((res) => (res.ok ? res.json() : Promise.reject(res)))
                .then((data) => {
                    if (!data?.cardHtml) { window.location.reload(); return; }
                    const wrapper = document.createElement('div');
                    wrapper.innerHTML = data.cardHtml.trim();
                    const freshCard = wrapper.firstElementChild;
                    freshCard.style.transition = 'opacity 180ms ease';
                    freshCard.style.opacity = '0';
                    card.replaceWith(freshCard);
                    requestAnimationFrame(() => { freshCard.style.opacity = '1'; });
                })
                .catch(() => {
                    lockBtn.disabled = false;
                    card.style.opacity = '1';
                    window.showToast?.(`Could not ${nowLocked ? 'lock' : 'unlock'} this card — try again.`, 'error');
                });
        });
    }

    document.querySelectorAll('.ei-day-scroller').forEach((scroller) => {
        wireLockToggles(scroller);

        scroller.addEventListener('input', (e) => {
            const input = e.target.closest('.ei-field');
            if (!input) return;
            if (input.dataset.money === '1') liveFormatMoney(input);
            clearTimeout(saveTimers.get(input));
            saveTimers.set(input, setTimeout(() => saveField(input), 600));
        });

        scroller.addEventListener('blur', (e) => {
            const input = e.target.closest('.ei-field');
            if (input) saveField(input);
        }, true);

        // Select-all-on-focus (explicit request, 2026-10-09: typing "5" into
        // a field showing "0" was producing "50" — the leading 0 is a real
        // value, not a placeholder, and with nothing selected a click just
        // drops the caret next to it instead of replacing it). Pre-selected
        // text means the first keystroke always replaces the whole value.
        scroller.addEventListener('focus', (e) => {
            const input = e.target.closest('.ei-field');
            if (input) input.select();
        }, true);

        scroller.addEventListener('keydown', (e) => {
            if (e.key !== 'Enter') return;
            const input = e.target.closest('.ei-field');
            if (input) { e.preventDefault(); input.blur(); }
        });
    });

    // Click-and-drag horizontal scroll (same convention as dsppr.blade.php's
    // own scroller), wired to every row's scroller independently. Factored
    // into a function so runSummaryRefresh() above can re-wire it onto the
    // fresh scrollers it swaps in after replaceWith() — the old elements'
    // listeners are destroyed along with them.
    function wireScroller(scrollers) {
        scrollers.forEach((scroller) => {
            let isDragging = false, dragStartX = 0, dragStartScroll = 0;

            scroller.addEventListener('mousedown', (e) => {
                // .ei-row excluded too (explicit request, 2026-10-02: row
                // drag-reorder) — a mousedown starting on a draggable row
                // must trigger the NATIVE HTML5 drag (dragstart below),
                // never this scroller's own manual click-drag-to-scroll,
                // or the two would fight over the same mouse gesture.
                if (e.target.closest('input') || e.target.closest('.ei-row')) return;
                isDragging = true;
                dragStartX = e.pageX;
                dragStartScroll = scroller.scrollLeft;
                scroller.classList.add('cursor-grabbing');
            });
            window.addEventListener('mousemove', (e) => {
                if (!isDragging) return;
                e.preventDefault();
                scroller.scrollLeft = dragStartScroll - (e.pageX - dragStartX);
            });
            window.addEventListener('mouseup', () => {
                isDragging = false;
                scroller.classList.remove('cursor-grabbing');
            });
        });
    }
    wireScroller(document.querySelectorAll('.ei-scroller'));

    // TSA name filter (explicit request, 2026-10-08) — shows/hides each
    // [data-tsa-block] wrapper by a simple substring match against her
    // name, lowercased on both sides ([data-tsa-name] is already
    // lowercased server-side). An empty query shows every block again.
    // No debounce needed — toggling a `hidden` class on a few dozen DOM
    // nodes per keystroke is cheap, nothing like the 600ms-debounced
    // autosave fetches elsewhere in this file.
    const tsaNameFilter = document.getElementById('eiTsaNameFilter');
    if (tsaNameFilter) {
        tsaNameFilter.addEventListener('input', () => {
            const query = tsaNameFilter.value.trim().toLowerCase();
            document.querySelectorAll('[data-tsa-block]').forEach((block) => {
                block.classList.toggle('hidden', query !== '' && !block.dataset.tsaName.includes(query));
            });
        });
        // Lives inside the same <form> as the team buttons (for flexbox
        // layout only — see this input's own doc comment above), which
        // would otherwise GET-submit the whole page (losing the filter,
        // reloading everything) on Enter the way the real date-range
        // field next to it is supposed to. This field is purely a client-
        // side display toggle, never meant to submit anything.
        tsaNameFilter.addEventListener('keydown', (e) => { if (e.key === 'Enter') e.preventDefault(); });
    }
})();
</script>
@endpush

@endsection
