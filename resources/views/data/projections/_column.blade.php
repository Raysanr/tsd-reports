@php
    /** @var array $entry — one ProjectionCalculator::forColumn() result. */
    $column = $entry['column'];
    $t = $entry['target_card'];
    $p = $entry['pnl'];

    // Only Opening Shift's AND Closing Shift's own P&L rows are real
    // inputs in the source sheet — every Individual TSA Monthly/Daily
    // card is a pure =F39/6-style FORMULA copied from its own shift, not
    // an independent cell (root-caused 2026-09-23 from the user's own
    // sheet screenshot; explicit decision after that finding: derived
    // cards' P&L rows display read-only, only the 2 base shifts' stay
    // editable). Telesales Department is ALSO derived now (= Opening +
    // Closing, explicit decision 2026-09-23 once Closing became
    // independently editable) so it's read-only too. The target card
    // below (Net Income Target/AOV/TSA Count) is a SEPARATE concern and
    // stays editable on every column regardless — see
    // ProjectionCalculator's own doc comment for the full chain.
    $editable = in_array($column->key, ['opening_shift', 'closing_shift'], true);

    $headerColors = [
        'telesales_department'            => ['bg' => '#f1f1f1', 'text' => '#111827'],
        'opening_shift'                   => ['bg' => '#c9daf8', 'text' => '#111827'],
        'opening_individual_tsa_monthly'  => ['bg' => '#111827', 'text' => '#ffffff'],
        'opening_individual_tsa_daily'    => ['bg' => '#111827', 'text' => '#ffffff'],
        'closing_shift'                   => ['bg' => '#d9ead3', 'text' => '#111827'],
        'closing_individual_tsa_monthly'  => ['bg' => '#274e13', 'text' => '#ffffff'],
        'closing_individual_tsa_daily'    => ['bg' => '#274e13', 'text' => '#ffffff'],
    ];
    $hc = $headerColors[$column->key] ?? ['bg' => '#f1f1f1', 'text' => '#111827'];
@endphp

