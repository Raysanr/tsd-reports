@extends('layouts.calls')
@section('title', 'TSA Logs')
@section('subtitle', 'Login · Break · DNA Huddle · Coaching · Logout · Lock history · Calls')

@section('content')

@php
$statusColor = fn($status) => match($status) {
    'login'  => 'bg-emerald-100 dark:bg-emerald-900/40 text-emerald-700 dark:text-emerald-400',
    'logout' => 'bg-slate-100 dark:bg-slate-700 text-slate-600 dark:text-slate-300',
    'locked' => 'bg-red-100 dark:bg-red-900/40 text-red-700 dark:text-red-400',
    default  => 'bg-amber-100 dark:bg-amber-900/40 text-amber-700 dark:text-amber-400',
};

// Selectable options for the Status filter (explicit request, 2026-09-22;
// corrected same day — "the statuses is only this: Login, Calling, Wrap
// Up, Break, Lunch, Coaching, DNA Huddle, Huddle, Others, Logout" — Lock
// and the synthetic 'call' entry, both present in an earlier draft, are
// explicitly NOT part of this filter's own list; a locked TSA's status
// still shows correctly in the TABLE via $statusColor's own 'locked'
// branch and a real Lock status change, it's just never a checkbox HERE).
// Built from TsaShift::STATUSES directly, filtered down to exactly that
// list rather than hand-typing 10 option rows that could drift out of
// sync with the real status labels/order over time.
$statusFilterOptions = collect($statuses)
    ->except('locked')
    ->map(fn ($def, $key) => ['value' => $key, 'label' => strtoupper($def['label'])])
    ->values();
@endphp

<div class="mb-6 flex items-center gap-3 flex-wrap">
    <form method="GET" id="tsaLogsFilterForm" class="flex items-center gap-3 flex-wrap">
        <select name="tsa" onchange="this.form.submit()"
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
    const wrap     = document.getElementById('tsaLogsStatusFilterWrap');
    const trigger  = document.getElementById('tsaLogsStatusFilterTrigger');
    const panel    = document.getElementById('tsaLogsStatusFilterPanel');
    const selectAll = document.getElementById('tsaLogsStatusSelectAll');
    const boxes    = Array.from(document.querySelectorAll('.tsa-logs-status-checkbox'));
    const form     = document.getElementById('tsaLogsFilterForm');
    if (!wrap || !trigger || !panel || !form) return;

    trigger.addEventListener('click', () => panel.classList.toggle('hidden'));
    document.addEventListener('click', (e) => {
        if (!wrap.contains(e.target)) panel.classList.add('hidden');
    });

    // "All Statuses" is genuinely a clear-and-submit action, not just a
    // client-side check-everything toggle — unchecking every individual
    // box already means "no filter" server-side (see the controller's own
    // doc comment), so this both unchecks every box AND submits
    // immediately, matching what a TSA expects clicking it to do.
    selectAll?.addEventListener('change', () => {
        if (!selectAll.checked) return; // only fires on check, never on uncheck
        boxes.forEach((b) => { b.checked = false; });
        form.submit();
    });

    // A real status box submits on its own change too (explicit request:
    // "add in TSA logs page this like selectable statuses" — matches the
    // reference screenshot's own instant-filter behavior, no separate
    // Apply button) — checking any individual box also implicitly clears
    // "All Statuses" visually, though it never had a form value to begin
    // with (it's not name="status[]").
    boxes.forEach((box) => {
        box.addEventListener('change', () => form.submit());
    });
})();
</script>
@endpush

<div class="bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-700 shadow-sm overflow-hidden">
    @if($logs->isEmpty())
    <div class="py-20 flex flex-col items-center justify-center gap-3">
        <svg class="w-10 h-10 text-slate-200 dark:text-slate-700" fill="none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" d="M9 12h3.75M9 15h3.75M9 18h3.75m3-15H6a2.25 2.25 0 00-2.25 2.25v13.5A2.25 2.25 0 006 21h12a2.25 2.25 0 002.25-2.25V6.108c0-.53-.211-1.04-.586-1.414l-3.808-3.808a2.25 2.25 0 00-1.414-.586H15z"/>
        </svg>
        <p class="text-sm font-mono text-slate-400">No status changes or calls recorded yet.</p>
        <p class="text-xs font-mono text-slate-300 dark:text-slate-600">Status changes appear here as soon as a TSA switches Login/Break/etc., and calls as soon as one clicks a customer's number.</p>
    </div>
    @else
    <div class="overflow-x-auto overflow-y-auto max-h-[70vh]">
    <table class="w-full text-sm font-mono">
        <thead class="bg-slate-100 dark:bg-slate-700 border-b border-slate-200 dark:border-slate-700 sticky top-0 z-10">
            <tr>
                <th class="px-4 py-3 text-left text-[11px] font-bold text-slate-400 uppercase tracking-wide">Time</th>
                <th class="px-4 py-3 text-left text-[11px] font-bold text-slate-400 uppercase tracking-wide">TSA</th>
                <th class="px-4 py-3 text-left text-[11px] font-bold text-slate-400 uppercase tracking-wide">Status</th>
                <th class="px-4 py-3 text-left text-[11px] font-bold text-slate-400 uppercase tracking-wide">Detail</th>
            </tr>
        </thead>
        <tbody class="divide-y divide-slate-100 dark:divide-slate-700">
            @foreach($logs as $log)
            <tr class="hover:bg-slate-50 dark:hover:bg-slate-800">
                <td class="px-4 py-3 text-slate-500 dark:text-slate-400 whitespace-nowrap tabular-nums">{{ $log->created_at->format('g:ia') }}</td>
                <td class="px-4 py-3 text-slate-700 dark:text-slate-200 font-semibold">{{ $log->tsa->display_name ?? '—' }}</td>
                <td class="px-4 py-3">
                    @if($log->kind === 'call')
                    <span class="inline-flex px-2 py-0.5 rounded-full text-[10px] font-bold uppercase tracking-wide bg-blue-100 dark:bg-blue-900/40 text-blue-700 dark:text-blue-400">Call</span>
                    @else
                    <span class="inline-flex px-2 py-0.5 rounded-full text-[10px] font-bold uppercase tracking-wide {{ $statusColor($log->status) }}">
                        {{ $statuses[$log->status]['label'] ?? $log->status }}
                    </span>
                    @endif
                </td>
                <td class="px-4 py-3 text-slate-500 dark:text-slate-400">{{ $log->detail ?? '—' }}</td>
            </tr>
            @endforeach
        </tbody>
    </table>
    </div>
    <div class="px-4 py-3 border-t border-slate-100 dark:border-slate-700">
        {{ $logs->links('partials.pagination') }}
    </div>
    @endif
</div>

@endsection
