@extends('layouts.app')
@section('title', 'Dashboard')
@section('subtitle', 'Sales Summary · Recent Orders')

@section('content')

{{-- DB SCHEMA ERROR BANNER --}}
@if(!empty($dbError))
<div class="mb-6 flex items-center gap-4 bg-red-50 border border-red-200 rounded-xl px-6 py-4">
    <svg class="w-5 h-5 text-red-500 shrink-0" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
        <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v2m0 4h.01M10.29 3.86L1.82 18a2 2 0 001.71 3h16.94a2 2 0 001.71-3L13.71 3.86a2 2 0 00-3.42 0z"/>
    </svg>
    <p class="text-sm font-mono text-red-700">
        {{ $dbError }}
    </p>
</div>
@endif

{{-- API NOT CONNECTED BANNER --}}
@if(!$apiConnected)
<div class="mb-6 flex items-center gap-4 bg-yellow-50 border border-yellow-200 rounded-xl px-6 py-4">
    <svg class="w-5 h-5 text-yellow-500 shrink-0" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
        <path stroke-linecap="round" stroke-linejoin="round" d="M13 16h-1v-4h-1m1-4h.01M12 2a10 10 0 100 20A10 10 0 0012 2z"/>
    </svg>
    <p class="text-sm font-mono text-yellow-700">
        Pancake POS API not connected yet.
        <a href="{{ route('settings') }}" class="underline font-bold ml-1">Go to Settings → API</a> to add your key.
    </p>
</div>
@endif

{{-- KPI CARDS --}}
<div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-5 mb-8">

    <div class="bg-white rounded-xl border border-slate-200 p-5 shadow-sm">
        <p class="text-xs font-mono font-semibold text-slate-400 uppercase tracking-wider mb-2">Total Cross-Sell Sales</p>
        <p class="text-3xl font-bold text-slate-800 font-mono leading-none">
            ₱{{ number_format($stats['total_sales'], 2) }}
        </p>
        <p class="mt-2 text-xs text-slate-400 font-mono">{{ $stats['total_orders'] }} orders</p>
    </div>

    <div class="bg-white rounded-xl border border-yellow-200 p-5 shadow-sm">
        <p class="text-xs font-mono font-semibold text-yellow-600 uppercase tracking-wider mb-2">Total Restocking</p>
        <p class="text-3xl font-bold text-slate-800 font-mono leading-none">
            ₱{{ number_format($stats['restocking_value'], 2) }}
        </p>
        <p class="mt-2 text-xs text-slate-400 font-mono">{{ $stats['restocking_count'] }} {{ \Illuminate\Support\Str::plural('order', $stats['restocking_count']) }} awaiting stock</p>
    </div>

    <div class="bg-white rounded-xl border border-slate-200 p-5 shadow-sm">
        <div class="flex items-center gap-1.5 mb-2">
            <svg class="w-3.5 h-3.5 text-amber-400" fill="currentColor" viewBox="0 0 20 20">
                <path d="M10 1l2.39 5.51 5.99.52-4.53 3.95 1.38 5.87L10 13.77l-5.23 3.08 1.38-5.87L1.62 7.03l5.99-.52L10 1z"/>
            </svg>
            <p class="text-xs font-mono font-semibold text-slate-400 uppercase tracking-wider">Top TSA Today</p>
        </div>
        @if($topTsa && $topTsa->upsell_count > 0)
        <p class="text-2xl font-bold text-slate-800 font-mono leading-none truncate" title="{{ $topTsa->display_name }}">
            {{ $topTsa->display_name }}
        </p>
        <p class="mt-2 text-xs text-slate-400 font-mono">
            {{ $topTsa->team_name ?? '—' }} · {{ $topTsa->upsell_count }} {{ \Illuminate\Support\Str::plural('upsell', $topTsa->upsell_count) }} · {{ $topTsa->upsell_rate }}%
        </p>
        <p class="mt-1 text-sm font-bold font-mono text-accent">
            ₱{{ number_format($topTsa->upsell_sales, 2) }}
        </p>
        @else
        <p class="text-2xl font-bold text-slate-300 font-mono leading-none">—</p>
        <p class="mt-2 text-xs text-slate-400 font-mono">No upsells logged yet</p>
        @endif
    </div>

    {{-- Total Cancelled Orders — deliberately a different accent (rose) from Total
         Restocking's yellow: these are NOT the same bucket. Restocking = order
         awaiting stock. Cancelled = the customer cancelled just the TSA's upsell
         add-on while their primary order still went through. Because is_upsell is
         already forced false for these at sync time (SyncTodayOrders), the amount
         is automatically excluded from Total Cross-Sell Sales above with no manual
         subtraction — this card is purely visibility into that, not a deduction step. --}}
    <div class="bg-white rounded-xl border border-rose-200 p-5 shadow-sm">
        <p class="text-xs font-mono font-semibold text-rose-500 uppercase tracking-wider mb-2">Total Cancelled Orders</p>
        <p class="text-3xl font-bold text-slate-800 font-mono leading-none">
            ₱{{ number_format($stats['cancelled_value'], 2) }}
        </p>
        <p class="mt-2 text-xs text-slate-400 font-mono">{{ $stats['cancelled_count'] }} upsell {{ \Illuminate\Support\Str::plural('cancellation', $stats['cancelled_count']) }}</p>
    </div>

