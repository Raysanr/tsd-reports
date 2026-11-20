{{-- Shared Org Chart / Teams tools (2026-11-20): searchable people picker
     (set head / add members), "Move to…" dialog, and the Team → Reports-to
     auto-fill used by every form with [data-team-select]. Needs ArchiveUi classes. --}}
@php
    $toolTeams = \App\Models\Department::active()->orderBy('sort_order')->orderBy('name')->get(['id', 'name', 'head_employee_id', 'color']);
    $toolPeople = \App\Models\Employee::current()->with('department:id,name')->orderBy('full_name')->get(['id', 'full_name', 'position', 'department_id', 'photo_path']);
@endphp

<dialog id="pickPeople" class="{{ $modal }}">
    <form method="POST" data-picker-form>
        @csrf
        <div class="flex items-center justify-between border-b border-line dark:border-slate-800 px-6 py-4">
            <div>
                <h3 class="font-semibold" data-modal-title="Pick people">Pick people</h3>
                <p class="text-xs text-ink-muted dark:text-slate-400" data-picker-hint></p>
            </div>
            <button type="button" data-modal-close class="rounded-lg p-1.5 text-ink-muted hover:bg-slate-100 dark:hover:bg-slate-800 cursor-pointer" aria-label="Close"><svg class="w-5 h-5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" d="M6 18L18 6M6 6l12 12"/></svg></button>
        </div>
        <div class="px-6 pt-4">
            <div class="relative">
                <svg class="pointer-events-none absolute left-3 top-1/2 -translate-y-1/2 w-4 h-4 text-slate-400" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" d="M21 21l-5.197-5.197m0 0A7.5 7.5 0 105.196 5.196a7.5 7.5 0 0010.607 10.607z"/></svg>
                <input type="search" data-picker-search placeholder="Type a name…" class="{{ $input }} pl-9" autocomplete="off">
            </div>
        </div>
        <ul data-picker-list class="mx-6 my-3 max-h-[50vh] overflow-y-auto divide-y divide-line dark:divide-slate-800 rounded-xl ring-1 ring-line dark:ring-slate-800">
            @foreach($toolPeople as $p)
            <li data-picker-row data-dept="{{ $p->department_id }}" data-name="{{ mb_strtolower($p->full_name . ' ' . $p->position . ' ' . $p->department?->name) }}">
                <label class="flex items-center gap-3 px-3 py-2 cursor-pointer hover:bg-slate-50 dark:hover:bg-slate-800/60 has-[:checked]:bg-yellow-50 dark:has-[:checked]:bg-yellow-500/10">
                    <input type="checkbox" value="{{ $p->id }}" class="rounded border-line text-primary focus:ring-primary">
                    @include('archive.partials.avatar', ['name' => $p->full_name, 'size' => 'sm', 'photo' => $p->photoUrl()])
                    <span class="min-w-0 flex-1">
                        <span class="block text-sm font-medium truncate">{{ $p->full_name }}</span>
                        <span class="block text-xs text-ink-muted dark:text-slate-400 truncate">{{ $p->position ?: 'No position' }} · <span data-picker-team>{{ $p->department?->name ?? 'No team' }}</span></span>
                    </span>
                    <span data-picker-tag class="hidden shrink-0 rounded-full bg-slate-100 dark:bg-slate-800 px-2 py-0.5 text-[11px] font-medium text-ink-muted">In this team</span>
                </label>
            </li>
            @endforeach
            <li data-picker-empty hidden class="px-3 py-6 text-center text-sm text-ink-muted">Nobody matches that name.</li>
        </ul>
        <div class="flex items-center justify-between gap-2 border-t border-line dark:border-slate-800 bg-slate-50 dark:bg-slate-800/40 px-6 py-3 rounded-b-2xl">
            <span class="text-xs text-ink-muted" data-picker-count></span>
            <div class="flex gap-2">
                <button type="button" data-modal-close class="{{ $btnGhost }}">Cancel</button>
                <button type="submit" class="{{ $btnPrimary }}" data-picker-submit disabled>Save</button>
            </div>
        </div>
    </form>
</dialog>

