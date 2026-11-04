{{-- Needs: $employees and ArchiveUi classes. No reply workflow (removed
     2026-10-22) — an incident is its details, an optional outcome and files. --}}
<dialog id="incidentModal" class="{{ $modal }} max-w-2xl">
    <form method="POST" action="{{ route('archive.incidents.store') }}" enctype="multipart/form-data" data-incident-form>
        @csrf
        <input type="hidden" name="_method" value="POST" data-default="POST">
        <div class="flex items-center justify-between border-b border-line dark:border-slate-800 px-6 py-4">
            <h3 class="font-semibold" data-modal-title="New incident">New incident</h3>
            <button type="button" data-modal-close class="rounded-lg p-1.5 text-ink-muted hover:bg-slate-100 dark:hover:bg-slate-800 cursor-pointer" aria-label="Close"><svg class="w-5 h-5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" d="M6 18L18 6M6 6l12 12"/></svg></button>
        </div>
        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4 px-6 py-5 max-h-[70vh] overflow-y-auto">
            <div>
                <label class="{{ $label }}" for="i_emp">Person *</label>
                <select id="i_emp" name="employee_id" required class="{{ $input }}"><option value="">— Choose —</option>@foreach($employees as $e)<option value="{{ $e->id }}">{{ $e->full_name }}</option>@endforeach</select>
            </div>
            <div>
                <label class="{{ $label }}" for="i_type">Type *</label>
                <select id="i_type" name="type" required class="{{ $input }}">@foreach(\App\Models\Incident::TYPES as $k => $l)<option value="{{ $k }}" @selected($k === 'nte')>{{ $l }}</option>@endforeach</select>
            </div>
            <div><label class="{{ $label }}" for="i_date">Incident date *</label><input id="i_date" type="date" name="incident_date" required max="{{ today()->toDateString() }}" class="{{ $input }}"></div>
            <div class="sm:col-span-2"><label class="{{ $label }}" for="i_title">What happened *</label><input id="i_title" name="title" required maxlength="200" class="{{ $input }}" placeholder="e.g. Late 3 times this week / Rude to customer on call"></div>
            <div class="sm:col-span-2"><label class="{{ $label }}" for="i_desc">Details</label><textarea id="i_desc" name="description" rows="3" maxlength="10000" class="{{ $input }}" placeholder="Dates, call/lead references, witnesses"></textarea></div>

            <div class="sm:col-span-2"><label class="{{ $label }}" for="i_decision">Outcome / action taken</label><textarea id="i_decision" name="decision" rows="2" maxlength="10000" class="{{ $input }}" placeholder="e.g. Verbal reminder only / Final warning issued"></textarea></div>
            <div class="sm:col-span-2">
                <span class="{{ $label }}">Attach files</span>
                <label data-incident-drop class="flex cursor-pointer flex-col items-center justify-center rounded-xl border-2 border-dashed border-slate-300 dark:border-slate-700 bg-slate-50/60 dark:bg-slate-800/40 px-4 py-5 text-center transition hover:border-yellow-400 hover:bg-yellow-50/60 dark:hover:bg-primary-dark/5">
                    <svg class="h-6 w-6 text-primary" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M18.375 12.739l-7.693 7.693a4.5 4.5 0 01-6.364-6.364l10.94-10.94A3 3 0 1119.5 7.372L8.552 18.32m.009-.01l-.01.01m5.699-9.941l-7.81 7.81a1.5 1.5 0 002.112 2.13"/></svg>
                    <span class="mt-1.5 text-sm font-semibold">Click or drop files</span>
                    <span class="text-[11px] text-ink-muted">NTE copy, signed reply, screenshots — PDF, Word, images, up to 20 MB each</span>
                    <input type="file" name="files[]" multiple accept=".{{ implode(',.', \App\Models\ArchiveDocument::ALLOWED_EXTENSIONS) }}" class="sr-only" data-incident-files>
                </label>
                <ul data-incident-file-list class="mt-2 space-y-1 text-xs"></ul>
            </div>
        </div>
        <div class="flex justify-end gap-2 border-t border-line dark:border-slate-800 bg-slate-50 dark:bg-slate-800/40 px-6 py-3 rounded-b-2xl">
            <button type="button" data-modal-close class="{{ $btnGhost }}">Cancel</button>
            <button type="submit" class="{{ $btnPrimary }}">Save</button>
        </div>
    </form>
</dialog>

@once
@push('scripts')
<script>
(function () {
    const form = document.querySelector('[data-incident-form]');
    if (!form) return;
    const input = form.querySelector('[data-incident-files]');
    const list = form.querySelector('[data-incident-file-list]');
    const drop = form.querySelector('[data-incident-drop]');
    const show = () => {
        list.innerHTML = [...input.files].map((f) => `<li class="flex items-center justify-between gap-2 rounded-lg bg-slate-50 dark:bg-slate-800/60 px-3 py-1.5"><span class="truncate">${f.name.replace(/[&<>"']/g, '')}</span><span class="shrink-0 text-ink-muted">${(f.size / 1048576).toFixed(1)} MB</span></li>`).join('');
    };
    input.addEventListener('change', show);
    ['dragover', 'dragenter'].forEach((ev) => drop.addEventListener(ev, (e) => { e.preventDefault(); drop.classList.add('border-yellow-400'); }));
    ['dragleave', 'drop'].forEach((ev) => drop.addEventListener(ev, () => drop.classList.remove('border-yellow-400')));
    drop.addEventListener('drop', (e) => { e.preventDefault(); input.files = e.dataTransfer.files; show(); });
    form.addEventListener('arc:filled', () => {
        if (!form.querySelector('[name="incident_date"]').value) form.querySelector('[name="incident_date"]').value = '{{ today()->toDateString() }}';
        input.value = '';
        list.innerHTML = '';
    });
})();
</script>
@endpush
@endonce
