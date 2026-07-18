@extends('layouts.app')
@section('title', 'Unmatched Orders')
@section('subtitle', 'Orders that synced but couldn\'t be attributed to a team')

@section('content')

{{-- HEADER EXPLANATION — for an admin who's never seen this page before: what
     "unmatched" means here and where to go fix it. team IS NULL is distinct
     from tsa_name IS NULL (an order can have a team via product-keyword
     inference alone, with nobody having ever claimed it — see Order-matching
     pipeline background) — this page is specifically about the team column,
     since that's what makes an order invisible to every report. --}}
<div class="mb-6 bg-white dark:bg-slate-900 rounded-xl border border-slate-200 dark:border-slate-700 shadow-sm px-5 py-4">
    <p class="text-sm text-slate-600 dark:text-slate-300 leading-relaxed">
        These orders synced from Pancake but couldn't be matched to a team — they don't appear in any report.
        Usually this means a product name or tag doesn't match any configured keyword yet. Check
        <a href="{{ route('product-management') }}" class="text-yellow-700 dark:text-yellow-400 font-semibold hover:underline">Product Management</a>
        for a missing or misspelled keyword, or
        <a href="{{ route('tsa-management') }}" class="text-yellow-700 dark:text-yellow-400 font-semibold hover:underline">TSA Management</a>
        if the order should have been claimed by an agent's name tag instead.
    </p>
</div>

{{-- COUNT + RE-CHECK — same .stat-card visual pattern as Sync Health's summary
     row, paired with the manual trigger for the existing orders:reinfer-teams
     command (previously CLI/--dry-run only). Non-destructive and idempotent,
     so no confirmation dialog. --}}
<div class="flex flex-col sm:flex-row sm:items-center gap-5 mb-8">
    <div class="stat-card bg-white dark:bg-slate-900 rounded-xl border {{ $totalUnmatched > 0 ? 'border-rose-200 dark:border-rose-800' : 'border-slate-200 dark:border-slate-700' }} p-5 shadow-sm flex items-start gap-4 flex-1">
        <div class="w-12 h-12 rounded-full {{ $totalUnmatched > 0 ? 'bg-rose-50 dark:bg-rose-950/40' : 'bg-emerald-50 dark:bg-emerald-950/40' }} flex items-center justify-center shrink-0">
            @if($totalUnmatched > 0)
            <svg class="w-6 h-6 text-rose-500" fill="none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m9-.75a9 9 0 11-18 0 9 9 0 0118 0zm-9 3.75h.008v.008H12v-.008z"/>
            </svg>
            @else
            <svg class="w-6 h-6 text-emerald-600" fill="none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75L11.25 15 15 9.75M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>
            </svg>
            @endif
        </div>
        <div class="min-w-0">
            <p class="text-xs font-mono font-semibold text-slate-400 uppercase tracking-wider mb-1">Unmatched Orders</p>
            <p class="text-2xl font-bold text-slate-800 dark:text-slate-100 font-mono leading-none" style="font-variant-numeric: tabular-nums">
                {{ number_format($totalUnmatched) }}
            </p>
            <p class="mt-1.5 text-xs text-slate-400 font-mono">team IS NULL · invisible to every report</p>
        </div>
    </div>

    <form method="POST" action="{{ route('unmatched-orders.reinfer') }}" class="shrink-0">
        @csrf
        <button type="submit"
                class="inline-flex items-center gap-1.5 px-4 py-2.5 bg-yellow-700 hover:bg-yellow-800 text-white text-sm font-semibold rounded-lg transition-colors cursor-pointer whitespace-nowrap">
            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/>
            </svg>
            Re-check now
        </button>
    </form>
</div>

@if($totalUnmatched === 0)
{{-- EMPTY STATE --}}
<div class="bg-white dark:bg-slate-900 rounded-xl border border-slate-200 dark:border-slate-700 shadow-sm py-16 flex flex-col items-center justify-center text-center gap-3">
    <svg class="w-10 h-10 text-emerald-300 dark:text-emerald-700" fill="none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24">
        <path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75L11.25 15 15 9.75M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>
    </svg>
    <p class="text-sm font-mono text-slate-400">Nothing unmatched right now.</p>
</div>
@else
{{-- TABLE — a genuine per-entity table (one row = one order), so it follows
     the sortable/filterable convention. Tags is the most important column:
     it's the raw material the matching logic works from. --}}
<div class="bg-white dark:bg-slate-900 rounded-xl border border-slate-200 dark:border-slate-700 shadow-sm overflow-hidden">
    <div class="px-5 py-4 border-b border-slate-100 dark:border-slate-700 flex items-center justify-between flex-wrap gap-3">
        <div>
            <h2 class="text-sm font-bold text-slate-700 dark:text-slate-200 font-mono">Unmatched Orders</h2>
            <p class="text-xs font-mono text-slate-400 mt-0.5">Most recent first</p>
        </div>
        <div class="flex items-center gap-3">
            <input type="text" data-table-filter="unmatchedOrdersTable" placeholder="Filter…" aria-label="Filter unmatched orders"
                   class="w-40 rounded-lg border border-slate-200 dark:border-slate-700 bg-white dark:bg-slate-800 px-3 py-1.5 text-xs font-mono text-slate-800 dark:text-slate-100 placeholder-slate-400 dark:placeholder-slate-500 focus:outline-none focus:ring-2 focus:ring-yellow-500">
            @include('partials.table-actions', ['target' => 'unmatchedOrdersTable', 'name' => 'unmatched-orders'])
        </div>
    </div>

    <div class="overflow-x-auto" id="unmatchedOrdersTable" data-sortable-table>
    <table class="w-full text-sm">
        <thead>
            <tr class="bg-slate-50 dark:bg-slate-800 text-xs font-mono text-slate-400 uppercase tracking-wide">
                <th class="px-5 py-2.5 text-left" data-sort-key="order_id">Order ID</th>
                <th class="px-4 py-2.5 text-left" data-sort-key="date">Date</th>
                <th class="px-4 py-2.5 text-left" data-sort-key="product">Product</th>
                <th class="px-4 py-2.5 text-left" data-sort-key="tags">Tags</th>
                <th class="px-4 py-2.5 text-right" data-sort-key="amount">Amount</th>
            </tr>
        </thead>
        <tbody class="divide-y divide-slate-100 dark:divide-slate-700">
            @foreach($orders as $order)
            @php
                $tags = collect($order->raw_tags ?? [])->filter(fn ($tag) => filled($tag))->values();
            @endphp
            <tr class="hover:bg-slate-50 dark:hover:bg-slate-800 transition-colors">
                <td class="px-5 py-3 font-mono text-xs text-slate-600 dark:text-slate-300 whitespace-nowrap" data-sort-key="order_id" data-sort-value="{{ $order->pancake_order_id }}">
                    #{{ $order->pancake_order_id }}
                </td>
                <td class="px-4 py-3 font-mono text-xs text-slate-600 dark:text-slate-300 whitespace-nowrap" data-sort-key="date" data-sort-value="{{ $order->pancake_created_at?->timestamp ?? 0 }}">
                    {{ $order->pancake_created_at?->format('M j, Y g:i A') ?? '—' }}
                </td>
                <td class="px-4 py-3 font-mono text-xs text-slate-700 dark:text-slate-200" data-sort-key="product" data-sort-value="{{ $order->product ?? '' }}">
                    {{ $order->product ?? '—' }}
                </td>
                <td class="px-4 py-3 font-mono text-xs" data-sort-key="tags" data-sort-value="{{ $tags->implode(' ') }}">
                    @if($tags->isEmpty())
                    <span class="text-slate-300 dark:text-slate-600">—</span>
                    @else
                    <div class="flex flex-wrap gap-1">
                        @foreach($tags as $tag)
                        <span class="inline-flex items-center px-2 py-0.5 rounded-full text-[10px] font-semibold bg-slate-100 dark:bg-slate-800 text-slate-600 dark:text-slate-300 whitespace-nowrap">{{ $tag }}</span>
                        @endforeach
                    </div>
                    @endif
                </td>
                <td class="px-4 py-3 font-mono text-xs text-right font-semibold text-accent" data-sort-key="amount" data-sort-value="{{ $order->amount }}">
                    ₱{{ number_format($order->amount, 2) }}
                </td>
            </tr>
            @endforeach
        </tbody>
    </table>
    </div>

    <div class="px-5 py-4 border-t border-slate-100 dark:border-slate-700">
        {{ $orders->links('partials.pagination') }}
    </div>
</div>
@endif

@endsection
