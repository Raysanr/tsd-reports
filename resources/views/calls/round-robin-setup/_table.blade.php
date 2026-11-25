{{-- Leads Setup table (redesign, 2026-11-25 — design A "Clear Table").
     Each row carries data-assigned + data-action; the page script repaints
     the row's numbers/bar/status from the limit box as it changes, and the
     limit saves by itself (no Save button, since 2026-09-21). --}}
@php
    $teamStyles = [
        'SH Naturals'  => 'text-primary-dark dark:text-yellow-400 bg-primary/10',
        'Eyecare Team' => 'text-teal-700 dark:text-teal-400 bg-teal-500/10',
    ];
    // Reads honestly as "today" only when it actually is a single-day range
    // that IS today (explicit request, 2026-08-24) — a past day/range picked
    // here never silently reads like a live "today" count.
    $isSingleDay = $dateFrom->isSameDay($dateTo);
    $isToday = $isSingleDay && $dateFrom->isToday();
    $assignedColumnLabel = $isToday
        ? 'Assigned today'
        : ($isSingleDay
            ? 'Assigned — ' . $dateFrom->format('M j, Y')
            : 'Assigned — ' . $dateFrom->format('M j') . ' to ' . $dateTo->format('M j, Y'));
    $reached = $tsas->filter(fn ($row) => $row['tsa']->daily_lead_cap !== null && $row['assigned_today'] >= $row['tsa']->daily_lead_cap);
    $totalAssigned = $tsas->sum('assigned_today');
@endphp

