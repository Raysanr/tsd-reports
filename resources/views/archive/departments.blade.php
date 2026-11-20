@extends('layouts.archive')
@section('title', 'Teams')
@section('subtitle', 'Pick each team\'s head and members here — the Org Chart updates by itself')
@php extract(\App\Support\ArchiveUi::classes()); @endphp

@push('topbar-right')
<button type="button" id="newTeamBtn" data-modal-open="deptModal" data-title="New team" data-action="{{ route('archive.departments.store') }}" data-method="POST" data-fill='{"color":"sky"}' class="{{ $btnPrimary }}">
    <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" d="M12 5v14M5 12h14"/></svg>
    New team
</button>
@endpush

@section('content')
@include('archive.partials.setup-guide')
<form method="GET" class="mb-6 flex flex-wrap items-center gap-3">
    <div class="relative w-full sm:w-80">
        <svg class="pointer-events-none absolute left-3 top-1/2 -translate-y-1/2 w-4 h-4 text-slate-400" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" d="M21 21l-5.197-5.197m0 0A7.5 7.5 0 105.196 5.196a7.5 7.5 0 0010.607 10.607z"/></svg>
        <input type="search" name="q" value="{{ $q }}" placeholder="Search teams…" class="{{ $input }} pl-9">
    </div>
    <span class="text-sm text-ink-muted dark:text-slate-400">{{ $teamCards->count() }} active</span>
    @if($noTeam)<a href="{{ route('archive.org-chart') }}" class="ml-auto text-sm font-medium text-amber-700 dark:text-amber-300 hover:underline">{{ $noTeam }} {{ \Illuminate\Support\Str::plural('person', $noTeam) }} not in a team yet →</a>@endif
</form>

@if($teamCards->isEmpty())
<div class="{{ $card }}">
    @include('archive.partials.empty', ['title' => $q ? 'No matches' : 'No teams yet', 'text' => $q ? 'Try a different search.' : 'Start with a top-level team (e.g. "TSD Telesales"), then add sub-teams underneath it.', 'icon' => 'M2.25 21h19.5m-18-18v18m10.5-18v18m6-13.5V21M3 3h12m-.75 4.5H21'])
