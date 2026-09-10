{{-- WHITEBOARD-STYLE EDITABLE SUMMARY (explicit request, 2026-09-09) — replaces
     the old Recent Orders table. Mirrors the physical "Telesales Department"
     whiteboard photo: 2 small prior-day columns on top (Gross Sales + Net
     Income only) and one large "today" block below (Gross Sales, Daily Net
     Income, Top Seller/Top Team side by side, sub-team counts, Overall
     Working TSA's) — fixed 3 slots, no scrolling. Follow-up request
     (2026-09-09): maximize space usage, add a PNG snapshot button (same
     html2canvas mechanism as every other report's table-actions), and make
     each slot's date pickable instead of hardcoded to today/yesterday/2-days-
     ago — changing a slot's date AJAX-loads whatever's saved for that date
     via GET /telesales-summary (DashboardController::showTelesalesSummary),
     no full page reload, no server round-trip through the whole Dashboard. --}}
<div class="bg-white dark:bg-slate-900 rounded-xl border border-black shadow-sm overflow-hidden flex flex-col" id="telesalesSummaryCard">
    {{-- Header (explicit follow-up, 2026-09-09): title+subtitle centered on
         screen at all times, not just in the exported snapshot — the camera
         button is absolutely positioned so it doesn't pull the centered
         block off-center. data-snapshot-hide on the button/subtitle still
         applies for the PNG export itself (see app.js's snapshot-hide
         handling) — an exported image has nothing to click, so neither
         belongs there even though both stay visible live. --}}
    <div class="relative px-4 py-3 border-b border-black bg-gradient-to-r from-amber-50 to-white dark:from-amber-950/20 dark:to-slate-900 text-center shrink-0">
        <h2 class="text-base font-bold text-slate-700 dark:text-slate-200 font-mono tracking-wide">Telesales Department</h2>
        <p data-snapshot-hide class="text-xs font-mono text-slate-400 mt-0.5">Click any value to edit</p>
        <button type="button" data-export-png="telesalesSummaryCard" data-export-name="telesales-department" data-snapshot-hide
                title="Save as image" aria-label="Save summary as image"
                class="absolute top-1/2 right-4 -translate-y-1/2 p-2 rounded-lg text-slate-400 hover:text-slate-600 dark:hover:text-slate-300 hover:bg-white dark:hover:bg-slate-800 transition-colors cursor-pointer disabled:cursor-wait shrink-0">
            <svg class="w-5 h-5" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" d="M6.827 6.175A2.31 2.31 0 015.186 7.23c-.38.054-.757.112-1.134.175C2.999 7.58 2.25 8.507 2.25 9.574V18a2.25 2.25 0 002.25 2.25h15A2.25 2.25 0 0021.75 18V9.574c0-1.067-.75-1.994-1.802-2.169a47.865 47.865 0 00-1.134-.175 2.31 2.31 0 01-1.64-1.055l-.822-1.316a2.192 2.192 0 00-1.736-1.039 48.774 48.774 0 00-5.232 0 2.192 2.192 0 00-1.736 1.039l-.821 1.316z"/>
                <path stroke-linecap="round" stroke-linejoin="round" d="M16.5 12.75a4.5 4.5 0 11-9 0 4.5 4.5 0 019 0z"/>
            </svg>
        </button>
    </div>

    {{-- Sized to its own content now, not stretched to match Hourly Leads'
         height (follow-up, 2026-09-10: "maximize the box of the down part...
         the down part will be like small") — the card used to force h-full/
         flex-1 all the way down to the sub-team section just to fill
         whatever height its sibling widget happened to have, which is what
         produced the empty gap the sub-team section then centered itself
         inside of. --}}
    <div class="p-3 flex flex-col gap-3">
        {{-- 2 small prior-day columns, side by side --}}
        <div class="grid grid-cols-2 gap-3">
            @foreach($telesalesPriorDates as $i => $date)
            @include('partials.telesales-summary-small', ['date' => $date, 'summary' => $telesalesPriorSummaries[$i]])
            @endforeach
        </div>

        @include('partials.telesales-summary-today', ['date' => $telesalesToday, 'summary' => $telesalesTodaySummary, 'teams' => $telesalesTeams])
    </div>
</div>
