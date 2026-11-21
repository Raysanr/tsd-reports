@extends('layouts.archive')
@section('title', 'People')
@section('subtitle', 'TSD manpower — every cell saves as you change it')
@php extract(\App\Support\ArchiveUi::classes()); @endphp

@push('topbar-right')
<form method="POST" action="{{ route('archive.people.sync') }}">@csrf
    <button type="submit" class="{{ $btnGhost }}" title="Add any new TSAs from TSA Management">
        <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M16.023 9.348h4.992v-.001M2.985 19.644v-4.992m0 0h4.992m-4.993 0l3.181 3.183a8.25 8.25 0 0013.803-3.7M4.031 9.865a8.25 8.25 0 0113.803-3.7l3.181 3.182m0-4.991v4.99"/></svg>
        Sync TSAs
    </button>
</form>
<button type="button" data-modal-open="employeeModal" data-title="Add person" data-action="{{ route('archive.people.store') }}" data-method="POST" data-fill='{"status":"active","department_id":"{{ $filters['department'] }}"}' class="{{ $btnPrimary }}">
    <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" d="M12 5v14M5 12h14"/></svg>
    Add person
</button>
@endpush

@section('content')
@include('archive.partials.setup-guide')
<form method="GET" class="mb-6 flex flex-wrap items-center gap-3">
    <div class="relative w-full sm:w-72">
        <svg class="pointer-events-none absolute left-3 top-1/2 -translate-y-1/2 w-4 h-4 text-slate-400" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" d="M21 21l-5.197-5.197m0 0A7.5 7.5 0 105.196 5.196a7.5 7.5 0 0010.607 10.607z"/></svg>
        <input type="search" name="q" value="{{ $filters['q'] }}" placeholder="Name, position, email…" class="{{ $input }} pl-9">
    </div>
    <select name="department" onchange="this.form.submit()" class="{{ $filter }}">
        <option value="">All teams</option>
        @foreach($departments as $d)<option value="{{ $d->id }}" @selected($filters['department'] == $d->id)>{{ $d->name }}</option>@endforeach
    </select>
    <div class="inline-flex rounded-lg bg-slate-100 dark:bg-slate-800 p-1 text-sm">
        @foreach(['' => 'Current', 'active' => 'Active', 'resigned' => 'Resigned'] as $k => $l)
        <button type="submit" name="status" value="{{ $k }}" class="rounded-md px-3 py-1.5 font-medium cursor-pointer {{ (string) $filters['status'] === (string) $k ? 'bg-white dark:bg-slate-700 shadow-sm text-ink dark:text-white' : 'text-ink-muted dark:text-slate-400 hover:text-ink' }}">{{ $l }}</button>
        @endforeach
    </div>
    <span class="ml-auto text-sm text-ink-muted dark:text-slate-400">{{ $employees->total() }} {{ \Illuminate\Support\Str::plural('person', $employees->total()) }}</span>
</form>

@if($employees->isEmpty())
<div class="{{ $card }}">
    @include('archive.partials.empty', ['title' => 'No people found', 'text' => 'Add people and assign them to teams to fill the org chart.', 'icon' => 'M15 19.128a9.38 9.38 0 002.625.372 9.337 9.337 0 004.121-.952 4.125 4.125 0 00-7.533-2.493M12 6.375a3.375 3.375 0 11-6.75 0 3.375 3.375 0 016.75 0z'])
</div>
@else
@include('archive.partials.manpower-table', ['rows' => $employees, 'title' => now()->year . ' Manpower'])
<div class="mt-6">{{ $employees->links() }}</div>
@endif

{{-- Former TSAs (Resigned) — shown under the current people on the default
     view so they're visible without switching to the Resigned tab
     (explicit request, 2026-10-20). --}}
