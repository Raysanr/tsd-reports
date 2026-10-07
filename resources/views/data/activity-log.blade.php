@extends('layouts.data')
@section('title', 'Activity Log')
@section('subtitle', 'Who edited what, across every Data Management page')

@section('content')

{{-- Reuses the plain app's own /audit-log table conventions (same
     wrapper/header/empty-state/pagination structure, see that page's own
     doc comment) — this is the SAME ActivityLog model/table, filtered to
     just this module's own action prefixes (projection.*, dsppr.*,
     tsa_sales.*, expected_income.*, cost_breakdown.*) via
     DataManagement\ActivityLogController. A normal user's own query
     there already excludes cost_breakdown.* server-side, so the module
     filter pills below never even render that option for her. --}}
<div class="mb-4 flex items-center gap-2 flex-wrap">
    <a href="{{ route('data.activity-log') }}"
       class="px-3 py-1.5 rounded-full text-xs font-mono font-semibold border {{ $module === '' ? 'bg-ink text-white dark:bg-slate-100 dark:text-slate-900 border-transparent' : 'border-line dark:border-slate-700 text-ink-muted dark:text-slate-400 hover:bg-slate-50 dark:hover:bg-slate-800' }}">
        All
    </a>
    @foreach($modules as $slug => $label)
    <a href="{{ route('data.activity-log', ['module' => $slug]) }}"
       class="px-3 py-1.5 rounded-full text-xs font-mono font-semibold border {{ $module === $slug ? 'bg-ink text-white dark:bg-slate-100 dark:text-slate-900 border-transparent' : 'border-line dark:border-slate-700 text-ink-muted dark:text-slate-400 hover:bg-slate-50 dark:hover:bg-slate-800' }}">
        {{ $label }}
    </a>
    @endforeach
</div>

