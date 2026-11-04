{{-- Needs: $departments, $managerOptions, $tsaOptions, $userOptions, and ArchiveUi classes. --}}
<dialog id="employeeModal" class="{{ $modal }} max-w-2xl">
    <form method="POST" action="{{ route('archive.people.store') }}" enctype="multipart/form-data" data-employee-form>
        @csrf
        <input type="hidden" name="_method" value="POST" data-default="POST">
        <div class="flex items-center justify-between border-b border-line dark:border-slate-800 px-6 py-4">
            <h3 class="font-semibold" data-modal-title="Add person">Add person</h3>
            <button type="button" data-modal-close class="rounded-lg p-1.5 text-ink-muted hover:bg-slate-100 dark:hover:bg-slate-800 cursor-pointer" aria-label="Close"><svg class="w-5 h-5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" d="M6 18L18 6M6 6l12 12"/></svg></button>
        </div>
        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4 px-6 py-5 max-h-[70vh] overflow-y-auto">
            <div class="sm:col-span-2 flex items-center gap-4">
                <input type="text" name="photo_url" hidden tabindex="-1" aria-hidden="true" data-photo-src>{{-- text, not hidden: hidden inputs keep script-set values through form.reset() --}}
                <div class="relative shrink-0">
                    <img data-photo-preview alt="" class="hidden w-20 h-20 rounded-full object-cover ring-2 ring-line dark:ring-slate-700">
                    <span data-photo-empty class="flex w-20 h-20 items-center justify-center rounded-full bg-slate-100 dark:bg-slate-800 text-slate-400 border-2 border-dashed border-line dark:border-slate-700">
                        <svg class="w-8 h-8" fill="none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M15.75 6a3.75 3.75 0 11-7.5 0 3.75 3.75 0 017.5 0zM4.501 20.118a7.5 7.5 0 0114.998 0A17.933 17.933 0 0112 21.75c-2.676 0-5.216-.584-7.499-1.632z"/></svg>
                    </span>
                </div>
                <div class="min-w-0">
                    <span class="{{ $label }}">Profile photo</span>
                    <div class="flex flex-wrap items-center gap-2">
                        <label class="{{ $btnGhost }} py-1.5 cursor-pointer">
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M6.827 6.175A2.31 2.31 0 015.186 7.23c-.38.054-.757.112-1.134.175C2.999 7.58 2.25 8.507 2.25 9.574V18a2.25 2.25 0 002.25 2.25h15A2.25 2.25 0 0021.75 18V9.574c0-1.067-.75-1.994-1.802-2.169a47.865 47.865 0 00-1.134-.175 2.31 2.31 0 01-1.64-1.055l-.822-1.316a2.192 2.192 0 00-1.736-1.039 48.774 48.774 0 00-5.232 0 2.192 2.192 0 00-1.736 1.039l-.821 1.316z"/><path stroke-linecap="round" stroke-linejoin="round" d="M16.5 12.75a4.5 4.5 0 11-9 0 4.5 4.5 0 019 0z"/></svg>
                            <span data-photo-label>Choose photo</span>
                            <input type="file" name="photo" accept="image/jpeg,image/png,image/webp" class="sr-only" data-photo-input>
                        </label>
                        <label data-photo-remove class="hidden items-center gap-1.5 text-xs text-ink-muted cursor-pointer"><input type="checkbox" name="remove_photo" value="1" class="rounded border-line text-rose-600 focus:ring-rose-500"> Remove photo</label>
                    </div>
                    <p class="mt-1 text-[11px] text-ink-muted">JPG, PNG or WEBP, up to 5 MB. A square photo looks best.</p>
                </div>
            </div>
            <div class="sm:col-span-2"><label class="{{ $label }}" for="e_name">Full name *</label><input id="e_name" name="full_name" required maxlength="120" class="{{ $input }}" placeholder="Juan Dela Cruz"></div>
            <div><label class="{{ $label }}" for="e_pos">Position</label><input id="e_pos" name="position" maxlength="120" class="{{ $input }}" placeholder="Telesales Agent"></div>
            <div>
                <label class="{{ $label }}" for="e_status">Status *</label>
                <select id="e_status" name="status" class="{{ $input }}">
                    @foreach(\App\Models\Employee::STATUSES as $k => $s)<option value="{{ $k }}">{{ $s['label'] }}</option>@endforeach
                </select>
            </div>
            <div>
                <label class="{{ $label }}" for="e_dept">Team</label>
                <select id="e_dept" name="department_id" class="{{ $input }}">
                    <option value="">— None —</option>
                    @foreach($departments as $d)<option value="{{ $d->id }}">{{ $d->name }}</option>@endforeach
                </select>
            </div>
            <div>
                <label class="{{ $label }}" for="e_mgr">Reports to</label>
                <select id="e_mgr" name="manager_id" class="{{ $input }}">
                    <option value="">— Nobody —</option>
                    @foreach($managerOptions as $m)<option value="{{ $m->id }}">{{ $m->full_name }}</option>@endforeach
                </select>
            </div>
            <div><label class="{{ $label }}" for="e_email">Email</label><input id="e_email" type="email" name="email" maxlength="160" class="{{ $input }}" placeholder="name@company.com"></div>
            <div><label class="{{ $label }}" for="e_phone">Phone</label><input id="e_phone" name="phone" maxlength="40" class="{{ $input }}" placeholder="09xx xxx xxxx"></div>
            <div><label class="{{ $label }}" for="e_hired">Date hired</label><input id="e_hired" type="date" name="hired_at" class="{{ $input }}"></div>
            <div><label class="{{ $label }}" for="e_left">Date left</label><input id="e_left" type="date" name="left_at" class="{{ $input }}"><p class="mt-1 text-[11px] text-ink-muted">Only kept when status is Resigned.</p></div>
            <div class="sm:col-span-2"><label class="{{ $label }}" for="e_notes">Notes</label><textarea id="e_notes" name="notes" rows="3" maxlength="5000" class="{{ $input }}"></textarea></div>
        </div>
        <div class="flex justify-end gap-2 border-t border-line dark:border-slate-800 bg-slate-50 dark:bg-slate-800/40 px-6 py-3 rounded-b-2xl">
            <button type="button" data-modal-close class="{{ $btnGhost }}">Cancel</button>
            <button type="submit" class="{{ $btnPrimary }}">Save person</button>
        </div>
    </form>
