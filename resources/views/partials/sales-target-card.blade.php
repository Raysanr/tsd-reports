{{-- "This month vs target" (2026-10-24) — Leads Reports Dashboard. Needs
     $salesTargets (App\Support\SalesTargets::forMonth()). Net income vs the
     Net income target in Data Management → Projections (no typing here). --}}
@php
    $st = $salesTargets;
    $peso = fn ($v) => ($v < 0 ? '−' : '') . '₱' . number_format(abs($v), 0);
    $statusMeta = [
        'hit'      => ['Hit 🎉', 'bg-emerald-100 text-emerald-800 dark:bg-emerald-500/15 dark:text-emerald-300', 'bg-emerald-500'],
        'on_track' => ['On track', 'bg-sky-100 text-sky-800 dark:bg-sky-500/15 dark:text-sky-300', 'bg-sky-500'],
        'behind'   => ['Behind', 'bg-rose-100 text-rose-800 dark:bg-rose-500/15 dark:text-rose-300', 'bg-rose-500'],
        'none'     => ['No target', 'bg-slate-100 text-slate-500 dark:bg-slate-800 dark:text-slate-400', 'bg-slate-400'],
    ];
    $withTargets = $st['tsas']->where('target', '>', 0);
@endphp
<section class="mb-8 rounded-xl border border-slate-200 dark:border-slate-700 bg-white dark:bg-slate-900 shadow-sm overflow-hidden">
    <div class="flex flex-wrap items-center justify-between gap-3 border-b border-slate-200 dark:border-slate-700 px-5 py-4">
        <div>
            <h2 class="text-sm font-bold font-mono uppercase tracking-wide text-slate-800 dark:text-slate-100">This month vs target</h2>
            <p class="text-xs font-mono text-slate-500 dark:text-slate-400 mt-0.5">{{ $st['label'] }} · net income vs Projections' net income target (same net income as the TSD Scoreboard) · {{ $st['daysLeft'] }} {{ \Illuminate\Support\Str::plural('day', $st['daysLeft']) }} left · {{ round($st['elapsed'] * 100) }}% of the month done</p>
        </div>
        <span class="text-[11px] font-mono text-slate-400">Targets from Data Management → Projections</span>
    </div>

    @if($withTargets->isEmpty())
        <p class="px-5 py-8 text-center text-sm font-mono text-slate-500 dark:text-slate-400">No Projections targets for {{ $st['label'] }} yet. Set them in Data Management → Projections.</p>
    @else
    {{-- Teams --}}
    <div class="grid grid-cols-1 md:grid-cols-{{ max(1, min(3, $st['teams']->where('target', '>', 0)->count())) }} gap-4 px-5 pt-5">
        @foreach($st['teams']->where('target', '>', 0) as $team)
        @php [$sLabel, $sClass, $bar] = $statusMeta[$team['status']]; @endphp
        <div class="rounded-xl bg-slate-50 dark:bg-slate-800/50 p-4">
            <div class="flex items-center justify-between gap-2">
                <span class="font-mono text-sm font-bold text-slate-800 dark:text-slate-100 uppercase truncate">{{ $team['name'] }}</span>
                <span class="rounded-full px-2 py-0.5 text-[11px] font-semibold {{ $sClass }}">{{ $sLabel }}</span>
            </div>
            <div class="mt-2 flex items-baseline gap-1.5 font-mono">
                <span class="text-xl font-bold text-slate-800 dark:text-slate-100" style="font-variant-numeric: tabular-nums">{{ $peso($team['actual']) }}</span>
                <span class="text-xs text-slate-500">/ {{ $peso($team['target']) }}</span>
                <span class="ml-auto text-sm font-bold text-slate-700 dark:text-slate-200">{{ round($team['pct'] * 100) }}%</span>
            </div>
            <div class="relative mt-2 h-2.5 rounded-full bg-slate-200 dark:bg-slate-700 overflow-hidden">
                <div class="h-full rounded-full {{ $bar }}" style="width: {{ max(0, min(100, round($team['pct'] * 100, 1))) }}%"></div>
                <div class="absolute inset-y-0 w-0.5 bg-slate-500/60" style="left: {{ round($st['elapsed'] * 100, 1) }}%" title="Where you should be today"></div>
            </div>
            <p class="mt-2 text-[11px] font-mono text-slate-500 dark:text-slate-400">
                @if($team['status'] === 'hit') Target reached @elseif($st['daysLeft'] > 0) Need {{ $peso($team['perDay']) }} net/day to hit target @else {{ $peso($team['remaining']) }} short @endif
            </p>
        </div>
        @endforeach
    </div>

    {{-- TSAs --}}
    <ul class="divide-y divide-slate-100 dark:divide-slate-800 px-5 py-3">
        @foreach($withTargets->sortByDesc('pct') as $row)
        @php [$sLabel, $sClass, $bar] = $statusMeta[$row['status']]; @endphp
        <li class="grid grid-cols-[minmax(0,10rem)_1fr_auto] sm:grid-cols-[minmax(0,12rem)_1fr_9rem_6rem] items-center gap-3 py-2.5 font-mono text-sm">
            <span class="truncate font-semibold text-slate-700 dark:text-slate-200">{{ $row['name'] }}</span>
            <div class="relative h-2 rounded-full bg-slate-100 dark:bg-slate-800 overflow-hidden">
                <div class="h-full rounded-full {{ $bar }}" style="width: {{ max(0, min(100, round($row['pct'] * 100, 1))) }}%"></div>
                <div class="absolute inset-y-0 w-0.5 bg-slate-500/50" style="left: {{ round($st['elapsed'] * 100, 1) }}%"></div>
            </div>
            <span class="hidden sm:block text-right text-xs text-slate-500" style="font-variant-numeric: tabular-nums">{{ $peso($row['actual']) }} / {{ $peso($row['target']) }}</span>
            <span class="justify-self-end rounded-full px-2 py-0.5 text-[11px] font-semibold whitespace-nowrap {{ $sClass }}">{{ round($row['pct'] * 100) }}% · {{ $sLabel }}</span>
        </li>
        @endforeach
    </ul>
    @endif
</section>