<dialog id="moveModal" class="{{ $modal }}">
    <form method="POST">
        @csrf @method('PATCH')
        <div class="flex items-center justify-between border-b border-line dark:border-slate-800 px-6 py-4">
            <h3 class="font-semibold" data-modal-title="Move">Move</h3>
            <button type="button" data-modal-close class="rounded-lg p-1.5 text-ink-muted hover:bg-slate-100 dark:hover:bg-slate-800 cursor-pointer" aria-label="Close"><svg class="w-5 h-5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" d="M6 18L18 6M6 6l12 12"/></svg></button>
        </div>
        <div class="space-y-4 px-6 py-5">
            <div>
                <label class="{{ $label }}" for="mv_team">Team</label>
                <select id="mv_team" name="department_id" data-team-select class="{{ $input }}">
                    <option value="">— No team —</option>
                    @foreach($toolTeams as $t)<option value="{{ $t->id }}" data-head="{{ $t->head_employee_id }}">{{ $t->name }}</option>@endforeach
                </select>
            </div>
            <div>
                <label class="{{ $label }}" for="mv_mgr">Reports to</label>
                <select id="mv_mgr" name="manager_id" data-manager-select class="{{ $input }}">
                    <option value="">— Nobody —</option>
                    @foreach($toolPeople as $p)<option value="{{ $p->id }}">{{ $p->full_name }}</option>@endforeach
                </select>
                <p class="mt-1 text-[11px] text-ink-muted">Picking a team fills in its head for you.</p>
            </div>
        </div>
        <div class="flex justify-end gap-2 border-t border-line dark:border-slate-800 bg-slate-50 dark:bg-slate-800/40 px-6 py-3 rounded-b-2xl">
            <button type="button" data-modal-close class="{{ $btnGhost }}">Cancel</button>
            <button type="submit" class="{{ $btnPrimary }}">Move</button>
        </div>
    </form>
</dialog>

@include('archive.partials.org-autofill-script')

@once
@push('scripts')
<script>
(function () {
    // ---- people picker: data-pick="head" (one person) | "members" (several), data-team="<id>"
    const dlg = document.getElementById('pickPeople');
    if (!dlg) return;
    const form = dlg.querySelector('form');
    const rows = [...dlg.querySelectorAll('[data-picker-row]')];
    const list = dlg.querySelector('[data-picker-list]');
    const search = dlg.querySelector('[data-picker-search]');
    const submit = dlg.querySelector('[data-picker-submit]');
    const count = dlg.querySelector('[data-picker-count]');
    const empty = dlg.querySelector('[data-picker-empty]');
    let mode = 'members';

    function refresh() {
        const n = rows.filter((r) => r.querySelector('input').checked).length;
        submit.disabled = n === 0;
        count.textContent = mode === 'members' ? (n ? `${n} selected` : 'Tick everyone to add') : '';
    }
    function filter() {
        const q = search.value.trim().toLowerCase();
        let shown = 0;
        rows.forEach((r) => { const ok = !q || r.dataset.name.includes(q); r.hidden = !ok || r.dataset.locked === '1'; if (!r.hidden) shown++; });
        empty.hidden = shown > 0;
    }
    document.addEventListener('click', (e) => {
        const opener = e.target.closest('[data-pick]');
        if (!opener) return;
        mode = opener.dataset.pick;
        const team = opener.dataset.team || '';
        dlg.querySelector('[data-picker-hint]').textContent = opener.dataset.hint || '';
        form.action = opener.dataset.action;
        submit.textContent = mode === 'head' ? 'Make head' : 'Add to team';
        rows.forEach((r) => {
            const box = r.querySelector('input');
            const inTeam = team && r.dataset.dept === team;
            box.type = mode === 'head' ? 'radio' : 'checkbox';
            box.name = mode === 'head' ? 'employee_id' : 'employee_ids[]';
            box.checked = mode === 'head' && opener.dataset.current && box.value === opener.dataset.current;
            // adding members: people already in the team are hidden
            r.dataset.locked = mode === 'members' && inTeam ? '1' : '';
            r.querySelector('[data-picker-tag]').classList.toggle('hidden', !(mode === 'head' && inTeam));
        });
        // picking a head: this team's own people first
        if (mode === 'head') rows.slice().sort((a, b) => (b.dataset.dept === team) - (a.dataset.dept === team)).forEach((r) => list.insertBefore(r, empty));
        search.value = ''; filter(); refresh();
        dlg.querySelectorAll('[data-modal-title]').forEach((el) => { el.textContent = opener.dataset.title || 'Pick people'; });
        document.getElementById('nodeActions')?.close();
        dlg.showModal();
        setTimeout(() => search.focus(), 50);
    });
    search.addEventListener('input', filter);
    list.addEventListener('change', refresh);
})();
</script>
@endpush
@endonce
