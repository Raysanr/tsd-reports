{{-- Team color coding reused from the two accents already established
     elsewhere (gold = SH Naturals/reporting, teal = Eyecare — see the Hub's
     Call Tracker card) rather than inventing a new palette. --}}
@php
    $teamStyles = [
        'SH Naturals'  => ['dot' => 'bg-primary',    'text' => 'text-primary-dark dark:text-yellow-400', 'bg' => 'bg-primary/10'],
        'Eyecare Team' => ['dot' => 'bg-teal-600',    'text' => 'text-teal-700 dark:text-teal-400',       'bg' => 'bg-teal-500/10'],
    ];
    // Reads honestly as "today" only when it actually is a single-day range
    // that IS today (explicit request, 2026-08-24) — same "the label says
    // what this really covers" reasoning Monitor TSA's own $dailyRecordLabel
    // already follows (and now a real multi-day range too, upgraded from a
    // single date the same day), so a past day/range picked here never
    // silently reads like a live "today" count.
    $isSingleDay = $dateFrom->isSameDay($dateTo);
    $assignedColumnLabel = $isSingleDay && $dateFrom->isToday()
        ? 'Assigned today'
        : ($isSingleDay
            ? 'Assigned — ' . $dateFrom->format('M j, Y')
            : 'Assigned — ' . $dateFrom->format('M j') . ' to ' . $dateTo->format('M j, Y'));
@endphp

<div class="bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-700 shadow-sm overflow-hidden">
    @if($tsas->isEmpty())
    <div class="py-20 flex flex-col items-center justify-center gap-3">
        <svg class="w-10 h-10 text-slate-200 dark:text-slate-700" fill="none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" d="M17 20h5v-2a4 4 0 00-3-3.87M9 20H4v-2a4 4 0 013-3.87m6-8.13a4 4 0 110 8 4 4 0 010-8zm6 8a4 4 0 00-3-3.87M5 12a4 4 0 013.87-3"/>
        </svg>
        <p class="text-sm font-mono text-slate-400">No TSAs match this filter.</p>
    </div>
    @else
    <div class="overflow-x-auto">
    <table class="w-full text-sm">
        <thead>
            <tr class="border-b border-slate-200 dark:border-slate-700">
                <th class="px-5 py-3 text-left text-[11px] font-bold font-mono text-slate-400 uppercase tracking-wide">TSA</th>
                <th class="px-5 py-3 text-left text-[11px] font-bold font-mono text-slate-400 uppercase tracking-wide">Team</th>
                <th class="px-5 py-3 text-left text-[11px] font-bold font-mono text-slate-400 uppercase tracking-wide">{{ $assignedColumnLabel }}</th>
                <th class="px-5 py-3 text-left text-[11px] font-bold font-mono text-slate-400 uppercase tracking-wide">Status</th>
                <th class="px-5 py-3 text-left text-[11px] font-bold font-mono text-slate-400 uppercase tracking-wide">Daily cap</th>
            </tr>
        </thead>
        <tbody class="divide-y divide-slate-100 dark:divide-slate-800">
            @foreach($tsas as $row)
                @php
                    $tsa      = $row['tsa'];
                    $assigned = $row['assigned_today'];
                    $cap      = $tsa->daily_lead_cap;
                    $atCap    = $cap !== null && $assigned >= $cap;
                    $pct      = $cap !== null ? min(100, round($assigned / max($cap, 1) * 100)) : null;
                    $style    = $teamStyles[$tsa->team] ?? ['dot' => 'bg-slate-400', 'text' => 'text-slate-500', 'bg' => 'bg-slate-100'];
                @endphp
                <tr class="hover:bg-slate-50 dark:hover:bg-slate-800/60 transition-colors duration-150">
                    <td class="px-5 py-4">
                        <div class="flex items-center gap-3">
                            <div class="w-9 h-9 rounded-full bg-slate-800 dark:bg-slate-700 text-white flex items-center justify-center text-[11px] font-bold font-mono shrink-0">
                                {{ strtoupper(substr($tsa->display_name, 0, 2)) }}
                            </div>
                            <div class="min-w-0">
                                <div class="font-semibold text-slate-800 dark:text-slate-100 truncate">{{ $tsa->display_name }}</div>
                                <div class="text-[11px] text-slate-400 font-mono">{{ $tsa->tsa_key }}</div>
                            </div>
                        </div>
                    </td>
                    <td class="px-5 py-4">
                        <span class="inline-flex items-center gap-1.5 text-xs font-mono font-semibold {{ $style['text'] }} {{ $style['bg'] }} rounded-full px-2.5 py-1">
                            <span class="w-1.5 h-1.5 rounded-full {{ $style['dot'] }}"></span>
                            {{ $tsa->team }}
                        </span>
                    </td>
                    <td class="px-5 py-4">
                        @if($cap !== null)
                        <div class="flex items-center gap-2.5 w-40">
                            <div class="flex-1 h-1.5 rounded-full bg-slate-100 dark:bg-slate-800 overflow-hidden">
                                <div class="h-full rounded-full {{ $atCap ? 'bg-red-500' : 'bg-primary' }}" style="width: {{ $pct }}%"></div>
                            </div>
                            <span class="text-xs font-mono font-bold tabular-nums {{ $atCap ? 'text-red-600 dark:text-red-400' : 'text-slate-600 dark:text-slate-300' }} shrink-0">{{ $assigned }}/{{ $cap }}</span>
                        </div>
                        @else
                        <span class="text-sm font-mono font-bold tabular-nums text-slate-800 dark:text-slate-100">{{ $assigned }}</span>
                        @endif
                    </td>
                    <td class="px-5 py-4">
                        @if($atCap)
                        <span class="inline-flex items-center gap-1.5 text-[11px] font-bold text-red-700 dark:text-red-400 bg-red-50 dark:bg-red-950/40 rounded-full px-2.5 py-1">
                            <span class="w-1.5 h-1.5 rounded-full bg-red-500"></span>
                            At cap
                        </span>
                        @else
                        <span class="inline-flex items-center gap-1.5 text-[11px] font-bold text-emerald-700 dark:text-emerald-400 bg-emerald-50 dark:bg-emerald-950/40 rounded-full px-2.5 py-1">
                            <span class="w-1.5 h-1.5 rounded-full bg-emerald-500"></span>
                            Available
                        </span>
                        @endif
                    </td>
                    <td class="px-5 py-4">
                        <form method="POST" action="{{ route('calls.round-robin-setup.update', $tsa) }}" class="flex items-center gap-2">
                            @csrf
                            <input type="number" name="daily_lead_cap" min="1" value="{{ $cap }}" placeholder="Unlimited"
                                   class="w-24 text-sm font-mono border border-slate-200 dark:border-slate-700 rounded-lg px-3 py-1.5 bg-slate-50 dark:bg-slate-800 text-slate-800 dark:text-slate-100 focus:outline-none focus:ring-2 focus:ring-yellow-500 focus:bg-white dark:focus:bg-slate-900 transition-colors">
                            <button type="submit"
                                    class="text-xs font-bold text-white bg-primary hover:bg-primary-dark rounded-lg px-3.5 py-1.5 cursor-pointer transition-colors">
                                Save
                            </button>
                        </form>
                    </td>
                </tr>
            @endforeach
        </tbody>
    </table>
    </div>
    @endif
</div>
