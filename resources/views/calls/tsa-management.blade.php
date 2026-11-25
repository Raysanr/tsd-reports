@extends('layouts.calls')
@section('title', 'TSA Management')
@section('subtitle', 'Live status, products and calling settings')

@section('content')

{{-- Layout (redesign, 2026-11-25 — design B "Table + Settings Panel"): the
     roster on the left, every global setting in the "How calling works"
     panel on the right. flex-wrap, so on a phone the panel drops below the
     table. The team filter pills, table, drag-to-pair and status dropdown
     are unchanged; only where things sit and how the settings read changed. --}}
<div class="flex flex-wrap items-start gap-6">
<div class="flex-[999_1_640px] min-w-0 flex flex-col gap-4">

    <div class="flex flex-wrap items-center justify-between gap-3">
        <div id="tsaMgmtFilter" class="relative inline-flex items-center gap-1 p-1 bg-slate-100 dark:bg-slate-800 rounded-xl max-w-full overflow-x-auto">
        <span id="tsaMgmtFilterHighlight" class="absolute inset-y-1 left-1 rounded-lg bg-white dark:bg-slate-900 shadow-sm transition-all duration-200 ease-out" style="width: 0"></span>

        {{-- team='' explicit, not omitted — an omitted param is
             indistinguishable from a fresh sidebar navigation to this page,
             which would wrongly leave the last-remembered team filter in
             place instead of actually clearing it (see
             PersistsCallTrackerFilters's own doc comment). --}}
        <a href="{{ route('calls.tsa-management', ['team' => '']) }}" data-team=""
           class="tsaMgmt-pill relative z-10 px-4 py-1.5 text-sm font-mono font-semibold rounded-lg transition-colors duration-200 {{ !$selectedTeam ? 'text-slate-800 dark:text-slate-100' : 'text-slate-500 dark:text-slate-400 hover:text-slate-700 dark:hover:text-slate-200' }}">
            All teams
        </a>
        {{-- Renamed-team-aware (explicit follow-up request, 2026-09-04) —
             $teams is now [order_team => display name] (Teams::config());
             the href/data-team keys stay the fixed order_team string, only
             the visible label uses the (possibly renamed) value. --}}
        @foreach($teams as $orderTeam => $displayName)
        <a href="{{ route('calls.tsa-management', ['team' => $orderTeam]) }}" data-team="{{ $orderTeam }}"
           class="tsaMgmt-pill relative z-10 px-4 py-1.5 text-sm font-mono font-semibold rounded-lg transition-colors duration-200 {{ $selectedTeam === $orderTeam ? 'text-slate-800 dark:text-slate-100' : 'text-slate-500 dark:text-slate-400 hover:text-slate-700 dark:hover:text-slate-200' }}">
            {{ $displayName }}
        </a>
        @endforeach
    </div>
    </div>

    {{-- Status key — what each colour in the "Right now" column means for
         lead assignment (RoundRobinAssigner::ELIGIBLE_STATUSES). --}}
    <div aria-label="Status key" class="flex flex-wrap gap-x-5 gap-y-1.5 text-xs text-slate-500 dark:text-slate-400">
        <span class="inline-flex items-center gap-1.5"><span class="w-2 h-2 rounded-full bg-emerald-500"></span>Ready · gets leads</span>
        <span class="inline-flex items-center gap-1.5"><span class="w-2 h-2 rounded-full bg-violet-500"></span>On a call · gets leads</span>
        <span class="inline-flex items-center gap-1.5"><span class="w-2 h-2 rounded-full bg-amber-500"></span>On break · paused</span>
        <span class="inline-flex items-center gap-1.5"><span class="w-2 h-2 rounded-full bg-slate-400"></span>Logged out</span>
    </div>

    <div id="tsaMgmtTableContainer" class="transition-opacity duration-150">
    @include('calls.tsa-management._table')
</div>

{{-- Auto-text message per product — explicit request, 2026-10-23 ("per
     product"). Each textarea autosaves on blur. Blank = that product's
     leads get no text (logged on the lead as skipped). --}}
<details id="smsTemplatesPanel" class="scroll-mt-6 bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-700 shadow-sm group/sms">
    <summary class="flex items-center justify-between gap-3 px-6 py-4 cursor-pointer select-none list-none">
        <div>
            <p class="text-xs font-mono font-semibold text-slate-500 dark:text-slate-400 uppercase tracking-wide">Auto-text messages</p>
            <p class="text-[11px] text-slate-400 mt-0.5">
                One message per product. Click <span class="font-mono font-semibold">{ }</span> to insert the customer's name, address and more — shown in blue, filled in per lead when sent.
                {{ $products->filter(fn ($p) => filled($p->sms_template))->count() }} of {{ $products->count() }} set.
            </p>
        </div>
        <svg class="w-4 h-4 text-slate-400 transition-transform duration-150 group-open/sms:rotate-180 shrink-0" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" d="M19 9l-7 7-7-7"/>
        </svg>
    </summary>
    <div class="grid grid-cols-1 lg:grid-cols-2 gap-3 px-6 pb-6">
        @foreach($products->sortBy(fn ($p) => [$p->team, $p->sort_order]) as $product)
        <div data-sms-editor>
            <div class="flex items-center justify-between gap-2 mb-1">
                <label for="smsTemplate-{{ $product->id }}" class="text-[11px] font-mono font-semibold text-slate-600 dark:text-slate-300 uppercase tracking-wide">{{ $product->display_name }}</label>
                <div class="flex items-center gap-2">
                    <span data-sms-template-status class="text-[11px] font-mono text-slate-400"></span>
                    <button type="button" data-sms-insert-btn title="Insert customer info"
                            class="inline-flex items-center justify-center h-6 w-7 rounded-md border border-slate-200 dark:border-slate-700 text-[11px] font-mono font-bold text-slate-500 dark:text-slate-300 hover:bg-slate-100 dark:hover:bg-slate-800 hover:text-slate-800 cursor-pointer">{ }</button>
                </div>
            </div>
            {{-- Blue placeholder highlight: the textarea's own text is
                 transparent (caret still shows) and sits over a mirror div
                 that renders the same text with {placeholders} wrapped in
                 blue — a textarea can't style part of its own text. Both
                 share one grid cell so the mirror's height drives the
                 auto-grow. Fonts/padding/border must stay identical. --}}
            <div class="grid text-sm leading-6">
                <div data-sms-mirror aria-hidden="true"
                     class="[grid-area:1/1] min-h-[4.25rem] whitespace-pre-wrap break-words border border-transparent rounded-lg px-3 py-2 text-slate-800 dark:text-slate-100 pointer-events-none"></div>
                <textarea id="smsTemplate-{{ $product->id }}" rows="2" spellcheck="false"
                          data-sms-template-action="{{ route('calls.tsa-management.sms-template', $product) }}"
                          placeholder="e.g. Hi {first_name}! Salamat sa order mo ng {product}. Tatawagan ka ni {tsa} shortly."
                          class="[grid-area:1/1] min-h-[4.25rem] w-full overflow-hidden resize-none border border-slate-300 dark:border-slate-600 rounded-lg px-3 py-2 bg-transparent text-transparent caret-slate-800 dark:caret-slate-100 placeholder:text-slate-400 selection:bg-blue-200/60 focus:outline-none focus:ring-2 focus:ring-yellow-500">{{ $product->sms_template }}</textarea>
            </div>
        </div>
        @endforeach
    </div>
</details>

</div>{{-- /roster column --}}

{{-- "How calling works" — every global Call Tracker setting in one panel,
     grouped, each with a sentence that says what happens in its current
     state (data-desc-on / data-desc-off, swapped by syncRuleDesc()). Every
     change saves by itself; the badge at the top shows Saving… / Saved.
     The switches keep their original ids, routes and JS. --}}
@php
    $rules = [
        'calling' => [
            ['id' => 'autoCall', 'label' => 'Auto-call', 'on' => $autoCallEnabled, 'route' => 'calls.tsa-management.toggle-auto-call',
             'descOn' => "Dials the next lead automatically.",
             'descOff' => 'Off. TSAs dial each lead.'],
        ],
        'messages' => [
            ['id' => 'autoSms', 'label' => 'Auto-text', 'on' => $autoSmsEnabled, 'route' => 'calls.tsa-management.toggle-auto-sms',
             'descOn' => "Texts each new lead.",
             'descOff' => 'Off. No texts sent.'],
        ],
        'orders' => [
            ['id' => 'autoTagging', 'label' => 'TSA name tag on orders', 'on' => $autoTaggingEnabled, 'route' => 'calls.tsa-management.toggle-auto-tagging',
             'descOn' => "Tags the Pancake order with the TSA's name.",
             'descOff' => 'Off. No name tag.'],
            ['id' => 'midnightAutoLogout', 'label' => 'Log everyone out at midnight', 'on' => $midnightAutoLogoutEnabled, 'route' => 'calls.tsa-management.toggle-midnight-auto-logout',
             'descOn' => 'Logs everyone out at 12:00 AM.',
             'descOff' => 'Off. TSAs stay logged in.'],
        ],
    ];
    $smsSet = $products->filter(fn ($p) => filled($p->sms_template))->count();
    $wrapPresets = [60, 120, 180, 300, 600];
@endphp
<aside aria-labelledby="callingSettingsHeading"
       class="flex-[1_1_320px] max-w-full lg:max-w-sm w-full bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-700 shadow-sm p-5 flex flex-col gap-5">
    <div class="flex items-start justify-between gap-3">
        <div>
            <h2 id="callingSettingsHeading" class="text-base font-bold text-slate-800 dark:text-slate-100">How calling works</h2>
            <p class="text-xs text-slate-500 dark:text-slate-400 mt-0.5">Applies to every TSA</p>
        </div>
        <span id="tsaSettingsSaveState" aria-live="polite" data-state="saved"
              class="inline-flex items-center gap-1 shrink-0 text-xs font-semibold rounded-full px-2.5 py-1 bg-emerald-50 dark:bg-emerald-900/30 text-emerald-700 dark:text-emerald-400">
            <svg data-save-icon class="w-3.5 h-3.5" fill="none" stroke="currentColor" stroke-width="2.6" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M5 12.5l4.5 4.5L19 7.5"/></svg>
            <span data-save-label>Saved</span>
        </span>
    </div>

    @foreach(['calling' => 'Calling', 'messages' => 'Messages', 'orders' => 'Orders and logins'] as $group => $groupLabel)
    <section class="flex flex-col gap-2">
        <h3 class="text-[11px] font-bold uppercase tracking-wider text-slate-500 dark:text-slate-400">{{ $groupLabel }}</h3>
        <div class="rounded-xl border border-slate-200 dark:border-slate-700 divide-y divide-slate-200 dark:divide-slate-700">
            @foreach($rules[$group] as $rule)
            <div class="flex items-center gap-4 px-4 py-3.5">
                <div class="flex-1 min-w-0">
                    <p class="text-sm font-semibold text-slate-800 dark:text-slate-100">{{ $rule['label'] }}</p>
                    <p id="{{ $rule['id'] }}Desc" data-desc-on="{{ $rule['descOn'] }}" data-desc-off="{{ $rule['descOff'] }}"
                       class="text-xs text-slate-500 dark:text-slate-400 leading-relaxed mt-0.5">{{ $rule['on'] ? $rule['descOn'] : $rule['descOff'] }}</p>
                </div>
                <button type="button" id="{{ $rule['id'] }}Toggle" aria-label="{{ $rule['label'] }}"
                        data-action="{{ route($rule['route']) }}"
                        data-enabled="{{ $rule['on'] ? '1' : '0' }}"
                        aria-pressed="{{ $rule['on'] ? 'true' : 'false' }}"
                        aria-describedby="{{ $rule['id'] }}Desc"
                        class="relative inline-flex h-6 w-11 shrink-0 items-center rounded-full transition-colors duration-300 ease-in-out cursor-pointer active:scale-95 {{ $rule['on'] ? 'bg-primary' : 'bg-slate-300 dark:bg-slate-600' }}">
                    <span id="{{ $rule['id'] }}Knob"
                          class="inline-block h-4 w-4 transform rounded-full bg-white shadow-md transition-transform duration-300 ease-[cubic-bezier(0.34,1.56,0.64,1)] will-change-transform {{ $rule['on'] ? 'translate-x-6' : 'translate-x-1' }}"></span>
                </button>
            </div>
            @endforeach

            @if($group === 'calling')
            {{-- Wrap Up length (2026-11-17; autosave 2026-11-25, no Save
                 button) — how long after a call before the TSA goes back to
                 Ready to Call. Stored as seconds; see ExpireWrapUpStatuses. --}}
            <div id="wrapUpForm" data-action="{{ route('calls.tsa-management.wrap-up-length') }}"
                 data-seconds="{{ $wrapUpSeconds }}" class="px-4 py-3.5 flex flex-col gap-3">
                <div>
                    <p class="text-sm font-semibold text-slate-800 dark:text-slate-100">Wrap Up time</p>
                    <p id="wrapUpDesc" class="text-xs text-slate-500 dark:text-slate-400 leading-relaxed mt-0.5"></p>
                </div>
                <div role="radiogroup" aria-label="Wrap Up time" class="grid grid-cols-5 gap-1.5">
                    @foreach($wrapPresets as $preset)
                    <button type="button" role="radio" data-wrap-preset="{{ $preset }}" aria-checked="{{ $wrapUpSeconds === $preset ? 'true' : 'false' }}"
                            class="h-10 rounded-lg border text-xs font-semibold cursor-pointer transition-colors">{{ $preset / 60 }}m</button>
                    @endforeach
                </div>
                <div class="flex items-center gap-2 text-xs text-slate-500 dark:text-slate-400">
                    <label for="wrapUpValue">Custom</label>
                    <input id="wrapUpValue" type="number" min="1" max="3600" step="1"
                           value="{{ $wrapUpSeconds % 60 === 0 ? $wrapUpSeconds / 60 : $wrapUpSeconds }}"
                           class="w-20 h-9 rounded-lg border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-800 px-2 text-sm text-slate-700 dark:text-slate-200">
                    <select id="wrapUpUnit" aria-label="Wrap Up unit"
                            class="h-9 rounded-lg border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-800 px-2 text-sm text-slate-700 dark:text-slate-200">
                        <option value="seconds" @selected($wrapUpSeconds % 60 !== 0)>seconds</option>
                        <option value="minutes" @selected($wrapUpSeconds % 60 === 0)>minutes</option>
                    </select>
                </div>
            </div>
            @endif

            @if($group === 'messages')
            <a href="#smsTemplatesPanel" data-open-sms-templates
               class="flex items-center justify-between gap-3 px-4 py-3 text-xs text-slate-600 dark:text-slate-300 hover:bg-slate-50 dark:hover:bg-slate-800/60 rounded-b-xl">
                <span><b class="font-semibold text-slate-800 dark:text-slate-100">{{ $smsSet }}/{{ $products->count() }}</b> messages set</span>
                <span class="inline-flex items-center gap-1 font-semibold text-primary-dark dark:text-yellow-400">Edit messages
                    <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M9 6l6 6-6 6"/></svg>
                </span>
            </a>
            @endif
        </div>
    </section>
    @endforeach
</aside>
</div>{{-- /layout --}}

{{-- Shared { } insert menu for the auto-text editors above. --}}
<div id="smsInsertMenu" role="menu"
     class="hidden fixed z-50 w-56 rounded-xl border border-slate-200 dark:border-slate-700 bg-white dark:bg-slate-900 shadow-xl p-1.5">
    @php
        $smsIcons = [
            'first_name' => 'M15 9h3m-3 3h3m-9 4.5h6M4.5 5.25h15A1.5 1.5 0 0121 6.75v10.5a1.5 1.5 0 01-1.5 1.5h-15A1.5 1.5 0 013 17.25V6.75a1.5 1.5 0 011.5-1.5zM9 10.5a1.5 1.5 0 11-3 0 1.5 1.5 0 013 0z',
            'last_name'  => 'M6.75 3h10.5A1.5 1.5 0 0118.75 4.5v15a1.5 1.5 0 01-1.5 1.5H6.75a1.5 1.5 0 01-1.5-1.5v-15A1.5 1.5 0 016.75 3zM9 16.5h6M14.25 10.5a2.25 2.25 0 11-4.5 0 2.25 2.25 0 014.5 0z',
            'full_name'  => 'M17.982 18.725A7.488 7.488 0 0012 15.75a7.488 7.488 0 00-5.982 2.975m11.964 0a9 9 0 10-11.963 0m11.963 0A8.966 8.966 0 0112 21a8.966 8.966 0 01-5.982-2.275M15 9.75a3 3 0 11-6 0 3 3 0 016 0z',
            'phone'      => 'M2.25 6.75c0 8.284 6.716 15 15 15h2.25a2.25 2.25 0 002.25-2.25v-1.372c0-.516-.351-.966-.852-1.091l-4.423-1.106a1.125 1.125 0 00-1.173.417l-.97 1.293a1.125 1.125 0 01-1.21.38 12.035 12.035 0 01-7.143-7.143 1.125 1.125 0 01.38-1.21l1.293-.97c.363-.271.527-.734.417-1.173L6.963 3.102a1.125 1.125 0 00-1.091-.852H4.5A2.25 2.25 0 002.25 4.5v2.25z',
            'address'    => 'M15 10.5a3 3 0 11-6 0 3 3 0 016 0zM19.5 10.5c0 7.142-7.5 11.25-7.5 11.25S4.5 17.642 4.5 10.5a7.5 7.5 0 1115 0z',
            'product'    => 'M20.25 7.5l-.625 10.632a2.25 2.25 0 01-2.247 2.118H6.622a2.25 2.25 0 01-2.247-2.118L3.75 7.5M10 11.25h4M3.375 7.5h17.25c.621 0 1.125-.504 1.125-1.125v-1.5c0-.621-.504-1.125-1.125-1.125H3.375c-.621 0-1.125.504-1.125 1.125v1.5c0 .621.504 1.125 1.125 1.125z',
            'tsa'        => 'M15.75 6a3.75 3.75 0 11-7.5 0 3.75 3.75 0 017.5 0zM4.501 20.118a7.5 7.5 0 0114.998 0A17.933 17.933 0 0112 21.75c-2.676 0-5.216-.584-7.499-1.632z',
        ];
    @endphp
    @foreach(\App\Support\LeadAutoText::PLACEHOLDERS as $key => $label)
    <button type="button" role="menuitem" data-sms-insert="{{ '{' . $key . '}' }}"
            class="w-full flex items-center gap-3 rounded-lg px-3 py-2 text-sm text-slate-700 dark:text-slate-200 hover:bg-slate-100 dark:hover:bg-slate-800 cursor-pointer text-left">
        <svg class="w-5 h-5 text-slate-500 shrink-0" fill="none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="{{ $smsIcons[$key] }}"/></svg>
        {{ $label }}
    </button>
    @endforeach
</div>



{{-- Pair-confirm modal — replaces a plain browser confirm() (explicit
     follow-up request, 2026-09-11: "create a modal for this") for the
     drag-to-pair action below. Copy is filled in by JS right before
     showing it (see the drag handler), same "one shared shell, text
     swapped per use" idea as this page's own Add TSA modal reusing one
     DOM node rather than building one modal per possible message. --}}
<div id="pairConfirmModal" class="hidden fixed inset-0 z-50 items-center justify-center bg-black/50 p-6">
    <div class="bg-white dark:bg-slate-900 rounded-2xl shadow-2xl w-full max-w-sm overflow-hidden">
        <div class="px-6 py-5 border-b border-slate-100 dark:border-slate-700 flex items-start gap-3">
            <span class="shrink-0 w-9 h-9 rounded-full bg-amber-100 dark:bg-amber-900/40 text-amber-600 dark:text-amber-400 flex items-center justify-center">
                <svg class="w-4.5 h-4.5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M13 10V3L4 14h7v7l9-11h-7z"/></svg>
            </span>
            <div>
                <h3 class="text-sm font-bold text-slate-800 dark:text-slate-100">Pair these TSAs?</h3>
                <p id="pairConfirmBody" class="text-xs text-slate-500 dark:text-slate-400 mt-1"></p>
            </div>
        </div>
        <div class="flex items-center justify-end gap-2 px-6 py-4">
            <button type="button" id="pairConfirmCancel"
                    class="px-3 py-2 text-xs font-mono text-slate-600 dark:text-slate-300 hover:text-slate-800 dark:hover:text-slate-100 border border-slate-200 dark:border-slate-700 rounded-lg hover:bg-slate-50 dark:hover:bg-slate-800 transition-colors cursor-pointer">
                Cancel
            </button>
            <button type="button" id="pairConfirmOk"
                    class="px-4 py-2 text-xs font-semibold text-white bg-primary hover:bg-primary-dark rounded-lg transition-colors cursor-pointer">
                Pair them
            </button>
        </div>
    </div>
</div>

{{-- Unpair-confirm modal — same shared-shell idea as pairConfirmModal
     above, for the "x" button on a pair's badge. --}}
<div id="unpairConfirmModal" class="hidden fixed inset-0 z-50 items-center justify-center bg-black/50 p-6">
    <div class="bg-white dark:bg-slate-900 rounded-2xl shadow-2xl w-full max-w-sm overflow-hidden">
        <div class="px-6 py-5 border-b border-slate-100 dark:border-slate-700 flex items-start gap-3">
            <span class="shrink-0 w-9 h-9 rounded-full bg-slate-100 dark:bg-slate-800 text-slate-500 dark:text-slate-400 flex items-center justify-center">
                <svg class="w-4.5 h-4.5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M13 13H7m0-8v13a2 2 0 002 2h6a2 2 0 002-2V5H7z"/></svg>
            </span>
            <div>
                <h3 class="text-sm font-bold text-slate-800 dark:text-slate-100">Unpair this TSA?</h3>
                <p id="unpairConfirmBody" class="text-xs text-slate-500 dark:text-slate-400 mt-1"></p>
            </div>
        </div>
        <div class="flex items-center justify-end gap-2 px-6 py-4">
            <button type="button" id="unpairConfirmCancel"
                    class="px-3 py-2 text-xs font-mono text-slate-600 dark:text-slate-300 hover:text-slate-800 dark:hover:text-slate-100 border border-slate-200 dark:border-slate-700 rounded-lg hover:bg-slate-50 dark:hover:bg-slate-800 transition-colors cursor-pointer">
                Cancel
            </button>
            <button type="button" id="unpairConfirmOk"
                    class="px-4 py-2 text-xs font-semibold text-white bg-red-600 hover:bg-red-700 rounded-lg transition-colors cursor-pointer">
                Unpair
            </button>
        </div>
    </div>
</div>

{{-- Cross-team pair picker — explicit request, 2026-09-11: "the tsa is
     will be like not move to the other team it will only like pair to
     the other team." Dragging a row from the currently-viewed team's
     table onto a DIFFERENT team's filter pill opens this small popover
     (not a full modal — it's a quick pick, not a decision needing the
     weight of a modal) listing that team's own unpaired TSAs
     (TsaManagementController::pairCandidates()); picking one calls the
     same pair() endpoint the same-team drag-onto-row flow already uses.
     Positioned dynamically under whichever pill was dropped onto — see
     the drag handler below. --}}
<div id="crossTeamPairPicker" class="hidden fixed z-50 w-64 bg-white dark:bg-slate-900 rounded-xl border border-slate-200 dark:border-slate-700 shadow-xl overflow-hidden">
    <div class="px-3 py-2 border-b border-slate-100 dark:border-slate-700">
        <p id="crossTeamPairPickerTitle" class="text-xs font-mono font-semibold text-slate-600 dark:text-slate-300"></p>
    </div>
    <div id="crossTeamPairPickerList" class="max-h-56 overflow-y-auto py-1">
        {{-- Populated by the drag handler below --}}
    </div>
</div>

<script>
(function () {
    // "How calling works" panel helpers (redesign, 2026-11-25): the badge
    // at the top of the panel replaces a toast per save, and each switch's
    // sentence follows its state.
    const saveBadge = document.getElementById('tsaSettingsSaveState');
    const SAVE_STYLES = {
        saving: ['Saving…', 'bg-slate-100 dark:bg-slate-800 text-slate-600 dark:text-slate-300'],
        saved:  ['Saved', 'bg-emerald-50 dark:bg-emerald-900/30 text-emerald-700 dark:text-emerald-400'],
        error:  ['Not saved', 'bg-red-50 dark:bg-red-900/30 text-red-700 dark:text-red-400'],
    };
    let pendingSaves = 0;
    window.tsaSettingsSaveState = function (state) {
        if (state === 'saving') pendingSaves++;
        else pendingSaves = Math.max(0, pendingSaves - 1);
        if (state === 'saved' && pendingSaves > 0) state = 'saving';
        if (!saveBadge) return;
        const all = Object.values(SAVE_STYLES).flatMap(([, cls]) => cls.split(' '));
        saveBadge.classList.remove(...all);
        saveBadge.classList.add(...SAVE_STYLES[state][1].split(' '));
        saveBadge.querySelector('[data-save-label]').textContent = SAVE_STYLES[state][0];
        saveBadge.querySelector('[data-save-icon]')?.classList.toggle('hidden', state !== 'saved');
        saveBadge.dataset.state = state;
    };
    function syncRuleDesc(toggle) {
        const desc = document.getElementById(toggle.id.replace(/Toggle$/, 'Desc'));
        if (desc) desc.textContent = toggle.dataset.enabled === '1' ? desc.dataset.descOn : desc.dataset.descOff;
    }
    document.querySelectorAll('[data-open-sms-templates]').forEach((link) => {
        link.addEventListener('click', (e) => {
            const panel = document.getElementById('smsTemplatesPanel');
            if (!panel) return;
            e.preventDefault();
            panel.open = true;
            panel.scrollIntoView({ behavior: 'smooth', block: 'start' });
        });
    });

    // Team filter — identical animated-pill + AJAX-swap pattern as Leads
    // Setup (round-robin-setup.blade.php); kept as its own copy rather than
    // a shared include since the two pages' container/pill ids differ and
    // this is the only place either needs it.
    const filter = document.getElementById('tsaMgmtFilter');
    const highlight = document.getElementById('tsaMgmtFilterHighlight');
    const container = document.getElementById('tsaMgmtTableContainer');
    if (filter && container) {
        function positionHighlight(pill, animate) {
            if (!pill) return;
            highlight.style.transitionDuration = animate ? '200ms' : '0ms';
            highlight.style.width = pill.offsetWidth + 'px';
            highlight.style.transform = `translateX(${pill.offsetLeft - 4}px)`;
        }

        function setActivePill(team) {
            filter.querySelectorAll('.tsaMgmt-pill').forEach((pill) => {
                const active = pill.dataset.team === team;
                pill.classList.toggle('text-slate-800', active);
                pill.classList.toggle('dark:text-slate-100', active);
                pill.classList.toggle('text-slate-500', !active);
                pill.classList.toggle('dark:text-slate-400', !active);
                if (active) positionHighlight(pill, true);
            });
        }

        positionHighlight(filter.querySelector('.tsaMgmt-pill.text-slate-800'), false);

        filter.querySelectorAll('.tsaMgmt-pill').forEach((pill) => {
            pill.addEventListener('click', (e) => {
                e.preventDefault();
                const team = pill.dataset.team;
                setActivePill(team);

                container.style.opacity = '0';
                fetch(pill.href, { headers: { 'X-Table-Refresh': '1' } })
                    .then((res) => (res.ok ? res.text() : null))
                    .then((html) => {
                        if (html === null) return;
                        container.innerHTML = html;
                        container.style.opacity = '1';
                        history.pushState({}, '', pill.href);
                    })
                    .catch(() => { container.style.opacity = '1'; });
            });
        });

        window.addEventListener('popstate', () => window.location.reload());
    }

    // Global POS name tag auto-tagging switch — single AJAX toggle, same
    // CSRF-header fetch convention as calls.js's other AJAX actions
    // (regenerate-token, etc.). Flips the button's visual state right away
    // and rolls it back if the request fails, so an admin never sees a
    // switch that silently didn't take.
    const autoTaggingToggle = document.getElementById('autoTaggingToggle');
    const autoTaggingKnob   = document.getElementById('autoTaggingKnob');
    if (autoTaggingToggle && autoTaggingKnob) {
        function paintAutoTagging(enabled) {
            autoTaggingToggle.dataset.enabled = enabled ? '1' : '0';
            autoTaggingToggle.setAttribute('aria-pressed', enabled ? 'true' : 'false'); syncRuleDesc(autoTaggingToggle);
            autoTaggingToggle.classList.toggle('bg-primary', enabled);
            autoTaggingToggle.classList.toggle('bg-slate-300', !enabled);
            autoTaggingToggle.classList.toggle('dark:bg-slate-600', !enabled);
            autoTaggingKnob.classList.toggle('translate-x-6', enabled);
            autoTaggingKnob.classList.toggle('translate-x-1', !enabled);
        }

        autoTaggingToggle.addEventListener('click', () => {
            const next = autoTaggingToggle.dataset.enabled !== '1';
            paintAutoTagging(next);
            autoTaggingToggle.disabled = true; window.tsaSettingsSaveState('saving');

            fetch(autoTaggingToggle.dataset.action, {
                method: 'POST',
                headers: {
                    'X-Requested-With': 'XMLHttpRequest',
                    'Accept': 'application/json',
                    'Content-Type': 'application/x-www-form-urlencoded',
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || '',
                },
                body: `enabled=${next ? '1' : '0'}`,
            })
                .then((res) => (res.ok ? res.json() : Promise.reject(res)))
                .then((data) => {
                    window.tsaSettingsSaveState('saved');
                })
                .catch(async (res) => {
                    paintAutoTagging(!next); window.tsaSettingsSaveState('error');
                    let message = 'Could not save — try again.';
                    if (res?.json) {
                        try {
                            const data = await res.json();
                            message = data.message || message;
                        } catch (e) { /* not JSON — keep the generic message */ }
                    }
                    window.showToast?.(message, 'error');
                })
                .finally(() => { autoTaggingToggle.disabled = false; });
        });
    }

    // Global midnight auto-logout switch — same shape/behavior as the POS
    // name tag toggle right above (single AJAX toggle, optimistic paint,
    // rollback on failure).
    const midnightAutoLogoutToggle = document.getElementById('midnightAutoLogoutToggle');
    const midnightAutoLogoutKnob   = document.getElementById('midnightAutoLogoutKnob');
    if (midnightAutoLogoutToggle && midnightAutoLogoutKnob) {
        function paintMidnightAutoLogout(enabled) {
            midnightAutoLogoutToggle.dataset.enabled = enabled ? '1' : '0';
            midnightAutoLogoutToggle.setAttribute('aria-pressed', enabled ? 'true' : 'false'); syncRuleDesc(midnightAutoLogoutToggle);
            midnightAutoLogoutToggle.classList.toggle('bg-primary', enabled);
            midnightAutoLogoutToggle.classList.toggle('bg-slate-300', !enabled);
            midnightAutoLogoutToggle.classList.toggle('dark:bg-slate-600', !enabled);
            midnightAutoLogoutKnob.classList.toggle('translate-x-6', enabled);
            midnightAutoLogoutKnob.classList.toggle('translate-x-1', !enabled);
        }

        midnightAutoLogoutToggle.addEventListener('click', () => {
            const next = midnightAutoLogoutToggle.dataset.enabled !== '1';
            paintMidnightAutoLogout(next);
            midnightAutoLogoutToggle.disabled = true; window.tsaSettingsSaveState('saving');

            fetch(midnightAutoLogoutToggle.dataset.action, {
                method: 'POST',
                headers: {
                    'X-Requested-With': 'XMLHttpRequest',
                    'Accept': 'application/json',
                    'Content-Type': 'application/x-www-form-urlencoded',
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || '',
                },
                body: `enabled=${next ? '1' : '0'}`,
            })
                .then((res) => (res.ok ? res.json() : Promise.reject(res)))
                .then((data) => {
                    window.tsaSettingsSaveState('saved');
                })
                .catch(async (res) => {
                    paintMidnightAutoLogout(!next); window.tsaSettingsSaveState('error');
                    let message = 'Could not save — try again.';
                    if (res?.json) {
                        try {
                            const data = await res.json();
                            message = data.message || message;
                        } catch (e) { /* not JSON — keep the generic message */ }
                    }
                    window.showToast?.(message, 'error');
                })
                .finally(() => { midnightAutoLogoutToggle.disabled = false; });
        });
    }

    // Global auto-call switch (2026-10-26) — same optimistic-paint/rollback
    // shape as the midnight auto-logout toggle above.
    const autoCallToggle = document.getElementById('autoCallToggle');
    const autoCallKnob   = document.getElementById('autoCallKnob');
    if (autoCallToggle && autoCallKnob) {
        const paintAutoCall = (enabled) => {
            autoCallToggle.dataset.enabled = enabled ? '1' : '0';
            autoCallToggle.setAttribute('aria-pressed', enabled ? 'true' : 'false'); syncRuleDesc(autoCallToggle);
            autoCallToggle.classList.toggle('bg-primary', enabled);
            autoCallToggle.classList.toggle('bg-slate-300', !enabled);
            autoCallToggle.classList.toggle('dark:bg-slate-600', !enabled);
            autoCallKnob.classList.toggle('translate-x-6', enabled);
            autoCallKnob.classList.toggle('translate-x-1', !enabled);
        };
        autoCallToggle.addEventListener('click', () => {
            const next = autoCallToggle.dataset.enabled !== '1';
            paintAutoCall(next);
            autoCallToggle.disabled = true; window.tsaSettingsSaveState('saving');
            fetch(autoCallToggle.dataset.action, {
                method: 'POST',
                headers: {
                    'X-Requested-With': 'XMLHttpRequest',
                    'Accept': 'application/json',
                    'Content-Type': 'application/x-www-form-urlencoded',
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || '',
                },
                body: `enabled=${next ? '1' : '0'}`,
            })
                .then((res) => (res.ok ? res.json() : Promise.reject(res)))
                .then((data) => window.tsaSettingsSaveState('saved'))
                .catch(() => { paintAutoCall(!next); window.tsaSettingsSaveState('error'); window.showToast?.('Could not save — try again.', 'error'); })
                .finally(() => { autoCallToggle.disabled = false; });
        });
    }

    // Wrap Up length (2026-11-17) — saves by itself (2026-11-25, no Save
    // button): a preset click saves at once; the custom number/unit saves
    // shortly after the admin stops typing.
    const wrapUpForm = document.getElementById('wrapUpForm');
    if (wrapUpForm) {
        const valueInput = document.getElementById('wrapUpValue');
        const unitSelect = document.getElementById('wrapUpUnit');
        const desc = document.getElementById('wrapUpDesc');
        const presets = wrapUpForm.querySelectorAll('[data-wrap-preset]');
        const label = (sec) => sec % 60 === 0
            ? `${sec / 60} minute${sec === 60 ? '' : 's'}`
            : `${sec} second${sec === 1 ? '' : 's'}`;
        const paint = (sec) => {
            presets.forEach((btn) => {
                const on = Number(btn.dataset.wrapPreset) === sec;
                btn.setAttribute('aria-checked', on ? 'true' : 'false');
                btn.classList.toggle('border-primary', on);
                btn.classList.toggle('bg-primary/10', on);
                btn.classList.toggle('text-primary-dark', on);
                btn.classList.toggle('dark:text-yellow-400', on);
                btn.classList.toggle('border-slate-300', !on);
                btn.classList.toggle('dark:border-slate-600', !on);
                btn.classList.toggle('text-slate-600', !on);
                btn.classList.toggle('dark:text-slate-300', !on);
            });
            if (desc) desc.textContent = `${label(sec)} after each call, then Ready to Call.`;
        };
        let saved = Number(wrapUpForm.dataset.seconds);
        paint(saved);

        let seq = 0;
        const save = (value, unit) => {
            const sec = Number(value) * (unit === 'minutes' ? 60 : 1);
            if (!Number.isFinite(sec) || sec <= 0 || sec === saved) return;
            const mine = ++seq;
            paint(sec);
            window.tsaSettingsSaveState('saving');
            fetch(wrapUpForm.dataset.action, {
                method: 'POST',
                headers: {
                    'X-Requested-With': 'XMLHttpRequest',
                    'Accept': 'application/json',
                    'Content-Type': 'application/x-www-form-urlencoded',
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || '',
                },
                body: new URLSearchParams({ value: String(value), unit }).toString(),
            })
                .then(async (res) => {
                    const data = await res.json().catch(() => ({}));
                    if (!res.ok) throw new Error(data.message || 'Could not save — try again.');
                    saved = Number(data.seconds ?? sec);
                    window.tsaSettingsSaveState('saved');
                })
                .catch((err) => {
                    window.tsaSettingsSaveState('error');
                    window.showToast?.(err.message || 'Could not save — try again.', 'error');
                    if (mine === seq) paint(saved);
                });
        };

        presets.forEach((btn) => btn.addEventListener('click', () => {
            const sec = Number(btn.dataset.wrapPreset);
            valueInput.value = sec / 60;
            unitSelect.value = 'minutes';
            save(sec / 60, 'minutes');
        }));
        let typingTimer = null;
        const saveCustom = () => {
            clearTimeout(typingTimer);
            typingTimer = setTimeout(() => {
                if (valueInput.value === '' || !valueInput.checkValidity()) return;
                save(parseInt(valueInput.value, 10), unitSelect.value);
            }, 700);
        };
        valueInput.addEventListener('input', saveCustom);
        unitSelect.addEventListener('change', saveCustom);
    }

    // Global auto-text switch — same optimistic-paint/rollback shape as
    // the midnight auto-logout toggle above.
    const autoSmsToggle = document.getElementById('autoSmsToggle');
    const autoSmsKnob   = document.getElementById('autoSmsKnob');
    if (autoSmsToggle && autoSmsKnob) {
        function paintAutoSms(enabled) {
            autoSmsToggle.dataset.enabled = enabled ? '1' : '0';
            autoSmsToggle.setAttribute('aria-pressed', enabled ? 'true' : 'false'); syncRuleDesc(autoSmsToggle);
            autoSmsToggle.classList.toggle('bg-primary', enabled);
            autoSmsToggle.classList.toggle('bg-slate-300', !enabled);
            autoSmsToggle.classList.toggle('dark:bg-slate-600', !enabled);
            autoSmsKnob.classList.toggle('translate-x-6', enabled);
            autoSmsKnob.classList.toggle('translate-x-1', !enabled);
        }

        autoSmsToggle.addEventListener('click', () => {
            const next = autoSmsToggle.dataset.enabled !== '1';
            paintAutoSms(next);
            autoSmsToggle.disabled = true; window.tsaSettingsSaveState('saving');

            fetch(autoSmsToggle.dataset.action, {
                method: 'POST',
                headers: {
                    'X-Requested-With': 'XMLHttpRequest',
                    'Accept': 'application/json',
                    'Content-Type': 'application/x-www-form-urlencoded',
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || '',
                },
                body: `enabled=${next ? '1' : '0'}`,
            })
                .then((res) => (res.ok ? res.json() : Promise.reject(res)))
                .then((data) => window.tsaSettingsSaveState('saved'))
                .catch(() => {
                    paintAutoSms(!next); window.tsaSettingsSaveState('error');
                    window.showToast?.('Could not save — try again.', 'error');
                })
                .finally(() => { autoSmsToggle.disabled = false; });
        });
    }

    // Auto-text message per product: { } insert menu, blue placeholder
    // highlight (mirror div under a transparent textarea), autosave on blur
    // and right after an insert. Only {keys} the server knows are blue.
    const smsKnownKeys = @json(array_merge(array_keys(\App\Support\LeadAutoText::PLACEHOLDERS), ['name', 'fullname']));
    const smsEscape = (t) => t.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
    function smsRenderMirror(ta) {
        const mirror = ta.parentElement.querySelector('[data-sms-mirror]');
        if (!mirror) return;
        const html = smsEscape(ta.value).replace(/\{([a-z_]+)\}/g, (m, key) => smsKnownKeys.includes(key)
            ? `<span class="rounded bg-blue-100 text-blue-700 dark:bg-blue-500/20 dark:text-blue-300">${m}</span>`
            : m);
        // Trailing space keeps the mirror as tall as a textarea ending in a newline.
        mirror.innerHTML = html + ' ';
    }
    function smsSaveTemplate(ta) {
        if (ta.value === ta.dataset.saved) return;
        const value = ta.value;
        const status = ta.closest('[data-sms-editor]')?.querySelector('[data-sms-template-status]');
        if (status) status.textContent = 'Saving…';
        fetch(ta.dataset.smsTemplateAction, {
            method: 'POST',
            headers: {
                'X-Requested-With': 'XMLHttpRequest',
                'Accept': 'application/json',
                'Content-Type': 'application/x-www-form-urlencoded',
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || '',
            },
            body: new URLSearchParams({ sms_template: value }),
        })
            .then((res) => (res.ok ? res.json() : Promise.reject(res)))
            .then(() => {
                ta.dataset.saved = value;
                if (status) status.textContent = '✓ Saved';
                setTimeout(() => { if (status && status.textContent === '✓ Saved') status.textContent = ''; }, 1800);
            })
            .catch(() => {
                if (status) status.textContent = 'Not saved';
                window.showToast?.('Could not save the message — try again.', 'error');
            });
    }
    document.querySelectorAll('[data-sms-template-action]').forEach((ta) => {
        ta.dataset.saved = ta.value;
        smsRenderMirror(ta);
        ta.addEventListener('input', () => smsRenderMirror(ta));
        ta.addEventListener('change', () => smsSaveTemplate(ta));
    });

    const smsMenu = document.getElementById('smsInsertMenu');
    let smsMenuTarget = null;
    function smsCloseMenu() {
        smsMenu?.classList.add('hidden');
        smsMenuTarget = null;
    }
    document.querySelectorAll('[data-sms-insert-btn]').forEach((btn) => {
        btn.addEventListener('click', (e) => {
            e.stopPropagation();
            const ta = btn.closest('[data-sms-editor]')?.querySelector('textarea');
            if (!smsMenu || !ta) return;
            if (smsMenuTarget === ta) { smsCloseMenu(); return; }
            smsMenuTarget = ta;
            const r = btn.getBoundingClientRect();
            smsMenu.classList.remove('hidden');
            const left = Math.min(r.right - smsMenu.offsetWidth, window.innerWidth - smsMenu.offsetWidth - 8);
            const below = r.bottom + 6;
            const top = below + smsMenu.offsetHeight > window.innerHeight ? r.top - smsMenu.offsetHeight - 6 : below;
            smsMenu.style.left = `${Math.max(8, left)}px`;
            smsMenu.style.top = `${Math.max(8, top)}px`;
        });
    });
    smsMenu?.querySelectorAll('[data-sms-insert]').forEach((item) => {
        item.addEventListener('click', () => {
            const ta = smsMenuTarget;
            if (!ta) return;
            const token = item.dataset.smsInsert;
            const start = ta.selectionStart ?? ta.value.length;
            const end = ta.selectionEnd ?? ta.value.length;
            ta.value = ta.value.slice(0, start) + token + ta.value.slice(end);
            ta.focus();
            ta.setSelectionRange(start + token.length, start + token.length);
            smsRenderMirror(ta);
            smsCloseMenu();
            smsSaveTemplate(ta);
        });
    });
    document.addEventListener('click', (e) => { if (smsMenu && !smsMenu.contains(e.target)) smsCloseMenu(); });
    document.addEventListener('keydown', (e) => { if (e.key === 'Escape') smsCloseMenu(); });
    window.addEventListener('scroll', smsCloseMenu, true);
})();

// Expand/collapse a TSA's detail row — delegated so it survives the filter's
// innerHTML swap without needing to re-bind per row. One row open at a time
// isn't enforced (an admin may want to compare two TSAs' setups side by
// side), unlike the single-popover status panel above.
//
// The status trigger/dropdown (calls/partials/tsa-status-panel) lives inside
// the same <tr> so its own click can reach this listener too — bailing out
// here (instead of an onclick="event.stopPropagation()" on its <td>, tried
// first and reverted) is what's needed: stopPropagation there also silently
// blocked calls.js's own document-level handler for the "Select status"
// options and the click-outside-closes-it logic, since both listen on
// document too and stopPropagation cuts the event off before it gets that
// far — the dropdown opened (a direct onclick on the trigger) but picking an
// option did nothing.
document.addEventListener('click', (e) => {
    if (e.target.closest('[data-status-panel-wrap]')) return;
    // Unpair "x" button and the drag handle both live inside the row that
    // ALSO carries data-tsa-row-toggle — without this bail, clicking either
    // would also toggle the detail panel open/closed underneath whatever
    // else it just did.
    if (e.target.closest('[data-tsa-unpair]')) return;
    if (e.target.closest('.tsa-drag-handle')) return;

    const trigger = e.target.closest('[data-tsa-row-toggle]');
    if (!trigger) return;

    const id      = trigger.dataset.tsaRowToggle;
    const grid    = document.querySelector(`[data-tsa-detail-grid="${id}"]`);
    const chevron = document.getElementById(`tsaChevron-${id}`);
    if (!grid) return;

    // grid-template-rows 0fr <-> 1fr, not a plain height transition — see
    // the row's own doc comment in _table.blade.php for why (content height
    // varies per TSA/per open <details>, which max-height can't handle
    // without either clipping it or guessing an oversized fixed value).
    const isOpen = grid.style.gridTemplateRows === '1fr';
    grid.style.gridTemplateRows = isOpen ? '0fr' : '1fr';
    if (chevron) chevron.classList.toggle('rotate-180', !isOpen);
});

// Shared confirm-modal helper (explicit follow-up request, 2026-09-11:
// "create a modal for this" — replaces the native browser confirm() the
// drag-to-pair/unpair actions below used at first). Returns a Promise
// resolving true/false, so call sites read exactly like the confirm()
// they replaced: `if (!(await confirmModal(...))) return;`.
function confirmModal(modalId, bodyId, okId, cancelId, message) {
    const modal  = document.getElementById(modalId);
    const body   = document.getElementById(bodyId);
    const okBtn  = document.getElementById(okId);
    const cancelBtn = document.getElementById(cancelId);
    if (!modal || !body || !okBtn || !cancelBtn) return Promise.resolve(false);

    body.textContent = message;
    modal.classList.remove('hidden');
    modal.classList.add('flex');

    return new Promise((resolve) => {
        function cleanup(result) {
            modal.classList.add('hidden');
            modal.classList.remove('flex');
            okBtn.removeEventListener('click', onOk);
            cancelBtn.removeEventListener('click', onCancel);
            modal.removeEventListener('click', onBackdrop);
            resolve(result);
        }
        function onOk() { cleanup(true); }
        function onCancel() { cleanup(false); }
        function onBackdrop(e) { if (e.target === modal) cleanup(false); }

        okBtn.addEventListener('click', onOk);
        cancelBtn.addEventListener('click', onCancel);
        modal.addEventListener('click', onBackdrop);
    });
}

// Cross-team pair picker (explicit follow-up, 2026-09-11: "the tsa is
// will be like not move to the other team it will only like pair to the
// other team") — opened by dropping a row on a different team's filter
// pill (see the drag handler below). Fetches that team's unpaired TSAs
// (TsaManagementController::pairCandidates()) and shows them in
// #crossTeamPairPicker, positioned under the dropped-on pill. Picking one
// calls the same POST /pair endpoint the same-team drag-onto-row flow
// already uses — this is purely a different way of choosing the partner,
// not a different pairing mechanism.
function openCrossTeamPairPicker(pillEl, draggedTsaId, draggedTsaName, targetTeam, targetTeamLabel) {
    const picker    = document.getElementById('crossTeamPairPicker');
    const title     = document.getElementById('crossTeamPairPickerTitle');
    const list      = document.getElementById('crossTeamPairPickerList');
    if (!picker || !title || !list) return;

    const rect = pillEl.getBoundingClientRect();
    picker.style.top  = `${rect.bottom + 6}px`;
    picker.style.left = `${Math.max(8, Math.min(rect.left, window.innerWidth - 264))}px`;

    title.textContent = `Pair ${draggedTsaName} with…`;
    list.innerHTML = '<p class="text-xs font-mono text-slate-400 text-center py-4">Loading…</p>';
    picker.classList.remove('hidden');
    picker.classList.add('flex', 'flex-col');

    function closePicker() {
        picker.classList.add('hidden');
        picker.classList.remove('flex', 'flex-col');
        document.removeEventListener('click', onOutsideClick, true);
    }
    function onOutsideClick(e) {
        if (!picker.contains(e.target)) closePicker();
    }
    // Capture phase + next tick: the drop event that opened this picker
    // would otherwise immediately bubble into this same listener and
    // close it before the user ever sees it.
    setTimeout(() => document.addEventListener('click', onOutsideClick, true), 0);

    fetch(`/calls/tsa-management/${draggedTsaId}/pair-candidates?team=${encodeURIComponent(targetTeam)}`, {
        headers: { Accept: 'application/json' },
    })
        .then(res => res.json())
        .then((data) => {
            if (!data.success) {
                list.innerHTML = `<p class="text-xs font-mono text-red-500 text-center py-4">${data.message || data.error || 'Could not load candidates.'}</p>`;
                return;
            }
            if (!data.candidates.length) {
                list.innerHTML = `<p class="text-xs font-mono text-slate-400 text-center py-4">No available TSAs on ${targetTeamLabel} — everyone there is already paired.</p>`;
                return;
            }
            list.innerHTML = data.candidates.map(c => `
                <button type="button" data-candidate-id="${c.id}" data-candidate-name="${c.display_name.replace(/"/g, '&quot;')}"
                        class="cross-team-pair-candidate w-full text-left px-3 py-2 text-sm text-slate-700 dark:text-slate-200 hover:bg-yellow-50 dark:hover:bg-yellow-950/40 hover:text-primary-dark cursor-pointer">
                    ${c.display_name}
                </button>
            `).join('');

            list.querySelectorAll('.cross-team-pair-candidate').forEach((btn) => {
                btn.addEventListener('click', async () => {
                    const partnerId   = btn.dataset.candidateId;
                    const partnerName = btn.dataset.candidateName;
                    closePicker();

                    const confirmed = await confirmModal(
                        'pairConfirmModal', 'pairConfirmBody', 'pairConfirmOk', 'pairConfirmCancel',
                        `Pair ${draggedTsaName} with ${partnerName}? ${draggedTsaName} will share ${partnerName}'s phone/token — ${draggedTsaName}'s own token will be cleared. You can unpair anytime.`
                    );
                    if (!confirmed) return;

                    fetch(`/calls/tsa-management/${partnerId}/pair`, {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json',
                            Accept: 'application/json',
                            'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || '',
                        },
                        body: JSON.stringify({ partner_id: draggedTsaId }),
                    })
                        .then(res => res.json().then(data => ({ ok: res.ok, data })))
                        .then(({ ok, data }) => {
                            if (!ok || !data.success) {
                                window.showToast?.(data.message || data.error || 'Could not pair these TSAs.', 'error');
                                return;
                            }
                            window.showToast?.(data.message || 'Paired.', 'success');
                            window.location.reload();
                        })
                        .catch(() => window.showToast?.('Something went wrong pairing these TSAs.', 'error'));
                });
            });
        })
        .catch(() => {
            list.innerHTML = '<p class="text-xs font-mono text-red-500 text-center py-4">Something went wrong loading candidates.</p>';
        });
}

// Drag-to-pair (explicit request, 2026-09-11: "2 tsa, one cellphone...
// no shift schedules, whoever is online and clicks dial") — dragging TSA
// row A onto TSA row B pairs them so they share B's phone/token, with
// live status (not a fixed schedule) deciding which of the two an
// incoming call event actually belongs to (see
// TsaShift::resolveActiveOfPair(), CallEventController::store()).
//
// Native HTML5 drag events, not a library — this table has exactly one
// drag interaction (row onto row), not a general sortable/reorderable
// list, so pulling in Sortable.js for this alone would be more dependency
// than the feature needs. Delegated on the table body (not per-row) for
// the same filter-swap-survives-without-rebinding reason the click
// handler above already uses.
(function () {
    const container = document.getElementById('tsaMgmtTableContainer');
    const filterBar  = document.getElementById('tsaMgmtFilter');
    if (!container) return;

    let draggedId   = null;
    let draggedTeam = null;

    container.addEventListener('dragstart', (e) => {
        const row = e.target.closest('.tsa-row');
        if (!row) return;
        draggedId   = row.dataset.tsaRow;
        draggedTeam = row.dataset.tsaTeam;
        e.dataTransfer.effectAllowed = 'move';
        // Firefox requires setData to be called for a drag to actually
        // start at all — the id itself isn't read back from this on drop
        // (draggedId above is what's used), this is purely to satisfy that.
        e.dataTransfer.setData('text/plain', draggedId);
        row.classList.add('opacity-50');
    });

    container.addEventListener('dragend', (e) => {
        const row = e.target.closest('.tsa-row');
        if (row) row.classList.remove('opacity-50');
        container.querySelectorAll('.tsa-row-drop-target').forEach(el => el.classList.remove('tsa-row-drop-target', 'bg-yellow-50', 'dark:bg-yellow-950/30'));
        filterBar?.querySelectorAll('.tsaMgmt-pill').forEach(p => p.classList.remove('ring-2', 'ring-primary'));
        draggedId   = null;
        draggedTeam = null;
    });

    // Cross-team pairing (explicit request, 2026-09-11: "the tsa is will
    // be like not move to the other team it will only like pair to the
    // other team") — dropping a row on a DIFFERENT team's filter pill
    // opens crossTeamPairPicker (see its own doc comment above) instead of
    // moving anyone. The CURRENTLY selected team's own pill is not a drop
    // target — same-team pairing already works by dragging onto a row
    // directly in the table below.
    if (filterBar) {
        filterBar.addEventListener('dragover', (e) => {
            const pill = e.target.closest('.tsaMgmt-pill');
            if (!pill || !draggedId || !pill.dataset.team || pill.dataset.team === draggedTeam) return;
            e.preventDefault();
            e.dataTransfer.dropEffect = 'move';
        });

        filterBar.addEventListener('dragenter', (e) => {
            const pill = e.target.closest('.tsaMgmt-pill');
            if (!pill || !draggedId || !pill.dataset.team || pill.dataset.team === draggedTeam) return;
            pill.classList.add('ring-2', 'ring-primary');
        });

        filterBar.addEventListener('dragleave', (e) => {
            const pill = e.target.closest('.tsaMgmt-pill');
            if (pill && !pill.contains(e.relatedTarget)) {
                pill.classList.remove('ring-2', 'ring-primary');
            }
        });

        filterBar.addEventListener('drop', (e) => {
            const pill = e.target.closest('.tsaMgmt-pill');
            if (!pill || !draggedId || !pill.dataset.team || pill.dataset.team === draggedTeam) return;
            e.preventDefault();
            pill.classList.remove('ring-2', 'ring-primary');

            const tsaId      = draggedId;
            const draggedRow = container.querySelector(`.tsa-row[data-tsa-row="${tsaId}"]`);
            if (draggedRow && draggedRow.dataset.tsaPaired === '1') {
                window.showToast?.('This TSA is already paired — unpair first.', 'error');
                return;
            }

            openCrossTeamPairPicker(pill, tsaId, draggedRow ? draggedRow.dataset.tsaName : 'This TSA', pill.dataset.team, pill.textContent.trim());
        });
    }

    container.addEventListener('dragover', (e) => {
        const row = e.target.closest('.tsa-row');
        if (!row || !draggedId || row.dataset.tsaRow === draggedId) return;
        e.preventDefault(); // required for drop to fire at all
        e.dataTransfer.dropEffect = 'move';
    });

    container.addEventListener('dragenter', (e) => {
        const row = e.target.closest('.tsa-row');
        if (!row || !draggedId || row.dataset.tsaRow === draggedId) return;
        row.classList.add('tsa-row-drop-target', 'bg-yellow-50', 'dark:bg-yellow-950/30');
    });

    container.addEventListener('dragleave', (e) => {
        const row = e.target.closest('.tsa-row');
        if (row && !row.contains(e.relatedTarget)) {
            row.classList.remove('tsa-row-drop-target', 'bg-yellow-50', 'dark:bg-yellow-950/30');
        }
    });

    container.addEventListener('drop', async (e) => {
        const targetRow = e.target.closest('.tsa-row');
        if (!targetRow || !draggedId || targetRow.dataset.tsaRow === draggedId) return;
        e.preventDefault();

        // Captured into a local BEFORE the confirm-modal await below —
        // dragend fires synchronously right after drop regardless of any
        // async work still running inside this handler, which resets the
        // shared draggedId to null while the modal is still open. Reading
        // draggedId itself (not this local) after the await was exactly
        // that bug: the fetch below sent partner_id: null, which the
        // backend correctly rejected as "The partner id field is
        // required."
        const partnerId = draggedId;
        const targetId = targetRow.dataset.tsaRow;
        const draggedRow = container.querySelector(`.tsa-row[data-tsa-row="${partnerId}"]`);

        if (targetRow.dataset.tsaPaired === '1' || (draggedRow && draggedRow.dataset.tsaPaired === '1')) {
            window.showToast?.('One of these is already paired — unpair first.', 'error');
            return;
        }

        const targetName   = targetRow.dataset.tsaName;
        const draggedName  = draggedRow ? draggedRow.dataset.tsaName : 'This TSA';
        const confirmed = await confirmModal(
            'pairConfirmModal', 'pairConfirmBody', 'pairConfirmOk', 'pairConfirmCancel',
            `Pair ${draggedName} with ${targetName}? ${draggedName} will share ${targetName}'s phone/token — ${draggedName}'s own token will be cleared. You can unpair anytime.`
        );
        if (!confirmed) return;

        fetch(`/calls/tsa-management/${targetId}/pair`, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                Accept: 'application/json',
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || '',
            },
            body: JSON.stringify({ partner_id: partnerId }),
        })
            .then(res => res.json().then(data => ({ ok: res.ok, data })))
            .then(({ ok, data }) => {
                if (!ok || !data.success) {
                    window.showToast?.(data.message || data.error || 'Could not pair these TSAs.', 'error');
                    return;
                }
                window.showToast?.(data.message || 'Paired.', 'success');
                window.location.reload();
            })
            .catch(() => window.showToast?.('Something went wrong pairing these TSAs.', 'error'));
    });
})();

// Unpair — single click, no schedule/token juggling for the admin to
// think about (TsaShift::unpair() issues the now-solo partner a fresh
// token automatically).
document.addEventListener('click', async (e) => {
    const btn = e.target.closest('[data-tsa-unpair]');
    if (!btn) return;

    const id   = btn.dataset.tsaUnpair;
    const name = btn.dataset.tsaUnpairName;
    const confirmed = await confirmModal(
        'unpairConfirmModal', 'unpairConfirmBody', 'unpairConfirmOk', 'unpairConfirmCancel',
        `Unpair ${name}? Both TSAs will get their own token back.`
    );
    if (!confirmed) return;

    fetch(`/calls/tsa-management/${id}/unpair`, {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            Accept: 'application/json',
            'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || '',
        },
    })
        .then(res => res.json().then(data => ({ ok: res.ok, data })))
        .then(({ ok, data }) => {
            if (!ok || !data.success) {
                window.showToast?.(data.message || data.error || 'Could not unpair.', 'error');
                return;
            }
            window.showToast?.(data.message || 'Unpaired.', 'success');
            window.location.reload();
        })
        .catch(() => window.showToast?.('Something went wrong unpairing.', 'error'));
});
</script>

@endsection
