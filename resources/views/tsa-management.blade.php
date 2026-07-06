@extends('layouts.app')
@section('title', 'TSA Management')
@section('subtitle', 'Roster, teams, and shift schedules')

@section('content')
<div class="max-w-3xl space-y-6">

    @if(session('success'))
    <div class="flex items-center gap-3 bg-green-50 border border-green-200 rounded-xl px-5 py-4">
        <svg class="w-4 h-4 text-green-500 shrink-0" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7"/>
        </svg>
        <p class="text-sm font-mono text-green-700">{{ session('success') }}</p>
    </div>
    @endif

    @if($errors->any())
    <div class="px-5 py-4 bg-red-50 border border-red-200 rounded-xl text-sm text-red-700">
        @foreach($errors->all() as $error)<div>{{ $error }}</div>@endforeach
    </div>
    @endif

    <div class="flex items-center justify-between">
        <p class="text-xs text-slate-400 font-mono">Add, edit, or remove agents and set call shifts — all reflected immediately on TSA Performance and Team Report.</p>
        <button type="button" id="addTsaBtn"
            class="inline-flex items-center gap-1.5 px-4 py-2 bg-yellow-700 hover:bg-yellow-800 text-white text-xs font-semibold rounded-lg transition-colors cursor-pointer whitespace-nowrap shrink-0 ml-4">
            <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/>
            </svg>
            Add TSA
        </button>
    </div>

    <form method="POST" action="{{ route('settings.shifts') }}" class="space-y-6">
        @csrf

        @foreach($teamGroups as $group)
        <div class="bg-white rounded-xl border border-yellow-100 shadow-sm overflow-hidden">
            <div class="px-6 py-4 border-b border-slate-100 flex items-center gap-3">
                <div class="w-7 h-7 rounded-full bg-yellow-700 text-white text-xs font-bold flex items-center justify-center">
                    <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0"/>
                    </svg>
                </div>
                <div>
                    <h3 class="text-sm font-semibold text-slate-800">{{ $group['name'] }}</h3>
                    <p class="text-xs text-slate-500 mt-0.5">{{ $group['shifts']->count() }} {{ \Illuminate\Support\Str::plural('agent', $group['shifts']->count()) }}</p>
                </div>
            </div>

            @if($group['shifts']->isEmpty())
            <div class="py-10 text-center text-sm text-slate-400 font-mono">No TSAs on this team yet</div>
            @else
            <div class="divide-y divide-slate-100">
                @foreach($group['shifts'] as $shift)
                <div class="px-6 py-3 flex items-center gap-4">
                    {{-- Avatar + name --}}
                    <div class="flex items-center gap-2.5 w-52 shrink-0">
                        <div class="w-7 h-7 rounded-full bg-primary flex items-center justify-center text-white text-[10px] font-bold shrink-0">
                            {{ strtoupper(substr($shift->display_name, 0, 2)) }}
                        </div>
                        <div>
                            <input type="text" name="shifts[{{ $shift->tsa_key }}][display_name]"
                                   value="{{ $shift->display_name }}"
                                   class="w-full text-sm font-mono font-semibold text-slate-700 border-0 border-b border-transparent
                                          hover:border-slate-200 focus:border-yellow-400 focus:outline-none bg-transparent py-0.5 transition-colors"
                                   placeholder="Full name">
                            <p class="text-[10px] text-slate-400 font-mono">{{ $shift->tsa_key }}</p>
                        </div>
                    </div>

                    {{-- Shift time --}}
                    <div class="flex items-center gap-2 flex-1">
                        <label class="text-xs font-mono text-slate-400 shrink-0">Shift</label>
                        <input type="time" name="shifts[{{ $shift->tsa_key }}][shift_start]"
                               value="{{ $shift->shift_start }}"
                               class="border border-slate-200 rounded-lg px-2 py-1.5 text-xs font-mono text-slate-700
                                      focus:outline-none focus:ring-2 focus:ring-yellow-400 cursor-pointer">
                        <span class="text-xs text-slate-400">to</span>
                        <input type="time" name="shifts[{{ $shift->tsa_key }}][shift_end]"
                               value="{{ $shift->shift_end }}"
                               class="border border-slate-200 rounded-lg px-2 py-1.5 text-xs font-mono text-slate-700
                                      focus:outline-none focus:ring-2 focus:ring-yellow-400 cursor-pointer">
                        @if($shift->shift_start && $shift->shift_end)
                        <span class="text-xs font-mono text-slate-400 ml-1">{{ $shift->shift_range }}</span>
                        @endif
                    </div>

                    {{-- Row actions: these open/submit forms OUTSIDE this bulk-save
                         form (browsers don't support nested <form> elements) --}}
                    <div class="flex items-center gap-1 shrink-0">
                        <button type="button"
                            class="editTsaBtn p-1.5 rounded-lg text-slate-400 hover:text-yellow-600 hover:bg-yellow-50 transition-colors cursor-pointer"
                            title="Edit"
                            data-id="{{ $shift->id }}"
                            data-display-name="{{ $shift->display_name }}"
                            data-team="{{ $shift->team }}"
                            data-extra="{{ $shift->extra_tag_keywords }}"
                            data-pos-user-id="{{ $shift->pos_user_id }}">
                            <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"/>
                            </svg>
                        </button>
                        <button type="button"
                            class="deleteTsaBtn p-1.5 rounded-lg text-slate-400 hover:text-red-600 hover:bg-red-50 transition-colors cursor-pointer"
                            title="Remove"
                            data-id="{{ $shift->id }}"
                            data-name="{{ $shift->display_name }}">
                            <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6M9 7V4a1 1 0 011-1h4a1 1 0 011 1v3M4 7h16"/>
                            </svg>
                        </button>
                    </div>
                </div>
                @endforeach
            </div>
            @endif
        </div>
        @endforeach

        @if($unassigned->isNotEmpty())
        <div class="bg-yellow-50 border border-yellow-200 rounded-xl px-6 py-4">
            <p class="text-xs font-semibold text-yellow-800 mb-1">Unassigned team</p>
            <p class="text-xs text-yellow-700">{{ $unassigned->pluck('display_name')->join(', ') }} — team value doesn't match a configured team.</p>
        </div>
        @endif

        <div class="bg-white rounded-xl border border-yellow-100 shadow-sm px-6 py-4 flex items-center justify-between">
            <p class="text-xs text-slate-400 font-mono">Changes apply immediately on TSA Performance and Team Report</p>
            <button type="submit"
                    class="inline-flex items-center gap-2 px-5 py-2 bg-yellow-700 hover:bg-yellow-800 text-white text-xs font-semibold rounded-lg transition-colors cursor-pointer">
                <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/>
                </svg>
                Save Schedules
            </button>
        </div>
    </form>

</div>

{{-- Shared Add / Edit modal --}}
<div id="tsaModal" class="hidden fixed inset-0 z-50 flex items-center justify-center bg-slate-900/40 px-4">
    <div class="bg-white rounded-2xl shadow-2xl w-full max-w-sm overflow-hidden">
        <div class="px-6 py-5 border-b border-slate-100">
            <h3 id="tsaModalTitle" class="text-sm font-bold text-slate-800">Add a new TSA</h3>
            <p id="tsaModalSubtitle" class="text-xs text-slate-500 mt-0.5">They'll be recognized starting with the next sync</p>
        </div>
        <form id="tsaForm" method="POST" action="{{ route('tsa-management.store') }}" class="px-6 py-5 space-y-4">
            @csrf
            <input type="hidden" name="_method" id="tsaFormMethod" value="">
            <input type="hidden" name="pos_user_id" id="tsaPosUserId" value="">

            <div class="relative">
                <label class="block text-xs font-semibold text-slate-700 mb-1">TSA name</label>
                <input type="text" id="tsaNameInput" name="display_name" required autocomplete="off"
                    placeholder="Search Pancake POS accounts..."
                    class="w-full rounded-lg border border-slate-200 px-3 py-2 text-sm text-slate-900 focus:outline-none focus:ring-2 focus:ring-yellow-500">
                <div id="tsaNameResults" class="hidden absolute z-10 mt-1 w-full bg-white border border-slate-200 rounded-lg shadow-xl max-h-52 overflow-y-auto"></div>
                <p id="tsaLinkedHint" class="hidden text-[11px] text-green-600 mt-1">
                    <svg class="w-3 h-3 inline -mt-0.5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7"/></svg>
                    Linked to a real POS account
                </p>
            </div>

            <div>
                <label class="block text-xs font-semibold text-slate-700 mb-1">Team</label>
                <select name="team" id="tsaTeamSelect" required
                    class="w-full rounded-lg border border-slate-200 px-3 py-2 text-sm text-slate-900 focus:outline-none focus:ring-2 focus:ring-yellow-500">
                    @foreach($teamsConfig as $team)
                    <option value="{{ $team['order_team'] }}">{{ $team['name'] }}</option>
                    @endforeach
                </select>
            </div>

            <div>
                <label class="block text-xs font-semibold text-slate-700 mb-1">
                    Also matches <span class="text-slate-400 font-normal">(optional)</span>
                </label>
                <input type="text" name="extra_keywords" id="tsaExtraInput" placeholder="e.g. nicknames used in Pancake tags, comma-separated"
                    class="w-full rounded-lg border border-slate-200 px-3 py-2 text-sm text-slate-900 focus:outline-none focus:ring-2 focus:ring-yellow-500">
                <p class="text-[11px] text-slate-400 mt-1">Their first name is matched automatically — add this only if Pancake tags use a different spelling or nickname.</p>
            </div>

            <div class="flex items-center justify-end gap-2 pt-2">
                <button type="button" id="cancelTsaModal" class="px-3 py-2 text-xs font-mono text-slate-600 hover:text-slate-800 border border-slate-200 rounded-lg hover:bg-slate-50 transition-colors cursor-pointer">Cancel</button>
                <button type="submit" id="tsaSubmitBtn" class="px-4 py-2 text-xs font-semibold text-white bg-yellow-700 hover:bg-yellow-800 rounded-lg transition-colors cursor-pointer">Add TSA</button>
            </div>
        </form>
    </div>
</div>

{{-- Standalone delete form — kept outside the bulk-save <form> above --}}
<form id="deleteTsaForm" method="POST" style="display:none">
    @csrf
    @method('DELETE')
</form>

@push('scripts')
<script>
(function () {
    const modal        = document.getElementById('tsaModal');
    const modalTitle    = document.getElementById('tsaModalTitle');
    const modalSubtitle = document.getElementById('tsaModalSubtitle');
    const form          = document.getElementById('tsaForm');
    const methodInput   = document.getElementById('tsaFormMethod');
    const posUserIdInput = document.getElementById('tsaPosUserId');
    const nameInput     = document.getElementById('tsaNameInput');
    const teamSelect    = document.getElementById('tsaTeamSelect');
    const extraInput    = document.getElementById('tsaExtraInput');
    const submitBtn     = document.getElementById('tsaSubmitBtn');
    const resultsBox    = document.getElementById('tsaNameResults');
    const linkedHint    = document.getElementById('tsaLinkedHint');
    const storeUrl      = form.action;

    function openModal() { modal.classList.remove('hidden'); }
    function closeModal() { modal.classList.add('hidden'); resultsBox.classList.add('hidden'); }

    function resetForm() {
        form.action = storeUrl;
        methodInput.value = '';
        posUserIdInput.value = '';
        nameInput.value = '';
        extraInput.value = '';
        teamSelect.selectedIndex = 0;
        linkedHint.classList.add('hidden');
        modalTitle.textContent = 'Add a new TSA';
        modalSubtitle.textContent = "They'll be recognized starting with the next sync";
        submitBtn.textContent = 'Add TSA';
    }

    document.getElementById('addTsaBtn').addEventListener('click', () => { resetForm(); openModal(); });
    document.getElementById('cancelTsaModal').addEventListener('click', closeModal);
    modal.addEventListener('click', (e) => { if (e.target === modal) closeModal(); });

    // Edit — populate the same modal, point the form at the update route
    document.querySelectorAll('.editTsaBtn').forEach(btn => {
        btn.addEventListener('click', () => {
            resetForm();
            const id = btn.dataset.id;
            form.action = storeUrl + '/' + id;
            methodInput.value = 'PUT';
            nameInput.value = btn.dataset.displayName || '';
            teamSelect.value = btn.dataset.team || '';
            extraInput.value = btn.dataset.extra || '';
            posUserIdInput.value = btn.dataset.posUserId || '';
            if (btn.dataset.posUserId) linkedHint.classList.remove('hidden');
            modalTitle.textContent = 'Edit TSA';
            modalSubtitle.textContent = 'Changes apply starting with the next sync';
            submitBtn.textContent = 'Save Changes';
            openModal();
        });
    });

    // Delete — confirm, then submit the standalone hidden form
    const deleteForm = document.getElementById('deleteTsaForm');
    document.querySelectorAll('.deleteTsaBtn').forEach(btn => {
        btn.addEventListener('click', () => {
            const name = btn.dataset.name || 'this TSA';
            if (!confirm(`Remove "${name}" from the roster? This can't be undone.`)) return;
            deleteForm.action = storeUrl + '/' + btn.dataset.id;
            deleteForm.submit();
        });
    });

    // Searchable POS-account picker
    let debounceTimer = null;
    nameInput.addEventListener('input', () => {
        posUserIdInput.value = '';
        linkedHint.classList.add('hidden');
        clearTimeout(debounceTimer);
        const q = nameInput.value.trim();
        debounceTimer = setTimeout(() => fetchPosUsers(q), 250);
    });
    nameInput.addEventListener('focus', () => {
        if (nameInput.value.trim() !== '') fetchPosUsers(nameInput.value.trim());
    });

    async function fetchPosUsers(q) {
        try {
            const res = await fetch(`{{ route('tsa-management.pos-users') }}?q=` + encodeURIComponent(q));
            const users = await res.json();
            renderResults(users);
        } catch (e) {
            resultsBox.classList.add('hidden');
        }
    }

    function renderResults(users) {
        if (!users.length) { resultsBox.classList.add('hidden'); resultsBox.innerHTML = ''; return; }
        resultsBox.innerHTML = users.map(u => `
            <div class="tsaResultRow px-3 py-2 text-sm text-slate-700 hover:bg-yellow-50 hover:text-yellow-700 cursor-pointer" data-id="${u.id}" data-name="${u.name.replace(/"/g, '&quot;')}">
                ${u.name}
            </div>
        `).join('');
        resultsBox.classList.remove('hidden');

        resultsBox.querySelectorAll('.tsaResultRow').forEach(row => {
            // mousedown fires before the input's blur, so the click registers
            row.addEventListener('mousedown', (e) => {
                e.preventDefault();
                nameInput.value = row.dataset.name;
                posUserIdInput.value = row.dataset.id;
                linkedHint.classList.remove('hidden');
                resultsBox.classList.add('hidden');
            });
        });
    }

    document.addEventListener('click', (e) => {
        if (!resultsBox.contains(e.target) && e.target !== nameInput) {
            resultsBox.classList.add('hidden');
        }
    });
})();
</script>
@endpush
@endsection
