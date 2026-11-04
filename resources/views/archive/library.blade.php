@extends('layouts.archive')
@section('title', 'TSD Documented Process')
@section('subtitle', 'Reports, product spiels, training, workflow, call flow and compensation — files and written pages')
@php extract(\App\Support\ArchiveUi::classes()); @endphp

@section('content')
<form method="GET" class="mb-6">
    <div class="relative w-full sm:w-96">
        <svg class="pointer-events-none absolute left-3 top-1/2 -translate-y-1/2 w-4 h-4 text-slate-400" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" d="M21 21l-5.197-5.197m0 0A7.5 7.5 0 105.196 5.196a7.5 7.5 0 0010.607 10.607z"/></svg>
        <input type="search" name="q" value="{{ $q }}" placeholder="Search every folder — files and pages…" class="{{ $input }} pl-9">
    </div>
</form>

@if($results)
<section class="{{ $card }} overflow-hidden">
    <div class="flex items-center justify-between border-b border-line dark:border-slate-800 px-5 py-3">
        <h3 class="font-semibold">Results for “{{ $q }}”</h3>
        <a href="{{ route('archive.library') }}" class="text-sm font-medium text-primary hover:underline">Clear</a>
    </div>
    @if($results['pages']->isEmpty() && $results['files']->isEmpty())
        <p class="px-5 py-10 text-center text-sm text-ink-muted">Nothing matches.</p>
    @else
    <ul class="divide-y divide-line dark:divide-slate-800">
        @foreach($results['files'] as $doc)
        @php [$tl, $tc] = $doc->typeMeta(); @endphp
        <li class="flex items-center gap-3 px-5 py-3">
            <span class="w-9 h-9 shrink-0 rounded-lg {{ $tc }} flex items-center justify-center text-[10px] font-bold">{{ $tl }}</span>
            <div class="min-w-0 flex-1"><div class="text-sm font-medium truncate">{{ $doc->title }}</div><div class="text-xs text-ink-muted">{{ \App\Support\DocumentedProcess::label($doc->process_folder) }}@if($doc->processSubfolder) / {{ $doc->processSubfolder->path() }}@endif · file · {{ $doc->humanSize() }}</div></div>
            @if($doc->isPreviewable())<a href="{{ route('archive.documents.preview', $doc) }}" data-preview data-kind="{{ $doc->isImage() ? 'image' : 'pdf' }}" data-title="{{ $doc->title }}" data-meta="{{ $doc->original_name }} · {{ $doc->humanSize() }}" data-download="{{ route('archive.documents.download', $doc) }}" class="text-sm font-medium text-primary hover:underline">Preview</a>@endif
            <a href="{{ route('archive.documents.download', $doc) }}" class="text-sm font-medium text-ink-muted hover:text-ink">Download</a>
        </li>
        @endforeach
        @foreach($results['pages'] as $it)
        <li><a href="{{ route('archive.library.show', $it) }}" class="flex items-center gap-3 px-5 py-3 hover:bg-slate-50 dark:hover:bg-slate-800/40">
            <span class="w-9 h-9 shrink-0 rounded-lg bg-slate-100 dark:bg-slate-800 flex items-center justify-center"><svg class="w-4 h-4 text-ink-muted" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M19.5 14.25v-2.625a3.375 3.375 0 00-3.375-3.375h-1.5A1.125 1.125 0 0113.5 7.125v-1.5a3.375 3.375 0 00-3.375-3.375H8.25m0 12.75h7.5m-7.5 3H12M10.5 2.25H5.625c-.621 0-1.125.504-1.125 1.125v17.25c0 .621.504 1.125 1.125 1.125h12.75c.621 0 1.125-.504 1.125-1.125V11.25a9 9 0 00-9-9z"/></svg></span>
            <div class="min-w-0 flex-1"><div class="text-sm font-medium truncate">{{ $it->title }}</div><div class="text-xs text-ink-muted">{{ \App\Support\DocumentedProcess::label($it->folder) }} · page · v{{ $it->version }}</div></div>
        </a></li>
        @endforeach
    </ul>
    @endif
</section>
@else
<div class="grid grid-cols-1 sm:grid-cols-2 xl:grid-cols-4 gap-4">
    @foreach($folders as $f)
    <a href="{{ route('archive.library.folder', $f['slug']) }}" class="{{ $card }} group flex flex-col p-5 transition hover:-translate-y-0.5 hover:shadow-panel-lift">
        <div class="flex items-start justify-between">
            <span class="flex w-11 h-11 items-center justify-center rounded-xl {{ $f['tint'] }}">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="{{ $f['icon'] }}"/></svg>
            </span>
            <svg class="w-4 h-4 text-slate-300 group-hover:text-primary transition" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M8.25 4.5l7.5 7.5-7.5 7.5"/></svg>
        </div>
        <h3 class="mt-4 font-semibold group-hover:text-primary">{{ $f['label'] }}</h3>
        <p class="mt-1 text-xs text-ink-muted dark:text-slate-400">
            @if($f['folders']){{ $f['folders'] }} {{ \Illuminate\Support\Str::plural('folder', $f['folders']) }} · @endif{{ $f['files'] }} {{ \Illuminate\Support\Str::plural('file', $f['files']) }} · {{ $f['pages'] }} {{ \Illuminate\Support\Str::plural('page', $f['pages']) }}
        </p>
        <p class="mt-auto pt-4 text-[11px] text-ink-muted dark:text-slate-500">{{ $f['updated'] ? 'Updated ' . $f['updated']->diffForHumans() : 'Empty' }}</p>
    </a>
    @endforeach
</div>
@endif
@endsection
