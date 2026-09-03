@extends('layouts.app')
@section('title', 'Analytics')
@section('subtitle', 'Trends · Excess Leads · Product & TSA Performance')

@section('content')

@php
    // Team colors — same cyan/emerald pair already used for SH Naturals / Eyecare
    // everywhere else this distinction shows up (was the Group Sales donut, now the
    // Dashboard's Team Comparison cards too). Reusing them here keeps "which color
    // means which team" consistent across the whole app instead of redefining it
    // per chart.
    $teamColors = ['#0891B2', '#059669'];
    $hasData    = collect($dailyLabels)->isNotEmpty() && (
        collect($excessSeries)->sum() + collect($answeredSeries)->sum() + collect($unansweredSeries)->sum() > 0
        || collect($productRows)->sum('total') > 0
    );
@endphp

@if(!$hasData)
<div class="bg-white dark:bg-slate-900 rounded-xl border border-slate-200 dark:border-slate-700 shadow-sm py-24 flex flex-col items-center justify-center gap-4">
    <svg class="w-12 h-12 text-slate-200 dark:text-slate-700" fill="none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24">
        <path stroke-linecap="round" stroke-linejoin="round"
              d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z"/>
    </svg>
    <p class="text-sm font-mono text-slate-400">No data for {{ $dateFrom }} – {{ $dateTo }}</p>
    <p class="text-xs font-mono text-slate-300 dark:text-slate-600">Try a wider date range, or sync orders first.</p>
</div>
@else

{{-- KPI SUMMARY — explicit request, 2026-09-03 ("modern UI... like TailAdmin"):
     4 headline numbers with a trend badge vs the immediately-preceding period
     of equal length, same .stat-card convention the Dashboard's own KPI row
     already established (icon badge left, label/value/subtitle stacked
     right) — just with the delta badge pattern from the reference added on
     top of it. --}}
<div class="grid grid-cols-2 lg:grid-cols-4 gap-3 sm:gap-5 mb-6">
    @foreach($kpis as $key => $kpi)
    @php
        $kpiIcons = [
            'total_called'    => ['bg' => 'rgba(202,138,4,0.12)', 'fg' => '#CA8A04', 'path' => 'M8 12h.01M12 12h.01M16 12h.01M21 12c0 4.418-4.03 8-9 8a9.863 9.863 0 01-4.255-.949L3 20l1.395-3.72C3.512 15.042 3 13.574 3 12c0-4.418 4.03-8 9-8s9 3.582 9 8z'],
            'pick_up_rate'    => ['bg' => 'rgba(37,99,235,0.12)',  'fg' => '#2563EB', 'path' => 'M9 12.75L11.25 15 15 9.75M21 12a9 9 0 11-18 0 9 9 0 0118 0z'],
            'conversion_rate' => ['bg' => 'rgba(234,88,12,0.12)',  'fg' => '#EA580C', 'path' => 'M13 7h8m0 0v8m0-8l-8 8-4-4-6 6'],
            'upselling_rate'  => ['bg' => 'rgba(202,138,4,0.12)',  'fg' => '#CA8A04', 'path' => 'M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V6m0 10v2'],
        ];
        $icon = $kpiIcons[$key];
        $deltaUp = $kpi['delta'] !== null && $kpi['delta'] > 0;
        $deltaDown = $kpi['delta'] !== null && $kpi['delta'] < 0;
    @endphp
    <div class="stat-card bg-white dark:bg-slate-900 rounded-xl border border-slate-200 dark:border-slate-700 p-3 sm:p-5 shadow-sm flex flex-col sm:flex-row items-center sm:items-start text-center sm:text-left gap-2 sm:gap-4">
        <div class="w-9 h-9 sm:w-12 sm:h-12 rounded-full flex items-center justify-center shrink-0" style="background:{{ $icon['bg'] }}">
            <svg class="w-4.5 h-4.5 sm:w-6 sm:h-6" style="color:{{ $icon['fg'] }}" fill="none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" d="{{ $icon['path'] }}" />
            </svg>
        </div>
        <div class="min-w-0 w-full">
            <p class="text-xs font-mono font-semibold text-slate-400 uppercase tracking-wider mb-1">{{ $kpi['label'] }}</p>
            <p class="text-lg sm:text-2xl font-bold text-slate-800 dark:text-slate-100 font-mono leading-none" style="font-variant-numeric: tabular-nums">
                {{ $kpi['value'] !== null ? $kpi['value'] . $kpi['suffix'] : '—' }}
            </p>
            <p class="mt-1.5 flex items-center justify-center sm:justify-start gap-1 text-xs font-mono">
                @if($kpi['delta'] === null)
                <span class="text-slate-400">No prior-period data</span>
                @else
                <span class="inline-flex items-center gap-0.5 font-semibold {{ $deltaUp ? 'text-green-600 dark:text-green-400' : ($deltaDown ? 'text-rose-600 dark:text-rose-400' : 'text-slate-400') }}">
                    @if($deltaUp)
                    <svg class="w-3 h-3" fill="currentColor" viewBox="0 0 20 20"><path d="M10 3l6 7h-4v7H8v-7H4l6-7z"/></svg>
                    @elseif($deltaDown)
                    <svg class="w-3 h-3" fill="currentColor" viewBox="0 0 20 20"><path d="M10 17l-6-7h4V3h4v7h4l-6 7z"/></svg>
                    @endif
                    {{ $deltaUp ? '+' : '' }}{{ $kpi['delta'] }}{{ $kpi['deltaSuffix'] }}
                </span>
                <span class="text-slate-400">vs prior period</span>
                @endif
            </p>
        </div>
    </div>
    @endforeach
