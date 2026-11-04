{{-- People as the "2026 MANPOWER UPDATE" sheet (2026-10-21), modern
     Tailwind version: readable dates ("Oct. 10, 2023") with a calendar
     popover, birthdays this month in green, cards on small screens.
     Every field saves on change (archive.people.manpower).
     Needs $rows, $title; optional $muted. --}}
@php
    $fmtDate = fn ($d) => $d ? $d->format('M') . ($d->format('M') === 'May' ? '' : '.') . $d->format(' j, Y') : null;
    $statuses = \App\Models\Employee::EMPLOYMENT_STATUSES;
    $shifts = \App\Models\Employee::SHIFT_SCHEDULES;
    $pillBase = 'appearance-none w-full rounded-full border-0 py-1 pl-3 pr-7 text-[11px] font-semibold uppercase tracking-wide text-center cursor-pointer transition focus:outline-none focus:ring-2 focus:ring-primary/50';
    $chevron = '<svg class="pointer-events-none absolute right-2 top-1/2 h-3 w-3 -translate-y-1/2 opacity-70" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M19.5 8.25l-7.5 7.5-7.5-7.5"/></svg>';
    $nowMonth = now()->month;
    $dateBtn = function ($e, string $field, $value, bool $birthday = false) use ($fmtDate, $nowMonth) {
        $isBday = $birthday && $value && $value->month === $nowMonth;
        $cls = $isBday
            ? 'bg-emerald-50 text-emerald-700 ring-emerald-200 dark:bg-emerald-500/10 dark:text-emerald-300 dark:ring-emerald-500/30 font-semibold'
            : ($value ? 'bg-white text-ink ring-line hover:ring-primary/50 dark:bg-slate-900 dark:text-slate-100 dark:ring-slate-700' : 'bg-slate-50 text-ink-muted ring-slate-200 hover:ring-primary/50 dark:bg-slate-800/60 dark:ring-slate-700');
        $icon = $isBday
            ? '<svg class="h-3.5 w-3.5 shrink-0" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M12 8.25v-1.5m0 1.5c-1.355 0-2.697.056-4.024.166C6.845 8.51 6 9.473 6 10.608v2.513m6-4.871c1.355 0 2.697.056 4.024.166C17.155 8.51 18 9.473 18 10.608v2.513M15 8.25v-1.5m-6 1.5v-1.5m12 9.75l-1.5.75a3.354 3.354 0 01-3 0 3.354 3.354 0 00-3 0 3.354 3.354 0 01-3 0 3.354 3.354 0 00-3 0 3.354 3.354 0 01-3 0L3 16.5m15-3.379a48.474 48.474 0 00-6-.371c-2.032 0-4.034.126-6 .371m12 0c.39.049.777.102 1.163.16 1.07.16 1.837 1.094 1.837 2.175v5.169c0 .621-.504 1.125-1.125 1.125H4.125A1.125 1.125 0 013 20.625v-5.17c0-1.08.768-2.014 1.837-2.174A47.78 47.78 0 016 13.12M12.265 3.11a.375.375 0 11-.53 0L12 2.845l.265.265zm-3 0a.375.375 0 11-.53 0L9 2.845l.265.265zm6 0a.375.375 0 11-.53 0L15 2.845l.265.265z"/></svg>'
            : '<svg class="h-3.5 w-3.5 shrink-0 opacity-60" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M6.75 3v2.25M17.25 3v2.25M3 18.75V7.5a2.25 2.25 0 012.25-2.25h13.5A2.25 2.25 0 0121 7.5v11.25m-18 0A2.25 2.25 0 005.25 21h13.5A2.25 2.25 0 0021 18.75m-18 0v-7.5A2.25 2.25 0 015.25 9h13.5A2.25 2.25 0 0121 11.25v7.5"/></svg>';

        return '<button type="button" data-date data-field="' . $field . '" data-value="' . e($value?->toDateString()) . '"' . ($birthday ? ' data-birthday' : '')
            . ' class="inline-flex items-center gap-1.5 whitespace-nowrap rounded-lg px-2.5 py-1.5 text-xs ring-1 ring-inset transition cursor-pointer ' . $cls . '"'
            . ($isBday ? ' title="Birthday this month"' : '') . '>' . $icon . '<span data-date-label>' . e($fmtDate($value) ?? 'Set date') . '</span></button>';
    };
    $statusSelect = function ($e) use ($statuses, $pillBase, $chevron) {
        $cur = $statuses[$e->employment_status] ?? null;
        $html = '<div class="relative"><select data-field="employment_status" data-pill class="' . $pillBase . ' ' . ($cur['pill'] ?? 'bg-slate-100 text-ink-muted dark:bg-slate-800 dark:text-slate-300') . '" aria-label="' . e($e->full_name) . ' status"><option value="">Set status</option>';
        foreach ($statuses as $k => $s) {
            $html .= '<option value="' . $k . '" data-pill-class="' . $s['pill'] . '"' . ($e->employment_status === $k ? ' selected' : '') . '>' . $s['label'] . '</option>';
        }

        return $html . '</select></div>';
    };
    $shiftSelect = function ($e) use ($shifts, $pillBase, $chevron) {
        $html = '<div class="relative"><select data-field="shift_schedule" class="' . $pillBase . ' bg-slate-100 text-ink dark:bg-slate-800 dark:text-slate-100" aria-label="' . e($e->full_name) . ' shift schedule"><option value="">Set shift</option>';
        foreach ($shifts as $k => $l) {
            $html .= '<option value="' . $k . '" data-pill-class="' . (\App\Models\Employee::SHIFT_PILLS[$k] ?? '') . '"' . ($e->shift_schedule === $k ? ' selected' : '') . '>' . $l . '</option>';
        }

        return $html . '</select></div>';
    };
    $noteInput = fn ($e) => '<input type="text" data-field="manual_status" value="' . e($e->manual_status) . '" maxlength="160" placeholder="Add a note…" aria-label="' . e($e->full_name) . ' manual status" class="w-full rounded-lg border-0 bg-slate-50 px-2.5 py-1.5 text-xs text-ink ring-1 ring-inset ring-transparent placeholder:text-slate-400 hover:ring-line focus:bg-white focus:outline-none focus:ring-2 focus:ring-primary/50 dark:bg-slate-800/60 dark:text-slate-100 dark:focus:bg-slate-900">';
    $bdayCount = $rows->filter(fn ($e) => $e->birthday && $e->birthday->month === $nowMonth)->count();
