{{-- Needs: $departments, $employees, and ArchiveUi classes. Pass
     ['processFolder' => true] for the simple Documented Process version. --}}
<dialog id="uploadModal" class="{{ $modal }} max-w-xl">
    <form method="POST" action="{{ route('archive.documents.store') }}" enctype="multipart/form-data" data-upload-form>
        @csrf
        <input type="text" name="process_folder" hidden tabindex="-1" aria-hidden="true">{{-- set by openers in TSD Documented Process --}}
        <input type="text" name="process_subfolder_id" hidden tabindex="-1" aria-hidden="true">
        <div class="flex items-center justify-between border-b border-line dark:border-slate-800 px-6 py-4">
            <h3 class="font-semibold" data-modal-title="Upload files">Upload files</h3>
            <button type="button" data-modal-close class="rounded-lg p-1.5 text-ink-muted hover:bg-slate-100 dark:hover:bg-slate-800 cursor-pointer" aria-label="Close"><svg class="w-5 h-5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" d="M6 18L18 6M6 6l12 12"/></svg></button>
        </div>
        <div class="space-y-4 px-6 py-5 max-h-[72vh] overflow-y-auto">
            <label data-dropzone class="group flex flex-col items-center justify-center rounded-2xl border-2 border-dashed border-slate-300 dark:border-slate-700 bg-slate-50/60 dark:bg-slate-800/40 px-6 py-8 text-center cursor-pointer transition hover:border-yellow-400 hover:bg-yellow-50/60 dark:hover:bg-primary-dark/5">
                <div class="w-12 h-12 rounded-full bg-white dark:bg-slate-800 shadow-sm ring-1 ring-line dark:ring-slate-700 flex items-center justify-center mb-3 group-hover:scale-105 transition">
                    <svg class="w-6 h-6 text-yellow-500" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M12 16.5V9.75m0 0l3 3m-3-3l-3 3M6.75 19.5a4.5 4.5 0 01-1.41-8.775 5.25 5.25 0 0110.233-2.33 3 3 0 013.758 3.848A3.752 3.752 0 0118 19.5H6.75z"/></svg>
                </div>
                <span class="text-sm font-semibold"><span class="text-primary">Click to choose</span> or drag files here</span>
                <span class="mt-1 text-xs text-ink-muted dark:text-slate-400">PDF, images, Word, Excel, PowerPoint, CSV, ZIP · up to 20 MB each · max 10 files</span>
                <input type="file" name="files[]" multiple required class="sr-only" accept="{{ collect(\App\Models\ArchiveDocument::ALLOWED_EXTENSIONS)->map(fn ($e) => '.' . $e)->implode(',') }}">
            </label>
            <ul data-file-list class="hidden space-y-1.5"></ul>

            <div data-title-field><label class="{{ $label }}" for="u_title">Title</label><input id="u_title" name="title" maxlength="160" class="{{ $input }}" placeholder="Defaults to the file name"></div>
            {{-- Inside a TSD Documented Process folder the folder IS the category,
                 and expiry/team/person are HR-file fields — so only Title,
                 Tags and Description are asked for there. --}}
            @unless($processFolder ?? false)
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                <div>
                    <label class="{{ $label }}" for="u_cat">Category *</label>
                    <select id="u_cat" name="category" class="{{ $input }}">
                        @foreach(\App\Models\ArchiveDocument::CATEGORIES as $k => $l)<option value="{{ $k }}" @selected($k === 'other')>{{ $l }}</option>@endforeach
                    </select>
                </div>
                <div><label class="{{ $label }}" for="u_exp">Expiry date</label><input id="u_exp" type="date" name="expires_at" class="{{ $input }}"></div>
                <div>
                    <label class="{{ $label }}" for="u_dept">Team</label>
                    <select id="u_dept" name="department_id" class="{{ $input }}">
                        <option value="">— None —</option>
                        @foreach($departments as $d)<option value="{{ $d->id }}">{{ $d->name }}</option>@endforeach
                    </select>
                </div>
                <div>
                    <label class="{{ $label }}" for="u_emp">Person</label>
                    <select id="u_emp" name="employee_id" class="{{ $input }}">
                        <option value="">— None —</option>
                        @foreach($employees as $e)<option value="{{ $e->id }}">{{ $e->full_name }}</option>@endforeach
                    </select>
                </div>
            </div>
            @endunless
            <div><label class="{{ $label }}" for="u_tags">Tags</label><input id="u_tags" name="tags" maxlength="255" class="{{ $input }}" placeholder="comma, separated, tags"></div>
            <div><label class="{{ $label }}" for="u_desc">Description</label><textarea id="u_desc" name="description" rows="2" maxlength="2000" class="{{ $input }}"></textarea></div>
        </div>
        <div class="flex items-center justify-end gap-2 border-t border-line dark:border-slate-800 bg-slate-50 dark:bg-slate-800/40 px-6 py-3 rounded-b-2xl">
            <button type="button" data-modal-close class="{{ $btnGhost }}">Cancel</button>
            <button type="submit" data-upload-submit class="{{ $btnPrimary }}">
                <svg data-spinner class="hidden w-4 h-4 animate-spin" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v4a4 4 0 00-4 4H4z"/></svg>
                Upload
            </button>
        </div>
    </form>
