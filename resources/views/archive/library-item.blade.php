@extends('layouts.archive')
@section('title', $item->title)
@section('subtitle', 'TSD Documented Process · ' . \App\Support\DocumentedProcess::label($item->folder ?? '') . ' · ' . (\App\Models\LibraryItem::KINDS[$item->kind]['label'] ?? 'Page'))
@php extract(\App\Support\ArchiveUi::classes()); @endphp

@push('topbar-right')
<a href="{{ $item->folder ? route('archive.library.folder', $item->folder) : route('archive.library') }}" class="{{ $btnGhost }}">← {{ \App\Support\DocumentedProcess::label($item->folder ?? '') ?: 'All folders' }}</a>
<button type="button" data-modal-open="libraryModal" data-title="Edit — saves version {{ $item->version + 1 }}" data-method="PUT" data-action="{{ route('archive.library.update', $item) }}"
        data-fill="{{ json_encode($item->only(['kind', 'folder', 'title', 'body', 'product_id', 'department_id'])) }}" class="{{ $btnPrimary }}">Edit</button>
@if($item->archived_at)
<form method="POST" action="{{ route('archive.library.unarchive', $item) }}">@csrf @method('PATCH')<button class="{{ $btnGhost }}">Restore</button></form>
@else
<form method="POST" action="{{ route('archive.library.archive', $item) }}" data-confirm="Archive &quot;{{ $item->title }}&quot;? It moves to Archived Records → Documented Process, where you can restore it.">@csrf @method('PATCH')<button class="{{ $btnGhost }}">Archive</button></form>
@endif
@endpush

@section('content')
@if($item->archived_at)
<div class="mb-5 rounded-xl border border-amber-200 dark:border-amber-500/30 bg-amber-50 dark:bg-amber-500/10 px-4 py-3 text-sm text-amber-800 dark:text-amber-200">
    Archived {{ $item->archived_at->format('M j, Y') }} — hidden from TSD Documented Process. Use <span class="font-semibold">Restore</span> to bring it back.
</div>
@endif
<div class="grid grid-cols-1 xl:grid-cols-3 gap-6">
    <article class="{{ $card }} xl:col-span-2 overflow-hidden">
        <div class="flex flex-wrap items-center justify-between gap-3 border-b border-line dark:border-slate-800 px-6 py-3">
            <div class="flex items-center gap-2 text-xs text-ink-muted dark:text-slate-400">
                <span class="rounded-full px-2 py-0.5 text-[11px] font-semibold ring-1 ring-inset {{ \App\Models\LibraryItem::KINDS[$item->kind]['badge'] }}">{{ \App\Models\LibraryItem::KINDS[$item->kind]['label'] }}</span>
                <span class="font-semibold text-ink dark:text-slate-200">v{{ $item->version }}</span> · updated {{ $item->updated_at->format('M j, Y') }} by {{ $item->editor?->name ?? 'Unknown' }}
            </div>
            <button type="button" data-copy="#libBody" class="inline-flex items-center gap-1.5 rounded-lg px-3 py-1.5 text-xs font-semibold ring-1 ring-inset ring-line dark:ring-slate-700 hover:bg-slate-50 dark:hover:bg-slate-800 cursor-pointer">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M15.666 3.888A2.25 2.25 0 0013.5 2.25h-3c-1.03 0-1.9.693-2.166 1.638m7.332 0c.055.194.084.4.084.612v0a.75.75 0 01-.75.75H9a.75.75 0 01-.75-.75v0c0-.212.03-.418.084-.612m7.332 0c.646.049 1.288.11 1.927.184 1.1.128 1.907 1.077 1.907 2.185V19.5a2.25 2.25 0 01-2.25 2.25H6.75A2.25 2.25 0 014.5 19.5V6.257c0-1.108.806-2.057 1.907-2.185a48.208 48.208 0 011.927-.184"/></svg>
                <span>Copy</span>
            </button>
        </div>
        <div id="libBody" class="px-6 py-6 text-[15px] leading-relaxed whitespace-pre-line">{{ $item->body }}</div>
    </article>

    <aside class="{{ $card }} overflow-hidden self-start">
        <div class="border-b border-line dark:border-slate-800 px-5 py-4"><h3 class="font-semibold">Version history <span class="font-normal text-ink-muted">({{ $item->versions->count() }})</span></h3></div>
        <ol class="divide-y divide-line dark:divide-slate-800 max-h-[70vh] overflow-y-auto">
            @foreach($item->versions as $v)
            <li class="px-5 py-3">
                <div class="flex items-center justify-between gap-2">
                    <span class="text-sm font-semibold">v{{ $v->version }} @if($v->version === $item->version)<span class="ml-1 rounded-full bg-emerald-50 dark:bg-emerald-500/10 px-1.5 py-0.5 text-[10px] font-semibold text-emerald-700 dark:text-emerald-300">current</span>@endif</span>
                    <span class="text-[11px] text-ink-muted dark:text-slate-400">{{ $v->created_at?->format('M j, Y g:i A') }}</span>
                </div>
                <p class="text-xs text-ink-muted dark:text-slate-400">{{ $v->user?->name ?? 'Unknown' }}@if($v->change_note) — {{ $v->change_note }}@endif</p>
                @if($v->version !== $item->version)
                <div class="mt-2 flex items-center gap-3">
                    <button type="button" data-modal-open="ver{{ $v->version }}" class="text-xs font-medium text-primary hover:underline cursor-pointer">View</button>
                    <form method="POST" action="{{ route('archive.library.restore', [$item, $v->version]) }}" data-confirm="Restore v{{ $v->version }}? It becomes a new version — nothing is lost.">@csrf @method('PATCH')<button class="text-xs font-medium text-ink-muted hover:text-ink cursor-pointer">Restore</button></form>
                </div>
                <dialog id="ver{{ $v->version }}" class="{{ $modal }} max-w-2xl">
                    <div class="flex items-center justify-between border-b border-line dark:border-slate-800 px-6 py-4">
                        <h3 class="font-semibold">v{{ $v->version }} — {{ $v->title }}</h3>
                        <button type="button" data-modal-close class="rounded-lg p-1.5 text-ink-muted hover:bg-slate-100 dark:hover:bg-slate-800 cursor-pointer" aria-label="Close"><svg class="w-5 h-5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" d="M6 18L18 6M6 6l12 12"/></svg></button>
                    </div>
                    <div class="px-6 py-5 max-h-[70vh] overflow-y-auto text-sm leading-relaxed whitespace-pre-line">{{ $v->body }}</div>
                </dialog>
                @endif
            </li>
            @endforeach
        </ol>
    </aside>
</div>

@include('archive.partials.library-modal')
@endsection

@push('scripts')
<script>
document.querySelectorAll('[data-copy]').forEach((btn) => btn.addEventListener('click', async () => {
    const text = document.querySelector(btn.dataset.copy)?.innerText ?? '';
    try {
        await navigator.clipboard.writeText(text.trim());
        btn.querySelector('span').textContent = 'Copied!';
        setTimeout(() => { btn.querySelector('span').textContent = 'Copy'; }, 1500);
    } catch (e) { window.showToast?.('Could not copy — select the text instead.', 'error'); }
}));
</script>
@endpush
