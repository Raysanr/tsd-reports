{{-- New / edit team dialog (shared by Teams and Org Chart). Optional: $parentOptions, $employeeOptions. --}}
@php
    $parentOptions ??= \App\Models\Department::active()->orderBy('name')->get(['id', 'name']);
    $employeeOptions ??= \App\Models\Employee::current()->orderBy('full_name')->get(['id', 'full_name', 'department_id']);
@endphp
<dialog id="deptModal" class="{{ $modal }}">
    <form method="POST" action="{{ route('archive.departments.store') }}">
        @csrf
        <input type="hidden" name="_method" value="POST" data-default="POST">
        <div class="flex items-center justify-between border-b border-line dark:border-slate-800 px-6 py-4">
            <h3 class="font-semibold" data-modal-title="New team">New team</h3>
            <button type="button" data-modal-close class="rounded-lg p-1.5 text-ink-muted hover:bg-slate-100 dark:hover:bg-slate-800 cursor-pointer" aria-label="Close"><svg class="w-5 h-5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" d="M6 18L18 6M6 6l12 12"/></svg></button>
        </div>
        <div class="space-y-4 px-6 py-5 max-h-[70vh] overflow-y-auto">
            <div><label class="{{ $label }}" for="d_name">Team name *</label><input id="d_name" name="name" required maxlength="120" class="{{ $input }}" placeholder="e.g. Team Closing"></div>
            <div>
                <label class="{{ $label }}" for="d_parent">Part of (optional)</label>
                <select id="d_parent" name="parent_id" class="{{ $input }}">
                    <option value="">— Nothing, it's a main team —</option>
                    @foreach($parentOptions as $p)<option value="{{ $p->id }}">{{ $p->name }}</option>@endforeach
                </select>
            </div>
            <div>
                <label class="{{ $label }}" for="d_head">Head (optional — you can pick later)</label>
                <select id="d_head" name="head_employee_id" class="{{ $input }}">
                    <option value="">— Not assigned —</option>
                    @foreach($employeeOptions as $e)<option value="{{ $e->id }}">{{ $e->full_name }}</option>@endforeach
                </select>
            </div>
            <div>
                <span class="{{ $label }}">Color</span>
                <div class="flex flex-wrap gap-2">
                    @foreach(\App\Models\Department::COLORS as $key => $cc)
                    <label class="cursor-pointer">
                        <input type="radio" name="color" value="{{ $key }}" class="peer sr-only" @checked($key === 'sky')>
                        <span class="block w-8 h-8 rounded-full {{ $cc['dot'] }} ring-2 ring-transparent ring-offset-2 ring-offset-white dark:ring-offset-slate-900 peer-checked:ring-slate-900 dark:peer-checked:ring-white peer-focus-visible:ring-yellow-500 transition" title="{{ ucfirst($key) }}"></span>
                    </label>
                    @endforeach
                </div>
            </div>
            <details class="group rounded-xl ring-1 ring-line dark:ring-slate-800">
                <summary class="flex cursor-pointer list-none items-center justify-between px-4 py-3 text-sm font-medium text-ink-muted hover:text-ink dark:text-slate-400">
                    More details <span class="text-xs font-normal">code, description, order</span>
                    <svg class="w-4 h-4 transition group-open:rotate-180" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M19.5 8.25l-7.5 7.5-7.5-7.5"/></svg>
                </summary>
                <div class="space-y-4 border-t border-line dark:border-slate-800 p-4">
                    <div class="grid grid-cols-3 gap-3">
                        <div><label class="{{ $label }}" for="d_code">Code</label><input id="d_code" name="code" maxlength="20" class="{{ $input }} uppercase" placeholder="TSA-A"></div>
                        <div class="col-span-2"><label class="{{ $label }}" for="d_sort">Order on the chart</label><input id="d_sort" type="number" min="0" name="sort_order" class="{{ $input }}" placeholder="0 = first"></div>
                    </div>
                    <div><label class="{{ $label }}" for="d_desc">Description</label><textarea id="d_desc" name="description" rows="3" maxlength="2000" class="{{ $input }}" placeholder="What this team is responsible for"></textarea></div>
                </div>
            </details>
        </div>
        <div class="flex justify-end gap-2 border-t border-line dark:border-slate-800 bg-slate-50 dark:bg-slate-800/40 px-6 py-3 rounded-b-2xl">
            <button type="button" data-modal-close class="{{ $btnGhost }}">Cancel</button>
            <button type="submit" class="{{ $btnPrimary }}">Save team</button>
        </div>
    </form>
</dialog>

@once
@push('scripts')
<script>
// A department can't be its own parent — hide itself from the parent list while editing.
document.addEventListener('click', (e) => {
    const opener = e.target.closest('[data-modal-open="deptModal"]');
    if (!opener) return;
    document.querySelectorAll('#d_parent option').forEach((o) => { o.hidden = !!opener.dataset.self && o.value === opener.dataset.self; });
}, true);
</script>
@endpush
@endonce
