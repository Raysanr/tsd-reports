@extends('layouts.calls')
@section('title', 'Call Tracker')
@section('subtitle', $isToday
    ? "Today's overview \u{00b7} " . now()->format('M j, Y')
    : "Overview \u{00b7} " . $dateFrom->format('M j') . ' – ' . $dateTo->format('M j, Y'))

@push('topbar-right')
{{-- Every KPI card + Recent Activity move with this; TSA Status/At-Risk
     Products don't (see DashboardController::index()'s own comment on why).
     Reuses tsd-reports' own shared date-picker partial (submit='navigate' —
     this page has no wrapping <form> to hook into). --}}
@include('partials.date-picker', [
    'mode' => 'range', 'id' => 'callsDashDrp',
    'dateFrom' => $dateFrom, 'dateTo' => $dateTo,
    'submit' => 'navigate', 'navigateBase' => route('calls.dashboard'),
])
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

{{-- Today's lead funnel + upsells — calls/partials/stat-tile.blade.php,
     same icon-badge KPI card as TSD Reports' own Dashboard. --}}
<div class="grid grid-cols-2 lg:grid-cols-3 gap-3 sm:gap-5 mb-6">
    @include('calls.partials.stat-tile', ['label' => $isToday ? 'Assigned Today' : 'Assigned', 'value' => $funnel['assigned'], 'icon' => 'inbox', 'color' => 'text-blue-600 dark:text-blue-400 bg-blue-50 dark:bg-blue-950/40', 'caption' => null])
    @include('calls.partials.stat-tile', ['label' => $isToday ? 'Called Today' : 'Called', 'value' => $funnel['called'], 'icon' => 'phone', 'color' => 'text-emerald-600 dark:text-emerald-400 bg-emerald-50 dark:bg-emerald-950/40', 'caption' => null])
    @include('calls.partials.stat-tile', ['label' => $isToday ? 'Overdue Today' : 'Overdue', 'value' => $funnel['overdue'], 'icon' => 'clock', 'color' => 'text-red-600 dark:text-red-400 bg-red-50 dark:bg-red-950/40', 'caption' => null])
    @include('calls.partials.stat-tile', ['label' => $isToday ? 'Callbacks Today' : 'Callbacks', 'value' => $funnel['callbacks'], 'icon' => 'calendar', 'color' => 'text-orange-600 dark:text-orange-400 bg-orange-50 dark:bg-orange-950/40', 'caption' => null])

    @if(auth()->user()->isAtLeastAdmin())
    @include('calls.partials.stat-tile', ['label' => $isToday ? 'Unassigned Today' : 'Unassigned', 'value' => $funnel['unassigned'], 'icon' => 'warning', 'color' => 'text-slate-600 dark:text-slate-300 bg-slate-100 dark:bg-slate-800', 'caption' => null])
    @endif

    @include('calls.partials.stat-tile', [
        'label' => $isToday ? 'Upsells Today' : 'Upsells', 'value' => $upsellStats['count'], 'icon' => 'plus',
        'color' => 'text-primary-dark bg-yellow-50 dark:bg-yellow-950/40', 'caption' => '₱' . number_format($upsellStats['amount'], 2),
    ])
</div>

<div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
    {{-- TSA status board — every active TSA, real-time. Same status colors
         as the topbar/Call Rotation panel (calls/partials/tsa-status-panel),
         color + text label together, never color alone. Avatar-initial
         chips give each row a scannable anchor instead of a bare dot. --}}
    <div class="lg:col-span-2 bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-700 shadow-sm overflow-hidden">
        <div class="px-5 py-3.5 border-b border-slate-100 dark:border-slate-700 flex items-center justify-between">
            <h2 class="text-sm font-bold text-slate-800 dark:text-slate-100 font-mono">TSA Status</h2>
            <span class="inline-flex items-center gap-1.5 text-[10px] font-mono font-bold text-emerald-700 dark:text-emerald-400 bg-emerald-50 dark:bg-emerald-950/40 px-2 py-1 rounded-full">
                <span class="w-1.5 h-1.5 rounded-full bg-emerald-500 animate-pulse"></span>
                {{ $tsas->where('status', \App\Models\TsaShift::STATUS_LOGIN)->count() }} ONLINE
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
        <div class="divide-y divide-slate-100 dark:divide-slate-700">
            @foreach($tsas as $tsa)
            <div class="flex items-center justify-between gap-3 px-5 py-3">
                <div class="flex items-center gap-3 min-w-0">
                    <div class="relative shrink-0">
                        <div class="w-8 h-8 rounded-full bg-slate-800 dark:bg-slate-700 text-white flex items-center justify-center text-[11px] font-bold font-mono">
                            {{ strtoupper(substr($tsa->display_name, 0, 2)) }}
                        </div>
                        <span class="absolute -bottom-0.5 -right-0.5 w-2.5 h-2.5 rounded-full ring-2 ring-white dark:ring-slate-900 {{ $statusDot($tsa->status) }}"></span>
                    </div>
                    <div class="min-w-0">
                        <p class="text-sm font-semibold text-slate-800 dark:text-slate-100 truncate">{{ $tsa->display_name }}</p>
                        <p class="text-[11px] text-slate-400 font-mono truncate">{{ $tsa->team }}</p>
                    </div>
                </div>
                <div class="text-right shrink-0">
                    <span class="inline-flex px-2 py-0.5 rounded-full text-[10px] font-bold uppercase tracking-wide {{ $statusBadge($tsa->status) }}">
                        {{ $statuses[$tsa->status]['label'] ?? $tsa->status }}
                    </span>
                    @if($tsa->status_changed_at)
                    <p class="text-[10px] text-slate-400 font-mono mt-1 tabular-nums">since {{ $tsa->status_changed_at->format('g:i A') }}</p>
                    @endif
                </div>
            </div>
            @endforeach
        </div>
        @endif
    </div>

    {{-- Recent activity — lead activity + TSA status changes merged into
         one real-recency timeline (DashboardController::index()'s own
         comment on why). Rendered as a connected timeline (dot + line) so
         the eye reads it as a sequence of events, not a flat log dump. --}}
    <div class="bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-700 shadow-sm overflow-hidden">
        <div class="px-5 py-3.5 border-b border-slate-100 dark:border-slate-700">
            <h2 class="text-sm font-bold text-slate-800 dark:text-slate-100 font-mono">Recent Activity</h2>
        </div>
        @if($recentActivity->isEmpty())
        <div class="py-16 flex flex-col items-center justify-center gap-3">
            <svg class="w-9 h-9 text-slate-200 dark:text-slate-700" fill="none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" d="M12 6v6h4.5m4.5 0a9 9 0 11-18 0 9 9 0 0118 0z"/>
            </svg>
            <p class="text-sm font-mono text-slate-400">Nothing yet today.</p>
        </div>
        @else
        <ul class="max-h-105 overflow-y-auto py-1">
            @foreach($recentActivity as $item)
            <li class="relative pl-9 pr-5 py-2.5">
                <span class="absolute left-5.25 top-0 bottom-0 w-0.5 bg-slate-200 dark:bg-slate-700 {{ $loop->last ? 'hidden' : '' }}"></span>
                <span class="absolute left-4.25 top-3.25 w-2.5 h-2.5 rounded-full ring-4 ring-white dark:ring-slate-900 {{ $item['kind'] === 'status' ? 'bg-amber-500' : 'bg-blue-500' }}"></span>
                <p class="text-xs text-slate-800 dark:text-slate-100 font-mono leading-snug">{{ $item['description'] }}</p>
                <p class="text-[10px] text-slate-400 font-mono mt-1">{{ $item['at']->diffForHumans() }}</p>
            </li>
            @endforeach
        </ul>
        @endif
    </div>
</div>

@endsection
