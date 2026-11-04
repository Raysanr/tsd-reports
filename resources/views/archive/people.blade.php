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

@include('archive.partials.employee-modal')
@endsection
