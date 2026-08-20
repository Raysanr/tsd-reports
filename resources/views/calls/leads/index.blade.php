@extends('layouts.calls')
@section('title', $view === 'overdue' ? 'Overdue Leads' : ($view === 'callbacks' ? "Today's Callbacks" : 'Leads'))
@section('subtitle', match($view) {
    'overdue'   => "Assigned but not called within {$overdueThresholdHours}h",
    'callbacks' => 'Callbacks due now or already past due',
    default     => 'Round-robin assigned leads · click to call',
})

@section('content')

<script>
// Filters only ever live in this page's own URL (?date_from=&date_to=&tsa=) —
// nothing persists them on its own. The sidebar's Leads/Overdue/Callbacks
// links carry them forward directly (layouts/calls.blade.php), but that only
// covers moving between THOSE three — clicking away to Dashboard/Analytics/
// etc. and back to Leads lands on a bare URL with nothing to forward from,
// same problem a whole new session has. Explicit request (2026-08-14 for
// dates, extended 2026-08-15 to tsa too): remember the last-applied filters
// across either kind of round trip via localStorage.
//
// date_from/date_to: synced whenever BOTH are in the URL; restored together
// when neither is (there's no "clear the date" affordance, so any URL
// missing them is presumed a fresh/bare link, not a deliberate clear).
//
// tsa: synced whenever present in the URL, INCLUDING empty ("All TSAs" is a
// real, explicitly selectable option, not just "no signal yet") — but only
// restored when the tsa key is missing from the URL entirely, so picking
// "All TSAs" (which submits tsa= with an empty value) is never silently
// overridden by an old saved value.
(function () {
    const params = new URLSearchParams(window.location.search);
    const from = params.get('date_from');
    const to   = params.get('date_to');
    const hasTsa = params.has('tsa');

    if (from && to) localStorage.setItem('callsLeadsDateRange', JSON.stringify({ from, to }));
    if (hasTsa) localStorage.setItem('callsLeadsTsa', params.get('tsa') || '');

    let needsRedirect = false;

    if (!(from && to)) {
        try {
            const saved = JSON.parse(localStorage.getItem('callsLeadsDateRange') || 'null');
            if (saved?.from && saved?.to) {
                params.set('date_from', saved.from);
                params.set('date_to', saved.to);
                needsRedirect = true;
            }
        } catch (e) { /* corrupt/old value — ignore, falls back to today */ }
    }

    if (!hasTsa) {
        const savedTsa = localStorage.getItem('callsLeadsTsa');
        if (savedTsa) {
            params.set('tsa', savedTsa);
            needsRedirect = true;
        }
    }

    if (needsRedirect) window.location.replace(window.location.pathname + '?' + params.toString());
})();
</script>

