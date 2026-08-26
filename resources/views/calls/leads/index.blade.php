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
//
// status: same "sync when present (incl. empty), restore only when absent"
// rule as tsa above — brought back 2026-08-21 alongside the filter itself.
// Only ever meaningful on the bare Leads view (LeadController::index()
// already ignores it on Overdue/Callbacks), so restoring it there is
// harmless even though it only really does anything once back on Leads.
(function () {
    const params = new URLSearchParams(window.location.search);
    const from = params.get('date_from');
    const to   = params.get('date_to');
    const hasTsa = params.has('tsa');
    const hasStatus = params.has('status');

    if (from && to) localStorage.setItem('callsLeadsDateRange', JSON.stringify({ from, to }));
    if (hasTsa) localStorage.setItem('callsLeadsTsa', params.get('tsa') || '');
    if (hasStatus) localStorage.setItem('callsLeadsStatus', params.get('status') || '');

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

    if (!hasStatus) {
        const savedStatus = localStorage.getItem('callsLeadsStatus');
        if (savedStatus) {
            params.set('status', savedStatus);
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
        {{-- Custom dropdown (explicit request, 2026-08-20 — same treatment
             as the status control next to it) instead of a plain native
             <select>: a real hidden input carries the actual `tsa` value
             the surrounding GET form submits, clicking a row just sets it
             and submits, same end result as the old <select
             onchange="submit()">. Each row's avatar circle reuses TSA
             Management's own initials-circle style so a TSA reads the same
             way here as it does there. --}}
        <div class="relative" data-tsa-filter-wrap>
            <input type="hidden" name="tsa" value="{{ $selectedTsa ?: '' }}" data-tsa-filter-input>
            <button type="button" data-tsa-filter-trigger
                    class="inline-flex items-center gap-2 text-sm font-mono font-semibold text-slate-700 dark:text-slate-200 border border-slate-300 dark:border-slate-600 rounded-lg px-3 py-2 bg-white dark:bg-slate-800 hover:bg-slate-50 dark:hover:bg-slate-700 cursor-pointer">
                @php $selectedTsaModel = $selectedTsa ? $tsas->firstWhere('id', $selectedTsa) : null; @endphp
                @if($selectedTsaModel)
                <span class="w-5 h-5 rounded-full bg-slate-800 dark:bg-slate-700 text-white flex items-center justify-center text-[9px] font-bold shrink-0">{{ strtoupper(substr($selectedTsaModel->display_name, 0, 2)) }}</span>
                @else
                <svg class="w-4 h-4 text-slate-400 shrink-0" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M18 18.72a9.094 9.094 0 003.741-.479 3 3 0 00-4.682-2.72m.94 3.198l.001.031c0 .225-.012.447-.037.666A11.944 11.944 0 0112 21c-2.17 0-4.207-.576-5.963-1.584A6.062 6.062 0 016 18.719m12 0a5.971 5.971 0 00-.941-3.197m0 0A5.995 5.995 0 0012 12.75a5.995 5.995 0 00-5.058 2.772m0 0a3 3 0 00-4.681 2.72 8.986 8.986 0 003.74.477m.94-3.197a5.971 5.971 0 00-.94 3.197M15 6.75a3 3 0 11-6 0 3 3 0 016 0zm6 3a2.25 2.25 0 11-4.5 0 2.25 2.25 0 014.5 0zm-13.5 0a2.25 2.25 0 11-4.5 0 2.25 2.25 0 014.5 0z"/></svg>
                @endif
                <span>{{ $selectedTsaModel?->display_name ?? 'All TSAs' }}</span>
                <svg class="w-3.5 h-3.5 text-slate-400" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M19 9l-7 7-7-7"/>
                </svg>
            </button>

            <div class="hidden fixed z-50 bg-white dark:bg-slate-900 rounded-2xl shadow-2xl border border-slate-200 dark:border-slate-700 w-64 max-h-96 overflow-y-auto" data-tsa-filter-panel>
                <div class="py-1">
                    <div class="tsa-filter-option flex items-center gap-3 px-4 py-2.5 cursor-pointer hover:bg-slate-50 dark:hover:bg-slate-800" data-value="">
                        <svg class="w-5 h-5 text-slate-400 shrink-0" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M18 18.72a9.094 9.094 0 003.741-.479 3 3 0 00-4.682-2.72m.94 3.198l.001.031c0 .225-.012.447-.037.666A11.944 11.944 0 0112 21c-2.17 0-4.207-.576-5.963-1.584A6.062 6.062 0 016 18.719m12 0a5.971 5.971 0 00-.941-3.197m0 0A5.995 5.995 0 0012 12.75a5.995 5.995 0 00-5.058 2.772m0 0a3 3 0 00-4.681 2.72 8.986 8.986 0 003.74.477m.94-3.197a5.971 5.971 0 00-.94 3.197M15 6.75a3 3 0 11-6 0 3 3 0 016 0zm6 3a2.25 2.25 0 11-4.5 0 2.25 2.25 0 014.5 0zm-13.5 0a2.25 2.25 0 11-4.5 0 2.25 2.25 0 014.5 0z"/></svg>
                        <span class="flex-1 text-sm font-semibold text-slate-700 dark:text-slate-200 font-mono">All TSAs</span>
                        @if(!$selectedTsa)
                        <svg class="w-4 h-4 text-primary shrink-0" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7"/></svg>
                        @endif
                    </div>
                    @foreach($tsas as $tsa)
                    <div class="tsa-filter-option flex items-center gap-3 px-4 py-2.5 cursor-pointer hover:bg-slate-50 dark:hover:bg-slate-800" data-value="{{ $tsa->id }}">
                        <span class="w-5 h-5 rounded-full bg-slate-800 dark:bg-slate-700 text-white flex items-center justify-center text-[9px] font-bold shrink-0">{{ strtoupper(substr($tsa->display_name, 0, 2)) }}</span>
                        <span class="flex-1 text-sm font-semibold text-slate-700 dark:text-slate-200 font-mono">{{ $tsa->display_name }}</span>
                        @if($selectedTsa === $tsa->id)
                        <svg class="w-4 h-4 text-primary shrink-0" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7"/></svg>
                        @endif
                    </div>
                    @endforeach
                </div>
            </div>
        </div>

        {{-- Status filter, brought back (explicit request, 2026-08-21) — see
             LeadController::index()'s own comment for why this only applies
             on the bare Leads view, not Overdue/Callbacks. Same plain
             &lt;select&gt;-submits-on-change pattern Monitor TSA's own status
             filter already uses, rather than the TSA filter's fancier
             custom dropdown above — this one's a short, fixed value list
             with no avatar/icon per option, so a native select is enough.
             Catered/Uncatered added (explicit request, 2026-08-26) — same
             "Catered" language the Call Tracker Dashboard KPI already uses
             (see LeadController::index()'s own comment on this). The
             original Unassigned/Assigned/Called options these replaced are
             removed from the UI (explicit request, same day) — the
             controller still recognizes those values via
             STATUS_FILTER_VALUES for any old bookmarked/typed URL, this is
             only about what the dropdown itself now offers. --}}
        @if(!$view)
        <select name="status" onchange="this.form.submit()"
                class="text-sm font-mono border border-slate-300 dark:border-slate-600 rounded-lg px-3 py-2 bg-white dark:bg-slate-800 text-slate-800 dark:text-slate-100 focus:outline-none focus:ring-2 focus:ring-yellow-500">
            <option value="">All Statuses</option>
            <option value="catered" {{ $selectedStatus === 'catered' ? 'selected' : '' }}>Catered</option>
            <option value="uncatered" {{ $selectedStatus === 'uncatered' ? 'selected' : '' }}>Uncatered</option>
        </select>
        @endif

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
        @if(!$view && $selectedTsaModel)
        @include('calls.partials.tsa-status-panel', [
            'id'      => 'leads-tsa-filter',
            'options' => \App\Models\TsaShift::SELF_SERVICE_STATUSES,
            'current' => $selectedTsaModel->status,
            'target'  => (string) $selectedTsaModel->id,
        ])
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
        @elseif(auth()->user()->tsa)
        {{-- A logged-in TSA sees their own name here, same avatar+name shape
             as the admin dropdown above minus the chevron/click handler —
             explicit request, 2026-08-26: "the one tsa can only see their
             name and has no dropdown." Everything else in the @if branch
             above (TSA filter, status filter, search, date range) stays
             admin-only exactly as it already was — this whole block was
             already entirely admin-gated before this change, so a TSA saw
             nothing here at all; this just adds a static name label instead
             of leaving that space empty. --}}
        <div class="inline-flex items-center gap-2 text-sm font-mono font-semibold text-slate-700 dark:text-slate-200 border border-slate-300 dark:border-slate-600 rounded-lg px-3 py-2 bg-white dark:bg-slate-800">
            <span class="w-5 h-5 rounded-full bg-slate-800 dark:bg-slate-700 text-white flex items-center justify-center text-[9px] font-bold shrink-0">{{ strtoupper(substr(auth()->user()->tsa->display_name, 0, 2)) }}</span>
            <span>{{ auth()->user()->tsa->display_name }}</span>
        </div>
        @endif
    </form>
</div>

<div id="leads-table-container" data-poll-url="{{ url()->full() }}">
    @include('calls.leads._table')
</div>

{{-- Bulk actions — admin-only (explicit request, 2026-08-26): matching
     Product Management's own checkbox + bulk-bar pattern, but restricted
     entirely to admins — both Pin/Unpin and Transfer require isAtLeastAdmin()
     server-side now (LeadController::bulkPin()/bulkTransfer()), so a TSA
     never sees a checkbox to select with in the first place (see
     _table.blade.php's own guard on the checkbox column). Sticky bottom bar,
     same shape as product-management.blade.php's own — but submitted via
     fetch (calls.js), not a full-page POST, since this table live-polls
     every 15s and a full-page reload would be a jarring step backward
     from that. --}}
@if(auth()->user()->isAtLeastAdmin())
<div id="bulkLeadsBar" class="hidden fixed bottom-0 left-0 right-0 md:left-64 z-30 px-4 py-3">
    <div class="max-w-3xl mx-auto bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-700 rounded-xl shadow-2xl px-5 py-3 flex flex-wrap items-center gap-3">
        <span id="bulkLeadsCount" class="text-xs font-semibold text-slate-700 dark:text-slate-200 whitespace-nowrap">0 selected</span>
        <button type="button" id="bulkLeadsClear" class="text-xs font-mono text-slate-400 hover:text-slate-600 dark:hover:text-slate-200 cursor-pointer">Clear</button>
        <div class="flex-1"></div>
        <div class="flex flex-wrap items-center gap-2">
            <button type="button" id="bulkLeadsPin" class="px-3 py-1.5 text-xs font-semibold text-slate-600 dark:text-slate-300 border border-slate-200 dark:border-slate-700 rounded-lg hover:bg-slate-50 dark:hover:bg-slate-800 transition-colors cursor-pointer">Pin</button>
            <button type="button" id="bulkLeadsUnpin" class="px-3 py-1.5 text-xs font-semibold text-slate-600 dark:text-slate-300 border border-slate-200 dark:border-slate-700 rounded-lg hover:bg-slate-50 dark:hover:bg-slate-800 transition-colors cursor-pointer">Unpin</button>
            <select id="bulkLeadsTsaSelect" class="rounded-lg border border-slate-200 dark:border-slate-700 bg-white dark:bg-slate-800 px-2 py-1.5 text-xs text-slate-900 dark:text-slate-100 focus:outline-none focus:ring-2 focus:ring-yellow-500">
                @foreach($tsas as $tsa)
                <option value="{{ $tsa->id }}">{{ $tsa->display_name }}</option>
                @endforeach
            </select>
            <button type="button" id="bulkLeadsTransfer" class="px-3 py-1.5 text-xs font-semibold text-yellow-700 dark:text-yellow-400 border border-yellow-200 dark:border-yellow-900 rounded-lg hover:bg-yellow-50 dark:hover:bg-yellow-950/40 transition-colors cursor-pointer">Transfer</button>
        </div>
    </div>
</div>
@endif

@include('calls.partials.modals')

@endsection
