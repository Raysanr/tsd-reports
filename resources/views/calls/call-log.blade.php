@extends('layouts.calls')
@section('title', 'Call Log')
@section('subtitle', 'Real calls reported by each TSA\'s own phone — the basis for load reimbursement')

@push('topbar-right')
{{-- Icon-only, same shared range picker Dashboard/Leads Setup use (explicit
     request, 2026-08-24: "make the date picker too of call log is like in
     the dashboard") — replaces the two plain <input type="date"> fields +
     Apply button this page used before. submit='navigate': a real page
     reload, consistent with every other icon-only topbar picker in this
     app (Dashboard, Leads Setup, Monitor TSA, Analytics). --}}
@include('partials.date-picker', [
    'mode' => 'range', 'id' => 'callLogDrp',
    'dateFrom' => \Illuminate\Support\Carbon::parse($dateFrom), 'dateTo' => \Illuminate\Support\Carbon::parse($dateTo),
    'submit' => 'navigate', 'navigateBase' => route('calls.call-log'),
])
@endpush

@php
    // Shared by both tables below (explicit request, 2026-08-24: show the
    // idle gap between calls instead of the outgoing/incoming/missed/
    // duration breakdown) — same "h/m/s, drop the leading zero units"
    // shape as Monitor TSA's own $formatSeconds.
    $formatGap = function (?int $totalSeconds) {
        if ($totalSeconds === null) return null;
        $hours   = intdiv($totalSeconds, 3600);
        $minutes = intdiv($totalSeconds % 3600, 60);
        $seconds = $totalSeconds % 60;
        if ($hours > 0)   return "{$hours}h {$minutes}m";
        if ($minutes > 0) return "{$minutes}m {$seconds}s";
        return "{$seconds}s";
    };
    // Same "how worried should this look" thresholds for both the per-TSA
    // Longest Gap column and each row's own Gap column, so a TSA flagged
    // amber/red in the summary is traceable to the exact call that caused it.
    $gapSeverityClass = function (int $seconds) {
        if ($seconds >= 1800) return 'bg-red-100 dark:bg-red-900/40 text-red-700 dark:text-red-400';     // 30m+
        if ($seconds >= 600)  return 'bg-amber-100 dark:bg-amber-900/40 text-amber-700 dark:text-amber-400'; // 10m+
        return 'bg-emerald-100 dark:bg-emerald-900/40 text-emerald-700 dark:text-emerald-400';
    };
@endphp

@section('content')

{{-- Team filter (explicit request, 2026-08-24) — same ALL/SH Naturals/Eyecare
     pill group + bg-primary-active styling Monitor TSA's own topbar filter
     already uses, matching that established convention rather than
     introducing a new pill style. Plain links (a real page reload), same as
     the date picker above — no partial-swap infrastructure exists on this
     page yet, and mixing an instant AJAX team-switch with a full-reload date
     change would feel inconsistent. Date range carries through the link (the
     date picker's own navigate mode can't carry `team` back the other way —
     an accepted minor rough edge, same as Leads Setup's own picker). --}}
<div class="mb-6 flex rounded-lg border border-slate-300 dark:border-slate-600 overflow-hidden w-fit">
    @foreach($teams as $key => $label)
    <a href="{{ route('calls.call-log', ['team' => $key, 'date_from' => $dateFrom, 'date_to' => $dateTo]) }}"
       class="px-3 py-1.5 text-xs font-semibold font-mono transition-colors duration-200
              {{ $selectedTeam === $key ? 'bg-primary text-white' : 'bg-white dark:bg-slate-900 text-slate-500 dark:text-slate-400 hover:bg-slate-50 dark:hover:bg-slate-800' }}">
        {{ $label }}
    </a>
    @endforeach
</div>

<div class="mb-4">
    <h2 class="text-xs font-mono font-semibold text-slate-500 dark:text-slate-400 uppercase tracking-wide mb-2">Per-TSA totals (for reimbursement)</h2>
    <div class="bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-700 shadow-sm overflow-hidden">
        @if($rows->isEmpty())
        <div class="py-12 flex flex-col items-center justify-center gap-2">
            <svg class="w-9 h-9 text-slate-200 dark:text-slate-700" fill="none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" d="M3 5a2 2 0 012-2h3.28a1 1 0 01.948.684l1.498 4.493a1 1 0 01-.502 1.21l-2.257 1.13a11.042 11.042 0 005.516 5.517l1.13-2.257a1 1 0 011.21-.502l4.493 1.498a1 1 0 01.684.949V19a2 2 0 01-2 2h-1C9.716 21 3 14.284 3 6V5z"/>
            </svg>
            <p class="text-sm font-mono text-slate-400">No call events reported for this range yet.</p>
            <p class="text-xs font-mono text-slate-300 dark:text-slate-600">Set up each TSA's phone automation in Call Rotation first.</p>
        </div>
        @else
        <div class="overflow-x-auto">
        <table class="w-full text-sm font-mono">
            <thead class="bg-slate-100 dark:bg-slate-700 border-b border-slate-200 dark:border-slate-700">
                <tr>
                    <th class="px-4 py-3 text-left text-[11px] font-bold text-slate-400 uppercase tracking-wide">TSA</th>
                    <th class="px-4 py-3 text-right text-[11px] font-bold text-slate-400 uppercase tracking-wide">Total Calls</th>
                    <th class="px-4 py-3 text-right text-[11px] font-bold text-slate-400 uppercase tracking-wide">Avg Gap</th>
                    <th class="px-4 py-3 text-right text-[11px] font-bold text-slate-400 uppercase tracking-wide">Longest Gap</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-100 dark:divide-slate-700">
                @foreach($rows as $row)
                <tr class="hover:bg-slate-50 dark:hover:bg-slate-800">
                    <td class="px-4 py-3 font-semibold text-slate-700 dark:text-slate-200">{{ $row['tsa']->display_name }}</td>
                    <td class="px-4 py-3 text-right text-slate-600 dark:text-slate-300">{{ $row['total_calls'] }}</td>
                    <td class="px-4 py-3 text-right text-slate-600 dark:text-slate-300">
                        {{ $row['avg_gap_seconds'] !== null ? $formatGap($row['avg_gap_seconds']) : '—' }}
                    </td>
                    <td class="px-4 py-3 text-right">
                        @if($row['longest_gap_seconds'] !== null)
                        <span class="inline-flex px-2 py-0.5 rounded-full text-[10px] font-bold uppercase tracking-wide {{ $gapSeverityClass($row['longest_gap_seconds']) }}">
                            {{ $formatGap($row['longest_gap_seconds']) }}
                        </span>
                        @else
                        <span class="text-slate-300 dark:text-slate-600">—</span>
                        @endif
                    </td>
                </tr>
                @endforeach
            </tbody>
        </table>
        </div>
        @endif
    </div>
</div>

<div>
    <h2 class="text-xs font-mono font-semibold text-slate-500 dark:text-slate-400 uppercase tracking-wide mb-2">Recent calls</h2>
    <div class="bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-700 shadow-sm overflow-hidden">
        @if($events->isEmpty())
        <div class="py-12 flex flex-col items-center justify-center gap-2">
            <svg class="w-9 h-9 text-slate-200 dark:text-slate-700" fill="none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" d="M3 5a2 2 0 012-2h3.28a1 1 0 01.948.684l1.498 4.493a1 1 0 01-.502 1.21l-2.257 1.13a11.042 11.042 0 005.516 5.517l1.13-2.257a1 1 0 011.21-.502l4.493 1.498a1 1 0 01.684.949V19a2 2 0 01-2 2h-1C9.716 21 3 14.284 3 6V5z"/>
            </svg>
            <p class="text-sm font-mono text-slate-400">No calls reported yet.</p>
        </div>
        @else
        <div class="overflow-x-auto">
        <table class="w-full text-sm font-mono">
            <thead class="bg-slate-100 dark:bg-slate-700 border-b border-slate-200 dark:border-slate-700">
                <tr>
                    <th class="px-4 py-3 text-left text-[11px] font-bold text-slate-400 uppercase tracking-wide">When</th>
                    <th class="px-4 py-3 text-left text-[11px] font-bold text-slate-400 uppercase tracking-wide">TSA</th>
                    <th class="px-4 py-3 text-left text-[11px] font-bold text-slate-400 uppercase tracking-wide">Number</th>
                    <th class="px-4 py-3 text-left text-[11px] font-bold text-slate-400 uppercase tracking-wide">Direction</th>
                    <th class="px-4 py-3 text-left text-[11px] font-bold text-slate-400 uppercase tracking-wide">Duration</th>
                    {{-- Gap to next customer (explicit request, 2026-08-24) —
                         idle time between this TSA's PREVIOUS call ending and
                         this one starting, not this row's own call length
                         (that's the Duration column already). "First call"
                         when there's nothing earlier for this TSA in the
                         picked range — see CallLogController::index()'s own
                         comment for exactly how this is computed. --}}
                    <th class="px-4 py-3 text-left text-[11px] font-bold text-slate-400 uppercase tracking-wide">Gap Before</th>
                    <th class="px-4 py-3 text-left text-[11px] font-bold text-slate-400 uppercase tracking-wide">Matched Lead</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-100 dark:divide-slate-700">
                @foreach($events as $event)
                <tr class="hover:bg-slate-50 dark:hover:bg-slate-800">
                    <td class="px-4 py-3 text-slate-500 dark:text-slate-400">{{ $event->occurred_at->format('M j, g:i A') }}</td>
                    <td class="px-4 py-3 text-slate-600 dark:text-slate-300">{{ $event->tsa?->display_name ?? '—' }}</td>
                    <td class="px-4 py-3 text-slate-600 dark:text-slate-300">{{ $event->phone_number }}</td>
                    <td class="px-4 py-3">
                        <span @class([
                            'inline-flex px-2 py-0.5 rounded-full text-[10px] font-bold uppercase tracking-wide',
                            'bg-emerald-100 dark:bg-emerald-900/40 text-emerald-700 dark:text-emerald-400' => $event->direction === 'outgoing',
                            'bg-blue-100 dark:bg-blue-900/40 text-blue-700 dark:text-blue-400'       => $event->direction === 'incoming',
                            'bg-red-100 dark:bg-red-900/40 text-red-700 dark:text-red-400'         => $event->direction === 'missed',
                        ])>{{ $event->direction }}</span>
                    </td>
                    <td class="px-4 py-3 text-slate-600 dark:text-slate-300">{{ $event->duration_seconds !== null ? gmdate('i:s', $event->duration_seconds) : '—' }}</td>
                    <td class="px-4 py-3">
                        @php $gap = $gapBeforeSeconds[$event->id] ?? null; @endphp
                        @if($gap !== null)
                        <span class="inline-flex px-2 py-0.5 rounded-full text-[10px] font-bold uppercase tracking-wide {{ $gapSeverityClass($gap) }}">
                            {{ $formatGap($gap) }}
                        </span>
                        @else
                        <span class="text-xs text-slate-300 dark:text-slate-600">First call</span>
                        @endif
                    </td>
                    <td class="px-4 py-3">
                        @if($event->lead)
                        <a href="{{ route('calls.leads.show', $event->lead) }}" class="text-primary hover:underline">{{ $event->lead->customer_name ?: '#'.$event->lead->pancake_order_id }}</a>
                        @else
                        <span class="text-slate-300 dark:text-slate-600">no match</span>
                        @endif
                    </td>
                </tr>
                @endforeach
            </tbody>
        </table>
        </div>
        @endif
    </div>
</div>

@endsection
