{{-- Extracted from tsa-logs.blade.php (2026-09-22) so the Status filter
     (and the pre-existing TSA/date filters) can swap this fragment via
     AJAX instead of a full page reload — see the parent view's own
     #tsaLogsTableContainer/data-poll-url and script block for the fetch
     side, same X-Table-Refresh convention LeadController::index() already
     uses for the Leads page's own table (see that controller's own doc
     comment). $statusColor is redefined here (not passed from the
     controller) since it's pure presentation logic, same as the parent
     view's own copy — kept in sync manually if either ever changes,
     matching how $statusFilterOptions' own color branch in the parent
     view already duplicates this exact match/default shape. --}}
@php
$statusColor = fn($status) => match($status) {
    'login'      => 'bg-emerald-100 dark:bg-emerald-900/40 text-emerald-700 dark:text-emerald-400',
    'call_backs' => 'bg-teal-100 dark:bg-teal-900/40 text-teal-700 dark:text-teal-400',
    'logout'     => 'bg-slate-100 dark:bg-slate-700 text-slate-600 dark:text-slate-300',
    'locked'     => 'bg-red-100 dark:bg-red-900/40 text-red-700 dark:text-red-400',
    default      => 'bg-amber-100 dark:bg-amber-900/40 text-amber-700 dark:text-amber-400',
};
@endphp

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
