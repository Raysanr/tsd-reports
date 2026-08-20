{{--
    Shared status panel — ported from call-tracker (merged into one app
    2026-08-12), restyled to tsd-reports' own dark-mode convention. Mirrors
    Pancake's own conversation-receive-mode popup: icon + bold label + gray
    description per row, checkmark on whichever is current. Used both by the
    topbar (a TSA's own status, $target='self') and, since 2026-08-20, the
    Leads tab's admin-facing status control (an admin setting whichever
    TSA's currently picked in the TSA selector, $target=that TSA's id — this
    used to be Call Rotation's own per-row dropdown, moved here instead) —
    same partial, same JS handlers (toggleStatusPanel/submitStatusChange in
    calls.js). Both pass SELF_SERVICE_STATUSES as $options (Lock excluded —
    no UI path sets it anymore, though STATUS_LOCKED/the readonly handling
    below stay in place for any TSA a prior Lock already left in that state).

    Required: $id (unique per instance), $options (TsaShift::STATUSES keys
    to show), $current (current status value), $target ('self' or a tsa id).
    Optional: $triggerClass (override the default trigger button styling),
    $readonly (true when $current is STATUS_LOCKED and $target is 'self' —
    a TSA can't unlock themselves).
--}}
@php
    // Every status gets its own distinct icon + color (explicit request,
    // 2026-08-20 — Break/Coaching/DNA Huddle/Huddle/Lunch/Others used to
    // all share the same generic amber crescent 'away' icon, making the
    // list hard to tell apart at a glance). Colors match the same per-
    // status palette Monitor TSA already uses for its dots/badges, so a
    // status reads the same color everywhere in the app, not just here.
    $icons = [
        'available' => '<svg class="w-4 h-4 text-emerald-500" fill="currentColor" viewBox="0 0 20 20"><path d="M2 5a2 2 0 012-2h12a2 2 0 012 2v8a2 2 0 01-2 2h-6l-4 3v-3H4a2 2 0 01-2-2V5z"/></svg>',
        // Pause bars — "stepped away, on hold."
        'break'     => '<svg class="w-4 h-4 text-yellow-500" fill="currentColor" viewBox="0 0 20 20"><rect x="5" y="3" width="3" height="14" rx="1"/><rect x="12" y="3" width="3" height="14" rx="1"/></svg>',
        // A fork — the one non-filled icon in this set, straight lines only
        // (three tines + a shared handle) rather than a misremembered
        // complex food-icon path.
        'lunch'     => '<svg class="w-4 h-4 text-amber-800 dark:text-amber-600" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" viewBox="0 0 20 20"><path d="M6 2v5M10 2v5M14 2v5M6 7h8M10 7v11"/></svg>',
        // Ascending bars — coaching/growth.
        'coaching'  => '<svg class="w-4 h-4 text-blue-500" fill="currentColor" viewBox="0 0 20 20"><rect x="3" y="11" width="3" height="6" rx="0.5"/><rect x="8.5" y="7" width="3" height="10" rx="0.5"/><rect x="14" y="3" width="3" height="14" rx="0.5"/></svg>',
        // Two overlapping circles — a small huddle. Distinct from Huddle's
        // three-circle icon below by COUNT, not just color, so the two
        // read as different at a glance even in grayscale.
        'dna_huddle'=> '<svg class="w-4 h-4 text-purple-500" fill="currentColor" viewBox="0 0 20 20"><circle cx="7" cy="7" r="3.5"/><circle cx="13" cy="11" r="3.5" opacity=".55"/></svg>',
        // Three circles in a triangle — a bigger group huddle.
        'huddle'    => '<svg class="w-4 h-4 text-sky-500" fill="currentColor" viewBox="0 0 20 20"><circle cx="10" cy="4.5" r="2.5"/><circle cx="4.5" cy="14" r="2.5"/><circle cx="15.5" cy="14" r="2.5"/></svg>',
        // Ellipsis — the classic "something else" glyph.
        'others'    => '<svg class="w-4 h-4 text-slate-400" fill="currentColor" viewBox="0 0 20 20"><circle cx="4" cy="10" r="1.75"/><circle cx="10" cy="10" r="1.75"/><circle cx="16" cy="10" r="1.75"/></svg>',
        // Clock — not offered as a manual option anywhere today (Wrap Up is
        // system-only), kept here for completeness in case this partial's
        // ever handed it as $current to render read-only.
        'wrap_up'   => '<svg class="w-4 h-4 text-orange-500" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" viewBox="0 0 20 20"><circle cx="10" cy="10" r="7.25"/><path d="M10 6v4l3 2"/></svg>',
        // Distinct from the others (explicit request, 2026-08-19) — a door-
        // with-arrow "exit" glyph, slate not amber, matching $dotColor's own
        // already-separate treatment of Logout below (Logout ends the shift
        // entirely; every other status is still a mid-shift pause).
        'logout'    => '<svg class="w-4 h-4 text-slate-400" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M3 4.25A2.25 2.25 0 015.25 2h5.5A2.25 2.25 0 0113 4.25v2a.75.75 0 01-1.5 0v-2a.75.75 0 00-.75-.75h-5.5a.75.75 0 00-.75.75v11.5c0 .414.336.75.75.75h5.5a.75.75 0 00.75-.75v-2a.75.75 0 011.5 0v2A2.25 2.25 0 0110.75 18h-5.5A2.25 2.25 0 013 15.75V4.25z" clip-rule="evenodd"/><path fill-rule="evenodd" d="M19 10a.75.75 0 00-.75-.75H8.704l1.048-.943a.75.75 0 10-1.004-1.114l-2.5 2.25a.75.75 0 000 1.114l2.5 2.25a.75.75 0 101.004-1.114l-1.048-.943h9.546A.75.75 0 0019 10z" clip-rule="evenodd"/></svg>',
        'lock'      => '<svg class="w-4 h-4 text-red-500" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M10 1a4 4 0 00-4 4v2H5a2 2 0 00-2 2v7a2 2 0 002 2h10a2 2 0 002-2V9a2 2 0 00-2-2h-1V5a4 4 0 00-4-4zm2 6V5a2 2 0 10-4 0v2h4z" clip-rule="evenodd"/></svg>',
    ];
    $dotColor = match(true) {
        $current === \App\Models\TsaShift::STATUS_LOGIN      => 'bg-emerald-500',
        $current === \App\Models\TsaShift::STATUS_CALLING    => 'bg-red-500',
        $current === \App\Models\TsaShift::STATUS_WRAP_UP    => 'bg-orange-500',
        $current === \App\Models\TsaShift::STATUS_BREAK      => 'bg-yellow-500',
        $current === \App\Models\TsaShift::STATUS_LUNCH      => 'bg-amber-800',
        $current === \App\Models\TsaShift::STATUS_COACHING   => 'bg-blue-500',
        $current === \App\Models\TsaShift::STATUS_DNA_HUDDLE => 'bg-purple-500',
        $current === \App\Models\TsaShift::STATUS_HUDDLE     => 'bg-sky-500',
        $current === \App\Models\TsaShift::STATUS_OTHERS     => 'bg-slate-400',
        $current === \App\Models\TsaShift::STATUS_LOGOUT     => 'bg-slate-300 dark:bg-slate-600',
        $current === \App\Models\TsaShift::STATUS_LOCKED     => 'bg-red-700',
        default => 'bg-amber-500',
    };
@endphp

@php $readonly = $readonly ?? false; @endphp
<div class="relative inline-block" data-status-panel-wrap>
    <button type="button" @if(!$readonly) onclick="toggleStatusPanel('{{ $id }}')" @endif id="statusTrigger-{{ $id }}"
            title="{{ $readonly ? 'Locked by an admin — ask them to change your status' : '' }}"
            class="{{ $readonly ? 'inline-flex items-center gap-1.5 text-xs font-mono font-semibold text-slate-400 border border-slate-200 dark:border-slate-700 rounded-lg px-2.5 py-1.5 bg-slate-50 dark:bg-slate-800 cursor-not-allowed' : ($triggerClass ?? 'inline-flex items-center gap-1.5 text-xs font-mono font-semibold text-slate-700 dark:text-slate-200 border border-slate-300 dark:border-slate-600 rounded-lg px-2.5 py-1.5 bg-white dark:bg-slate-800 hover:bg-slate-50 dark:hover:bg-slate-700 cursor-pointer') }}">
        <span class="w-2 h-2 rounded-full shrink-0 {{ $dotColor }}"></span>
        {{ strtoupper(\App\Models\TsaShift::STATUSES[$current]['label'] ?? $current) }}
        @if(!$readonly)
        <svg class="w-3 h-3 text-slate-400" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M19 9l-7 7-7-7"/></svg>
        @endif
    </button>

    @if(!$readonly)
    <div id="statusPanel-{{ $id }}" class="hidden fixed z-50 bg-white dark:bg-slate-900 rounded-2xl shadow-2xl border border-slate-200 dark:border-slate-700 w-72"
         data-status-panel data-status-target="{{ $target }}">
        <div class="px-4 py-3 border-b border-slate-100 dark:border-slate-700">
            <p class="text-xs font-bold text-slate-700 dark:text-slate-200 font-mono">Select status</p>
        </div>
        <div class="py-1">
            @foreach($options as $value)
            @php $opt = \App\Models\TsaShift::STATUSES[$value]; @endphp
            <div class="status-panel-option flex items-start gap-3 px-4 py-2.5 cursor-pointer hover:bg-slate-50 dark:hover:bg-slate-800" data-value="{{ $value }}">
                <span class="shrink-0 mt-0.5">{!! $icons[$opt['icon']] !!}</span>
                <div class="flex-1 min-w-0">
                    <p class="text-sm font-semibold text-slate-700 dark:text-slate-200 font-mono">{{ $opt['label'] }}</p>
                    <p class="text-[11px] text-slate-400 font-mono leading-snug">{{ $opt['description'] }}</p>
                </div>
                @if($current === $value)
                <svg class="w-4 h-4 text-primary shrink-0 mt-0.5" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7"/></svg>
                @endif
            </div>
            @endforeach
        </div>
    </div>
    @endif
</div>
