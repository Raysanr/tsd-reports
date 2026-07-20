@extends('layouts.app')
@section('title', 'Reconciliation Run Detail')
@section('subtitle', 'Run from ' . $run->ran_at->format('M j, Y g:i A'))

@section('content')

<a href="{{ route('reconciliation') }}" class="inline-flex items-center gap-1.5 text-xs font-mono font-semibold text-slate-500 dark:text-slate-400 hover:text-slate-700 dark:hover:text-slate-200 mb-4">
    <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M10.5 19.5L3 12m0 0l7.5-7.5M3 12h18"/></svg>
    Back to history
</a>

{{-- STATUS BANNER — same red/green language as the history page's per-row status pill. --}}
@if($run->has_issues)
<div class="mb-6 flex items-center gap-4 bg-orange-50 dark:bg-orange-950/40 border border-orange-200 dark:border-orange-800 rounded-xl px-6 py-4">
    <svg class="w-6 h-6 text-orange-500 shrink-0" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
        <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v2m0 4h.01M10.29 3.86L1.82 18a2 2 0 001.71 3h16.94a2 2 0 001.71-3L13.71 3.86a2 2 0 00-3.42 0z"/>
    </svg>
    <p class="text-sm font-bold font-mono text-orange-700 dark:text-orange-400">
        {{ $run->issue_count }} issue{{ $run->issue_count === 1 ? '' : 's' }} found on this run
    </p>
</div>
@else
<div class="mb-6 flex items-center gap-4 bg-green-50 dark:bg-green-950/40 border border-green-200 dark:border-green-800 rounded-xl px-6 py-4">
    <svg class="w-6 h-6 text-green-500 shrink-0" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
        <path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75L11.25 15 15 9.75M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>
    </svg>
    <p class="text-sm font-bold font-mono text-green-700 dark:text-green-400">No issues found on this run</p>
</div>
@endif

{{-- COMPLETENESS SIDE-BY-SIDE — the raw local vs Pancake counts behind the
     completeness-check issue string (if any), so this isn't just re-reading the
     same formatted sentence the history table already shows. --}}
<div class="grid grid-cols-1 sm:grid-cols-3 gap-5 mb-8">

    <div class="stat-card bg-white dark:bg-slate-900 rounded-xl border border-slate-200 dark:border-slate-700 p-5 shadow-sm">
        <p class="text-xs font-mono font-semibold text-slate-400 uppercase tracking-wider mb-1">Checked Date</p>
        <p class="text-2xl font-bold text-slate-800 dark:text-slate-100 font-mono leading-none">
            {{ $run->checked_date?->toDateString() ?? '—' }}
        </p>
        <p class="mt-1.5 text-xs text-slate-400 font-mono">Asia/Manila calendar day</p>
    </div>

    <div class="stat-card bg-white dark:bg-slate-900 rounded-xl border border-slate-200 dark:border-slate-700 p-5 shadow-sm">
        <p class="text-xs font-mono font-semibold text-slate-400 uppercase tracking-wider mb-1">Local Count</p>
        <p class="text-2xl font-bold text-slate-800 dark:text-slate-100 font-mono leading-none" style="font-variant-numeric: tabular-nums">
            {{ $run->local_count !== null ? number_format($run->local_count) : '—' }}
        </p>
        <p class="mt-1.5 text-xs text-slate-400 font-mono">Orders synced with matching pancake_updated_at</p>
    </div>

    <div class="stat-card bg-white dark:bg-slate-900 rounded-xl border border-slate-200 dark:border-slate-700 p-5 shadow-sm">
        <p class="text-xs font-mono font-semibold text-slate-400 uppercase tracking-wider mb-1">Pancake Count</p>
        <p class="text-2xl font-bold text-slate-800 dark:text-slate-100 font-mono leading-none" style="font-variant-numeric: tabular-nums">
            {{ $run->pancake_count !== null ? number_format($run->pancake_count) : '—' }}
        </p>
        <p class="mt-1.5 text-xs text-slate-400 font-mono">Pancake's own updated_at count for the day</p>
    </div>

</div>

{{-- FULL ISSUE LIST — every issue string from this run, completeness + tag-drift
     both included, in the same order handle() produced them. --}}
<div class="bg-white dark:bg-slate-900 rounded-xl border border-slate-200 dark:border-slate-700 shadow-sm overflow-hidden">
    <div class="px-5 py-4 border-b border-slate-100 dark:border-slate-700">
        <h2 class="text-sm font-bold text-slate-700 dark:text-slate-200 font-mono">Issues</h2>
        <p class="text-xs font-mono text-slate-400 mt-0.5">Raw issue strings recorded for this run</p>
    </div>

    @if(empty($run->issues))
    <div class="py-16 flex flex-col items-center justify-center text-center gap-3">
        <svg class="w-10 h-10 text-slate-200 dark:text-slate-700" fill="none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75L11.25 15 15 9.75M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>
        </svg>
        <p class="text-sm font-mono text-slate-400">No issues to show</p>
    </div>
    @else
    <ul class="divide-y divide-slate-100 dark:divide-slate-700">
        @foreach($run->issues as $issue)
        <li class="px-5 py-3 flex items-start gap-3">
            <svg class="w-4 h-4 text-orange-500 shrink-0 mt-0.5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v2m0 4h.01M10.29 3.86L1.82 18a2 2 0 001.71 3h16.94a2 2 0 001.71-3L13.71 3.86a2 2 0 00-3.42 0z"/>
            </svg>
            <p class="text-sm font-mono text-slate-700 dark:text-slate-200">{{ $issue }}</p>
        </li>
        @endforeach
    </ul>
    @endif
</div>

@endsection
