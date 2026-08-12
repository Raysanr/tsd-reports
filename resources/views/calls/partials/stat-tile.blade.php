{{--
    Reusable KPI/stat tile — ported from call-tracker (merged into one app
    2026-08-12), restyled to tsd-reports' own dark-mode convention (see
    resources/css/app.css's @theme comment) instead of call-tracker's
    light-only bg-white/border-line/text-ink tokens: same icon-badge layout
    as tsd-reports' own Dashboard KPI cards (resources/views/dashboard.blade.php).

    Required: $label, $value, $icon (a key below), $color (Tailwind bg+text
    classes for the icon circle, e.g. 'text-blue-600 bg-blue-50 dark:bg-blue-950/40').
    Optional: $caption (a second, smaller line under the value).
--}}
<div class="stat-card bg-white dark:bg-slate-900 rounded-xl border border-slate-200 dark:border-slate-700 shadow-sm p-3 sm:p-5 flex flex-col sm:flex-row items-center sm:items-start text-center sm:text-left gap-2 sm:gap-4">
    <div class="w-9 h-9 sm:w-12 sm:h-12 rounded-full flex items-center justify-center shrink-0 {{ $color }}">
        @switch($icon)
            @case('inbox')
                <svg class="w-4.5 h-4.5 sm:w-6 sm:h-6" fill="none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M3 8l3-5h12l3 5M3 8v10a2 2 0 002 2h14a2 2 0 002-2V8M3 8h18M9 12h6"/></svg>
                @break
            @case('phone')
                <svg class="w-4.5 h-4.5 sm:w-6 sm:h-6" fill="none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M3 5a2 2 0 012-2h3.28a1 1 0 01.948.684l1.498 4.493a1 1 0 01-.502 1.21l-2.257 1.13a11.042 11.042 0 005.516 5.517l1.13-2.257a1 1 0 011.21-.502l4.493 1.498a1 1 0 01.684.949V19a2 2 0 01-2 2h-1C9.716 21 3 14.284 3 6V5z"/></svg>
                @break
            @case('clock')
                <svg class="w-4.5 h-4.5 sm:w-6 sm:h-6" fill="none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m9-.75a9 9 0 11-18 0 9 9 0 0118 0zm-9 3.75h.008v.008H12v-.008z"/></svg>
                @break
            @case('calendar')
                <svg class="w-4.5 h-4.5 sm:w-6 sm:h-6" fill="none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"/></svg>
                @break
            @case('warning')
                <svg class="w-4.5 h-4.5 sm:w-6 sm:h-6" fill="none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m-9.303 3.376c-.866 1.5.217 3.374 1.948 3.374h14.71c1.73 0 2.813-1.874 1.948-3.374L13.949 3.378c-.866-1.5-3.032-1.5-3.898 0L2.697 16.126zM12 15.75h.007v.008H12v-.008z"/></svg>
                @break
            @case('plus')
                <svg class="w-4.5 h-4.5 sm:w-6 sm:h-6" fill="none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15"/></svg>
                @break
        @endswitch
    </div>
    <div class="min-w-0 w-full">
        <p class="text-xs font-mono font-semibold text-slate-400 uppercase tracking-wider mb-1">{{ $label }}</p>
        <p class="text-lg sm:text-2xl font-bold text-slate-800 dark:text-slate-100 font-mono leading-none tabular-nums">{{ $value }}</p>
        @if($caption)
        <p class="mt-1.5 text-xs text-slate-400 font-mono">{{ $caption }}</p>
        @endif
    </div>
</div>