</div>

{{-- RECENT ORDERS + HOURLY ACTIVITY — side by side, same height (stacks on mobile). --}}
<div class="grid grid-cols-1 lg:grid-cols-2 gap-6 items-stretch">

<div class="bg-white rounded-xl border border-slate-200 shadow-sm overflow-hidden flex flex-col">
        <div class="px-5 py-4 border-b border-slate-100">
            <h2 class="text-sm font-bold text-slate-700 font-mono">Recent Orders</h2>
        </div>

        @if($recentOrders->isEmpty())
        <div class="flex-1 py-16 flex flex-col items-center justify-center text-center gap-3">
            <svg class="w-10 h-10 text-slate-200" fill="none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2"/>
            </svg>
            <p class="text-sm font-mono text-slate-400">Recent orders will appear here once synced</p>
        </div>
        @else
        <div class="flex-1 overflow-y-auto">
        <table class="w-full text-sm">
            <thead>
                <tr class="bg-slate-50 text-xs font-mono text-slate-400 uppercase tracking-wide">
                    <th class="px-5 py-2.5 text-left">Order ID</th>
                    <th class="px-4 py-2.5 text-left">TSA</th>
                    <th class="px-4 py-2.5 text-left">Product</th>
                    <th class="px-4 py-2.5 text-left">Disposition</th>
                    <th class="px-4 py-2.5 text-left">Status</th>
                    <th class="px-4 py-2.5 text-right">Amount</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
                @foreach($recentOrders as $order)
                <tr class="hover:bg-slate-50 transition-colors {{ $order->is_void_status ? 'opacity-60' : '' }}">
                    <td class="px-5 py-3 font-mono text-xs text-primary font-semibold">
                        #{{ $order->pancake_order_id }}
                    </td>
                    <td class="px-4 py-3 font-mono text-xs text-slate-700">
                        {{ $order->tsa_name ?? '—' }}
                    </td>
                    <td class="px-4 py-3 font-mono text-xs text-slate-600">
                        {{ $order->product ?? '—' }}
                    </td>
                    <td class="px-4 py-3 font-mono text-xs text-slate-500">
                        {{ $order->disposition ?? '—' }}
                    </td>
                    <td class="px-4 py-3">
                        @if($order->status_label)
                        <span @class([
                            'inline-flex items-center px-2 py-0.5 rounded-full text-[10px] font-semibold font-mono whitespace-nowrap',
                            'bg-yellow-100 text-yellow-700' => $order->status_code === 11,
                            'bg-red-100 text-red-700'     => $order->is_void_status && $order->status_code !== 11,
                            'bg-green-100 text-green-700' => !$order->is_void_status,
                        ])>
                            {{ $order->status_label }}
                        </span>
                        @else
                        <span class="text-xs text-slate-300">—</span>
                        @endif
                    </td>
                    <td class="px-4 py-3 font-mono text-xs font-semibold text-right {{ $order->is_void_status ? 'text-slate-400' : 'text-accent' }}">
                        ₱{{ number_format($order->amount, 2) }}
                    </td>
                </tr>
                @endforeach
            </tbody>
        </table>
        </div>
        @endif
    </div>

    {{-- HOURLY ACTIVITY — a 24-hour radial chart (one wedge per hour, like a clock
         face) rather than a linear bar chart. Hour-of-day genuinely wraps around
         (23 → 0), so a clock layout reads more naturally for "when did calls happen"
         than a straight line does, and makes shift-timing gaps (the overnight-shift
         case we found by hand this session) visible as a gap in the ring. --}}
    @if($hourlyActivity->sum() > 0)
    @php
        $hourMax    = max(1, $hourlyActivity->max());
        $peakHour   = $hourlyActivity->search($hourlyActivity->max());
        $size       = 460;
        $cx         = $size / 2;
        $cy         = $size / 2;
        $maxRadius  = 150;
        $innerHole  = 14;   // small center hole so every hour renders as a visible wedge, even at 0
        $labelR     = $maxRadius + 26;
        $slices     = collect(range(0, 23))->map(function ($hour) use ($hourlyActivity, $hourMax, $cx, $cy, $maxRadius, $innerHole, $labelR, $peakHour) {
            $count      = $hourlyActivity[$hour];
            $outerR     = $innerHole + ($count / $hourMax) * ($maxRadius - $innerHole);
            $centerDeg  = -90 + $hour * 15;      // hour 0 at 12 o'clock, clockwise
            $halfWidth  = 6.5;                    // slice spans 13° of its 15° slot, leaving a thin gap
            $a1 = deg2rad($centerDeg - $halfWidth);
            $a2 = deg2rad($centerDeg + $halfWidth);
            $labelAngle = deg2rad($centerDeg);

            return [
                'hour'   => $hour,
                'count'  => $count,
                'isPeak' => $hour === $peakHour,
                'x1'     => $cx + $innerHole * cos($a1), 'y1' => $cy + $innerHole * sin($a1),
                'x2'     => $cx + $outerR * cos($a1),    'y2' => $cy + $outerR * sin($a1),
                'x3'     => $cx + $outerR * cos($a2),    'y3' => $cy + $outerR * sin($a2),
                'x4'     => $cx + $innerHole * cos($a2), 'y4' => $cy + $innerHole * sin($a2),
                'lx'     => $cx + $labelR * cos($labelAngle),
                'ly'     => $cy + $labelR * sin($labelAngle),
                'anchor' => cos($labelAngle) > 0.3 ? 'start' : (cos($labelAngle) < -0.3 ? 'end' : 'middle'),
            ];
        });
    @endphp
    <div class="bg-white rounded-xl border border-slate-200 shadow-sm px-5 py-4 flex flex-col">
        <div class="flex items-center justify-between mb-3">
            <div>
                <h2 class="text-sm font-bold text-slate-700 font-mono">Hourly Activity</h2>
                <p class="text-xs font-mono text-slate-400 mt-0.5">Calls per hour, today</p>
            </div>
            <div class="text-right">
                <p class="text-2xl font-bold text-slate-800 font-mono leading-none">{{ $hourlyActivity->sum() }}</p>
                <p class="text-xs font-mono text-slate-400 mt-1">peak {{ \App\Support\HourFormatter::label($peakHour) }}</p>
            </div>
        </div>

        <div class="relative flex-1 flex items-center justify-center">
        {{-- Custom hover tooltip — the native SVG <title> tooltip (kept below for
             screen readers) takes ~1s to appear and can't be styled; this shows
             instantly and matches the app's look. --}}
        <div id="hourlyTooltip" class="hidden absolute z-10 pointer-events-none px-2.5 py-1.5 rounded-lg bg-slate-800 text-white text-xs font-mono shadow-lg whitespace-nowrap"
             style="transform: translate(-50%, -120%)">
        </div>

        <svg viewBox="0 0 {{ $size }} {{ $size }}" class="mx-auto block" style="max-width:460px; width:100%; height:auto">
            {{-- Recessive reference rings, at even fractions of the busiest hour --}}
            @foreach([0.33, 0.66, 1] as $frac)
            <circle cx="{{ $cx }}" cy="{{ $cy }}" r="{{ $innerHole + $frac * ($maxRadius - $innerHole) }}"
                    fill="none" stroke="#E2E8F0" stroke-width="1" />
            @endforeach

            @foreach($slices as $s)
            <path class="hourly-wedge" data-hour="{{ \App\Support\HourFormatter::label($s['hour']) }}" data-count="{{ $s['count'] }}"
                  style="cursor:pointer; transition: opacity 150ms ease"
                  d="M {{ $s['x1'] }},{{ $s['y1'] }}
                     L {{ $s['x2'] }},{{ $s['y2'] }}
                     A {{ $maxRadius }},{{ $maxRadius }} 0 0,1 {{ $s['x3'] }},{{ $s['y3'] }}
                     L {{ $s['x4'] }},{{ $s['y4'] }}
                     A {{ $innerHole }},{{ $innerHole }} 0 0,0 {{ $s['x1'] }},{{ $s['y1'] }} Z"
                  fill="{{ $s['isPeak'] ? '#CA8A04' : '#CBD5E1' }}">
                <title>{{ \App\Support\HourFormatter::label($s['hour']) }} — {{ $s['count'] }} {{ \Illuminate\Support\Str::plural('call', $s['count']) }}</title>
            </path>
            @endforeach

            {{-- Hour labels — 12-hour clock format, around the outside of the ring --}}
            @foreach($slices as $s)
            <text x="{{ $s['lx'] }}" y="{{ $s['ly'] }}" text-anchor="{{ $s['anchor'] }}"
                  dominant-baseline="middle" font-size="12" font-family="monospace"
                  fill="{{ $s['isPeak'] ? '#854D0E' : '#94A3B8' }}" font-weight="{{ $s['isPeak'] ? 'bold' : 'normal' }}">
                {{ \App\Support\HourFormatter::label($s['hour']) }}
            </text>
            @endforeach
        </svg>
        </div>
    </div>
    <script>
    (function () {
        const wrap    = document.querySelector('#hourlyTooltip')?.parentElement;
        const tooltip = document.getElementById('hourlyTooltip');
        if (!wrap || !tooltip) return;

        wrap.querySelectorAll('.hourly-wedge').forEach(wedge => {
            wedge.addEventListener('mouseenter', () => {
                const hour  = wedge.dataset.hour;
                const count = wedge.dataset.count;
                tooltip.innerHTML = `<span class="font-semibold">${hour}</span> — ${count} ${count == 1 ? 'call' : 'calls'}`;
                tooltip.classList.remove('hidden');
                wedge.style.opacity = '0.75';
            });
            wedge.addEventListener('mousemove', (e) => {
                const box = wrap.getBoundingClientRect();
                tooltip.style.left = (e.clientX - box.left) + 'px';
                tooltip.style.top  = (e.clientY - box.top) + 'px';
            });
            wedge.addEventListener('mouseleave', () => {
                tooltip.classList.add('hidden');
                wedge.style.opacity = '1';
            });
        });
    })();
    </script>
    @endif

</div>

{{-- TEAM COMPARISON — replaces the old Shop Lines panel. That one only showed
     revenue by team; this shows orders + upsell rate + revenue side by side,
     since upsell rate is the metric the rest of this app is built around. --}}
@if($teamComparison->isNotEmpty())
@php
    $teamColors = ['#0891B2', '#059669']; // cyan, emerald — fixed order, never cycled per-team
    $bestRate   = $teamComparison->max('upsell_rate');
@endphp
<div class="mt-6">
    <div class="flex items-center justify-between mb-3">
        <div>
            <h2 class="text-sm font-bold text-slate-700 font-mono">Team Comparison</h2>
            <p class="text-xs font-mono text-slate-400 mt-0.5">Today, by upsell performance</p>
        </div>
    </div>

    <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
        @foreach($teamComparison as $i => $team)
        @php
            $color    = $teamColors[$i % count($teamColors)];
            $isLeader = $team['upsell_rate'] > 0 && $team['upsell_rate'] === $bestRate;
        @endphp
        <div class="bg-white rounded-xl border border-slate-200 shadow-sm overflow-hidden">
            <div class="h-1.5" style="background:{{ $color }}"></div>
            <div class="p-5">
                <div class="flex items-center justify-between mb-4">
                    <div class="flex items-center gap-3">
                        <span class="w-9 h-9 rounded-full flex items-center justify-center text-white text-sm font-bold font-mono shrink-0"
                              style="background:{{ $color }}">
                            {{ mb_substr($team['name'], 0, 1) }}
                        </span>
                        <p class="text-sm font-bold font-mono text-slate-700">{{ $team['name'] }}</p>
                    </div>
                    @if($isLeader)
                    <span class="inline-flex items-center gap-1 px-2 py-1 rounded-full text-[10px] font-bold font-mono uppercase tracking-wide bg-emerald-50 text-emerald-700">
                        <svg class="w-3 h-3" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M5 10l7-7m0 0l7 7m-7-7v18"/>
                        </svg>
                        Leading
                    </span>
                    @endif
                </div>

                <div class="grid grid-cols-2 gap-4 mb-4">
                    <div>
                        <p class="text-2xl font-bold text-slate-800 font-mono leading-none" style="font-variant-numeric: tabular-nums">{{ $team['total_calls'] }}</p>
                        <p class="text-xs font-mono text-slate-400 mt-1">calls today</p>
                    </div>
                    <div>
                        <p class="text-2xl font-bold text-slate-800 font-mono leading-none" style="font-variant-numeric: tabular-nums">{{ $team['upsell_count'] }}</p>
                        <p class="text-xs font-mono text-slate-400 mt-1">upsells</p>
                    </div>
                </div>

                <div class="mb-4">
                    <div class="flex items-center justify-between mb-1.5">
                        <p class="text-[10px] font-mono font-semibold text-slate-400 uppercase tracking-wide">Upsell Rate</p>
                        <p class="text-xs font-mono font-bold text-slate-700">{{ number_format($team['upsell_rate'], 1) }}%</p>
                    </div>
                    <div class="w-full h-2 rounded-full bg-slate-100 overflow-hidden">
                        <div class="h-full rounded-full" style="width:{{ min(100, $team['upsell_rate']) }}%; background:{{ $color }}"></div>
                    </div>
                </div>

                <div class="pt-4 border-t border-slate-100 flex items-center justify-between">
                    <p class="text-xs font-mono text-slate-400">Revenue</p>
                    <p class="text-sm font-bold font-mono text-accent" style="font-variant-numeric: tabular-nums">₱{{ number_format($team['revenue'], 2) }}</p>
                </div>
            </div>
        </div>
        @endforeach
    </div>
</div>
@endif

{{-- RESTOCKING BREAKDOWN — the "Total Restocking" KPI tile up top is one lump sum
     across every TSA and brand; this splits it out per brand and per TSA so it's
     clear whose/which brand's stock is actually the bottleneck. --}}
@if($restockingByTeam->sum('restocking_count') > 0)
<div class="mt-6">
    <div class="flex items-center justify-between mb-3">
        <div>
            <h2 class="text-sm font-bold text-slate-700 font-mono">Restocking Breakdown</h2>
            <p class="text-xs font-mono text-slate-400 mt-0.5">Orders awaiting stock, by brand and TSA</p>
        </div>
    </div>

    <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">

        {{-- By brand --}}
        <div class="bg-white rounded-xl border border-yellow-200 shadow-sm overflow-hidden">
            <div class="px-5 py-4 border-b border-slate-100">
                <h3 class="text-sm font-bold text-slate-700 font-mono">By Brand</h3>
            </div>
            <div class="divide-y divide-slate-100">
                @foreach($restockingByTeam as $team)
                <div class="px-5 py-3 flex items-center gap-4">
                    <div class="flex-1 min-w-0">
                        <p class="text-sm font-semibold font-mono text-slate-700 truncate">{{ $team['name'] }}</p>
                        <p class="text-xs font-mono text-slate-400 mt-0.5">{{ $team['restocking_count'] }} {{ \Illuminate\Support\Str::plural('order', $team['restocking_count']) }} awaiting stock</p>
                    </div>
                    <p class="text-sm font-bold font-mono text-yellow-600 shrink-0">₱{{ number_format($team['restocking_value'], 2) }}</p>
                </div>
                @endforeach
            </div>
        </div>

        {{-- By TSA --}}
        <div class="bg-white rounded-xl border border-yellow-200 shadow-sm overflow-hidden">
            <div class="px-5 py-4 border-b border-slate-100">
                <h3 class="text-sm font-bold text-slate-700 font-mono">By TSA</h3>
            </div>
            @if($restockingByTsa->isEmpty())
            <div class="py-10 flex flex-col items-center justify-center text-center gap-2">
                <p class="text-sm font-mono text-slate-400">No restocking orders attributed to a TSA</p>
            </div>
            @else
            <div class="divide-y divide-slate-100 max-h-80 overflow-y-auto">
                @foreach($restockingByTsa as $row)
                <div class="px-5 py-3 flex items-center gap-4">
                    <div class="flex-1 min-w-0">
                        <p class="text-sm font-semibold font-mono text-slate-700 truncate">{{ $row->display_name }}</p>
                        <p class="text-xs font-mono text-slate-400 mt-0.5">{{ $row->team_name ?? '—' }} · {{ $row->restocking_count }} {{ \Illuminate\Support\Str::plural('order', $row->restocking_count) }}</p>
                    </div>
                    <p class="text-sm font-bold font-mono text-yellow-600 shrink-0">₱{{ number_format($row->restocking_value, 2) }}</p>
                </div>
                @endforeach
            </div>
            @endif
        </div>

    </div>
</div>
@endif

{{-- TODAY'S TSA LEADERBOARD + TOP UPSELL PRODUCTS --}}
<div class="grid grid-cols-1 lg:grid-cols-2 gap-6 mt-6">

    <div class="bg-white rounded-xl border border-slate-200 shadow-sm overflow-hidden">
        <div class="px-5 py-4 border-b border-slate-100">
            <h2 class="text-sm font-bold text-slate-700 font-mono">Today's TSA Leaderboard</h2>
            <p class="text-xs font-mono text-slate-400 mt-0.5">Ranked by upsells</p>
        </div>

        @if($tsaLeaderboard->isEmpty())
        <div class="py-16 flex flex-col items-center justify-center text-center gap-3">
            <p class="text-sm font-mono text-slate-400">No calls logged yet today</p>
        </div>
        @else
        <div class="divide-y divide-slate-100">
            @foreach($tsaLeaderboard as $i => $row)
            <div class="px-5 py-3 flex items-center gap-4">
                <span class="w-5 text-xs font-mono font-bold text-slate-300">{{ $i + 1 }}</span>
                <div class="flex-1 min-w-0">
                    <p class="text-sm font-semibold font-mono text-slate-700 truncate">{{ $row->display_name }}</p>
                    <p class="text-xs font-mono text-slate-400 mt-0.5">{{ $row->team_name }} · {{ $row->total_calls }} {{ \Illuminate\Support\Str::plural('call', $row->total_calls) }}</p>
                </div>
                <div class="text-right shrink-0">
                    <p class="text-sm font-bold font-mono {{ $row->upsell_count > 0 ? 'text-primary' : 'text-slate-300' }}">{{ $row->upsell_count }} upsells</p>
                    <p class="text-xs font-mono text-slate-400 mt-0.5">{{ $row->upsell_rate }}% rate</p>
                </div>
            </div>
            @endforeach
        </div>
        @endif
    </div>

    <div class="bg-white rounded-xl border border-slate-200 shadow-sm overflow-hidden">
        <div class="px-5 py-4 border-b border-slate-100">
            <h2 class="text-sm font-bold text-slate-700 font-mono">Top Upsell Products</h2>
            <p class="text-xs font-mono text-slate-400 mt-0.5">Today, by number of upsells</p>
        </div>

        @if($topProducts->isEmpty())
        <div class="py-16 flex flex-col items-center justify-center text-center gap-3">
            <p class="text-sm font-mono text-slate-400">No upsells logged yet today</p>
        </div>
        @else
        <div class="divide-y divide-slate-100">
            @foreach($topProducts as $i => $row)
            <div class="px-5 py-3 flex items-center gap-4">
                <span class="w-5 text-xs font-mono font-bold text-slate-300">{{ $i + 1 }}</span>
                <div class="flex-1 min-w-0">
                    <p class="text-sm font-semibold font-mono text-slate-700 truncate">{{ $row->product }}</p>
                    <p class="text-xs font-mono text-slate-400 mt-0.5">{{ $row->upsell_count }} {{ \Illuminate\Support\Str::plural('upsell', $row->upsell_count) }}</p>
                </div>
                <p class="text-sm font-bold font-mono text-accent shrink-0">₱{{ number_format($row->total_sales, 2) }}</p>
            </div>
            @endforeach
        </div>
        @endif
    </div>

</div>

@endsection

@push('topbar-right')
<div class="flex items-center gap-3">

    {{-- Live refresh indicator — only when viewing today --}}
    @if($dateFrom->isToday() && $dateTo->copy()->startOfDay()->isToday())
    @include('partials.live-indicator')
    @endif

    {{-- Shared date-picker (partials/date-picker.blade.php) — range mode, since the
         Dashboard is the one report that can show more than a single day. --}}
    @include('partials.date-picker', [
        'mode' => 'range', 'id' => 'drp', 'dateFrom' => $dateFrom, 'dateTo' => $dateTo,
        'submit' => 'navigate', 'navigateBase' => '/',
    ])

    {{-- Sync — icon-only, matching the date picker's trigger size/shape. The stale-
         sync health check that used to live in the "Last Sync" KPI card now lives
         here instead: red background + warning wording in the tooltip/aria-label
         (color is never the only signal — the text explains it too) when the
         background cron hasn't run recently. "No data synced" stays as visible
         text since it's a different, unrelated signal (this date range has no
         orders yet, regardless of whether the cron itself is healthy). --}}
    @if(!$hasSyncedData)
    <span class="text-xs font-mono text-slate-400">No data synced</span>
    @endif
    @php
        $syncTooltip = $stats['sync_stale']
            ? 'Sync — stale, last ran ' . ($stats['last_synced'] ? \Carbon\Carbon::parse($stats['last_synced'])->diffForHumans() : 'never') . '. Check the scheduler.'
            : 'Sync — last ran ' . \Carbon\Carbon::parse($stats['last_synced'])->diffForHumans() . ', every ' . $stats['sync_interval'] . 'min';
    @endphp
    <button id="syncBtn" type="button" title="{{ $syncTooltip }}"
        aria-label="Sync orders{{ $stats['sync_stale'] ? '. Warning: background sync appears stale' : '' }}"
        class="inline-flex items-center justify-center w-8 h-8 {{ $stats['sync_stale'] ? 'bg-red-600 hover:bg-red-700' : 'bg-yellow-600 hover:bg-yellow-700' }} text-white rounded-full transition-colors cursor-pointer shrink-0">
        <svg id="syncIcon" class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/>
        </svg>
    </button>
</div>
@endpush

@push('scripts')
<script>
(function () {
    const syncBtn = document.getElementById('syncBtn');

    // The date-picker partial (mode='drp' on this page) publishes its currently
    // selected range to window.__datePicker.drp as the user interacts with it —
    // read from there instead of duplicating the picker's own state here.
    syncBtn.addEventListener('click', () => {
        const icon = document.getElementById('syncIcon');
        syncBtn.disabled = true;
        icon.classList.add('animate-spin');
        syncBtn.querySelector('span') && (syncBtn.querySelector('span').textContent = 'Syncing...');

        const range = (window.__datePicker && window.__datePicker.drp) || {
            from: '{{ $dateFrom->toDateString() }}',
            to: '{{ $dateTo->copy()->startOfDay()->toDateString() }}',
        };

        const csrfToken = document.querySelector('meta[name=csrf-token]').content;
        fetch('/sync', {
            method : 'POST',
            headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrfToken },
            body   : JSON.stringify({ date_from: range.from, date_to: range.to }),
        })
        .then(r => r.json())
        // Swap the freshly synced numbers in place — no full reload, no
        // flicker, scroll position kept (softRefresh in resources/js/app.js).
        .then(() => window.softRefresh())
        .finally(() => { syncBtn.disabled = false; icon.classList.remove('animate-spin'); });
    });
})();
</script>
@endpush
