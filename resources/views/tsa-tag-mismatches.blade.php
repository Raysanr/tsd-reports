@extends('layouts.app')
@section('title', 'TSA Tag Mismatches')
@section('subtitle', $mode === 'audit' ? "Every order tagged \"{$tsaFilterName}\" and why it did or didn't count" : 'Orders whose name tag disagrees with who actually got credited')

@section('content')

@if($mode === 'audit')
{{-- AUDIT MODE — a tag disagreement isn't the only way an order can drop out
     of a TSA's own count: it can be tagged and credited correctly and STILL
     get excluded afterward (excluded seller, cancelled add-on, void/
     restocking status, or never carrying the real "UPSELL TSD" tag at all).
     Live investigation (2026-08-22) proved the mismatch check alone can
     report zero and still miss a real gap — this mode shows every order
     under one TSA's own tag with the exact reason, superseding the need to
     re-investigate from scratch next time. --}}
<div class="mb-6 bg-white dark:bg-slate-900 rounded-xl border border-slate-200 dark:border-slate-700 shadow-sm px-5 py-4">
    <p class="text-sm text-slate-600 dark:text-slate-300 leading-relaxed">
        Every order below carries <span class="font-semibold text-slate-800 dark:text-slate-100">{{ $tsaFilterName }}'s</span>
        own name tag in Pancake for this range. <span class="font-semibold text-slate-800 dark:text-slate-100">Counted</span> means
        it's inside her real-upsell total (Dashboard, TSA Performance, Leads Report all agree on this same definition) — if it
        isn't, the Reason column says exactly why: credited to someone else, closed under an excluded seller account, the add-on
        was cancelled, the order landed in a void/restocking status, or it never actually carried the real "UPSELL TSD" tag
        Pancake requires, even if a note says otherwise.
    </p>
</div>

<div class="bg-white dark:bg-slate-900 rounded-xl border border-slate-200 dark:border-slate-700 shadow-sm overflow-hidden mb-6">
    <div class="px-5 py-4 border-b border-slate-100 dark:border-slate-700 flex items-center justify-between flex-wrap gap-3">
        <div>
            <h2 class="text-sm font-bold text-slate-700 dark:text-slate-200 font-mono">{{ $tsaFilterName }} — Tagged Orders</h2>
            <p class="text-xs font-mono text-slate-400 mt-0.5">
                {{ $rows->count() }} total ·
                <span class="font-semibold text-emerald-600 dark:text-emerald-400">{{ $rows->where('counted', true)->count() }} counted</span> ·
                <span class="font-semibold text-rose-600 dark:text-rose-400">{{ $rows->where('counted', false)->count() }} not counted</span>
            </p>
        </div>
        <input type="text" data-table-filter="tsaAuditTable" placeholder="Filter…" aria-label="Filter tagged orders"
               class="w-40 rounded-lg border border-slate-200 dark:border-slate-700 bg-white dark:bg-slate-800 px-3 py-1.5 text-xs font-mono text-slate-800 dark:text-slate-100 placeholder-slate-400 dark:placeholder-slate-500 focus:outline-none focus:ring-2 focus:ring-yellow-500">
    </div>

    @if($rows->isEmpty())
    <div class="py-16 flex flex-col items-center justify-center text-center gap-3">
        <p class="text-sm font-mono text-slate-400">No orders carry {{ $tsaFilterName }}'s tag for this range.</p>
    </div>
    @else
    <div class="overflow-x-auto" id="tsaAuditTable" data-sortable-table data-scroll-shadow>
    <table class="w-full text-sm">
        <thead>
            <tr class="bg-slate-50 dark:bg-slate-800 text-xs font-mono text-slate-400 uppercase tracking-wide">
                <th class="px-5 py-2.5 text-left" data-sort-key="order_id">Order ID</th>
                <th class="px-4 py-2.5 text-left" data-sort-key="date">Date</th>
                <th class="px-4 py-2.5 text-left" data-sort-key="product">Product</th>
                <th class="px-4 py-2.5 text-center" data-sort-key="counted">Counted?</th>
                <th class="px-4 py-2.5 text-left" data-sort-key="reason">Reason (if not)</th>
                <th class="px-4 py-2.5 text-right" data-sort-key="amount">Amount</th>
            </tr>
        </thead>
        <tbody class="divide-y divide-slate-100 dark:divide-slate-700">
            @foreach($rows as $row)
            @php $order = $row->order; @endphp
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
                <td class="px-4 py-3 text-center" data-sort-key="counted" data-sort-value="{{ $row->counted ? 1 : 0 }}">
                    @if($row->counted)
                    <span class="inline-flex items-center px-2 py-0.5 rounded-full text-[10px] font-semibold bg-emerald-50 dark:bg-emerald-950/40 text-emerald-600 dark:text-emerald-400">Yes</span>
                    @else
                    <span class="inline-flex items-center px-2 py-0.5 rounded-full text-[10px] font-semibold bg-rose-50 dark:bg-rose-950/40 text-rose-600 dark:text-rose-400">No</span>
                    @endif
                </td>
                <td class="px-4 py-3 font-mono text-xs text-slate-600 dark:text-slate-300" data-sort-key="reason" data-sort-value="{{ $row->reason ?? '' }}">
                    @if(!$row->agrees)
                    <span class="text-rose-600 dark:text-rose-400">Credited to {{ $row->actual_name ?? '— unattributed —' }}</span>
                    @elseif($row->reason)
                    {{ $row->reason }}
                    @else
                    <span class="text-slate-300 dark:text-slate-600">—</span>
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
    @endif
