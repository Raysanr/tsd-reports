@extends('layouts.archive')
@section('title', 'TSD Scoreboard')
@section('subtitle', 'Each TSA\'s total sales and net income — this month and by month')
@php extract(\App\Support\ArchiveUi::classes()); @endphp


@section('content')
<div class="mb-5 flex flex-wrap items-center gap-3">
    <div class="relative w-full sm:w-64">
        <svg class="pointer-events-none absolute left-3 top-1/2 -translate-y-1/2 w-4 h-4 text-slate-400" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" d="M21 21l-5.197-5.197m0 0A7.5 7.5 0 105.196 5.196a7.5 7.5 0 0010.607 10.607z"/></svg>
        <input id="liveSearch" type="search" placeholder="Find a TSA…" class="{{ $input }} pl-9">
    </div>
    <div class="inline-flex rounded-lg ring-1 ring-line dark:ring-slate-700 bg-white dark:bg-slate-800 p-1 text-xs font-semibold uppercase tracking-wide" role="tablist" aria-label="Team">
        @foreach(['all' => 'All'] + collect($teams)->map(fn ($t) => $t['name'])->all() as $slug => $name)
        <a href="{{ route('archive.live-tsa', ['team' => $slug, 'year' => $year]) }}" role="tab" aria-selected="{{ $team === $slug ? 'true' : 'false' }}"
           class="rounded-md px-3 py-1.5 {{ $team === $slug ? 'bg-primary text-white shadow-sm' : 'text-ink-muted dark:text-slate-300 hover:text-ink' }}">{{ $name }}</a>
        @endforeach
    </div>
    <p class="ml-auto text-xs text-ink-muted dark:text-slate-400">Cards: this month so far — same Gross Sales and Net Income as Data Management's Summary Sales Report.</p>
</div>

<div id="liveBoard">@include('archive.partials.live-board')</div>

{{-- Sales & net income by month — same Summary Sales Report figures as
     the cards, per month (cached: past months 12h, current month 5 min).
     One table, two metrics: the toggle swaps which one every cell shows. --}}
@php
    $peso = fn ($v) => ($v < 0 ? '−' : '') . '₱' . number_format(abs($v), 2);
    $short = fn ($v) => $v == 0 ? '—' : ($v < 0 ? '−' : '') . number_format(abs($v), 2);
    $heat = fn ($v, $max, $neg) => $v == 0 ? '' : 'background-color: ' . ($neg && $v < 0 ? 'rgba(244,63,94,' : 'rgba(16,185,129,') . round(0.06 + 0.3 * abs($v) / $max, 3) . ')';
