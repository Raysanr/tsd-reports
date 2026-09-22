@extends('layouts.calls')
@section('title', 'TSA Logs')
@section('subtitle', 'Login · Break · DNA Huddle · Coaching · Logout · Lock history · Calls')

@section('content')

@php
// Duplicated in tsa-logs/_table.blade.php (its own copy, not shared) —
// this parent view still needs it for the Status filter's own dropdown
// badge colors below, while the table fragment needs it independently
// since it can render standalone via an X-Table-Refresh AJAX swap without
// this parent view's own @php block ever running.
$statusColor = fn($status) => match($status) {
    'login'      => 'bg-emerald-100 dark:bg-emerald-900/40 text-emerald-700 dark:text-emerald-400',
    'call_backs' => 'bg-teal-100 dark:bg-teal-900/40 text-teal-700 dark:text-teal-400',
    'logout'     => 'bg-slate-100 dark:bg-slate-700 text-slate-600 dark:text-slate-300',
    'locked'     => 'bg-red-100 dark:bg-red-900/40 text-red-700 dark:text-red-400',
    default      => 'bg-amber-100 dark:bg-amber-900/40 text-amber-700 dark:text-amber-400',
};

// Selectable options for the Status filter (explicit request, 2026-09-22;
// corrected same day — "the statuses is only this: Login, Calling, Wrap
// Up, Break, Lunch, Coaching, DNA Huddle, Huddle, Others, Logout" — Lock
// was explicitly NOT part of this filter's own list; a locked TSA's status
// still shows correctly in the TABLE via $statusColor's own 'locked'
// branch and a real Lock status change, it's just never a checkbox HERE).
// Built from TsaShift::STATUSES directly, filtered down to exactly that
// list rather than hand-typing 10 option rows that could drift out of
// sync with the real status labels/order over time.
//
// The synthetic 'call' entry appended below is a SECOND reversal, same
// day (bug report, 2026-09-22: "if i filter login the displaying too is
// has call in the table... but you can add call in the filter too") — the
// CALL rows ($callRows in the controller, from LeadActivity's
// 'call_clicked' events, not a TsaStatusLog status at all) were always
// shown regardless of this filter, which read as a bug once a TSA
// actually expected checking ONLY "Login" to hide everything else,
// including Calls. 'call' is not a TsaShift::STATUSES key, so it's
// appended here rather than coming from that collection — the
// controller's own selectedStatuses check treats it as a special case
// gating $callRows, not a TsaStatusLog::status value to whereIn() against.
$statusFilterOptions = collect($statuses)
    ->except('locked')
    ->map(fn ($def, $key) => ['value' => $key, 'label' => strtoupper($def['label'])])
    ->values()
    ->push(['value' => 'call', 'label' => 'CALL']);
@endphp

