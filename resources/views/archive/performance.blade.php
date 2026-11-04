@extends('layouts.archive')
@section('title', 'Performance History')
@section('subtitle', 'Each TSA\'s month, frozen on the 1st so history never shifts')
@php
    extract(\App\Support\ArchiveUi::classes());
    $peso = fn ($v) => '₱' . number_format($v, 2);
@endphp

@push('topbar-right')
{{-- Month picker: choosing a month opens it right away (?month=YYYY-MM).
     Months that already have a snapshot get a dot; future months are off. --}}
<div class="relative" data-month-picker
     data-current="{{ $month->format('Y-m') }}" data-max="{{ now()->format('Y-m') }}"
     data-snapshots='@json($months->values())' data-url="{{ route('archive.performance') }}">
    <button type="button" data-mp-toggle aria-haspopup="dialog" aria-expanded="false"
            class="inline-flex items-center gap-2.5 rounded-lg bg-white dark:bg-slate-800 py-2 pl-3 pr-2.5 text-sm font-semibold ring-1 ring-inset ring-line dark:ring-slate-700 hover:ring-yellow-400 focus-visible:ring-2 focus-visible:ring-yellow-500 outline-none cursor-pointer transition">
        <svg class="w-4 h-4 text-primary" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M6.75 3v2.25M17.25 3v2.25M3 18.75V7.5a2.25 2.25 0 012.25-2.25h13.5A2.25 2.25 0 0121 7.5v11.25m-18 0A2.25 2.25 0 005.25 21h13.5A2.25 2.25 0 0021 18.75m-18 0v-7.5A2.25 2.25 0 015.25 9h13.5A2.25 2.25 0 0121 11.25v7.5"/></svg>
        <span class="tabular-nums">{{ $month->format('F Y') }}</span>
        <svg class="w-4 h-4 text-ink-muted transition" data-mp-chevron fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M19.5 8.25l-7.5 7.5-7.5-7.5"/></svg>
    </button>

    <div data-mp-panel hidden role="dialog" aria-label="Choose a month"
         class="absolute right-0 top-full z-40 mt-2 w-72 origin-top-right rounded-2xl bg-white dark:bg-slate-900 p-4 shadow-2xl ring-1 ring-black/5 dark:ring-white/10">
        <div class="flex items-center justify-between">
            <button type="button" data-mp-prev aria-label="Previous year" class="rounded-lg p-1.5 text-ink-muted hover:bg-slate-100 hover:text-ink dark:hover:bg-slate-800 cursor-pointer"><svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M15.75 19.5L8.25 12l7.5-7.5"/></svg></button>
            <span data-mp-year class="text-sm font-bold tabular-nums"></span>
            <button type="button" data-mp-next aria-label="Next year" class="rounded-lg p-1.5 text-ink-muted hover:bg-slate-100 hover:text-ink dark:hover:bg-slate-800 cursor-pointer disabled:opacity-30 disabled:cursor-not-allowed disabled:hover:bg-transparent"><svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M8.25 4.5l7.5 7.5-7.5 7.5"/></svg></button>
        </div>
        <div data-mp-grid class="mt-3 grid grid-cols-3 gap-1.5"></div>
        <div class="mt-4 flex items-center justify-between border-t border-line dark:border-slate-800 pt-3 text-xs">
            <span class="inline-flex items-center gap-1.5 text-ink-muted dark:text-slate-400"><span class="h-1.5 w-1.5 rounded-full bg-emerald-500"></span>Has snapshot</span>
            <div class="flex items-center gap-1">
                <button type="button" data-mp-jump="last" class="rounded-md px-2 py-1 font-semibold text-ink-muted hover:bg-slate-100 hover:text-ink dark:hover:bg-slate-800 cursor-pointer">Last month</button>
                <button type="button" data-mp-jump="this" class="rounded-md px-2 py-1 font-semibold text-primary hover:bg-yellow-50 dark:hover:bg-yellow-500/10 cursor-pointer">This month</button>
            </div>
        </div>
    </div>
</div>

<form method="POST" action="{{ route('archive.performance.generate') }}" data-confirm="{{ $rows->isEmpty() ? 'Generate' : 'Refresh' }} the snapshot for {{ $month->format('F Y') }}? Existing numbers for that month are replaced with freshly computed ones.">
    @csrf
    <input type="hidden" name="month" value="{{ $month->format('Y-m') }}">
    <button type="submit" class="{{ $btnPrimary }}">
        <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M16.023 9.348h4.992v-.001M2.985 19.644v-4.992m0 0h4.992m-4.993 0l3.181 3.183a8.25 8.25 0 0013.803-3.7M4.031 9.865a8.25 8.25 0 0113.803-3.7l3.181 3.182m0-4.991v4.99"/></svg>
        {{ $rows->isEmpty() ? 'Generate' : 'Refresh' }} {{ $month->format('M') }}
    </button>