@endphp
<section id="by-month" class="mt-8 {{ $card }} overflow-hidden">
    <div class="flex flex-wrap items-center justify-between gap-3 border-b border-line dark:border-slate-800 px-5 py-4">
        <div>
            <h3 class="font-semibold">Sales &amp; net income by month</h3>
            <p class="text-xs text-ink-muted dark:text-slate-400 mt-0.5">Same Gross Sales and Net Income as Data Management's Summary Sales Report.
                @if($figures['sheetMonths'])
                <span class="ml-1 inline-flex items-center gap-1"><span class="inline-block h-1.5 w-1.5 rounded-full bg-sky-500"></span>{{ collect($figures['sheetMonths'])->map(fn ($m) => \Carbon\Carbon::create($year, $m, 1)->format('M'))->join(', ') }} from the Google Sheet (IS Report).</span>
                @endif
            </p>
        </div>
        <div class="flex flex-wrap items-center gap-3">
            <div class="flex items-center gap-3 text-xs text-ink-muted dark:text-slate-400">
                <span class="inline-flex items-center gap-1.5"><span class="font-semibold text-ink dark:text-slate-100">1,234.00</span> Total sales</span>
                <span class="inline-flex items-center gap-1.5"><span class="font-semibold text-emerald-700 dark:text-emerald-300">567.00</span> Net income</span>
            </div>
            <div class="flex items-center gap-1 rounded-lg ring-1 ring-line dark:ring-slate-700 bg-white dark:bg-slate-800 p-1 text-sm">
                <a href="{{ route('archive.live-tsa', ['year' => $year - 1, 'team' => $team]) }}#by-month" class="rounded-md p-1.5 hover:bg-slate-100 dark:hover:bg-slate-700" aria-label="Previous year"><svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M15.75 19.5L8.25 12l7.5-7.5"/></svg></a>
                <span class="px-2 font-semibold tabular-nums">{{ $year }}</span>
                @if($year < now()->year)
                <a href="{{ route('archive.live-tsa', ['year' => $year + 1, 'team' => $team]) }}#by-month" class="rounded-md p-1.5 hover:bg-slate-100 dark:hover:bg-slate-700" aria-label="Next year"><svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M8.25 4.5l7.5 7.5-7.5 7.5"/></svg></a>
                @else
                <span class="rounded-md p-1.5 opacity-30"><svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M8.25 4.5l7.5 7.5-7.5 7.5"/></svg></span>
                @endif
            </div>
        </div>
    </div>

    @php
        $netClass = fn ($v) => $v < 0 ? 'text-rose-600 dark:text-rose-400' : 'text-emerald-700 dark:text-emerald-300';
        $bestSales = $figures['bestMonth']['sales'];
        $bestNet = $figures['bestMonth']['net'];
        $monthName = fn ($m) => $m ? ' · ' . \Carbon\Carbon::create($year, $m, 1)->format('F') : '';
        $tiles = [
            ['Total sales · ' . $year, $peso($figures['yearTotal']['sales']), ''],
            ['Net income · ' . $year, $peso($figures['yearTotal']['net']), $netClass($figures['yearTotal']['net'])],
            ['Best sales month' . $monthName($bestSales), $peso($bestSales ? $figures['monthTotals'][$bestSales]['sales'] : 0), ''],
            ['Best net income month' . $monthName($bestNet), $peso($bestNet ? $figures['monthTotals'][$bestNet]['net'] : 0), $bestNet ? $netClass($figures['monthTotals'][$bestNet]['net']) : ''],
        ];
    @endphp
    <div class="grid grid-cols-2 lg:grid-cols-4 gap-px bg-line dark:bg-slate-800 border-b border-line dark:border-slate-800">
        @foreach($tiles as [$k, $v, $c])
        <div class="bg-white dark:bg-slate-900 px-5 py-3">
            <div class="text-xs text-ink-muted dark:text-slate-400">{{ $k }}</div>
            <div class="mt-0.5 text-lg font-bold tabular-nums {{ $c }}">{{ $v }}</div>
        </div>
        @endforeach
    </div>

    @if($figures['rows']->isEmpty())
        @include('archive.partials.empty', ['title' => 'No active TSAs'])
    @else
    <div class="overflow-x-auto">
        <table class="min-w-full text-sm">
            {{-- each month = two columns side by side: Total sales | Net income --}}
            <thead>
                <tr class="text-xs uppercase tracking-wider text-ink-muted dark:text-slate-400">
                    <th rowspan="2" class="sticky left-0 z-10 bg-white dark:bg-slate-900 px-5 py-3 text-left font-semibold align-bottom">TSA</th>
                    @for($m = 1; $m <= 12; $m++)
                    <th colspan="2" class="px-3 pt-3 pb-1 text-center font-semibold whitespace-nowrap border-l border-line dark:border-slate-800 {{ $year === now()->year && $m === now()->month ? 'text-primary dark:text-yellow-400' : '' }}"
                        @if(in_array($m, $figures['sheetMonths'], true)) title="From the Google Sheet (IS Report)" @endif>
                        @if(in_array($m, $figures['sheetMonths'], true))<span class="mr-1 inline-block h-1.5 w-1.5 rounded-full bg-sky-500 align-middle"></span>@endif{{ \Carbon\Carbon::create($year, $m, 1)->format('M') }}
                    </th>
                    @endfor
                    <th colspan="2" class="px-5 pt-3 pb-1 text-center font-semibold border-l border-line dark:border-slate-800 bg-slate-50 dark:bg-slate-800/60">{{ $year }}</th>
                </tr>
                <tr class="text-[10px] uppercase tracking-wide text-ink-muted dark:text-slate-400">
                    @for($m = 1; $m <= 13; $m++)
                    <th class="pl-3 pr-2 pb-2 text-right font-medium whitespace-nowrap border-l border-line dark:border-slate-800 {{ $m === 13 ? 'bg-slate-50 dark:bg-slate-800/60' : '' }}">Total sales</th>
                    <th class="pl-2 pr-3 pb-2 text-right font-medium whitespace-nowrap text-emerald-700 dark:text-emerald-300 {{ $m === 13 ? 'bg-slate-50 dark:bg-slate-800/60' : '' }}">Net income</th>
                    @endfor
                </tr>
            </thead>
            <tbody class="divide-y divide-line dark:divide-slate-800">
                @foreach($figures['rows'] as $r)
                <tr class="hover:bg-slate-50/60 dark:hover:bg-slate-800/30">
                    <td class="sticky left-0 z-10 bg-white dark:bg-slate-900 px-5 py-2.5">
                        <div class="flex items-center gap-2.5 whitespace-nowrap">
                            @include('archive.partials.avatar', ['name' => $r['name'], 'size' => 'sm', 'photo' => $r['photo'] ?? null])
                            <div>
                                <div class="font-medium {{ $r['former'] ? 'text-ink-muted dark:text-slate-400' : '' }}">{{ $r['name'] }}@if($r['former'])<span class="ml-1.5 rounded-full bg-slate-100 dark:bg-slate-800 px-1.5 py-0.5 text-[10px] font-semibold uppercase tracking-wide text-slate-500 dark:text-slate-400" title="Not on the current roster — figures from the Google Sheet">Former</span>@endif</div>
                                <div class="text-[11px] text-ink-muted dark:text-slate-400">{{ $r['team'] }}</div>
                            </div>
                        </div>
                    </td>
                    @foreach($r['months'] as $m => $v)
                    @php $future = $year === now()->year && $m > now()->month; $empty = $v['sales'] == 0 && $v['net'] == 0; $bg = $empty ? '' : $heat($v['net'], $figures['cellMax']['net'], true); @endphp
                    <td class="pl-3 pr-2 py-2.5 text-right tabular-nums whitespace-nowrap font-medium border-l border-line dark:border-slate-800 {{ $future ? 'text-slate-300 dark:text-slate-600' : '' }}" style="{{ $bg }}">{{ $empty ? '—' : $short($v['sales']) }}</td>
                    <td class="pl-2 pr-3 py-2.5 text-right tabular-nums whitespace-nowrap font-semibold {{ $empty ? ($future ? 'text-slate-300 dark:text-slate-600' : '') : $netClass($v['net']) }}" style="{{ $bg }}">{{ $empty ? '—' : $short($v['net']) }}</td>
                    @endforeach
                    <td class="pl-5 pr-2 py-2.5 text-right tabular-nums whitespace-nowrap font-semibold border-l border-line dark:border-slate-800 bg-slate-50 dark:bg-slate-800/60">{{ $peso($r['year']['sales']) }}</td>
                    <td class="pl-2 pr-5 py-2.5 text-right tabular-nums whitespace-nowrap font-semibold bg-slate-50 dark:bg-slate-800/60 {{ $netClass($r['year']['net']) }}">{{ $peso($r['year']['net']) }}</td>
                </tr>
                @endforeach
            </tbody>
            <tfoot>
                <tr class="border-t-2 border-line dark:border-slate-700 font-semibold">
                    <td class="sticky left-0 z-10 bg-white dark:bg-slate-900 px-5 py-3">Total</td>
                    @foreach($figures['monthTotals'] as $v)
                    @php $empty = $v['sales'] == 0 && $v['net'] == 0; @endphp
                    <td class="pl-3 pr-2 py-3 text-right tabular-nums whitespace-nowrap border-l border-line dark:border-slate-800">{{ $empty ? '—' : $short($v['sales']) }}</td>
                    <td class="pl-2 pr-3 py-3 text-right tabular-nums whitespace-nowrap {{ $empty ? '' : $netClass($v['net']) }}">{{ $empty ? '—' : $short($v['net']) }}</td>
                    @endforeach
                    <td class="pl-5 pr-2 py-3 text-right tabular-nums whitespace-nowrap border-l border-line dark:border-slate-800 bg-slate-50 dark:bg-slate-800/60">{{ $peso($figures['yearTotal']['sales']) }}</td>
                    <td class="pl-2 pr-5 py-3 text-right tabular-nums whitespace-nowrap bg-slate-50 dark:bg-slate-800/60 {{ $netClass($figures['yearTotal']['net']) }}">{{ $peso($figures['yearTotal']['net']) }}</td>
                </tr>
            </tfoot>
        </table>
    </div>
    @endif
