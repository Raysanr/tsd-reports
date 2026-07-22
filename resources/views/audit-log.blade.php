@extends('layouts.app')
@section('title', 'Audit Log')
@section('subtitle', 'Admin activity history · Who did what, when')

@section('content')

{{-- ACTIVITY LOG TABLE — every ActivityLog entry, paginated, sortable/filterable.
     Mirrors sync-health.blade.php's table conventions (same wrapper/header/empty
     state/pagination structure) so this page doesn't read as a different app
     bolted onto the Sync Health page next to it in the sidebar. --}}
<div class="bg-white dark:bg-slate-900 rounded-xl border border-slate-200 dark:border-slate-700 shadow-sm overflow-hidden">
    <div class="px-5 py-4 border-b border-slate-100 dark:border-slate-700 flex items-center justify-between flex-wrap gap-3">
        <div>
            <h2 class="text-sm font-bold text-slate-700 dark:text-slate-200 font-mono">Activity History</h2>
            <p class="text-xs font-mono text-slate-400 mt-0.5">Every admin action recorded, most recent first</p>
        </div>
        <div class="flex items-center gap-3">
            <input type="text" data-table-filter="auditLogTable" placeholder="Filter…" aria-label="Filter activity log"
                   value="{{ $query ?? '' }}"
                   class="w-40 rounded-lg border border-slate-200 dark:border-slate-700 bg-white dark:bg-slate-800 px-3 py-1.5 text-xs font-mono text-slate-800 dark:text-slate-100 placeholder-slate-400 dark:placeholder-slate-500 focus:outline-none focus:ring-2 focus:ring-yellow-500">
            @if(!$logs->isEmpty())
            @include('partials.table-actions', ['target' => 'auditLogTable', 'name' => 'audit-log'])
            @endif
        </div>
    </div>

    @if($logs->isEmpty())
    <div class="py-16 flex flex-col items-center justify-center text-center gap-3">
        <svg class="w-10 h-10 text-slate-200 dark:text-slate-700" fill="none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round"
                  d="M9 12h3.75M9 15h3.75M9 18h3.75M3.75 4.5h10.5M3.75 4.5v15A2.25 2.25 0 006 21.75h12A2.25 2.25 0 0020.25 19.5V8.25a2.25 2.25 0 00-.659-1.591l-3.5-3.5A2.25 2.25 0 0014.5 2.5h-8.75A2.25 2.25 0 003.5 4.75z"/>
        </svg>
        <p class="text-sm font-mono text-slate-400">No activity yet</p>
    </div>
    @else
    <div class="overflow-x-auto" id="auditLogTable" data-sortable-table>
    <table class="w-full text-sm">
        <thead>
            <tr class="bg-slate-50 dark:bg-slate-800 text-xs font-mono text-slate-400 uppercase tracking-wide">
                <th class="px-5 py-2.5 text-left" data-sort-key="when">When</th>
                <th class="px-4 py-2.5 text-left" data-sort-key="who">Who</th>
                <th class="px-4 py-2.5 text-left" data-sort-key="action">Action</th>
                <th class="px-4 py-2.5 text-left">Details</th>
            </tr>
        </thead>
        <tbody class="divide-y divide-slate-100 dark:divide-slate-700">
            @foreach($logs as $log)
            @php
                // e.g. 'product.created' -> 'Product created'. No lookup table needed —
                // every action string this app writes is already "{subject}.{verb}" or
                // "{subject}.bulk_{verb}", so a mechanical transform reads fine as-is.
                $actionLabel = \Illuminate\Support\Str::of($log->action)
                    ->after('.')
                    ->replace('_', ' ')
                    ->ucfirst();
            @endphp
            <tr class="hover:bg-slate-50 dark:hover:bg-slate-800 transition-colors">
                <td class="px-5 py-3 font-mono text-xs text-slate-600 dark:text-slate-300 whitespace-nowrap" data-sort-key="when" data-sort-value="{{ $log->created_at->timestamp }}" title="{{ $log->created_at->format('M j, Y g:i A') }}">
                    {{ $log->created_at->diffForHumans() }}
                </td>
                <td class="px-4 py-3 font-mono text-xs text-slate-700 dark:text-slate-200" data-sort-key="who" data-sort-value="{{ $log->user?->name ?? 'Unknown user' }}">
                    {{ $log->user?->name ?? 'Unknown user' }}
                </td>
                <td class="px-4 py-3" data-sort-key="action" data-sort-value="{{ $actionLabel }}">
                    <span class="inline-flex items-center px-2 py-0.5 rounded-full text-[10px] font-semibold font-mono whitespace-nowrap bg-yellow-100 dark:bg-yellow-900/40 text-yellow-700 dark:text-yellow-400">
                        {{ $actionLabel }}
                    </span>
                </td>
                <td class="px-4 py-3 font-mono text-xs text-slate-600 dark:text-slate-300">
                    {{ $log->description }}
                </td>
            </tr>
            @endforeach
        </tbody>
    </table>
    </div>

    <div class="px-5 py-4 border-t border-slate-100 dark:border-slate-700">
        {{ $logs->links('partials.pagination') }}
    </div>
    @endif
</div>

@endsection
