{{-- 3-step setup guide (2026-11-20) on Org Chart / Teams / People. Hidden once every step is done. --}}
@php $setup ??= \App\Support\OrgStructure::setup(); @endphp
@unless($setup['done'])
@php $next = collect($setup['steps'])->search(fn ($s) => ! $s['done']); @endphp
<section class="no-print mb-6 rounded-2xl bg-gradient-to-br from-yellow-50 to-white dark:from-yellow-500/10 dark:to-slate-900 ring-1 ring-yellow-200 dark:ring-yellow-500/20 p-5">
    <div class="flex flex-wrap items-baseline justify-between gap-2">
        <h2 class="font-semibold">Set up your org chart in 3 steps</h2>
        <p class="text-xs text-ink-muted dark:text-slate-400">The chart draws itself once these are done — no dragging or lines needed.</p>
    </div>
    <ol class="mt-4 grid gap-3 md:grid-cols-3">
        @foreach($setup['steps'] as $i => $step)
        <li class="flex gap-3 rounded-xl p-3 {{ $i === $next ? 'bg-white dark:bg-slate-900 ring-2 ring-primary/60 shadow-sm' : 'bg-white/60 dark:bg-slate-900/60 ring-1 ring-line dark:ring-slate-800' }}">
            <span class="flex h-7 w-7 shrink-0 items-center justify-center rounded-full text-sm font-bold {{ $step['done'] ? 'bg-emerald-500 text-white' : ($i === $next ? 'bg-primary text-white' : 'bg-slate-200 text-slate-600 dark:bg-slate-700 dark:text-slate-300') }}">
                @if($step['done'])<svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="3" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M4.5 12.75l6 6 9-13.5"/></svg>@else{{ $i + 1 }}@endif
            </span>
            <div class="min-w-0">
                <div class="text-sm font-semibold {{ $step['done'] ? 'text-ink-muted line-through decoration-1' : '' }}">{{ $step['title'] }}</div>
                <p class="text-xs text-ink-muted dark:text-slate-400">{{ $step['text'] }}</p>
                @if(! $step['done'])
                <div class="mt-2 flex flex-wrap gap-2">
                    @if($i === 0)
                        <a href="{{ route('archive.departments') }}#new" class="rounded-lg bg-primary px-2.5 py-1 text-xs font-semibold text-white hover:bg-primary-dark">+ New team</a>
                    @elseif($i === 1)
                        <a href="{{ route('archive.departments') }}" class="rounded-lg bg-primary px-2.5 py-1 text-xs font-semibold text-white hover:bg-primary-dark">Add members to teams</a>
                        <form method="POST" action="{{ route('archive.people.sync') }}">@csrf<button type="submit" class="rounded-lg px-2.5 py-1 text-xs font-semibold ring-1 ring-inset ring-line dark:ring-slate-700 bg-white dark:bg-slate-800 hover:bg-slate-50 cursor-pointer">Import TSAs</button></form>
                    @else
                        <a href="{{ route('archive.departments') }}" class="rounded-lg bg-primary px-2.5 py-1 text-xs font-semibold text-white hover:bg-primary-dark">Pick heads on Teams</a>
                    @endif
                </div>
                @endif
            </div>
        </li>
        @endforeach
    </ol>
</section>
@endunless
