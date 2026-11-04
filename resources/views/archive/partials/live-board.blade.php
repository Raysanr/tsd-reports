{{-- TSD Scoreboard cards: Total Sales + Total Net Income this month (Summary Sales Report figures). --}}
<div data-live-board>
    @if($rows->isEmpty())
    <div class="rounded-2xl bg-white dark:bg-slate-900 ring-1 ring-line dark:ring-slate-800">
        @include('archive.partials.empty', ['title' => 'No active TSAs', 'text' => 'TSAs marked active in TSA Management will appear here.'])
    </div>
    @else
    <div class="grid grid-cols-1 sm:grid-cols-2 xl:grid-cols-3 2xl:grid-cols-4 gap-4">
        @foreach($rows->sortByDesc(fn ($r) => $totals[$r['id']]['net'] ?? 0)->values() as $r)
        <article data-name="{{ strtolower($r['name']) }}" data-tsa-id="{{ $r['id'] }}" role="button" tabindex="0" aria-haspopup="dialog" aria-label="Open {{ $r['name'] }}'s sales and net income"
                 class="cursor-pointer rounded-2xl bg-white dark:bg-slate-900 ring-1 ring-line dark:ring-slate-800 shadow-panel p-5 transition duration-200 hover:-translate-y-0.5 hover:shadow-lg hover:ring-yellow-300 dark:hover:ring-yellow-500/40 focus:outline-none focus-visible:ring-2 focus-visible:ring-yellow-400">
            <div class="flex items-center gap-3">
                <span data-tsa-avatar>@include('archive.partials.avatar', ['name' => $r['name'], 'size' => 'md', 'photo' => $r['photo'] ?? null])</span>
                <div class="min-w-0">
                    <div class="font-semibold truncate">{{ $r['name'] }}</div>
                    <div class="text-xs text-ink-muted dark:text-slate-400 truncate">{{ $r['team'] ?: '—' }}</div>
                </div>
            </div>
            @php $t = $totals[$r['id']] ?? ['sales' => 0, 'net' => 0]; @endphp
            <dl class="mt-4 grid grid-cols-2 gap-3">
                <div class="rounded-xl bg-slate-50 dark:bg-slate-800/60 px-3 py-2.5">
                    <dt class="text-[11px] text-ink-muted dark:text-slate-400">Total sales · {{ now()->format('M') }}</dt>
                    <dd class="mt-0.5 text-lg font-bold tabular-nums">₱{{ number_format($t['sales'], 2) }}</dd>
                </div>
                <div class="rounded-xl bg-emerald-50/70 dark:bg-emerald-500/10 ring-1 ring-inset ring-emerald-100 dark:ring-emerald-500/20 px-3 py-2.5">
                    <dt class="text-[11px] text-emerald-700/80 dark:text-emerald-300/80">Total net income · {{ now()->format('M') }}</dt>
                    <dd class="mt-0.5 text-lg font-bold tabular-nums {{ $t['net'] < 0 ? 'text-rose-600 dark:text-rose-400' : 'text-emerald-700 dark:text-emerald-300' }}">{{ $t['net'] < 0 ? '−' : '' }}₱{{ number_format(abs($t['net']), 2) }}</dd>
                </div>
            </dl>
        </article>
        @endforeach
    </div>
    @endif
</div>
