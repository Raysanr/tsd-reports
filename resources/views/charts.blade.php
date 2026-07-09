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
<div class="bg-white rounded-xl border border-slate-200 shadow-sm py-24 flex flex-col items-center justify-center gap-4">
    <svg class="w-12 h-12 text-slate-200" fill="none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24">
        <path stroke-linecap="round" stroke-linejoin="round"
              d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z"/>
    </svg>
    <p class="text-sm font-mono text-slate-400">No data for {{ $dateFrom }} – {{ $dateTo }}</p>
    <p class="text-xs font-mono text-slate-300">Try a wider date range, or sync orders first.</p>
</div>
@else

<div class="grid grid-cols-1 lg:grid-cols-2 gap-6 mb-6">

    {{-- RATE TRENDS — 3 line charts, one per rate, each split by team --}}
    @foreach(['pick_up_rate' => 'Pick-up Rate', 'conversion_rate' => 'Conversion Rate', 'upselling_rate' => 'Upselling Rate'] as $rateKey => $rateLabel)
    <div class="bg-white rounded-xl border border-slate-200 shadow-sm p-6">
        <div class="flex items-center justify-between mb-5">
            <div>
                <h2 class="text-sm font-bold text-slate-700 font-mono">{{ $rateLabel }} Trend</h2>
                <p class="text-xs text-slate-400 font-mono mt-0.5">{{ $dateFrom }} – {{ $dateTo }}</p>
            </div>
            <div class="flex items-center gap-4 text-xs font-mono">
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
    <div class="bg-white rounded-xl border border-slate-200 shadow-sm p-6">
        <div class="flex items-center justify-between mb-5">
            <div>
                <h2 class="text-sm font-bold text-slate-700 font-mono">Total Called Leads Trend</h2>
                <p class="text-xs text-slate-400 font-mono mt-0.5">{{ $dateFrom }} – {{ $dateTo }}</p>
            </div>
            <div class="flex items-center gap-4 text-xs font-mono">
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
    <div class="bg-white rounded-xl border border-slate-200 shadow-sm p-6">
        <div class="mb-5">
            <h2 class="text-sm font-bold text-slate-700 font-mono">Excess Leads Trend</h2>
            <p class="text-xs text-slate-400 font-mono mt-0.5">Uncatered, unclaimed leads per day — both teams</p>
        </div>
        <canvas id="excessChart" height="140"></canvas>
    </div>

    {{-- DAILY SALES --}}
    <div class="bg-white rounded-xl border border-slate-200 shadow-sm p-6">
        <div class="flex items-center justify-between mb-5">
            <div>
                <h2 class="text-sm font-bold text-slate-700 font-mono">Daily Sales</h2>
                <p class="text-xs text-slate-400 font-mono mt-0.5">Cross-sell/upsell revenue per day</p>
            </div>
            <div class="flex items-center gap-4 text-xs font-mono">
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
    <div class="bg-white rounded-xl border border-slate-200 shadow-sm p-6">
        <div class="flex items-center justify-between mb-5">
            <div>
                <h2 class="text-sm font-bold text-slate-700 font-mono">Lead Outcome Mix</h2>
                <p class="text-xs text-slate-400 font-mono mt-0.5">Answered vs Unanswered vs Excess, per day</p>
            </div>
            <div class="flex items-center gap-3 text-xs font-mono">
                <span class="flex items-center gap-1.5"><span class="w-3 h-3 rounded-sm inline-block bg-green-500"></span> Answered</span>
                <span class="flex items-center gap-1.5"><span class="w-3 h-3 rounded-sm inline-block bg-red-400"></span> Unanswered</span>
                <span class="flex items-center gap-1.5"><span class="w-3 h-3 rounded-sm inline-block bg-rose-600"></span> Excess</span>
            </div>
        </div>
        <canvas id="mixChart" height="140"></canvas>
    </div>

    {{-- HOURLY LEADS VS EXCESS --}}
    <div class="bg-white rounded-xl border border-slate-200 shadow-sm p-6">
        <div class="flex items-center justify-between mb-5">
            <div>
                <h2 class="text-sm font-bold text-slate-700 font-mono">Leads by Hour of Day</h2>
                <p class="text-xs text-slate-400 font-mono mt-0.5">Total across the range — spot when excess spikes with volume</p>
            </div>
            <div class="flex items-center gap-3 text-xs font-mono">
                <span class="flex items-center gap-1.5"><span class="w-3 h-3 rounded-sm inline-block" style="background:#CA8A04"></span> New Leads</span>
                <span class="flex items-center gap-1.5"><span class="w-3 h-3 rounded-sm inline-block bg-rose-600"></span> Excess</span>
            </div>
        </div>
        <canvas id="hourlyChart" height="140"></canvas>
    </div>

</div>

{{-- PRODUCT COMPARISON --}}
<div class="bg-white rounded-xl border border-slate-200 shadow-sm p-6 mb-6">
    <div class="flex items-center justify-between mb-5">
        <div>
            <h2 class="text-sm font-bold text-slate-700 font-mono">Upselling Rate by Product</h2>
            <p class="text-xs text-slate-400 font-mono mt-0.5">{{ $dateFrom }} – {{ $dateTo }}, sorted highest to lowest</p>
        </div>
        <div class="flex items-center gap-4 text-xs font-mono">
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

@endif

@endsection

@push('topbar-right')
<div class="flex items-center gap-4">

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
<script>
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
const productRows  = @json($productRows);
const hourlyLabels = @json($hourlyLabels);
const hourlyLeads  = @json($hourlyLeads);
const hourlyExcess = @json($hourlyExcess);

Chart.defaults.font.family = "'Fira Code', monospace";
Chart.defaults.font.size   = 11;
Chart.defaults.color       = '#94a3b8';

const gridStyle = { color: '#f1f5f9' };

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
</script>
@endpush
@endif
