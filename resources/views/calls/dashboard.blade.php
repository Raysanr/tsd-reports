@extends('layouts.calls')
@section('title', 'Call Tracker')
@section('subtitle', $isToday
    ? "Today's overview \u{00b7} " . now()->format('M j, Y')
    : "Overview \u{00b7} " . $dateFrom->format('M j') . ' – ' . $dateTo->format('M j, Y'))

@push('topbar-right')
{{-- Same ALL/SH Naturals/Eyecare filter as TSD Reports' own Dashboard
     (explicit request, 2026-08-17) — a plain GET form carrying the current
     date range along as hidden fields, so switching teams never resets it
     back to today (same reasoning as that page's own team filter). --}}
<form method="GET" action="{{ route('calls.dashboard') }}" class="contents">
    <input type="hidden" name="date_from" value="{{ $dateFrom->toDateString() }}">
    <input type="hidden" name="date_to" value="{{ $dateTo->copy()->startOfDay()->toDateString() }}">
    <div class="flex rounded-lg border border-slate-300 dark:border-slate-600 overflow-hidden">
        @foreach($teams as $key => $label)
        <button type="submit" name="team" value="{{ $key }}"
                class="px-3 py-1.5 text-xs font-semibold font-mono cursor-pointer transition-colors duration-200
                       {{ $selectedTeam === $key ? 'bg-primary text-white' : 'bg-white dark:bg-slate-900 text-slate-500 dark:text-slate-400 hover:bg-slate-50 dark:hover:bg-slate-800' }}">
            {{ $label }}
        </button>
        @endforeach
    </div>
</form>

{{-- Every KPI card + Recent Activity move with this; TSA Status/At-Risk
     Products don't (see DashboardController::index()'s own comment on why).
     Reuses tsd-reports' own shared date-picker partial (submit='navigate' —
     this page has no wrapping <form> to hook into). --}}
@include('partials.date-picker', [
    'mode' => 'range', 'id' => 'callsDashDrp',
    'dateFrom' => $dateFrom, 'dateTo' => $dateTo,
    'submit' => 'navigate', 'navigateBase' => route('calls.dashboard'),
])

{{-- Sync — kicks off pancake:sync-leads in the background and polls for the
     result (DashboardController::sync()/syncStatus()), same icon-only
     lightning-bolt convention as TSD Reports' own Dashboard sync button. --}}
<button id="callsSyncBtn" type="button" title="Sync — fetch the latest leads from Pancake now"
        aria-label="Sync leads from Pancake"
        class="inline-flex items-center justify-center w-8 h-8 bg-yellow-600 hover:bg-yellow-700 text-white rounded-full transition-colors cursor-pointer shrink-0">
    <svg id="callsSyncIcon" class="w-4 h-4" fill="currentColor" viewBox="0 0 24 24">
        <path fill-rule="evenodd" d="M14.615 1.595a.75.75 0 01.359.852L12.982 9.75h7.268a.75.75 0 01.548 1.262l-10.5 11.25a.75.75 0 01-1.272-.71l1.992-7.302H3.75a.75.75 0 01-.548-1.262l10.5-11.25a.75.75 0 01.913-.143z" clip-rule="evenodd"/>
    </svg>
</button>
@endpush

@push('scripts')
<script>
(function () {
    const syncBtn = document.getElementById('callsSyncBtn');
    if (!syncBtn) return;
    const syncIcon = document.getElementById('callsSyncIcon');

    const MAX_POLL_ATTEMPTS = 60; // ~2 minutes at 2s apart — pancake:sync-leads is a lighter fetch than the Order sync

    function pollStatus(since, attempt = 0) {
        if (attempt >= MAX_POLL_ATTEMPTS) {
            window.showToast?.('Sync is taking longer than expected — check back shortly.', 'error');
            finish();
            return;
        }
        fetch(`{{ route('calls.dashboard.sync.status') }}?since=${encodeURIComponent(since)}`)
            .then((r) => (r.ok ? r.json() : Promise.reject()))
            .then((data) => {
                if (!data.done) {
                    setTimeout(() => pollStatus(since, attempt + 1), 2000);
                    return;
                }
                if (data.success) {
                    window.showToast?.(`Synced — ${data.new_leads} new lead${data.new_leads === 1 ? '' : 's'}.`, 'success');
                } else {
                    window.showToast?.(`Sync failed: ${data.error_message || 'Unknown error'}`, 'error');
                }
                finish();
            })
            .catch(() => {
                window.showToast?.('Sync failed: request error.', 'error');
                finish();
            });
    }

    function finish() {
        syncBtn.disabled = false;
        syncIcon.classList.remove('animate-pulse');
    }

    syncBtn.addEventListener('click', () => {
        syncBtn.disabled = true;
        syncIcon.classList.add('animate-pulse');
        fetch('{{ route('calls.dashboard.sync') }}', {
            method: 'POST',
            headers: { 'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content },
        })
            .then((r) => (r.ok ? r.json() : Promise.reject()))
            .then((data) => pollStatus(data.since))
            .catch(() => {
                window.showToast?.('Could not start sync.', 'error');
                finish();
            });
    });
})();
</script>
@endpush

@section('content')

@php
$statusDot = fn($status) => match(true) {
    $status === \App\Models\TsaShift::STATUS_LOGIN  => 'bg-emerald-500',
    $status === \App\Models\TsaShift::STATUS_LOGOUT => 'bg-slate-300 dark:bg-slate-600',
    $status === \App\Models\TsaShift::STATUS_LOCKED => 'bg-red-500',
    default => 'bg-amber-500',
};
$statusBadge = fn($status) => match(true) {
    $status === \App\Models\TsaShift::STATUS_LOGIN  => 'bg-emerald-100 dark:bg-emerald-900/40 text-emerald-700 dark:text-emerald-400',
    $status === \App\Models\TsaShift::STATUS_LOGOUT => 'bg-slate-100 dark:bg-slate-700 text-slate-600 dark:text-slate-300',
    $status === \App\Models\TsaShift::STATUS_LOCKED => 'bg-red-100 dark:bg-red-900/40 text-red-700 dark:text-red-400',
    default => 'bg-amber-100 dark:bg-amber-900/40 text-amber-700 dark:text-amber-400',
};
@endphp

{{-- Round-robin risk warning — only a product actually missing coverage
     right now shows here. Icon + text + color, never color alone. --}}
@if($atRiskProducts->isNotEmpty())
<div class="mb-6 bg-red-50 dark:bg-red-950/40 border border-red-200 dark:border-red-800 rounded-2xl px-5 py-4 flex items-start gap-3">
    <svg class="w-5 h-5 text-red-500 shrink-0 mt-0.5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
        <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m-9.303 3.376c-.866 1.5.217 3.374 1.948 3.374h14.71c1.73 0 2.813-1.874 1.948-3.374L13.949 3.378c-.866-1.5-3.032-1.5-3.898 0L2.697 16.126zM12 15.75h.007v.008H12v-.008z"/>
    </svg>
    <div class="min-w-0">
        <p class="text-sm font-bold text-red-800 dark:text-red-400 font-mono">No TSA currently logged in for {{ $atRiskProducts->count() }} {{ \Illuminate\Support\Str::plural('product', $atRiskProducts->count()) }} — new leads won't be assigned</p>
        <p class="text-xs text-red-600 dark:text-red-400 font-mono mt-1">
            {{ $atRiskProducts->pluck('display_name')->join(', ', ' and ') }}. Round-robin will resume automatically once any TSA on the roster switches back to Login.
        </p>
    </div>
</div>
@endif

{{-- KPI row — explicit request (2026-08-18): matches a KPI-dashboard
     reference image's 5 cards exactly (TSA Log In, Total Leads, Total
     Catered Leads, AHT, Unproductive Time), replacing the previous funnel
     row. TSA Log In stays unlabeled "Today" (it's live, not range-scoped —
     see DashboardController::index()'s own comment); the other 4 pick up
     the isToday-aware suffix the old cards used. --}}
<div class="grid grid-cols-2 lg:grid-cols-5 gap-3 sm:gap-5 mb-6">
    @include('calls.partials.stat-tile', ['label' => 'TSA Log In', 'value' => $tsaLoginCount, 'icon' => 'user', 'color' => 'text-yellow-600 dark:text-yellow-400 bg-yellow-50 dark:bg-yellow-950/40', 'underline' => 'bg-yellow-500', 'caption' => 'Total TSA logged in'])
    @include('calls.partials.stat-tile', ['label' => $isToday ? 'Total Leads Today' : 'Total Leads', 'value' => $totalLeads, 'icon' => 'inbox', 'color' => 'text-amber-600 dark:text-amber-400 bg-amber-50 dark:bg-amber-950/40', 'underline' => 'bg-amber-500', 'caption' => 'Every lead created, assigned or not'])
    @include('calls.partials.stat-tile', ['label' => $isToday ? 'Total Catered Today' : 'Total Catered Leads', 'value' => $totalCateredLeads, 'icon' => 'headset', 'color' => 'text-emerald-600 dark:text-emerald-400 bg-emerald-50 dark:bg-emerald-950/40', 'underline' => 'bg-emerald-500', 'caption' => 'Total leads catered'])
    @include('calls.partials.stat-tile', ['label' => 'AHT', 'value' => $ahtDisplay, 'icon' => 'stopwatch', 'color' => 'text-blue-600 dark:text-blue-400 bg-blue-50 dark:bg-blue-950/40', 'underline' => 'bg-blue-500', 'caption' => 'Average handle time (mm:ss)'])
    @include('calls.partials.stat-tile', ['label' => 'Unproductive Time', 'value' => $unproductiveDisplay, 'icon' => 'hourglass', 'color' => 'text-red-600 dark:text-red-400 bg-red-50 dark:bg-red-950/40', 'underline' => 'bg-red-500', 'caption' => 'Average unproductive time (mm:ss)'])
</div>

{{-- Overview charts — bar/donut reshape the same Total Leads/Catered Leads
     numbers already in the KPI cards above (never a separate source of
     truth); the trend line is AHT & Unproductive Time today, hour by hour,
     its own always-on window (see DashboardController::index()'s own
     comment on why) — same source data as Team Analytics' own AHT tab,
     aggregated for the team in scope instead of broken out per TSA. --}}
<script type="application/json" id="dashboardChartData">{!! json_encode($chartData) !!}</script>

<div class="grid grid-cols-1 lg:grid-cols-4 gap-4 mb-6">
    <div class="lg:col-span-1 bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-700 shadow-sm p-5">
        <h2 class="text-sm font-bold text-slate-800 dark:text-slate-100 font-mono mb-1">Leads Overview</h2>
        <p class="text-xs font-mono text-slate-400 mb-4">Total leads vs. catered{{ $isToday ? ' today' : ' this range' }}</p>
        @if(!$chartData['hasOverviewData'])
        <div id="dashboardOverviewEmpty" class="h-48 flex items-center justify-center text-center px-2">
            <p class="text-xs font-mono text-slate-400">No leads in this range yet.</p>
        </div>
        @else
        <div id="dashboardOverviewWrap" class="h-48">
            <canvas id="chartLeadsOverview" role="img" aria-label="Bar chart comparing total leads against total catered leads — see the KPI cards above for exact figures"></canvas>
        </div>
        @endif
    </div>

    <div class="lg:col-span-1 bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-700 shadow-sm p-5">
        <h2 class="text-sm font-bold text-slate-800 dark:text-slate-100 font-mono mb-1">Catered Leads Rate</h2>
        <p class="text-xs font-mono text-slate-400 mb-4">Share of {{ $isToday ? "today's" : "this range's" }} leads catered to</p>
        @if(!$chartData['hasOverviewData'])
        <div class="h-48 flex items-center justify-center text-center px-2">
            <p class="text-xs font-mono text-slate-400">No leads in this range yet.</p>
        </div>
        @else
        <div class="relative h-48">
            <canvas id="chartLeadsSplit" role="img" aria-label="Donut chart showing the catered leads rate — see the KPI cards above for exact figures"></canvas>
            <div class="absolute inset-0 flex items-center justify-center pointer-events-none">
                <span class="text-2xl font-bold text-slate-900 dark:text-slate-100 font-mono">{{ $chartData['cateredRate'] !== null ? $chartData['cateredRate'].'%' : '—' }}</span>
            </div>
        </div>
        @endif
    </div>

    <div class="lg:col-span-2 bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-700 shadow-sm p-5">
        <h2 class="text-sm font-bold text-slate-800 dark:text-slate-100 font-mono mb-1">AHT &amp; Unproductive Time Trend</h2>
        <p class="text-xs font-mono text-slate-400 mb-4">Team averages per hour, today</p>
        @if(!$chartData['hasTrendData'])
        <div id="dashboardTrendEmpty" class="h-48 flex items-center justify-center text-center px-2">
            <p class="text-xs font-mono text-slate-400">No logged calls today yet.</p>
        </div>
        @else
        <div id="dashboardTrendWrap" class="h-48">
            <canvas id="chartTrend" role="img" aria-label="Line chart tracking average handle time and average unproductive time per hour, today"></canvas>
        </div>
        @endif
    </div>
</div>

{{-- TSA Performance Overview — explicit request (2026-08-18), replaces the
     TSA Status + Recent Activity two-panel row with one full-roster table
     matching the KPI-dashboard reference image's own bottom table: live
     login status (same $statusDot/$statusBadge as the panel this replaces,
     color + text together, never color alone) plus this same date range's
     Total Leads/Catered/AHT/Unproductive/Catered Rate per TSA
     (DashboardController::index()'s own comment on scope/why), a solid
     yellow header and a black TOTAL row matching the reference exactly. --}}
<div class="bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-700 shadow-sm overflow-hidden">
    <div class="px-5 py-3.5 border-b border-slate-100 dark:border-slate-700 flex items-center justify-between gap-3">
        <div class="min-w-0">
            <h2 class="text-sm font-bold text-slate-800 dark:text-slate-100 font-mono">TSA Performance Overview</h2>
            {{-- Explicit follow-up (2026-09-01): the KPI card above counts
                 every lead CREATED in this range, including ones no TSA
                 has picked up yet; this table counts leads actually
                 ASSIGNED to a TSA in this range (round-robin output) —
                 a smaller number by design (DashboardController::index()'s
                 own comment on why), not a bug. Spelled out here so that
                 doesn't need re-discovering every time the two disagree. --}}
            <p class="text-[11px] font-mono text-slate-400 mt-0.5">Leads actually assigned to a TSA in this range — usually fewer than the Total Leads card above, which counts every lead created</p>
        </div>
        <span class="inline-flex items-center gap-1.5 text-[10px] font-mono font-bold text-emerald-700 dark:text-emerald-400 bg-emerald-50 dark:bg-emerald-950/40 px-2 py-1 rounded-full shrink-0">
            <span class="w-1.5 h-1.5 rounded-full bg-emerald-500 animate-pulse"></span>
            {{ $tsaLoginCount }} ONLINE
        </span>
    </div>
    @if($tsas->isEmpty())
    <div class="py-16 flex flex-col items-center justify-center gap-3">
        <svg class="w-9 h-9 text-slate-200 dark:text-slate-700" fill="none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" d="M17 20h5v-2a4 4 0 00-3-3.87M9 20H4v-2a4 4 0 013-3.87m6-8.13a4 4 0 110 8 4 4 0 010-8zm6 8a4 4 0 00-3-3.87M5 12a4 4 0 013.87-3"/>
        </svg>
        <p class="text-sm font-mono text-slate-400">No active TSAs configured.</p>
        <p class="text-xs font-mono text-slate-300 dark:text-slate-600">Add TSAs from the Call Rotation page.</p>
    </div>
    @else
    <div class="overflow-x-auto">
    <table class="w-full text-sm font-mono border-separate border-spacing-0">
        <thead class="sticky top-0 z-10">
            <tr>
                <th class="px-4 py-3 text-left text-[11px] font-bold text-slate-900 uppercase tracking-wide bg-yellow-500 dark:bg-yellow-600 border-b-2 border-yellow-600 dark:border-yellow-700">TSA</th>
                <th class="px-4 py-3 text-left text-[11px] font-bold text-slate-900 uppercase tracking-wide bg-yellow-500 dark:bg-yellow-600 border-b-2 border-yellow-600 dark:border-yellow-700">TSA Log In</th>
                <th class="px-4 py-3 text-right text-[11px] font-bold text-slate-900 uppercase tracking-wide bg-yellow-500 dark:bg-yellow-600 border-b-2 border-yellow-600 dark:border-yellow-700">Total Leads</th>
                <th class="px-4 py-3 text-right text-[11px] font-bold text-slate-900 uppercase tracking-wide bg-yellow-500 dark:bg-yellow-600 border-b-2 border-yellow-600 dark:border-yellow-700">Total Catered Leads</th>
                <th class="px-4 py-3 text-right text-[11px] font-bold text-slate-900 uppercase tracking-wide bg-yellow-500 dark:bg-yellow-600 border-b-2 border-yellow-600 dark:border-yellow-700">AHT (mm:ss)</th>
                <th class="px-4 py-3 text-right text-[11px] font-bold text-slate-900 uppercase tracking-wide bg-yellow-500 dark:bg-yellow-600 border-b-2 border-yellow-600 dark:border-yellow-700">Unproductive Time (mm:ss)</th>
                <th class="px-4 py-3 text-right text-[11px] font-bold text-slate-900 uppercase tracking-wide bg-yellow-500 dark:bg-yellow-600 border-b-2 border-yellow-600 dark:border-yellow-700">Catered Leads Rate</th>
            </tr>
        </thead>
        <tbody>
            @foreach($tsaPerformance as $row)
            <tr class="even:bg-slate-50/60 dark:even:bg-slate-800/40 hover:bg-yellow-50 dark:hover:bg-slate-700/50 transition-colors">
                <td class="px-4 py-3 border-b border-slate-100 dark:border-slate-700">
                    <div class="flex items-center gap-2.5 min-w-0">
                        <div class="w-7 h-7 rounded-full bg-slate-800 dark:bg-slate-700 text-white flex items-center justify-center text-[10px] font-bold shrink-0 ring-2 ring-white dark:ring-slate-900 shadow-sm">
                            {{ strtoupper(substr($row['tsa']->display_name, 0, 2)) }}
                        </div>
                        <span class="font-semibold text-slate-800 dark:text-slate-100 truncate">{{ $row['tsa']->display_name }}</span>
                    </div>
                </td>
                <td class="px-4 py-3 border-b border-slate-100 dark:border-slate-700">
                    <span class="inline-flex items-center gap-1.5 px-2 py-0.5 rounded-full text-[10px] font-bold uppercase tracking-wide {{ $statusBadge($row['tsa']->status) }}">
                        <span class="w-1.5 h-1.5 rounded-full {{ $statusDot($row['tsa']->status) }}"></span>
                        {{ $statuses[$row['tsa']->status]['label'] ?? $row['tsa']->status }}
                    </span>
                </td>
                <td class="px-4 py-3 text-right text-slate-600 dark:text-slate-300 tabular-nums border-b border-slate-100 dark:border-slate-700">{{ $row['totalLeads'] }}</td>
                <td class="px-4 py-3 text-right text-slate-600 dark:text-slate-300 tabular-nums border-b border-slate-100 dark:border-slate-700">{{ $row['catered'] }}</td>
                <td class="px-4 py-3 text-right text-slate-600 dark:text-slate-300 tabular-nums border-b border-slate-100 dark:border-slate-700">{{ $row['ahtDisplay'] }}</td>
                <td class="px-4 py-3 text-right text-slate-600 dark:text-slate-300 tabular-nums border-b border-slate-100 dark:border-slate-700">{{ $row['unproductiveDisplay'] }}</td>
                <td class="px-4 py-3 border-b border-slate-100 dark:border-slate-700">
                    <div class="flex items-center justify-end gap-2">
                        <div class="w-14 h-1.5 rounded-full bg-slate-200 dark:bg-slate-700 overflow-hidden shrink-0">
                            <div class="h-full rounded-full {{ ($row['cateredRate'] ?? 0) >= 95 ? 'bg-emerald-500' : (($row['cateredRate'] ?? 0) >= 70 ? 'bg-amber-500' : 'bg-red-500') }}"
                                 style="width: {{ $row['cateredRate'] !== null ? min(100, $row['cateredRate']) : 0 }}%"></div>
                        </div>
                        <span class="text-right font-semibold tabular-nums w-12 shrink-0 {{ $row['cateredRate'] !== null && $row['cateredRate'] >= 95 ? 'text-emerald-600 dark:text-emerald-400' : 'text-slate-600 dark:text-slate-300' }}">
                            {{ $row['cateredRate'] !== null ? $row['cateredRate'].'%' : '—' }}
                        </span>
                    </div>
                </td>
            </tr>
            @endforeach
        </tbody>
        <tfoot>
            <tr class="bg-slate-900 dark:bg-black text-white">
                <td class="px-4 py-3.5 font-bold uppercase tracking-wide border-t-2 border-yellow-500" colspan="2">Total</td>
                <td class="px-4 py-3.5 text-right font-bold tabular-nums border-t-2 border-yellow-500">{{ $tsaPerformanceTotal['totalLeads'] }}</td>
                <td class="px-4 py-3.5 text-right font-bold tabular-nums border-t-2 border-yellow-500">{{ $tsaPerformanceTotal['catered'] }}</td>
                <td class="px-4 py-3.5 text-right font-bold tabular-nums border-t-2 border-yellow-500">{{ $tsaPerformanceTotal['ahtDisplay'] }}</td>
                <td class="px-4 py-3.5 text-right font-bold tabular-nums border-t-2 border-yellow-500">{{ $tsaPerformanceTotal['unproductiveDisplay'] }}</td>
                <td class="px-4 py-3.5 text-right font-bold tabular-nums text-emerald-400 border-t-2 border-yellow-500">
                    {{ $tsaPerformanceTotal['cateredRate'] !== null ? $tsaPerformanceTotal['cateredRate'].'%' : '—' }}
                </td>
            </tr>
        </tfoot>
    </table>
    </div>
    @endif
</div>

@endsection
