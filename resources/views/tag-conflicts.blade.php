@extends('layouts.app')
@section('title', 'Tag Conflicts')
@section('subtitle', 'Orders whose tag and cart item point to two different products')

@section('content')

{{-- HEADER EXPLANATION — for an admin who's never seen this page before: what
     a "conflict" is here, why it exists, and why the app can't just fix it. --}}
<div class="mb-6 bg-white dark:bg-slate-900 rounded-xl border border-slate-200 dark:border-slate-700 shadow-sm px-5 py-4">
    <p class="text-sm text-slate-600 dark:text-slate-300 leading-relaxed">
        Each order below carries a tag matching one product's keyword, but its actual cart item (the
        <span class="font-mono text-xs">product</span> field) matches a <em>different</em> product — usually a TSA
        left a stale tag on the order from earlier in the conversation. These orders are already excluded from both
        products' report counts (see <span class="font-mono text-xs">ProductPerformance::buildRow()</span>), so
        report totals aren't affected — but the wrong tag is still sitting on the order in Pancake POS, unfixed,
        until someone corrects it there. This app can't edit Pancake's tags directly, so use the order ID below to
        find and fix it in Pancake, then mark it reviewed here so it stops resurfacing.
    </p>
    @if($clamped)
    <p class="mt-2 text-xs font-mono text-amber-600 dark:text-amber-400">
        Requested range exceeded {{ $maxWindowDays }} days — clamped to the {{ $maxWindowDays }}-day window ending {{ $dateTo }} (scanning the full order history takes too long to do live).
    </p>
    @endif
</div>

{{-- COUNT + REVIEWED TOGGLE — same .stat-card pattern as Unmatched Orders. --}}
<div class="flex flex-col sm:flex-row sm:items-center gap-5 mb-8">
    <div class="stat-card bg-white dark:bg-slate-900 rounded-xl border {{ $totalConflicts > 0 && !$showReviewed ? 'border-rose-200 dark:border-rose-800' : 'border-slate-200 dark:border-slate-700' }} p-5 shadow-sm flex items-start gap-4 flex-1">
        <div class="w-12 h-12 rounded-full {{ $totalConflicts > 0 && !$showReviewed ? 'bg-rose-50 dark:bg-rose-950/40' : 'bg-emerald-50 dark:bg-emerald-950/40' }} flex items-center justify-center shrink-0">
            @if($totalConflicts > 0 && !$showReviewed)
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
            <p class="text-xs font-mono font-semibold text-slate-400 uppercase tracking-wider mb-1">
                {{ $showReviewed ? 'Reviewed Conflicts' : 'Open Tag Conflicts' }}
            </p>
            <p class="text-2xl font-bold text-slate-800 dark:text-slate-100 font-mono leading-none" style="font-variant-numeric: tabular-nums">
                {{ number_format($totalConflicts) }}
            </p>
            <p class="mt-1.5 text-xs text-slate-400 font-mono">{{ $dateFrom }} → {{ $dateTo }}</p>
        </div>
    </div>

    <a href="{{ route('tag-conflicts', ['date_from' => $dateFrom, 'date_to' => $dateTo] + ($showReviewed ? [] : ['reviewed' => 1])) }}"
       class="shrink-0 inline-flex items-center gap-1.5 px-4 py-2.5 bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-700 hover:bg-slate-50 dark:hover:bg-slate-800 text-slate-600 dark:text-slate-300 text-sm font-semibold rounded-lg transition-colors cursor-pointer whitespace-nowrap">
        @if($showReviewed)
        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18"/>
        </svg>
        Back to open queue
        @else
        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12.75L11.25 15 15 9.75M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>
        </svg>
        View reviewed
        @endif
    </a>
</div>

@if($totalConflicts === 0)
{{-- EMPTY STATE --}}
<div class="bg-white dark:bg-slate-900 rounded-xl border border-slate-200 dark:border-slate-700 shadow-sm py-16 flex flex-col items-center justify-center text-center gap-3">
    <svg class="w-10 h-10 text-emerald-300 dark:text-emerald-700" fill="none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24">
        <path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75L11.25 15 15 9.75M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>
    </svg>
    <p class="text-sm font-mono text-slate-400">
        {{ $showReviewed ? 'Nothing reviewed in this window yet.' : 'No tag conflicts in this window.' }}
    </p>
</div>
@else
{{-- TABLE — one row = one order. Tag says / Cart says is the whole point of
     this page, so those two columns get the most visual weight. --}}
<div class="bg-white dark:bg-slate-900 rounded-xl border border-slate-200 dark:border-slate-700 shadow-sm overflow-hidden">
    <div class="px-5 py-4 border-b border-slate-100 dark:border-slate-700 flex items-center justify-between flex-wrap gap-3">
        <div>
            <h2 class="text-sm font-bold text-slate-700 dark:text-slate-200 font-mono">{{ $showReviewed ? 'Reviewed Conflicts' : 'Tag Conflicts' }}</h2>
            <p class="text-xs font-mono text-slate-400 mt-0.5">Most recent first</p>
        </div>
        <div class="flex items-center gap-3">
            <input type="text" data-table-filter="tagConflictsTable" placeholder="Filter…" aria-label="Filter tag conflicts"
                   class="w-40 rounded-lg border border-slate-200 dark:border-slate-700 bg-white dark:bg-slate-800 px-3 py-1.5 text-xs font-mono text-slate-800 dark:text-slate-100 placeholder-slate-400 dark:placeholder-slate-500 focus:outline-none focus:ring-2 focus:ring-yellow-500">
            @include('partials.table-actions', ['target' => 'tagConflictsTable', 'name' => 'tag-conflicts'])
        </div>
    </div>

    <div class="overflow-x-auto" id="tagConflictsTable" data-sortable-table>
    <table class="w-full text-sm">
        <thead>
            <tr class="bg-slate-50 dark:bg-slate-800 text-xs font-mono text-slate-400 uppercase tracking-wide">
                <th class="px-5 py-2.5 text-left" data-sort-key="order_id">Order ID</th>
                <th class="px-4 py-2.5 text-left" data-sort-key="date">Date</th>
                <th class="px-4 py-2.5 text-left" data-sort-key="team">Team / TSA</th>
                <th class="px-4 py-2.5 text-left" data-sort-key="tag_product">Tag Says</th>
                <th class="px-4 py-2.5 text-left" data-sort-key="cart_product">Cart Item Says</th>
                <th class="px-4 py-2.5 text-left" data-sort-key="tags">Raw Tags</th>
                <th class="px-5 py-2.5 text-right">Action</th>
            </tr>
        </thead>
        <tbody class="divide-y divide-slate-100 dark:divide-slate-700">
            @foreach($conflicts as $row)
            @php
                $order = $row['order'];
                $tags = collect($order->raw_tags ?? [])->filter(fn ($tag) => filled($tag))->values();
            @endphp
            <tr class="hover:bg-slate-50 dark:hover:bg-slate-800 transition-colors">
                <td class="px-5 py-3 font-mono text-xs text-slate-600 dark:text-slate-300 whitespace-nowrap" data-sort-key="order_id" data-sort-value="{{ $order->pancake_order_id }}">
                    #{{ $order->pancake_order_id }}
                </td>
                <td class="px-4 py-3 font-mono text-xs text-slate-600 dark:text-slate-300 whitespace-nowrap" data-sort-key="date" data-sort-value="{{ $order->pancake_created_at?->timestamp ?? 0 }}">
                    {{ $order->pancake_created_at?->format('M j, Y g:i A') ?? '—' }}
                </td>
                <td class="px-4 py-3 font-mono text-xs text-slate-700 dark:text-slate-200" data-sort-key="team" data-sort-value="{{ $order->team }} {{ $order->tsa_name }}">
                    <div>{{ $order->team }}</div>
                    <div class="text-slate-400">{{ $order->tsa_name ?? '—' }}</div>
                </td>
                <td class="px-4 py-3" data-sort-key="tag_product" data-sort-value="{{ $row['tagProduct']->display_name }}">
                    <span class="inline-flex items-center px-2 py-0.5 rounded-full text-[10px] font-semibold bg-rose-100 dark:bg-rose-950/40 text-rose-700 dark:text-rose-400 whitespace-nowrap">{{ $row['tagProduct']->display_name }}</span>
                </td>
                <td class="px-4 py-3" data-sort-key="cart_product" data-sort-value="{{ $row['cartProduct']->display_name }}">
                    <span class="inline-flex items-center px-2 py-0.5 rounded-full text-[10px] font-semibold bg-amber-100 dark:bg-amber-950/40 text-amber-700 dark:text-amber-400 whitespace-nowrap">{{ $row['cartProduct']->display_name }}</span>
                    <div class="mt-1 text-xs font-mono text-slate-400 truncate max-w-[180px]" title="{{ $order->product }}">{{ $order->product }}</div>
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
                <td class="px-5 py-3 text-right whitespace-nowrap">
                    <a href="https://pos.pages.fm/" target="_blank" rel="noopener"
                       title="Open Pancake POS and search for order #{{ $order->pancake_order_id }}"
                       class="inline-flex items-center gap-1 px-2.5 py-1.5 text-xs font-semibold text-yellow-700 dark:text-yellow-400 hover:underline mr-2">
                        Open Pancake
                        <svg class="w-3 h-3" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M10.5 6H6a2 2 0 00-2 2v10a2 2 0 002 2h10a2 2 0 002-2v-4.5M14 4h6m0 0v6m0-6L10 14"/></svg>
                    </a>
                    @if($showReviewed)
                    <form method="POST" action="{{ route('tag-conflicts.unreview', $order) }}" class="inline">
                        @csrf
                        @method('DELETE')
                        <input type="hidden" name="date_from" value="{{ $dateFrom }}">
                        <input type="hidden" name="date_to" value="{{ $dateTo }}">
                        <button type="submit" class="px-2.5 py-1.5 text-xs font-semibold text-slate-500 dark:text-slate-400 hover:text-slate-700 dark:hover:text-slate-200 border border-slate-200 dark:border-slate-700 rounded-lg hover:bg-slate-50 dark:hover:bg-slate-800 transition-colors cursor-pointer">
                            Move back to queue
                        </button>
                    </form>
                    @else
                    <form method="POST" action="{{ route('tag-conflicts.review', $order) }}" class="inline">
                        @csrf
                        <input type="hidden" name="date_from" value="{{ $dateFrom }}">
                        <input type="hidden" name="date_to" value="{{ $dateTo }}">
                        <button type="submit" class="px-2.5 py-1.5 text-xs font-semibold text-white bg-yellow-700 hover:bg-yellow-800 rounded-lg transition-colors cursor-pointer">
                            Mark reviewed
                        </button>
                    </form>
                    @endif
                </td>
            </tr>
            @endforeach
        </tbody>
    </table>
    </div>

    <div class="px-5 py-4 border-t border-slate-100 dark:border-slate-700">
        {{ $conflicts->links('partials.pagination') }}
    </div>
</div>
@endif

@endsection

@push('topbar-right')
{{-- 'form' submit mode (not 'navigate') so a hidden `reviewed` field survives
     the picker's Apply — 'navigate' hardcodes its own '?date_from=...' query
     string onto navigateBase, which would double up with a '?reviewed=1'
     already baked into that URL when viewing the reviewed archive. --}}
<form method="GET" action="{{ route('tag-conflicts') }}" class="flex items-center gap-4">
    @if($showReviewed)
    <input type="hidden" name="reviewed" value="1">
    @endif
    @include('partials.date-picker', [
        'mode' => 'range', 'id' => 'tagConflictsDrp',
        'dateFrom' => \Illuminate\Support\Carbon::parse($dateFrom), 'dateTo' => \Illuminate\Support\Carbon::parse($dateTo),
        'submit' => 'form',
    ])
</form>
@endpush
