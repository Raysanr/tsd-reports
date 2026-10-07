{{--
    Whole-table lock toggle (explicit request, 2026-10-07: "add lock icon
    in every table ... like in the projections page", "smooth transition
    of lock too ... has animation") — same open/closed padlock SVG pair
    and 180ms opacity cross-fade Projections/Expected Income's own
    per-card lock already uses (see cost-breakdown.blade.php's own
    wireLockToggles() for the animation), just scoped to a whole <table>
    (every .cb-field input inside it) instead of one card.

    Required: $table (the short lock key — salary/pools/tsa, matches
    CostBreakdownController::LOCK_TABLES), $locked (bool, current state).
--}}
<button type="button" data-cb-lock-toggle data-table="{{ $table }}" data-locked="{{ $locked ? '1' : '0' }}"
        title="{{ $locked ? 'Unlock this table' : 'Lock this table' }}"
        aria-label="{{ $locked ? 'Unlock this table' : 'Lock this table' }}"
        class="shrink-0 p-1 rounded-md transition-colors cursor-pointer">
    <span data-cb-lock-icon style="display:inline-flex; transition:opacity 180ms ease;">
        @if($locked)
        <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" d="M16.5 10.5V6.75a4.5 4.5 0 10-9 0v3.75m-.75 11.25h10.5a2.25 2.25 0 002.25-2.25v-6.75a2.25 2.25 0 00-2.25-2.25H6.75a2.25 2.25 0 00-2.25 2.25v6.75a2.25 2.25 0 002.25 2.25z"/>
        </svg>
        @else
        <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" d="M13.5 10.5V6.75a4.5 4.5 0 119 0v3.75M3.75 21.75h10.5a2.25 2.25 0 002.25-2.25v-6.75a2.25 2.25 0 00-2.25-2.25H3.75a2.25 2.25 0 00-2.25 2.25v6.75a2.25 2.25 0 002.25 2.25z"/>
        </svg>
        @endif
    </span>
</button>
