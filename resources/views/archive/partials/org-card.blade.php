{{-- One card on the Org Chart canvas. Needs: $node (['model','parent','depth'?]), $view. --}}
@php
    $m = $node['model'];
    if ($view === 'people') {
        $levels = [
            ['band' => 'border-yellow-500', 'ring' => 'ring-yellow-500', 'body' => 'bg-yellow-50 dark:bg-slate-900'],
            ['band' => 'border-amber-400',  'ring' => 'ring-amber-400',  'body' => 'bg-amber-50/70 dark:bg-slate-900'],
            ['band' => 'border-stone-300 dark:border-slate-600', 'ring' => 'ring-stone-300 dark:ring-slate-600', 'body' => 'bg-white dark:bg-slate-900'],
        ];
        $lv = $levels[min($node['depth'] ?? 2, 2)];
        $name = $m->full_name;
    } else {
        $c = $m->colorClasses();
        $lv = ['band' => $c['band'], 'ring' => $c['ring'], 'body' => 'bg-white dark:bg-slate-900'];
        $name = $m->name;
    }
@endphp
<div class="org-node" data-id="{{ $m->id }}" data-parent="{{ $node['parent'] }}" data-name="{{ $name }}"
     @if($m->chart_x !== null) data-x="{{ $m->chart_x }}" data-y="{{ $m->chart_y }}" @endif>
    <div class="org-avatar {{ $lv['ring'] }}">
        @if($view === 'people' || $m->head)
            @include('archive.partials.avatar', ['name' => $view === 'people' ? $m->full_name : $m->head->full_name, 'size' => 'lg', 'photo' => $view === 'people' ? $m->photoUrl() : $m->head->photoUrl()])
        @else
            <span class="flex w-14 h-14 items-center justify-center rounded-full bg-sidebar text-white">
                <svg class="w-6 h-6" fill="none" stroke="currentColor" stroke-width="1.6" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M2.25 21h19.5m-18-18v18m10.5-18v18m6-13.5V21M6.75 6.75h.75m-.75 3h.75m-.75 3h.75m3-6h.75m-.75 3h.75m-.75 3h.75M3 3h12m-.75 4.5H21"/></svg>
            </span>
        @endif
    </div>
    <div class="org-card {{ $lv['band'] }} {{ $lv['body'] }}">
        <button type="button" class="org-handle org-handle-side no-print" data-side="left" title="Drag onto any card to link side-to-side" aria-label="Side link from {{ $name }}"></button>
        <button type="button" class="org-handle org-handle-side no-print" data-side="right" title="Drag onto any card to link side-to-side" aria-label="Side link from {{ $name }}"></button>
        @if($view === 'people')
            <a href="{{ route('archive.people.show', $m) }}" draggable="false" class="block font-bold leading-tight hover:text-primary">{{ $m->full_name }}</a>
            <div class="mt-0.5 text-xs text-ink-muted dark:text-slate-400 truncate">{{ $m->position ?: 'No position set' }}</div>
            <div class="mt-2 flex flex-wrap items-center justify-center gap-1.5">
                @if($m->department)<span class="rounded-full px-2 py-0.5 text-[11px] font-medium ring-1 ring-inset {{ $m->department->colorClasses()['soft'] }}">{{ $m->department->name }}</span>@endif
            </div>
        @else
            <div class="font-bold leading-tight">{{ $m->name }}</div>
            <div class="mt-0.5 text-xs text-ink-muted dark:text-slate-400 truncate">
                @if($m->head)<a href="{{ route('archive.people.show', $m->head) }}" draggable="false" class="hover:text-primary">{{ $m->head->full_name }}</a>@else No lead assigned @endif
            </div>
            <div class="mt-2 flex items-center justify-center gap-1.5">
                @if($m->code)<span class="rounded-md px-1.5 py-0.5 text-[10px] font-bold uppercase tracking-wider ring-1 ring-inset {{ $c['soft'] }}">{{ $m->code }}</span>@endif
                <span class="rounded-full px-2 py-0.5 text-[11px] font-semibold ring-1 ring-inset {{ $c['soft'] }}">{{ $m->employees->count() }} {{ \Illuminate\Support\Str::plural('person', $m->employees->count()) }}</span>
            </div>
            @php $members = $m->employees->reject(fn ($e) => $e->id === $m->head_employee_id); @endphp
            @if($members->isNotEmpty())
            <details class="org-members mt-3 group text-left">
                <summary class="flex cursor-pointer list-none items-center justify-between rounded-lg px-1 py-1 text-xs font-medium text-ink-muted dark:text-slate-400 hover:text-primary">
                    <span class="flex -space-x-2">
                        @foreach($members->take(5) as $mm)@include('archive.partials.avatar', ['name' => $mm->full_name, 'size' => 'sm', 'photo' => $mm->photoUrl()])@endforeach
                        @if($members->count() > 5)<span class="inline-flex w-7 h-7 items-center justify-center rounded-full bg-slate-200 dark:bg-slate-700 text-[10px] font-semibold ring-2 ring-white dark:ring-slate-900">+{{ $members->count() - 5 }}</span>@endif
                    </span>
                    <span class="flex items-center gap-1">Members <svg class="w-3.5 h-3.5 transition group-open:rotate-180" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M19.5 8.25l-7.5 7.5-7.5-7.5"/></svg></span>
                </summary>
                <ul class="mt-2 max-h-48 overflow-y-auto space-y-1">
                    @foreach($members as $mm)
                    <li><a href="{{ route('archive.people.show', $mm) }}" draggable="false" class="flex items-center gap-2 rounded-lg px-1.5 py-1 hover:bg-slate-50 dark:hover:bg-slate-800">
                        @include('archive.partials.avatar', ['name' => $mm->full_name, 'size' => 'sm', 'photo' => $mm->photoUrl()])
                        <span class="min-w-0"><span class="block text-xs font-medium truncate">{{ $mm->full_name }}</span><span class="block text-[11px] text-ink-muted dark:text-slate-400 truncate">{{ $mm->position ?: '—' }}</span></span>
                    </a></li>
                    @endforeach
                </ul>
            </details>
            @endif
        @endif
    </div>
    <button type="button" class="org-handle no-print" data-side="bottom" title="Drag onto a card to put it under {{ $name }}" aria-label="Put a card under {{ $name }}"></button>
</div>
