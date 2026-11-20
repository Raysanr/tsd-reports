{{-- A clickable card on the self-building Org Chart. Needs $node; $compact = small row style.
     Clicking opens the plain-button action menu (#nodeActions) from the <template>. --}}
@php
    $m = $node['model'];
    $isTeam = $node['type'] === 'team';
    $compact ??= false;
    $team = $isTeam ? $m : ($node['headOfTeam'] ?? $m->department);
    $c = $team ? $team->colorClasses() : \App\Models\Department::COLORS['slate'];
    $name = $isTeam ? $m->name : $m->full_name;
    $sub = $isTeam
        ? ($m->head ? 'Head: ' . $m->head->full_name : 'No head yet')
        : ($node['isHead'] ? 'Head of ' . $node['headOfTeam']->name : ($m->position ?: 'No position set'));
@endphp
<div class="org-cell">
    <button type="button" data-node data-title="{{ $name }}" data-sub="{{ $sub }}" data-search="{{ mb_strtolower($name) }}"
            class="org-c {{ $compact ? 'org-c-compact' : '' }} border-t-4 {{ $c['band'] }} {{ $node['isHead'] ? 'org-c-head' : '' }}">
        @if($isTeam)
            <span class="flex items-center gap-2">
                <span class="flex h-8 w-8 shrink-0 items-center justify-center rounded-lg {{ $c['soft'] }} ring-1 ring-inset">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M18 18.72a9.094 9.094 0 003.741-.479 3 3 0 00-4.682-2.72m.94 3.198l.001.031c0 .225-.012.447-.037.666A11.944 11.944 0 0112 21c-2.17 0-4.207-.576-5.963-1.584A6.062 6.062 0 016 18.719m12 0a5.971 5.971 0 00-.941-3.197m0 0A5.995 5.995 0 0012 12.75a5.995 5.995 0 00-5.058 2.772m0 0a3 3 0 00-4.681 2.72 8.986 8.986 0 003.74.477m.94-3.197a5.971 5.971 0 00-.94 3.197M15 6.75a3 3 0 11-6 0 3 3 0 016 0zm6 3a2.25 2.25 0 11-4.5 0 2.25 2.25 0 014.5 0zm-13.5 0a2.25 2.25 0 11-4.5 0 2.25 2.25 0 014.5 0z"/></svg>
                </span>
                <span class="min-w-0 text-left">
                    <span class="block font-bold leading-tight truncate">{{ $m->name }}</span>
                    <span class="block text-xs text-ink-muted dark:text-slate-400">{{ $m->employees_count }} {{ \Illuminate\Support\Str::plural('person', $m->employees_count) }}</span>
                </span>
            </span>
            @unless($m->head)
            <span class="mt-2 block rounded-md bg-amber-50 dark:bg-amber-500/10 px-2 py-1 text-[11px] font-semibold text-amber-700 dark:text-amber-300">No head yet — click to pick one</span>
            @endunless
        @elseif($compact)
            <span class="flex items-center gap-2.5 text-left">
                @include('archive.partials.avatar', ['name' => $m->full_name, 'size' => 'sm', 'photo' => $m->photoUrl()])
                <span class="min-w-0">
                    <span class="block text-sm font-semibold leading-tight truncate">{{ $m->full_name }}</span>
                    <span class="block text-[11px] text-ink-muted dark:text-slate-400 truncate">{{ $sub }}</span>
                </span>
            </span>
        @else
            <span class="flex flex-col items-center text-center">
                @include('archive.partials.avatar', ['name' => $m->full_name, 'size' => 'lg', 'photo' => $m->photoUrl()])
                <span class="mt-2 block font-bold leading-tight">{{ $m->full_name }}</span>
                <span class="mt-0.5 block text-xs text-ink-muted dark:text-slate-400 truncate max-w-full">{{ $m->position ?: 'No position set' }}</span>
                @if($node['isHead'])
                <span class="mt-2 inline-flex items-center gap-1 rounded-full px-2 py-0.5 text-[11px] font-semibold ring-1 ring-inset {{ $c['soft'] }}">★ Head · {{ $node['headOfTeam']->name }}</span>
                @elseif(! empty($node['children']))
                <span class="mt-2 text-[11px] text-ink-muted">{{ count($node['children']) }} under {{ explode(' ', trim($m->full_name))[0] }}</span>
                @endif
            </span>
        @endif
    </button>
    <template>@include('archive.partials.org-node-actions', ['node' => $node])</template>
</div>
