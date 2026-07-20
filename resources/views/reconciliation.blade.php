@extends('layouts.app')
@section('title', 'Reconciliation')
@section('subtitle', 'Pancake completeness & tag-drift history · Every run, not just the latest')

@section('content')

{{-- LAST RUN BANNER — same red/green status language as Sync Health's stale banner,
     but keyed off whether the most recent reconciliation run found anything, since
     "no data yet" and "clean run" both need distinct treatment here. --}}
@if(!$lastRun)
<div class="mb-6 flex items-center gap-4 bg-slate-50 dark:bg-slate-800/60 border border-slate-200 dark:border-slate-700 rounded-xl px-6 py-4">
    <svg class="w-6 h-6 text-slate-400 shrink-0" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
        <path stroke-linecap="round" stroke-linejoin="round" d="M13 16h-1v-4h-1m1-4h.01M12 2a10 10 0 100 20A10 10 0 0012 2z"/>
    </svg>
    <p class="text-sm font-mono text-slate-500 dark:text-slate-400">
        No reconciliation runs recorded yet — the scheduled `pancake:reconcile` command hasn't run since this page shipped.
    </p>
</div>
@elseif($lastRun->has_issues)
<div class="mb-6 flex items-center gap-4 bg-orange-50 dark:bg-orange-950/40 border border-orange-200 dark:border-orange-800 rounded-xl px-6 py-4">
    <svg class="w-6 h-6 text-orange-500 shrink-0" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
        <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v2m0 4h.01M10.29 3.86L1.82 18a2 2 0 001.71 3h16.94a2 2 0 001.71-3L13.71 3.86a2 2 0 00-3.42 0z"/>
    </svg>
    <div class="min-w-0">
        <p class="text-sm font-bold font-mono text-orange-700 dark:text-orange-400">
            Latest run ({{ $lastRun->ran_at->diffForHumans() }}) found {{ $lastRun->issue_count }} issue{{ $lastRun->issue_count === 1 ? '' : 's' }}
        </p>
        <p class="text-xs font-mono text-orange-600 dark:text-orange-400 mt-0.5">
            See the "Issues" column below, or <a href="{{ route('reconciliation.show', $lastRun) }}" class="underline font-bold">open the latest run</a> for the full detail.
        </p>
    </div>
</div>
@else
<div class="mb-6 flex items-center gap-4 bg-green-50 dark:bg-green-950/40 border border-green-200 dark:border-green-800 rounded-xl px-6 py-4">
    <svg class="w-6 h-6 text-green-500 shrink-0" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
        <path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75L11.25 15 15 9.75M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>
    </svg>
    <div class="min-w-0">
        <p class="text-sm font-bold font-mono text-green-700 dark:text-green-400">Latest run clean</p>
        <p class="text-xs font-mono text-green-600 dark:text-green-400 mt-0.5">
            Ran {{ $lastRun->ran_at->diffForHumans() }} · checked {{ $lastRun->checked_date?->toDateString() ?? 'unknown date' }} · no issues found
        </p>
    </div>
</div>
@endif

