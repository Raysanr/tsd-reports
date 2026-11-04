@extends('layouts.archive')
@section('title', 'Teams')
@section('subtitle', 'Telesales teams and sub-teams that power the org chart')
@php extract(\App\Support\ArchiveUi::classes()); @endphp

@push('topbar-right')
<button type="button" data-modal-open="deptModal" data-title="New team" data-action="{{ route('archive.departments.store') }}" data-method="POST" data-fill='{"color":"sky"}' class="{{ $btnPrimary }}">
    <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" d="M12 5v14M5 12h14"/></svg>
    New team
</button>
@endpush

@section('content')
<form method="GET" class="mb-6 flex flex-wrap items-center gap-3">
    <div class="relative w-full sm:w-80">
        <svg class="pointer-events-none absolute left-3 top-1/2 -translate-y-1/2 w-4 h-4 text-slate-400" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" d="M21 21l-5.197-5.197m0 0A7.5 7.5 0 105.196 5.196a7.5 7.5 0 0010.607 10.607z"/></svg>
        <input type="search" name="q" value="{{ $q }}" placeholder="Search teams…" class="{{ $input }} pl-9">
    </div>
    <span class="text-sm text-ink-muted dark:text-slate-400">{{ $departments->count() }} active</span>
</form>

@if($departments->isEmpty())
<div class="{{ $card }}">
    @include('archive.partials.empty', ['title' => $q ? 'No matches' : 'No teams yet', 'text' => $q ? 'Try a different search.' : 'Start with a top-level team (e.g. "TSD Telesales"), then add sub-teams underneath it.', 'icon' => 'M2.25 21h19.5m-18-18v18m10.5-18v18m6-13.5V21M3 3h12m-.75 4.5H21'])
</div>
@else
<div class="grid grid-cols-1 md:grid-cols-2 2xl:grid-cols-3 gap-5">
    @foreach($departments as $d)
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

            <div class="mt-4 flex items-center gap-3 rounded-xl bg-slate-50 dark:bg-slate-800/60 p-3">
                @if($d->head)
                    @include('archive.partials.avatar', ['name' => $d->head->full_name, 'photo' => $d->head->photoUrl()])
                    <div class="min-w-0">
                        <div class="text-sm font-medium truncate">{{ $d->head->full_name }}</div>
                        <div class="text-xs text-ink-muted dark:text-slate-400">Team lead</div>
                    </div>
                @else
                    <span class="w-9 h-9 rounded-full border-2 border-dashed border-slate-300 dark:border-slate-600"></span>
                    <span class="text-sm text-ink-muted dark:text-slate-400">No head assigned</span>
                @endif
            </div>

            <dl class="mt-4 grid grid-cols-3 divide-x divide-line dark:divide-slate-800 text-center">
                <a href="{{ route('archive.people', ['department' => $d->id]) }}" class="hover:text-primary"><dt class="text-[11px] uppercase tracking-wider text-ink-muted dark:text-slate-400">People</dt><dd class="mt-0.5 text-lg font-bold tabular-nums">{{ $d->employees_count }}</dd></a>
                <div><dt class="text-[11px] uppercase tracking-wider text-ink-muted dark:text-slate-400">Sub-teams</dt><dd class="mt-0.5 text-lg font-bold tabular-nums">{{ $d->children_count }}</dd></div>
                <a href="{{ route('archive.documents', ['department' => $d->id]) }}" class="hover:text-primary"><dt class="text-[11px] uppercase tracking-wider text-ink-muted dark:text-slate-400">Files</dt><dd class="mt-0.5 text-lg font-bold tabular-nums">{{ $d->documents_count }}</dd></a>
            </dl>
        </div>
    </article>
    @endforeach
</div>
@endif

<dialog id="deptModal" class="{{ $modal }}">
    <form method="POST" action="{{ route('archive.departments.store') }}">
        @csrf
        <input type="hidden" name="_method" value="POST" data-default="POST">
        <div class="flex items-center justify-between border-b border-line dark:border-slate-800 px-6 py-4">
            <h3 class="font-semibold" data-modal-title="New team">New team</h3>
            <button type="button" data-modal-close class="rounded-lg p-1.5 text-ink-muted hover:bg-slate-100 dark:hover:bg-slate-800 cursor-pointer" aria-label="Close"><svg class="w-5 h-5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" d="M6 18L18 6M6 6l12 12"/></svg></button>
        </div>
        <div class="space-y-4 px-6 py-5 max-h-[70vh] overflow-y-auto">
            <div class="grid grid-cols-3 gap-3">
                <div class="col-span-2"><label class="{{ $label }}" for="d_name">Name *</label><input id="d_name" name="name" required maxlength="120" class="{{ $input }}" placeholder="e.g. Telesales Team A"></div>
                <div><label class="{{ $label }}" for="d_code">Code</label><input id="d_code" name="code" maxlength="20" class="{{ $input }} uppercase" placeholder="TSA-A"></div>
            </div>
            <div>
                <label class="{{ $label }}" for="d_parent">Parent team</label>
                <select id="d_parent" name="parent_id" class="{{ $input }}">
                    <option value="">— None (top level) —</option>
                    @foreach($parentOptions as $p)<option value="{{ $p->id }}">{{ $p->name }}</option>@endforeach
                </select>
            </div>
            <div>
                <label class="{{ $label }}" for="d_head">Team lead</label>
                <select id="d_head" name="head_employee_id" class="{{ $input }}">
                    <option value="">— Not assigned —</option>
                    @foreach($employeeOptions as $e)<option value="{{ $e->id }}">{{ $e->full_name }}</option>@endforeach
                </select>
            </div>
            <div>
                <span class="{{ $label }}">Color</span>
                <div class="flex flex-wrap gap-2">
                    @foreach(\App\Models\Department::COLORS as $key => $cc)
                    <label class="cursor-pointer">
                        <input type="radio" name="color" value="{{ $key }}" class="peer sr-only" @checked($key === 'sky')>
                        <span class="block w-8 h-8 rounded-full {{ $cc['dot'] }} ring-2 ring-transparent ring-offset-2 ring-offset-white dark:ring-offset-slate-900 peer-checked:ring-slate-900 dark:peer-checked:ring-white peer-focus-visible:ring-yellow-500 transition" title="{{ ucfirst($key) }}"></span>
                    </label>
                    @endforeach
                </div>
            </div>
            <div><label class="{{ $label }}" for="d_desc">Description</label><textarea id="d_desc" name="description" rows="3" maxlength="2000" class="{{ $input }}" placeholder="What this team is responsible for"></textarea></div>
            <div class="w-32"><label class="{{ $label }}" for="d_sort">Sort order</label><input id="d_sort" type="number" min="0" name="sort_order" class="{{ $input }}" placeholder="0"></div>
        </div>
        <div class="flex justify-end gap-2 border-t border-line dark:border-slate-800 bg-slate-50 dark:bg-slate-800/40 px-6 py-3 rounded-b-2xl">
            <button type="button" data-modal-close class="{{ $btnGhost }}">Cancel</button>
            <button type="submit" class="{{ $btnPrimary }}">Save team</button>
        </div>
    </form>
</dialog>
@endsection

@push('scripts')
<script>
// A department can't be its own parent — hide itself from the parent list while editing.
document.addEventListener('click', (e) => {
    const opener = e.target.closest('[data-modal-open="deptModal"]');
    if (!opener) return;
    document.querySelectorAll('#d_parent option').forEach((o) => { o.hidden = !!opener.dataset.self && o.value === opener.dataset.self; });
}, true);
</script>
@endpush
