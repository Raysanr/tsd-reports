@extends('layouts.archive')
@section('title', 'Documents')
@section('subtitle', 'Every uploaded file, organized and searchable')
@php extract(\App\Support\ArchiveUi::classes()); $query = fn (array $over) => route('archive.documents', array_filter(array_merge($filters, ['view' => $view], $over), fn ($v) => $v !== null && $v !== '')); @endphp

@push('topbar-right')
<button type="button" data-modal-open="uploadModal" data-fill='{"category":"{{ $filters['category'] ?? 'other' }}","department_id":"{{ $filters['department'] }}"}' class="{{ $btnPrimary }}">
    <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M3 16.5v2.25A2.25 2.25 0 005.25 21h13.5A2.25 2.25 0 0021 18.75V16.5m-13.5-9L12 3m0 0l4.5 4.5M12 3v13.5"/></svg>
    Upload
</button>
@endpush

@section('content')
<div class="grid grid-cols-1 lg:grid-cols-[220px_1fr] gap-6">
    {{-- Category rail --}}
    <aside class="space-y-1">
        <p class="px-3 mb-2 text-[11px] font-semibold uppercase tracking-wider text-ink-muted dark:text-slate-400">Categories</p>
        <a href="{{ $query(['category' => null]) }}" class="flex items-center justify-between rounded-lg px-3 py-2 text-sm font-medium {{ ! $filters['category'] ? 'bg-yellow-50 text-yellow-700 dark:bg-yellow-500/10 dark:text-yellow-300' : 'hover:bg-white dark:hover:bg-slate-900' }}">
            All files <span class="text-xs tabular-nums opacity-70">{{ $totalCount }}</span>
        </a>
        @foreach(\App\Models\ArchiveDocument::CATEGORIES as $k => $l)
        <a href="{{ $query(['category' => $k]) }}" class="flex items-center justify-between rounded-lg px-3 py-2 text-sm font-medium {{ $filters['category'] === $k ? 'bg-yellow-50 text-yellow-700 dark:bg-yellow-500/10 dark:text-yellow-300' : 'text-ink-muted dark:text-slate-400 hover:bg-white hover:text-ink dark:hover:bg-slate-900' }}">
            {{ $l }} <span class="text-xs tabular-nums opacity-70">{{ $categoryCounts[$k] ?? 0 }}</span>
        </a>
        @endforeach
    </aside>

    <div class="min-w-0">
        <form method="GET" class="mb-5 flex flex-wrap items-center gap-3">
            @if($filters['category'])<input type="hidden" name="category" value="{{ $filters['category'] }}">@endif
            <input type="hidden" name="view" value="{{ $view }}">
            <div class="relative w-full sm:w-72">
                <svg class="pointer-events-none absolute left-3 top-1/2 -translate-y-1/2 w-4 h-4 text-slate-400" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" d="M21 21l-5.197-5.197m0 0A7.5 7.5 0 105.196 5.196a7.5 7.5 0 0010.607 10.607z"/></svg>
                <input type="search" name="q" value="{{ $filters['q'] }}" placeholder="Search title, file name, tags…" class="{{ $input }} pl-9">
            </div>
            <select name="department" onchange="this.form.submit()" class="{{ $filter }}">
                <option value="">All teams</option>
                @foreach($departments as $d)<option value="{{ $d->id }}" @selected($filters['department'] == $d->id)>{{ $d->name }}</option>@endforeach
            </select>
            <div class="ml-auto inline-flex rounded-lg bg-slate-100 dark:bg-slate-800 p-1">
                @foreach(['grid' => 'M3.75 6A2.25 2.25 0 016 3.75h2.25A2.25 2.25 0 0110.5 6v2.25a2.25 2.25 0 01-2.25 2.25H6a2.25 2.25 0 01-2.25-2.25V6zM3.75 15.75A2.25 2.25 0 016 13.5h2.25a2.25 2.25 0 012.25 2.25V18a2.25 2.25 0 01-2.25 2.25H6A2.25 2.25 0 013.75 18v-2.25zM13.5 6a2.25 2.25 0 012.25-2.25H18A2.25 2.25 0 0120.25 6v2.25A2.25 2.25 0 0118 10.5h-2.25a2.25 2.25 0 01-2.25-2.25V6zM13.5 15.75a2.25 2.25 0 012.25-2.25H18a2.25 2.25 0 012.25 2.25V18A2.25 2.25 0 0118 20.25h-2.25A2.25 2.25 0 0113.5 18v-2.25z', 'list' => 'M8.25 6.75h12M8.25 12h12m-12 5.25h12M3.75 6.75h.007v.008H3.75V6.75zm.375 0a.375.375 0 11-.75 0 .375.375 0 01.75 0zM3.75 12h.007v.008H3.75V12zm.375 0a.375.375 0 11-.75 0 .375.375 0 01.75 0zm-.375 5.25h.007v.008H3.75v-.008zm.375 0a.375.375 0 11-.75 0 .375.375 0 01.75 0z'] as $v => $icon)
                <a href="{{ $query(['view' => $v]) }}" aria-label="{{ ucfirst($v) }} view" class="rounded-md p-1.5 {{ $view === $v ? 'bg-white dark:bg-slate-700 shadow-sm text-primary' : 'text-ink-muted hover:text-ink' }}"><svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="{{ $icon }}"/></svg></a>
                @endforeach
            </div>
        </form>

        @if($documents->isEmpty())
        <div class="{{ $card }}">
            @include('archive.partials.empty', ['title' => $filters['q'] || $filters['category'] || $filters['department'] ? 'No files match' : 'No files yet', 'text' => 'Drag files into the upload window — contracts, SOPs, memos, evaluations.', 'icon' => 'M19.5 14.25v-2.625a3.375 3.375 0 00-3.375-3.375h-1.5A1.125 1.125 0 0113.5 7.125v-1.5a3.375 3.375 0 00-3.375-3.375H8.25m2.25 0H5.625c-.621 0-1.125.504-1.125 1.125v17.25c0 .621.504 1.125 1.125 1.125h12.75c.621 0 1.125-.504 1.125-1.125V11.25a9 9 0 00-9-9z'])
        </div>
        @elseif($view === 'grid')
        <div class="grid grid-cols-1 sm:grid-cols-2 xl:grid-cols-3 2xl:grid-cols-4 gap-4">
            @foreach($documents as $doc)
            @php [$typeLabel, $typeClass] = $doc->typeMeta(); $expired = $doc->expires_at && $doc->expires_at->isPast(); @endphp
            <article class="{{ $card }} group flex flex-col overflow-hidden transition hover:-translate-y-0.5 hover:shadow-panel-lift">
                <div class="relative aspect-[16/10] bg-slate-50 dark:bg-slate-800/60 flex items-center justify-center overflow-hidden">
                    @if($doc->isImage())
                        <img src="{{ route('archive.documents.preview', $doc) }}" alt="" loading="lazy" class="h-full w-full object-cover">
                    @else
                        <span class="w-16 h-20 rounded-lg {{ $typeClass }} flex items-center justify-center text-sm font-bold shadow-sm ring-1 ring-black/5">{{ $typeLabel }}</span>
                    @endif
                    @if($doc->expires_at)
                    <span class="absolute left-3 top-3 rounded-full px-2 py-0.5 text-[11px] font-semibold ring-1 ring-inset {{ $expired ? 'bg-rose-50 text-rose-700 ring-rose-200' : 'bg-white/90 text-ink ring-line' }}">{{ $expired ? 'Expired' : 'Exp ' . $doc->expires_at->format('M j, Y') }}</span>
                    @endif
                    <div class="absolute inset-0 flex items-center justify-center gap-2 bg-slate-900/50 opacity-0 group-hover:opacity-100 group-focus-within:opacity-100 transition">
                        @if($doc->isPreviewable())<a href="{{ route('archive.documents.preview', $doc) }}" data-preview data-kind="{{ $doc->isImage() ? 'image' : 'pdf' }}" data-title="{{ $doc->title }}" data-meta="{{ $doc->original_name }} · {{ $doc->humanSize() }}" data-download="{{ route('archive.documents.download', $doc) }}" target="_blank" rel="noopener" class="rounded-lg bg-white px-3 py-1.5 text-xs font-semibold text-slate-900 hover:bg-yellow-50">Preview</a>@endif
                        <a href="{{ route('archive.documents.download', $doc) }}" class="rounded-lg bg-primary px-3 py-1.5 text-xs font-semibold text-white hover:bg-primary-dark">Download</a>
                    </div>
                </div>
                <div class="flex flex-1 flex-col p-4">
                    <div class="flex items-start justify-between gap-2">
                        <h3 class="text-sm font-semibold leading-snug line-clamp-2" title="{{ $doc->original_name }}">{{ $doc->title }}</h3>
                        @include('archive.partials.document-actions', ['doc' => $doc])
                    </div>
                    <p class="mt-1 text-xs text-ink-muted dark:text-slate-400">{{ \App\Models\ArchiveDocument::CATEGORIES[$doc->category] ?? $doc->category }} · {{ $doc->humanSize() }}</p>
                    @if($doc->tagList())
                    <div class="mt-2 flex flex-wrap gap-1">@foreach(array_slice($doc->tagList(), 0, 4) as $tag)<a href="{{ $query(['q' => $tag]) }}" class="rounded-md bg-slate-100 dark:bg-slate-800 px-1.5 py-0.5 text-[11px] text-ink-muted hover:text-primary">#{{ $tag }}</a>@endforeach</div>
                    @endif
                    <div class="mt-auto pt-3 flex items-center justify-between gap-2 text-[11px] text-ink-muted dark:text-slate-400">
                        <span class="truncate">{{ $doc->employee?->full_name ?? $doc->department?->name ?? 'General' }}</span>
                        <span class="shrink-0">{{ $doc->created_at->diffForHumans(null, true) }} ago</span>
                    </div>
                </div>
            </article>
            @endforeach
        </div>
        @else
        <div class="{{ $card }} overflow-x-auto">
            <table class="min-w-full text-sm">
                <thead class="text-left text-xs uppercase tracking-wider text-ink-muted dark:text-slate-400">
                    <tr class="border-b border-line dark:border-slate-800">
                        <th class="px-5 py-3 font-semibold">Name</th><th class="px-3 py-3 font-semibold">Category</th><th class="px-3 py-3 font-semibold">Linked to</th>
                        <th class="px-3 py-3 font-semibold">Size</th><th class="px-3 py-3 font-semibold">Uploaded</th><th class="px-3 py-3 font-semibold">Expires</th><th class="px-5 py-3"></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-line dark:divide-slate-800">
                    @foreach($documents as $doc)
                    @php [$typeLabel, $typeClass] = $doc->typeMeta(); @endphp
                    <tr class="hover:bg-slate-50/70 dark:hover:bg-slate-800/40">
                        <td class="px-5 py-3"><div class="flex items-center gap-3 min-w-0">
                            <span class="w-9 h-9 shrink-0 rounded-lg {{ $typeClass }} flex items-center justify-center text-[10px] font-bold">{{ $typeLabel }}</span>
                            <div class="min-w-0"><div class="font-medium truncate max-w-xs">{{ $doc->title }}</div><div class="text-xs text-ink-muted truncate max-w-xs">{{ $doc->original_name }}</div></div>
                        </div></td>
                        <td class="px-3 py-3 whitespace-nowrap">{{ \App\Models\ArchiveDocument::CATEGORIES[$doc->category] ?? $doc->category }}</td>
                        <td class="px-3 py-3 whitespace-nowrap text-ink-muted">{{ $doc->employee?->full_name ?? $doc->department?->name ?? '—' }}</td>
                        <td class="px-3 py-3 whitespace-nowrap tabular-nums text-ink-muted">{{ $doc->humanSize() }}</td>
                        <td class="px-3 py-3 whitespace-nowrap text-ink-muted">{{ $doc->created_at->format('M j, Y') }}<div class="text-xs">{{ $doc->uploader?->name }}</div></td>
                        <td class="px-3 py-3 whitespace-nowrap {{ $doc->expires_at?->isPast() ? 'text-rose-600 font-medium' : 'text-ink-muted' }}">{{ $doc->expires_at?->format('M j, Y') ?? '—' }}</td>
                        <td class="px-5 py-3"><div class="flex items-center justify-end gap-3">
                            @if($doc->isPreviewable())<a href="{{ route('archive.documents.preview', $doc) }}" data-preview data-kind="{{ $doc->isImage() ? 'image' : 'pdf' }}" data-title="{{ $doc->title }}" data-meta="{{ $doc->original_name }} · {{ $doc->humanSize() }}" data-download="{{ route('archive.documents.download', $doc) }}" target="_blank" rel="noopener" class="font-medium text-primary hover:underline">Preview</a>@endif
                            <a href="{{ route('archive.documents.download', $doc) }}" class="font-medium text-ink-muted hover:text-ink">Download</a>
                            @include('archive.partials.document-actions', ['doc' => $doc])
                        </div></td>
                    </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        @endif
        <div class="mt-6">{{ $documents->links() }}</div>
    </div>