@endphp
<section class="overflow-hidden rounded-2xl bg-white dark:bg-slate-900 ring-1 ring-line dark:ring-slate-800 shadow-panel {{ ($muted ?? false) ? 'opacity-95' : '' }}">
    <header class="flex flex-wrap items-center justify-between gap-3 border-b border-line dark:border-slate-800 px-5 py-4">
        <div class="flex items-center gap-3">
            <span class="flex h-9 w-9 items-center justify-center rounded-xl {{ ($muted ?? false) ? 'bg-slate-100 text-slate-500 dark:bg-slate-800' : 'bg-yellow-100 text-yellow-800 dark:bg-yellow-500/10 dark:text-yellow-300' }}">
                <svg class="h-5 w-5" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M18 18.72a9.094 9.094 0 003.741-.479 3 3 0 00-4.682-2.72m.94 3.198l.001.031c0 .225-.012.447-.037.666A11.944 11.944 0 0112 21c-2.17 0-4.207-.576-5.963-1.584A6.062 6.062 0 016 18.719m12 0a5.971 5.971 0 00-.941-3.197m0 0A5.995 5.995 0 0012 12.75a5.995 5.995 0 00-5.058 2.772m0 0a3 3 0 00-4.681 2.72 8.986 8.986 0 003.74.477m.94-3.197a5.971 5.971 0 00-.94 3.197M15 6.75a3 3 0 11-6 0 3 3 0 016 0zm6 3a2.25 2.25 0 11-4.5 0 2.25 2.25 0 014.5 0zm-13.5 0a2.25 2.25 0 11-4.5 0 2.25 2.25 0 014.5 0z"/></svg>
            </span>
            <div>
                <h2 class="font-semibold">{{ $title }}</h2>
                <p class="text-xs text-ink-muted dark:text-slate-400">{{ $rows->count() }} {{ \Illuminate\Support\Str::plural('person', $rows->count()) }}</p>
            </div>
        </div>
        @if($bdayCount)
        <span class="inline-flex items-center gap-1.5 rounded-full bg-emerald-50 px-3 py-1 text-xs font-semibold text-emerald-700 ring-1 ring-inset ring-emerald-200 dark:bg-emerald-500/10 dark:text-emerald-300 dark:ring-emerald-500/30">
            🎂 {{ $bdayCount }} {{ \Illuminate\Support\Str::plural('birthday', $bdayCount) }} in {{ now()->format('F') }}
        </span>
        @endif
    </header>

    {{-- Table: md and up --}}
    <div class="hidden md:block overflow-x-auto">
        <table class="min-w-full text-sm" data-manpower>
            <thead class="bg-slate-50/80 dark:bg-slate-800/40">
                <tr class="text-left text-[11px] font-semibold uppercase tracking-wider text-ink-muted dark:text-slate-400">
                    <th class="px-5 py-3">Name</th>
                    <th class="px-3 py-3">Birthday</th>
                    <th class="px-3 py-3">Hiring date</th>
                    <th class="px-3 py-3 w-36">Status</th>
                    <th class="px-3 py-3 w-36">Shift schedule</th>
                    <th class="px-3 py-3 min-w-48">Status (manual)</th>
                    <th class="px-5 py-3">Date of last day</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-line dark:divide-slate-800">
                @foreach($rows as $e)
                <tr data-url="{{ route('archive.people.manpower', $e) }}" data-emp="{{ $e->id }}" class="transition-colors hover:bg-slate-50/70 dark:hover:bg-slate-800/30">
                    <td class="px-5 py-3">
                        <a href="{{ route('archive.people.show', $e) }}" class="group flex items-center gap-3">
                            @include('archive.partials.avatar', ['name' => $e->full_name, 'size' => 'sm', 'photo' => $e->photoUrl()])
                            <span class="min-w-0">
                                <span class="block truncate font-medium group-hover:text-primary">{{ $e->full_name }}</span>
                                <span class="block truncate text-xs text-ink-muted dark:text-slate-400">{{ $e->department?->name ?? 'No team' }}</span>
                            </span>
                        </a>
                    </td>
                    <td class="px-3 py-3">{!! $dateBtn($e, 'birthday', $e->birthday, true) !!}</td>
                    <td class="px-3 py-3">{!! $dateBtn($e, 'hired_at', $e->hired_at) !!}</td>
                    <td class="px-3 py-3">{!! $statusSelect($e) !!}</td>
                    <td class="px-3 py-3">{!! $shiftSelect($e) !!}</td>
                    <td class="px-3 py-3">{!! $noteInput($e) !!}</td>
                    <td class="px-5 py-3">{!! $dateBtn($e, 'left_at', $e->left_at) !!}</td>
                </tr>
                @endforeach
            </tbody>
        </table>
    </div>

    {{-- Cards: below md --}}
    <ul class="md:hidden divide-y divide-line dark:divide-slate-800" data-manpower>
        @foreach($rows as $e)
        <li data-url="{{ route('archive.people.manpower', $e) }}" data-emp="{{ $e->id }}" class="space-y-3 p-4">
            <a href="{{ route('archive.people.show', $e) }}" class="flex items-center gap-3">
                @include('archive.partials.avatar', ['name' => $e->full_name, 'size' => 'md', 'photo' => $e->photoUrl()])
                <span class="min-w-0">
                    <span class="block truncate font-semibold">{{ $e->full_name }}</span>
                    <span class="block truncate text-xs text-ink-muted dark:text-slate-400">{{ $e->department?->name ?? 'No team' }}</span>
                </span>
            </a>
            <div class="grid grid-cols-2 gap-2">{!! $statusSelect($e) !!}{!! $shiftSelect($e) !!}</div>
            <dl class="grid grid-cols-2 gap-x-3 gap-y-2 text-xs">
                <div><dt class="mb-1 text-ink-muted dark:text-slate-400">Birthday</dt><dd>{!! $dateBtn($e, 'birthday', $e->birthday, true) !!}</dd></div>
                <div><dt class="mb-1 text-ink-muted dark:text-slate-400">Hiring date</dt><dd>{!! $dateBtn($e, 'hired_at', $e->hired_at) !!}</dd></div>
                <div><dt class="mb-1 text-ink-muted dark:text-slate-400">Date of last day</dt><dd>{!! $dateBtn($e, 'left_at', $e->left_at) !!}</dd></div>
                <div class="col-span-2"><dt class="mb-1 text-ink-muted dark:text-slate-400">Status (manual)</dt><dd>{!! $noteInput($e) !!}</dd></div>
            </dl>
        </li>
        @endforeach
    </ul>
