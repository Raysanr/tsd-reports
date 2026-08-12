@extends('layouts.calls')
@section('title', 'Call Log')
@section('subtitle', 'Real calls reported by each TSA\'s own phone — the basis for load reimbursement')

@section('content')

<div class="mb-6">
    <form method="GET" class="flex items-center gap-3 flex-wrap">
        <input type="date" name="date_from" value="{{ $dateFrom }}"
               class="text-sm font-mono border border-slate-300 dark:border-slate-600 rounded-lg px-3 py-2 bg-white dark:bg-slate-800 text-slate-800 dark:text-slate-100 focus:outline-none focus:ring-2 focus:ring-yellow-500">
        <span class="text-slate-400 text-sm font-mono">to</span>
        <input type="date" name="date_to" value="{{ $dateTo }}"
               class="text-sm font-mono border border-slate-300 dark:border-slate-600 rounded-lg px-3 py-2 bg-white dark:bg-slate-800 text-slate-800 dark:text-slate-100 focus:outline-none focus:ring-2 focus:ring-yellow-500">
        <button type="submit" class="text-sm font-mono font-semibold text-white bg-primary hover:bg-primary-dark rounded-lg px-4 py-2 cursor-pointer">
            Apply
        </button>
    </form>
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
                    <th class="px-4 py-3 text-right text-[11px] font-bold text-slate-400 uppercase tracking-wide">Outgoing</th>
                    <th class="px-4 py-3 text-right text-[11px] font-bold text-slate-400 uppercase tracking-wide">Incoming</th>
                    <th class="px-4 py-3 text-right text-[11px] font-bold text-slate-400 uppercase tracking-wide">Missed</th>
                    <th class="px-4 py-3 text-right text-[11px] font-bold text-slate-400 uppercase tracking-wide">Total Duration</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-100 dark:divide-slate-700">
                @foreach($rows as $row)
                <tr class="hover:bg-slate-50 dark:hover:bg-slate-800">
                    <td class="px-4 py-3 font-semibold text-slate-700 dark:text-slate-200">{{ $row['tsa']->display_name }}</td>
                    <td class="px-4 py-3 text-right text-slate-600 dark:text-slate-300">{{ $row['total_calls'] }}</td>
                    <td class="px-4 py-3 text-right text-slate-600 dark:text-slate-300">{{ $row['outgoing'] }}</td>
                    <td class="px-4 py-3 text-right text-slate-600 dark:text-slate-300">{{ $row['incoming'] }}</td>
                    <td class="px-4 py-3 text-right text-slate-600 dark:text-slate-300">{{ $row['missed'] }}</td>
                    <td class="px-4 py-3 text-right text-slate-600 dark:text-slate-300">{{ gmdate('H:i:s', $row['total_duration_s']) }}</td>
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