@if($former->isNotEmpty())
<section class="mt-10">
    <div class="mb-4 flex flex-wrap items-baseline justify-between gap-2">
        <h2 class="text-sm font-semibold uppercase tracking-wider text-ink-muted dark:text-slate-400">Former TSAs <span class="ml-1 rounded-full bg-slate-200/70 dark:bg-slate-800 px-2 py-0.5 text-xs tabular-nums">{{ $former->count() }}</span></h2>
        <a href="{{ route('archive.people', array_filter(['status' => 'resigned', 'department' => $filters['department'], 'q' => $filters['q']])) }}" class="text-xs font-medium text-primary hover:underline">Show only former →</a>
    </div>
    @include('archive.partials.manpower-table', ['rows' => $former, 'title' => 'Former TSAs', 'muted' => true])
</section>
@endif

{{-- Bulk actions (2026-11-21): tick people, then act on all of them at once. --}}
<form id="bulkBar" method="POST" action="{{ route('archive.people.bulk') }}" hidden
      class="no-print fixed bottom-5 left-1/2 z-[60] -translate-x-1/2 flex flex-wrap items-center gap-2 rounded-2xl bg-white dark:bg-slate-900 px-4 py-3 shadow-2xl ring-1 ring-line dark:ring-slate-700 text-sm">
    @csrf
    <div data-bulk-ids></div>
    <span class="font-semibold"><span data-bulk-count>0</span> selected</span>
    <select name="action" data-bulk-action class="{{ $input }} w-auto py-1.5">
        <option value="team">Move to team…</option>
        <option value="manager">Set "Reports to"…</option>
        <option value="status">Set status…</option>
    </select>
    <select name="department_id" data-bulk-for="team" class="{{ $input }} w-auto py-1.5">
        <option value="">— No team —</option>
        @foreach($departments as $d)<option value="{{ $d->id }}">{{ $d->name }}</option>@endforeach
    </select>
    <select name="manager_id" data-bulk-for="manager" class="{{ $input }} w-auto py-1.5" hidden disabled>
        <option value="">— Nobody —</option>
        @foreach($managerOptions as $m)<option value="{{ $m->id }}">{{ $m->full_name }}</option>@endforeach
    </select>
    <select name="status" data-bulk-for="status" class="{{ $input }} w-auto py-1.5" hidden disabled>
        <option value="active">Active</option>
        <option value="resigned">Resigned (Former)</option>
    </select>
    <button type="submit" class="{{ $btnPrimary }} py-1.5">Apply</button>
    <button type="button" data-bulk-clear class="{{ $btnGhost }} py-1.5">Clear</button>
</form>

@include('archive.partials.employee-modal')
@endsection

@push('scripts')
<script>
(function () {
    const bar = document.getElementById('bulkBar');
    const boxes = () => [...document.querySelectorAll('[data-bulk]')];
    const refresh = () => {
        const ids = [...new Set(boxes().filter((b) => b.checked).map((b) => b.value))];
        bar.hidden = ids.length === 0;
        bar.querySelector('[data-bulk-count]').textContent = ids.length;
        bar.querySelector('[data-bulk-ids]').replaceChildren(...ids.map((id) => Object.assign(document.createElement('input'), { type: 'hidden', name: 'ids[]', value: id })));
    };
    document.addEventListener('change', (e) => {
        if (e.target.matches('[data-bulk-all]')) {
            e.target.closest('table').querySelectorAll('[data-bulk]').forEach((b) => { b.checked = e.target.checked; });
        }
        if (e.target.matches('[data-bulk]')) {
            // the same person appears in the table and the phone list: keep both in step
            document.querySelectorAll(`[data-bulk][value="${e.target.value}"]`).forEach((b) => { b.checked = e.target.checked; });
        }
        if (e.target.matches('[data-bulk-action]')) {
            bar.querySelectorAll('[data-bulk-for]').forEach((s) => { const on = s.dataset.bulkFor === e.target.value; s.hidden = !on; s.disabled = !on; });
        }
        if (e.target.matches('[data-bulk], [data-bulk-all]')) refresh();
    });
    bar.querySelector('[data-bulk-clear]').addEventListener('click', () => {
        document.querySelectorAll('[data-bulk], [data-bulk-all]').forEach((b) => { b.checked = false; });
        refresh();
    });
})();
</script>
@endpush
