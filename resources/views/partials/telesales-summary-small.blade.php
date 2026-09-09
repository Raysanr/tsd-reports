{{-- One "small" prior-day column — date is independently editable (data-tss-
     date-input) via a native <input type="date">, not fixed to whatever
     DashboardController::index() computed on page load. Changing it fires
     tssLoadSmall() in app.js, which AJAX-loads that date's saved summary (or
     blanks the fields if nothing's saved yet) via GET /telesales-summary. --}}
<div class="tss-small rounded-lg border border-amber-200 dark:border-amber-900 bg-amber-50/40 dark:bg-amber-950/10 px-2.5 py-2 flex flex-col" data-tss-small data-date="{{ $date }}">
    <input type="date" data-tss-date-input value="{{ $date }}" max="{{ now()->toDateString() }}"
           class="w-full bg-transparent text-center text-xs font-bold font-mono text-slate-600 dark:text-slate-300 border-0 border-b border-amber-200 dark:border-amber-900 rounded-none px-0 py-0 pb-1.5 mb-1.5 focus:ring-0 focus:border-primary cursor-pointer">

    <div class="flex items-center justify-between gap-1 mb-1">
        <span class="text-[10px] font-mono font-semibold text-slate-400 uppercase shrink-0">Gross Sales</span>
        <div class="flex items-center gap-0.5">
            <span class="text-xs font-mono text-slate-400">₱</span>
            <input type="number" step="0.01" min="0" data-field="gross_sales"
                   value="{{ $summary?->gross_sales }}" placeholder="0.00"
                   class="w-20 bg-white dark:bg-slate-800 border border-slate-200 dark:border-slate-600 rounded px-1.5 py-0.5 text-right text-xs font-bold font-mono text-slate-800 dark:text-slate-100 focus:ring-2 focus:ring-primary focus:border-primary transition-colors" style="font-variant-numeric: tabular-nums">
        </div>
    </div>

    <div class="flex items-center justify-between gap-1">
        <span class="text-[10px] font-mono font-semibold text-slate-400 uppercase shrink-0">Net Income</span>
        <div class="flex items-center gap-0.5">
            <span class="text-xs font-mono text-slate-400">₱</span>
            <input type="number" step="0.01" data-field="net_income"
                   value="{{ $summary?->net_income }}" placeholder="0.00"
                   class="w-20 bg-white dark:bg-slate-800 border border-slate-200 dark:border-slate-600 rounded px-1.5 py-0.5 text-right text-xs font-bold font-mono text-slate-800 dark:text-slate-100 focus:ring-2 focus:ring-primary focus:border-primary transition-colors" style="font-variant-numeric: tabular-nums">
        </div>
    </div>

    <button type="button" data-tss-small-save
            class="mt-1.5 w-full inline-flex items-center justify-center gap-1 px-2 py-1 rounded-md text-[10px] font-semibold font-mono bg-amber-600 text-white hover:bg-amber-700 transition-colors cursor-pointer disabled:opacity-50 disabled:cursor-wait">
        <svg class="w-2.5 h-2.5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" d="M4.5 12.75l6 6 9-13.5"/>
        </svg>
        Save
    </button>
</div>