</div>

@include('archive.partials.upload-modal')

{{-- Edit details --}}
<dialog id="docEditModal" class="{{ $modal }}">
    <form method="POST">
        @csrf
        <input type="hidden" name="_method" value="PUT" data-default="PUT">
        <div class="flex items-center justify-between border-b border-line dark:border-slate-800 px-6 py-4">
            <h3 class="font-semibold">Edit file details</h3>
            <button type="button" data-modal-close class="rounded-lg p-1.5 text-ink-muted hover:bg-slate-100 dark:hover:bg-slate-800 cursor-pointer" aria-label="Close"><svg class="w-5 h-5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" d="M6 18L18 6M6 6l12 12"/></svg></button>
        </div>
        <div class="space-y-4 px-6 py-5">
            <div><label class="{{ $label }}" for="de_title">Title *</label><input id="de_title" name="title" required maxlength="160" class="{{ $input }}"></div>
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                <div><label class="{{ $label }}" for="de_cat">Category *</label><select id="de_cat" name="category" class="{{ $input }}">@foreach(\App\Models\ArchiveDocument::CATEGORIES as $k => $l)<option value="{{ $k }}">{{ $l }}</option>@endforeach</select></div>
                <div><label class="{{ $label }}" for="de_exp">Expiry date</label><input id="de_exp" type="date" name="expires_at" class="{{ $input }}"></div>
                <div><label class="{{ $label }}" for="de_dept">Team</label><select id="de_dept" name="department_id" class="{{ $input }}"><option value="">— None —</option>@foreach($departments as $d)<option value="{{ $d->id }}">{{ $d->name }}</option>@endforeach</select></div>
                <div><label class="{{ $label }}" for="de_emp">Person</label><select id="de_emp" name="employee_id" class="{{ $input }}"><option value="">— None —</option>@foreach($employees as $e)<option value="{{ $e->id }}">{{ $e->full_name }}</option>@endforeach</select></div>
            </div>
            <div><label class="{{ $label }}" for="de_tags">Tags</label><input id="de_tags" name="tags" maxlength="255" class="{{ $input }}"></div>
            <div><label class="{{ $label }}" for="de_desc">Description</label><textarea id="de_desc" name="description" rows="2" maxlength="2000" class="{{ $input }}"></textarea></div>
        </div>
        <div class="flex justify-end gap-2 border-t border-line dark:border-slate-800 bg-slate-50 dark:bg-slate-800/40 px-6 py-3 rounded-b-2xl">
            <button type="button" data-modal-close class="{{ $btnGhost }}">Cancel</button>
            <button type="submit" class="{{ $btnPrimary }}">Save changes</button>
        </div>
    </form>
</dialog>
@endsection