</div>
@else
<div class="grid grid-cols-1 md:grid-cols-2 2xl:grid-cols-3 gap-5">
    @foreach($teamCards as $d)
    @php $c = $d->colorClasses(); @endphp
    <article class="{{ $card }} group relative overflow-hidden transition hover:shadow-panel-lift">
        <div class="h-1.5 bg-gradient-to-r {{ $c['bar'] }}"></div>
        <div class="p-5">
            <div class="flex items-start justify-between gap-3">
                <div class="min-w-0">
                    <div class="flex items-center gap-2">
                        <h3 class="font-semibold truncate">{{ $d->name }}</h3>
                        @if($d->code)<span class="rounded-md px-1.5 py-0.5 text-[10px] font-bold uppercase tracking-wider ring-1 ring-inset {{ $c['soft'] }}">{{ $d->code }}</span>@endif
                    </div>
                    <p class="mt-0.5 text-xs text-ink-muted dark:text-slate-400">
                        @if($d->parent) Under <span class="font-medium text-ink dark:text-slate-200">{{ $d->parent->name }}</span> @else Top-level @endif
                    </p>
                </div>
                <div class="flex shrink-0 items-center gap-1 opacity-100 md:opacity-0 md:group-hover:opacity-100 md:focus-within:opacity-100 transition">
                    <button type="button" title="Edit" aria-label="Edit {{ $d->name }}"
                            data-modal-open="deptModal" data-title="Edit team" data-method="PUT"
                            data-action="{{ route('archive.departments.update', $d) }}"
                            data-fill="{{ json_encode($d->only(['name', 'code', 'description', 'color', 'parent_id', 'head_employee_id', 'sort_order'])) }}"
                            data-self="{{ $d->id }}"
                            class="rounded-lg p-2 text-ink-muted hover:bg-slate-100 hover:text-ink dark:hover:bg-slate-800 cursor-pointer">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M16.862 4.487l1.687-1.688a1.875 1.875 0 112.652 2.652L10.582 16.07a4.5 4.5 0 01-1.897 1.13L6 18l.8-2.685a4.5 4.5 0 011.13-1.897l8.932-8.931z"/></svg>
                    </button>
                    <form method="POST" action="{{ route('archive.departments.archive', $d) }}" data-confirm="Archive team &quot;{{ $d->name }}&quot;? Its people stay in the directory; you can restore it from Archived Records.">
                        @csrf @method('PATCH')
                        <button type="submit" title="Archive" aria-label="Archive {{ $d->name }}" class="rounded-lg p-2 text-ink-muted hover:bg-rose-50 hover:text-rose-600 dark:hover:bg-rose-500/10 cursor-pointer">
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M20.25 7.5l-.625 10.632a2.25 2.25 0 01-2.247 2.118H6.622a2.25 2.25 0 01-2.247-2.118L3.75 7.5M10 11.25h4M3.375 7.5h17.25c.621 0 1.125-.504 1.125-1.125v-1.5c0-.621-.504-1.125-1.125-1.125H3.375c-.621 0-1.125.504-1.125 1.125v1.5c0 .621.504 1.125 1.125 1.125z"/></svg>
                        </button>
                    </form>
                </div>
            </div>

            @if($d->description)<p class="mt-3 text-sm text-ink-muted dark:text-slate-400 line-clamp-2">{{ $d->description }}</p>@endif

            {{-- Head: one click to pick or change (2026-11-20) --}}
            <div class="mt-4 flex items-center gap-3 rounded-xl {{ $d->head ? 'bg-slate-50 dark:bg-slate-800/60' : 'bg-amber-50 dark:bg-amber-500/10 ring-1 ring-inset ring-amber-200 dark:ring-amber-500/30' }} p-3">
                @if($d->head)
                    @include('archive.partials.avatar', ['name' => $d->head->full_name, 'photo' => $d->head->photoUrl()])
                    <div class="min-w-0 flex-1">
                        <a href="{{ route('archive.people.show', $d->head) }}" class="block text-sm font-medium truncate hover:text-primary">{{ $d->head->full_name }}</a>
                        <div class="text-xs text-ink-muted dark:text-slate-400">★ {{ $d->head->position ?: 'Head' }}</div>
                    </div>
                @else
                    <span class="w-9 h-9 shrink-0 rounded-full border-2 border-dashed border-amber-300 dark:border-amber-500/50"></span>
                    <span class="flex-1 text-sm font-medium text-amber-800 dark:text-amber-300">No head yet</span>
                @endif
                <button type="button" data-pick="head" data-team="{{ $d->id }}" data-current="{{ $d->head_employee_id }}" data-action="{{ route('archive.departments.head', $d) }}"
                        data-title="{{ $d->head ? 'Change' : 'Pick' }} the head of {{ $d->name }}" data-hint="People already in this team are listed first."
                        class="shrink-0 rounded-lg px-2.5 py-1.5 text-xs font-semibold {{ $d->head ? 'ring-1 ring-inset ring-line dark:ring-slate-700 bg-white dark:bg-slate-900 hover:bg-slate-50' : 'bg-primary text-white hover:bg-primary-dark' }} cursor-pointer">{{ $d->head ? 'Change' : 'Pick head' }}</button>
            </div>

            {{-- Members --}}
            @php $members = $d->employees->reject(fn ($e) => $e->id === $d->head_employee_id); @endphp
            <div class="mt-4">
                <div class="flex items-center justify-between">
                    <span class="text-[11px] font-semibold uppercase tracking-wider text-ink-muted dark:text-slate-400">Members · {{ $members->count() }}</span>
                    @if($members->count() > 8)<a href="{{ route('archive.people', ['department' => $d->id]) }}" class="text-xs font-medium text-primary hover:underline">See all</a>@endif
                </div>
                @if($members->isEmpty())
                    <p class="mt-2 text-sm text-ink-muted dark:text-slate-400">Nobody in this team yet.</p>
                @else
                <div class="mt-2 flex flex-wrap gap-1.5">
                    @foreach($members->take(8) as $mm)
                    <a href="{{ route('archive.people.show', $mm) }}" class="inline-flex items-center gap-1.5 rounded-full bg-slate-50 dark:bg-slate-800 py-0.5 pl-0.5 pr-2.5 text-xs font-medium ring-1 ring-inset ring-line dark:ring-slate-700 hover:ring-yellow-300" title="{{ $mm->full_name }}">
                        @include('archive.partials.avatar', ['name' => $mm->full_name, 'size' => 'sm', 'photo' => $mm->photoUrl()]){{ \Illuminate\Support\Str::limit($mm->full_name, 18) }}
                    </a>
                    @endforeach
                    @if($members->count() > 8)<span class="inline-flex items-center rounded-full bg-slate-100 dark:bg-slate-800 px-2.5 text-xs font-semibold text-ink-muted">+{{ $members->count() - 8 }} more</span>@endif
                </div>
                @endif
                <div class="mt-3 flex flex-wrap gap-2">
                    <button type="button" data-pick="members" data-team="{{ $d->id }}" data-action="{{ route('archive.departments.members', $d) }}" data-title="Add people to {{ $d->name }}"
                            data-hint="Tick everyone who belongs in this team. They'll report to {{ $d->head?->full_name ?? 'the team head' }}."
                            class="inline-flex items-center gap-1.5 rounded-lg bg-primary/10 px-2.5 py-1.5 text-xs font-semibold text-primary hover:bg-primary/20 cursor-pointer">+ Add member</button>
                    <button type="button" data-modal-open="employeeModal" data-title="New person in {{ $d->name }}" data-action="{{ route('archive.people.store') }}" data-method="POST"
                            data-fill="{{ json_encode(['status' => 'active', 'department_id' => $d->id, 'manager_id' => $d->head_employee_id]) }}"
                            class="inline-flex items-center gap-1.5 rounded-lg px-2.5 py-1.5 text-xs font-medium ring-1 ring-inset ring-line dark:ring-slate-700 hover:bg-slate-50 dark:hover:bg-slate-800 cursor-pointer">New person</button>
                </div>
            </div>

            <dl class="mt-4 grid grid-cols-3 divide-x border-t border-line dark:border-slate-800 pt-3 divide-line dark:divide-slate-800 text-center">
                <a href="{{ route('archive.people', ['department' => $d->id]) }}" class="hover:text-primary"><dt class="text-[11px] uppercase tracking-wider text-ink-muted dark:text-slate-400">People</dt><dd class="mt-0.5 text-lg font-bold tabular-nums">{{ $d->employees_count }}</dd></a>
                <div><dt class="text-[11px] uppercase tracking-wider text-ink-muted dark:text-slate-400">Sub-teams</dt><dd class="mt-0.5 text-lg font-bold tabular-nums">{{ $d->children_count }}</dd></div>
                <a href="{{ route('archive.documents', ['department' => $d->id]) }}" class="hover:text-primary"><dt class="text-[11px] uppercase tracking-wider text-ink-muted dark:text-slate-400">Files</dt><dd class="mt-0.5 text-lg font-bold tabular-nums">{{ $d->documents_count }}</dd></a>
            </dl>
        </div>
    </article>
    @endforeach
</div>
@endif

@include('archive.partials.team-modal')
@include('archive.partials.org-tools')
@include('archive.partials.employee-modal')
@endsection

@push('scripts')
<script>window.addEventListener('load', () => { if (location.hash === '#new') document.getElementById('newTeamBtn')?.click(); });</script>
@endpush

