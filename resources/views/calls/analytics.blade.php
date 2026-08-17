@extends('layouts.calls')
@section('title', 'Call Analytics')
@section('subtitle', 'Call volume, response time, and outcomes per TSA')

@section('content')

<div class="mb-6">
    <form method="GET" class="flex items-center gap-3 flex-wrap">
        <input type="date" name="date_from" value="{{ $dateFrom }}"
               class="text-sm font-mono border border-slate-300 dark:border-slate-600 rounded-lg px-3 py-2 bg-white dark:bg-slate-800 text-slate-800 dark:text-slate-100 focus:outline-none focus:ring-2 focus:ring-yellow-500">
        <span class="text-slate-400 text-sm font-mono">to</span>
        <input type="date" name="date_to" value="{{ $dateTo }}"
               class="text-sm font-mono border border-slate-300 dark:border-slate-600 rounded-lg px-3 py-2 bg-white dark:bg-slate-800 text-slate-800 dark:text-slate-100 focus:outline-none focus:ring-2 focus:ring-yellow-500">
        <button type="submit" class="text-sm font-mono font-semibold text-white bg-primary hover:bg-primary-dark rounded-lg px-4 py-2 cursor-pointer">
            Apply
        </button>
    </form>
</div>

{{-- Chart data — a JSON script tag, not inline JS, so a TSA's display_name
     (free-text, admin-editable) never needs escaping into a JS string
     literal; JSON.parse handles that safely regardless of its contents. --}}
<script type="application/json" id="analyticsChartData">{!! json_encode($chartData) !!}</script>

{{-- Two tabs, plain show/hide (no page reload) — Overview keeps everything
     that was already here; AHT is real per-call duration data from
     CallEvent.duration_seconds (MacroDroid's own call-log report), separate
     from Overview's lead-based numbers since it answers a different
     question ("how long are calls taking", not "how many/how fast"). --}}
<div class="mb-6 border-b border-slate-200 dark:border-slate-700 flex items-center gap-1">
    <button type="button" id="analyticsTabOverviewBtn" onclick="switchAnalyticsTab('overview')"
            class="analytics-tab-btn px-4 py-2.5 text-sm font-mono font-semibold border-b-2 border-primary text-primary-dark cursor-pointer">
        Overview
    </button>
    <button type="button" id="analyticsTabAhtBtn" onclick="switchAnalyticsTab('aht')"
            class="analytics-tab-btn px-4 py-2.5 text-sm font-mono font-semibold border-b-2 border-transparent text-slate-500 dark:text-slate-400 hover:text-slate-700 dark:hover:text-slate-200 cursor-pointer">
        AHT
    </button>
</div>

<div id="analyticsTabOverview">

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

<div class="bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-700 shadow-sm overflow-hidden">
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

</div>{{-- /#analyticsTabOverview --}}

<div id="analyticsTabAht" class="hidden">

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

</div>{{-- /#analyticsTabAht --}}

@endsection
