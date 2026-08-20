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

        {{-- Explicit request (2026-08-20): this is what replaces TSA
             Management's old per-row status control — NOT a filter on this
             list (that's what the first attempt at this got wrong). Picking
             a TSA above reveals this same tsa-status-panel component TSA
             Management used to render per-row (target = that TSA's id,
             options = SELF_SERVICE_STATUSES — the same Login/Break/
             Coaching/DNA Huddle/Huddle/Logout set it always offered),
             letting an admin change THAT TSA's status right here instead of
             a separate page. Only makes sense once a specific TSA is
             picked — "All TSAs" has no single status to show/change, so
             this stays hidden until one is. --}}
        @if(!$view && $selectedTsa)
        @php $statusPanelTsa = $tsas->firstWhere('id', $selectedTsa); @endphp
        @if($statusPanelTsa)
        @include('calls.partials.tsa-status-panel', [
            'id'      => 'leads-tsa-filter',
            'options' => \App\Models\TsaShift::SELF_SERVICE_STATUSES,
            'current' => $statusPanelTsa->status,
            'target'  => (string) $statusPanelTsa->id,
        ])
        @endif
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
