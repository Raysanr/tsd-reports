{{-- Needs: $teams, $products, ArchiveUi classes. --}}
<dialog id="libraryModal" class="{{ $modal }} max-w-3xl">
    <form method="POST" action="{{ route('archive.library.store') }}">
        @csrf
        <input type="hidden" name="_method" value="POST" data-default="POST">
        <div class="flex items-center justify-between border-b border-line dark:border-slate-800 px-6 py-4">
            <h3 class="font-semibold" data-modal-title="New page">New page</h3>
            <button type="button" data-modal-close class="rounded-lg p-1.5 text-ink-muted hover:bg-slate-100 dark:hover:bg-slate-800 cursor-pointer" aria-label="Close"><svg class="w-5 h-5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" d="M6 18L18 6M6 6l12 12"/></svg></button>
        </div>
        <div class="grid grid-cols-1 sm:grid-cols-3 gap-4 px-6 py-5 max-h-[72vh] overflow-y-auto">
            <div class="sm:col-span-3">
                <span class="{{ $label }}">Page type *</span>
                <div class="grid grid-cols-2 sm:grid-cols-4 gap-2">
                    @foreach(\App\Models\LibraryItem::KINDS as $k => $t)
                    <label class="cursor-pointer"><input type="radio" name="kind" value="{{ $k }}" class="peer sr-only" @checked($k === 'script')>
                        <span class="flex items-center justify-center rounded-lg px-3 py-2 text-sm font-medium ring-1 ring-inset ring-line dark:ring-slate-700 peer-checked:bg-primary peer-checked:text-white peer-checked:ring-primary">{{ $t['label'] }}</span></label>
                    @endforeach
                </div>
            </div>
            <div class="sm:col-span-3">
                <label class="{{ $label }}" for="l_folder">Folder *</label>
                <select id="l_folder" name="folder" required class="{{ $input }}">
                    @foreach(\App\Support\DocumentedProcess::FOLDERS as $slug => $f)<option value="{{ $slug }}">{{ $f[0] }}</option>@endforeach
                </select>
            </div>
            <div class="sm:col-span-3"><label class="{{ $label }}" for="l_title">Title *</label><input id="l_title" name="title" required maxlength="200" class="{{ $input }}" placeholder="e.g. PteryLief — opening spiel"></div>
            <div><label class="{{ $label }}" for="l_prod">Product</label><select id="l_prod" name="product_id" class="{{ $input }}"><option value="">— Any —</option>@foreach($products as $p)<option value="{{ $p->id }}">{{ $p->display_name }}</option>@endforeach</select></div>
            <div><label class="{{ $label }}" for="l_team">Team</label><select id="l_team" name="department_id" class="{{ $input }}"><option value="">All teams</option>@foreach($teams as $t)<option value="{{ $t->id }}">{{ $t->name }}</option>@endforeach</select></div>
            <div><label class="{{ $label }}" for="l_note">Change note</label><input id="l_note" name="change_note" maxlength="255" class="{{ $input }}" placeholder="What changed?"></div>
            <div class="sm:col-span-3"><label class="{{ $label }}" for="l_body">Content *</label><textarea id="l_body" name="body" required rows="14" maxlength="50000" class="{{ $input }} font-mono text-[13px] leading-relaxed" placeholder="Write the script exactly as the TSA should say it…"></textarea></div>
        </div>
        <div class="flex items-center justify-between gap-2 border-t border-line dark:border-slate-800 bg-slate-50 dark:bg-slate-800/40 px-6 py-3 rounded-b-2xl">
            <p class="text-[11px] text-ink-muted">Editing the title or content saves a new version; older ones stay viewable.</p>
            <div class="flex gap-2"><button type="button" data-modal-close class="{{ $btnGhost }}">Cancel</button><button type="submit" class="{{ $btnPrimary }}">Save</button></div>
        </div>
    </form>
</dialog>