</section>
{{-- TSA detail: click a card → that TSA's total sales and net income
     (this month, the year, and each month). Data from $figures, so it
     matches the table exactly. Own open/close animation (scale + fade). --}}
@php
    $tsaDetail = $figures['rows']->where('former', false)->mapWithKeys(fn ($r) => [$r['id'] => [
        'name' => $r['name'], 'team' => $r['team'],
        'month' => $totals[$r['id']] ?? ['sales' => 0, 'net' => 0],
        'year' => $r['year'],
        'months' => collect($r['months'])->map(fn ($v, $m) => ['m' => \Carbon\Carbon::create($year, $m, 1)->format('F'), 'sales' => $v['sales'], 'net' => $v['net']])
            ->filter(fn ($v) => $v['sales'] != 0 || $v['net'] != 0)->values(),
    ]]);
@endphp
<script type="application/json" id="tsaDetailData">{!! json_encode($tsaDetail) !!}</script>
<dialog id="tsaDetail" class="tsa-modal m-auto w-[min(560px,calc(100vw-2rem))] max-h-[calc(100vh-2rem)] rounded-3xl bg-white dark:bg-slate-900 text-ink dark:text-slate-100 p-0 shadow-2xl ring-1 ring-black/5 dark:ring-white/10 overflow-hidden">
    <div class="relative bg-gradient-to-br from-neutral-900 via-neutral-900 to-yellow-900/60 px-6 pt-6 pb-5 text-white">
        <button type="button" data-tsa-close class="absolute right-4 top-4 rounded-full p-1.5 text-white/60 hover:bg-white/10 hover:text-white" aria-label="Close">
            <svg class="w-5 h-5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" d="M6 18L18 6M6 6l12 12"/></svg>
        </button>
        <div class="flex items-center gap-3">
            <span data-tsa-field="avatar" class="rounded-full ring-2 ring-yellow-400/70"></span>
            <div class="min-w-0">
                <div data-tsa-field="name" class="text-lg font-bold truncate"></div>
                <div data-tsa-field="team" class="text-xs uppercase tracking-wider text-yellow-300/90"></div>
            </div>
        </div>
    </div>
    <div class="max-h-[calc(100vh-10rem)] overflow-y-auto px-6 py-5 space-y-5">
        <div>
            <div class="mb-2 text-xs font-semibold uppercase tracking-wider text-ink-muted dark:text-slate-400">This month · {{ now()->format('F') }}</div>
            <div class="grid grid-cols-2 gap-3">
                <div class="tsa-stat rounded-2xl bg-slate-50 dark:bg-slate-800/60 px-4 py-3">
                    <div class="text-xs text-ink-muted dark:text-slate-400">Total sales</div>
                    <div data-tsa-field="month-sales" class="mt-1 text-2xl font-bold tabular-nums"></div>
                </div>
                <div class="tsa-stat rounded-2xl bg-emerald-50/70 dark:bg-emerald-500/10 ring-1 ring-inset ring-emerald-100 dark:ring-emerald-500/20 px-4 py-3">
                    <div class="text-xs text-emerald-700/80 dark:text-emerald-300/80">Net income</div>
                    <div data-tsa-field="month-net" class="mt-1 text-2xl font-bold tabular-nums"></div>
                </div>
            </div>
        </div>
        <div>
            <div class="mb-2 text-xs font-semibold uppercase tracking-wider text-ink-muted dark:text-slate-400">Year · {{ $year }}</div>
            <div class="grid grid-cols-2 gap-3">
                <div class="tsa-stat rounded-2xl bg-slate-50 dark:bg-slate-800/60 px-4 py-3">
                    <div class="text-xs text-ink-muted dark:text-slate-400">Total sales</div>
                    <div data-tsa-field="year-sales" class="mt-1 text-xl font-bold tabular-nums"></div>
                </div>
                <div class="tsa-stat rounded-2xl bg-emerald-50/70 dark:bg-emerald-500/10 ring-1 ring-inset ring-emerald-100 dark:ring-emerald-500/20 px-4 py-3">
                    <div class="text-xs text-emerald-700/80 dark:text-emerald-300/80">Net income</div>
                    <div data-tsa-field="year-net" class="mt-1 text-xl font-bold tabular-nums"></div>
                </div>
            </div>
        </div>
        <div>
            <div class="mb-2 text-xs font-semibold uppercase tracking-wider text-ink-muted dark:text-slate-400">By month</div>
            <div class="rounded-2xl ring-1 ring-line dark:ring-slate-800 overflow-hidden">
                <div class="grid grid-cols-3 bg-slate-50 dark:bg-slate-800/60 px-4 py-2 text-[11px] font-semibold uppercase tracking-wide text-ink-muted dark:text-slate-400">
                    <span>Month</span><span class="text-right">Total sales</span><span class="text-right text-emerald-700 dark:text-emerald-300">Net income</span>
                </div>
                <div data-tsa-field="months" class="divide-y divide-line dark:divide-slate-800 text-sm"></div>
            </div>
        </div>
    </div>
