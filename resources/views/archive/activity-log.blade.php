@extends('layouts.archive')
@section('title', 'Activity Log')
@section('subtitle', 'Every change made in the Archiving System')
@php
    extract(\App\Support\ArchiveUi::classes());
    $tone = fn (string $a) => match (true) {
        str_contains($a, 'deleted') || str_contains($a, 'trashed') => 'bg-rose-500',
        str_contains($a, 'archived') => 'bg-amber-500',
        str_contains($a, 'created') || str_contains($a, 'uploaded') => 'bg-emerald-500',
        default => 'bg-yellow-500',
    };
@endphp

@section('content')
<div class="{{ $card }} p-5 md:p-6">
    @if($logs->isEmpty())
        @include('archive.partials.empty', ['title' => 'No activity yet', 'text' => 'Uploads, edits and deletions in this module will be recorded here.', 'icon' => 'M12 6v6l4 2M21 12a9 9 0 11-18 0 9 9 0 0118 0z'])
    @else
    <ol class="relative ml-3 border-l-2 border-line dark:border-slate-800">
        @foreach($logs as $log)
        <li class="relative mb-6 pl-6 last:mb-0">
            <span class="absolute -left-[7px] top-1.5 h-3 w-3 rounded-full ring-4 ring-white dark:ring-slate-900 {{ $tone($log->action) }}"></span>
            <div class="flex flex-wrap items-baseline justify-between gap-x-4 gap-y-1">
                <p class="text-sm"><span class="font-semibold">{{ $log->actor_name ?? 'Unknown user' }}</span> <span class="text-ink-muted dark:text-slate-300">{{ \Illuminate\Support\Str::lcfirst($log->description) }}</span></p>
                <time class="text-xs text-ink-muted dark:text-slate-400 tabular-nums" datetime="{{ $log->created_at->toIso8601String() }}" title="{{ $log->created_at->format('M j, Y g:i A') }}">{{ $log->created_at->diffForHumans() }}</time>
            </div>
            <p class="mt-0.5 text-[11px] font-mono text-ink-muted/70">{{ $log->action }}</p>
        </li>
        @endforeach
    </ol>
    <div class="mt-6">{{ $logs->links() }}</div>
    @endif
</div>
@endsection
