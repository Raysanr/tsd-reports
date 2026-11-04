@extends('layouts.archive')
@section('title', $employee->full_name)
@section('subtitle', $employee->position ?: 'Profile')
@php extract(\App\Support\ArchiveUi::classes()); $s = $employee->statusMeta(); @endphp

@push('topbar-right')
<a href="{{ route('archive.people') }}" class="{{ $btnGhost }}">← All people</a>
<button type="button" data-modal-open="uploadModal" data-fill='{"employee_id":"{{ $employee->id }}","department_id":"{{ $employee->department_id }}","category":"contract"}' class="{{ $btnPrimary }}">
    <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M3 16.5v2.25A2.25 2.25 0 005.25 21h13.5A2.25 2.25 0 0021 18.75V16.5m-13.5-9L12 3m0 0l4.5 4.5M12 3v13.5"/></svg>
    Upload file
</button>
@endpush

@section('content')
<div class="grid grid-cols-1 xl:grid-cols-3 gap-6">
    <div class="space-y-6">
        <section class="{{ $card }} overflow-hidden">
            <div class="h-24 bg-gradient-to-br from-yellow-500 via-amber-500 to-orange-500"></div>
            <div class="px-6 pb-6">
                <div class="-mt-10 flex items-end gap-3">
                    <form method="POST" action="{{ route('archive.people.photo.update', $employee) }}" enctype="multipart/form-data" class="relative group">
                        @csrf
                        <label class="block cursor-pointer rounded-full" title="Change profile photo">
                            @include('archive.partials.avatar', ['name' => $employee->full_name, 'size' => 'xl', 'photo' => $employee->photoUrl()])
                            <span class="absolute inset-0 flex items-center justify-center rounded-full bg-black/50 text-white opacity-0 group-hover:opacity-100 group-focus-within:opacity-100 transition">
                                <svg class="w-6 h-6" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M6.827 6.175A2.31 2.31 0 015.186 7.23c-.38.054-.757.112-1.134.175C2.999 7.58 2.25 8.507 2.25 9.574V18a2.25 2.25 0 002.25 2.25h15A2.25 2.25 0 0021.75 18V9.574c0-1.067-.75-1.994-1.802-2.169a47.865 47.865 0 00-1.134-.175 2.31 2.31 0 01-1.64-1.055l-.822-1.316a2.192 2.192 0 00-1.736-1.039 48.774 48.774 0 00-5.232 0 2.192 2.192 0 00-1.736 1.039l-.821 1.316z"/><path stroke-linecap="round" stroke-linejoin="round" d="M16.5 12.75a4.5 4.5 0 11-9 0 4.5 4.5 0 019 0z"/></svg>
                            </span>
                            <input type="file" name="photo" accept="image/jpeg,image/png,image/webp" class="sr-only" onchange="this.files.length && this.form.submit()" aria-label="Change profile photo">
                        </label>
                    </form>
                    @if($employee->photo_path)
                    <form method="POST" action="{{ route('archive.people.photo.destroy', $employee) }}" data-confirm="Remove {{ $employee->full_name }}'s profile photo?" class="mb-1">
                        @csrf @method('DELETE')
                        <button type="submit" class="text-xs font-medium text-ink-muted hover:text-rose-600 cursor-pointer">Remove photo</button>
                    </form>
                    @endif
                </div>
                <h2 class="mt-3 text-xl font-bold tracking-tight">{{ $employee->full_name }}</h2>
                <p class="text-sm text-ink-muted dark:text-slate-400">{{ $employee->position ?: 'No position set' }}</p>
                <div class="mt-3 flex flex-wrap gap-1.5">
                    <span class="rounded-full px-2.5 py-0.5 text-xs font-medium ring-1 ring-inset {{ $s['badge'] }}">{{ $s['label'] }}</span>
                    @if($employee->department)<span class="rounded-full px-2.5 py-0.5 text-xs font-medium ring-1 ring-inset {{ $employee->department->colorClasses()['soft'] }}">{{ $employee->department->name }}</span>@endif
                </div>
                <dl class="mt-6 space-y-3 text-sm">
                    @foreach([
                        ['Email', $employee->email ? '<a class="text-primary hover:underline" href="mailto:'.e($employee->email).'">'.e($employee->email).'</a>' : '—'],
                        ['Phone', e($employee->phone ?: '—')],
                        ['Reports to', $employee->manager ? '<a class="text-primary hover:underline" href="'.route('archive.people.show', $employee->manager).'">'.e($employee->manager->full_name).'</a>' : '—'],
                        ['Date hired', $employee->hired_at?->format('M j, Y') ?? '—'],
                        ['Tenure', $employee->hired_at ? $employee->hired_at->diffForHumans($employee->left_at ?? now(), ['syntax' => \Carbon\CarbonInterface::DIFF_ABSOLUTE, 'parts' => 2]) : '—'],
                        ['Date left', $employee->left_at?->format('M j, Y') ?? '—'],
                        ['App login', e($employee->user?->email ?? '—')],
                    ] as [$k, $v])
                    <div class="flex justify-between gap-3"><dt class="text-ink-muted dark:text-slate-400">{{ $k }}</dt><dd class="font-medium text-right truncate">{!! $v !!}</dd></div>
                    @endforeach
                </dl>
                @if($employee->notes)
                <div class="mt-6 rounded-xl bg-slate-50 dark:bg-slate-800/60 p-4 text-sm whitespace-pre-line">{{ $employee->notes }}</div>
                @endif
                <div class="mt-6 flex gap-2">
                    <button type="button" data-modal-open="employeeModal" data-title="Edit {{ $employee->full_name }}" data-method="PUT"
                            data-action="{{ route('archive.people.update', $employee) }}"
                            data-fill="{{ json_encode(array_merge($employee->only(['full_name','position','email','phone','status','department_id','manager_id','user_id','tsa_shift_id','notes']), ['hired_at' => $employee->hired_at?->toDateString(), 'left_at' => $employee->left_at?->toDateString(), 'photo_url' => $employee->photoUrl()])) }}"
                            class="{{ $btnGhost }} flex-1">Edit profile</button>
                </div>
            </div>
        </section>

        @if($employee->reports->isNotEmpty())
        <section class="{{ $card }} p-5">
            <h3 class="font-semibold">Direct reports <span class="font-normal text-ink-muted">({{ $employee->reports->count() }})</span></h3>
            <ul class="mt-3 space-y-1">
                @foreach($employee->reports as $r)
                <li><a href="{{ route('archive.people.show', $r) }}" class="flex items-center gap-3 rounded-lg p-2 -mx-2 hover:bg-slate-50 dark:hover:bg-slate-800/60">
                    @include('archive.partials.avatar', ['name' => $r->full_name, 'size' => 'sm', 'photo' => $r->photoUrl()])
                    <span class="text-sm font-medium">{{ $r->full_name }}</span><span class="ml-auto text-xs text-ink-muted">{{ $r->position }}</span>
                </a></li>
                @endforeach
            </ul>
        </section>
        @endif
    </div>

    <div class="xl:col-span-2 space-y-6">
        @if($live)
        <section class="{{ $card }} p-5 md:p-6">
            <div class="flex items-center justify-between">
                <h3 class="font-semibold flex items-center gap-2">
                    <span class="relative flex h-2.5 w-2.5"><span class="absolute inline-flex h-full w-full rounded-full bg-emerald-400 opacity-75 animate-ping"></span><span class="relative inline-flex h-2.5 w-2.5 rounded-full bg-emerald-500"></span></span>
                    Today as a TSA
                </h3>
                <a href="{{ route('archive.live-tsa') }}" class="text-sm font-medium text-primary dark:text-yellow-400 hover:underline">Scoreboard →</a>
            </div>
            <div class="mt-4 grid grid-cols-2 md:grid-cols-4 gap-3">
                @foreach([['Status', $live['status_label']], ['Leads', number_format($live['leads'])], ['Upsells', number_format($live['upsells'])], ['Sales', '₱' . number_format($live['sales'], 2)]] as [$k, $v])
                <div class="rounded-xl bg-slate-50 dark:bg-slate-800/60 px-4 py-3">
                    <div class="text-xs text-ink-muted dark:text-slate-400">{{ $k }}</div>
                    <div class="mt-1 text-lg font-bold tabular-nums truncate">{{ $v }}</div>
                </div>
                @endforeach
            </div>
        </section>
        @endif

        {{-- Lifecycle timeline --}}
        @php
            $tlMeta = \App\Models\EmployeeEvent::TYPES + [
                'coaching' => ['Coaching', 'bg-yellow-500', 'M4.26 10.147a60.436 60.436 0 00-.491 6.347A48.627 48.627 0 0112 20.904a48.627 48.627 0 018.232-4.41 60.46 60.46 0 00-.491-6.347m-15.482 0a50.57 50.57 0 00-2.658-.813A59.905 59.905 0 0112 3.493a59.902 59.902 0 0110.399 5.84c-.896.248-1.783.52-2.658.814m-15.482 0A50.697 50.697 0 0112 13.489a50.702 50.702 0 017.74-3.342'],
                'qa'       => ['QA',       'bg-sky-500',    'M9 12.75L11.25 15 15 9.75M21 12a9 9 0 11-18 0 9 9 0 0118 0z'],
                'incident' => ['Incident', 'bg-rose-500',   'M12 9v3.75m-9.303 3.376c-.866 1.5.217 3.374 1.948 3.374h14.71c1.73 0 2.813-1.874 1.948-3.374L13.949 3.378c-.866-1.5-3.032-1.5-3.898 0L2.697 16.126zM12 15.75h.007v.008H12v-.008z'],
                'document' => ['File',     'bg-stone-400',  'M19.5 14.25v-2.625a3.375 3.375 0 00-3.375-3.375h-1.5A1.125 1.125 0 0113.5 7.125v-1.5a3.375 3.375 0 00-3.375-3.375H8.25m2.25 0H5.625c-.621 0-1.125.504-1.125 1.125v17.25c0 .621.504 1.125 1.125 1.125h12.75c.621 0 1.125-.504 1.125-1.125V11.25a9 9 0 00-9-9z'],
            ];
        @endphp
        <section class="{{ $card }} overflow-hidden">
            <div class="flex items-center justify-between border-b border-line dark:border-slate-800 px-5 py-4">
                <h3 class="font-semibold">Lifecycle timeline</h3>
                <button type="button" data-modal-open="milestoneModal" data-fill="{{ json_encode(['occurred_on' => today()->toDateString()]) }}" class="text-sm font-semibold text-primary hover:underline cursor-pointer">+ Add milestone</button>
            </div>
            @if($timeline->isEmpty())
                <p class="px-5 py-8 text-center text-sm text-ink-muted dark:text-slate-400">No history yet.</p>
            @else
            <ol class="relative px-5 py-5 max-h-[34rem] overflow-y-auto">
                @foreach($timeline as $t)
                @php [$tLabel, $tDot, $tIcon] = $tlMeta[$t['type']] ?? $tlMeta['milestone']; @endphp
                <li class="relative flex gap-4 pb-5 last:pb-0">
                    @unless($loop->last)<span class="absolute left-[15px] top-8 bottom-0 w-px bg-line dark:bg-slate-800"></span>@endunless
                    <span class="relative z-10 flex w-8 h-8 shrink-0 items-center justify-center rounded-full {{ $tDot }} text-white ring-4 ring-white dark:ring-slate-900">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="{{ $tIcon }}"/></svg>
                    </span>
                    <div class="min-w-0 flex-1 pt-0.5">
                        <div class="flex flex-wrap items-baseline justify-between gap-x-3">
                            <p class="text-sm font-medium">{{ $t['title'] }}</p>
                            <time class="text-xs text-ink-muted dark:text-slate-400 tabular-nums whitespace-nowrap">{{ $t['date']->format('M j, Y') }}</time>
                        </div>
                        <p class="text-[11px] text-ink-muted dark:text-slate-400">{{ $tLabel }}@if($t['by']) · {{ $t['by'] }}@endif @if($t['details']) · {{ $t['details'] }}@endif</p>
                        @if($t['event_id'])
                        <form method="POST" action="{{ route('archive.people.events.destroy', [$employee, $t['event_id']]) }}" data-confirm="Remove this milestone?" class="mt-0.5">@csrf @method('DELETE')<button class="text-[11px] text-ink-muted hover:text-rose-600 cursor-pointer">Remove</button></form>
                        @endif
                    </div>
                </li>
                @endforeach
            </ol>
            @endif
        </section>

        {{-- Incidents --}}
        <section class="{{ $card }} overflow-hidden">
            <div class="flex items-center justify-between border-b border-line dark:border-slate-800 px-5 py-4">
                <h3 class="font-semibold">Incidents &amp; NTEs <span class="font-normal text-ink-muted">({{ $employee->incidents->count() }})</span></h3>
                <button type="button" data-modal-open="incidentModal" data-title="New incident for {{ $employee->full_name }}" data-action="{{ route('archive.incidents.store') }}" data-method="POST"
                        data-fill="{{ json_encode(['employee_id' => $employee->id, 'type' => 'nte', 'incident_date' => today()->toDateString()]) }}" class="text-sm font-semibold text-primary hover:underline cursor-pointer">+ Add</button>
            </div>
            @if($employee->incidents->isEmpty())
                <p class="px-5 py-8 text-center text-sm text-ink-muted dark:text-slate-400">Clean record — no incidents.</p>
            @else
                @include('archive.partials.incident-list', ['incidents' => $employee->incidents, 'showPerson' => false])
            @endif
        </section>

        {{-- Monthly history --}}
        @if($snapshots->isNotEmpty())
        <section class="{{ $card }} overflow-hidden">
            <div class="flex items-center justify-between border-b border-line dark:border-slate-800 px-5 py-4">
                <h3 class="font-semibold">Monthly history</h3>
                <a href="{{ route('archive.performance') }}" class="text-sm font-medium text-primary dark:text-yellow-400 hover:underline">All TSAs →</a>
            </div>
            <div class="overflow-x-auto">
                <table class="min-w-full text-sm">
                    <thead class="text-[11px] uppercase tracking-wider text-ink-muted dark:text-slate-400"><tr class="border-b border-line dark:border-slate-800">
                        <th class="px-5 py-2.5 text-left font-semibold">Month</th><th class="px-3 py-2.5 text-right font-semibold">Leads</th><th class="px-3 py-2.5 text-right font-semibold">Upsells</th><th class="px-3 py-2.5 text-right font-semibold">Conv.</th><th class="px-3 py-2.5 text-right font-semibold">Sales</th><th class="px-3 py-2.5 text-right font-semibold">Delivered</th><th class="px-5 py-2.5 text-right font-semibold">RTS</th>
                    </tr></thead>
                    <tbody class="divide-y divide-line dark:divide-slate-800">
                        @foreach($snapshots as $sn)
                        <tr>
                            <td class="px-5 py-2.5 font-medium whitespace-nowrap">{{ $sn->month->format('M Y') }}</td>
                            <td class="px-3 py-2.5 text-right tabular-nums">{{ number_format($sn->leads) }}</td>
                            <td class="px-3 py-2.5 text-right tabular-nums">{{ number_format($sn->upsells) }}</td>
                            <td class="px-3 py-2.5 text-right tabular-nums">{{ $sn->conversion_rate !== null ? $sn->conversion_rate . '%' : '—' }}</td>
                            <td class="px-3 py-2.5 text-right tabular-nums whitespace-nowrap">₱{{ number_format($sn->upsell_sales, 2) }}</td>
                            <td class="px-3 py-2.5 text-right tabular-nums whitespace-nowrap font-semibold text-emerald-700 dark:text-emerald-300">₱{{ number_format($sn->delivered, 2) }}</td>
                            <td class="px-5 py-2.5 text-right tabular-nums whitespace-nowrap text-rose-600 dark:text-rose-400">₱{{ number_format($sn->rts, 2) }}</td>
                        </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </section>
        @endif

    </div>
