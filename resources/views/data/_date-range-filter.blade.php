{{--
    Shared "From / To" date-range picker for every Data Management report
    page (DSPPR, Summary Sales Report, Expected Income) — explicit request,
    2026-09-27: "make all of the date picker in all pages ... has design,
    use tailwind css components for modern design." Replaces the bare
    native <input type="date"> look (a plain bordered box with the OS's own
    unstyled calendar-icon glyph) with a proper field: a floating calendar
    icon, a soft ring/shadow, and a clear hover/focus state — while keeping
    the real native date input underneath, so typing a date and the
    browser's own picker popup both keep working exactly as before on
    every platform (a full custom JS calendar widget would drop that for
    no real gain here).

    $name (form field name), $value (current date string), $label ("From"
    / "To") required. Submits its OWN <form> on change — same "auto-
    submit the filter form" convention every one of these 3 pages already
    uses, so this partial has to render INSIDE that page's own <form>, not
    wrap one itself.
--}}
<div>
    <label class="block text-[11px] font-mono font-semibold tracking-widest text-ink-muted dark:text-slate-400 uppercase mb-1">{{ $label }}</label>
    <div class="relative">
        <svg class="pointer-events-none absolute z-10 left-3 top-1/2 -translate-y-1/2 w-4 h-4 text-primary" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" d="M6.75 3v2.25M17.25 3v2.25M3.75 8.25h16.5M4.5 5.25h15A1.5 1.5 0 0121 6.75v13.5a1.5 1.5 0 01-1.5 1.5h-15A1.5 1.5 0 013 20.25V6.75a1.5 1.5 0 011.5-1.5z"/>
        </svg>
        <input type="date" name="{{ $name }}" value="{{ $value }}" onchange="this.form.submit()"
               class="date-range-input text-sm font-mono border border-line dark:border-slate-600 rounded-lg pl-9 pr-3 py-2 bg-white dark:bg-slate-800 text-ink dark:text-slate-100 shadow-sm hover:border-primary/50 focus:outline-none focus:ring-2 focus:ring-primary/40 focus:border-primary transition-colors">
    </div>
</div>

@once
    <style>
        /* Left-aligned custom SVG icon replaces the browser's own calendar
           glyph (WebKit/Blink only expose ::-webkit-calendar-picker-
           indicator for this — Firefox has no equivalent pseudo-element,
           so its native icon just stays put on the right there, which is
           harmless since the two icons don't visually collide). Stretched
           over the whole field (not just the OS's own small icon hitbox)
           so clicking anywhere opens the picker, a small but real usability
           upgrade over the native default. */
        .date-range-input::-webkit-calendar-picker-indicator {
            position: absolute;
            inset: 0;
            width: 100%;
            height: 100%;
            margin: 0;
            opacity: 0;
            cursor: pointer;
        }
        .date-range-input { position: relative; }
    </style>
@endonce