</dialog>

@endsection

@push('head')
<style>
    dialog.tsa-modal[open] { animation: tsa-in .32s cubic-bezier(.16,1,.3,1); }
    dialog.tsa-modal[open]::backdrop { background: rgba(2,6,23,.55); backdrop-filter: blur(4px); animation: tsa-fade .32s ease-out; }
    dialog.tsa-modal.is-closing { animation: tsa-out .2s ease-in forwards; }
    dialog.tsa-modal.is-closing::backdrop { animation: tsa-fade .2s ease-in reverse forwards; }
    dialog.tsa-modal[open] .tsa-stat { animation: tsa-rise .4s cubic-bezier(.16,1,.3,1) both; }
    dialog.tsa-modal[open] .tsa-stat:nth-child(2) { animation-delay: .05s; }
    @keyframes tsa-in { from { opacity: 0; transform: translateY(16px) scale(.94); } to { opacity: 1; transform: none; } }
    @keyframes tsa-out { to { opacity: 0; transform: translateY(10px) scale(.96); } }
    @keyframes tsa-fade { from { opacity: 0; } to { opacity: 1; } }
    @keyframes tsa-rise { from { opacity: 0; transform: translateY(6px); } to { opacity: 1; transform: none; } }
    @media (prefers-reduced-motion: reduce) { dialog.tsa-modal, dialog.tsa-modal::backdrop, dialog.tsa-modal .tsa-stat { animation: none !important; } }