</div>

{{-- SECTION TABS — explicit request, 2026-09-03 ("separation in analytics
     like in the settings"): same tab pattern as settings.blade.php (plain
     show/hide, sessionStorage-persisted active tab, .{page}-tab-btn /
     data-{page}-panel convention) — 'analytics' prefix instead of
     'settings' so both pages' JS/storage never collide if open in different
     tabs at once. Chart.js needs its OWN handling here that Settings never
     needed: a canvas drawn while its parent is display:none measures as
     0×0, so every chart on this page is now created hidden-safe (see the
     script block below — each Chart.js instance is tracked and .resize()'d
     the moment its tab actually becomes visible) rather than at page-load
     time regardless of which tab is showing. --}}
<div class="border-b border-slate-200 dark:border-slate-700 mb-6">
    <nav class="flex gap-6 overflow-x-auto" role="tablist" aria-label="Analytics sections">
        @foreach(['trends' => 'Trends', 'products' => 'Products', 'tsa' => 'TSA'] as $tabKey => $tabLabel)
        <button type="button" role="tab" data-analytics-tab="{{ $tabKey }}"
                class="analytics-tab-btn shrink-0 px-1 pb-3 text-sm font-semibold border-b-2 border-transparent text-slate-400 dark:text-slate-500 hover:text-slate-600 dark:hover:text-slate-300 transition-colors cursor-pointer whitespace-nowrap">
            {{ $tabLabel }}
        </button>
        @endforeach
    </nav>
</div>

