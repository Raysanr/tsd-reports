{{--
    Reusable KPI/stat tile — ported from call-tracker (merged into one app
    2026-08-12), restyled to tsd-reports' own dark-mode convention (see
    resources/css/app.css's @theme comment) instead of call-tracker's
    light-only bg-white/border-line/text-ink tokens: same icon-badge layout
    as tsd-reports' own Dashboard KPI cards (resources/views/dashboard.blade.php).

    Required: $label, $value, $icon (a key below), $color (Tailwind bg+text
    classes for the icon circle, e.g. 'text-blue-600 bg-blue-50 dark:bg-blue-950/40').
    Optional: $caption (a second, smaller line under the value), $accent (true
    draws a warning-colored border instead of the neutral one — same border-
    only treatment tsd-reports' own Dashboard already uses on its "Total
    Restocking" card to flag a number that needs attention, not a full color
    swap of the card. dark:border-red-800, not -900 — red isn't the
    yellow/amber exception the app.css dark-mode convention comment calls
    out, so it follows the plain tinted-border rule; matches this same
    page's own at-risk-products banner, border-red-200 dark:border-red-800),
    $underline (a Tailwind bg-* class, e.g. 'bg-blue-500', for a short
    color-coded swatch under the number — a saturated solid, not a tinted
    surface, so it stays legible unpaired in both themes rather than needing
    its own dark: variant).

    Layout — explicit request (2026-08-18): fully centered, label on its own
    line above an icon+value row, matching a KPI-dashboard reference image
    the request came with, not the previous label/icon-left layout.
--}}
@php $accent = $accent ?? false; @endphp
<div class="stat-card bg-white dark:bg-slate-900 rounded-2xl border {{ $accent ? 'border-red-200 dark:border-red-800' : 'border-slate-200 dark:border-slate-700' }} shadow-sm p-5 sm:p-6 text-center">
    <p class="text-xs sm:text-sm font-bold text-slate-800 dark:text-slate-100 uppercase tracking-wide mb-4">{{ $label }}</p>
    <div class="flex items-center justify-center gap-3">
        <div class="w-11 h-11 sm:w-14 sm:h-14 rounded-full flex items-center justify-center shrink-0 {{ $color }}">
            @switch($icon)
                @case('inbox')
                    <svg class="w-5 h-5 sm:w-7 sm:h-7" fill="none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M3 8l3-5h12l3 5M3 8v10a2 2 0 002 2h14a2 2 0 002-2V8M3 8h18M9 12h6"/></svg>
                    @break
                @case('phone')
                    <svg class="w-5 h-5 sm:w-7 sm:h-7" fill="none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M3 5a2 2 0 012-2h3.28a1 1 0 01.948.684l1.498 4.493a1 1 0 01-.502 1.21l-2.257 1.13a11.042 11.042 0 005.516 5.517l1.13-2.257a1 1 0 011.21-.502l4.493 1.498a1 1 0 01.684.949V19a2 2 0 01-2 2h-1C9.716 21 3 14.284 3 6V5z"/></svg>
                    @break
                @case('clock')
                    <svg class="w-5 h-5 sm:w-7 sm:h-7" fill="none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m9-.75a9 9 0 11-18 0 9 9 0 0118 0zm-9 3.75h.008v.008H12v-.008z"/></svg>
                    @break
                @case('calendar')
                    <svg class="w-5 h-5 sm:w-7 sm:h-7" fill="none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"/></svg>
                    @break
                @case('warning')
                    <svg class="w-5 h-5 sm:w-7 sm:h-7" fill="none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m-9.303 3.376c-.866 1.5.217 3.374 1.948 3.374h14.71c1.73 0 2.813-1.874 1.948-3.374L13.949 3.378c-.866-1.5-3.032-1.5-3.898 0L2.697 16.126zM12 15.75h.007v.008H12v-.008z"/></svg>
                    @break
                @case('plus')
                    <svg class="w-5 h-5 sm:w-7 sm:h-7" fill="none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15"/></svg>
                    @break
                @case('user')
                    <svg class="w-5 h-5 sm:w-7 sm:h-7" fill="none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M15.75 6a3.75 3.75 0 11-7.5 0 3.75 3.75 0 017.5 0zM4.5 20.118a7.5 7.5 0 0115 0A17.933 17.933 0 0112 21.75c-2.676 0-5.216-.584-7.5-1.632z"/></svg>
                    @break
                @case('headset')
                    <svg class="w-5 h-5 sm:w-7 sm:h-7" fill="none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M3 18v-6a9 9 0 1118 0v6M21 19a2 2 0 01-2 2h-1a2 2 0 01-2-2v-3a2 2 0 012-2h3v5zM3 19a2 2 0 002 2h1a2 2 0 002-2v-3a2 2 0 00-2-2H3v5z"/></svg>
                    @break
                @case('stopwatch')
                    <svg class="w-5 h-5 sm:w-7 sm:h-7" fill="none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M9 2h6M12 3v2m0 3v4l2.5 2.5M21 13a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                    @break
                @case('hourglass')
                    <svg class="w-5 h-5 sm:w-7 sm:h-7" fill="none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M6 3h12M6 21h12M7 3c0 5 5 5.5 5 9s-5 4-5 9M17 3c0 5-5 5.5-5 9s5 4 5 9"/></svg>
                    @break
            @endswitch
        </div>
        <p class="text-2xl sm:text-3xl font-bold text-slate-900 dark:text-slate-100 font-mono leading-none tabular-nums">{{ $value }}</p>
    </div>
    @if(!empty($underline))
    <span class="block w-7 h-1 rounded-full {{ $underline }} mx-auto mt-3"></span>
    @endif
    @if($caption)
    <p class="mt-2 text-xs text-slate-400 font-mono">{{ $caption }}</p>
    @endif
</div>
