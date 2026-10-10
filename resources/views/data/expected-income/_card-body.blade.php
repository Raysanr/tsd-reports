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

    $customRowKeys (array of every key from ProjectionCustomRow, both
    sections combined) tells the Selling/Operating loops below which of
    $sellingRows/$operatingRows entries are a CUSTOM row rather than a
    real column on ExpectedIncomeEntry — explicit request, 2026-09-28: a
    row added via the + icon on Projections "should be automatically
    added to the expected income rows." $entry?->{$key} would silently
    read null (Eloquent doesn't throw on an unknown property) for a
    custom key since its value lives in ExpectedIncomeCustomValue, a
    separate table, not a column here — $d['selling_lines'][$key]/
    $d['operating_lines'][$key] (already merged in by the controller,
    see its own withCustomRowValues()) is the correct source for BOTH
    built-in and custom rows' current value, used for every editable
    input's seed value now instead of $entry->{$key} directly.

    $locked (explicit request, 2026-10-02: "can you create lock icon too
    in this, like in the projections" — same "when it is lock it can't
    edit" behavior Projections' own Opening/Closing Shift cards already
    have): only meaningful alongside $editable = true (the overall
    rollup card is already always read-only regardless) — every real
    <input> below gets the `disabled` attribute plus a locked visual
    style instead of being swapped for a plain span, so the field stays
    genuinely non-editable (can't focus or type into it) without this
    partial needing a third render mode on top of editable/read-only.
--}}
@php($lockedInputClass = ($locked ?? false) ? ' opacity-60 cursor-not-allowed' : '')
<div class="px-5 py-4 font-mono text-[13px] space-y-2 border-b border-line dark:border-slate-700">
    @if($editable ?? false)
        <div class="flex items-center justify-end -mt-1 -mb-1">
            <span class="ei-card-status text-[11px] font-mono text-ink-muted dark:text-slate-400 min-h-[1.25rem]"></span>
        </div>
    @endif

    @foreach([
        ['key' => 'roas', 'label' => 'ROAS', 'money' => true],
        ['key' => 'standard_cost_per_message', 'label' => 'Standard Cost Per Message', 'money' => true],
        ['key' => 'actual_cost_per_lead', 'label' => 'Actual Cost Per Message', 'money' => true],
    ] as $col)
    <div class="grid grid-cols-[1fr_auto] gap-x-3 items-center">
        <span class="text-ink-muted dark:text-slate-400">{{ $col['label'] }}</span>
        @if($editable ?? false)
            <input type="text" inputmode="{{ ($col['int'] ?? false) ? 'numeric' : 'decimal' }}"
                   value="{{ ($col['money'] ?? false) ? number_format($entry?->{$col['key']} ?? 0, 2) : ($entry?->{$col['key']} ?? 0) }}"
                   data-field="{{ $col['key'] }}" @if($col['money'] ?? false) data-money="1" @endif @if($locked ?? false) disabled @endif
                   class="ei-field w-28 text-right bg-slate-50 dark:bg-slate-800 border border-line dark:border-slate-700 rounded-md px-1.5 py-0.5 font-semibold text-ink dark:text-slate-100 focus:ring-2 focus:ring-primary/40 focus:border-primary outline-none{{ $lockedInputClass }}">
        @else
            <span data-out="{{ $col['key'] }}" class="font-semibold text-ink dark:text-slate-100 text-right">{{ ($col['money'] ?? false) ? number_format($d[$col['key']], 2) : number_format($d[$col['key']]) }}</span>
        @endif
    </div>
    @endforeach

    {{-- Number of Leads is no longer typed in — automated 2026-10-09 to
         tally the real Lead table count for this TSA/product/day (see
         ExpectedIncomeController::leadCountsByProductAndDate()'s own doc
         comment), same always-read-only span every other derived field
         (Conversion Rate, Returns, Delivered...) already uses on this
         card, editable or not. --}}
    <div class="grid grid-cols-[1fr_auto] gap-x-3 items-center">
        <span class="text-ink-muted dark:text-slate-400">Number of Leads</span>
        <span data-out="number_of_leads" class="font-semibold text-ink dark:text-slate-100 text-right">{{ number_format($d['number_of_leads']) }}</span>
    </div>

    <div class="grid grid-cols-[1fr_auto] gap-x-3 items-center">
        <span class="text-ink-muted dark:text-slate-400">Conversion Rate</span>
        <span data-out="conversion_rate" class="font-semibold text-ink dark:text-slate-100 text-right">{{ $fmtPct($d['conversion_rate']) }}</span>
    </div>

    {{-- Number of Orders is no longer typed in on a real product card
         specifically — automated 2026-10-10 ("the number of orders is the
         upsell") to the same real matched-upsell-order COUNT Gross Sales'
         own dollar sum uses (ExpectedIncomeController::
         grossSalesByProductAndDate()'s own 'numberOfOrders' map — same
         isBroadRealUpsell-filtered matched set, EXACTLY
         ProductPerformance::tally()'s own upsell_confirmation formula).
         Gated on $tsaScoped, NOT just $editable, same reasoning as Gross
         Sales directly above — TikTok's own 2 fixed cards stay fully
         manual (no real Product/Pancake assignee data behind either). --}}
    <div class="grid grid-cols-[1fr_auto] gap-x-3 items-center">
        <span class="text-ink-muted dark:text-slate-400">Number of Orders</span>
        @if(($editable ?? false) && !($tsaScoped ?? false))
            <input type="text" inputmode="numeric" value="{{ $entry?->number_of_orders ?? 0 }}"
                   data-field="number_of_orders" @if($locked ?? false) disabled @endif
                   class="ei-field w-28 text-right bg-slate-50 dark:bg-slate-800 border border-line dark:border-slate-700 rounded-md px-1.5 py-0.5 font-semibold text-ink dark:text-slate-100 focus:ring-2 focus:ring-primary/40 focus:border-primary outline-none{{ $lockedInputClass }}">
        @else
            <span data-out="number_of_orders" class="font-semibold text-ink dark:text-slate-100 text-right">{{ number_format($d['number_of_orders']) }}</span>
        @endif
    </div>
    <div class="grid grid-cols-[1fr_auto] gap-x-3 items-center">
        <span class="text-ink-muted dark:text-slate-400">Average Order Value</span>
        @if($editable ?? false)
            <input type="text" inputmode="decimal" value="{{ number_format($entry?->average_order_value ?? 0, 2) }}"
                   data-field="average_order_value" data-money="1" @if($locked ?? false) disabled @endif
                   class="ei-field w-28 text-right bg-slate-50 dark:bg-slate-800 border border-line dark:border-slate-700 rounded-md px-1.5 py-0.5 font-semibold text-ink dark:text-slate-100 focus:ring-2 focus:ring-primary/40 focus:border-primary outline-none{{ $lockedInputClass }}">
        @else
            <span data-out="average_order_value" class="font-semibold text-ink dark:text-slate-100 text-right">{{ $fmtMoney($d['average_order_value']) }}</span>
        @endif
    </div>
</div>

<div class="px-5 py-4 font-mono text-[13px] space-y-0.5">
    {{-- Gross Sales is NO LONGER a manual input on a real product card
         specifically (explicit request, 2026-10-10: "in the expected
         income the gross sales is make it automated too") — same real
         per-item Pancake assignee revenue basis Summary Sales Report's
         own Gross Sales already uses
         (ExpectedIncomeController::grossSalesByProductAndDate()), scoped
         per TSA per PRODUCT per day here. Reversed from the 2026-09-28
         "make all manually input" decision below, same "automate it,
         don't keep two independently-typed versions of the same real
         number" reasoning every other field on this page has already
         gone through (Number of Leads, 2026-10-09; Net Income's own
         Summary Sales Report consumer, 2026-10-10). Gated on $tsaScoped,
         NOT just $editable — $editable alone also covers TikTok's own 2
         fixed cards (_tiktok-card.blade.php, always tsaScoped=false, no
         real Product/Pancake assignee data behind either card, STILL
         fully manual by design), so $tsaScoped is what uniquely
         identifies "a real per-TSA product card" (_product-card.blade.php
         is the only caller passing editable=true AND tsaScoped=true —
         confirmed live, 2026-10-10: a first version of this fix gated on
         $editable alone and silently removed TikTok's own Gross Sales
         input too). Cancelled stays manual everywhere — no real
         per-cancellation attribution signal exists anywhere in this app
         the way upsell revenue does. Projected Returns/Projected
         Delivered: DERIVED from Gross Sales (explicit correction,
         2026-10-01: "the only auto is Projected Returns / Projected
         Delivered" — confirmed against the real sheet's own formulas,
         Returns = Gross Sales × 25%, Delivered = Gross Sales − Cancelled
         − Returns), so no input for either even on an otherwise-editable
         card — see ExpectedIncomeCalculator's own doc comment for the
         confirmed-exact rate. --}}
    <div class="grid grid-cols-[1fr_6.5rem_3.5rem] gap-x-2 items-center py-1">
        <span class="text-ink-muted dark:text-slate-400">Gross Sales</span>
        @if(($editable ?? false) && !($tsaScoped ?? false))
            <input type="text" inputmode="decimal" value="{{ number_format($entry?->gross_sales ?? 0, 2) }}"
                   data-field="gross_sales" data-money="1" @if($locked ?? false) disabled @endif
                   class="ei-field w-full text-right bg-slate-50 dark:bg-slate-800 border border-line dark:border-slate-700 rounded-md px-1.5 py-0.5 font-semibold text-ink dark:text-slate-100 focus:ring-2 focus:ring-primary/40 focus:border-primary outline-none{{ $lockedInputClass }}">
        @else
            <span data-out="gross_sales" class="text-right font-semibold text-ink dark:text-slate-100">{{ $fmtMoney($d['gross_sales']) }}</span>
        @endif
        <span data-out="gross_sales_pct" class="text-right text-ink-muted dark:text-slate-500 text-xs">{{ $fmtPct($d['gross_sales_pct']) }}</span>
    </div>
    <div class="grid grid-cols-[1fr_6.5rem_3.5rem] gap-x-2 items-center py-1">
        <span class="text-red-600 dark:text-red-400">Cancelled</span>
        @if($editable ?? false)
            <input type="text" inputmode="decimal" value="{{ number_format($entry?->cancelled ?? 0, 2) }}"
                   data-field="cancelled" data-money="1" @if($locked ?? false) disabled @endif
                   class="ei-field w-full text-right bg-slate-50 dark:bg-slate-800 border border-line dark:border-slate-700 rounded-md px-1.5 py-0.5 font-semibold text-red-600 dark:text-red-400 focus:ring-2 focus:ring-primary/40 focus:border-primary outline-none{{ $lockedInputClass }}">
        @else
            <span data-out="cancelled" class="text-right font-semibold text-red-600 dark:text-red-400">{{ $fmtMoney($d['cancelled']) }}</span>
        @endif
        <span data-out="cancelled_pct" class="text-right text-ink-muted dark:text-slate-500 text-xs">{{ $fmtPct($d['cancelled_pct']) }}</span>
    </div>

    @foreach([
        ['key' => 'returns', 'label' => 'Projected Returns'],
        ['key' => 'delivered', 'label' => 'Projected Delivered'],
    ] as $col)
    <div class="grid grid-cols-[1fr_6.5rem_3.5rem] gap-x-2 items-center py-1">
        <span class="text-red-600 dark:text-red-400">{{ $col['label'] }}</span>
        <span data-out="{{ $col['key'] }}" class="text-right font-semibold text-red-600 dark:text-red-400">{{ $fmtMoney($d[$col['key']]) }}</span>
        <span data-out="{{ $col['key'] }}_pct" class="text-right text-ink-muted dark:text-slate-500 text-xs">{{ $fmtPct($d[$col['key'] . '_pct']) }}</span>
    </div>
    @endforeach

    @foreach([
        ['key' => 'tax_allocation', 'label' => 'Tax Allocation', 'blue' => true],
        ['key' => 'product_cost', 'label' => 'Product Cost'],
    ] as $col)
    {{-- Tax Allocation on a TSA-scoped card is a computed formula from
         Cost Breakdown, never editable (explicit request, 2026-10-02: "the
         tax allocation in tsa cards is should be not editable") — same
         "computed, never editable" lock Salaries/the 20 shared pools
         already have, via $tsaScoped (the ALL view's own tsa_id-NULL cards
         have no TSA to compute anything from, so Tax Allocation stays a
         plain manual input there, same as Product Cost always is). --}}
    @php($isTaxAllocationLocked = $col['key'] === 'tax_allocation' && ($tsaScoped ?? false))
    <div class="grid grid-cols-[1fr_6.5rem_3.5rem] gap-x-2 items-center py-1">
        <span class="{{ ($col['blue'] ?? false) ? 'text-blue-600 dark:text-blue-400' : 'text-ink-muted dark:text-slate-400' }}">{{ $col['label'] }}</span>
        @if(($editable ?? false) && !$isTaxAllocationLocked)
            <input type="text" inputmode="decimal" value="{{ number_format($entry?->{$col['key']} ?? 0, 2) }}"
                   data-field="{{ $col['key'] }}" data-money="1" @if($locked ?? false) disabled @endif
                   class="ei-field w-full text-right bg-slate-50 dark:bg-slate-800 border border-line dark:border-slate-700 rounded-md px-1.5 py-0.5 text-xs font-semibold {{ ($col['blue'] ?? false) ? 'text-blue-600 dark:text-blue-400' : 'text-ink dark:text-slate-100' }} focus:ring-2 focus:ring-primary/40 focus:border-primary outline-none{{ $lockedInputClass }}">
        @else
            <span data-out="{{ $col['key'] }}" class="text-right {{ ($col['blue'] ?? false) ? 'text-blue-600 dark:text-blue-400' : 'text-ink dark:text-slate-100' }}">{{ $fmtMoney($d[$col['key']]) }}</span>
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
    <div data-row-dropzone="selling">
    @foreach($sellingRows as $key => $label)
    @php($isCustom = in_array($key, $customRowKeys ?? [], true))
    @php($isNonEditable = in_array($key, \App\Support\ExpectedIncomeCalculator::nonEditableRows(), true))
    {{-- Row order shared with Projections (RowOrder::rows()) — explicit
         decision, 2026-10-02: dragging stays Projections-only ("why is it
         even expected income has drag to change position? it is only in
         the projection"), so NOT draggable=true / no handle icon here,
         even though data-row-key/data-row-section are kept so a drag on
         Projections still correctly reorders this same element here too.
         cod_fee/fulfillment_fee (now inside this same loop, see
         SELLING_COST_ROWS' own doc comment) share the same order, just
         never editable — their own VALUE is still always the computed
         formula, $isNonEditable only affects whether it renders an
         <input> or a <span>. --}}
    <div class="grid grid-cols-[1fr_6.5rem_3.5rem] gap-x-2 items-center py-1 ei-row" data-row-key="{{ $key }}" data-row-section="selling">
        <span class="text-ink-muted dark:text-slate-400 flex items-center gap-1">
            {{ $label }}
        </span>
        @if(($editable ?? false) && !$isNonEditable)
            <input type="text" inputmode="decimal" value="{{ number_format($d['selling_lines'][$key] ?? 0, 2) }}"
                   data-field="{{ $key }}" data-money="1" @if($isCustom) data-custom="1" @endif @if($locked ?? false) disabled @endif
                   class="ei-field w-full text-right bg-slate-50 dark:bg-slate-800 border border-line dark:border-slate-700 rounded-md px-1.5 py-0.5 text-xs font-semibold text-ink dark:text-slate-100 focus:ring-2 focus:ring-primary/40 focus:border-primary outline-none{{ $lockedInputClass }}">
        @else
            <span data-out="{{ $key }}" class="text-right text-ink dark:text-slate-100">{{ $fmtMoney($d['selling_lines'][$key] ?? 0) }}</span>
        @endif
        <span data-out-pct="{{ $key }}" class="text-right text-ink-muted dark:text-slate-500 text-xs">{{ $d['gross_sales'] > 0 ? $fmtPct(($d['selling_lines'][$key] ?? 0) / $d['gross_sales']) : '0.00%' }}</span>
    </div>
    @endforeach
    </div>
    <div class="grid grid-cols-[1fr_auto_4.5rem] gap-x-2 items-center py-1.5 border-t border-line dark:border-slate-700 font-bold">
        <span class="text-ink dark:text-slate-100">Total Selling Costs</span>
        <span data-out="total_selling_costs" class="text-right text-ink dark:text-slate-100">{{ $fmtMoney($d['total_selling_costs']) }}</span>
        <span data-out="total_selling_costs_pct" class="text-right text-ink-muted dark:text-slate-400 text-xs font-normal">{{ $fmtPct($d['total_selling_costs_pct']) }}</span>
    </div>

    {{-- Income Before OPEX — explicit request, 2026-09-28 (real template
         screenshot): Gross Profit minus Selling & Marketing costs only,
         before Operating Costs are subtracted. --}}
    <div class="grid grid-cols-[1fr_auto_4.5rem] gap-x-2 items-center py-1.5 font-bold">
        <span class="text-ink dark:text-slate-100">Income Before OPEX</span>
        <span data-out="income_before_opex" class="text-right {{ $d['income_before_opex'] < 0 ? 'text-red-600 dark:text-red-400' : 'text-ink dark:text-slate-100' }}">{{ $fmtMoney($d['income_before_opex']) }}</span>
        <span data-out="income_before_opex_pct" class="text-right text-ink-muted dark:text-slate-400 text-xs font-normal">{{ $fmtPct($d['income_before_opex_pct']) }}</span>
    </div>

    <div class="pt-3 pb-1 font-bold text-ink dark:text-slate-100">Operating Costs</div>
    <div data-row-dropzone="operating">
    @foreach($operatingRows as $key => $label)
    @php($isCustom = in_array($key, $customRowKeys ?? [], true))
    @php($isBlue = in_array($key, ['geniusmakers_management_fee', 'hmo_expense'], true))
    {{-- Every BUILT-IN Operating Costs row (Salaries + every shared pool —
         explicit request, 2026-09-30: "the salaries row is based to the
         Daily Rate / Product", then "the daily cost is it is this
         [Communication Allowance, 13th Month Allowance, SIL, ...]") on a
         TSA-scoped card is a computed formula from Cost Breakdown, never
         editable — same "computed, never editable" pattern as COD Fee/
         Fulfillment Fee above. A CUSTOM row (added via Projections' + icon,
         $isCustom) has no Cost Breakdown source to lock to at all, so it
         stays a plain manual input even on a TSA-scoped card. Only when
         $tsaScoped is true (the ALL view's own tsa_id-NULL cards have no
         TSA to compute anything from, so every row there is unaffected,
         still a plain manual input). --}}
    {{-- A TikTok card (tsaScoped = false, lockedOperatingKeys = ['salaries']
         — see _tiktok-card.blade.php's own doc comment, explicit request
         2026-10-07) locks ONLY Salaries, not the other 19 pools — unlike a
         real TSA-scoped product card, which has no Cost Breakdown
         automation for Communication Allowance/SIL/etc. on a TikTok card
         at all, so those stay plain manual inputs there. $lockedOperatingKeys
         defaults to null (not passed), meaning "every key, same as the
         existing tsaScoped-wide rule" — only a TikTok card passes an
         explicit narrower list. --}}
    @php($isOperatingCostLocked = !$isCustom && (isset($lockedOperatingKeys) ? in_array($key, $lockedOperatingKeys, true) : ($tsaScoped ?? false)))
    <div class="grid grid-cols-[1fr_6.5rem_3.5rem] gap-x-2 items-center py-1 ei-row" data-row-key="{{ $key }}" data-row-section="operating">
        <span class="{{ $isBlue ? 'text-blue-600 dark:text-blue-400' : 'text-ink-muted dark:text-slate-400' }} flex items-center gap-1">
            {{ $label }}
        </span>
        @if(($editable ?? false) && !$isOperatingCostLocked)
            <input type="text" inputmode="decimal" value="{{ number_format($d['operating_lines'][$key] ?? 0, 2) }}"
                   data-field="{{ $key }}" data-money="1" @if($isCustom) data-custom="1" @endif @if($locked ?? false) disabled @endif
                   class="ei-field w-full text-right bg-slate-50 dark:bg-slate-800 border border-line dark:border-slate-700 rounded-md px-1.5 py-0.5 text-xs font-semibold {{ $isBlue ? 'text-blue-600 dark:text-blue-400' : 'text-ink dark:text-slate-100' }} focus:ring-2 focus:ring-primary/40 focus:border-primary outline-none{{ $lockedInputClass }}">
        @else
            <span data-out="{{ $key }}" class="text-right {{ $isBlue ? 'text-blue-600 dark:text-blue-400' : 'text-ink dark:text-slate-100' }}">{{ $fmtMoney($d['operating_lines'][$key] ?? 0) }}</span>
        @endif
        <span data-out-pct="{{ $key }}" class="text-right text-ink-muted dark:text-slate-500 text-xs">{{ $d['gross_sales'] > 0 ? $fmtPct(($d['operating_lines'][$key] ?? 0) / $d['gross_sales']) : '0.00%' }}</span>
    </div>
    @endforeach
    </div>
    <div class="grid grid-cols-[1fr_auto_4.5rem] gap-x-2 items-center py-1.5 border-t border-line dark:border-slate-700 font-bold">
        <span class="text-ink dark:text-slate-100">Total Operating Costs</span>
        <span data-out="total_operating_costs" class="text-right text-ink dark:text-slate-100">{{ $fmtMoney($d['total_operating_costs']) }}</span>
        <span data-out="total_operating_costs_pct" class="text-right text-ink-muted dark:text-slate-400 text-xs font-normal">{{ $fmtPct($d['total_operating_costs_pct']) }}</span>
    </div>

    <div class="grid grid-cols-[1fr_auto_4.5rem] gap-x-2 items-center py-2 mt-2 border-t-2 border-ink/20 dark:border-slate-600">
        <span class="font-bold text-ink dark:text-slate-100">NET INCOME</span>
        <span data-out="net_income" class="text-right font-bold text-base {{ $d['net_income'] < 0 ? 'text-red-600 dark:text-red-400' : 'text-green-600 dark:text-green-400' }}">{{ $fmtMoney($d['net_income']) }}</span>
        <span data-out="net_income_pct" class="text-right text-ink-muted dark:text-slate-400 text-xs">{{ $fmtPct($d['net_income_pct']) }}</span>
    </div>
</div>