<div class="pj-card bg-white dark:bg-slate-900 border border-line dark:border-slate-700 rounded-2xl shadow-panel overflow-hidden"
     data-key="{{ $column->key }}"
     data-action="{{ route('data.projections.update-column', $column) }}">

    <div class="px-5 py-4 flex items-center gap-2" style="background:{{ $hc['bg'] }};">
        <input type="text" value="{{ $column->label }}" data-field="label"
               class="pj-field flex-1 min-w-0 bg-transparent border-none focus:ring-2 focus:ring-primary/40 rounded-md px-1.5 py-1 -mx-1.5 font-mono font-bold text-sm uppercase tracking-wide truncate"
               style="color:{{ $hc['text'] }};">
        <span class="pj-card-status text-[11px] font-mono shrink-0 min-w-[3.5rem] text-right" style="color:{{ $hc['text'] }};opacity:.7;"></span>
    </div>

    {{-- # of Orders / Gross Sales — editable ONLY on Opening Shift
         (explicit request, 2026-09-23: "the Gross Sales is editable and
         the number of orders", scoped down after the cross-card formula
         finding to just the one truly-independent column). They're two
         views of the SAME underlying number (Gross Sales = Orders × AOV):
         # of Orders saves orders_override directly via updateColumn (a
         pj-field, like Net Income Target/AOV below); Gross Sales is
         back-solved to Orders (÷ AOV) client-side first, then saved
         through the SAME endpoint — NOT the shared-rate endpoint, since
         orders_override is per-column, not a cross-column rate. On the 3
         derived cards these are plain read-only spans that
         applyComputed() keeps in sync, same as every other total on the
         page. See pj.js's own saveColumnField(). --}}
    <div class="px-5 py-3 font-mono text-[13px]">
        <div class="grid grid-cols-[1fr_auto] gap-x-3 items-center py-1.5 border-b border-line dark:border-slate-700">
            <span class="text-ink-muted dark:text-slate-400"># of Orders</span>
            @if($editable)
                <input type="text" inputmode="decimal" value="{{ number_format($p['orders'], 2) }}" data-field="orders_override" data-money="1" data-orders="1"
                       class="pj-field w-24 text-right bg-slate-50 dark:bg-slate-800 border border-line dark:border-slate-700 rounded-md px-1.5 py-0.5 font-semibold text-ink dark:text-slate-100 focus:ring-2 focus:ring-primary/40 focus:border-primary outline-none">
            @else
                <span data-orders="1" class="font-semibold text-ink dark:text-slate-100 text-right">{{ number_format($p['orders'], 2) }}</span>
            @endif
        </div>
    </div>

    <div class="px-5 pb-3 font-mono text-[13px] space-y-0.5">
        <div class="grid grid-cols-[1fr_6.5rem_3.5rem] gap-x-2 items-center py-1">
            <span class="text-ink-muted dark:text-slate-400">Gross Sales</span>
            @if($editable)
                <input type="text" inputmode="decimal" value="{{ number_format($p['gross_sales'], 2) }}" data-field="orders_override" data-money="1" data-gross-sales="1"
                       class="pj-field w-full text-right bg-slate-50 dark:bg-slate-800 border border-line dark:border-slate-700 rounded-md px-1.5 py-0.5 font-semibold text-ink dark:text-slate-100 focus:ring-2 focus:ring-primary/40 focus:border-primary outline-none">
            @else
                <span data-gross-sales="1" class="font-semibold text-ink dark:text-slate-100 text-right">{{ number_format($p['gross_sales'], 2) }}</span>
            @endif
            <span class="text-right text-ink-muted dark:text-slate-500 text-xs">100.00%</span>
        </div>

        @include('data.projections._pnl-row', ['label' => 'Cancelled & Waiting', 'pnlKey' => 'cancelled', 'rateKey' => 'cancelled', 'value' => $p['cancelled'], 'ratePct' => $rates['cancelled'] * 100, 'editable' => false])
        @include('data.projections._pnl-row', ['label' => 'Returns', 'pnlKey' => 'returns', 'rateKey' => 'returns', 'value' => $p['returns'], 'ratePct' => $rates['returns'] * 100, 'editable' => false])
        @include('data.projections._pnl-row', ['label' => 'Delivered', 'pnlKey' => 'delivered', 'rateKey' => 'delivered', 'value' => $p['delivered'], 'ratePct' => $rates['delivered'] * 100, 'editable' => false])
        @include('data.projections._pnl-row', ['label' => 'Tax Allocation', 'pnlKey' => 'tax_allocation', 'rateKey' => 'tax_allocation', 'value' => $p['tax_allocation'], 'ratePct' => $rates['tax_allocation'] * 100, 'editable' => $editable])
        @include('data.projections._pnl-row', ['label' => 'Product Cost', 'pnlKey' => 'product_cost', 'rateKey' => 'product_cost', 'value' => $p['product_cost'], 'ratePct' => $rates['product_cost'] * 100, 'editable' => $editable])

        <div class="grid grid-cols-[1fr_auto_4.5rem] gap-x-2 items-center py-1.5 border-y border-ink/10 dark:border-slate-600 font-bold">
            <span class="text-ink dark:text-slate-100">Gross Profit</span>
            <span data-pnl="gross_profit" class="text-right text-ink dark:text-slate-100">{{ number_format($p['gross_profit'], 2) }}</span>
            <span data-pnl="gross_profit_pct" class="text-right text-ink-muted dark:text-slate-400 text-xs font-normal">{{ number_format($p['gross_profit_pct'] * 100, 2) }}%</span>
        </div>

        @php
            // customRowsByKey: every user-added row keyed by its own `key`
            // column, so a row loop below can tell whether a given key is
            // custom (renders the small × control) vs. built-in — same
            // "the shared list is the source of truth" convention as
            // ProjectionCalculator::sellingCostRows()/operatingCostRows()
            // itself, which is where $p['selling_lines']/$rates already
            // got these keys folded in.
            $customRowsByKey = \App\Models\ProjectionCustomRow::all()->keyBy('key');
            // + icon lives ONLY on Opening/Closing Shift (explicit
            // decision, 2026-09-26: "Opening Shift + Closing Shift only")
            // — every other card just displays whatever rows exist,
            // same read-only rule the built-in rows already follow there.
            $canAddCustomRow = $editable;
        @endphp

        <div class="pt-3 pb-1 flex items-center justify-between">
            <span class="font-bold text-ink dark:text-slate-100">Selling And Marketing</span>
            @if($canAddCustomRow)
                <button type="button" data-add-custom-row="selling" title="Add a row"
                        class="pj-add-row shrink-0 w-4 h-4 inline-flex items-center justify-center rounded-full text-xs leading-none font-bold text-ink-muted/70 border border-ink-muted/40 hover:text-primary hover:border-primary dark:text-slate-400 dark:border-slate-600">
                    +
                </button>
            @endif
        </div>
        @foreach(\App\Support\ProjectionCalculator::sellingCostRows() as $key => $rowLabel)
            {{-- COD Fee/Fulfillment Fee (and any custom row marked "fixed")
                 are never editable, even on Opening Shift — confirmed via
                 the real sheet's own formula-view (2026-09-24) that both
                 are computed formulas (Delivered × 2.24%, Orders × ₱25
                 flat), not settable %-of-Gross-Sales rates like every
                 other row in this loop. Their own $ratePct is computed
                 live (value ÷ this column's own Gross Sales) instead of
                 read from $rates — that array no longer carries either key
                 at all now that neither is a real settable rate
                 (regression fixed 2026-09-24: every card was showing a
                 flat 0.00% for both rows because
                 $rates['cod_fee']/['fulfillment_fee'] no longer exist). --}}
            @php
                $rowValue = $p['selling_lines'][$key] ?? 0;
                $isNonEditable = in_array($key, \App\Support\ProjectionCalculator::nonEditableRows(), true);
                $rowRatePct = $isNonEditable
                    ? ($p['gross_sales'] > 0 ? $rowValue / $p['gross_sales'] * 100 : 0)
                    : ($rates[$key] ?? 0) * 100;
            @endphp
            @include('data.projections._pnl-row', ['label' => $rowLabel, 'pnlKey' => null, 'lineKey' => $key, 'rateKey' => $key, 'value' => $rowValue, 'ratePct' => $rowRatePct, 'editable' => $editable && !$isNonEditable, 'customRowId' => $customRowsByKey->get($key)?->id])
        @endforeach
        <div class="grid grid-cols-[1fr_auto_4.5rem] gap-x-2 items-center py-1.5 border-t border-line dark:border-slate-700 font-bold">
            <span class="text-ink dark:text-slate-100">Total Selling Costs</span>
            <span data-pnl="total_selling_costs" class="text-right text-ink dark:text-slate-100">{{ number_format($p['total_selling_costs'], 2) }}</span>
            <span data-pnl="total_selling_costs_pct" class="text-right text-ink-muted dark:text-slate-400 text-xs font-normal">{{ number_format($p['total_selling_costs_pct'] * 100, 2) }}%</span>
        </div>

        <div class="pt-3 pb-1 flex items-center justify-between">
            <span class="font-bold text-ink dark:text-slate-100">Operating Costs</span>
            @if($canAddCustomRow)
                <button type="button" data-add-custom-row="operating" title="Add a row"
                        class="pj-add-row shrink-0 w-4 h-4 inline-flex items-center justify-center rounded-full text-xs leading-none font-bold text-ink-muted/70 border border-ink-muted/40 hover:text-primary hover:border-primary dark:text-slate-400 dark:border-slate-600">
                    +
                </button>
            @endif
        </div>
        @foreach(\App\Support\ProjectionCalculator::operatingCostRows() as $key => $rowLabel)
            @php $isCustomFixed = $customRowsByKey->get($key)?->is_fixed ?? false; @endphp
            @include('data.projections._pnl-row', ['label' => $rowLabel, 'pnlKey' => null, 'lineKey' => $key, 'rateKey' => $key, 'value' => $p['operating_lines'][$key] ?? 0, 'ratePct' => ($rates[$key] ?? 0) * 100, 'editable' => $editable && !$isCustomFixed, 'customRowId' => $customRowsByKey->get($key)?->id])
        @endforeach
        <div class="grid grid-cols-[1fr_auto_4.5rem] gap-x-2 items-center py-1.5 border-t border-line dark:border-slate-700 font-bold">
            <span class="text-ink dark:text-slate-100">Total Operating Costs</span>
            <span data-pnl="total_operating_costs" class="text-right text-ink dark:text-slate-100">{{ number_format($p['total_operating_costs'], 2) }}</span>
            <span data-pnl="total_operating_costs_pct" class="text-right text-ink-muted dark:text-slate-400 text-xs font-normal">{{ number_format($p['total_operating_costs_pct'] * 100, 2) }}%</span>
        </div>

        <div class="grid grid-cols-[1fr_auto_4.5rem] gap-x-2 items-center py-2 mt-2 border-t-2 border-ink/20 dark:border-slate-600">
            <span class="font-bold text-ink dark:text-slate-100">NET INCOME</span>
            <span data-pnl="net_income" class="text-right font-bold text-base text-primary">{{ number_format($p['net_income'], 2) }}</span>
            <span data-pnl="net_income_pct" class="text-right text-ink-muted dark:text-slate-400 text-xs">{{ number_format($p['net_income_pct'] * 100, 2) }}%</span>
        </div>
    </div>

    {{-- Target card — the inputs that DRIVE the whole column (net income
         target, AOV, TSA count) plus the computed outputs the source
         sheet's own card shows below every column (explicit request,
         2026-09-23: "for example this in opening, all of the card rows
         should be in the projections too"). --}}
    <div class="px-5 py-4 border-t border-line dark:border-slate-700 bg-slate-50 dark:bg-slate-800/60 font-mono text-[13px] space-y-2">
        <div class="grid grid-cols-[1fr_auto] gap-x-3 items-center">
            <span class="text-ink-muted dark:text-slate-400">Current TSAs</span>
            <input type="number" step="1" min="1" value="{{ $t['tsa_count'] }}" data-field="tsa_count"
                   class="pj-field w-24 text-right bg-white dark:bg-slate-900 border border-line dark:border-slate-700 rounded-md px-2 py-1 text-ink dark:text-slate-100 focus:ring-2 focus:ring-primary/40 focus:border-primary outline-none">
        </div>
        <div class="grid grid-cols-[1fr_auto] gap-x-3 items-center">
            <span class="text-ink-muted dark:text-slate-400">Net Income Target</span>
            <input type="text" inputmode="decimal" value="{{ number_format($t['net_income_target'], 2) }}" data-field="net_income_target" data-money="1"
                   class="pj-field w-32 text-right bg-white dark:bg-slate-900 border border-line dark:border-slate-700 rounded-md px-2 py-1 text-ink dark:text-slate-100 focus:ring-2 focus:ring-primary/40 focus:border-primary outline-none">
        </div>
        <div class="grid grid-cols-[1fr_auto] gap-x-3 items-center">
            <span class="text-ink-muted dark:text-slate-400">Average Order Value</span>
            <input type="text" inputmode="decimal" value="{{ number_format($t['average_order_value'], 2) }}" data-field="average_order_value" data-money="1"
                   class="pj-field w-32 text-right bg-white dark:bg-slate-900 border border-line dark:border-slate-700 rounded-md px-2 py-1 text-ink dark:text-slate-100 focus:ring-2 focus:ring-primary/40 focus:border-primary outline-none">
        </div>
        {{-- Total Orders Needed / Total Leads Needed are plain read-only
             numbers now (explicit request, 2026-09-24: "not editable and
             no percentage too") — their own underlying target_margin/
             conversion_rate rates no longer have a UI control on this
             page at all; both still exist as real Settings values
             (DEFAULT_RATES' own 31.25%/30%), just not editable from here
             anymore. --}}
        <div class="grid grid-cols-[1fr_auto] gap-x-3 items-center pt-1 border-t border-line/60 dark:border-slate-700">
            <span class="text-ink-muted dark:text-slate-400">Total Orders Needed</span>
            <span data-out="orders_needed" class="text-right font-bold text-primary">{{ number_format($t['orders_needed'], $t['orders_needed'] < 100 ? 2 : 0) }}</span>
        </div>
        <div class="grid grid-cols-[1fr_auto] gap-x-3 items-center">
            <span class="text-ink-muted dark:text-slate-400">Total Leads Needed</span>
            <span data-out="leads_needed" class="text-right font-semibold text-ink dark:text-slate-100">{{ number_format($t['leads_needed']) }}</span>
        </div>
        {{-- Target Pick-up Rate: the % input box is gone (explicit request,
             2026-09-24) — the displayed value itself (Leads Needed × Pick-up
             Rate %) is now the one editable field. Typing a number here
             back-solves it into the shared pickup_rate fraction against
             THIS column's own Leads Needed (value ÷ leads_needed), the same
             back-solve convention the P&L rows' data-mode="dollar" fields
             already use against Gross Sales — see pj.js's own saveRate()
             data-mode="leads" branch. --}}
        <div class="grid grid-cols-[1fr_auto] gap-x-3 items-center">
            <span class="text-ink-muted dark:text-slate-400">Target Pick-up Rate</span>
            <input type="text" inputmode="decimal" value="{{ number_format($t['pickup_rate']) }}" data-rate="pickup_rate" data-mode="leads"
                   class="pj-rate-field w-32 text-right bg-white dark:bg-slate-900 border border-line dark:border-slate-700 rounded-md px-2 py-1 font-semibold text-ink dark:text-slate-100 focus:ring-2 focus:ring-primary/40 focus:border-primary outline-none">
        </div>
        <div class="grid grid-cols-[1fr_auto] gap-x-3 items-center">
            <span class="text-ink-muted dark:text-slate-400">Target Upselling Rate</span>
            <input type="text" inputmode="decimal" value="{{ number_format($t['upselling_rate'], 2) }}" data-field="upselling_rate_override" data-money="1"
                   class="pj-field w-32 text-right bg-white dark:bg-slate-900 border border-line dark:border-slate-700 rounded-md px-2 py-1 font-bold text-primary focus:ring-2 focus:ring-primary/40 focus:border-primary outline-none">
        </div>
    </div>
</div>
