@extends('layouts.app')
@section('title', 'Team Report')
@section('subtitle', 'Orders · Hourly Breakdown · Dispositions')

@section('content')

{{-- RESULTS SUMMARY --}}
<div class="bg-white rounded-xl border border-slate-200 shadow-sm px-6 py-4 mb-6 flex items-center gap-4 text-xs font-mono">
    <span class="text-slate-500 font-semibold">{{ $totals['orders'] }} orders</span>
    <span class="text-accent font-bold">₱{{ number_format($totals['sales'], 2) }}</span>
</div>

{{-- SUMMARY ROWS: Hourly + By Disposition --}}
<div class="grid grid-cols-1 lg:grid-cols-3 gap-6 mb-6">

    {{-- Hourly breakdown --}}
    <div class="lg:col-span-2 bg-white rounded-xl border border-slate-200 shadow-sm overflow-hidden">
        <div class="px-5 py-3.5 border-b border-slate-100">
            <h2 class="text-sm font-bold text-slate-700 font-mono">Hourly Breakdown — {{ $teams[$selectedTeam] ?? $selectedTeam }}</h2>
        </div>
        <table class="w-full text-sm">
            <thead>
                <tr class="bg-slate-50 text-xs font-mono text-slate-400 uppercase tracking-wide">
                    <th class="px-5 py-2.5 text-left">Hour</th>
                    <th class="px-4 py-2.5 text-center">Orders</th>
                    <th class="px-4 py-2.5 text-right">Sales</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
                @foreach($hourlyRows as $row)
                @if($row['total_orders'] > 0)
                <tr class="hover:bg-slate-50">
                    <td class="px-5 py-2.5 font-mono text-xs font-semibold text-primary">{{ $row['hour'] }}</td>
                    <td class="px-4 py-2.5 text-center font-mono text-xs text-slate-700">{{ $row['total_orders'] }}</td>
                    <td class="px-4 py-2.5 text-right font-mono text-xs font-semibold text-accent">
                        ₱{{ number_format($row['total_sales'], 2) }}
                    </td>
                </tr>
                @endif
                @endforeach
                @if(collect($hourlyRows)->sum('total_orders') === 0)
                <tr>
                    <td colspan="3" class="px-5 py-10 text-center font-mono text-xs text-slate-400">
                        No orders for {{ $selectedDate }}
                    </td>
                </tr>
                @endif
            </tbody>
        </table>
    </div>

    {{-- Dispositions --}}
    <div class="bg-white rounded-xl border border-slate-200 shadow-sm overflow-hidden">
        <div class="px-5 py-3.5 border-b border-slate-100">
            <h2 class="text-sm font-bold text-slate-700 font-mono">By Disposition</h2>
        </div>
        @if($byDisposition->isEmpty())
        <div class="py-12 text-center font-mono text-xs text-slate-400">No data</div>
        @else
        <div class="divide-y divide-slate-100">
            @foreach($byDisposition as $row)
            <div class="px-5 py-3 flex items-center justify-between">
                <span class="text-xs font-mono text-slate-600 truncate pr-2">
                    {{ $row->disposition ?? 'Unknown' }}
                </span>
                <span class="text-xs font-bold font-mono text-slate-800 shrink-0">{{ $row->count }}</span>
            </div>
            @endforeach
        </div>
        @endif
    </div>

</div>

{{-- ORDERS TABLE --}}
<div class="bg-white rounded-xl border border-slate-200 shadow-sm overflow-hidden">
    <div class="px-6 py-4 border-b border-slate-100 flex items-center justify-between">
        <div>
            <h2 class="text-sm font-bold text-slate-700 font-mono">
                Orders — {{ $teams[$selectedTeam] ?? $selectedTeam }}
            </h2>
            <p class="text-xs text-slate-400 mt-0.5 font-mono">{{ $selectedDate }}</p>
        </div>
        <span class="text-xs font-mono text-slate-400">{{ $currentOrders->count() }} records</span>
    </div>

    @if($currentOrders->isEmpty())
    <div class="py-24 flex flex-col items-center justify-center text-center gap-4">
        <svg class="w-12 h-12 text-slate-200" fill="none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round"
                  d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10"/>
        </svg>
        <div>
            <p class="text-sm font-semibold font-mono text-slate-500">No orders found</p>
            <p class="text-xs font-mono text-slate-400 mt-1">Try a different date or run <code class="bg-slate-100 px-1 rounded">php artisan pancake:sync</code></p>
        </div>
    </div>
    @else
    <table class="w-full text-sm">
        <thead>
            <tr class="bg-slate-50 text-xs font-mono text-slate-400 uppercase tracking-wide">
                <th class="px-5 py-2.5 text-left">Order ID</th>
                <th class="px-4 py-2.5 text-left">Time</th>
                <th class="px-4 py-2.5 text-left">TSA</th>
                <th class="px-4 py-2.5 text-left">Product</th>
                <th class="px-4 py-2.5 text-left">Disposition</th>
                <th class="px-4 py-2.5 text-right">Amount</th>
            </tr>
        </thead>
        <tbody class="divide-y divide-slate-100">
            @foreach($currentOrders as $order)
            <tr class="hover:bg-slate-50 transition-colors">
                <td class="px-5 py-3 font-mono text-xs text-primary font-semibold">#{{ $order->pancake_order_id }}</td>
                <td class="px-4 py-3 font-mono text-xs text-slate-500">
                    {{ $order->pancake_created_at?->format('h:i A') ?? '—' }}
                </td>
                <td class="px-4 py-3 font-mono text-xs text-slate-700">{{ $order->tsa_name ?? '—' }}</td>
                <td class="px-4 py-3 font-mono text-xs text-slate-600">{{ $order->product ?? '—' }}</td>
                <td class="px-4 py-3 font-mono text-xs text-slate-500">{{ $order->disposition ?? '—' }}</td>
                <td class="px-4 py-3 font-mono text-xs font-semibold text-right text-accent">
                    ₱{{ number_format($order->amount, 2) }}
                </td>
            </tr>
            @endforeach
        </tbody>
    </table>
    @endif
</div>

@endsection

@push('topbar-right')
<div class="flex items-center gap-4 flex-wrap">

@if($selectedDate === now('Asia/Manila')->format('Y-m-d'))
@include('partials.live-indicator')
@endif

<form method="GET" action="{{ route('team-report') }}" class="flex items-center gap-3 flex-wrap">
    {{-- Hidden fallback so applying the date picker (or any submit besides clicking
         a team button directly) doesn't drop the currently selected team. --}}
    <input type="hidden" name="team" value="{{ $selectedTeam }}">

    <div class="flex rounded-lg border border-slate-200 overflow-hidden">
        @foreach($teams as $key => $label)
        <button type="submit" name="team" value="{{ $key }}"
                class="px-3 py-1.5 text-xs font-semibold font-mono cursor-pointer transition-colors
                       {{ $selectedTeam === $key ? 'bg-primary text-white' : 'bg-white text-slate-500 hover:bg-slate-50' }}">
            {{ $label }}
        </button>
        @endforeach
    </div>

    {{-- Positioned last (right before the primary action), matching the Dashboard's
         filters-then-date-then-action convention. --}}
    @include('partials.date-picker', ['mode' => 'single', 'id' => 'drp', 'date' => $selectedDate, 'submit' => 'form', 'dateField' => 'date'])

    <button type="submit"
            class="px-4 py-1.5 bg-accent text-white text-xs font-semibold rounded-lg
                   hover:bg-amber-600 transition-colors cursor-pointer">
        Fetch Orders
    </button>
</form>

</div>
@endpush