<div class="bg-white dark:bg-slate-900 rounded-xl border border-line dark:border-slate-700 shadow-sm overflow-hidden">
    <div class="px-5 py-4 border-b border-line dark:border-slate-700 flex items-center justify-between flex-wrap gap-3">
        <div>
            <h2 class="text-sm font-bold text-ink dark:text-slate-200 font-mono">Activity History</h2>
            <p class="text-xs font-mono text-ink-muted dark:text-slate-400 mt-0.5">Every edit recorded, most recent first</p>
        </div>
        <div class="flex items-center gap-3">
            {{-- Server-side search across EVERY matching row, not just the
                 current page of 30 (this page's own layout, layouts.data,
                 loads calls.js — not app.js, which is the only bundle
                 with the client-side data-table-filter handler the plain
                 /audit-log page relies on; see that two-bundle split
                 noted elsewhere in this app). Submits on Enter or on a
                 600ms typing pause, no separate button needed. --}}
            <form method="GET" id="dataActivityLogSearchForm" class="flex items-center gap-2">
                @if($module !== '')
                <input type="hidden" name="module" value="{{ $module }}">
                @endif
                <input type="text" name="q" value="{{ $query }}" placeholder="Filter…" aria-label="Filter activity log"
                       id="dataActivityLogSearchInput"
                       class="w-40 rounded-lg border border-line dark:border-slate-700 bg-white dark:bg-slate-800 px-3 py-1.5 text-xs font-mono text-ink dark:text-slate-100 placeholder-ink-muted/60 dark:placeholder-slate-500 focus:outline-none focus:ring-2 focus:ring-primary/40">
            </form>
            @if(!$logs->isEmpty())
            @include('partials.table-actions', ['target' => 'dataActivityLogTable', 'name' => 'data-management-activity-log', 'pngOnly' => true])
            @endif
        </div>
    </div>

    @if($logs->isEmpty())
    <div class="py-16 flex flex-col items-center justify-center text-center gap-3">
        <svg class="w-10 h-10 text-slate-200 dark:text-slate-700" fill="none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round"
                  d="M9 12h3.75M9 15h3.75M9 18h3.75M3.75 4.5h10.5M3.75 4.5v15A2.25 2.25 0 006 21.75h12A2.25 2.25 0 0020.25 19.5V8.25a2.25 2.25 0 00-.659-1.591l-3.5-3.5A2.25 2.25 0 0014.5 2.5h-8.75A2.25 2.25 0 003.5 4.75z"/>
        </svg>
        <p class="text-sm font-mono text-ink-muted dark:text-slate-400">No activity yet</p>
    </div>
    @else
    <div class="overflow-x-auto" id="dataActivityLogTable">
    <table class="w-full text-sm">
        <thead>
            <tr class="bg-slate-50 dark:bg-slate-800 text-xs font-mono text-ink-muted dark:text-slate-400 uppercase tracking-wide">
                <th class="px-5 py-2.5 text-left">When</th>
                <th class="px-4 py-2.5 text-left">Who</th>
                <th class="px-4 py-2.5 text-left">Page</th>
                <th class="px-4 py-2.5 text-left">Action</th>
                <th class="px-4 py-2.5 text-left">Details</th>
            </tr>
        </thead>
        <tbody class="divide-y divide-line dark:divide-slate-700">
            @foreach($logs as $log)
            @php
                // 'cost_breakdown.field_updated' -> module slug
                // 'cost_breakdown' -> page label "Cost Breakdown" (via
                // $modules, built once in the controller).
                $moduleSlug = (string) \Illuminate\Support\Str::of($log->action)->before('.');
                $moduleLabel = $modules[$moduleSlug] ?? ucfirst(str_replace('_', ' ', $moduleSlug));
                $actionLabel = \Illuminate\Support\Str::of($log->action)
                    ->after('.')
                    ->replace('_', ' ')
                    ->ucfirst();
                $actorName = $log->actor_name ?? $log->user?->name ?? 'Unknown user';
            @endphp
            <tr class="hover:bg-slate-50 dark:hover:bg-slate-800 transition-colors">
                {{-- Actual date + time (explicit follow-up, 2026-10-07:
                     "in the activity page i want you to add time like
                     that") — was relative-only ("2 hours ago") with the
                     real timestamp hidden behind a hover tooltip; now
                     shown directly, with the relative phrase kept as a
                     smaller second line underneath. --}}
                <td class="px-5 py-3 font-mono text-xs text-ink-muted dark:text-slate-300 whitespace-nowrap" title="{{ $log->created_at->format('M j, Y g:i A') }}">
                    <div class="text-ink dark:text-slate-200">{{ $log->created_at->format('M j, Y g:i A') }}</div>
                    <div class="text-[11px] text-ink-muted/70 dark:text-slate-500">{{ $log->created_at->diffForHumans() }}</div>
                </td>
                <td class="px-4 py-3 font-mono text-xs text-ink dark:text-slate-200 whitespace-nowrap">
                    {{ $actorName }}
                </td>
                <td class="px-4 py-3 whitespace-nowrap">
                    <span class="inline-flex items-center px-2 py-0.5 rounded-full text-[10px] font-semibold font-mono whitespace-nowrap bg-slate-100 dark:bg-slate-800 text-ink-muted dark:text-slate-300">
                        {{ $moduleLabel }}
                    </span>
                </td>
                <td class="px-4 py-3 whitespace-nowrap">
                    <span class="inline-flex items-center px-2 py-0.5 rounded-full text-[10px] font-semibold font-mono whitespace-nowrap bg-yellow-100 dark:bg-yellow-900/40 text-yellow-700 dark:text-yellow-400">
                        {{ $actionLabel }}
                    </span>
                </td>
                <td class="px-4 py-3 font-mono text-xs text-ink-muted dark:text-slate-300">
                    {{ $log->description }}
                </td>
            </tr>
            @endforeach
        </tbody>
    </table>
    </div>

    <div class="px-5 py-4 border-t border-line dark:border-slate-700">
        {{ $logs->links('partials.pagination') }}
    </div>
    @endif
</div>

@endsection

@push('scripts')
<script>
(function () {
    const input = document.getElementById('dataActivityLogSearchInput');
    const form = document.getElementById('dataActivityLogSearchForm');
    if (!input || !form) return;
    let timer;
    input.addEventListener('input', () => {
        clearTimeout(timer);
        timer = setTimeout(() => form.submit(), 600);
    });
})();
</script>
@endpush