</dialog>

@once
@push('scripts')
<script>
(function () {
    const form = document.querySelector('[data-upload-form]');
    if (!form) return;
    const input = form.querySelector('input[type=file]');
    const zone = form.querySelector('[data-dropzone]');
    const list = form.querySelector('[data-file-list]');
    const titleField = form.querySelector('[data-title-field]');
    const maxBytes = {{ \App\Models\ArchiveDocument::MAX_KB }} * 1024;
    const fmt = (b) => b < 1024 ? b + ' B' : b < 1048576 ? (b / 1024).toFixed(1) + ' KB' : (b / 1048576).toFixed(1) + ' MB';

    function render() {
        const files = [...input.files];
        list.innerHTML = '';
        list.classList.toggle('hidden', !files.length);
        titleField.classList.toggle('hidden', files.length > 1);
        files.forEach((f) => {
            const li = document.createElement('li');
            const tooBig = f.size > maxBytes;
            li.className = 'flex items-center gap-3 rounded-lg px-3 py-2 text-sm ring-1 ring-inset ' + (tooBig ? 'ring-rose-200 bg-rose-50 text-rose-700 dark:bg-rose-500/10 dark:ring-rose-500/30 dark:text-rose-300' : 'ring-line dark:ring-slate-700 bg-white dark:bg-slate-800');
            const name = document.createElement('span');
            name.className = 'min-w-0 flex-1 truncate font-medium';
            name.textContent = f.name;
            const size = document.createElement('span');
            size.className = 'text-xs tabular-nums opacity-70';
            size.textContent = tooBig ? 'Too large' : fmt(f.size);
            li.append(name, size);
            list.append(li);
        });
    }
    input.addEventListener('change', render);
    form.addEventListener('arc:filled', render);
    ['dragenter', 'dragover'].forEach((ev) => zone.addEventListener(ev, (e) => { e.preventDefault(); zone.classList.add('border-yellow-500', 'bg-yellow-50'); }));
    ['dragleave', 'drop'].forEach((ev) => zone.addEventListener(ev, (e) => { e.preventDefault(); zone.classList.remove('border-yellow-500', 'bg-yellow-50'); }));
    zone.addEventListener('drop', (e) => {
        if (!e.dataTransfer.files.length) return;
        input.files = e.dataTransfer.files;
        render();
    });
    form.addEventListener('submit', () => {
        const btn = form.querySelector('[data-upload-submit]');
        btn.disabled = true;
        btn.classList.add('opacity-70');
        btn.querySelector('[data-spinner]').classList.remove('hidden');
    });
    // Overview's "Upload files" button links here with ?upload=1.
    if (new URLSearchParams(location.search).get('upload') === '1') form.closest('dialog').showModal();
})();
</script>
@endpush
@endonce
