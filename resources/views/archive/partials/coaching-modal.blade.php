{{-- Needs: $employees and ArchiveUi classes. --}}
<dialog id="coachingModal" class="{{ $modal }} max-w-2xl">
    <form method="POST" action="{{ route('archive.coaching.store') }}" data-coaching-form>
        @csrf
        <input type="hidden" name="_method" value="POST" data-default="POST">
        <div class="flex items-center justify-between border-b border-line dark:border-slate-800 px-6 py-4">
            <h3 class="font-semibold" data-modal-title="New coaching / QA record">New coaching / QA record</h3>
            <button type="button" data-modal-close class="rounded-lg p-1.5 text-ink-muted hover:bg-slate-100 dark:hover:bg-slate-800 cursor-pointer" aria-label="Close"><svg class="w-5 h-5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" d="M6 18L18 6M6 6l12 12"/></svg></button>
        </div>
        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4 px-6 py-5 max-h-[70vh] overflow-y-auto">
            <div class="sm:col-span-2">
                <span class="{{ $label }}">Type *</span>
                <div class="grid grid-cols-2 gap-2">
                    @foreach(\App\Models\CoachingRecord::TYPES as $k => $t)
                    <label class="cursor-pointer">
                        <input type="radio" name="type" value="{{ $k }}" class="peer sr-only" @checked($k === 'coaching')>
                        <span class="flex items-center justify-center rounded-lg px-3 py-2 text-sm font-medium ring-1 ring-inset ring-line dark:ring-slate-700 peer-checked:bg-primary peer-checked:text-white peer-checked:ring-primary peer-focus-visible:ring-2 peer-focus-visible:ring-yellow-500">{{ $t['label'] }}</span>
                    </label>
                    @endforeach
                </div>
            </div>
            <div>
                <label class="{{ $label }}" for="c_emp">Person *</label>
                <select id="c_emp" name="employee_id" required class="{{ $input }}">
                    <option value="">— Choose —</option>
                    @foreach($employees as $e)<option value="{{ $e->id }}">{{ $e->full_name }}</option>@endforeach
                </select>
            </div>
            <div><label class="{{ $label }}" for="c_date">Date *</label><input id="c_date" type="date" name="session_date" required max="{{ today()->toDateString() }}" value="{{ today()->toDateString() }}" class="{{ $input }}"></div>
            <div class="sm:col-span-2"><label class="{{ $label }}" for="c_topic">Topic *</label><input id="c_topic" name="topic" required maxlength="160" class="{{ $input }}" placeholder="e.g. Handling price objections on upsell"></div>
            <div><label class="{{ $label }}" for="c_ref">Call / lead reference</label><input id="c_ref" name="reference" maxlength="120" class="{{ $input }}" placeholder="Order #, lead ID, recording…"></div>
            <div data-score-field><label class="{{ $label }}" for="c_score">QA score (0–100)</label><input id="c_score" type="number" name="score" min="0" max="100" step="0.5" class="{{ $input }}" placeholder="e.g. 88"></div>
            <div class="sm:col-span-2"><label class="{{ $label }}" for="c_notes">Observations</label><textarea id="c_notes" name="notes" rows="3" maxlength="5000" class="{{ $input }}" placeholder="What went well, what to improve"></textarea></div>
            <div class="sm:col-span-2"><label class="{{ $label }}" for="c_plan">Action plan</label><textarea id="c_plan" name="action_plan" rows="2" maxlength="5000" class="{{ $input }}" placeholder="Agreed next steps and follow-up date"></textarea></div>
        </div>
        <div class="flex justify-end gap-2 border-t border-line dark:border-slate-800 bg-slate-50 dark:bg-slate-800/40 px-6 py-3 rounded-b-2xl">
            <button type="button" data-modal-close class="{{ $btnGhost }}">Cancel</button>
            <button type="submit" class="{{ $btnPrimary }}">Save record</button>
        </div>
    </form>
</dialog>

@once
@push('scripts')
<script>
(function () {
    const form = document.querySelector('[data-coaching-form]');
    if (!form) return;
    const scoreField = form.querySelector('[data-score-field]');
    const sync = () => {
        const qa = form.querySelector('input[name="type"]:checked')?.value === 'qa';
        scoreField.classList.toggle('opacity-40', !qa);
        scoreField.querySelector('input').disabled = !qa;
    };
    form.addEventListener('change', sync);
    form.addEventListener('arc:filled', () => {
        if (!form.querySelector('input[name="type"]:checked')) form.querySelector('input[name="type"][value="coaching"]').checked = true;
        if (!form.querySelector('[name="session_date"]').value) form.querySelector('[name="session_date"]').value = '{{ today()->toDateString() }}';
        sync();
    });
    sync();
})();
</script>
@endpush
@endonce
