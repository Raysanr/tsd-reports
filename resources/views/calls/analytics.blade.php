@extends('layouts.calls')
@section('title', 'Call Analytics')
@section('subtitle', $from->isSameDay($to)
    ? $from->format('M j, Y')
    : $from->format('M j') . ' – ' . $to->format('M j, Y'))

@push('topbar-right')
{{-- Icon-only topbar picker (explicit request, 2026-08-19) — same shared
     partial/convention as the Dashboard's own (submit='navigate', this page
     has no wrapping <form> either), replacing the old plain date inputs +
     Apply button that used to sit in the page body. --}}
@include('partials.date-picker', [
    'mode' => 'range', 'id' => 'analyticsDrp',
    'dateFrom' => $from, 'dateTo' => $to,
    'submit' => 'navigate', 'navigateBase' => route('calls.analytics'),
])
@endpush

@section('content')

{{-- Chart data — a JSON script tag, not inline JS, so a TSA's display_name
     (free-text, admin-editable) never needs escaping into a JS string
     literal; JSON.parse handles that safely regardless of its contents. --}}
<script type="application/json" id="analyticsChartData">{!! json_encode($chartData) !!}</script>

{{-- Explicit request (2026-08-19): no more Overview/AHT tab switcher — both
     sections render together on one continuous page. Charts that used to be
     built lazily on the AHT tab's first reveal (a canvas inside a
     display:none container measures 0x0) now just build immediately, since
     nothing here is ever hidden any more (see calls.js's own comment on
     buildAhtCharts()). --}}

{{-- KPI row — moved to the very top of the page (explicit request,
     2026-08-19; used to sit between the Overview and AHT sections). Same
     icon-badge stat-tile as Call Tracker's own Dashboard. Login Time/
     Unproductive/Total Leads/Total Catered don't depend on CallEvent data
     existing, so they render even when the charts below are still in their
     empty state — only the AHT card itself falls back to "—" then. --}}
<div class="grid grid-cols-2 lg:grid-cols-5 gap-3 sm:gap-5 mb-6">
    @include('calls.partials.stat-tile', ['label' => 'Login Time', 'value' => $loginTimeDisplay, 'icon' => 'user', 'color' => 'text-yellow-600 dark:text-yellow-400 bg-yellow-50 dark:bg-yellow-950/40', 'underline' => 'bg-yellow-500', 'caption' => 'Total time logged in'])
    @include('calls.partials.stat-tile', ['label' => 'AHT', 'value' => $overallAhtDisplay, 'icon' => 'stopwatch', 'color' => 'text-blue-600 dark:text-blue-400 bg-blue-50 dark:bg-blue-950/40', 'underline' => 'bg-blue-500', 'caption' => 'Average handle time'])
    @include('calls.partials.stat-tile', ['label' => 'Unproductive', 'value' => $overallUnproductiveDisplay, 'icon' => 'hourglass', 'color' => 'text-red-600 dark:text-red-400 bg-red-50 dark:bg-red-950/40', 'underline' => 'bg-red-500', 'caption' => 'Average per TSA'])
    @include('calls.partials.stat-tile', ['label' => 'Total Leads', 'value' => $totalLeadsSum, 'icon' => 'inbox', 'color' => 'text-amber-600 dark:text-amber-400 bg-amber-50 dark:bg-amber-950/40', 'underline' => 'bg-amber-500', 'caption' => 'This range'])
    @include('calls.partials.stat-tile', ['label' => 'Total Catered Leads', 'value' => $totalCateredSum, 'icon' => 'headset', 'color' => 'text-emerald-600 dark:text-emerald-400 bg-emerald-50 dark:bg-emerald-950/40', 'underline' => 'bg-emerald-500', 'caption' => 'This range'])
</div>

