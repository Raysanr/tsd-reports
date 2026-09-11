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
    {{-- Native <input type="date"> stays the real control backing the
         friendly-formatted <span> layered on top of it (data-tss-date-
         label, updated live in app.js's tssFormatDateLabel()) — but is now
         calendar-picker-only (explicit follow-up, 2026-09-11: "why is it
         like hidden date picker... i want only they will pick in the
         calendar"). Before, the input's own text was merely color-
         transparent while its native day/month/year segments stayed real
         and independently focusable/typable underneath the overlay — a
         click could land on a segment and show the browser's own edit-
         highlight (the "09" blue box in the bug report) fighting visually
         with the label text on top of it, and typing digits could silently
         change the date with no visible feedback at all.
         opacity-0 (not color:transparent) hides the whole control
         including its segment-focus chrome; pointer-events-none takes it
         out of the click path entirely so only the wrapper's own onclick
         below (data-tss-date-trigger) can ever open it, via showPicker() —
         a real user click can no longer land on a native segment. Keydown
         is blocked in app.js so even a focus arrived at programmatically
         can't be typed into. --}}
    <div class="relative w-full pb-2 mb-2 border-b border-black cursor-pointer" data-tss-date-trigger>
        <input type="date" data-tss-date-input value="{{ $date }}" max="{{ now()->toDateString() }}"
               class="absolute inset-0 w-full h-full opacity-0 pointer-events-none border-0 p-0" tabindex="-1" aria-hidden="true">
        <span data-tss-date-label class="block text-center text-base font-bold font-mono text-slate-700 dark:text-slate-200"></span>
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
