{{-- Needs: $records (iterable of CoachingRecord), optional $showPerson (default true). --}}
@php $showPerson = $showPerson ?? true; @endphp
<ul class="divide-y divide-line dark:divide-slate-800">
    @foreach($records as $r)
    @php $t = \App\Models\CoachingRecord::TYPES[$r->type] ?? \App\Models\CoachingRecord::TYPES['coaching']; @endphp
    <li class="px-5 py-4">
        <div class="flex flex-wrap items-start gap-4">
            <div class="w-12 shrink-0 text-center">
                <div class="text-[10px] font-semibold uppercase text-ink-muted dark:text-slate-400">{{ $r->session_date->format('M') }}</div>
                <div class="text-xl font-bold leading-tight tabular-nums">{{ $r->session_date->format('j') }}</div>
                <div class="text-[10px] text-ink-muted dark:text-slate-400">{{ $r->session_date->format('Y') }}</div>
            </div>
            <div class="min-w-0 flex-1">
                <div class="flex flex-wrap items-center gap-2">
                    <span class="rounded-full px-2 py-0.5 text-[11px] font-semibold ring-1 ring-inset {{ $t['badge'] }}">{{ $t['label'] }}</span>
                    @if($r->type === 'qa' && $r->score !== null)<span class="rounded-full px-2 py-0.5 text-[11px] font-bold tabular-nums {{ $r->scoreClasses() }}">{{ rtrim(rtrim(number_format($r->score, 1), '0'), '.') }}%</span>@endif
                    @if($r->acknowledged_at)
                        <span class="inline-flex items-center gap-1 text-[11px] font-medium text-emerald-700 dark:text-emerald-300"><svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M4.5 12.75l6 6 9-13.5"/></svg>Acknowledged</span>
                    @else
                        <span class="text-[11px] font-medium text-amber-700 dark:text-amber-300">Awaiting acknowledgment</span>
                    @endif
                </div>
                <h4 class="mt-1 font-semibold">{{ $r->topic }}</h4>
                <p class="text-xs text-ink-muted dark:text-slate-400">
                    @if($showPerson)<a href="{{ route('archive.people.show', $r->employee) }}" class="font-medium text-ink dark:text-slate-200 hover:text-primary">{{ $r->employee->full_name }}</a> · @endif
                    by {{ $r->coach?->name ?? 'Unknown' }}@if($r->reference) · ref {{ $r->reference }}@endif
                </p>
                @if($r->notes)<p class="mt-2 text-sm whitespace-pre-line">{{ $r->notes }}</p>@endif
                @if($r->action_plan)<div class="mt-2 rounded-lg bg-yellow-50/70 dark:bg-yellow-500/5 ring-1 ring-inset ring-yellow-100 dark:ring-yellow-500/20 px-3 py-2 text-sm"><span class="font-semibold text-primary-dark dark:text-yellow-300">Action plan:</span> <span class="whitespace-pre-line">{{ $r->action_plan }}</span></div>@endif
            </div>
            <div class="flex shrink-0 items-center gap-1">
                <form method="POST" action="{{ route('archive.coaching.acknowledge', $r) }}">@csrf @method('PATCH')
                    <button type="submit" class="rounded-lg px-2.5 py-1.5 text-xs font-medium ring-1 ring-inset ring-line dark:ring-slate-700 hover:bg-slate-50 dark:hover:bg-slate-800 cursor-pointer">{{ $r->acknowledged_at ? 'Undo ack' : 'Mark acknowledged' }}</button>
                </form>
                <button type="button" title="Edit" aria-label="Edit record"
                        data-modal-open="coachingModal" data-title="Edit record" data-method="PUT" data-action="{{ route('archive.coaching.update', $r) }}"
                        data-fill="{{ json_encode(array_merge($r->only(['employee_id', 'type', 'topic', 'reference', 'score', 'notes', 'action_plan']), ['session_date' => $r->session_date->toDateString()])) }}"
                        class="rounded-lg p-2 text-ink-muted hover:bg-slate-100 hover:text-ink dark:hover:bg-slate-800 cursor-pointer">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M16.862 4.487l1.687-1.688a1.875 1.875 0 112.652 2.652L10.582 16.07a4.5 4.5 0 01-1.897 1.13L6 18l.8-2.685a4.5 4.5 0 011.13-1.897l8.932-8.931z"/></svg>
                </button>
                <form method="POST" action="{{ route('archive.coaching.destroy', $r) }}" data-confirm="Delete this {{ strtolower($t['label']) }} record? This can't be undone.">@csrf @method('DELETE')
                    <button type="submit" title="Delete" aria-label="Delete record" class="rounded-lg p-2 text-ink-muted hover:bg-rose-50 hover:text-rose-600 dark:hover:bg-rose-500/10 cursor-pointer">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M14.74 9l-.346 9m-4.788 0L9.26 9m9.968-3.21c.342.052.682.107 1.022.166m-1.022-.165L18.16 19.673a2.25 2.25 0 01-2.244 2.077H8.084a2.25 2.25 0 01-2.244-2.077L4.772 5.79m14.456 0a48.108 48.108 0 00-3.478-.397m-12 .562c.34-.059.68-.114 1.022-.165m0 0a48.11 48.11 0 013.478-.397m7.5 0v-.916c0-1.18-.91-2.164-2.09-2.201a51.964 51.964 0 00-3.32 0c-1.18.037-2.09 1.022-2.09 2.201v.916m7.5 0a48.667 48.667 0 00-7.5 0"/></svg>
                    </button>
                </form>
            </div>
        </div>
    </li>
    @endforeach
</ul>