</section>

@once
@push('scripts')
{{-- Calendar popover shared by every date button --}}
<div id="mpCal" hidden role="dialog" aria-label="Pick a date"
     class="fixed z-[80] w-72 origin-top rounded-2xl bg-white p-3 shadow-2xl ring-1 ring-black/5 dark:bg-slate-900 dark:ring-white/10 transition duration-150 ease-out opacity-0 scale-95">
    <div class="mb-2 flex items-center justify-between gap-1">
        <button type="button" data-cal-prev class="rounded-lg p-1.5 text-ink-muted hover:bg-slate-100 dark:hover:bg-slate-800 cursor-pointer" aria-label="Previous month"><svg class="h-4 w-4" fill="none" stroke="currentColor" stroke-width="2.2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M15.75 19.5L8.25 12l7.5-7.5"/></svg></button>
        <div class="flex items-center gap-1">
            <select data-cal-month class="rounded-lg border-0 bg-slate-50 py-1 pl-2 pr-7 text-sm font-semibold focus:ring-2 focus:ring-primary/50 dark:bg-slate-800 cursor-pointer"></select>
            <select data-cal-year class="rounded-lg border-0 bg-slate-50 py-1 pl-2 pr-7 text-sm font-semibold focus:ring-2 focus:ring-primary/50 dark:bg-slate-800 cursor-pointer"></select>
        </div>
        <button type="button" data-cal-next class="rounded-lg p-1.5 text-ink-muted hover:bg-slate-100 dark:hover:bg-slate-800 cursor-pointer" aria-label="Next month"><svg class="h-4 w-4" fill="none" stroke="currentColor" stroke-width="2.2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M8.25 4.5l7.5 7.5-7.5 7.5"/></svg></button>
    </div>
    <div class="grid grid-cols-7 text-center text-[10px] font-semibold uppercase tracking-wide text-ink-muted dark:text-slate-500">
        <span class="py-1">Su</span><span class="py-1">Mo</span><span class="py-1">Tu</span><span class="py-1">We</span><span class="py-1">Th</span><span class="py-1">Fr</span><span class="py-1">Sa</span>
    </div>
    <div data-cal-days class="grid grid-cols-7 gap-0.5"></div>
    <div class="mt-2 flex items-center justify-between border-t border-line pt-2 dark:border-slate-800">
        <button type="button" data-cal-clear class="rounded-lg px-2.5 py-1 text-xs font-medium text-rose-600 hover:bg-rose-50 dark:hover:bg-rose-500/10 cursor-pointer">Clear</button>
        <button type="button" data-cal-today class="rounded-lg px-2.5 py-1 text-xs font-semibold text-primary hover:bg-yellow-50 dark:text-yellow-400 dark:hover:bg-yellow-500/10 cursor-pointer">Today</button>
    </div>
