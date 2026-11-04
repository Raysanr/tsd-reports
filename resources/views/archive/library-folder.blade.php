@extends('layouts.archive')
@section('title', $subfolder?->name ?? $meta[0])
@section('subtitle', 'TSD Documented Process' . ($subfolder ? ' · ' . $meta[0] : ''))
@php
    extract(\App\Support\ArchiveUi::classes());
    $here = $subfolder?->name ?? $meta[0];
    $uploadFill = json_encode(['process_folder' => $folder, 'process_subfolder_id' => $subfolder?->id]);
    $parentUrl = ! $subfolder ? route('archive.library')
        : ($subfolder->parent_id ? route('archive.library.subfolder', [$folder, $subfolder->parent_id]) : route('archive.library.folder', $folder));
    $folderIcon = 'M2.25 12.75V12A2.25 2.25 0 014.5 9.75h15A2.25 2.25 0 0121.75 12v.75m-8.69-6.44l-2.12-2.12a1.5 1.5 0 00-1.061-.44H4.5A2.25 2.25 0 002.25 6v12a2.25 2.25 0 002.25 2.25h15A2.25 2.25 0 0021.75 18V9a2.25 2.25 0 00-2.25-2.25h-5.379a1.5 1.5 0 01-1.06-.44z';
@endphp

@push('topbar-right')
<a href="{{ $parentUrl }}" class="{{ $btnGhost }}">← {{ $subfolder ? 'Back' : 'All folders' }}</a>
<button type="button" data-modal-open="newFolderModal" class="{{ $btnGhost }} ring-1 ring-line dark:ring-slate-700">
    <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M12 10.5v6m3-3H9m4.06-7.19l-2.12-2.12a1.5 1.5 0 00-1.061-.44H4.5A2.25 2.25 0 002.25 6v12a2.25 2.25 0 002.25 2.25h15A2.25 2.25 0 0021.75 18V9a2.25 2.25 0 00-2.25-2.25h-5.379a1.5 1.5 0 01-1.06-.44z"/></svg>
    New folder
</button>
<button type="button" data-modal-open="uploadModal" data-title="Upload to {{ $here }}" data-fill="{{ $uploadFill }}" class="{{ $btnPrimary }}">
    <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M3 16.5v2.25A2.25 2.25 0 005.25 21h13.5A2.25 2.25 0 0021 18.75V16.5m-13.5-9L12 3m0 0l4.5 4.5M12 3v13.5"/></svg>
    Upload files
</button>
@endpush

@section('content')
<nav class="mb-5 flex items-center gap-2 text-sm text-ink-muted dark:text-slate-400" aria-label="Breadcrumb">
    <a href="{{ route('archive.library') }}" class="hover:text-primary">TSD Documented Process</a>
    <span>/</span>
    <a href="{{ route('archive.library.folder', $folder) }}" class="inline-flex items-center gap-2 {{ $subfolder ? 'hover:text-primary' : 'font-semibold text-ink dark:text-slate-100' }}">
        <span class="flex w-6 h-6 items-center justify-center rounded-md {{ $meta[1] }}"><svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="{{ $meta[2] }}"/></svg></span>
        {{ $meta[0] }}
    </a>
    @foreach($trail as $crumb)
    <span>/</span>
    @if($loop->last)
    <span class="font-semibold text-ink dark:text-slate-100">{{ $crumb->name }}</span>
    @else
    <a href="{{ route('archive.library.subfolder', [$folder, $crumb]) }}" class="hover:text-primary">{{ $crumb->name }}</a>
    @endif
    @endforeach
</nav>

