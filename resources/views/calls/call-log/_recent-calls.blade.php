{{-- Extracted from call-log.blade.php (2026-09-23) so the search box can
     swap this fragment via AJAX instead of a full page reload — see the
     parent view's own #recentCallsContainer/data-poll-url and script block
     for the fetch side, same X-Table-Refresh convention LeadController::
     index()/TsaStatusController::index() already use for their own tables.
     $formatGap/$gapSeverityClass are redefined here (not passed from the
     controller) since they're pure presentation logic, same as the parent
     view's own copy (used by the Per-TSA Totals table, which stays in the
     parent and is never touched by a search-triggered refresh) — kept in
     sync manually if either ever changes, matching how tsa-logs/
     _table.blade.php's own $statusColor already duplicates its parent's
     copy for the identical reason. --}}
@php
    $formatGap = function (?int $totalSeconds) {
        if ($totalSeconds === null) return null;
        $hours   = intdiv($totalSeconds, 3600);
        $minutes = intdiv($totalSeconds % 3600, 60);
        $seconds = $totalSeconds % 60;
        if ($hours > 0)   return "{$hours}h {$minutes}m";
        if ($minutes > 0) return "{$minutes}m {$seconds}s";
        return "{$seconds}s";
    };
    $gapSeverityClass = function (int $seconds) {
        if ($seconds >= 1800) return 'bg-red-100 dark:bg-red-900/40 text-red-700 dark:text-red-400';     // 30m+
        if ($seconds >= 600)  return 'bg-amber-100 dark:bg-amber-900/40 text-amber-700 dark:text-amber-400'; // 10m+
        return 'bg-emerald-100 dark:bg-emerald-900/40 text-emerald-700 dark:text-emerald-400';
    };
@endphp
<div class="bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-700 shadow-sm overflow-hidden">
    @if($events->isEmpty())
    <div class="py-12 flex flex-col items-center justify-center gap-2">
        <svg class="w-9 h-9 text-slate-200 dark:text-slate-700" fill="none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" d="M3 5a2 2 0 012-2h3.28a1 1 0 01.948.684l1.498 4.493a1 1 0 01-.502 1.21l-2.257 1.13a11.042 11.042 0 005.516 5.517l1.13-2.257a1 1 0 011.21-.502l4.493 1.498a1 1 0 01.684.949V19a2 2 0 01-2 2h-1C9.716 21 3 14.284 3 6V5z"/>
        </svg>
        <p class="text-sm font-mono text-slate-400">{{ $q !== '' ? 'No calls match your search.' : 'No calls reported yet.' }}</p>
    </div>
    @else
    {{-- Scrollable body, sticky header (explicit request, 2026-09-18:
         "can you make the Recent calls has scroll") — this table is
         capped at 200 rows server-side (CallLogController::index()'s
         own ->take(200)) but was still rendering every one of them
         inline, making the whole PAGE scroll a very long way past
         the Per-TSA Totals table above it. A fixed-height scroll
         container keeps Recent Calls contained to one screen's worth
         of space; the header stays pinned (position: sticky) so the
         column labels are never scrolled out of view while browsing
         a long list. --}}
    <div class="overflow-x-auto overflow-y-auto max-h-[37.5rem]">
    <table class="w-full text-sm font-mono">
        <thead class="bg-slate-100 dark:bg-slate-700 border-b border-slate-200 dark:border-slate-700 sticky top-0 z-10">
            <tr>
                <th class="px-4 py-3 text-left text-[11px] font-bold text-slate-400 uppercase tracking-wide">When</th>
                <th class="px-4 py-3 text-left text-[11px] font-bold text-slate-400 uppercase tracking-wide">TSA</th>
                <th class="px-4 py-3 text-left text-[11px] font-bold text-slate-400 uppercase tracking-wide">Number</th>
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
                    {{-- How many times this customer was called within the
                         picked range/filters (explicit request, 2026-09-16)
                         — only shown once there's more than one, so a
                         normal single call stays uncluttered. --}}
                    @php $callCount = $callCountsByLeadId[$event->lead_id] ?? 1; @endphp
                    @if($callCount > 1)
                    <span class="inline-flex items-center justify-center ml-1.5 px-1.5 py-0.5 rounded-full text-[10px] font-bold bg-amber-100 dark:bg-amber-900/40 text-amber-700 dark:text-amber-300" title="Called {{ $callCount }} times in this range">
                        ×{{ $callCount }}
                    </span>
                    @endif
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
