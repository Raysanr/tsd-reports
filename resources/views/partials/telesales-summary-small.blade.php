{{-- One "small" prior-day column — date is independently editable (data-tss-
     date-input) via a native <input type="date">, not fixed to whatever
     DashboardController::index() computed on page load. Changing it fires
     tssLoadSmall() in app.js, which AJAX-loads that date's saved summary (or
     blanks the fields if nothing's saved yet) via GET /telesales-summary.

     Sized up (explicit follow-up, 2026-09-10: "make the 2 dates of the top
     is make it is larger too") — bumped from text-xs/text-[10px] toward the
     today block's own field sizes, since the empty space that used to sit
     below today's own fields is gone (see telesales-summary-today.blade.php's
     own comment) and these two columns are no longer visually dwarfed by it. --}}
<div class="tss-small rounded-lg border border-black bg-amber-50/40 dark:bg-amber-950/10 px-4 py-3 flex flex-col" data-tss-small data-date="{{ $date }}">
    {{-- Native <input type="date"> stays the real, clickable/focusable
         control (still opens the browser's own date picker) — its own text
         is just made invisible (color:transparent) so the friendly-
         formatted <span> layered on top of it (data-tss-date-label,
         updated live in app.js's tssFormatDateLabel()) is what's actually
         seen: "September 09, 2026" instead of the native "09/09/2026"
         (explicit request, 2026-09-10). --}}
    <div class="relative w-full pb-2 mb-2 border-b border-black">
        <input type="date" data-tss-date-input value="{{ $date }}" max="{{ now()->toDateString() }}"
               class="w-full bg-transparent text-center text-base font-bold font-mono border-0 rounded-none px-0 py-0 focus:ring-0 cursor-pointer" style="color: transparent">
        <span data-tss-date-label class="absolute inset-0 flex items-center justify-center text-base font-bold font-mono text-slate-700 dark:text-slate-200 pointer-events-none"></span>
    </div>

    <div class="flex items-center justify-between gap-2 mb-2">
        <span class="text-xs font-mono font-semibold text-slate-400 uppercase shrink-0">Gross Sales</span>
        <div class="flex items-center gap-1">
            <span class="text-sm font-mono text-slate-400">₱</span>
            <input type="text" inputmode="decimal" data-field="gross_sales" data-money-field
                   value="{{ $summary?->gross_sales }}" placeholder="0.00"
                   class="w-32 bg-white dark:bg-slate-800 border border-black rounded-md px-2 py-1.5 text-right text-base font-bold font-mono text-slate-800 dark:text-slate-100 focus:ring-2 focus:ring-primary focus:border-primary transition-colors" style="font-variant-numeric: tabular-nums">
        </div>
    </div>

    <div class="flex items-center justify-between gap-2">
        <span class="text-xs font-mono font-semibold text-slate-400 uppercase shrink-0">Net Income</span>
        <div class="flex items-center gap-1">
            <span class="text-sm font-mono text-slate-400">₱</span>
            <input type="text" inputmode="decimal" data-field="net_income" data-money-field
                   value="{{ $summary?->net_income }}" placeholder="0.00"
                   class="w-32 bg-white dark:bg-slate-800 border border-black rounded-md px-2 py-1.5 text-right text-base font-bold font-mono text-slate-800 dark:text-slate-100 focus:ring-2 focus:ring-primary focus:border-primary transition-colors" style="font-variant-numeric: tabular-nums">
        </div>
    </div>

    <button type="button" data-tss-small-save data-snapshot-hide
            class="mt-3 w-full inline-flex items-center justify-center gap-1.5 px-3 py-2 rounded-lg text-sm font-semibold font-mono bg-amber-600 text-white hover:bg-amber-700 transition-colors cursor-pointer disabled:opacity-50 disabled:cursor-wait">
        <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" d="M4.5 12.75l6 6 9-13.5"/>
        </svg>
        Save
    </button>
</div>