</style>
@endpush

@push('scripts')
<script>
(function () {
    const board = document.getElementById('liveBoard');
    const search = document.getElementById('liveSearch');
    function apply() {
        const q = search.value.trim().toLowerCase();
        board.querySelectorAll('[data-name]').forEach((card) => card.classList.toggle('hidden', !!q && !card.dataset.name.includes(q)));
    }
    search.addEventListener('input', apply);

    // TSA detail modal
    const data = JSON.parse(document.getElementById('tsaDetailData').textContent || '{}');
    const dlg = document.getElementById('tsaDetail');
    const f = (k) => dlg.querySelector(`[data-tsa-field="${k}"]`);
    const peso = (v) => (v < 0 ? '−' : '') + '₱' + Math.abs(v).toLocaleString('en-PH', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    const tone = (el, v) => { el.classList.toggle('text-rose-600', v < 0); el.classList.toggle('dark:text-rose-400', v < 0); el.classList.toggle('text-emerald-700', v >= 0); el.classList.toggle('dark:text-emerald-300', v >= 0); };
    function open(card) {
        const d = data[card.dataset.tsaId];
        if (!d) return;
        f('avatar').innerHTML = card.querySelector('[data-tsa-avatar]')?.innerHTML || '';
        f('name').textContent = d.name;
        f('team').textContent = d.team || '';
        f('month-sales').textContent = peso(d.month.sales);
        f('month-net').textContent = peso(d.month.net); tone(f('month-net'), d.month.net);
        f('year-sales').textContent = peso(d.year.sales);
        f('year-net').textContent = peso(d.year.net); tone(f('year-net'), d.year.net);
        f('months').innerHTML = d.months.length ? '' : '<div class="px-4 py-4 text-center text-ink-muted">No figures yet.</div>';
        d.months.forEach((m) => {
            const row = document.createElement('div');
            row.className = 'grid grid-cols-3 px-4 py-2 tabular-nums';
            row.innerHTML = '<span class="font-medium"></span><span class="text-right"></span><span class="text-right font-semibold"></span>';
            row.children[0].textContent = m.m;
            row.children[1].textContent = peso(m.sales);
            row.children[2].textContent = peso(m.net); tone(row.children[2], m.net);
            f('months').appendChild(row);
        });
        dlg.classList.remove('is-closing');
        dlg.showModal();
    }
    function close() {
        if (!dlg.open || dlg.classList.contains('is-closing')) return;
        dlg.classList.add('is-closing');
        dlg.addEventListener('animationend', () => { dlg.classList.remove('is-closing'); dlg.close(); }, { once: true });
    }
    board.addEventListener('click', (e) => { const c = e.target.closest('[data-tsa-id]'); if (c) open(c); });
    board.addEventListener('keydown', (e) => { const c = e.target.closest('[data-tsa-id]'); if (c && (e.key === 'Enter' || e.key === ' ')) { e.preventDefault(); open(c); } });
    dlg.addEventListener('cancel', (e) => { e.preventDefault(); close(); });
    dlg.addEventListener('click', (e) => {
        if (e.target.closest('[data-tsa-close]')) return close();
        if (e.target !== dlg) return;
        const r = dlg.getBoundingClientRect();
        if (e.clientX < r.left || e.clientX > r.right || e.clientY < r.top || e.clientY > r.bottom) close();
    });


})();
</script>
@endpush
