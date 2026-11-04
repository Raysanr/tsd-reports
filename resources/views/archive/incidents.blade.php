@extends('layouts.archive')
@section('title', 'Incidents & NTEs')
@section('subtitle', 'Notices to explain, warnings, outcomes and their files')
@php extract(\App\Support\ArchiveUi::classes()); @endphp

@push('topbar-right')
<button type="button" data-modal-open="incidentModal" data-title="New incident" data-action="{{ route('archive.incidents.store') }}" data-method="POST"
        data-fill="{{ json_encode(['employee_id' => $filters['person'], 'type' => 'nte', 'incident_date' => today()->toDateString()]) }}" class="{{ $btnPrimary }}">
    <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" d="M12 5v14M5 12h14"/></svg>
    New incident
</button>
@endpush

@section('content')
<div class="grid grid-cols-2 lg:grid-cols-4 gap-4 mb-6">
    @foreach([['Recorded · ' . now()->format('M'), $stats['month'], ''], ['NTEs · ' . now()->format('M'), $stats['ntes'], 'text-amber-600 dark:text-amber-400'], ['Recorded · ' . now()->year, $stats['year'], ''], ['Files attached', $stats['files'], 'text-primary dark:text-yellow-400']] as [$k, $v, $c])
    <div class="{{ $card }} p-5"><div class="text-xs text-ink-muted dark:text-slate-400">{{ $k }}</div><div class="mt-1 text-3xl font-bold tabular-nums {{ $c }}">{{ $v }}</div></div>
    @endforeach
</div>

<form method="GET" class="mb-5 flex flex-wrap items-center gap-3">
    <select name="type" onchange="this.form.submit()" class="{{ $filter }}"><option value="">All types</option>@foreach(\App\Models\Incident::TYPES as $k => $l)<option value="{{ $k }}" @selected($filters['type'] === $k)>{{ $l }}</option>@endforeach</select>
    <select name="team" onchange="this.form.submit()" class="{{ $filter }}"><option value="">All teams</option>@foreach($teams as $t)<option value="{{ $t->id }}" @selected($filters['team'] == $t->id)>{{ $t->name }}</option>@endforeach</select>
    <select name="person" onchange="this.form.submit()" class="{{ $filter }}"><option value="">Everyone</option>@foreach($employees as $e)<option value="{{ $e->id }}" @selected($filters['person'] == $e->id)>{{ $e->full_name }}</option>@endforeach</select>
</form>

<div class="{{ $card }} overflow-hidden">
    @if($incidents->isEmpty())
        @include('archive.partials.empty', ['title' => 'No incidents', 'text' => 'Nothing recorded for this filter.', 'icon' => 'M9 12.75L11.25 15 15 9.75M21 12a9 9 0 11-18 0 9 9 0 0118 0z'])
    @else
        @include('archive.partials.incident-list', ['incidents' => $incidents])
    @endif
</div>
<div class="mt-6">{{ $incidents->links() }}</div>

@include('archive.partials.incident-modal')
@endsection