{{-- Subfolders (2026-10-21) --}}
@if($subfolders->isNotEmpty())
<section class="mb-8">
    <h2 class="mb-3 text-sm font-semibold uppercase tracking-wider text-ink-muted dark:text-slate-400">Folders <span class="ml-1 rounded-full bg-slate-200/70 dark:bg-slate-800 px-2 py-0.5 text-xs tabular-nums">{{ $subfolders->count() }}</span></h2>
    <div class="grid grid-cols-1 sm:grid-cols-2 xl:grid-cols-3 2xl:grid-cols-4 gap-3">
        @foreach($subfolders as $sf)
        <div class="{{ $card }} group relative flex items-center gap-3 p-4 transition hover:-translate-y-0.5 hover:shadow-panel-lift">
            <span class="flex w-10 h-10 shrink-0 items-center justify-center rounded-xl {{ $meta[1] }}"><svg class="w-5 h-5" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="{{ $folderIcon }}"/></svg></span>
            <a href="{{ route('archive.library.subfolder', [$folder, $sf]) }}" class="min-w-0 flex-1 after:absolute after:inset-0 after:content-['']">
                <div class="font-semibold truncate group-hover:text-primary">{{ $sf->name }}</div>
                <div class="text-xs text-ink-muted dark:text-slate-400">
                    @if($sf->children_count){{ $sf->children_count }} {{ Str::plural('folder', $sf->children_count) }} · @endif{{ $sf->documents_count }} {{ Str::plural('file', $sf->documents_count) }}
                </div>
            </a>
            <div class="relative z-10 flex items-center gap-0.5 opacity-0 group-hover:opacity-100 group-focus-within:opacity-100 transition">
                <button type="button" data-modal-open="renameFolderModal" data-action="{{ route('archive.library.subfolders.rename', $sf) }}" data-fill="{{ json_encode(['name' => $sf->name]) }}"
                        title="Rename" aria-label="Rename {{ $sf->name }}" class="rounded-md p-1.5 text-ink-muted hover:bg-slate-100 hover:text-ink dark:hover:bg-slate-800 cursor-pointer">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M16.862 4.487l1.687-1.688a1.875 1.875 0 112.652 2.652L10.582 16.07a4.5 4.5 0 01-1.897 1.13L6 18l.8-2.685a4.5 4.5 0 011.13-1.897l8.932-8.931z"/></svg>
                </button>
                <form method="POST" action="{{ route('archive.library.subfolders.destroy', $sf) }}" data-confirm="Delete the folder &quot;{{ $sf->name }}&quot;? Only empty folders can be deleted.">@csrf @method('DELETE')
                    <button type="submit" title="Delete" aria-label="Delete {{ $sf->name }}" class="rounded-md p-1.5 text-ink-muted hover:bg-rose-50 hover:text-rose-600 dark:hover:bg-rose-500/10 cursor-pointer">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M14.74 9l-.346 9m-4.788 0L9.26 9m9.968-3.21c.342.052.682.107 1.022.166m-1.022-.165L18.16 19.673a2.25 2.25 0 01-2.244 2.077H8.084a2.25 2.25 0 01-2.244-2.077L4.772 5.79m14.456 0a48.108 48.108 0 00-3.478-.397m-12 .562c.34-.059.68-.114 1.022-.165m0 0a48.11 48.11 0 013.478-.397m7.5 0v-.916c0-1.18-.91-2.164-2.09-2.201a51.964 51.964 0 00-3.32 0c-1.18.037-2.09 1.022-2.09 2.201v.916m7.5 0a48.667 48.667 0 00-7.5 0"/></svg>
                    </button>
                </form>
            </div>
        </div>
        @endforeach
    </div>
</section>
@endif