{{-- SUMMARY STAT CARDS — same .stat-card pattern as Sync Health/Dashboard. --}}
<div class="grid grid-cols-1 sm:grid-cols-3 gap-5 mb-8">

    <div class="stat-card bg-white dark:bg-slate-900 rounded-xl border border-slate-200 dark:border-slate-700 p-5 shadow-sm flex items-start gap-4">
        <div class="w-12 h-12 rounded-full bg-blue-50 dark:bg-blue-950/40 flex items-center justify-center shrink-0">
            <svg class="w-6 h-6 text-blue-600" fill="none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" d="M16.023 9.348h4.992v-.001M2.985 19.644v-4.992m0 0h4.992m-4.993 0l3.181 3.183a8.25 8.25 0 0013.803-3.7M4.031 9.865a8.25 8.25 0 0113.803-3.7l3.181 3.182m0-4.991v4.99"/>
            </svg>
        </div>
        <div class="min-w-0">
            <p class="text-xs font-mono font-semibold text-slate-400 uppercase tracking-wider mb-1">Total Runs</p>
            <p class="text-2xl font-bold text-slate-800 dark:text-slate-100 font-mono leading-none" style="font-variant-numeric: tabular-nums">
                {{ number_format($totalRuns) }}
            </p>
            <p class="mt-1.5 text-xs text-slate-400 font-mono">All-time reconciliation runs</p>
        </div>
    </div>

    <div class="stat-card bg-white dark:bg-slate-900 rounded-xl border border-orange-200 dark:border-orange-800 p-5 shadow-sm flex items-start gap-4">
        <div class="w-12 h-12 rounded-full bg-orange-50 dark:bg-orange-950/40 flex items-center justify-center shrink-0">
            <svg class="w-6 h-6 text-orange-500" fill="none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m9-.75a9 9 0 11-18 0 9 9 0 0118 0zm-9 3.75h.008v.008H12v-.008z"/>
            </svg>
        </div>
        <div class="min-w-0">
            <p class="text-xs font-mono font-semibold text-orange-500 dark:text-orange-400 uppercase tracking-wider mb-1">Runs With Issues</p>
            <p class="text-2xl font-bold text-slate-800 dark:text-slate-100 font-mono leading-none" style="font-variant-numeric: tabular-nums">
                {{ number_format($runsWithIssues) }}
            </p>
            <p class="mt-1.5 text-xs text-slate-400 font-mono">All-time flagged runs</p>
        </div>
    </div>

    <div class="stat-card bg-white dark:bg-slate-900 rounded-xl border border-slate-200 dark:border-slate-700 p-5 shadow-sm flex items-start gap-4">
        <div class="w-12 h-12 rounded-full bg-emerald-50 dark:bg-emerald-950/40 flex items-center justify-center shrink-0">
            <svg class="w-6 h-6 text-emerald-600" fill="none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75L11.25 15 15 9.75M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>
            </svg>
        </div>
        <div class="min-w-0">
            <p class="text-xs font-mono font-semibold text-slate-400 uppercase tracking-wider mb-1">Clean Rate</p>
            <p class="text-2xl font-bold text-slate-800 dark:text-slate-100 font-mono leading-none" style="font-variant-numeric: tabular-nums">
                {{ $totalRuns > 0 ? number_format(($totalRuns - $runsWithIssues) / $totalRuns * 100, 1) . '%' : '—' }}
            </p>
            <p class="mt-1.5 text-xs text-slate-400 font-mono">Of all-time runs</p>
        </div>
    </div>

</div>

