{{-- Telesales Department card, Aurora design (2026-11-05; styles in
     resources/css/telesales-aurora.css, derived numbers in app.js's
     tssRenderAurora). Replaces the whiteboard-style boxes but keeps the same
     fixed 3 slots: 2 prior days ([data-tss-small]) and today
     ([data-tss-today]), each with its own date picker and Save. Fields, saved
     data, and the PNG export are unchanged. The small slots now sit INSIDE
     today's block, because the design puts them mid-card under the chart. --}}
<div class="tsa-card" id="telesalesSummaryCard">
    @include('partials.telesales-summary-today', [
        'date' => $telesalesToday,
        'summary' => $telesalesTodaySummary,
        'teams' => $telesalesTeams,
        'priorDates' => $telesalesPriorDates,
        'priorSummaries' => $telesalesPriorSummaries,
    ])
</div>
