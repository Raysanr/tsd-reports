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
@endphp

<div class="mb-6 flex items-center gap-3 flex-wrap">
    <form method="GET" class="flex items-center gap-3 flex-wrap">
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
    </form>
</div>

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
