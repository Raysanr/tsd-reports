{{-- The plain-button menu shown when a card on the Org Chart is clicked.
     Needs $node (from OrgStructure::build()). Rendered inside a <template>. --}}
@php
    $m = $node['model'];
    $row = 'flex w-full items-center gap-3 rounded-xl px-3 py-2.5 text-left text-sm font-medium hover:bg-slate-100 dark:hover:bg-slate-800 cursor-pointer';
    $icon = fn ($d) => '<svg class="w-5 h-5 shrink-0 text-ink-muted" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="' . $d . '"/></svg>';
    $plus = 'M12 4.5v15m7.5-7.5h-15';
    $first = fn ($name) => explode(' ', trim($name))[0];
@endphp
<div class="space-y-1">
@if($node['type'] === 'person')
    @php $team = $m->department; $isHead = $node['isHead']; @endphp
    <button type="button" class="{{ $row }}" data-modal-open="employeeModal" data-title="Add person under {{ $m->full_name }}" data-action="{{ route('archive.people.store') }}" data-method="POST"
            data-fill="{{ json_encode(['status' => 'active', 'department_id' => $node['headOfTeam']?->id ?? $m->department_id, 'manager_id' => $m->id]) }}">
        {!! $icon($plus) !!} Add a person under {{ $first($m->full_name) }}
    </button>
    <button type="button" class="{{ $row }}" data-modal-open="moveModal" data-title="Move {{ $m->full_name }}" data-action="{{ route('archive.people.move', $m) }}" data-self-id="{{ $m->id }}"
            data-fill="{{ json_encode(['department_id' => $m->department_id, 'manager_id' => $m->manager_id]) }}">
        {!! $icon('M7.5 21L3 16.5m0 0L7.5 12M3 16.5h13.5m0-13.5L21 7.5m0 0L16.5 12M21 7.5H7.5') !!} Move to another team or manager…
    </button>
    @if($team && ! $isHead)
    <form method="POST" action="{{ route('archive.departments.head', $team) }}">
        @csrf <input type="hidden" name="employee_id" value="{{ $m->id }}">
        <button type="submit" class="{{ $row }}">{!! $icon('M11.48 3.499a.562.562 0 011.04 0l2.125 5.111a.563.563 0 00.475.345l5.518.442c.499.04.701.663.321.988l-4.204 3.602a.563.563 0 00-.182.557l1.285 5.385a.562.562 0 01-.84.61l-4.725-2.885a.563.563 0 00-.586 0L6.982 20.54a.562.562 0 01-.84-.61l1.285-5.386a.562.562 0 00-.182-.557l-4.204-3.602a.563.563 0 01.321-.988l5.518-.442a.563.563 0 00.475-.345L11.48 3.5z') !!} Make head of {{ $team->name }}</button>
    </form>
    @endif
    <button type="button" class="{{ $row }}" data-modal-open="employeeModal" data-title="Edit {{ $m->full_name }}" data-method="PUT" data-action="{{ route('archive.people.update', $m) }}" data-self-id="{{ $m->id }}"
            data-fill="{{ json_encode(array_merge($m->only(['full_name','position','email','phone','status','department_id','manager_id','user_id','tsa_shift_id','notes']), ['hired_at' => $m->hired_at?->toDateString(), 'left_at' => $m->left_at?->toDateString(), 'photo_url' => $m->photoUrl()])) }}">
        {!! $icon('M16.862 4.487l1.687-1.688a1.875 1.875 0 112.652 2.652L10.582 16.07a4.5 4.5 0 01-1.897 1.13L6 18l.8-2.685a4.5 4.5 0 011.13-1.897l8.932-8.931z') !!} Edit details
    </button>
    <a href="{{ route('archive.people.show', $m) }}" class="{{ $row }}">{!! $icon('M15.75 6a3.75 3.75 0 11-7.5 0 3.75 3.75 0 017.5 0zM4.501 20.118a7.5 7.5 0 0114.998 0A17.933 17.933 0 0112 21.75c-2.676 0-5.216-.584-7.499-1.632z') !!} Open profile</a>
    @if($m->department_id || $isHead)
    <form method="POST" action="{{ route('archive.people.remove-from-team', $m) }}" data-confirm="Take {{ $m->full_name }} out of {{ $team?->name ?? 'the chart' }}? They stay in People; anyone under them moves up one level.">
        @csrf @method('PATCH')
        <button type="submit" class="{{ $row }} text-rose-600 hover:bg-rose-50 dark:hover:bg-rose-500/10">{!! $icon('M22 10.5h-6m-2.25-4.125a3.375 3.375 0 11-6.75 0 3.375 3.375 0 016.75 0zM4 19.235v-.11a6.375 6.375 0 0112.75 0v.109A12.318 12.318 0 0110.374 21c-2.331 0-4.512-.645-6.374-1.766z') !!} Remove from team</button>
    </form>
    @endif