</div>
<script>
(function () {
    const token = document.querySelector('meta[name="csrf-token"]')?.content;
    const MONTHS = ['January', 'February', 'March', 'April', 'May', 'June', 'July', 'August', 'September', 'October', 'November', 'December'];
    const SHORT = ['Jan.', 'Feb.', 'Mar.', 'Apr.', 'May', 'Jun.', 'Jul.', 'Aug.', 'Sep.', 'Oct.', 'Nov.', 'Dec.'];
    const pad = (n) => String(n).padStart(2, '0');
    const iso = (d) => `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}`;
    const parse = (s) => { if (!s) return null; const [y, m, d] = s.split('-').map(Number); return new Date(y, m - 1, d); };
    const pretty = (s) => { const d = parse(s); return d ? `${SHORT[d.getMonth()]} ${d.getDate()}, ${d.getFullYear()}` : 'Set date'; };
    const BDAY_ON = ['bg-emerald-50', 'text-emerald-700', 'ring-emerald-200', 'dark:bg-emerald-500/10', 'dark:text-emerald-300', 'dark:ring-emerald-500/30', 'font-semibold'];
    const SET = ['bg-white', 'text-ink', 'ring-line', 'dark:bg-slate-900', 'dark:text-slate-100', 'dark:ring-slate-700'];
    const EMPTY = ['bg-slate-50', 'text-ink-muted', 'ring-slate-200', 'dark:bg-slate-800/60', 'dark:ring-slate-700'];

    async function save(holder, field, value) {
        const res = await fetch(holder.dataset.url, {
            method: 'PATCH',
            headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-TOKEN': token },
            body: JSON.stringify({ field, value }),
        });
        if (!res.ok) throw new Error();
        return res.json();
    }
    const flash = (el) => { el.classList.add('ring-2', 'ring-emerald-400'); setTimeout(() => el.classList.remove('ring-2', 'ring-emerald-400'), 700); };
    // The table (desktop) and cards (phone) both exist — keep every copy in step.
    const copies = (emp, field) => document.querySelectorAll(`[data-emp="${emp}"] [data-field="${field}"]`);

    function paintDate(btn, value) {
        btn.dataset.value = value || '';
        btn.querySelector('[data-date-label]').textContent = pretty(value);
        const isBday = btn.hasAttribute('data-birthday') && value && parse(value).getMonth() === new Date().getMonth();
        btn.classList.remove(...BDAY_ON, ...SET, ...EMPTY);
        btn.classList.add(...(isBday ? BDAY_ON : value ? SET : EMPTY));
        btn.title = isBday ? 'Birthday this month' : '';
    }
    function paintPill(sel) {
        const all = [...sel.options].map((o) => o.dataset.pillClass || '').join(' ').split(' ').filter(Boolean);
        sel.classList.remove(...all, 'bg-slate-100', 'text-ink-muted', 'dark:bg-slate-800', 'dark:text-slate-300');
        const cls = sel.selectedOptions[0]?.dataset.pillClass;
        sel.classList.add(...(cls ? cls.split(' ') : ['bg-slate-100', 'text-ink-muted', 'dark:bg-slate-800', 'dark:text-slate-300']));
    }

    async function commit(el, value) {
        const holder = el.closest('[data-url]');
        const field = el.dataset.field;
        const before = el.dataset.date !== undefined ? el.dataset.value : (el.dataset.saved ?? el.defaultValue);
        if ((value || '') === (before || '')) return;
        const apply = (v) => copies(holder.dataset.emp, field).forEach((c) => {
            if (c.dataset.date !== undefined) paintDate(c, v);
            else { c.value = v || ''; c.dataset.saved = v || ''; if (c.dataset.pill !== undefined) paintPill(c); }
        });
        apply(value);
        try {
            const data = await save(holder, field, value);
            flash(el);
            if (data.moved) { window.showToast?.('Saved — moved between current and former.', 'success'); setTimeout(() => location.reload(), 900); }
        } catch (e) {
            apply(before);
            window.showToast?.('Could not save — try again.', 'error');
        }
    }

    document.querySelectorAll('[data-manpower]').forEach((root) => {
        root.addEventListener('change', (e) => {
            const el = e.target.closest('select[data-field], input[data-field]');
            if (el) commit(el, el.value);
        });
        root.addEventListener('keydown', (e) => {
            if (e.key === 'Enter' && e.target.matches('input[data-field]')) { e.preventDefault(); e.target.blur(); }
        });
        root.addEventListener('click', (e) => {
            const btn = e.target.closest('[data-date]');
            if (btn) { e.stopPropagation(); openCal(btn); }
        });
    });

    // ---- Calendar popover ----
    const cal = document.getElementById('mpCal');
    const monthSel = cal.querySelector('[data-cal-month]');
    const yearSel = cal.querySelector('[data-cal-year]');
    const daysEl = cal.querySelector('[data-cal-days]');
    const thisYear = new Date().getFullYear();
    MONTHS.forEach((m, i) => monthSel.add(new Option(m, i)));
    for (let y = thisYear + 2; y >= 1950; y--) yearSel.add(new Option(y, y));
    let target = null, view = new Date();

    function render() {
        monthSel.value = view.getMonth();
        yearSel.value = view.getFullYear();
        const first = new Date(view.getFullYear(), view.getMonth(), 1);
        const start = new Date(first); start.setDate(1 - first.getDay());
        const sel = target?.dataset.value || '';
        const today = iso(new Date());
        daysEl.innerHTML = '';
        for (let i = 0; i < 42; i++) {
            const d = new Date(start); d.setDate(start.getDate() + i);
            const v = iso(d), inMonth = d.getMonth() === view.getMonth();
            const b = document.createElement('button');
            b.type = 'button';
            b.dataset.day = v;
            b.textContent = d.getDate();
            b.className = 'h-8 rounded-lg text-xs tabular-nums transition cursor-pointer '
                + (v === sel ? 'bg-primary text-white font-semibold shadow-sm '
                    : (v === today ? 'ring-1 ring-inset ring-primary/60 font-semibold ' : '')
                    + (inMonth ? 'text-ink hover:bg-yellow-50 dark:text-slate-100 dark:hover:bg-slate-800 ' : 'text-slate-300 hover:bg-slate-50 dark:text-slate-600 dark:hover:bg-slate-800/60 '));
            daysEl.appendChild(b);
        }
    }
    function place() {
        const r = target.getBoundingClientRect();
        const w = cal.offsetWidth, h = cal.offsetHeight;
        let left = Math.min(Math.max(8, r.left), window.innerWidth - w - 8);
        let top = r.bottom + 6;
        if (top + h > window.innerHeight - 8) top = Math.max(8, r.top - h - 6);
        cal.style.left = left + 'px';
        cal.style.top = top + 'px';
    }
    function openCal(btn) {
        if (target === btn && !cal.hidden) return closeCal();
        target = btn;
        view = parse(btn.dataset.value) || new Date();
        render();
        cal.hidden = false;
        place();
        requestAnimationFrame(() => cal.classList.remove('opacity-0', 'scale-95'));
    }
    function closeCal() {
        cal.classList.add('opacity-0', 'scale-95');
        setTimeout(() => { cal.hidden = true; }, 150);
        target = null;
    }
    function pick(value) {
        const btn = target;
        closeCal();
        if (btn) commit(btn, value);
    }
    cal.addEventListener('click', (e) => {
        e.stopPropagation();
        const day = e.target.closest('[data-day]');
        if (day) return pick(day.dataset.day);
        if (e.target.closest('[data-cal-prev]')) { view.setMonth(view.getMonth() - 1); render(); }
        if (e.target.closest('[data-cal-next]')) { view.setMonth(view.getMonth() + 1); render(); }
        if (e.target.closest('[data-cal-today]')) pick(iso(new Date()));
        if (e.target.closest('[data-cal-clear]')) pick('');
    });
    monthSel.addEventListener('change', () => { view = new Date(view.getFullYear(), +monthSel.value, 1); render(); });
    yearSel.addEventListener('change', () => { view = new Date(+yearSel.value, view.getMonth(), 1); render(); });
    document.addEventListener('click', () => { if (!cal.hidden) closeCal(); });
    document.addEventListener('keydown', (e) => { if (e.key === 'Escape' && !cal.hidden) closeCal(); });
    window.addEventListener('resize', () => { if (!cal.hidden && target) place(); });
    document.querySelector('main')?.addEventListener('scroll', () => { if (!cal.hidden) closeCal(); }, { passive: true });
})();
</script>
@endpush
@endonce