</form>
@endpush

@section('content')
<div class="mb-5 flex flex-wrap items-center gap-2">
    @forelse($months->take(12) as $m)
    <a href="{{ route('archive.performance', ['month' => $m]) }}"
       class="rounded-full px-3 py-1.5 text-sm font-medium ring-1 ring-inset {{ $m === $month->format('Y-m') ? 'bg-primary text-white ring-primary' : 'bg-white dark:bg-slate-900 ring-line dark:ring-slate-700 text-ink-muted hover:text-ink' }}">
        {{ \Carbon\Carbon::parse($m . '-01')->format('M Y') }}
    </a>
    @empty
    <span class="text-sm text-ink-muted dark:text-slate-400">No snapshots yet — they're created automatically on the 1st of each month. Pick a month above, then press Generate.</span>
    @endforelse
</div>

<div class="grid grid-cols-2 md:grid-cols-3 xl:grid-cols-6 gap-4 mb-6">
    @foreach([['Leads', number_format($totals['leads']), ''], ['Called', number_format($totals['called']), ''], ['Upsells', number_format($totals['upsells']), ''],
              ['Upsell sales', $peso($totals['upsell_sales']), 'text-primary dark:text-yellow-400'], ['Delivered', $peso($totals['delivered']), 'text-emerald-600 dark:text-emerald-400'], ['RTS', $peso($totals['rts']), 'text-rose-600 dark:text-rose-400']] as [$k, $v, $c])
    <div class="{{ $card }} p-4"><div class="text-xs text-ink-muted dark:text-slate-400">{{ $k }} · {{ $month->format('M Y') }}</div><div class="mt-1 text-xl font-bold tabular-nums {{ $c }}">{{ $v }}</div></div>
    @endforeach
</div>

<div class="{{ $card }} overflow-hidden">
    <div class="flex items-center justify-between border-b border-line dark:border-slate-800 px-5 py-3">
        <h3 class="font-semibold">{{ $month->format('F Y') }}</h3>
        @if($generatedAt)<span class="text-xs text-ink-muted dark:text-slate-400">Snapshot taken {{ $generatedAt->format('M j, Y g:i A') }}</span>@endif
    </div>
    @if($rows->isEmpty())
        @include('archive.partials.empty', ['title' => 'No snapshot for ' . $month->format('F Y'), 'text' => 'Press Generate to freeze this month\'s numbers now.'])
    @else
    <div class="overflow-x-auto">
        <table class="min-w-full text-sm">
            <thead class="text-[11px] uppercase tracking-wider text-ink-muted dark:text-slate-400">
                <tr class="border-b border-line dark:border-slate-800">
                    <th class="px-5 py-3 text-left font-semibold">#</th><th class="px-3 py-3 text-left font-semibold">TSA</th>
                    <th class="px-3 py-3 text-right font-semibold">Leads</th><th class="px-3 py-3 text-right font-semibold">Called</th>
                    <th class="px-3 py-3 text-right font-semibold">Upsells</th><th class="px-3 py-3 text-right font-semibold">Conv.</th>
                    <th class="px-3 py-3 text-right font-semibold">Upsell sales</th><th class="px-3 py-3 text-right font-semibold">Delivered</th>
                    <th class="px-3 py-3 text-right font-semibold">RTS</th><th class="px-5 py-3 text-right font-semibold">Delivery rate</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-line dark:divide-slate-800">
                @foreach($rows as $i => $r)
                @php $rate = $r->deliveryRate(); @endphp
                <tr class="hover:bg-slate-50/60 dark:hover:bg-slate-800/30">
                    <td class="px-5 py-2.5 text-xs font-semibold text-ink-muted tabular-nums">{{ $i + 1 }}</td>
                    <td class="px-3 py-2.5"><div class="flex items-center gap-2.5 whitespace-nowrap">@include('archive.partials.avatar', ['name' => $r->tsa_name, 'size' => 'sm'])<div><div class="font-medium">{{ $r->tsa_name }}</div><div class="text-[11px] text-ink-muted dark:text-slate-400">{{ $r->team }}</div></div></div></td>
                    <td class="px-3 py-2.5 text-right tabular-nums">{{ number_format($r->leads) }}</td>
                    <td class="px-3 py-2.5 text-right tabular-nums">{{ number_format($r->called) }}</td>
                    <td class="px-3 py-2.5 text-right tabular-nums">{{ number_format($r->upsells) }}</td>
                    <td class="px-3 py-2.5 text-right tabular-nums">{{ $r->conversion_rate !== null ? $r->conversion_rate . '%' : '—' }}</td>
                    <td class="px-3 py-2.5 text-right tabular-nums whitespace-nowrap">{{ $peso($r->upsell_sales) }}</td>
                    <td class="px-3 py-2.5 text-right tabular-nums whitespace-nowrap font-semibold text-emerald-700 dark:text-emerald-300">{{ $peso($r->delivered) }}</td>
                    <td class="px-3 py-2.5 text-right tabular-nums whitespace-nowrap text-rose-600 dark:text-rose-400">{{ $peso($r->rts) }}</td>
                    <td class="px-5 py-2.5 text-right">
                        @if($rate !== null)
                        <div class="flex items-center justify-end gap-2"><div class="h-1.5 w-16 rounded-full bg-slate-100 dark:bg-slate-800 overflow-hidden"><div class="h-full rounded-full {{ $rate >= 80 ? 'bg-emerald-500' : ($rate >= 60 ? 'bg-yellow-500' : 'bg-rose-500') }}" style="width: {{ $rate }}%"></div></div><span class="w-11 text-xs font-semibold tabular-nums">{{ $rate }}%</span></div>
                        @else — @endif
                    </td>
                </tr>
                @endforeach
            </tbody>
        </table>
    </div>
    @endif