</div>

@else
{{-- MISMATCH MODE (default) — same "what this means, where to look"
     convention Unmatched Orders' own header follows. Attribution priority
     (account over tag) is deliberate and right almost every time — this
     page exists for the rare cases where it isn't, not to suggest the rule
     itself is wrong. Select a TSA above to switch to the fuller per-order
     audit instead, which also catches gaps this summary can't (see that
     mode's own header). --}}
<div class="mb-6 bg-white dark:bg-slate-900 rounded-xl border border-slate-200 dark:border-slate-700 shadow-sm px-5 py-4">
    <p class="text-sm text-slate-600 dark:text-slate-300 leading-relaxed">
        Every order below carries a TSA's own name tag in Pancake, but was actually credited to someone else — or to
        nobody — because whoever's Pancake account closed the upsell item didn't match that tag. This only catches
        THAT one class of gap — pick a TSA from the dropdown above for the fuller per-order audit, which also catches
        an order that was tagged and credited correctly but still didn't count (excluded seller, cancelled add-on,
        void/restocking status, or no real upsell tag at all).
    </p>
</div>

<div class="bg-white dark:bg-slate-900 rounded-xl border {{ $mismatches->isNotEmpty() ? 'border-rose-200 dark:border-rose-800' : 'border-slate-200 dark:border-slate-700' }} p-5 shadow-sm flex items-start gap-4 mb-6">
    <div class="w-12 h-12 rounded-full {{ $mismatches->isNotEmpty() ? 'bg-rose-50 dark:bg-rose-950/40' : 'bg-emerald-50 dark:bg-emerald-950/40' }} flex items-center justify-center shrink-0">
        @if($mismatches->isNotEmpty())
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
        <p class="text-xs font-mono font-semibold text-slate-400 uppercase tracking-wider mb-1">Tag Mismatches</p>
        <p class="text-2xl font-bold text-slate-800 dark:text-slate-100 font-mono leading-none" style="font-variant-numeric: tabular-nums">
            {{ number_format($mismatches->count()) }}
        </p>
        <p class="mt-1.5 text-xs text-slate-400 font-mono">
            {{ $dateFrom->isSameDay($dateTo) ? $dateFrom->format('M j, Y') : $dateFrom->format('M j') . ' – ' . $dateTo->format('M j, Y') }}
        </p>
    </div>
</div>

@if($mismatches->isEmpty())
<div class="bg-white dark:bg-slate-900 rounded-xl border border-slate-200 dark:border-slate-700 shadow-sm py-16 flex flex-col items-center justify-center text-center gap-3">
    <svg class="w-10 h-10 text-emerald-300 dark:text-emerald-700" fill="none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24">
        <path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75L11.25 15 15 9.75M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>
    </svg>
    <p class="text-sm font-mono text-slate-400">No tag mismatches for this range — every tagged order agrees with who it was credited to.</p>
</div>
@else

{{-- BY TSA — same "answer the real question first" convention Unmatched
     Orders' own By Product summary follows: which TSA is actually missing
     credit, before the order-by-order detail. --}}
<div class="bg-white dark:bg-slate-900 rounded-xl border border-slate-200 dark:border-slate-700 shadow-sm overflow-hidden mb-6">
    <div class="px-5 py-4 border-b border-slate-100 dark:border-slate-700">
        <h2 class="text-sm font-bold text-slate-700 dark:text-slate-200 font-mono">By TSA (tag-implied)</h2>
        <p class="text-xs font-mono text-slate-400 mt-0.5">Whose own name tag was overridden by account-based attribution</p>
    </div>
    <div class="overflow-x-auto" data-scroll-shadow>
    <table class="w-full text-sm">
        <thead>
            <tr class="bg-slate-50 dark:bg-slate-800 text-xs font-mono text-slate-400 uppercase tracking-wide">
                <th class="px-5 py-2.5 text-left">TSA</th>
                <th class="px-4 py-2.5 text-right">Mismatched Orders</th>
            </tr>
        </thead>
        <tbody class="divide-y divide-slate-100 dark:divide-slate-700">
            @foreach($byTsa as $tsaName => $count)
            <tr class="hover:bg-slate-50 dark:hover:bg-slate-800 transition-colors">
                <td class="px-5 py-3 font-mono text-xs font-semibold text-slate-700 dark:text-slate-200">{{ $tsaName }}</td>
                <td class="px-4 py-3 font-mono text-xs text-right font-semibold text-rose-600 dark:text-rose-400">{{ number_format($count) }}</td>
            </tr>
            @endforeach
        </tbody>
    </table>
    </div>
</div>

<div class="bg-white dark:bg-slate-900 rounded-xl border border-slate-200 dark:border-slate-700 shadow-sm overflow-hidden">
    <div class="px-5 py-4 border-b border-slate-100 dark:border-slate-700 flex items-center justify-between flex-wrap gap-3">
        <div>
            <h2 class="text-sm font-bold text-slate-700 dark:text-slate-200 font-mono">Mismatched Orders</h2>
            <p class="text-xs font-mono text-slate-400 mt-0.5">Most recent first</p>
        </div>
        <input type="text" data-table-filter="tagMismatchesTable" placeholder="Filter…" aria-label="Filter mismatched orders"
               class="w-40 rounded-lg border border-slate-200 dark:border-slate-700 bg-white dark:bg-slate-800 px-3 py-1.5 text-xs font-mono text-slate-800 dark:text-slate-100 placeholder-slate-400 dark:placeholder-slate-500 focus:outline-none focus:ring-2 focus:ring-yellow-500">
    </div>

    <div class="overflow-x-auto" id="tagMismatchesTable" data-sortable-table data-scroll-shadow>
    <table class="w-full text-sm">
        <thead>
            <tr class="bg-slate-50 dark:bg-slate-800 text-xs font-mono text-slate-400 uppercase tracking-wide">
                <th class="px-5 py-2.5 text-left" data-sort-key="order_id">Order ID</th>
                <th class="px-4 py-2.5 text-left" data-sort-key="date">Date</th>
                <th class="px-4 py-2.5 text-left" data-sort-key="tag_implied">Tag Implies</th>
                <th class="px-4 py-2.5 text-left" data-sort-key="actual">Actually Credited</th>
                <th class="px-4 py-2.5 text-left" data-sort-key="product">Product</th>
                <th class="px-4 py-2.5 text-center" data-sort-key="upsell">Real Upsell?</th>
                <th class="px-4 py-2.5 text-right" data-sort-key="amount">Amount</th>
            </tr>
        </thead>
        <tbody class="divide-y divide-slate-100 dark:divide-slate-700">
            @foreach($mismatches as $row)
            @php $order = $row->order; @endphp
            <tr class="hover:bg-slate-50 dark:hover:bg-slate-800 transition-colors">
                <td class="px-5 py-3 font-mono text-xs text-slate-600 dark:text-slate-300 whitespace-nowrap" data-sort-key="order_id" data-sort-value="{{ $order->pancake_order_id }}">
                    #{{ $order->pancake_order_id }}
                </td>
                <td class="px-4 py-3 font-mono text-xs text-slate-600 dark:text-slate-300 whitespace-nowrap" data-sort-key="date" data-sort-value="{{ $order->pancake_created_at?->timestamp ?? 0 }}">
                    {{ $order->pancake_created_at?->format('M j, Y g:i A') ?? '—' }}
                </td>
                <td class="px-4 py-3 font-mono text-xs font-semibold text-slate-700 dark:text-slate-200" data-sort-key="tag_implied" data-sort-value="{{ $row->tag_implied_name }}">
                    {{ $row->tag_implied_name }}
                </td>
                <td class="px-4 py-3 font-mono text-xs" data-sort-key="actual" data-sort-value="{{ $row->actual_name ?? '' }}">
                    @if($row->actual_name)
                    <span class="font-semibold text-slate-700 dark:text-slate-200">{{ $row->actual_name }}</span>
                    @else
                    <span class="text-slate-300 dark:text-slate-600">— unattributed —</span>
                    @endif
                </td>
                <td class="px-4 py-3 font-mono text-xs text-slate-700 dark:text-slate-200" data-sort-key="product" data-sort-value="{{ $order->product ?? '' }}">
                    {{ $order->product ?? '—' }}
                </td>
                <td class="px-4 py-3 text-center" data-sort-key="upsell" data-sort-value="{{ $row->is_real_upsell ? 1 : 0 }}">
                    @if($row->is_real_upsell)
                    <span class="inline-flex items-center px-2 py-0.5 rounded-full text-[10px] font-semibold bg-rose-50 dark:bg-rose-950/40 text-rose-600 dark:text-rose-400">Yes</span>
                    @else
                    <span class="text-slate-300 dark:text-slate-600 text-xs">—</span>
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
</div>
@endif
@endif

@endsection

@push('topbar-right')
{{-- TSA picker — switches the page into per-order audit mode for whoever's
     selected (?tsa=), same GET-form-soft-submits convention the rest of the
     app's team/status filters already use. "All TSAs" (empty value) returns
     to the tag-mismatch summary above. --}}
<form method="GET" action="{{ route('tsa-tag-mismatches') }}" class="contents">
    <input type="hidden" name="date_from" value="{{ $dateFrom->toDateString() }}">
    <input type="hidden" name="date_to" value="{{ $dateTo->copy()->startOfDay()->toDateString() }}">
    <select name="tsa" onchange="this.form.submit()"
            class="rounded-lg border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-800 px-3 py-1.5 text-xs font-mono text-slate-700 dark:text-slate-200 cursor-pointer focus:outline-none focus:ring-2 focus:ring-yellow-500">
        <option value="">All TSAs (mismatches only)</option>
        @foreach($tsaOptions as $key => $name)
        <option value="{{ $key }}" {{ ($tsaFilter ?? null) === $key ? 'selected' : '' }}>{{ $name }}</option>
        @endforeach
    </select>
</form>

{{-- Range date-picker, same navigate-mode pattern Dashboard's own topbar uses
     — this page has no wrapping <form> around the whole toolbar, so
     'navigate' just redirects with date_from/date_to (dropping the tsa
     filter back to "All TSAs" on a date change, same as every other page's
     filters reset to their defaults on navigate). --}}
@include('partials.date-picker', [
    'mode' => 'range', 'id' => 'tagMismatchDrp', 'dateFrom' => $dateFrom, 'dateTo' => $dateTo,
    'submit' => 'navigate', 'navigateBase' => route('tsa-tag-mismatches'),
])
@endpush
