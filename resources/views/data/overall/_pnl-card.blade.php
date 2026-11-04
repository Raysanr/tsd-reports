{{-- Read-only Projections P&L card for TSD Overall Performance (2026-11-04).
     $p = a ProjectionCalculator pnl array; $label, $headerBg, $headerText. --}}
@php
    $money = fn ($n) => number_format((float) $n, 2);
    $pct = fn ($n) => number_format(((float) $n) * 100, 2) . '%';
    $gs = (float) $p['gross_sales'];
    $share = fn ($n) => $gs > 0 ? $pct((float) $n / $gs) : $pct(0);
@endphp
<div class="ei-card bg-white dark:bg-slate-900 border border-line dark:border-slate-700 rounded-2xl shadow-panel overflow-hidden w-[26rem] shrink-0" @isset($cardId) id="{{ $cardId }}" @endisset>
    <div class="px-5 py-4" style="background:{{ $headerBg }};">
        <span class="font-mono font-bold text-sm uppercase tracking-wide truncate block" style="color:{{ $headerText ?? 'var(--color-ink, #111827)' }};">{{ $label }}</span>
    </div>
    <div class="px-5 py-4 font-mono text-[13px] space-y-2 border-b border-line dark:border-slate-700">
        <div class="grid grid-cols-[1fr_auto] gap-x-3">
            <span class="text-ink-muted dark:text-slate-400">Number of Orders</span>
            <span class="font-semibold text-ink dark:text-slate-100 text-right">{{ number_format((float) $p['orders'], 2) }}</span>
        </div>
        <div class="grid grid-cols-[1fr_auto] gap-x-3">
            <span class="text-ink-muted dark:text-slate-400">Average Order Value</span>
            <span class="font-semibold text-ink dark:text-slate-100 text-right">{{ $money($p['orders'] > 0 ? $gs / $p['orders'] : 0) }}</span>
        </div>
    </div>
    <div class="px-5 py-4 font-mono text-[13px] space-y-1">
        @php
            $line = function (string $label, $value, string $class = '') use ($money, $share) {
                return '<div class="grid grid-cols-[1fr_7rem_4.5rem] gap-x-2 py-0.5 ' . $class . '"><span>' . e($label) . '</span><span class="text-right">' . $money($value) . '</span><span class="text-right text-ink-muted dark:text-slate-400">' . $share($value) . '</span></div>';
            };
            $muted = 'text-ink-muted dark:text-slate-300';
            $bold = 'font-bold text-ink dark:text-slate-100 border-t border-line dark:border-slate-700 pt-1.5';
            $heading = '<div class="pt-3 pb-1 font-bold text-ink dark:text-slate-100">%s</div>';
        @endphp
        {!! $line('Gross Sales', $p['gross_sales'], $muted) !!}
        {!! $line('Cancelled', $p['cancelled'], 'text-red-600 dark:text-red-400') !!}
        {!! $line('Projected Returns', $p['returns'], 'text-red-600 dark:text-red-400') !!}
        {!! $line('Projected Delivered', $p['delivered'], 'text-red-600 dark:text-red-400') !!}
        {!! $line('Tax Allocation', $p['tax_allocation'], $muted) !!}
        {!! $line('Product Cost', $p['product_cost'], $muted) !!}
        {!! $line('Gross Profit', $p['gross_profit'], $bold) !!}

        {!! sprintf($heading, 'Selling And Marketing') !!}
        @foreach($sellingRows as $key => $rowLabel)
        {!! $line($rowLabel, $p['selling_lines'][$key] ?? 0, $muted) !!}
        @endforeach
        {!! $line('Total Selling Costs', $p['total_selling_costs'], $bold) !!}
        {!! $line('Income Before OPEX', $p['income_before_opex'] ?? 0, $bold) !!}

        {!! sprintf($heading, 'Operating Costs') !!}
        @foreach($operatingRows as $key => $rowLabel)
        {!! $line($rowLabel, $p['operating_lines'][$key] ?? 0, $muted) !!}
        @endforeach
        {!! $line('Total Operating Costs', $p['total_operating_costs'], $bold) !!}
    </div>
    <div class="px-5 py-4 border-t-2 border-line dark:border-slate-700 font-mono text-[13px]">
        <div class="grid grid-cols-[1fr_7rem_4.5rem] gap-x-2 items-center font-bold">
            <span class="text-ink dark:text-slate-100">NET INCOME</span>
            <span class="text-right text-base {{ $p['net_income'] < 0 ? 'text-red-600 dark:text-red-400' : 'text-green-600 dark:text-green-400' }}">{{ $money($p['net_income']) }}</span>
            <span class="text-right text-ink-muted dark:text-slate-400 font-normal">{{ $share($p['net_income']) }}</span>
        </div>
    </div>
</div>
