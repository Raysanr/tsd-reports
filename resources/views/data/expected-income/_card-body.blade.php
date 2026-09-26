{{--
    One Expected Income card's body — shared between the overall "TELESALES"
    rollup card ($editable = false, plain read-only spans) and every
    product's own card ($editable = true, real <input>s). $d is a derived
    array from ExpectedIncomeCalculator::derive()/sum(); $entry (only
    passed for product cards) seeds each editable field's raw stored value
    so a blank/never-saved field shows 0 instead of the derived 0 twice
    over. Same visual grammar as Projections' own _column.blade.php +
    _pnl-row.blade.php: a $ input where editable, a plain span where not,
    an optional %/count column to the right.
--}}
<div class="px-5 py-4 font-mono text-[13px] space-y-2 border-b border-line dark:border-slate-700">
    @if($editable ?? false)
        <div class="flex items-center justify-end -mt-1 -mb-1">
            <span class="ei-card-status text-[11px] font-mono text-ink-muted dark:text-slate-400 min-h-[1.25rem]"></span>
        </div>
    @endif

    @foreach([
        ['key' => 'roas', 'label' => 'ROAS', 'money' => true],
        ['key' => 'actual_cost_per_lead', 'label' => 'Actual Cost Per Lead', 'money' => true],
        ['key' => 'number_of_leads', 'label' => 'Number of Leads', 'int' => true],
    ] as $col)
    <div class="grid grid-cols-[1fr_auto] gap-x-3 items-center">
        <span class="text-ink-muted dark:text-slate-400">{{ $col['label'] }}</span>
        @if($editable ?? false)
            <input type="text" inputmode="{{ ($col['int'] ?? false) ? 'numeric' : 'decimal' }}"
                   value="{{ ($col['money'] ?? false) ? number_format($entry?->{$col['key']} ?? 0, 2) : ($entry?->{$col['key']} ?? 0) }}"
                   data-field="{{ $col['key'] }}" @if($col['money'] ?? false) data-money="1" @endif
                   class="ei-field w-28 text-right bg-slate-50 dark:bg-slate-800 border border-line dark:border-slate-700 rounded-md px-1.5 py-0.5 font-semibold text-ink dark:text-slate-100 focus:ring-2 focus:ring-primary/40 focus:border-primary outline-none">
        @else
            <span data-out="{{ $col['key'] }}" class="font-semibold text-ink dark:text-slate-100 text-right">{{ ($col['money'] ?? false) ? number_format($d[$col['key']], 2) : number_format($d[$col['key']]) }}</span>
        @endif
    </div>
    @endforeach

    <div class="grid grid-cols-[1fr_auto] gap-x-3 items-center">
        <span class="text-ink-muted dark:text-slate-400">Conversion Rate</span>
        <span data-out="conversion_rate" class="font-semibold text-ink dark:text-slate-100 text-right">{{ $fmtPct($d['conversion_rate']) }}</span>
    </div>

    <div class="grid grid-cols-[1fr_auto] gap-x-3 items-center">
        <span class="text-ink-muted dark:text-slate-400">Number of Orders</span>
        @if($editable ?? false)
            <input type="text" inputmode="numeric" value="{{ $entry?->number_of_orders ?? 0 }}"
                   data-field="number_of_orders"
                   class="ei-field w-28 text-right bg-slate-50 dark:bg-slate-800 border border-line dark:border-slate-700 rounded-md px-1.5 py-0.5 font-semibold text-ink dark:text-slate-100 focus:ring-2 focus:ring-primary/40 focus:border-primary outline-none">
        @else
            <span data-out="number_of_orders" class="font-semibold text-ink dark:text-slate-100 text-right">{{ number_format($d['number_of_orders']) }}</span>
        @endif
    </div>
    <div class="grid grid-cols-[1fr_auto] gap-x-3 items-center">
        <span class="text-ink-muted dark:text-slate-400">Average Order Value</span>
        @if($editable ?? false)
            <input type="text" inputmode="decimal" value="{{ number_format($entry?->average_order_value ?? 0, 2) }}"
                   data-field="average_order_value" data-money="1"
                   class="ei-field w-28 text-right bg-slate-50 dark:bg-slate-800 border border-line dark:border-slate-700 rounded-md px-1.5 py-0.5 font-semibold text-ink dark:text-slate-100 focus:ring-2 focus:ring-primary/40 focus:border-primary outline-none">
        @else
            <span data-out="average_order_value" class="font-semibold text-ink dark:text-slate-100 text-right">{{ $fmtMoney($d['average_order_value']) }}</span>
        @endif
    </div>
</div>