@if(!$chartData['hasAnyCalls'])
<div id="analyticsChartsEmpty" class="bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-700 shadow-sm px-6 py-16 text-center mb-6">
    <p class="text-sm font-mono text-slate-400">No calls logged yet in this range.</p>
    <p class="text-xs font-mono text-slate-400/70 mt-1">Charts will appear once a TSA logs an outcome on a lead.</p>
</div>
@endif

<div id="analyticsChartsWrap" class="{{ $chartData['hasAnyCalls'] ? '' : 'hidden' }} grid grid-cols-1 lg:grid-cols-2 gap-4 mb-6">
    <div class="bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-700 shadow-sm p-5">
        <h2 class="text-sm font-bold text-slate-800 dark:text-slate-100 font-mono mb-1">Call Volume &amp; Coverage</h2>
        <p class="text-xs font-mono text-slate-400 mb-4">Total leads vs. how many were actually called, per TSA</p>
        <div class="h-64">
            <canvas id="chartCallVolume" role="img" aria-label="Bar chart comparing total leads assigned against leads called, per TSA — see the table below for exact figures"></canvas>
        </div>
    </div>
    <div class="bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-700 shadow-sm p-5">
        <h2 class="text-sm font-bold text-slate-800 dark:text-slate-100 font-mono mb-1">Outcome Quality</h2>
        <p class="text-xs font-mono text-slate-400 mb-4">Confirm rate vs. no-answer rate, per TSA</p>
        <div class="h-64">
            <canvas id="chartOutcomeQuality" role="img" aria-label="Bar chart comparing confirm rate against no-answer rate as percentages, per TSA — see the table below for exact figures"></canvas>
        </div>
    </div>
    <div class="bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-700 shadow-sm p-5 lg:col-span-2">
        <h2 class="text-sm font-bold text-slate-800 dark:text-slate-100 font-mono mb-1">Avg Response Time</h2>
        <p class="text-xs font-mono text-slate-400 mb-4">Minutes between a lead being assigned and actually called — fastest first</p>
        <div class="h-64">
            <canvas id="chartResponseTime" role="img" aria-label="Horizontal bar chart ranking TSAs by average response time in minutes, fastest first — see the table below for exact figures"></canvas>
        </div>
    </div>
</div>

<div class="bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-700 shadow-sm overflow-hidden mb-6">
    <div class="overflow-x-auto">
    <table class="w-full text-sm font-mono">
        <thead class="bg-slate-100 dark:bg-slate-700 border-b border-slate-200 dark:border-slate-700">
            <tr>
                <th class="px-4 py-3 text-left text-[11px] font-bold text-slate-400 uppercase tracking-wide">TSA</th>
                <th class="px-4 py-3 text-right text-[11px] font-bold text-slate-400 uppercase tracking-wide">Total Leads</th>
                <th class="px-4 py-3 text-right text-[11px] font-bold text-slate-400 uppercase tracking-wide">Called</th>
                <th class="px-4 py-3 text-right text-[11px] font-bold text-slate-400 uppercase tracking-wide">Confirm Rate</th>
                <th class="px-4 py-3 text-right text-[11px] font-bold text-slate-400 uppercase tracking-wide">No-Answer Rate</th>
                <th class="px-4 py-3 text-right text-[11px] font-bold text-slate-400 uppercase tracking-wide">Avg Response</th>
            </tr>
        </thead>
        <tbody class="divide-y divide-slate-100 dark:divide-slate-700">
            @foreach($rows as $row)
            <tr class="hover:bg-slate-50 dark:hover:bg-slate-800">
                <td class="px-4 py-3 font-semibold text-slate-700 dark:text-slate-200">{{ $row['tsa']->display_name }}</td>
                <td class="px-4 py-3 text-right text-slate-600 dark:text-slate-300">{{ $row['total'] }}</td>
                <td class="px-4 py-3 text-right text-slate-600 dark:text-slate-300">{{ $row['called'] }}</td>
                <td class="px-4 py-3 text-right text-slate-600 dark:text-slate-300">{{ $row['confirm_rate'] !== null ? $row['confirm_rate'].'%' : '—' }}</td>
                <td class="px-4 py-3 text-right text-slate-600 dark:text-slate-300">{{ $row['no_answer_rate'] !== null ? $row['no_answer_rate'].'%' : '—' }}</td>
                <td class="px-4 py-3 text-right text-slate-600 dark:text-slate-300">{{ $row['avg_response_mins'] !== null ? $row['avg_response_mins'].' min' : '—' }}</td>
            </tr>
            @endforeach
        </tbody>
    </table>
    </div>