{{-- FULL HISTORY TABLE — one row per ReconciliationRun, most recent first. Mirrors
     Sync Health's table wrapper/header/empty-state/pagination structure. --}}
<div class="bg-white dark:bg-slate-900 rounded-xl border border-slate-200 dark:border-slate-700 shadow-sm overflow-hidden">
    <div class="px-5 py-4 border-b border-slate-100 dark:border-slate-700 flex items-center justify-between flex-wrap gap-3">
        <div>
            <h2 class="text-sm font-bold text-slate-700 dark:text-slate-200 font-mono">Reconciliation History</h2>
            <p class="text-xs font-mono text-slate-400 mt-0.5">Every completeness + tag-drift check, most recent first</p>
        </div>
        <div class="flex items-center gap-3">
            <input type="text" data-table-filter="reconciliationTable" placeholder="Filter…" aria-label="Filter reconciliation history"
                   class="w-40 rounded-lg border border-slate-200 dark:border-slate-700 bg-white dark:bg-slate-800 px-3 py-1.5 text-xs font-mono text-slate-800 dark:text-slate-100 placeholder-slate-400 dark:placeholder-slate-500 focus:outline-none focus:ring-2 focus:ring-yellow-500">
            @if(!$runs->isEmpty())
            @include('partials.table-actions', ['target' => 'reconciliationTable', 'name' => 'reconciliation-history'])
            @endif
        </div>
    </div>

    @if($runs->isEmpty())
    <div class="py-16 flex flex-col items-center justify-center text-center gap-3">
        <svg class="w-10 h-10 text-slate-200 dark:text-slate-700" fill="none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" d="M16.023 9.348h4.992v-.001M2.985 19.644v-4.992m0 0h4.992m-4.993 0l3.181 3.183a8.25 8.25 0 0013.803-3.7M4.031 9.865a8.25 8.25 0 0113.803-3.7l3.181 3.182m0-4.991v4.99"/>
        </svg>
        <p class="text-sm font-mono text-slate-400">No reconciliation runs recorded yet</p>
    </div>
    @else
    <div class="overflow-x-auto" id="reconciliationTable" data-sortable-table>
    <table class="w-full text-sm">
        <thead>
            <tr class="bg-slate-50 dark:bg-slate-800 text-xs font-mono text-slate-400 uppercase tracking-wide">
                <th class="px-5 py-2.5 text-left" data-sort-key="ran_at">Ran At</th>
                <th class="px-4 py-2.5 text-left" data-sort-key="checked_date">Checked Date</th>
                <th class="px-4 py-2.5 text-left" data-sort-key="status">Status</th>
                <th class="px-4 py-2.5 text-right" data-sort-key="local_count">Local Count</th>
                <th class="px-4 py-2.5 text-right" data-sort-key="pancake_count">Pancake Count</th>
                <th class="px-4 py-2.5 text-left">Issues</th>
                <th class="px-4 py-2.5 text-left"></th>
            </tr>
        </thead>
        <tbody class="divide-y divide-slate-100 dark:divide-slate-700">
            @foreach($runs as $run)
            <tr class="hover:bg-slate-50 dark:hover:bg-slate-800 transition-colors">
                <td class="px-5 py-3 font-mono text-xs text-slate-600 dark:text-slate-300 whitespace-nowrap" data-sort-key="ran_at" data-sort-value="{{ $run->ran_at->timestamp }}">
                    {{ $run->ran_at->format('M j, Y g:i A') }}
                </td>
                <td class="px-4 py-3 font-mono text-xs text-slate-600 dark:text-slate-300 whitespace-nowrap" data-sort-key="checked_date" data-sort-value="{{ $run->checked_date?->timestamp ?? 0 }}">
                    {{ $run->checked_date?->toDateString() ?? '—' }}
                </td>
                <td class="px-4 py-3" data-sort-key="status" data-sort-value="{{ $run->has_issues ? 0 : 1 }}">
                    @if($run->has_issues)
                    <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-[10px] font-semibold font-mono whitespace-nowrap bg-orange-100 dark:bg-orange-900/40 text-orange-700 dark:text-orange-400">
                        <svg class="w-3 h-3" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m0 3.75h.008M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                        {{ $run->issue_count }} issue{{ $run->issue_count === 1 ? '' : 's' }}
                    </span>
                    @else
                    <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-[10px] font-semibold font-mono whitespace-nowrap bg-green-100 dark:bg-green-900/40 text-green-700 dark:text-green-400">
                        <svg class="w-3 h-3" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M4.5 12.75l6 6 9-13.5"/></svg>
                        Clean
                    </span>
                    @endif
                </td>
                <td class="px-4 py-3 font-mono text-xs text-right text-slate-700 dark:text-slate-200" data-sort-key="local_count" data-sort-value="{{ $run->local_count ?? -1 }}">
                    {{ $run->local_count !== null ? number_format($run->local_count) : '—' }}
                </td>
                <td class="px-4 py-3 font-mono text-xs text-right text-slate-700 dark:text-slate-200" data-sort-key="pancake_count" data-sort-value="{{ $run->pancake_count ?? -1 }}">
                    {{ $run->pancake_count !== null ? number_format($run->pancake_count) : '—' }}
                </td>
                <td class="px-4 py-3 font-mono text-xs text-slate-600 dark:text-slate-300 max-w-sm truncate"
                    @if($run->has_issues) title="{{ implode(' | ', $run->issues) }}" @endif>
                    @if($run->has_issues)
                    {{ \Illuminate\Support\Str::limit($run->issues[0], 70) }}
                    @else
                    <span class="text-slate-300 dark:text-slate-600">—</span>
                    @endif
                </td>
                <td class="px-4 py-3 text-right whitespace-nowrap">
                    <a href="{{ route('reconciliation.show', $run) }}" class="text-xs font-mono font-semibold text-yellow-700 dark:text-yellow-500 hover:underline">
                        View details
                    </a>
                </td>
            </tr>
            @endforeach
        </tbody>
    </table>
    </div>

    <div class="px-5 py-4 border-t border-slate-100 dark:border-slate-700">
        {{ $runs->links('partials.pagination') }}
    </div>
    @endif
</div>

@endsection