<div class="flex flex-col gap-4">
    @if($isToday && $reached->isNotEmpty())
    {{-- Same check the sidebar's "!" badge uses (TsaShift::hasReachedDailyCap()). --}}
    <div role="alert" class="flex items-start gap-3 rounded-xl border border-orange-200 dark:border-orange-900/60 bg-orange-50 dark:bg-orange-950/30 px-4 py-3">
        <span class="shrink-0 w-6 h-6 rounded-full bg-orange-500 text-white flex items-center justify-center text-sm font-bold" aria-hidden="true">!</span>
        <p class="text-sm text-orange-900 dark:text-orange-200">
            <b class="font-semibold">{{ $reached->map(fn ($row) => $row['tsa']->display_name)->join(', ', ' and ') }}</b>
            {{ $reached->count() === 1 ? 'has' : 'have' }} reached today's limit. New leads skip them until the limit is raised.
        </p>
    </div>
    @endif

    <p class="text-sm text-slate-500 dark:text-slate-400">
        <b class="font-semibold text-slate-800 dark:text-slate-100">{{ $totalAssigned }}</b> {{ $totalAssigned === 1 ? 'lead' : 'leads' }} {{ $isToday ? 'assigned today' : 'assigned in this range' }}
    </p>

<div class="bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-700 shadow-sm overflow-hidden">
    @if($tsas->isEmpty())
    <div class="py-20 flex flex-col items-center justify-center gap-3">
        <svg class="w-10 h-10 text-slate-200 dark:text-slate-700" fill="none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" d="M17 20h5v-2a4 4 0 00-3-3.87M9 20H4v-2a4 4 0 013-3.87m6-8.13a4 4 0 110 8 4 4 0 010-8zm6 8a4 4 0 00-3-3.87M5 12a4 4 0 013.87-3"/>
        </svg>
        <p class="text-sm text-slate-400">No TSAs match this filter.</p>
    </div>
    @else
    <div class="overflow-x-auto">
    <table class="w-full text-sm min-w-[860px]">
        <thead>
            <tr class="border-b border-slate-200 dark:border-slate-700 text-left text-xs text-slate-500 dark:text-slate-400">
                <th scope="col" class="px-5 py-3.5 font-semibold">TSA</th>
                <th scope="col" class="px-5 py-3.5 font-semibold">Team</th>
                <th scope="col" class="px-5 py-3.5 font-semibold w-72">{{ $assignedColumnLabel }}</th>
                <th scope="col" class="px-5 py-3.5 font-semibold">Status</th>
                <th scope="col" class="px-5 py-3.5 font-semibold">Daily limit</th>
            </tr>
        </thead>
        <tbody class="divide-y divide-slate-100 dark:divide-slate-800">
            @foreach($tsas as $row)
                @php
                    $tsa      = $row['tsa'];
                    $assigned = $row['assigned_today'];
                    $cap      = $tsa->daily_lead_cap;
                    $atCap    = $cap !== null && $assigned >= $cap;
                    $pct      = $cap !== null ? min(100, round($assigned / max($cap, 1) * 100)) : 0;
                @endphp
                <tr class="daily-cap-row hover:bg-slate-50 dark:hover:bg-slate-800/60 transition-colors duration-150"
                    data-action="{{ route('calls.round-robin-setup.update', $tsa) }}" data-assigned="{{ $assigned }}">
                    <td class="px-5 py-3.5">
                        <div class="flex items-center gap-3">
                            <div class="w-9 h-9 rounded-full bg-slate-800 dark:bg-slate-700 text-white flex items-center justify-center text-[11px] font-bold shrink-0">
                                {{ strtoupper(substr($tsa->display_name, 0, 2)) }}
                            </div>
                            <span class="font-semibold text-slate-800 dark:text-slate-100 truncate">{{ $tsa->display_name }}</span>
                        </div>
                    </td>
                    <td class="px-5 py-3.5">
                        <span class="inline-block text-xs font-semibold rounded-md px-2.5 py-1 {{ $teamStyles[$tsa->team] ?? 'text-slate-600 bg-slate-100' }}">
                            {{-- Renamed-team-aware (2026-09-04): visible label from $teams. --}}
                            {{ $teams[$tsa->team] ?? $tsa->team }}
                        </span>
                    </td>
                    <td class="px-5 py-3.5">
                        <div class="flex flex-col gap-1.5">
                            <div class="flex justify-between gap-3 text-xs">
                                <span><b class="text-sm font-bold text-slate-800 dark:text-slate-100 tabular-nums">{{ $assigned }}</b> <span data-cap-of class="text-slate-500 dark:text-slate-400">{{ $cap !== null ? 'of ' . $cap : ($assigned === 1 ? 'lead' : 'leads') }}</span></span>
                                <span data-cap-left class="text-slate-500 dark:text-slate-400 tabular-nums">{{ $cap !== null ? max(0, $cap - $assigned) . ' left' : '' }}</span>
                            </div>
                            <div data-cap-track class="h-1.5 rounded-full bg-slate-100 dark:bg-slate-800 overflow-hidden {{ $cap === null ? 'invisible' : '' }}">
                                <div data-cap-bar class="h-full rounded-full {{ $atCap ? 'bg-orange-500' : 'bg-primary' }}" style="width: {{ $pct }}%"></div>
                            </div>
                        </div>
                    </td>
                    <td class="px-5 py-3.5">
                        <span data-cap-status="reached" class="{{ $atCap ? 'inline-flex' : 'hidden' }} items-center gap-1.5 text-xs font-semibold text-orange-800 dark:text-orange-300 bg-orange-50 dark:bg-orange-950/40 rounded-full px-2.5 py-1">
                            <span class="w-4 h-4 rounded-full bg-orange-500 text-white flex items-center justify-center text-[10px] font-bold" aria-hidden="true">!</span>
                            Limit reached
                        </span>
                        <span data-cap-status="taking" class="{{ $cap !== null && !$atCap ? 'inline-flex' : 'hidden' }} items-center gap-1.5 text-xs font-semibold text-emerald-700 dark:text-emerald-400 bg-emerald-50 dark:bg-emerald-950/40 rounded-full px-2.5 py-1">
                            <span class="w-1.5 h-1.5 rounded-full bg-emerald-500"></span>
                            Taking leads
                        </span>
                        <span data-cap-status="none" class="{{ $cap === null ? 'inline-flex' : 'hidden' }} items-center gap-1.5 text-xs font-semibold text-indigo-700 dark:text-indigo-300 bg-indigo-50 dark:bg-indigo-950/40 rounded-full px-2.5 py-1">
                            <span class="w-1.5 h-1.5 rounded-full bg-indigo-500"></span>
                            No limit
                        </span>
                    </td>
                    <td class="px-5 py-3.5">
                        <div class="flex items-center gap-2">
                            <div class="flex items-center rounded-lg border border-slate-200 dark:border-slate-700 bg-white dark:bg-slate-900 overflow-hidden">
                                <button type="button" data-cap-step="-10" aria-label="Lower {{ $tsa->display_name }}'s limit by 10"
                                        class="w-9 h-10 text-base text-primary-dark dark:text-yellow-400 hover:bg-slate-50 dark:hover:bg-slate-800 cursor-pointer">−</button>
                                <input type="number" min="1" value="{{ $cap }}" placeholder="No limit" aria-label="Daily limit for {{ $tsa->display_name }}"
                                       class="daily-cap-input w-20 h-10 text-center text-sm font-semibold border-0 bg-transparent text-slate-800 dark:text-slate-100 focus:outline-none [appearance:textfield] [&::-webkit-inner-spin-button]:appearance-none">
                                <button type="button" data-cap-step="10" aria-label="Raise {{ $tsa->display_name }}'s limit by 10"
                                        class="w-9 h-10 text-base text-primary-dark dark:text-yellow-400 hover:bg-slate-50 dark:hover:bg-slate-800 cursor-pointer">+</button>
                            </div>
                            <button type="button" data-cap-unlimited aria-pressed="{{ $cap === null ? 'true' : 'false' }}"
                                    class="h-10 px-3 rounded-lg border text-xs font-semibold cursor-pointer {{ $cap === null ? 'border-primary bg-primary/10 text-primary-dark dark:text-yellow-400' : 'border-slate-200 dark:border-slate-700 text-slate-500 dark:text-slate-400 hover:bg-slate-50 dark:hover:bg-slate-800' }}">
                                No limit
                            </button>
                        </div>
                    </td>
                </tr>
            @endforeach
        </tbody>
    </table>
    </div>
    @endif
</div>
</div>
