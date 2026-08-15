@extends('layouts.calls')
@section('title', 'Leads Setup')
@section('subtitle', "Per-TSA daily lead cap — round-robin skips a TSA once they've hit it today")

@section('content')

<div class="mb-6">
    <form method="GET" class="flex items-center gap-3">
        <select name="team" onchange="this.form.submit()"
                class="text-sm font-mono border border-slate-300 dark:border-slate-600 rounded-lg px-3 py-2 bg-white dark:bg-slate-800 text-slate-800 dark:text-slate-100 focus:outline-none focus:ring-2 focus:ring-yellow-500">
            <option value="">All teams</option>
            @foreach($teams as $team)
            <option value="{{ $team }}" @selected($selectedTeam === $team)>{{ $team }}</option>
            @endforeach
        </select>
    </form>
</div>

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
    <table class="w-full text-sm font-mono">
        <thead class="bg-slate-100 dark:bg-slate-700 border-b border-slate-200 dark:border-slate-700">
            <tr>
                <th class="px-4 py-3 text-left text-[11px] font-bold text-slate-400 uppercase tracking-wide">TSA</th>
                <th class="px-4 py-3 text-left text-[11px] font-bold text-slate-400 uppercase tracking-wide">Team</th>
                <th class="px-4 py-3 text-left text-[11px] font-bold text-slate-400 uppercase tracking-wide">Assigned today</th>
                <th class="px-4 py-3 text-left text-[11px] font-bold text-slate-400 uppercase tracking-wide">Status</th>
                <th class="px-4 py-3 text-left text-[11px] font-bold text-slate-400 uppercase tracking-wide">Daily cap</th>
            </tr>
        </thead>
        <tbody class="divide-y divide-slate-100 dark:divide-slate-700">
            @foreach($tsas as $row)
                @php $tsa = $row['tsa']; $assigned = $row['assigned_today']; $cap = $tsa->daily_lead_cap; $atCap = $cap !== null && $assigned >= $cap; @endphp
                <tr class="hover:bg-slate-50 dark:hover:bg-slate-800 transition-colors duration-150">
                    <td class="px-4 py-3">
                        <div class="font-bold text-slate-800 dark:text-slate-100">{{ $tsa->display_name }}</div>
                        <div class="text-[11px] text-slate-400">{{ $tsa->tsa_key }}</div>
                    </td>
                    <td class="px-4 py-3 text-slate-500 dark:text-slate-400">{{ $tsa->team }}</td>
                    <td class="px-4 py-3 font-bold tabular-nums {{ $atCap ? 'text-red-600 dark:text-red-400' : 'text-slate-800 dark:text-slate-100' }}">
                        {{ $assigned }}{{ $cap !== null ? ' / ' . $cap : '' }}
                    </td>
                    <td class="px-4 py-3">
                        @if($atCap)
                        <span class="text-[11px] font-bold text-red-600 dark:text-red-400 bg-red-50 dark:bg-red-950/40 border border-red-200 dark:border-red-800 rounded-full px-3 py-1">
                            At cap — skipped
                        </span>
                        @else
                        <span class="text-[11px] text-slate-300 dark:text-slate-600">—</span>
                        @endif
                    </td>
                    <td class="px-4 py-3">
                        <form method="POST" action="{{ route('calls.round-robin-setup.update', $tsa) }}" class="flex items-center gap-2">
                            @csrf
                            <input type="number" name="daily_lead_cap" min="1" value="{{ $cap }}" placeholder="Unlimited"
                                   class="w-24 text-sm font-mono border border-slate-300 dark:border-slate-600 rounded-lg px-3 py-1.5 bg-white dark:bg-slate-800 text-slate-800 dark:text-slate-100 focus:outline-none focus:ring-2 focus:ring-yellow-500">
                            <button type="submit"
                                    class="text-xs font-semibold text-white bg-primary hover:bg-primary-dark rounded-lg px-3 py-1.5 cursor-pointer">
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

@endsection
