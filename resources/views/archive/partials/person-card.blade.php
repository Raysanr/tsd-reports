{{-- One person card on the People page. Needs: $e; optional $muted (former people). --}}
@php $s = $e->statusMeta(); $muted = $muted ?? false; @endphp
    <article class="{{ $card }} group relative p-5 transition hover:-translate-y-0.5 hover:shadow-panel-lift {{ $muted ? 'opacity-75 hover:opacity-100' : '' }}">
        <div class="flex items-start gap-4">
            @include('archive.partials.avatar', ['name' => $e->full_name, 'size' => 'lg', 'photo' => $e->photoUrl()])
            <div class="min-w-0 flex-1">
                <a href="{{ route('archive.people.show', $e) }}" class="font-semibold hover:text-primary truncate block after:absolute after:inset-0">{{ $e->full_name }}</a>
                <p class="text-sm text-ink-muted dark:text-slate-400 truncate">{{ $e->position ?: 'No position set' }}</p>
                <div class="mt-2 flex flex-wrap items-center gap-1.5">
                    <span class="rounded-full px-2 py-0.5 text-[11px] font-medium ring-1 ring-inset {{ $s['badge'] }}">{{ $s['label'] }}</span>
                    @if($e->tsa)<span class="rounded-full px-2 py-0.5 text-[11px] font-medium ring-1 ring-inset bg-yellow-50 text-yellow-700 ring-yellow-200 dark:bg-yellow-500/10 dark:text-yellow-300 dark:ring-yellow-500/30">TSA</span>@endif
                </div>
            </div>
            <button type="button" title="Edit" aria-label="Edit {{ $e->full_name }}"
                    data-modal-open="employeeModal" data-title="Edit {{ $e->full_name }}" data-method="PUT"
                    data-action="{{ route('archive.people.update', $e) }}"
                    data-fill="{{ json_encode(array_merge($e->only(['full_name','position','email','phone','status','department_id','manager_id','user_id','tsa_shift_id','notes']), ['hired_at' => $e->hired_at?->toDateString(), 'left_at' => $e->left_at?->toDateString(), 'photo_url' => $e->photoUrl()])) }}"
                    class="relative z-10 rounded-lg p-2 text-ink-muted hover:bg-slate-100 hover:text-ink dark:hover:bg-slate-800 cursor-pointer">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M16.862 4.487l1.687-1.688a1.875 1.875 0 112.652 2.652L10.582 16.07a4.5 4.5 0 01-1.897 1.13L6 18l.8-2.685a4.5 4.5 0 011.13-1.897l8.932-8.931z"/></svg>
            </button>
        </div>
        <dl class="mt-4 space-y-1.5 border-t border-line dark:border-slate-800 pt-4 text-xs">
            <div class="flex justify-between gap-2"><dt class="text-ink-muted dark:text-slate-400">Team</dt><dd class="font-medium truncate">@if($e->department)<span class="inline-flex items-center gap-1.5"><span class="h-2 w-2 rounded-full {{ $e->department->colorClasses()['dot'] }}"></span>{{ $e->department->name }}</span>@else — @endif</dd></div>
            <div class="flex justify-between gap-2"><dt class="text-ink-muted dark:text-slate-400">Reports to</dt><dd class="font-medium truncate">{{ $e->manager?->full_name ?? '—' }}</dd></div>
            <div class="flex justify-between gap-2"><dt class="text-ink-muted dark:text-slate-400">Files</dt><dd class="font-medium tabular-nums">{{ $e->documents_count }}</dd></div>
        </dl>
    </article>