@else
    @php $head = $m->head; @endphp
    <button type="button" class="{{ $row }}" data-pick="members" data-team="{{ $m->id }}" data-action="{{ route('archive.departments.members', $m) }}" data-title="Add people to {{ $m->name }}" data-hint="Tick everyone who belongs in this team. They'll report to {{ $head?->full_name ?? 'the team head' }}.">
        {!! $icon('M18 7.5v3m0 0v3m0-3h3m-3 0h-3m-2.25-4.125a3.375 3.375 0 11-6.75 0 3.375 3.375 0 016.75 0zM3 19.235v-.11a6.375 6.375 0 0112.75 0v.109A12.318 12.318 0 019.374 21c-2.331 0-4.512-.645-6.374-1.766z') !!} Add people to this team
    </button>
    <button type="button" class="{{ $row }}" data-pick="head" data-team="{{ $m->id }}" data-current="{{ $m->head_employee_id }}" data-action="{{ route('archive.departments.head', $m) }}" data-title="{{ $head ? 'Change' : 'Pick' }} the head of {{ $m->name }}" data-hint="People already in this team are listed first.">
        {!! $icon('M11.48 3.499a.562.562 0 011.04 0l2.125 5.111a.563.563 0 00.475.345l5.518.442c.499.04.701.663.321.988l-4.204 3.602a.563.563 0 00-.182.557l1.285 5.385a.562.562 0 01-.84.61l-4.725-2.885a.563.563 0 00-.586 0L6.982 20.54a.562.562 0 01-.84-.61l1.285-5.386a.562.562 0 00-.182-.557l-4.204-3.602a.563.563 0 01.321-.988l5.518-.442a.563.563 0 00.475-.345L11.48 3.5z') !!} {{ $head ? 'Change head' : 'Pick a head' }}
    </button>
    <button type="button" class="{{ $row }}" data-modal-open="employeeModal" data-title="New person in {{ $m->name }}" data-action="{{ route('archive.people.store') }}" data-method="POST"
            data-fill="{{ json_encode(['status' => 'active', 'department_id' => $m->id, 'manager_id' => $m->head_employee_id]) }}">
        {!! $icon($plus) !!} Add a new person (not in the list yet)
    </button>
    <button type="button" class="{{ $row }}" data-modal-open="deptModal" data-title="New team under {{ $m->name }}" data-action="{{ route('archive.departments.store') }}" data-method="POST" data-fill="{{ json_encode(['color' => $m->color ?: 'sky', 'parent_id' => $m->id]) }}">
        {!! $icon('M2.25 21h19.5m-18-18v18m10.5-18v18m6-13.5V21M3 3h12m-.75 4.5H21') !!} Add a sub-team
    </button>
    <button type="button" class="{{ $row }}" data-modal-open="deptModal" data-title="Edit team" data-method="PUT" data-action="{{ route('archive.departments.update', $m) }}" data-self="{{ $m->id }}"
            data-fill="{{ json_encode($m->only(['name', 'code', 'description', 'color', 'parent_id', 'head_employee_id', 'sort_order']) + ['under_employee_ids[]' => $m->under_employee_ids ?? []]) }}">
        {!! $icon('M16.862 4.487l1.687-1.688a1.875 1.875 0 112.652 2.652L10.582 16.07a4.5 4.5 0 01-1.897 1.13L6 18l.8-2.685a4.5 4.5 0 011.13-1.897l8.932-8.931z') !!} Edit team (name, color…)
    </button>
    <form method="POST" action="{{ route('archive.departments.archive', $m) }}" data-confirm="Archive team &quot;{{ $m->name }}&quot;? Its people stay in People; you can restore it from Archived Records.">
        @csrf @method('PATCH')
        <button type="submit" class="{{ $row }} text-rose-600 hover:bg-rose-50 dark:hover:bg-rose-500/10">{!! $icon('M20.25 7.5l-.625 10.632a2.25 2.25 0 01-2.247 2.118H6.622a2.25 2.25 0 01-2.247-2.118L3.75 7.5M10 11.25h4M3.375 7.5h17.25c.621 0 1.125-.504 1.125-1.125v-1.5c0-.621-.504-1.125-1.125-1.125H3.375c-.621 0-1.125.504-1.125 1.125v1.5c0 .621.504 1.125 1.125 1.125z') !!} Archive team</button>
    </form>
@endif
</div>