</div>

{{-- Status Time — team-wide totals only (explicit request, 2026-08-19),
         not broken out per TSA. Computed from TsaStatusLog in the controller
         (see its own comment for the walk-the-log-history reasoning);
         Others folds in Break/Logout/Lock. --}}
    <div class="bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-700 shadow-sm p-5 mb-6">
        <h2 class="text-sm font-bold text-slate-800 dark:text-slate-100 font-mono mb-1">Status Time</h2>
        <p class="text-xs font-mono text-slate-400 mb-4">Team-wide time spent in each status this range</p>
        <div class="grid grid-cols-2 sm:grid-cols-4 gap-4">
            <div class="flex items-center gap-3">
                <span class="w-2.5 h-2.5 rounded-full bg-blue-500 shrink-0"></span>
                <div class="min-w-0">
                    <p class="text-[11px] font-mono font-semibold text-slate-400 uppercase tracking-wide">Coaching</p>
                    <p class="text-lg font-bold text-slate-800 dark:text-slate-100 font-mono tabular-nums">{{ $statusTime['coaching'] }}</p>
                </div>
            </div>
            <div class="flex items-center gap-3">
                <span class="w-2.5 h-2.5 rounded-full bg-purple-500 shrink-0"></span>
                <div class="min-w-0">
                    <p class="text-[11px] font-mono font-semibold text-slate-400 uppercase tracking-wide">DNA Huddle</p>
                    <p class="text-lg font-bold text-slate-800 dark:text-slate-100 font-mono tabular-nums">{{ $statusTime['dnaHuddle'] }}</p>
                </div>
            </div>
            <div class="flex items-center gap-3">
                <span class="w-2.5 h-2.5 rounded-full bg-orange-500 shrink-0"></span>
                <div class="min-w-0">
                    <p class="text-[11px] font-mono font-semibold text-slate-400 uppercase tracking-wide">Huddle</p>
                    <p class="text-lg font-bold text-slate-800 dark:text-slate-100 font-mono tabular-nums">{{ $statusTime['huddle'] }}</p>
                </div>
            </div>
            <div class="flex items-center gap-3">
                <span class="w-2.5 h-2.5 rounded-full bg-slate-400 shrink-0"></span>
                <div class="min-w-0">
                    <p class="text-[11px] font-mono font-semibold text-slate-400 uppercase tracking-wide">Others</p>
                    <p class="text-lg font-bold text-slate-800 dark:text-slate-100 font-mono tabular-nums">{{ $statusTime['others'] }}</p>
                    <p class="text-[10px] text-slate-400 font-mono">Break + Logout</p>
                </div>
            </div>
        </div>
    </div>

    @if(!$chartData['hasAnyAht'])
    <div class="bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-700 shadow-sm px-6 py-16 text-center mb-6">
        <p class="text-sm font-mono text-slate-400">No call durations logged yet in this range.</p>
        <p class="text-xs font-mono text-slate-400/70 mt-1">This needs the phone call automation set up on Call Rotation — see "Phone call automation (MacroDroid)" for each TSA.</p>
    </div>
    @else
    <div class="grid grid-cols-1 lg:grid-cols-2 gap-4 mb-6">
        <div class="bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-700 shadow-sm p-5">
            <h2 class="text-sm font-bold text-slate-800 dark:text-slate-100 font-mono mb-1">Avg Handle Time by TSA</h2>
            <p class="text-xs font-mono text-slate-400 mb-4">Average call duration, per TSA — missed calls excluded</p>
            <div class="h-64">
                <canvas id="chartAhtByTsa" role="img" aria-label="Bar chart comparing average call duration per TSA — see the table below for exact figures"></canvas>
            </div>
        </div>
        <div class="bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-700 shadow-sm p-5">
            <h2 class="text-sm font-bold text-slate-800 dark:text-slate-100 font-mono mb-1">AHT Trend</h2>
            <p class="text-xs font-mono text-slate-400 mb-4">Team-wide average call duration, day by day</p>
            <div class="h-64">
                <canvas id="chartAhtTrend" role="img" aria-label="Line chart showing the team's average call duration for each day in the selected range"></canvas>
            </div>
        </div>
    </div>

    <div class="bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-700 shadow-sm overflow-hidden">
        <div class="overflow-x-auto">
        <table class="w-full text-sm font-mono">
            <thead class="bg-slate-100 dark:bg-slate-700 border-b border-slate-200 dark:border-slate-700">
                <tr>
                    <th class="px-4 py-3 text-left text-[11px] font-bold text-slate-400 uppercase tracking-wide">TSA</th>
                    <th class="px-4 py-3 text-right text-[11px] font-bold text-slate-400 uppercase tracking-wide">Calls (with duration)</th>
                    <th class="px-4 py-3 text-right text-[11px] font-bold text-slate-400 uppercase tracking-wide">AHT</th>
                    <th class="px-4 py-3 text-right text-[11px] font-bold text-slate-400 uppercase tracking-wide">Total Handled Time</th>
                    <th class="px-4 py-3 text-right text-[11px] font-bold text-slate-400 uppercase tracking-wide">Logged-in Time</th>
                    <th class="px-4 py-3 text-right text-[11px] font-bold text-slate-400 uppercase tracking-wide">Unproductive Time</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-100 dark:divide-slate-700">
                @foreach($rows as $row)
                <tr class="hover:bg-slate-50 dark:hover:bg-slate-800">
                    <td class="px-4 py-3 font-semibold text-slate-700 dark:text-slate-200">{{ $row['tsa']->display_name }}</td>
                    <td class="px-4 py-3 text-right text-slate-600 dark:text-slate-300">{{ $row['aht_call_count'] }}</td>
                    <td class="px-4 py-3 text-right text-slate-600 dark:text-slate-300">
                        @if($row['aht_seconds'] !== null)
                            {{ intdiv($row['aht_seconds'], 60) }}m {{ $row['aht_seconds'] % 60 }}s
                        @else
                            —
                        @endif
                    </td>
                    <td class="px-4 py-3 text-right text-slate-600 dark:text-slate-300">
                        {{ intdiv($row['tht_seconds'], 60) }}m {{ $row['tht_seconds'] % 60 }}s
                    </td>
                    <td class="px-4 py-3 text-right text-slate-600 dark:text-slate-300">
                        {{ intdiv($row['logged_in_minutes'], 60) }}h {{ $row['logged_in_minutes'] % 60 }}m
                        <span class="text-slate-400 dark:text-slate-500">({{ $row['working_days'] }}d)</span>
                    </td>
                    <td class="px-4 py-3 text-right text-slate-600 dark:text-slate-300">
                        {{ intdiv((int) round($row['unproductive_minutes']), 60) }}h {{ (int) round($row['unproductive_minutes']) % 60 }}m
                        @if($row['unproductive_ratio'] !== null)
                            <span class="text-slate-400 dark:text-slate-500">({{ $row['unproductive_ratio'] }}%)</span>
                        @endif
                    </td>
                </tr>
                @endforeach
            </tbody>
        </table>
        </div>
    </div>
    @endif

@endsection