{{-- Files --}}
<section class="mb-8">
    <h2 class="mb-3 text-sm font-semibold uppercase tracking-wider text-ink-muted dark:text-slate-400">Files <span class="ml-1 rounded-full bg-slate-200/70 dark:bg-slate-800 px-2 py-0.5 text-xs tabular-nums">{{ $files->count() }}</span></h2>
    @if($files->isEmpty())
    <button type="button" data-modal-open="uploadModal" data-title="Upload to {{ $here }}" data-fill="{{ $uploadFill }}"
            class="flex w-full flex-col items-center justify-center rounded-2xl border-2 border-dashed border-slate-300 dark:border-slate-700 px-6 py-10 text-center hover:border-yellow-400 hover:bg-yellow-50/40 dark:hover:bg-yellow-500/5 transition cursor-pointer">
        <svg class="w-7 h-7 text-primary" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M12 16.5V9.75m0 0l3 3m-3-3l-3 3M6.75 19.5a4.5 4.5 0 01-1.41-8.775 5.25 5.25 0 0110.233-2.33 3 3 0 013.758 3.848A3.752 3.752 0 0118 19.5H6.75z"/></svg>
        <span class="mt-2 text-sm font-semibold">Upload the first file to {{ $here }}</span>
        <span class="mt-0.5 text-xs text-ink-muted">PDF, Word, Excel, PowerPoint, images — up to 20 MB each</span>
    </button>
    @else
    <div class="grid grid-cols-1 sm:grid-cols-2 xl:grid-cols-3 2xl:grid-cols-4 gap-4">
        @foreach($files as $doc)
        @php [$typeLabel, $typeClass] = $doc->typeMeta(); @endphp
        <article class="{{ $card }} group flex flex-col overflow-hidden">
            <div class="relative aspect-[16/9] bg-slate-50 dark:bg-slate-800/60 flex items-center justify-center overflow-hidden">
                @if($doc->isImage())
                    <img src="{{ route('archive.documents.preview', $doc) }}" alt="" loading="lazy" class="h-full w-full object-cover">
                @else
                    <span class="w-14 h-16 rounded-lg {{ $typeClass }} flex items-center justify-center text-sm font-bold shadow-sm ring-1 ring-black/5">{{ $typeLabel }}</span>
                @endif
                <div class="absolute inset-0 flex items-center justify-center gap-2 bg-slate-900/50 opacity-0 group-hover:opacity-100 group-focus-within:opacity-100 transition">
                    @if($doc->isPreviewable())<a href="{{ route('archive.documents.preview', $doc) }}" data-preview data-kind="{{ $doc->isImage() ? 'image' : 'pdf' }}" data-title="{{ $doc->title }}" data-meta="{{ $doc->original_name }} · {{ $doc->humanSize() }}" data-download="{{ route('archive.documents.download', $doc) }}" target="_blank" rel="noopener" class="rounded-lg bg-white px-3 py-1.5 text-xs font-semibold text-slate-900 hover:bg-yellow-50">Preview</a>@endif
                    <a href="{{ route('archive.documents.download', $doc) }}" class="rounded-lg bg-primary px-3 py-1.5 text-xs font-semibold text-white hover:bg-primary-dark">Download</a>
                </div>
            </div>
            <div class="flex flex-1 items-start gap-2 p-4">
                <div class="min-w-0 flex-1">
                    <h3 class="text-sm font-semibold leading-snug line-clamp-2" title="{{ $doc->original_name }}">{{ $doc->title }}</h3>
                    <p class="mt-1 text-[11px] text-ink-muted dark:text-slate-400">{{ $doc->humanSize() }} · {{ $doc->created_at->format('M j, Y') }}@if($doc->uploader) · {{ $doc->uploader->name }}@endif</p>
                </div>
                <form method="POST" action="{{ route('archive.documents.destroy', $doc) }}" data-confirm="Move &quot;{{ $doc->title }}&quot; to trash? You can restore it from Archived Records.">@csrf @method('DELETE')
                    <button type="submit" title="Move to trash" aria-label="Delete {{ $doc->title }}" class="rounded-md p-1.5 text-ink-muted hover:bg-rose-50 hover:text-rose-600 dark:hover:bg-rose-500/10 cursor-pointer"><svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M14.74 9l-.346 9m-4.788 0L9.26 9m9.968-3.21c.342.052.682.107 1.022.166m-1.022-.165L18.16 19.673a2.25 2.25 0 01-2.244 2.077H8.084a2.25 2.25 0 01-2.244-2.077L4.772 5.79m14.456 0a48.108 48.108 0 00-3.478-.397m-12 .562c.34-.059.68-.114 1.022-.165m0 0a48.11 48.11 0 013.478-.397m7.5 0v-.916c0-1.18-.91-2.164-2.09-2.201a51.964 51.964 0 00-3.32 0c-1.18.037-2.09 1.022-2.09 2.201v.916m7.5 0a48.667 48.667 0 00-7.5 0"/></svg></button>
                </form>
            </div>
        </article>
        @endforeach
    </div>
    @endif
</section>

