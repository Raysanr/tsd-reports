{{-- Disposition breakdown 3D pie panel. 'id' = canvas element id, must match
     an entry in the page's own $chartsData payload (see
     partials/disposition-pie-charts.blade.php, which renders it). Bold
     border + uppercase caption strip deliberately echoes the source sheet's
     own 3D pie chart style (explicit request, 2026-08-13) — the canvas
     itself draws the tilted-ellipse 3D look; this is just the frame. --}}
{{-- Explicit request (2026-08-13): fixed, self-contained size — two things
     confirmed broken at the "maximize the space" sizing this same day:
     (1) a fixed height (NOT flex-1/min-h, which stretches this panel to
     match the sibling table's full height via align-items:stretch — fine
     for a short table, but ~1000px+ tall for a 24-hour breakdown);
     (2) a RIGID 44rem (704px) width — root cause of a recurring "chart
     covers the table" report. Every earlier verification pass this same
     day only checked wide viewports (1600px+, where 704px is a small
     share of the row), so it always looked fine. A user report of "zooming
     out fixes it" led to sweeping actual viewport widths: at 1024px the
     table's own box measured 0px wide, at 1200px only 158px — shrink-0
     plus a flat 704px never yields ANY room back to the table below
     roughly ~1500px of effective width (smaller/non-maximized window, or
     simply zoomed in). w-[min(44rem,42%)] + dropping shrink-0 makes the
     panel shrink proportionally (42% of the row, capped at 704px on wide
     screens) instead of demanding a fixed amount regardless of how much
     the row actually has to give.
     self-center (not self-start): explicit request — the panel should sit
     vertically centered against however tall the sibling table/row ends up
     being, not pinned to the top of it. --}}
{{-- Manual resize handle (explicit request, 2026-08-14: replaces the
     earlier slider — wanted it as a drag handle sitting right at the
     boundary between the table and the chart, not a separate control up in
     the header). Placed as its OWN top-level element ahead of the panel
     div so it lands in the gap between the table and the panel in the
     parent row's flex layout (no changes needed there). Desktop-only
     (lg:): below that breakpoint the panel is already full-width with no
     table sharing the row to trade space against. touch-none stops
     touchscreens from scrolling the page while dragging the handle. --}}
<div data-pie-resizer
     class="hidden lg:flex self-stretch items-stretch w-4 shrink-0 relative cursor-col-resize touch-none group"
     role="separator" aria-orientation="vertical" aria-label="Resize disposition breakdown chart" tabindex="0">
    <div class="w-px mx-auto bg-slate-300 dark:bg-slate-600 group-hover:bg-yellow-600 dark:group-hover:bg-yellow-500 group-focus-visible:bg-yellow-600 dark:group-focus-visible:bg-yellow-500 transition-colors"></div>
    <div class="absolute top-1/2 left-1/2 -translate-x-1/2 -translate-y-1/2 flex items-center justify-center w-5 h-5 rounded-full bg-white dark:bg-slate-900 border border-slate-300 dark:border-slate-600 group-hover:border-yellow-600 dark:group-hover:border-yellow-500 transition-colors">
        <svg class="w-3 h-3 text-slate-400 dark:text-slate-500 group-hover:text-yellow-600 dark:group-hover:text-yellow-500 transition-colors" viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
            <path d="M7 6l-4 4 4 4M13 6l4 4-4 4"/>
        </svg>
    </div>
</div>
<div data-pie-panel class="self-center w-full lg:w-[min(44rem,42%)] bg-white dark:bg-slate-900 rounded-xl border-2 border-slate-800 dark:border-slate-200 shadow-sm p-4 flex flex-col">
    <div class="text-[11px] font-bold uppercase tracking-widest text-slate-500 dark:text-slate-400 mb-2 text-center">Disposition Breakdown</div>
    <div class="h-[520px] flex items-center justify-center">
        <canvas id="{{ $id }}" class="w-full h-full"></canvas>
    </div>
</div>