<div class="mb-6 flex items-center gap-3 flex-wrap">
    <form method="GET" id="tsaLogsFilterForm" class="flex items-center gap-3 flex-wrap">
        {{-- onchange used to call this.form.submit() directly (a hard
             reload) — now just fires the form's own 'change' event, which
             the AJAX submit handler below listens for on non-text form
             controls (see that script's own doc comment for why change,
             not submit, is what a <select> and checkboxes actually need). --}}
        <select name="tsa"
                class="text-sm font-mono border border-slate-300 dark:border-slate-600 rounded-lg px-3 py-2 bg-white dark:bg-slate-800 text-slate-800 dark:text-slate-100 focus:outline-none focus:ring-2 focus:ring-yellow-500">
            <option value="">All TSAs</option>
            @foreach($tsas as $tsa)
            <option value="{{ $tsa->id }}" @selected($selectedTsa === $tsa->id)>{{ $tsa->display_name }}</option>
            @endforeach
        </select>

        @include('partials.date-picker', [
            'mode' => 'range', 'id' => 'callsTsaLogsDrp',
            'dateFrom' => \Illuminate\Support\Carbon::parse($dateFrom ?: now()),
            'dateTo'   => \Illuminate\Support\Carbon::parse($dateTo ?: now()),
            'showLabel' => true,
        ])

        {{-- Multi-select Status filter — checkboxes, not the single-select
             data-filter-trigger/data-filter-panel pattern used elsewhere in
             this app (Leads' own TSA/Team/Product dropdowns), since those
             only ever pick ONE value into one hidden input. Every checked
             box submits as its own status[]=X on the surrounding GET form
             natively — no hidden-input/JS-driven value assembly needed,
             just the open/close panel behavior and the Select All
             convenience, wired in the script block below. No boxes checked
             at all means "no filter" (see the controller's own doc
             comment) — same as this page's pre-existing, unfiltered
             default, so a fresh visit/Select-All-then-uncheck-all reads
             identically to never having filtered. --}}
        <div class="relative" id="tsaLogsStatusFilterWrap">
            <button type="button" id="tsaLogsStatusFilterTrigger"
                    class="inline-flex items-center gap-2 text-sm font-mono font-semibold text-slate-700 dark:text-slate-200 border border-slate-300 dark:border-slate-600 rounded-lg px-3 py-2 bg-white dark:bg-slate-800 hover:bg-slate-50 dark:hover:bg-slate-700 cursor-pointer">
                <svg class="w-4 h-4 text-slate-400 shrink-0" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M3 4.5h18M6 9h12M9 13.5h6M11 18h2"/></svg>
                <span>
                    @if(empty($selectedStatuses))
                        All Statuses
                    @elseif(count($selectedStatuses) === 1)
                        {{ $statusFilterOptions->firstWhere('value', $selectedStatuses[0])['label'] ?? 'All Statuses' }}
                    @else
                        {{ count($selectedStatuses) }} Statuses
                    @endif
                </span>
                <svg class="w-3.5 h-3.5 text-slate-400" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M19 9l-7 7-7-7"/>
                </svg>
            </button>

            <div id="tsaLogsStatusFilterPanel" class="hidden absolute z-50 mt-1 bg-white dark:bg-slate-900 rounded-2xl shadow-2xl border border-slate-200 dark:border-slate-700 w-72 max-h-96 overflow-y-auto">
                <div class="py-1">
                    <label class="flex items-center gap-3 px-4 py-2.5 cursor-pointer hover:bg-slate-50 dark:hover:bg-slate-800 border-b border-slate-100 dark:border-slate-700">
                        <input type="checkbox" id="tsaLogsStatusSelectAll" class="w-4 h-4 rounded border-slate-300 dark:border-slate-600 text-primary focus:ring-yellow-500 shrink-0">
                        <span class="flex-1 text-sm font-semibold text-slate-700 dark:text-slate-200 font-mono">All Statuses</span>
                    </label>
                    @foreach($statusFilterOptions as $option)
                    <label class="flex items-center gap-3 px-4 py-2.5 cursor-pointer hover:bg-slate-50 dark:hover:bg-slate-800">
                        <input type="checkbox" name="status[]" value="{{ $option['value'] }}" class="tsa-logs-status-checkbox w-4 h-4 rounded border-slate-300 dark:border-slate-600 text-primary focus:ring-yellow-500 shrink-0"
                               @checked(in_array($option['value'], $selectedStatuses, true))>
                        <span class="inline-flex px-2 py-0.5 rounded-full text-[10px] font-bold uppercase tracking-wide {{ $option['value'] === 'call' ? 'bg-blue-100 dark:bg-blue-900/40 text-blue-700 dark:text-blue-400' : $statusColor($option['value']) }}">{{ $option['label'] }}</span>
                    </label>
                    @endforeach
                </div>
            </div>
        </div>
    </form>
</div>

@push('scripts')
<script>
(function () {
    const wrap        = document.getElementById('tsaLogsStatusFilterWrap');
    const trigger      = document.getElementById('tsaLogsStatusFilterTrigger');
    const panel        = document.getElementById('tsaLogsStatusFilterPanel');
    const selectAll    = document.getElementById('tsaLogsStatusSelectAll');
    const boxes        = Array.from(document.querySelectorAll('.tsa-logs-status-checkbox'));
    const form         = document.getElementById('tsaLogsFilterForm');
    const container    = document.getElementById('tsaLogsTableContainer');
    if (!wrap || !trigger || !panel || !form || !container) return;

    trigger.addEventListener('click', () => panel.classList.toggle('hidden'));
    document.addEventListener('click', (e) => {
        if (!wrap.contains(e.target)) panel.classList.add('hidden');
    });

    // AJAX submit (regression fix, 2026-09-22: "why every time i select
    // the dropdown will close and the page will full reload" — every
    // filter here, including the pre-existing TSA dropdown and date
    // picker, used to be a plain form.submit()/requestSubmit() full page
    // reload. For the Status checkboxes specifically that meant the
    // dropdown panel got destroyed and re-created CLOSED on every single
    // box checked, making it impossible to pick more than one status
    // before losing the panel — but the same root cause affected the
    // TSA/date filters too, just less visibly since they don't have an
    // open panel to lose). Fetches just the table fragment (tsa-logs/
    // _table.blade.php, via X-Table-Refresh — same convention
    // LeadController::index() already uses for the Leads page's own
    // table) and swaps it into #tsaLogsTableContainer, leaving the
    // dropdown panel, TSA select, and date picker's own DOM nodes
    // untouched. Pagination links stay plain full-page navigations,
    // unchanged — same as the Leads page's own pagination, never
    // intercepted by JS there either.
    //
    // URL built from `${window.location.pathname}?...FormData(form)`, NOT
    // form.action (real production bug, 2026-09-21, on the Leads search
    // box: an unset action="" attribute makes the DOM property resolve to
    // window.location.href — the FULL current URL, own query string
    // included — so appending a second "?...params" onto that produced a
    // malformed URL and a live 500; see that incident's own commit
    // message for the full story). pathname alone can never contain a
    // "?", so this can't repeat that bug.
    function submitFilters() {
        const url = `${window.location.pathname}?${new URLSearchParams(new FormData(form)).toString()}`;
        container.dataset.pollUrl = url;
        history.replaceState({}, '', url);

        fetch(url, { headers: { 'X-Table-Refresh': '1' }, cache: 'no-store' })
            .then((res) => (res.ok ? res.text() : null))
            .then((html) => {
                if (html === null) return;
                container.innerHTML = html;
            })
            .catch(() => {
                // Fetch itself failed (offline, etc.) — a real navigation
                // is the only remaining way to actually apply the filter.
                window.location.href = url;
            });
    }

    // Real <form> submit (the date picker's own Apply button calls
    // form.requestSubmit(), which fires this) — intercepted the same way
    // app.js's generic GET-form soft-refresh already does for other pages
    // (see that file's own "GET filter forms → soft refresh" section),
    // just scoped to this one page/container instead of a whole-page swap
    // (this page's layout, layouts.calls, never loads app.js at all).
    form.addEventListener('submit', (e) => {
        e.preventDefault();
        submitFilters();
    });

    // The TSA <select> and every status checkbox fire 'change', not
    // 'submit' — routed through the exact same submitFilters() so the URL/
    // table swap logic never has two different implementations to keep in
    // sync.
    const tsaSelect = form.querySelector('select[name="tsa"]');
    tsaSelect?.addEventListener('change', submitFilters);

    // "All Statuses" is genuinely a clear-and-submit action, not just a
    // client-side check-everything toggle — unchecking every individual
    // box already means "no filter" server-side (see the controller's own
    // doc comment), so this both unchecks every box AND submits
    // immediately, matching what a TSA expects clicking it to do.
    selectAll?.addEventListener('change', () => {
        if (!selectAll.checked) return; // only fires on check, never on uncheck
        boxes.forEach((b) => { b.checked = false; });
        submitFilters();
    });

    // A real status box submits on its own change too (explicit request:
    // "add in TSA logs page this like selectable statuses" — matches the
    // reference screenshot's own instant-filter behavior, no separate
    // Apply button) — checking any individual box also implicitly clears
    // "All Statuses" visually, though it never had a form value to begin
    // with (it's not name="status[]"). The panel deliberately stays OPEN
    // after this (no panel.classList.add('hidden') here) — that's the
    // entire point of this fix, letting several boxes be checked in a row.
    boxes.forEach((box) => {
        box.addEventListener('change', submitFilters);
    });

    // Back/forward between filtered states — same convention every other
    // AJAX-swapped filter bar in this app uses (round-robin-setup.blade.php,
    // leads/index.blade.php): a full reload correctly re-renders from the
    // restored URL rather than trying to re-derive checkbox/select state
    // from a bfcache'd DOM.
    window.addEventListener('popstate', () => window.location.reload());
})();
</script>
@endpush

{{-- AJAX-swapped container (explicit request, 2026-09-22: "why every time
     i select the dropdown will close and the page will full reload" —
     every filter here used to be a plain form.submit() full-page reload,
     which for the multi-select Status checkboxes specifically meant the
     dropdown panel got destroyed and re-created closed on every single
     box clicked, making it impossible to check more than one status
     before losing the panel. Same #leads-table-container/data-poll-url +
     X-Table-Refresh convention LeadController::index()'s own doc comment
     describes for the Leads page — fetches just the table fragment
     (tsa-logs/_table.blade.php) and swaps it in, keeping the dropdown
     panel open and the rest of the page untouched. Pagination links
     themselves stay plain full-page navigations, unchanged — same as the
     Leads page's own pagination, never intercepted by JS there either. --}}
<div id="tsaLogsTableContainer" data-poll-url="{{ url()->full() }}">
    @include('calls.tsa-logs._table')
</div>

@endsection