{{-- ===== TRENDS ===== --}}
<div data-analytics-panel="trends">
<div class="grid grid-cols-1 lg:grid-cols-2 gap-6 mb-6">

    {{-- RATE TRENDS — 3 line charts, one per rate, each split by team --}}
    @foreach(['pick_up_rate' => 'Pick-up Rate', 'conversion_rate' => 'Conversion Rate', 'upselling_rate' => 'Upselling Rate'] as $rateKey => $rateLabel)
    <div class="bg-white dark:bg-slate-900 rounded-xl border border-slate-200 dark:border-slate-700 shadow-sm p-6">
        <div class="flex items-center justify-between mb-5">
            <div>
                <h2 class="text-sm font-bold text-slate-700 dark:text-slate-200 font-mono">{{ $rateLabel }} Trend</h2>
                <p class="text-xs text-slate-400 font-mono mt-0.5">{{ $dateFrom }} – {{ $dateTo }}</p>
            </div>
            <div class="flex items-center gap-4 text-xs font-mono text-slate-600 dark:text-slate-400">
                @foreach($orderTeams as $i => $team)
                <span class="flex items-center gap-1.5">
                    <span class="w-3 h-0.5 inline-block rounded" style="background:{{ $teamColors[$i % count($teamColors)] }}"></span>
                    {{ $teamNames[$team] ?? $team }}
                </span>
                @endforeach
            </div>
        </div>
        <canvas id="rateChart-{{ $rateKey }}" height="90"></canvas>
    </div>
    @endforeach

    {{-- TOTAL CALLED LEADS TREND — pairs with the 3 rate trends above (volume
         alongside "how well handled"), and fills the 4th slot in this 2x2 grid so
         Upselling Rate Trend isn't left alone with an empty gap next to it. --}}
    <div class="bg-white dark:bg-slate-900 rounded-xl border border-slate-200 dark:border-slate-700 shadow-sm p-6">
        <div class="flex items-center justify-between mb-5">
            <div>
                <h2 class="text-sm font-bold text-slate-700 dark:text-slate-200 font-mono">Total Called Leads Trend</h2>
                <p class="text-xs text-slate-400 font-mono mt-0.5">{{ $dateFrom }} – {{ $dateTo }}</p>
            </div>
            <div class="flex items-center gap-4 text-xs font-mono text-slate-600 dark:text-slate-400">
                @foreach($orderTeams as $i => $team)
                <span class="flex items-center gap-1.5">
                    <span class="w-3 h-0.5 inline-block rounded" style="background:{{ $teamColors[$i % count($teamColors)] }}"></span>
                    {{ $teamNames[$team] ?? $team }}
                </span>
                @endforeach
            </div>
        </div>
        <canvas id="calledChart" height="90"></canvas>
    </div>

</div>

<div class="grid grid-cols-1 lg:grid-cols-2 gap-6 mb-6">

    {{-- EXCESS LEADS TREND --}}
    <div class="bg-white dark:bg-slate-900 rounded-xl border border-slate-200 dark:border-slate-700 shadow-sm p-6">
        <div class="mb-5">
            <h2 class="text-sm font-bold text-slate-700 dark:text-slate-200 font-mono">Excess Leads Trend</h2>
            <p class="text-xs text-slate-400 font-mono mt-0.5">Uncatered, unclaimed leads per day — both teams</p>
        </div>
        <canvas id="excessChart" height="140"></canvas>
    </div>

    {{-- RTS vs DELIVERED TREND — upsell revenue that reached the customer vs came
         back, per day, both teams combined (the trend behind the RTS/Delivered tab). --}}
    <div class="bg-white dark:bg-slate-900 rounded-xl border border-slate-200 dark:border-slate-700 shadow-sm p-6">
        <div class="flex items-center justify-between mb-5">
            <div>
                <h2 class="text-sm font-bold text-slate-700 dark:text-slate-200 font-mono">RTS vs Delivered Trend</h2>
                <p class="text-xs text-slate-400 font-mono mt-0.5">Upsell revenue delivered vs returned, per day</p>
            </div>
            <div class="flex items-center gap-3 text-xs font-mono text-slate-600 dark:text-slate-400">
                <span class="flex items-center gap-1.5"><span class="w-3 h-0.5 inline-block rounded bg-green-500"></span> Delivered</span>
                <span class="flex items-center gap-1.5"><span class="w-3 h-0.5 inline-block rounded bg-rose-500"></span> RTS</span>
            </div>
        </div>
        <canvas id="rtsDeliveredChart" height="140"></canvas>
    </div>

</div>

{{-- Odd card out (5 charts in this region since RTS vs Delivered joined) — runs
     full-width rather than sitting half-width beside an empty slot. --}}
<div class="grid grid-cols-1 gap-6 mb-6">

    {{-- DAILY SALES --}}
    <div class="bg-white dark:bg-slate-900 rounded-xl border border-slate-200 dark:border-slate-700 shadow-sm p-6">
        <div class="flex items-center justify-between mb-5">
            <div>
                <h2 class="text-sm font-bold text-slate-700 dark:text-slate-200 font-mono">Daily Sales</h2>
                <p class="text-xs text-slate-400 font-mono mt-0.5">Cross-sell/upsell revenue per day</p>
            </div>
            <div class="flex items-center gap-4 text-xs font-mono text-slate-600 dark:text-slate-400">
                @foreach($orderTeams as $i => $team)
                <span class="flex items-center gap-1.5">
                    <span class="w-3 h-3 rounded-sm inline-block" style="background:{{ $teamColors[$i % count($teamColors)] }}"></span>
                    {{ $teamNames[$team] ?? $team }}
                </span>
                @endforeach
            </div>
        </div>
        <canvas id="salesChart" height="140"></canvas>
    </div>

</div>

<div class="grid grid-cols-1 lg:grid-cols-2 gap-6 mb-6">

    {{-- DISPOSITION MIX TREND --}}
    <div class="bg-white dark:bg-slate-900 rounded-xl border border-slate-200 dark:border-slate-700 shadow-sm p-6">
        <div class="flex items-center justify-between mb-5">
            <div>
                <h2 class="text-sm font-bold text-slate-700 dark:text-slate-200 font-mono">Lead Outcome Mix</h2>
                <p class="text-xs text-slate-400 font-mono mt-0.5">Answered vs Unanswered vs Excess, per day</p>
            </div>
            <div class="flex items-center gap-3 text-xs font-mono text-slate-600 dark:text-slate-400">
                <span class="flex items-center gap-1.5"><span class="w-3 h-3 rounded-sm inline-block bg-green-500"></span> Answered</span>
                <span class="flex items-center gap-1.5"><span class="w-3 h-3 rounded-sm inline-block bg-red-400"></span> Unanswered</span>
                <span class="flex items-center gap-1.5"><span class="w-3 h-3 rounded-sm inline-block bg-rose-600"></span> Excess</span>
            </div>
        </div>
        <canvas id="mixChart" height="140"></canvas>
    </div>

    {{-- HOURLY LEADS VS EXCESS --}}
    <div class="bg-white dark:bg-slate-900 rounded-xl border border-slate-200 dark:border-slate-700 shadow-sm p-6">
        <div class="flex items-center justify-between mb-5">
            <div>
                <h2 class="text-sm font-bold text-slate-700 dark:text-slate-200 font-mono">Leads by Hour of Day</h2>
                <p class="text-xs text-slate-400 font-mono mt-0.5">Total across the range — spot when excess spikes with volume</p>
            </div>
            <div class="flex items-center gap-3 text-xs font-mono text-slate-600 dark:text-slate-400">
                <span class="flex items-center gap-1.5"><span class="w-3 h-3 rounded-sm inline-block" style="background:#CA8A04"></span> New Leads</span>
                <span class="flex items-center gap-1.5"><span class="w-3 h-3 rounded-sm inline-block bg-rose-600"></span> Excess</span>
            </div>
        </div>
        <canvas id="hourlyChart" height="140"></canvas>
    </div>

</div>
</div>{{-- /data-analytics-panel="trends" --}}

{{-- ===== PRODUCTS ===== --}}
<div data-analytics-panel="products" class="hidden">
<div class="bg-white dark:bg-slate-900 rounded-xl border border-slate-200 dark:border-slate-700 shadow-sm p-6 mb-6">
    <div class="flex items-center justify-between mb-5">
        <div>
            <h2 class="text-sm font-bold text-slate-700 dark:text-slate-200 font-mono">Upselling Rate by Product</h2>
            <p class="text-xs text-slate-400 font-mono mt-0.5">{{ $dateFrom }} – {{ $dateTo }}, sorted highest to lowest</p>
        </div>
        <div class="flex items-center gap-4 text-xs font-mono text-slate-600 dark:text-slate-400">
            @foreach($orderTeams as $i => $team)
            <span class="flex items-center gap-1.5">
                <span class="w-3 h-3 rounded-sm inline-block" style="background:{{ $teamColors[$i % count($teamColors)] }}"></span>
                {{ $teamNames[$team] ?? $team }}
            </span>
            @endforeach
        </div>
    </div>
    <canvas id="productChart" height="110"></canvas>
</div>

{{-- PRODUCT SALES COMPARISON — same card/bar style as Upselling Rate above, but
     measuring cross-sell REVENUE per product (₱), sorted highest to lowest. --}}
<div class="bg-white dark:bg-slate-900 rounded-xl border border-slate-200 dark:border-slate-700 shadow-sm p-6 mb-6">
    <div class="flex items-center justify-between mb-5">
        <div>
            <h2 class="text-sm font-bold text-slate-700 dark:text-slate-200 font-mono">Total Upsell Sales by Product</h2>
            <p class="text-xs text-slate-400 font-mono mt-0.5">{{ $dateFrom }} – {{ $dateTo }}, sorted highest to lowest</p>
        </div>
        <div class="flex items-center gap-4 text-xs font-mono text-slate-600 dark:text-slate-400">
            @foreach($orderTeams as $i => $team)
            <span class="flex items-center gap-1.5">
                <span class="w-3 h-3 rounded-sm inline-block" style="background:{{ $teamColors[$i % count($teamColors)] }}"></span>
                {{ $teamNames[$team] ?? $team }}
            </span>
            @endforeach
        </div>
    </div>
    <canvas id="productSalesChart" height="110"></canvas>
</div>
</div>{{-- /data-analytics-panel="products" --}}

{{-- TSA RANKINGS — explicit request, 2026-09-03: Pick-up/Conversion/Upselling
     Rate per TSA, as three bar charts (same card/bar style as the Product
     comparison charts above — one metric per chart, sorted highest to
     lowest, bars colored by team), not a table. --}}
{{-- ===== TSA ===== --}}
<div data-analytics-panel="tsa" class="hidden">
@if($tsaRankings->isNotEmpty())
<div class="bg-white dark:bg-slate-900 rounded-xl border border-slate-200 dark:border-slate-700 shadow-sm p-6 mb-6">
    <div class="flex items-center justify-between mb-5">
        <div>
            <h2 class="text-sm font-bold text-slate-700 dark:text-slate-200 font-mono">Pick-up Rate by TSA</h2>
            <p class="text-xs text-slate-400 font-mono mt-0.5">{{ $dateFrom }} – {{ $dateTo }}, sorted highest to lowest</p>
        </div>
        <div class="flex items-center gap-4 text-xs font-mono text-slate-600 dark:text-slate-400">
            @foreach($orderTeams as $i => $team)
            <span class="flex items-center gap-1.5">
                <span class="w-3 h-3 rounded-sm inline-block" style="background:{{ $teamColors[$i % count($teamColors)] }}"></span>
                {{ $teamNames[$team] ?? $team }}
            </span>
            @endforeach
        </div>
    </div>
    <canvas id="tsaPickUpChart" height="110"></canvas>
</div>

<div class="bg-white dark:bg-slate-900 rounded-xl border border-slate-200 dark:border-slate-700 shadow-sm p-6 mb-6">
    <div class="flex items-center justify-between mb-5">
        <div>
            <h2 class="text-sm font-bold text-slate-700 dark:text-slate-200 font-mono">Conversion Rate by TSA</h2>
            <p class="text-xs text-slate-400 font-mono mt-0.5">{{ $dateFrom }} – {{ $dateTo }}, sorted highest to lowest</p>
        </div>
        <div class="flex items-center gap-4 text-xs font-mono text-slate-600 dark:text-slate-400">
            @foreach($orderTeams as $i => $team)
            <span class="flex items-center gap-1.5">
                <span class="w-3 h-3 rounded-sm inline-block" style="background:{{ $teamColors[$i % count($teamColors)] }}"></span>
                {{ $teamNames[$team] ?? $team }}
            </span>
            @endforeach
        </div>
    </div>
    <canvas id="tsaConversionChart" height="110"></canvas>
</div>

<div class="bg-white dark:bg-slate-900 rounded-xl border border-slate-200 dark:border-slate-700 shadow-sm p-6 mb-6">
    <div class="flex items-center justify-between mb-5">
        <div>
            <h2 class="text-sm font-bold text-slate-700 dark:text-slate-200 font-mono">Upselling Rate by TSA</h2>
            <p class="text-xs text-slate-400 font-mono mt-0.5">{{ $dateFrom }} – {{ $dateTo }}, sorted highest to lowest</p>
        </div>
        <div class="flex items-center gap-4 text-xs font-mono text-slate-600 dark:text-slate-400">
            @foreach($orderTeams as $i => $team)
            <span class="flex items-center gap-1.5">
                <span class="w-3 h-3 rounded-sm inline-block" style="background:{{ $teamColors[$i % count($teamColors)] }}"></span>
                {{ $teamNames[$team] ?? $team }}
            </span>
            @endforeach
        </div>
    </div>
    <canvas id="tsaUpsellingChart" height="110"></canvas>
</div>
@endif
</div>{{-- /data-analytics-panel="tsa" --}}

@endif

@endsection

@push('topbar-right')
<div class="flex items-center gap-4 flex-wrap">

@if($dateTo === now('Asia/Manila')->format('Y-m-d'))
@include('partials.live-indicator')
@endif

{{-- Range picker — this tab is trend-first, so unlike every other single-day
     report it defaults to (and needs) a date RANGE, same as the Dashboard. --}}
@include('partials.date-picker', [
    'mode' => 'range', 'id' => 'drp',
    'dateFrom' => \Illuminate\Support\Carbon::parse($dateFrom), 'dateTo' => \Illuminate\Support\Carbon::parse($dateTo),
    'submit' => 'navigate', 'navigateBase' => route('charts'),
])

</div>
@endpush

@if($hasData)
@push('scripts')
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.2/dist/chart.umd.min.js"></script>
{{-- data-rerun: app.js's softRefresh re-executes this (from the freshly fetched
     page, so the @json data below is current) after swapping <main> in place —
     the canvases these inits target live inside the swapped region. The IIFE
     wrapper is what makes re-execution safe: without it the top-level consts
     would collide with the first run's declarations and throw.

     Dark mode: Chart.js draws to a <canvas>, so `dark:` utility classes can't
     reach its text/gridline colors — same escape hatch as the Dashboard's
     inline-SVG hourly chart (app.css's --chart-* custom properties), read here
     via getComputedStyle instead of duplicating the hex values in JS. This
     picks the right palette on initial load and on every soft-refresh
     (date-range change, sync); it does NOT live-redraw on a theme-toggle click
     with no navigation in between — acceptable per the same reasoning as the
     rest of this page's charts (re-init is opt-in via [data-rerun], not a
     persistent live-updating chart instance). Saturated series colors (team
     cyan/emerald, green/red/rose status colors) are left unpaired throughout,
     same as everywhere else in the app — they already read fine on both
     surfaces. --}}
<script data-rerun>
(function () {
const dailyLabels  = @json($dailyLabels);
const orderTeams   = @json($orderTeams);
const teamNames    = @json($teamNames);
const teamColors   = @json($teamColors);
const rateSeries   = @json($rateSeries);
const calledSeries = @json($calledSeries);
const salesSeries  = @json($salesSeries);
const excessSeries = @json($excessSeries);
const answeredSeries   = @json($answeredSeries);
const unansweredSeries = @json($unansweredSeries);
const deliveredSeries  = @json($deliveredSeries);
const rtsSeries        = @json($rtsSeries);
const productRows  = @json($productRows);
const tsaRankings  = @json($tsaRankings);
const hourlyLabels = @json($hourlyLabels);
const hourlyLeads  = @json($hourlyLeads);
const hourlyExcess = @json($hourlyExcess);

// Read the current theme's chart colors from the CSS custom properties defined
// in app.css (--chart-grid / --chart-label, light + .dark values) instead of
// hardcoding a second copy of the palette here.
const rootStyles = getComputedStyle(document.documentElement);
const cssVar = (name, fallback) => (rootStyles.getPropertyValue(name) || '').trim() || fallback;
const labelColor = cssVar('--chart-label', '#94a3b8');
const gridColor  = cssVar('--chart-grid', '#f1f5f9');

Chart.defaults.font.family = "'Fira Code', monospace";
Chart.defaults.font.size   = 11;
Chart.defaults.color       = labelColor;

const gridStyle = { color: gridColor };

/* --- Rate trend line charts (3x) --- */
Object.keys(rateSeries).forEach((rateKey) => {
    const el = document.getElementById('rateChart-' + rateKey);
    if (!el) return;
    const datasets = orderTeams.map((team, i) => ({
        label: teamNames[team] || team,
        data: rateSeries[rateKey][team],
        borderColor: teamColors[i % teamColors.length],
        backgroundColor: teamColors[i % teamColors.length] + '14',
        borderDash: i === 1 ? [6, 4] : [], // second team dashed — series distinguished
        tension: 0.35, fill: i === 0, pointRadius: 3, borderWidth: 2,
        spanGaps: true,
    }));
    new Chart(el, {
        type: 'line',
        data: { labels: dailyLabels, datasets },
        options: {
            responsive: true,
            plugins: {
                legend: { display: false },
                tooltip: {
                    mode: 'index', intersect: false,
                    callbacks: { label: ctx => ` ${ctx.dataset.label}: ${ctx.raw === null ? '—' : ctx.raw + '%'}` },
                },
            },
            scales: {
                x: { grid: gridStyle },
                y: { grid: gridStyle, beginAtZero: true, max: 100, ticks: { callback: v => v + '%' } },
            },
        },
    });
});

/* --- Total Called Leads trend (pairs with the 3 rate charts above) --- */
new Chart(document.getElementById('calledChart'), {
    type: 'line',
    data: {
        labels: dailyLabels,
        datasets: orderTeams.map((team, i) => ({
            label: teamNames[team] || team,
            data: calledSeries[team],
            borderColor: teamColors[i % teamColors.length],
            backgroundColor: teamColors[i % teamColors.length] + '14',
            borderDash: i === 1 ? [6, 4] : [],
            tension: 0.35, fill: i === 0, pointRadius: 3, borderWidth: 2,
        })),
    },
    options: {
        responsive: true,
        plugins: {
            legend: { display: false },
            tooltip: { mode: 'index', intersect: false },
        },
        scales: {
            x: { grid: gridStyle },
            y: { grid: gridStyle, beginAtZero: true, ticks: { precision: 0 } },
        },
    },
});

/* --- Excess Leads trend (single bar) --- */
new Chart(document.getElementById('excessChart'), {
    type: 'bar',
    data: {
        labels: dailyLabels,
        datasets: [{ label: 'Excess Leads', data: excessSeries, backgroundColor: '#e11d48', borderRadius: 4, borderSkipped: false }],
    },
    options: {
        responsive: true,
        plugins: { legend: { display: false } },
        scales: { x: { grid: { display: false } }, y: { grid: gridStyle, beginAtZero: true, ticks: { precision: 0 } } },
    },
});

/* --- RTS vs Delivered trend (two lines, both teams combined) --- */
new Chart(document.getElementById('rtsDeliveredChart'), {
    type: 'line',
    data: {
        labels: dailyLabels,
        datasets: [
            {
                label: 'Delivered', data: deliveredSeries,
                borderColor: '#22c55e', backgroundColor: '#22c55e14',
                tension: 0.35, fill: true, pointRadius: 3, borderWidth: 2,
            },
            {
                label: 'RTS', data: rtsSeries,
                borderColor: '#f43f5e', backgroundColor: '#f43f5e14',
                tension: 0.35, fill: false, pointRadius: 3, borderWidth: 2,
            },
        ],
    },
    options: {
        responsive: true,
        plugins: {
            legend: { display: false },
            tooltip: {
                mode: 'index', intersect: false,
                callbacks: { label: ctx => ` ${ctx.dataset.label}: ₱${ctx.raw.toLocaleString()}` },
            },
        },
        scales: {
            x: { grid: gridStyle },
            y: { grid: gridStyle, beginAtZero: true, ticks: { callback: v => '₱' + v.toLocaleString() } },
        },
    },
});

/* --- Daily Sales (grouped bar, per team) --- */
new Chart(document.getElementById('salesChart'), {
    type: 'bar',
    data: {
        labels: dailyLabels,
        datasets: orderTeams.map((team, i) => ({
            label: teamNames[team] || team,
            data: salesSeries[team],
            backgroundColor: teamColors[i % teamColors.length],
            borderRadius: 4, borderSkipped: false,
        })),
    },
    options: {
        responsive: true,
        plugins: {
            legend: { display: false },
            tooltip: { callbacks: { label: ctx => ` ${ctx.dataset.label}: ₱${ctx.raw.toLocaleString()}` } },
        },
        scales: {
            x: { grid: { display: false } },
            y: { grid: gridStyle, beginAtZero: true, ticks: { callback: v => '₱' + v.toLocaleString() } },
        },
    },
});

/* --- Lead Outcome Mix (stacked bar) --- */
new Chart(document.getElementById('mixChart'), {
    type: 'bar',
    data: {
        labels: dailyLabels,
        datasets: [
            { label: 'Answered',   data: answeredSeries,   backgroundColor: '#22c55e', stack: 'mix' },
            { label: 'Unanswered', data: unansweredSeries, backgroundColor: '#f87171', stack: 'mix' },
            { label: 'Excess',     data: excessSeries,     backgroundColor: '#e11d48', stack: 'mix' },
        ],
    },
    options: {
        responsive: true,
        plugins: { legend: { display: false } },
        scales: {
            x: { grid: { display: false }, stacked: true },
            y: { grid: gridStyle, stacked: true, beginAtZero: true, ticks: { precision: 0 } },
        },
    },
});

/* --- Hourly Leads vs Excess (grouped bar) --- */
new Chart(document.getElementById('hourlyChart'), {
    type: 'bar',
    data: {
        labels: hourlyLabels,
        datasets: [
            { label: 'New Leads', data: hourlyLeads,  backgroundColor: '#CA8A04', borderRadius: 4, borderSkipped: false },
            { label: 'Excess',    data: hourlyExcess, backgroundColor: '#e11d48', borderRadius: 4, borderSkipped: false },
        ],
    },
    options: {
        responsive: true,
        plugins: { legend: { display: false } },
        scales: { x: { grid: { display: false } }, y: { grid: gridStyle, beginAtZero: true, ticks: { precision: 0 } } },
    },
});

/* --- Product Comparison (Upselling Rate, sorted, colored by team) --- */
new Chart(document.getElementById('productChart'), {
    type: 'bar',
    data: {
        labels: productRows.map(p => p.display_name),
        datasets: [{
            label: 'Upselling Rate',
            data: productRows.map(p => p.upselling_rate),
            backgroundColor: productRows.map(p => teamColors[orderTeams.indexOf(p.team) % teamColors.length]),
            borderRadius: 4, borderSkipped: false,
        }],
    },
    options: {
        responsive: true,
        plugins: {
            legend: { display: false },
            tooltip: { callbacks: { label: ctx => ` ${ctx.raw === null ? '—' : ctx.raw + '%'}` } },
        },
        scales: {
            x: { grid: { display: false } },
            y: { grid: gridStyle, beginAtZero: true, max: 100, ticks: { callback: v => v + '%' } },
        },
    },
});

/* --- Total Upsell Sales per product (₱, own sort — revenue order isn't rate order) --- */
const salesRows = [...productRows].sort((a, b) => b.upsell_sales - a.upsell_sales);
new Chart(document.getElementById('productSalesChart'), {
    type: 'bar',
    data: {
        labels: salesRows.map(p => p.display_name),
        datasets: [{
            label: 'Upsell Sales',
            data: salesRows.map(p => p.upsell_sales),
            backgroundColor: salesRows.map(p => teamColors[orderTeams.indexOf(p.team) % teamColors.length]),
            borderRadius: 4, borderSkipped: false,
        }],
    },
    options: {
        responsive: true,
        plugins: {
            legend: { display: false },
            tooltip: { callbacks: { label: ctx => ' ₱' + Number(ctx.raw).toLocaleString('en-PH', { minimumFractionDigits: 2 }) } },
        },
        scales: {
            x: { grid: { display: false } },
            y: { grid: gridStyle, beginAtZero: true, ticks: { callback: v => '₱' + Number(v).toLocaleString('en-PH') } },
        },
    },
});

/* --- TSA Rankings: Pick-up/Conversion/Upselling Rate per TSA (each own sort
   — a TSA's best rate can differ per metric, same reasoning as Total Upsell
   Sales sorting separately from the Upselling Rate chart above). Colored by
   team, same convention as every other chart on this page. --- */
function tsaRateChart(canvasId, rateKey) {
    const rows = [...tsaRankings]
        .filter(r => r[rateKey] !== null)
        .sort((a, b) => b[rateKey] - a[rateKey]);
    if (rows.length === 0) return;

    new Chart(document.getElementById(canvasId), {
        type: 'bar',
        data: {
            labels: rows.map(r => r.display_name),
            datasets: [{
                data: rows.map(r => r[rateKey]),
                backgroundColor: rows.map(r => teamColors[orderTeams.indexOf(r.team) % teamColors.length]),
                borderRadius: 4, borderSkipped: false,
            }],
        },
        options: {
            responsive: true,
            plugins: {
                legend: { display: false },
                tooltip: { callbacks: { label: ctx => ` ${ctx.raw === null ? '—' : ctx.raw + '%'}` } },
            },
            scales: {
                x: { grid: { display: false } },
                y: { grid: gridStyle, beginAtZero: true, max: 100, ticks: { callback: v => v + '%' } },
            },
        },
    });
}

tsaRateChart('tsaPickUpChart', 'pick_up_rate');
tsaRateChart('tsaConversionChart', 'conversion_rate');
tsaRateChart('tsaUpsellingChart', 'upselling_rate');
})();
</script>

<script data-rerun>
{{-- Section tabs — same plain show/hide + sessionStorage pattern as
     settings.blade.php's own tab JS (see that file for the full reasoning),
     'analytics' prefix so this page's storage key/classes never collide
     with Settings' if both happen to be open in different tabs at once.
     Chart.js-specific problem Settings never had: every chart above is
     created immediately on page load regardless of which tab is visible —
     a canvas inside a display:none ancestor measures 0×0 width/height at
     that moment, so a chart built while its own tab is hidden renders
     blank/squashed even after the tab is later shown (canvas dimensions
     don't recompute on their own just because a CSS class changed).
     Chart.getChart(id) (a real Chart.js v4 static lookup — no need to
     capture/track each `new Chart(...)` return value up in the block
     above) retrieves the already-built instance for a given canvas id;
     calling .resize() on it forces Chart.js to re-measure its now-visible
     container and redraw at the correct size. Run once per canvas the
     first time its own tab is actually activated (a chart already sized
     correctly on a later re-activation doesn't need another resize). --}}
(function () {
    const tabButtons = document.querySelectorAll('.analytics-tab-btn');
    const panels      = document.querySelectorAll('[data-analytics-panel]');
    const STORAGE_KEY = 'analyticsActiveTab';

    const canvasIdsByPanel = {
        trends:   ['rateChart-pick_up_rate', 'rateChart-conversion_rate', 'rateChart-upselling_rate', 'calledChart', 'excessChart', 'rtsDeliveredChart', 'salesChart', 'mixChart', 'hourlyChart'],
        products: ['productChart', 'productSalesChart'],
        tsa:      ['tsaPickUpChart', 'tsaConversionChart', 'tsaUpsellingChart'],
    };
    const resizedPanels = new Set();

    function activate(tabKey) {
        panels.forEach(p => p.classList.toggle('hidden', p.dataset.analyticsPanel !== tabKey));
        tabButtons.forEach(b => {
            const isActive = b.dataset.analyticsTab === tabKey;
            b.classList.toggle('text-primary', isActive);
            b.classList.toggle('border-primary', isActive);
            b.classList.toggle('text-slate-400', !isActive);
            b.classList.toggle('dark:text-slate-500', !isActive);
            b.classList.toggle('border-transparent', !isActive);
        });
        try { sessionStorage.setItem(STORAGE_KEY, tabKey); } catch (e) {}

        if (!resizedPanels.has(tabKey)) {
            resizedPanels.add(tabKey);
            (canvasIdsByPanel[tabKey] || []).forEach(id => {
                const chart = window.Chart?.getChart?.(id);
                chart?.resize();
            });
        }
    }

    tabButtons.forEach(btn => btn.addEventListener('click', () => activate(btn.dataset.analyticsTab)));

    let initial = 'trends';
    try {
        const stored = sessionStorage.getItem(STORAGE_KEY);
        if (stored && document.querySelector(`[data-analytics-panel="${stored}"]`)) initial = stored;
    } catch (e) {}
    activate(initial);
})();
</script>
@endpush
@endif