</div>

@include('archive.partials.employee-modal')
@include('archive.partials.upload-modal')
@include('archive.partials.incident-modal')

<dialog id="milestoneModal" class="{{ $modal }}">
    <form method="POST" action="{{ route('archive.people.events.store', $employee) }}">
        @csrf
        <div class="flex items-center justify-between border-b border-line dark:border-slate-800 px-6 py-4">
            <h3 class="font-semibold">Add milestone</h3>
            <button type="button" data-modal-close class="rounded-lg p-1.5 text-ink-muted hover:bg-slate-100 dark:hover:bg-slate-800 cursor-pointer" aria-label="Close"><svg class="w-5 h-5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" d="M6 18L18 6M6 6l12 12"/></svg></button>
        </div>
        <div class="space-y-4 px-6 py-5">
            <div><label class="{{ $label }}" for="ms_title">What happened *</label><input id="ms_title" name="title" required maxlength="200" class="{{ $input }}" placeholder="e.g. Completed product training / Top seller of September"></div>
            <div><label class="{{ $label }}" for="ms_date">Date *</label><input id="ms_date" type="date" name="occurred_on" required max="{{ today()->toDateString() }}" class="{{ $input }}"></div>
            <div><label class="{{ $label }}" for="ms_details">Details</label><textarea id="ms_details" name="details" rows="2" maxlength="2000" class="{{ $input }}"></textarea></div>
        </div>
        <div class="flex justify-end gap-2 border-t border-line dark:border-slate-800 bg-slate-50 dark:bg-slate-800/40 px-6 py-3 rounded-b-2xl">
            <button type="button" data-modal-close class="{{ $btnGhost }}">Cancel</button>
            <button type="submit" class="{{ $btnPrimary }}">Add</button>
        </div>
    </form>
</dialog>
@endsection
