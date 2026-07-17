@extends('layouts.app')
@section('title', 'TSA Management')
@section('subtitle', 'Roster, teams, and shift schedules')

@section('content')
<div class="max-w-6xl">
<div class="flex flex-col lg:flex-row gap-6 items-start">
<div class="flex-1 min-w-0 space-y-6">

    @if($errors->any())
    <div class="px-5 py-4 bg-red-50 dark:bg-red-950/40 border border-red-200 dark:border-red-800 rounded-xl text-sm text-red-700 dark:text-red-400">
        @foreach($errors->all() as $error)<div>{{ $error }}</div>@endforeach
    </div>
    @endif

    <div class="flex items-center justify-between">
        <p class="text-xs text-slate-400 font-mono">Add, edit, or remove agents and set call shifts — all reflected immediately on TSA Performance and Leads Report.</p>
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
        <div class="bg-white dark:bg-slate-900 rounded-xl border border-yellow-100 dark:border-yellow-900 shadow-sm overflow-hidden">
            <div class="px-6 py-4 border-b border-slate-100 dark:border-slate-700 flex items-center gap-3">
                <div class="w-7 h-7 rounded-full bg-yellow-700 text-white text-xs font-bold flex items-center justify-center">
                    <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0"/>
                    </svg>
                </div>
                <div>
                    <h3 class="text-sm font-semibold text-slate-800 dark:text-slate-100">{{ $group['name'] }}</h3>
                    <p class="text-xs text-slate-500 dark:text-slate-400 mt-0.5">{{ $group['shifts']->count() }} {{ \Illuminate\Support\Str::plural('agent', $group['shifts']->count()) }}</p>
                </div>
            </div>

            @if($group['shifts']->isEmpty())
            <div class="py-10 text-center text-sm text-slate-400 font-mono">No TSAs on this team yet</div>
            @else
            <div class="divide-y divide-slate-100 dark:divide-slate-700">
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
                                   class="w-full text-sm font-mono font-semibold text-slate-700 dark:text-slate-200 border-0 border-b border-transparent
                                          hover:border-slate-200 dark:hover:border-slate-700 focus:border-yellow-400 focus:outline-none bg-transparent py-0.5 transition-colors"
                                   placeholder="Full name">
                            <p class="text-[10px] text-slate-400 font-mono">{{ $shift->tsa_key }}</p>
                        </div>
                    </div>

                    {{-- Shift time --}}
                    <div class="flex items-center gap-2 flex-1">
                        <label class="text-xs font-mono text-slate-400 shrink-0">Shift</label>
                        <input type="time" name="shifts[{{ $shift->tsa_key }}][shift_start]"
                               value="{{ $shift->shift_start }}"
                               class="border border-slate-200 dark:border-slate-700 bg-white dark:bg-slate-800 rounded-lg px-2 py-1.5 text-xs font-mono text-slate-700 dark:text-slate-200
                                      focus:outline-none focus:ring-2 focus:ring-yellow-400 cursor-pointer">
                        <span class="text-xs text-slate-400">to</span>
                        <input type="time" name="shifts[{{ $shift->tsa_key }}][shift_end]"
                               value="{{ $shift->shift_end }}"
                               class="border border-slate-200 dark:border-slate-700 bg-white dark:bg-slate-800 rounded-lg px-2 py-1.5 text-xs font-mono text-slate-700 dark:text-slate-200
                                      focus:outline-none focus:ring-2 focus:ring-yellow-400 cursor-pointer">
                        @if($shift->shift_start && $shift->shift_end)
                        <span class="text-xs font-mono text-slate-400 ml-1">{{ $shift->shift_range }}</span>
                        @endif
                    </div>

                    {{-- Row actions: these open/submit forms OUTSIDE this bulk-save
                         form (browsers don't support nested <form> elements) --}}
                    <div class="flex items-center gap-1 shrink-0">
                        <button type="button"
                            class="editTsaBtn p-1.5 rounded-lg text-slate-400 hover:text-yellow-600 dark:hover:text-yellow-400 hover:bg-yellow-50 dark:hover:bg-yellow-950/40 transition-colors cursor-pointer"
                            title="Edit"
                            data-id="{{ $shift->id }}"
                            data-tsa-key="{{ $shift->tsa_key }}"
                            data-display-name="{{ $shift->display_name }}"
                            data-team="{{ $shift->team }}"
                            data-extra="{{ $shift->extra_tag_keywords }}"
                            data-pos-user-id="{{ $shift->pos_user_id }}"
                            data-rest-day="{{ $shift->rest_day_of_week }}">
                            <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"/>
                            </svg>
                        </button>
                        <button type="button"
                            class="deleteTsaBtn p-1.5 rounded-lg text-slate-400 hover:text-red-600 dark:hover:text-red-400 hover:bg-red-50 dark:hover:bg-red-950/40 transition-colors cursor-pointer"
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
        <div class="bg-yellow-50 dark:bg-yellow-950/40 border border-yellow-200 dark:border-yellow-900 rounded-xl px-6 py-4">
            <p class="text-xs font-semibold text-yellow-800 dark:text-yellow-400 mb-1">Unassigned team</p>
            <p class="text-xs text-yellow-700 dark:text-yellow-400">{{ $unassigned->pluck('display_name')->join(', ') }} — team value doesn't match a configured team.</p>
        </div>
        @endif

        <div class="bg-white dark:bg-slate-900 rounded-xl border border-yellow-100 dark:border-yellow-900 shadow-sm px-6 py-4 flex items-center justify-between">
            <p class="text-xs text-slate-400 font-mono">Changes apply immediately on TSA Performance and Leads Report</p>
            <button type="submit"
                    class="inline-flex items-center gap-2 px-5 py-2 bg-yellow-700 hover:bg-yellow-800 text-white text-xs font-semibold rounded-lg transition-colors cursor-pointer">
                <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/>
                </svg>
                Save Schedules
            </button>
        </div>
    </form>

    {{-- Kept outside the bulk-save <form> above — browsers don't support nested
         <form> elements, and each restore button below is its own form. --}}
    @if($trashedShifts->isNotEmpty())
    <details class="bg-white dark:bg-slate-900 rounded-xl border border-slate-200 dark:border-slate-700 shadow-sm overflow-hidden">
        <summary class="px-6 py-4 cursor-pointer text-sm font-semibold text-slate-600 dark:text-slate-300 hover:bg-slate-50 dark:hover:bg-slate-800 transition-colors select-none">
            Removed ({{ $trashedShifts->count() }})
        </summary>
        <div class="divide-y divide-slate-100 dark:divide-slate-700 border-t border-slate-100 dark:border-slate-700">
            @foreach($trashedShifts as $shift)
            <div class="px-6 py-3 flex items-center gap-4 opacity-60">
                <div class="flex items-center gap-2.5 w-52 shrink-0">
                    <div class="w-7 h-7 rounded-full bg-slate-300 dark:bg-slate-700 flex items-center justify-center text-white text-[10px] font-bold shrink-0">
                        {{ strtoupper(substr($shift->display_name, 0, 2)) }}
                    </div>
                    <div>
                        <p class="text-sm font-mono font-semibold text-slate-700 dark:text-slate-200">{{ $shift->display_name }}</p>
                        <p class="text-[10px] text-slate-400 font-mono">{{ $shift->tsa_key }}</p>
                    </div>
                </div>
                <div class="flex-1">
                    <p class="text-xs text-slate-400 font-mono">{{ $shift->team }} — removed {{ $shift->deleted_at->diffForHumans() }}</p>
                </div>
                <form method="POST" action="{{ route('tsa-management.restore', $shift->id) }}">
                    @csrf
                    <button type="submit" class="px-3 py-1.5 text-xs font-semibold text-yellow-700 dark:text-yellow-400 border border-yellow-200 dark:border-yellow-900 rounded-lg hover:bg-yellow-50 dark:hover:bg-yellow-950/40 transition-colors cursor-pointer">
                        Restore
                    </button>
                </form>
            </div>
            @endforeach
        </div>
    </details>
    @endif

</div>

<div class="w-full lg:w-80 shrink-0">
    <div class="bg-white dark:bg-slate-900 rounded-xl border border-yellow-100 dark:border-yellow-900 shadow-sm overflow-hidden">
        <div class="px-5 py-4 border-b border-slate-100 dark:border-slate-700 flex items-center justify-between">
            <a href="{{ route('tsa-management', ['month' => $calendar['prev_month']]) }}"
               class="p-1.5 rounded-lg text-slate-400 hover:text-yellow-600 dark:hover:text-yellow-400 hover:bg-yellow-50 dark:hover:bg-yellow-950/40 transition-colors cursor-pointer">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M15 19l-7-7 7-7"/>
                </svg>
            </a>
            <h3 class="text-sm font-semibold text-slate-800 dark:text-slate-100">{{ $calendar['month_label'] }}</h3>
            <a href="{{ route('tsa-management', ['month' => $calendar['next_month']]) }}"
               class="p-1.5 rounded-lg text-slate-400 hover:text-yellow-600 dark:hover:text-yellow-400 hover:bg-yellow-50 dark:hover:bg-yellow-950/40 transition-colors cursor-pointer">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M9 5l7 7-7 7"/>
                </svg>
            </a>
        </div>
        <div class="p-4">
            <div class="grid grid-cols-7 gap-1 text-center text-[10px] font-mono text-slate-400 mb-2">
                <div>Sun</div><div>Mon</div><div>Tue</div><div>Wed</div><div>Thu</div><div>Fri</div><div>Sat</div>
            </div>
            <div class="grid grid-cols-7 gap-1">
                @for($i = 0; $i < $calendar['leading_blanks']; $i++)
                <div></div>
                @endfor
                @foreach($calendar['days'] as $dayData)
                <button type="button" class="restDayCell aspect-square rounded-lg border border-slate-100 dark:border-slate-700 hover:border-yellow-300 dark:hover:border-yellow-900 hover:bg-yellow-50 dark:hover:bg-yellow-950/40 transition-colors cursor-pointer p-1 flex flex-col items-center justify-start"
                    data-date="{{ $dayData['date'] }}" data-off="{{ $dayData['off_tsas']->pluck('tsa_key')->join(',') }}">
                    <span class="text-[11px] font-mono text-slate-500 dark:text-slate-400">{{ $dayData['day'] }}</span>
                    @if($dayData['off_tsas']->isNotEmpty())
                    <span class="text-[9px] font-mono text-yellow-700 dark:text-yellow-400 leading-tight text-center">
                        {{ $dayData['off_tsas']->pluck('initials')->join(' ') }}
                    </span>
                    @endif
                </button>
                @endforeach
            </div>
        </div>
    </div>
</div>

</div>
</div>

{{-- Shared Add / Edit modal --}}
<div id="tsaModal" class="hidden fixed inset-0 z-50 flex items-center justify-center bg-slate-900/40 px-4">
    <div class="bg-white dark:bg-slate-900 rounded-2xl shadow-2xl w-full max-w-sm overflow-hidden">
        <div class="px-6 py-5 border-b border-slate-100 dark:border-slate-700">
            <h3 id="tsaModalTitle" class="text-sm font-bold text-slate-800 dark:text-slate-100">Add a new TSA</h3>
            <p id="tsaModalSubtitle" class="text-xs text-slate-500 dark:text-slate-400 mt-0.5">They'll be recognized starting with the next sync</p>
        </div>
        <form id="tsaForm" method="POST" action="{{ route('tsa-management.store') }}" class="px-6 py-5 space-y-4">
            @csrf
            <input type="hidden" name="_method" id="tsaFormMethod" value="">
            <input type="hidden" name="pos_user_id" id="tsaPosUserId" value="">

            <div class="relative">
                <label class="block text-xs font-semibold text-slate-700 dark:text-slate-200 mb-1">TSA name</label>
                <input type="text" id="tsaNameInput" name="display_name" required autocomplete="off"
                    placeholder="Search Pancake POS accounts..."
                    class="w-full rounded-lg border border-slate-200 dark:border-slate-700 bg-white dark:bg-slate-800 px-3 py-2 text-sm text-slate-900 dark:text-slate-100 focus:outline-none focus:ring-2 focus:ring-yellow-500">
                <div id="tsaNameResults" class="hidden absolute z-10 mt-1 w-full bg-white dark:bg-slate-800 border border-slate-200 dark:border-slate-700 rounded-lg shadow-xl max-h-52 overflow-y-auto"></div>
                <p id="tsaLinkedHint" class="hidden text-[11px] text-green-600 dark:text-green-400 mt-1">
                    <svg class="w-3 h-3 inline -mt-0.5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7"/></svg>
                    Linked to a real POS account
                </p>
            </div>

            <div>
                <label class="block text-xs font-semibold text-slate-700 dark:text-slate-200 mb-1">Team</label>
                <select name="team" id="tsaTeamSelect" required
                    class="w-full rounded-lg border border-slate-200 dark:border-slate-700 bg-white dark:bg-slate-800 px-3 py-2 text-sm text-slate-900 dark:text-slate-100 focus:outline-none focus:ring-2 focus:ring-yellow-500">
                    @foreach($teamsConfig as $team)
                    <option value="{{ $team['order_team'] }}">{{ $team['name'] }}</option>
                    @endforeach
                </select>
            </div>

            <div class="relative">
                <label class="block text-xs font-semibold text-slate-700 dark:text-slate-200 mb-1">
                    Also matches <span class="text-slate-400 font-normal">(optional)</span>
                </label>
                <input type="hidden" name="extra_keywords" id="tsaExtraInput" value="">
                <div id="tsaTagChips" class="hidden flex-wrap gap-1.5 mb-2"></div>
                <input type="text" id="tsaTagSearch" autocomplete="off"
                    placeholder="Search Pancake tags..."
                    class="w-full rounded-lg border border-slate-200 dark:border-slate-700 bg-white dark:bg-slate-800 px-3 py-2 text-sm text-slate-900 dark:text-slate-100 focus:outline-none focus:ring-2 focus:ring-yellow-500">
                <div id="tsaTagResults" class="hidden absolute z-10 mt-1 w-full bg-white dark:bg-slate-800 border border-slate-200 dark:border-slate-700 rounded-lg shadow-xl max-h-52 overflow-y-auto"></div>
                <p class="text-[11px] text-slate-400 mt-1">Their first name is matched automatically — pick any other Pancake tags that should also count as theirs.</p>
            </div>

            <div>
                <label class="block text-xs font-semibold text-slate-700 dark:text-slate-200 mb-1">
                    Rest day <span class="text-slate-400 font-normal">(optional)</span>
                </label>
                <select name="rest_day_of_week" id="tsaRestDaySelect"
                    class="w-full rounded-lg border border-slate-200 dark:border-slate-700 bg-white dark:bg-slate-800 px-3 py-2 text-sm text-slate-900 dark:text-slate-100 focus:outline-none focus:ring-2 focus:ring-yellow-500">
                    <option value="">None</option>
                    <option value="sunday">Sunday</option>
                    <option value="monday">Monday</option>
                    <option value="tuesday">Tuesday</option>
                    <option value="wednesday">Wednesday</option>
                    <option value="thursday">Thursday</option>
                    <option value="friday">Friday</option>
                    <option value="saturday">Saturday</option>
                </select>
            </div>

            <div class="flex items-center justify-end gap-2 pt-2">
                <button type="button" id="cancelTsaModal" class="px-3 py-2 text-xs font-mono text-slate-600 dark:text-slate-400 hover:text-slate-800 dark:hover:text-slate-100 border border-slate-200 dark:border-slate-700 rounded-lg hover:bg-slate-50 dark:hover:bg-slate-800 transition-colors cursor-pointer">Cancel</button>
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

{{-- Rest Day modal — a separate modal instance from #tsaModal above, toggled the
     same way (hidden class + click handlers), so editing a date's rest days doesn't
     share state with the Add/Edit TSA modal. --}}
<div id="restDayModal" class="hidden fixed inset-0 z-50 flex items-center justify-center bg-slate-900/40 px-4">
    <div class="bg-white dark:bg-slate-900 rounded-2xl shadow-2xl w-full max-w-sm overflow-hidden">
        <div class="px-6 py-5 border-b border-slate-100 dark:border-slate-700">
            <h3 id="restDayModalTitle" class="text-sm font-bold text-slate-800 dark:text-slate-100">Rest days</h3>
        </div>
        <form id="restDayForm" method="POST" class="px-6 py-5 space-y-3">
            @csrf
            @foreach($shifts as $shift)
            <label class="flex items-center gap-2 text-sm text-slate-700 dark:text-slate-200">
                <input type="checkbox" name="tsas[]" value="{{ $shift->tsa_key }}" class="restDayCheckbox" data-tsa-key="{{ $shift->tsa_key }}">
                {{ $shift->display_name }}
            </label>
            @endforeach
            <div class="flex items-center justify-end gap-2 pt-2">
                <button type="button" id="cancelRestDayModal" class="px-3 py-2 text-xs font-mono text-slate-600 dark:text-slate-400 hover:text-slate-800 dark:hover:text-slate-100 border border-slate-200 dark:border-slate-700 rounded-lg hover:bg-slate-50 dark:hover:bg-slate-800 transition-colors cursor-pointer">Cancel</button>
                <button type="submit" class="px-4 py-2 text-xs font-semibold text-white bg-yellow-700 hover:bg-yellow-800 rounded-lg transition-colors cursor-pointer">Save</button>
            </div>
        </form>
    </div>
</div>

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
    const tagSearch     = document.getElementById('tsaTagSearch');
    const tagResults    = document.getElementById('tsaTagResults');
    const tagChips      = document.getElementById('tsaTagChips');
    const restDaySelect = document.getElementById('tsaRestDaySelect');
    const submitBtn     = document.getElementById('tsaSubmitBtn');
    const resultsBox    = document.getElementById('tsaNameResults');
    const linkedHint    = document.getElementById('tsaLinkedHint');
    const storeUrl      = form.action;

    let selectedTags  = [];
    let currentTsaKey = '';

    // Their first name is always auto-matched (see TsaShift::tag_keywords), so
    // offering it again in the "Also matches" picker is a trap: picking it looks
    // fine in the moment (chip shows, form saves) but TsaShift::extra_tag_keywords
    // diffs the base name back out on read, so it silently vanishes on reload —
    // reads exactly like "my saved tag disappeared". Editing an existing TSA uses
    // its real tsa_key (display_name can drift from it); a brand-new TSA doesn't
    // have one yet, so this mirrors generateUniqueKey()'s derivation from the name
    // field so far.
    function currentBaseKey() {
        if (currentTsaKey) return currentTsaKey.toUpperCase();
        const firstWord = (nameInput.value.trim().split(/\s+/)[0] || '').replace(/[^A-Za-z]/g, '');
        return firstWord.toUpperCase();
    }

    function openModal() { modal.classList.remove('hidden'); }
    function closeModal() {
        modal.classList.add('hidden');
        resultsBox.classList.add('hidden');
        tagResults.classList.add('hidden');
    }

    function setSelectedTags(tags) {
        selectedTags = tags;
        extraInput.value = selectedTags.join(',');
        renderTagChips();
    }

    function renderTagChips() {
        tagChips.innerHTML = '';
        if (!selectedTags.length) {
            tagChips.classList.add('hidden');
            tagChips.classList.remove('flex');
            return;
        }
        tagChips.classList.remove('hidden');
        tagChips.classList.add('flex');

        selectedTags.forEach(tag => {
            const chip = document.createElement('span');
            chip.className = 'inline-flex items-center gap-1 pl-2 pr-1 py-0.5 rounded-full bg-yellow-50 dark:bg-yellow-950/40 border border-yellow-200 dark:border-yellow-900 text-[11px] font-mono text-yellow-800 dark:text-yellow-400';

            const label = document.createElement('span');
            label.textContent = tag;
            chip.appendChild(label);

            const removeBtn = document.createElement('button');
            removeBtn.type = 'button';
            removeBtn.className = 'hover:text-yellow-950 dark:hover:text-yellow-200 cursor-pointer leading-none';
            removeBtn.textContent = '×';
            removeBtn.addEventListener('click', () => setSelectedTags(selectedTags.filter(t => t !== tag)));
            chip.appendChild(removeBtn);

            tagChips.appendChild(chip);
        });
    }

    function resetForm() {
        form.action = storeUrl;
        methodInput.value = '';
        posUserIdInput.value = '';
        nameInput.value = '';
        tagSearch.value = '';
        setSelectedTags([]);
        restDaySelect.value = '';
        teamSelect.selectedIndex = 0;
        currentTsaKey = '';
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
            currentTsaKey = btn.dataset.tsaKey || '';
            nameInput.value = btn.dataset.displayName || '';
            teamSelect.value = btn.dataset.team || '';
            const existingTags = (btn.dataset.extra || '').split(',').map(t => t.trim()).filter(Boolean);
            setSelectedTags(existingTags);
            restDaySelect.value = btn.dataset.restDay || '';
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
            if (!confirm(`Remove "${name}" from the roster? You can restore it from the Removed list below.`)) return;
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
            <div class="tsaResultRow px-3 py-2 text-sm text-slate-700 dark:text-slate-200 hover:bg-yellow-50 dark:hover:bg-yellow-950/40 hover:text-yellow-700 dark:hover:text-yellow-400 cursor-pointer" data-id="${u.id}" data-name="${u.name.replace(/"/g, '&quot;')}">
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

    // Searchable Pancake-tags picker for "Also matches"
    let tagDebounceTimer = null;
    let tagRequestId = 0;
    tagSearch.addEventListener('input', () => {
        clearTimeout(tagDebounceTimer);
        const q = tagSearch.value.trim();
        tagDebounceTimer = setTimeout(() => fetchTags(q), 250);
    });
    tagSearch.addEventListener('focus', () => {
        if (tagSearch.value.trim() !== '') fetchTags(tagSearch.value.trim());
    });

    async function fetchTags(q) {
        // Requests can resolve out of order (e.g. an uncached "" query outrunning
        // a cached, later-fired one) — a stale response would otherwise clobber
        // a newer, correctly-filtered result list. Only the most recently issued
        // request is allowed to render.
        const requestId = ++tagRequestId;
        try {
            const res = await fetch(`{{ route('tsa-management.tags') }}?q=` + encodeURIComponent(q));
            const tags = await res.json();
            if (requestId !== tagRequestId) return;
            renderTagResults(tags);
        } catch (e) {
            if (requestId === tagRequestId) tagResults.classList.add('hidden');
        }
    }

    function renderTagResults(tags) {
        const base = currentBaseKey();
        const visible = tags.filter(t => !selectedTags.includes(t.name));
        tagResults.innerHTML = '';

        if (!visible.length) { tagResults.classList.add('hidden'); return; }

        visible.forEach(t => {
            // Their own base name is always auto-matched (see TsaShift::tag_keywords)
            // — it's a real tag and shows up here since it exists in Pancake, but
            // picking it is a no-op that vanishes on reload (extra_tag_keywords diffs
            // it back out). Show it, greyed out, instead of hiding it outright —
            // hiding a tag that's visibly real in Pancake just reads as "this tool is
            // missing data".
            const isOwnBase = t.name.toUpperCase() === base;

            const row = document.createElement('div');
            row.className = isOwnBase
                ? 'px-3 py-2 text-sm text-slate-400 flex items-center justify-between gap-2 cursor-default'
                : 'px-3 py-2 text-sm text-slate-700 dark:text-slate-200 hover:bg-yellow-50 dark:hover:bg-yellow-950/40 hover:text-yellow-700 dark:hover:text-yellow-400 cursor-pointer flex items-center justify-between gap-2';

            const label = document.createElement('span');
            label.textContent = t.name;
            row.appendChild(label);

            const note = document.createElement('span');
            note.className = 'text-[10px] font-mono text-slate-300 dark:text-slate-600';
            note.textContent = isOwnBase ? 'auto-matched already' : t.count;
            row.appendChild(note);

            if (!isOwnBase) {
                // mousedown fires before the input's blur, so the click registers
                row.addEventListener('mousedown', (e) => {
                    e.preventDefault();
                    setSelectedTags([...selectedTags, t.name]);
                    tagSearch.value = '';
                    tagResults.classList.add('hidden');
                    tagSearch.focus();
                });
            }

            tagResults.appendChild(row);
        });

        tagResults.classList.remove('hidden');
    }

    document.addEventListener('click', (e) => {
        if (!resultsBox.contains(e.target) && e.target !== nameInput) {
            resultsBox.classList.add('hidden');
        }
        if (!tagResults.contains(e.target) && e.target !== tagSearch) {
            tagResults.classList.add('hidden');
        }
    });
})();
</script>
<script>
(function () {
    const restDayModal      = document.getElementById('restDayModal');
    const restDayForm       = document.getElementById('restDayForm');
    const restDayModalTitle = document.getElementById('restDayModalTitle');

    document.querySelectorAll('.restDayCell').forEach(cell => {
        cell.addEventListener('click', () => {
            const date    = cell.dataset.date;
            const offKeys = cell.dataset.off ? cell.dataset.off.split(',') : [];

            document.querySelectorAll('.restDayCheckbox').forEach(cb => {
                cb.checked = offKeys.includes(cb.dataset.tsaKey);
            });

            restDayForm.action = `{{ url('/tsa-management/rest-days') }}/${date}`;
            restDayModalTitle.textContent = 'Rest days — ' + new Date(date + 'T00:00:00')
                .toLocaleDateString('en-US', { month: 'long', day: 'numeric', year: 'numeric' });
            restDayModal.classList.remove('hidden');
        });
    });

    document.getElementById('cancelRestDayModal').addEventListener('click', () => {
        restDayModal.classList.add('hidden');
    });
    restDayModal.addEventListener('click', (e) => {
        if (e.target === restDayModal) restDayModal.classList.add('hidden');
    });
})();
</script>
@endpush
@endsection
