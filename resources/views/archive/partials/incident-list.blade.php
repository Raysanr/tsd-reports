{{-- Needs: $incidents, optional $showPerson (default true). --}}
@php $showPerson = $showPerson ?? true; @endphp
<ul class="divide-y divide-line dark:divide-slate-800">
    @foreach($incidents as $i)
    <li class="px-5 py-4">
        <div class="flex flex-wrap items-start gap-4">
            <div class="w-12 shrink-0 text-center">
                <div class="text-[10px] font-semibold uppercase text-ink-muted dark:text-slate-400">{{ $i->incident_date->format('M') }}</div>
                <div class="text-xl font-bold leading-tight tabular-nums">{{ $i->incident_date->format('j') }}</div>
                <div class="text-[10px] text-ink-muted dark:text-slate-400">{{ $i->incident_date->format('Y') }}</div>
            </div>
            <div class="min-w-0 flex-1">
                <div class="flex flex-wrap items-center gap-2">
                    <span class="rounded-md px-2 py-0.5 text-[11px] font-semibold {{ \App\Models\Incident::SEVERITY[$i->type] ?? '' }}">{{ \App\Models\Incident::TYPES[$i->type] }}</span>
                    @if($i->attachments->isNotEmpty())<span class="inline-flex items-center gap-1 text-[11px] text-ink-muted"><svg class="h-3 w-3" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M18.375 12.739l-7.693 7.693a4.5 4.5 0 01-6.364-6.364l10.94-10.94A3 3 0 1119.5 7.372L8.552 18.32"/></svg>{{ $i->attachments->count() }} {{ \Illuminate\Support\Str::plural('file', $i->attachments->count()) }}</span>@endif
                </div>
                <h4 class="mt-1 font-semibold">{{ $i->title }}</h4>
                @if($showPerson)<p class="text-xs text-ink-muted dark:text-slate-400"><a href="{{ route('archive.people.show', $i->employee) }}" class="font-medium text-ink dark:text-slate-200 hover:text-primary">{{ $i->employee->full_name }}</a>@if($i->employee->department) · {{ $i->employee->department->name }}@endif</p>@endif
                @if($i->description)<p class="mt-2 text-sm whitespace-pre-line">{{ $i->description }}</p>@endif
                @if($i->decision)<div class="mt-2 rounded-lg bg-emerald-50/70 dark:bg-emerald-500/5 ring-1 ring-inset ring-emerald-100 dark:ring-emerald-500/20 px-3 py-2 text-sm"><span class="font-semibold text-emerald-800 dark:text-emerald-300">Outcome:</span> <span class="whitespace-pre-line">{{ $i->decision }}</span></div>@endif
                @if($i->attachments->isNotEmpty())
                <div class="mt-3 flex flex-wrap gap-2">
                    @foreach($i->attachments as $doc)
                    @php [$typeLabel, $typeClass] = $doc->typeMeta(); @endphp
                    <div class="group/file inline-flex max-w-full items-center gap-2 rounded-xl bg-white dark:bg-slate-900 py-1.5 pl-1.5 pr-1 ring-1 ring-line dark:ring-slate-700">
                        <span class="flex h-8 w-8 shrink-0 items-center justify-center rounded-lg text-[10px] font-bold {{ $typeClass }}">{{ $typeLabel }}</span>
                        <a href="{{ $doc->isPreviewable() ? route('archive.documents.preview', $doc) : route('archive.documents.download', $doc) }}"
                           @if($doc->isPreviewable()) data-preview data-kind="{{ $doc->isImage() ? 'image' : 'pdf' }}" data-title="{{ $doc->title }}" data-meta="{{ $doc->original_name }} · {{ $doc->humanSize() }}" data-download="{{ route('archive.documents.download', $doc) }}" target="_blank" rel="noopener" @endif
                           class="min-w-0 hover:text-primary" title="{{ $doc->original_name }}">
                            <span class="block max-w-[14rem] truncate text-xs font-medium">{{ $doc->original_name }}</span>
                            <span class="block text-[10px] text-ink-muted">{{ $doc->humanSize() }}</span>
                        </a>
                        <a href="{{ route('archive.documents.download', $doc) }}" title="Download" aria-label="Download {{ $doc->original_name }}" class="rounded-md p-1.5 text-ink-muted hover:bg-slate-100 hover:text-ink dark:hover:bg-slate-800"><svg class="h-3.5 w-3.5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M3 16.5v2.25A2.25 2.25 0 005.25 21h13.5A2.25 2.25 0 0021 18.75V16.5M16.5 12L12 16.5m0 0L7.5 12m4.5 4.5V3"/></svg></a>
                        <form method="POST" action="{{ route('archive.documents.destroy', $doc) }}" data-confirm="Remove &quot;{{ $doc->original_name }}&quot; from this incident? It goes to Archived Records, where you can restore it.">@csrf @method('DELETE')
                            <button type="submit" title="Remove file" aria-label="Remove {{ $doc->original_name }}" class="rounded-md p-1.5 text-ink-muted hover:bg-rose-50 hover:text-rose-600 dark:hover:bg-rose-500/10 cursor-pointer"><svg class="h-3.5 w-3.5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" d="M6 18L18 6M6 6l12 12"/></svg></button>
                        </form>
                    </div>
                    @endforeach
                </div>
                @endif
            </div>
            <div class="flex shrink-0 items-center gap-1">
                <button type="button" data-modal-open="incidentModal" data-title="Update incident" data-method="PUT" data-action="{{ route('archive.incidents.update', $i) }}"
                        data-fill="{{ json_encode(array_merge($i->only(['employee_id', 'type', 'title', 'description', 'decision']), ['incident_date' => $i->incident_date->toDateString()])) }}"
                        class="rounded-lg px-2.5 py-1.5 text-xs font-medium ring-1 ring-inset ring-line dark:ring-slate-700 hover:bg-slate-50 dark:hover:bg-slate-800 cursor-pointer">Edit</button>
                <form method="POST" action="{{ route('archive.incidents.destroy', $i) }}" data-confirm="Delete this incident record permanently?">@csrf @method('DELETE')
                    <button type="submit" title="Delete" aria-label="Delete incident" class="rounded-lg p-2 text-ink-muted hover:bg-rose-50 hover:text-rose-600 dark:hover:bg-rose-500/10 cursor-pointer"><svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M14.74 9l-.346 9m-4.788 0L9.26 9m9.968-3.21c.342.052.682.107 1.022.166m-1.022-.165L18.16 19.673a2.25 2.25 0 01-2.244 2.077H8.084a2.25 2.25 0 01-2.244-2.077L4.772 5.79m14.456 0a48.108 48.108 0 00-3.478-.397m-12 .562c.34-.059.68-.114 1.022-.165m0 0a48.11 48.11 0 013.478-.397m7.5 0v-.916c0-1.18-.91-2.164-2.09-2.201a51.964 51.964 0 00-3.32 0c-1.18.037-2.09 1.022-2.09 2.201v.916m7.5 0a48.667 48.667 0 00-7.5 0"/></svg></button>
                </form>
            </div>
        </div>
    </li>
    @endforeach
</ul>