<div class="px-5 py-4 font-mono text-[13px] space-y-0.5">
    <div class="grid grid-cols-[1fr_6.5rem_3.5rem] gap-x-2 items-center py-1">
        <span class="text-ink-muted dark:text-slate-400">Gross Sales</span>
        <span data-out="gross_sales" class="text-right font-semibold text-ink dark:text-slate-100">{{ $fmtMoney($d['gross_sales']) }}</span>
        <span class="text-right text-ink-muted dark:text-slate-500 text-xs">100.00%</span>
    </div>
    <div class="grid grid-cols-[1fr_6.5rem_3.5rem] gap-x-2 items-center py-1">
        <span class="text-ink-muted dark:text-slate-400">Cancelled</span>
        <span data-out="cancelled" class="text-right text-ink dark:text-slate-100">{{ $fmtMoney($d['cancelled']) }}</span>
        <span class="text-right text-ink-muted dark:text-slate-500 text-xs">5.00%</span>
    </div>
    <div class="grid grid-cols-[1fr_6.5rem_3.5rem] gap-x-2 items-center py-1">
        <span class="text-ink-muted dark:text-slate-400">Projected Returns</span>
        <span data-out="returns" class="text-right text-ink dark:text-slate-100">{{ $fmtMoney($d['returns']) }}</span>
        <span class="text-right text-ink-muted dark:text-slate-500 text-xs">25.00%</span>
    </div>
    <div class="grid grid-cols-[1fr_6.5rem_3.5rem] gap-x-2 items-center py-1">
        <span class="text-ink-muted dark:text-slate-400">Projected Delivered</span>
        <span data-out="delivered" class="text-right text-ink dark:text-slate-100">{{ $fmtMoney($d['delivered']) }}</span>
        <span class="text-right text-ink-muted dark:text-slate-500 text-xs">70.00%</span>
    </div>

    @foreach([
        ['key' => 'tax_allocation', 'label' => 'Tax Allocation'],
        ['key' => 'product_cost', 'label' => 'Product Cost'],
    ] as $col)
    <div class="grid grid-cols-[1fr_6.5rem_3.5rem] gap-x-2 items-center py-1">
        <span class="text-ink-muted dark:text-slate-400">{{ $col['label'] }}</span>
        @if($editable ?? false)
            <input type="text" inputmode="decimal" value="{{ number_format($entry?->{$col['key']} ?? 0, 2) }}"
                   data-field="{{ $col['key'] }}" data-money="1"
                   class="ei-field w-full text-right bg-slate-50 dark:bg-slate-800 border border-line dark:border-slate-700 rounded-md px-1.5 py-0.5 text-xs font-semibold text-ink dark:text-slate-100 focus:ring-2 focus:ring-primary/40 focus:border-primary outline-none">
        @else
            <span data-out="{{ $col['key'] }}" class="text-right text-ink dark:text-slate-100">{{ $fmtMoney($d[$col['key']]) }}</span>
        @endif
        <span data-out="{{ $col['key'] }}_pct" class="text-right text-ink-muted dark:text-slate-500 text-xs">{{ $fmtPct($d[$col['key'] . '_pct']) }}</span>
    </div>
    @endforeach

    <div class="grid grid-cols-[1fr_auto_4.5rem] gap-x-2 items-center py-1.5 border-y border-ink/10 dark:border-slate-600 font-bold">
        <span class="text-ink dark:text-slate-100">Gross Profit</span>
        <span data-out="gross_profit" class="text-right {{ $d['gross_profit'] < 0 ? 'text-red-600 dark:text-red-400' : 'text-ink dark:text-slate-100' }}">{{ $fmtMoney($d['gross_profit']) }}</span>
        <span data-out="gross_profit_pct" class="text-right text-ink-muted dark:text-slate-400 text-xs font-normal">{{ $fmtPct($d['gross_profit_pct']) }}</span>
    </div>

    <div class="pt-3 pb-1 font-bold text-ink dark:text-slate-100">Selling And Marketing</div>
    @foreach($sellingRows as $key => $label)
    <div class="grid grid-cols-[1fr_6.5rem_3.5rem] gap-x-2 items-center py-1">
        <span class="text-ink-muted dark:text-slate-400">{{ $label }}</span>
        @if($editable ?? false)
            <input type="text" inputmode="decimal" value="{{ number_format($entry?->{$key} ?? 0, 2) }}"
                   data-field="{{ $key }}" data-money="1"
                   class="ei-field w-full text-right bg-slate-50 dark:bg-slate-800 border border-line dark:border-slate-700 rounded-md px-1.5 py-0.5 text-xs font-semibold text-ink dark:text-slate-100 focus:ring-2 focus:ring-primary/40 focus:border-primary outline-none">
        @else
            <span data-out="{{ $key }}" class="text-right text-ink dark:text-slate-100">{{ $fmtMoney($d['selling_lines'][$key] ?? 0) }}</span>
        @endif
        <span data-out-pct="{{ $key }}" class="text-right text-ink-muted dark:text-slate-500 text-xs">{{ $d['gross_sales'] > 0 ? $fmtPct(($d['selling_lines'][$key] ?? 0) / $d['gross_sales']) : '0.00%' }}</span>
    </div>
    @endforeach
    {{-- COD Fee/Fulfillment Fee: computed formulas, never editable, even
         on a product card — same convention as Projections' own $editable
         P&L rows for these two (Delivered × 2.24%, Orders × ₱25 flat). --}}
    <div class="grid grid-cols-[1fr_6.5rem_3.5rem] gap-x-2 items-center py-1">
        <span class="text-ink-muted dark:text-slate-400">COD Fee</span>
        <span data-out="cod_fee" class="text-right text-ink dark:text-slate-100">{{ $fmtMoney($d['selling_lines']['cod_fee']) }}</span>
        <span data-out-pct="cod_fee" class="text-right text-ink-muted dark:text-slate-500 text-xs">{{ $d['gross_sales'] > 0 ? $fmtPct($d['selling_lines']['cod_fee'] / $d['gross_sales']) : '0.00%' }}</span>
    </div>
    <div class="grid grid-cols-[1fr_6.5rem_3.5rem] gap-x-2 items-center py-1">
        <span class="text-ink-muted dark:text-slate-400">Fulfillment Fee</span>
        <span data-out="fulfillment_fee" class="text-right text-ink dark:text-slate-100">{{ $fmtMoney($d['selling_lines']['fulfillment_fee']) }}</span>
        <span data-out-pct="fulfillment_fee" class="text-right text-ink-muted dark:text-slate-500 text-xs">{{ $d['gross_sales'] > 0 ? $fmtPct($d['selling_lines']['fulfillment_fee'] / $d['gross_sales']) : '0.00%' }}</span>
    </div>
    <div class="grid grid-cols-[1fr_auto_4.5rem] gap-x-2 items-center py-1.5 border-t border-line dark:border-slate-700 font-bold">
        <span class="text-ink dark:text-slate-100">Total Selling Costs</span>
        <span data-out="total_selling_costs" class="text-right text-ink dark:text-slate-100">{{ $fmtMoney($d['total_selling_costs']) }}</span>
        <span data-out="total_selling_costs_pct" class="text-right text-ink-muted dark:text-slate-400 text-xs font-normal">{{ $fmtPct($d['total_selling_costs_pct']) }}</span>
    </div>

    <div class="pt-3 pb-1 font-bold text-ink dark:text-slate-100">Operating Costs</div>
    @foreach($operatingRows as $key => $label)
    <div class="grid grid-cols-[1fr_6.5rem_3.5rem] gap-x-2 items-center py-1">
        <span class="text-ink-muted dark:text-slate-400">{{ $label }}</span>
        @if($editable ?? false)
            <input type="text" inputmode="decimal" value="{{ number_format($entry?->{$key} ?? 0, 2) }}"
                   data-field="{{ $key }}" data-money="1"
                   class="ei-field w-full text-right bg-slate-50 dark:bg-slate-800 border border-line dark:border-slate-700 rounded-md px-1.5 py-0.5 text-xs font-semibold text-ink dark:text-slate-100 focus:ring-2 focus:ring-primary/40 focus:border-primary outline-none">
        @else
            <span data-out="{{ $key }}" class="text-right text-ink dark:text-slate-100">{{ $fmtMoney($d['operating_lines'][$key] ?? 0) }}</span>
        @endif
        <span data-out-pct="{{ $key }}" class="text-right text-ink-muted dark:text-slate-500 text-xs">{{ $d['gross_sales'] > 0 ? $fmtPct(($d['operating_lines'][$key] ?? 0) / $d['gross_sales']) : '0.00%' }}</span>
    </div>
    @endforeach
    <div class="grid grid-cols-[1fr_auto_4.5rem] gap-x-2 items-center py-1.5 border-t border-line dark:border-slate-700 font-bold">
        <span class="text-ink dark:text-slate-100">Total Operating Costs</span>
        <span data-out="total_operating_costs" class="text-right text-ink dark:text-slate-100">{{ $fmtMoney($d['total_operating_costs']) }}</span>
        <span data-out="total_operating_costs_pct" class="text-right text-ink-muted dark:text-slate-400 text-xs font-normal">{{ $fmtPct($d['total_operating_costs_pct']) }}</span>
    </div>

    <div class="grid grid-cols-[1fr_auto_4.5rem] gap-x-2 items-center py-2 mt-2 border-t-2 border-ink/20 dark:border-slate-600">
        <span class="font-bold text-ink dark:text-slate-100">NET INCOME</span>
        <span data-out="net_income" class="text-right font-bold text-base {{ $d['net_income'] < 0 ? 'text-red-600 dark:text-red-400' : 'text-primary' }}">{{ $fmtMoney($d['net_income']) }}</span>
        <span data-out="net_income_pct" class="text-right text-ink-muted dark:text-slate-400 text-xs">{{ $fmtPct($d['net_income_pct']) }}</span>
    </div>
</div>