</div>
@endsection

@push('scripts')
<script>
(function () {
    const root = document.querySelector('[data-month-picker]');
    if (!root) return;
    const MONTHS = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
    const panel = root.querySelector('[data-mp-panel]');
    const toggle = root.querySelector('[data-mp-toggle]');
    const grid = root.querySelector('[data-mp-grid]');
    const current = root.dataset.current, max = root.dataset.max;
    const snapshots = new Set(JSON.parse(root.dataset.snapshots || '[]'));
    const pad = (n) => String(n).padStart(2, '0');
    let year = +current.slice(0, 4);

    const go = (ym) => { location.href = root.dataset.url + '?month=' + ym; };

    function render() {
        root.querySelector('[data-mp-year]').textContent = year;
        root.querySelector('[data-mp-next]').disabled = year >= +max.slice(0, 4);
        grid.innerHTML = '';
        MONTHS.forEach((label, i) => {
            const ym = `${year}-${pad(i + 1)}`;
            const btn = document.createElement('button');
            btn.type = 'button';
            btn.textContent = label;
            const future = ym > max, selected = ym === current, isNow = ym === max;
            btn.disabled = future;
            btn.className = 'relative rounded-lg py-2 text-sm font-medium transition outline-none focus-visible:ring-2 focus-visible:ring-yellow-500 '
                + (selected ? 'bg-primary text-white shadow-sm '
                    : future ? 'text-slate-300 dark:text-slate-600 cursor-not-allowed '
                    : 'text-ink dark:text-slate-200 hover:bg-yellow-50 dark:hover:bg-slate-800 cursor-pointer ')
                + (isNow && !selected ? 'ring-1 ring-inset ring-yellow-400 ' : '');
            if (snapshots.has(ym)) {
                const dot = document.createElement('span');
                dot.className = 'absolute bottom-1 left-1/2 h-1 w-1 -translate-x-1/2 rounded-full ' + (selected ? 'bg-white' : 'bg-emerald-500');
                btn.append(dot);
            }
            if (!future) btn.addEventListener('click', () => go(ym));
            grid.append(btn);
        });
    }
    function open(state) {
        panel.hidden = !state;
        toggle.setAttribute('aria-expanded', state);
        root.querySelector('[data-mp-chevron]').classList.toggle('rotate-180', state);
        if (state) { year = +current.slice(0, 4); render(); grid.querySelector('button:not(:disabled)')?.focus(); }
    }
    toggle.addEventListener('click', () => open(panel.hidden));
    root.querySelector('[data-mp-prev]').addEventListener('click', () => { year--; render(); });
    root.querySelector('[data-mp-next]').addEventListener('click', () => { year++; render(); });
    root.querySelectorAll('[data-mp-jump]').forEach((b) => b.addEventListener('click', () => {
        const d = new Date(+max.slice(0, 4), +max.slice(5, 7) - 1, 1);
        if (b.dataset.mpJump === 'last') d.setMonth(d.getMonth() - 1);
        go(`${d.getFullYear()}-${pad(d.getMonth() + 1)}`);
    }));
    document.addEventListener('click', (e) => { if (!panel.hidden && !root.contains(e.target)) open(false); });
    document.addEventListener('keydown', (e) => { if (e.key === 'Escape' && !panel.hidden) { open(false); toggle.focus(); } });
})();
</script>
@endpush