{{-- Pages (existing written pages only — adding new ones was removed 2026-10-20) --}}
@if($pages->isNotEmpty())
<section>
    <h2 class="mb-3 text-sm font-semibold uppercase tracking-wider text-ink-muted dark:text-slate-400">Pages <span class="ml-1 rounded-full bg-slate-200/70 dark:bg-slate-800 px-2 py-0.5 text-xs tabular-nums">{{ $pages->count() }}</span></h2>
    <div class="grid grid-cols-1 md:grid-cols-2 2xl:grid-cols-3 gap-4">
        @foreach($pages as $it)
        <a href="{{ route('archive.library.show', $it) }}" class="{{ $card }} group flex flex-col p-5 transition hover:-translate-y-0.5 hover:shadow-panel-lift">
            <div class="flex items-center justify-between gap-2">
                <span class="rounded-full px-2 py-0.5 text-[11px] font-semibold ring-1 ring-inset {{ \App\Models\LibraryItem::KINDS[$it->kind]['badge'] ?? '' }}">{{ \App\Models\LibraryItem::KINDS[$it->kind]['label'] ?? 'Page' }}</span>
                <span class="rounded-md bg-slate-100 dark:bg-slate-800 px-1.5 py-0.5 text-[10px] font-bold text-ink-muted">v{{ $it->version }}</span>
            </div>
            <h3 class="mt-2 font-semibold leading-snug group-hover:text-primary">{{ $it->title }}</h3>
            <p class="mt-2 text-sm text-ink-muted dark:text-slate-400 line-clamp-3 whitespace-pre-line">{{ $it->body }}</p>
            <div class="mt-auto pt-4 flex flex-wrap items-center gap-x-3 gap-y-1 text-[11px] text-ink-muted dark:text-slate-400">
                @if($it->product)<span class="font-medium text-ink dark:text-slate-200">{{ $it->product->display_name }}</span>@endif
                <span>{{ $it->department?->name ?? 'All teams' }}</span>
                <span class="ml-auto">updated {{ $it->updated_at->diffForHumans() }}</span>
            </div>
        </a>
        @endforeach
    </div>
</section>
@endif

@include('archive.partials.upload-modal', ['processFolder' => true])

{{-- New folder / rename folder --}}
<dialog id="newFolderModal" class="{{ $modal }} max-w-md">
    <form method="POST" action="{{ route('archive.library.subfolders.store', $folder) }}">
        @csrf
        <input type="hidden" name="parent_id" value="{{ $subfolder?->id }}">
        <div class="border-b border-line dark:border-slate-800 px-6 py-4"><h3 class="font-semibold">New folder in {{ $here }}</h3></div>
        <div class="px-6 py-5">
            <label class="{{ $label }}" for="nf_name">Folder name</label>
            <input id="nf_name" name="name" required maxlength="120" autofocus class="{{ $input }}" placeholder="e.g. Scripts 2026">
        </div>
        <div class="flex items-center justify-end gap-2 border-t border-line dark:border-slate-800 bg-slate-50 dark:bg-slate-800/40 px-6 py-3 rounded-b-2xl">
            <button type="button" data-modal-close class="{{ $btnGhost }}">Cancel</button>
            <button type="submit" class="{{ $btnPrimary }}">Create folder</button>
        </div>
    </form>
</dialog>
<dialog id="renameFolderModal" class="{{ $modal }} max-w-md">
    <form method="POST" action="">
        @csrf <input type="hidden" name="_method" value="PATCH" data-default="PATCH">
        <div class="border-b border-line dark:border-slate-800 px-6 py-4"><h3 class="font-semibold">Rename folder</h3></div>
        <div class="px-6 py-5">
            <label class="{{ $label }}" for="rf_name">Folder name</label>
            <input id="rf_name" name="name" required maxlength="120" class="{{ $input }}">
        </div>
        <div class="flex items-center justify-end gap-2 border-t border-line dark:border-slate-800 bg-slate-50 dark:bg-slate-800/40 px-6 py-3 rounded-b-2xl">
            <button type="button" data-modal-close class="{{ $btnGhost }}">Cancel</button>
            <button type="submit" class="{{ $btnPrimary }}">Save</button>
        </div>
    </form>
</dialog>
@endsection