</dialog>

@once
@push('scripts')
<script>
(function () {
    const form = document.querySelector('[data-employee-form]');
    if (!form) return;
    const input = form.querySelector('[data-photo-input]');
    const img = form.querySelector('[data-photo-preview]');
    const empty = form.querySelector('[data-photo-empty]');
    const removeWrap = form.querySelector('[data-photo-remove]');
    const removeBox = removeWrap.querySelector('input');
    const label = form.querySelector('[data-photo-label]');
    let existing = '';

    function show(src) {
        img.classList.toggle('hidden', !src); empty.classList.toggle('hidden', !!src);
        if (src) img.src = src; else img.removeAttribute('src');
    }
    form.addEventListener('arc:filled', () => {
        existing = form.querySelector('[data-photo-src]').value || '';
        show(existing);
        removeWrap.classList.toggle('hidden', !existing); removeWrap.classList.toggle('inline-flex', !!existing);
        label.textContent = existing ? 'Change photo' : 'Choose photo';
    });
    input.addEventListener('change', () => {
        const f = input.files[0];
        if (!f) return show(existing);
        if (f.size > 5 * 1024 * 1024) { window.showToast?.('That photo is over 5 MB — pick a smaller one.', 'error'); input.value = ''; return show(existing); }
        removeBox.checked = false;
        show(URL.createObjectURL(f));
    });
    removeBox.addEventListener('change', () => show(removeBox.checked ? '' : existing));
})();
</script>
@endpush
@endonce
