@extends('layouts.app')
@section('title', 'Sync Health')
@section('subtitle', 'Background sync status · Full run history')

@section('content')

{{-- STATUS BANNER — same red/yellow "stale" visual language the Dashboard's Sync
     button already uses (dashboard.blade.php's $syncTooltip/$stats['sync_stale']
     block), just expanded into a full banner here instead of a button tooltip.
     Color is never the only signal: icon + heading text carry the same meaning. --}}
@if($health['sync_stale'])
<div class="mb-6 flex items-center gap-4 bg-red-50 dark:bg-red-950/40 border border-red-200 dark:border-red-800 rounded-xl px-6 py-4">
    <svg class="w-6 h-6 text-red-500 shrink-0" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
        <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v2m0 4h.01M10.29 3.86L1.82 18a2 2 0 001.71 3h16.94a2 2 0 001.71-3L13.71 3.86a2 2 0 00-3.42 0z"/>
    </svg>
    <div class="min-w-0">
        <p class="text-sm font-bold font-mono text-red-700 dark:text-red-400">Sync appears stale</p>
        <p class="text-xs font-mono text-red-600 dark:text-red-400 mt-0.5">
            Last synced {{ $health['last_synced'] ? \Illuminate\Support\Carbon::parse($health['last_synced'])->diffForHumans() : 'never' }}
            — expected every {{ $health['sync_interval'] }} min. The background scheduler may have stopped running; check the server, `schedule:run`, or the Pancake API key.
        </p>
    </div>
</div>
@else
<div class="mb-6 flex items-center gap-4 bg-green-50 dark:bg-green-950/40 border border-green-200 dark:border-green-800 rounded-xl px-6 py-4">
    <svg class="w-6 h-6 text-green-500 shrink-0" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
        <path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75L11.25 15 15 9.75M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>
    </svg>
    <div class="min-w-0">
        <p class="text-sm font-bold font-mono text-green-700 dark:text-green-400">Sync healthy</p>
        <p class="text-xs font-mono text-green-600 dark:text-green-400 mt-0.5">
            Last synced {{ \Illuminate\Support\Carbon::parse($health['last_synced'])->diffForHumans() }} · every {{ $health['sync_interval'] }} min
        </p>
    </div>
</div>
@endif

{{-- SUMMARY STAT CARDS — same .stat-card visual pattern as the Dashboard's KPI
     row (icon badge left, label/value/subtitle stacked right). --}}
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
            <p class="mt-1.5 text-xs text-slate-400 font-mono">All-time sync attempts</p>
        </div>
    </div>

    <div class="stat-card bg-white dark:bg-slate-900 rounded-xl border border-rose-200 dark:border-rose-800 p-5 shadow-sm flex items-start gap-4">
        <div class="w-12 h-12 rounded-full bg-rose-50 dark:bg-rose-950/40 flex items-center justify-center shrink-0">
            <svg class="w-6 h-6 text-rose-500" fill="none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m9-.75a9 9 0 11-18 0 9 9 0 0118 0zm-9 3.75h.008v.008H12v-.008z"/>
            </svg>
        </div>
        <div class="min-w-0">
            <p class="text-xs font-mono font-semibold text-rose-500 dark:text-rose-400 uppercase tracking-wider mb-1">Failed Runs</p>
            <p class="text-2xl font-bold text-slate-800 dark:text-slate-100 font-mono leading-none" style="font-variant-numeric: tabular-nums">
                {{ number_format($failedRuns) }}
            </p>
            <p class="mt-1.5 text-xs text-slate-400 font-mono">All-time failures</p>
        </div>
    </div>

    <div class="stat-card bg-white dark:bg-slate-900 rounded-xl border border-slate-200 dark:border-slate-700 p-5 shadow-sm flex items-start gap-4">
        <div class="w-12 h-12 rounded-full bg-emerald-50 dark:bg-emerald-950/40 flex items-center justify-center shrink-0">
            <svg class="w-6 h-6 text-emerald-600" fill="none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75L11.25 15 15 9.75M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>
            </svg>
        </div>
        <div class="min-w-0">
            <p class="text-xs font-mono font-semibold text-slate-400 uppercase tracking-wider mb-1">Success Rate</p>
            <p class="text-2xl font-bold text-slate-800 dark:text-slate-100 font-mono leading-none" style="font-variant-numeric: tabular-nums">
                {{ $successRate !== null ? $successRate.'%' : '—' }}
            </p>
            <p class="mt-1.5 text-xs text-slate-400 font-mono">Of all-time runs</p>
        </div>
    </div>