<div class="mb-6 flex items-center gap-3 flex-wrap">
    <form method="GET" class="flex items-center gap-3 flex-wrap">
        @if($view)<input type="hidden" name="view" value="{{ $view }}">@endif

        @if(auth()->user()->isAtLeastAdmin())
        <select name="tsa" onchange="this.form.submit()"
                class="text-sm font-mono border border-slate-300 dark:border-slate-600 rounded-lg px-3 py-2 bg-white dark:bg-slate-800 text-slate-800 dark:text-slate-100 focus:outline-none focus:ring-2 focus:ring-yellow-500">
            <option value="">All TSAs</option>
            @foreach($tsas as $tsa)
            <option value="{{ $tsa->id }}" @selected($selectedTsa === $tsa->id)>{{ $tsa->display_name }}</option>
            @endforeach
        </select>

        {{-- Explicit request (2026-08-20): replaces the old Assigned/Called/
             Unassigned lead-status filter — narrows by the ASSIGNED TSA'S
             CURRENT status instead (Login/Break/Calling/etc), now that TSA
             Management's own per-row status column was removed the same
             day. Admin-only, like the TSA selector above it — a self-viewing
             TSA only ever sees their own single TSA's leads, so "which TSA
             status" isn't a meaningful filter for them the way it is for an
             admin looking across the whole roster.

             Custom dropdown (not a plain native <select>, explicit request
             2026-08-20) — same colored-dot-per-status visual language as
             Monitor TSA and the topbar's own status panel, rather than the
             browser's generic select-list styling. A real hidden input
             carries the actual `status` value the surrounding GET form
             submits; clicking an option just sets that input and submits,
             same end result as the old <select onchange="submit()">. --}}
        @if(!$view)
        @php
            $statusDotClass = fn (string $s) => match ($s) {
                \App\Models\TsaShift::STATUS_LOGIN      => 'bg-emerald-500',
                \App\Models\TsaShift::STATUS_CALLING    => 'bg-red-500',
                \App\Models\TsaShift::STATUS_WRAP_UP    => 'bg-orange-500',
                \App\Models\TsaShift::STATUS_BREAK      => 'bg-yellow-400',
                \App\Models\TsaShift::STATUS_LUNCH      => 'bg-amber-800',
                \App\Models\TsaShift::STATUS_COACHING   => 'bg-blue-500',
                \App\Models\TsaShift::STATUS_DNA_HUDDLE => 'bg-purple-500',
                \App\Models\TsaShift::STATUS_HUDDLE     => 'bg-sky-400',
                \App\Models\TsaShift::STATUS_LOGOUT     => 'bg-slate-300 dark:bg-slate-600',
                default => 'bg-slate-300 dark:bg-slate-600',
            };
        @endphp
        <div class="relative" data-status-filter-wrap>
            <input type="hidden" name="status" value="{{ $selectedStatus }}" data-status-filter-input>
            <button type="button" data-status-filter-trigger
                    class="inline-flex items-center gap-2 text-sm font-mono font-semibold text-slate-700 dark:text-slate-200 border border-slate-300 dark:border-slate-600 rounded-lg px-3 py-2 bg-white dark:bg-slate-800 hover:bg-slate-50 dark:hover:bg-slate-700 cursor-pointer">
                <span class="w-2 h-2 rounded-full shrink-0 {{ $selectedStatus ? $statusDotClass($selectedStatus) : 'bg-slate-300 dark:bg-slate-600' }}" data-status-filter-dot></span>
                <span data-status-filter-label>{{ $selectedStatus ? (\App\Models\TsaShift::STATUSES[$selectedStatus]['label'] ?? $selectedStatus) : 'All statuses' }}</span>
                <svg class="w-3.5 h-3.5 text-slate-400" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M19 9l-7 7-7-7"/>
                </svg>
            </button>

            <div class="hidden fixed z-50 bg-white dark:bg-slate-900 rounded-2xl shadow-2xl border border-slate-200 dark:border-slate-700 w-64 max-h-96 overflow-y-auto" data-status-filter-panel>
                <div class="py-1">
                    <div class="status-filter-option flex items-center gap-3 px-4 py-2.5 cursor-pointer hover:bg-slate-50 dark:hover:bg-slate-800" data-value="">
                        <span class="w-2 h-2 rounded-full shrink-0 bg-slate-300 dark:bg-slate-600"></span>
                        <span class="flex-1 text-sm font-semibold text-slate-700 dark:text-slate-200 font-mono">All statuses</span>
                        @if(!$selectedStatus)
                        <svg class="w-4 h-4 text-primary shrink-0" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7"/></svg>
                        @endif
                    </div>
                    @foreach(\App\Models\TsaShift::LEAD_FILTER_STATUSES as $s)
                    <div class="status-filter-option flex items-center gap-3 px-4 py-2.5 cursor-pointer hover:bg-slate-50 dark:hover:bg-slate-800" data-value="{{ $s }}">
                        <span class="w-2 h-2 rounded-full shrink-0 {{ $statusDotClass($s) }}"></span>
                        <span class="flex-1 text-sm font-semibold text-slate-700 dark:text-slate-200 font-mono">{{ \App\Models\TsaShift::STATUSES[$s]['label'] }}</span>
                        @if($selectedStatus === $s)
                        <svg class="w-4 h-4 text-primary shrink-0" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7"/></svg>
                        @endif
                    </div>
                    @endforeach
                </div>
            </div>
        </div>
        @endif

        <div class="relative">
            <svg class="w-4 h-4 text-slate-300 dark:text-slate-600 absolute left-3 top-1/2 -translate-y-1/2 pointer-events-none" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" d="M21 21l-4.35-4.35M18 10.5a7.5 7.5 0 11-15 0 7.5 7.5 0 0115 0z"/>
            </svg>
            <input type="text" name="q" value="{{ $q }}" placeholder="Search name, phone, order ID…"
                   class="text-sm font-mono border border-slate-300 dark:border-slate-600 rounded-lg pl-9 pr-3 py-2 bg-white dark:bg-slate-800 text-slate-800 dark:text-slate-100 focus:outline-none focus:ring-2 focus:ring-yellow-500 w-64">
        </div>
        <button type="submit" class="text-sm font-mono font-semibold text-white bg-primary hover:bg-primary-dark rounded-lg px-4 py-2 cursor-pointer">Search</button>

        @include('partials.date-picker', [
            'mode' => 'range', 'id' => 'callsLeadsDrp',
            'dateFrom' => \Illuminate\Support\Carbon::parse($dateFrom ?: now()),
            'dateTo'   => \Illuminate\Support\Carbon::parse($dateTo ?: now()),
        ])
        @endif
    </form>
</div>

<div id="leads-table-container" data-poll-url="{{ url()->full() }}">
    @include('calls.leads._table')
</div>

@include('calls.partials.modals')

@endsection
