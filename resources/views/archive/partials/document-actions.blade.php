<div class="flex shrink-0 items-center">
    <button type="button" title="Edit details" aria-label="Edit {{ $doc->title }}"
            data-modal-open="docEditModal" data-method="PUT" data-action="{{ route('archive.documents.update', $doc) }}"
            data-fill="{{ json_encode(array_merge($doc->only(['title', 'category', 'department_id', 'employee_id', 'tags', 'description']), ['expires_at' => $doc->expires_at?->toDateString()])) }}"
            class="rounded-md p-1.5 text-ink-muted hover:bg-slate-100 hover:text-ink dark:hover:bg-slate-800 cursor-pointer">
        <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M16.862 4.487l1.687-1.688a1.875 1.875 0 112.652 2.652L10.582 16.07a4.5 4.5 0 01-1.897 1.13L6 18l.8-2.685a4.5 4.5 0 011.13-1.897l8.932-8.931z"/></svg>
    </button>
    <form method="POST" action="{{ route('archive.documents.destroy', $doc) }}" data-confirm="Move &quot;{{ $doc->title }}&quot; to trash? You can restore it from Archived Records.">
        @csrf @method('DELETE')
        <button type="submit" title="Move to trash" aria-label="Delete {{ $doc->title }}" class="rounded-md p-1.5 text-ink-muted hover:bg-rose-50 hover:text-rose-600 dark:hover:bg-rose-500/10 cursor-pointer">
            <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M14.74 9l-.346 9m-4.788 0L9.26 9m9.968-3.21c.342.052.682.107 1.022.166m-1.022-.165L18.16 19.673a2.25 2.25 0 01-2.244 2.077H8.084a2.25 2.25 0 01-2.244-2.077L4.772 5.79m14.456 0a48.108 48.108 0 00-3.478-.397m-12 .562c.34-.059.68-.114 1.022-.165m0 0a48.11 48.11 0 013.478-.397m7.5 0v-.916c0-1.18-.91-2.164-2.09-2.201a51.964 51.964 0 00-3.32 0c-1.18.037-2.09 1.022-2.09 2.201v.916m7.5 0a48.667 48.667 0 00-7.5 0"/></svg>
        </button>
    </form>
</div>