</div>

{{-- RETRY-A-DATE — distinct from the Dashboard's Sync button (which syncs a date
     RANGE): this re-runs the sync for exactly one date, useful when the history
     table below shows one specific day failed or looks incomplete. Plain form
     POST + redirect (not fetch/JSON) since a full page reload here is cheap and
     keeps this action independent of the Dashboard's JS sync flow. --}}
<div class="bg-white dark:bg-slate-900 rounded-xl border border-slate-200 dark:border-slate-700 shadow-sm px-5 py-4 mb-6">
    <h2 class="text-sm font-bold text-slate-700 dark:text-slate-200 font-mono mb-1">Retry a Date</h2>
    <p class="text-xs font-mono text-slate-400 mb-3">Manually re-run the sync for one specific day.</p>
    <form method="POST" action="{{ route('sync-health.retry') }}" class="flex items-end gap-3 flex-wrap">
        @csrf
        <div>
            <label for="retryDate" class="block text-[10px] font-mono font-semibold text-slate-400 uppercase tracking-wide mb-1">Date</label>
            <input type="date" name="date" id="retryDate" required
                   value="{{ old('date', now()->toDateString()) }}"
                   max="{{ now()->toDateString() }}"
                   class="rounded-lg border border-slate-200 dark:border-slate-700 bg-white dark:bg-slate-800 px-3 py-1.5 text-sm font-mono text-slate-800 dark:text-slate-100 focus:outline-none focus:ring-2 focus:ring-yellow-500 focus:border-yellow-500">
        </div>
        <button type="submit"
                class="inline-flex items-center gap-1.5 px-4 py-1.5 bg-yellow-700 hover:bg-yellow-800 text-white text-xs font-semibold rounded-lg transition-colors cursor-pointer whitespace-nowrap">
            <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/>
            </svg>
            Retry Sync
        </button>
        @error('date')
        <p class="text-xs font-mono text-red-600 dark:text-red-400 w-full">{{ $message }}</p>
        @enderror
    </form>
</div>

{{-- FULL HISTORY TABLE — every SyncRun, paginated, sortable/filterable. A genuine
     per-entity table (one row = one sync run), unlike the hourly-pivot tables
     elsewhere in this app that deliberately skip the sortable-table pattern. --}}
<div class="bg-white dark:bg-slate-900 rounded-xl border border-slate-200 dark:border-slate-700 shadow-sm overflow-hidden">
    <div class="px-5 py-4 border-b border-slate-100 dark:border-slate-700 flex items-center justify-between flex-wrap gap-3">
        <div>
            <h2 class="text-sm font-bold text-slate-700 dark:text-slate-200 font-mono">Sync Run History</h2>
            <p class="text-xs font-mono text-slate-400 mt-0.5">Every background/manual sync attempt, most recent first</p>
        </div>
        <div class="flex items-center gap-3">
            <input type="text" data-table-filter="syncRunsTable" placeholder="Filter…" aria-label="Filter sync run history"
                   class="w-40 rounded-lg border border-slate-200 dark:border-slate-700 bg-white dark:bg-slate-800 px-3 py-1.5 text-xs font-mono text-slate-800 dark:text-slate-100 placeholder-slate-400 dark:placeholder-slate-500 focus:outline-none focus:ring-2 focus:ring-yellow-500">
            @if(!$runs->isEmpty())
            @include('partials.table-actions', ['target' => 'syncRunsTable', 'name' => 'sync-health-history'])
            @endif
        </div>
    </div>

    @if($runs->isEmpty())
    <div class="py-16 flex flex-col items-center justify-center text-center gap-3">
        <svg class="w-10 h-10 text-slate-200 dark:text-slate-700" fill="none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" d="M16.023 9.348h4.992v-.001M2.985 19.644v-4.992m0 0h4.992m-4.993 0l3.181 3.183a8.25 8.25 0 0013.803-3.7M4.031 9.865a8.25 8.25 0 0113.803-3.7l3.181 3.182m0-4.991v4.99"/>
        </svg>
        <p class="text-sm font-mono text-slate-400">No sync runs recorded yet</p>
    </div>
    @else
    <div class="overflow-x-auto" id="syncRunsTable" data-sortable-table>
    <table class="w-full text-sm">
        <thead>
            <tr class="bg-slate-50 dark:bg-slate-800 text-xs font-mono text-slate-400 uppercase tracking-wide">
                <th class="px-5 py-2.5 text-left" data-sort-key="ran_at">Ran At</th>
                <th class="px-4 py-2.5 text-left" data-sort-key="status">Status</th>
                <th class="px-4 py-2.5 text-right" data-sort-key="total_synced">Total Synced</th>
                <th class="px-4 py-2.5 text-right" data-sort-key="new_orders">New Orders</th>
                <th class="px-4 py-2.5 text-right" data-sort-key="upsell_count">Upsell Count</th>
                <th class="px-4 py-2.5 text-right" data-sort-key="upsell_sales">Upsell Sales</th>
                <th class="px-4 py-2.5 text-right" data-sort-key="duration">Duration</th>
                <th class="px-4 py-2.5 text-left" data-sort-key="error">Error</th>
            </tr>
        </thead>
        <tbody class="divide-y divide-slate-100 dark:divide-slate-700">
            @foreach($runs as $run)
            @php
                $durationLabel = $run->duration_ms > 1000
                    ? number_format($run->duration_ms / 1000, 1) . 's'
                    : number_format($run->duration_ms) . 'ms';
                // Same redaction the flash message on retry() goes through (see
                // App\Support\SyncHealth::redactSecrets) — applied here too since
                // this column also puts error_message content in front of the
                // browser, just via a rendered table cell instead of a toast.
                $errorText = \App\Support\SyncHealth::redactSecrets($run->error_message);
            @endphp
            <tr class="hover:bg-slate-50 dark:hover:bg-slate-800 transition-colors">
                <td class="px-5 py-3 font-mono text-xs text-slate-600 dark:text-slate-300 whitespace-nowrap" data-sort-key="ran_at" data-sort-value="{{ $run->ran_at->timestamp }}">
                    {{ $run->ran_at->format('M j, Y g:i A') }}
                </td>
                <td class="px-4 py-3" data-sort-key="status" data-sort-value="{{ $run->success ? 1 : 0 }}">
                    @if($run->success)
                    <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-[10px] font-semibold font-mono whitespace-nowrap bg-green-100 dark:bg-green-900/40 text-green-700 dark:text-green-400">
                        <svg class="w-3 h-3" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M4.5 12.75l6 6 9-13.5"/></svg>
                        Success
                    </span>
                    @else
                    <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-[10px] font-semibold font-mono whitespace-nowrap bg-red-100 dark:bg-red-900/40 text-red-700 dark:text-red-400">
                        <svg class="w-3 h-3" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/></svg>
                        Failed
                    </span>
                    @endif
                </td>
                <td class="px-4 py-3 font-mono text-xs text-right text-slate-700 dark:text-slate-200" data-sort-key="total_synced" data-sort-value="{{ $run->total_synced }}">
                    {{ number_format($run->total_synced) }}
                </td>
                <td class="px-4 py-3 font-mono text-xs text-right text-slate-700 dark:text-slate-200" data-sort-key="new_orders" data-sort-value="{{ $run->new_orders }}">
                    {{ number_format($run->new_orders) }}
                </td>
                <td class="px-4 py-3 font-mono text-xs text-right text-slate-700 dark:text-slate-200" data-sort-key="upsell_count" data-sort-value="{{ $run->upsell_count }}">
                    {{ number_format($run->upsell_count) }}
                </td>
                <td class="px-4 py-3 font-mono text-xs text-right font-semibold text-accent" data-sort-key="upsell_sales" data-sort-value="{{ $run->upsell_sales }}">
                    ₱{{ number_format($run->upsell_sales, 2) }}
                </td>
                <td class="px-4 py-3 font-mono text-xs text-right text-slate-500 dark:text-slate-400" data-sort-key="duration" data-sort-value="{{ $run->duration_ms }}">
                    {{ $durationLabel }}
                </td>
                <td class="px-4 py-3 font-mono text-xs text-rose-600 dark:text-rose-400 max-w-xs truncate" data-sort-key="error" data-sort-value="{{ $run->success ? '' : $errorText }}"
                    @if(!$run->success && $errorText) title="{{ $errorText }}" @endif>
                    @if(!$run->success && $errorText)
                    {{ \Illuminate\Support\Str::limit($errorText, 60) }}
                    @else
                    <span class="text-slate-300 dark:text-slate-600">—</span>
                    @endif
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
