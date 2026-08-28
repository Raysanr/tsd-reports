@extends('layouts.calls')
@section('title', 'Call Sync Health')
@section('subtitle', 'Pancake lead-sync run history')

@section('content')

<div class="bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-700 shadow-sm overflow-hidden">
    @if($runs->isEmpty())
    <div class="py-20 flex flex-col items-center justify-center gap-3">
        <svg class="w-10 h-10 text-slate-200 dark:text-slate-700" fill="none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" d="M16.023 9.348h4.992v-.001M2.985 19.644v-4.992m0 0h4.992m-4.993 0l3.181 3.183a8.25 8.25 0 0013.803-3.7M4.031 9.865a8.25 8.25 0 0113.803-3.7l3.181 3.182m0-4.991v4.99"/>
        </svg>
        <p class="text-sm font-mono text-slate-400">No sync runs recorded yet.</p>
        <p class="text-xs font-mono text-slate-300 dark:text-slate-600">Runs appear here automatically once the scheduler starts pulling leads.</p>
    </div>
    @else
    <div class="overflow-x-auto overflow-y-auto max-h-[70vh]">
    <table class="w-full text-sm font-mono">
        <thead class="bg-slate-100 dark:bg-slate-700 border-b border-slate-200 dark:border-slate-700 sticky top-0 z-10">
            <tr>
                <th class="px-4 py-3 text-left text-[11px] font-bold text-slate-400 uppercase tracking-wide">Ran At</th>
                <th class="px-4 py-3 text-left text-[11px] font-bold text-slate-400 uppercase tracking-wide">Fetched</th>
                <th class="px-4 py-3 text-left text-[11px] font-bold text-slate-400 uppercase tracking-wide">New Leads</th>
                <th class="px-4 py-3 text-left text-[11px] font-bold text-slate-400 uppercase tracking-wide">Skipped</th>
                <th class="px-4 py-3 text-left text-[11px] font-bold text-slate-400 uppercase tracking-wide">Duration</th>
                <th class="px-4 py-3 text-left text-[11px] font-bold text-slate-400 uppercase tracking-wide">Result</th>
            </tr>
        </thead>
        <tbody class="divide-y divide-slate-100 dark:divide-slate-700">
            @foreach($runs as $run)
            <tr class="hover:bg-slate-50 dark:hover:bg-slate-800 align-top">
                <td class="px-4 py-3 text-slate-600 dark:text-slate-300">{{ $run->ran_at->format('M j, Y g:i:s A') }}</td>
                <td class="px-4 py-3 text-slate-600 dark:text-slate-300">{{ $run->total_fetched }}</td>
                <td class="px-4 py-3 text-slate-600 dark:text-slate-300">{{ $run->new_leads }}</td>
                <td class="px-4 py-3 text-slate-600 dark:text-slate-300">{{ $run->skipped }}</td>
                <td class="px-4 py-3 text-slate-600 dark:text-slate-300">{{ number_format($run->duration_ms) }}ms</td>
                <td class="px-4 py-3">
                    @if($run->success)
                    <span class="inline-flex px-2 py-0.5 rounded-full text-[10px] font-bold uppercase tracking-wide bg-emerald-100 dark:bg-emerald-900/40 text-emerald-700 dark:text-emerald-400">Success</span>
                    @else
                    <span class="inline-flex px-2 py-0.5 rounded-full text-[10px] font-bold uppercase tracking-wide bg-red-100 dark:bg-red-900/40 text-red-700 dark:text-red-400">Failed</span>
                    <p class="text-red-500 dark:text-red-400 text-xs mt-1 max-w-md break-words">{{ $run->error_message }}</p>
                    @endif
                </td>
            </tr>
            @endforeach
        </tbody>
    </table>
    </div>
    <div class="px-4 py-3 border-t border-slate-100 dark:border-slate-700">
        {{ $runs->links('partials.pagination') }}
    </div>
    @endif
</div>

@endsection
