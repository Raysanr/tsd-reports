@extends('layouts.archive')
@section('title', 'Archived Records')
@section('subtitle', 'Former employees, retired teams, archived scripts and deleted files')
@php extract(\App\Support\ArchiveUi::classes()); @endphp

@section('content')
<div class="mb-6 border-b border-line dark:border-slate-800">
    <nav class="-mb-px flex gap-6 overflow-x-auto">
        @foreach(['people' => 'Former employees', 'departments' => 'Teams', 'library' => 'Documented Process', 'trash' => 'Trash'] as $k => $l)
        <a href="{{ route('archive.records', ['tab' => $k]) }}" class="whitespace-nowrap border-b-2 px-1 pb-3 text-sm font-medium {{ $tab === $k ? 'border-yellow-500 text-primary dark:text-yellow-400' : 'border-transparent text-ink-muted hover:border-slate-300 hover:text-ink dark:text-slate-400' }}">
            {{ $l }} <span class="ml-1.5 rounded-full px-2 py-0.5 text-xs {{ $tab === $k ? 'bg-yellow-50 text-yellow-700 dark:bg-yellow-500/10 dark:text-yellow-300' : 'bg-slate-100 dark:bg-slate-800' }}">{{ $counts[$k] }}</span>
        </a>
        @endforeach
    </nav>
</div>

<div class="{{ $card }} overflow-hidden">
@if($tab === 'people')
    @if($people->isEmpty())
        @include('archive.partials.empty', ['title' => 'No former employees', 'text' => 'Setting a person\'s status to Resigned moves them here.'])
    @else
    <ul class="divide-y divide-line dark:divide-slate-800">
        @foreach($people as $p)
        <li class="flex flex-wrap items-center gap-4 px-5 py-4">
            @include('archive.partials.avatar', ['name' => $p->full_name, 'photo' => $p->photoUrl()])
            <div class="min-w-0 flex-1">
                <a href="{{ route('archive.people.show', $p) }}" class="font-medium hover:text-primary">{{ $p->full_name }}</a>
                <div class="text-xs text-ink-muted dark:text-slate-400">{{ $p->position ?: '—' }} · {{ $p->department?->name ?? 'No team' }}</div>
            </div>
            <div class="text-xs text-ink-muted dark:text-slate-400 text-right">
                {{ $p->hired_at?->format('M Y') ?? '?' }} – {{ $p->left_at?->format('M j, Y') ?? '?' }}
            </div>
            <div class="flex items-center gap-2">
                <form method="POST" action="{{ route('archive.people.reinstate', $p) }}">@csrf @method('PATCH')<button class="{{ $btnGhost }} py-1.5">Reinstate</button></form>
                <form method="POST" action="{{ route('archive.people.destroy', $p) }}" data-confirm="Permanently delete {{ $p->full_name }}? Their files stay but lose the link to this person. This can't be undone.">@csrf @method('DELETE')<button class="rounded-lg px-3 py-1.5 text-sm font-medium text-rose-600 hover:bg-rose-50 dark:hover:bg-rose-500/10 cursor-pointer">Delete</button></form>
            </div>
        </li>
        @endforeach
    </ul>
    @endif
@elseif($tab === 'departments')
    @if($departments->isEmpty())
        @include('archive.partials.empty', ['title' => 'No archived teams'])
    @else
    <ul class="divide-y divide-line dark:divide-slate-800">
        @foreach($departments as $d)
        <li class="flex flex-wrap items-center gap-4 px-5 py-4">
            <span class="h-3 w-3 rounded-full {{ $d->colorClasses()['dot'] }}"></span>
            <div class="min-w-0 flex-1">
                <div class="font-medium">{{ $d->name }}</div>
                <div class="text-xs text-ink-muted dark:text-slate-400">{{ $d->employees_count }} people · archived {{ $d->archived_at->format('M j, Y') }}</div>
            </div>
            <form method="POST" action="{{ route('archive.departments.restore', $d) }}">@csrf @method('PATCH')<button class="{{ $btnGhost }} py-1.5">Restore</button></form>
        </li>
        @endforeach
    </ul>
    @endif
@elseif($tab === 'library')
    @if($library->isEmpty())
        @include('archive.partials.empty', ['title' => 'No archived pages', 'text' => 'Pages you archive in TSD Documented Process land here.'])
    @else
    <ul class="divide-y divide-line dark:divide-slate-800">
        @foreach($library as $it)
        <li class="flex flex-wrap items-center gap-4 px-5 py-4">
            <span class="rounded-full px-2 py-0.5 text-[11px] font-semibold ring-1 ring-inset {{ \App\Models\LibraryItem::KINDS[$it->kind]['badge'] }}">{{ \App\Models\LibraryItem::KINDS[$it->kind]['label'] }}</span>
            <div class="min-w-0 flex-1">
                <a href="{{ route('archive.library.show', $it) }}" class="font-medium hover:text-primary truncate block">{{ $it->title }}</a>
                <div class="text-xs text-ink-muted dark:text-slate-400">v{{ $it->version }}@if($it->product) · {{ $it->product->display_name }}@endif · {{ $it->department?->name ?? 'All teams' }} · archived {{ $it->archived_at->diffForHumans() }}</div>
            </div>
            <div class="flex items-center gap-2">
                <form method="POST" action="{{ route('archive.library.unarchive', $it) }}">@csrf @method('PATCH')<button class="{{ $btnGhost }} py-1.5">Restore</button></form>
                <form method="POST" action="{{ route('archive.library.destroy', $it) }}" data-confirm="Permanently delete &quot;{{ $it->title }}&quot; and all its versions? This can't be undone.">@csrf @method('DELETE')<button class="rounded-lg px-3 py-1.5 text-sm font-medium text-rose-600 hover:bg-rose-50 dark:hover:bg-rose-500/10 cursor-pointer">Delete forever</button></form>
            </div>
        </li>
        @endforeach
    </ul>
    @endif
@else
    @if($trash->isEmpty())
        @include('archive.partials.empty', ['title' => 'Trash is empty', 'text' => 'Deleted files land here first, so nothing is lost by accident.'])
    @else
    <ul class="divide-y divide-line dark:divide-slate-800">
        @foreach($trash as $doc)
        @php [$typeLabel, $typeClass] = $doc->typeMeta(); @endphp
        <li class="flex flex-wrap items-center gap-4 px-5 py-4">
            <span class="w-10 h-10 shrink-0 rounded-lg {{ $typeClass }} flex items-center justify-center text-[10px] font-bold">{{ $typeLabel }}</span>
            <div class="min-w-0 flex-1">
                <div class="font-medium truncate">{{ $doc->title }}</div>
                <div class="text-xs text-ink-muted dark:text-slate-400">{{ $doc->humanSize() }} · deleted {{ $doc->deleted_at->diffForHumans() }}</div>
            </div>
            <div class="flex items-center gap-2">
                <form method="POST" action="{{ route('archive.documents.restore', $doc->id) }}">@csrf @method('PATCH')<button class="{{ $btnGhost }} py-1.5">Restore</button></form>
                <form method="POST" action="{{ route('archive.documents.force-destroy', $doc->id) }}" data-confirm="Permanently delete &quot;{{ $doc->title }}&quot;? The file is removed from storage and can't be recovered.">@csrf @method('DELETE')<button class="rounded-lg px-3 py-1.5 text-sm font-medium text-rose-600 hover:bg-rose-50 dark:hover:bg-rose-500/10 cursor-pointer">Delete forever</button></form>
            </div>
        </li>
        @endforeach
    </ul>
    @endif
@endif
</div>
@endsection
