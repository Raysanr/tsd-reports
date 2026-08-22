{{--
    Shared Leads modals — ported from call-tracker's layouts/app.blade.php
    (merged into one app 2026-08-12), restyled to tsd-reports' own
    dark-mode convention. call-tracker rendered these once in its own
    layout so every page had them; tsd-reports' shared layout isn't touched
    in this phase, so instead this partial is included directly by every
    calls/leads/*.blade.php view that needs click-to-call / outcome-tagging
    / upsell / conversation — the JS in resources/js/calls.js (openXModal()
    etc.) is the same regardless of which page included this markup.
--}}

{{-- Conversation modal — real Pancake messages rendered here (fetched
     server-side via /calls/leads/{lead}/conversation), NOT an iframe:
     Pancake's own CSP (frame-ancestors) blocks embedding its page on any
     other domain, so this renders the message data ourselves instead.
     Opened via openConversationModal(leadId) (see calls.js). --}}
<div id="conversationModal" class="hidden fixed inset-0 z-50 items-center justify-center bg-black/50 p-6 opacity-0 transition-opacity duration-200">
    <div class="bg-white dark:bg-slate-900 rounded-2xl shadow-2xl w-full max-w-lg h-[80vh] flex flex-col overflow-hidden opacity-0 scale-95 transition-all duration-200">
        <div class="flex items-center justify-between px-5 py-3 border-b border-slate-200 dark:border-slate-700 shrink-0">
            <h3 class="text-sm font-bold text-slate-700 dark:text-slate-200 font-mono flex items-center gap-2">
                <svg class="w-4 h-4 text-blue-500" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M8 12h.01M12 12h.01M16 12h.01M21 12c0 4.418-4.03 8-9 8a9.863 9.863 0 01-4.255-.949L3 20l1.395-3.72C3.512 15.042 3 13.574 3 12c0-4.418 4.03-8 9-8s9 3.582 9 8z"/>
                </svg>
                Conversation
            </h3>
            <button type="button" onclick="closeConversationModal()" aria-label="Close"
                    class="p-1.5 rounded-lg text-slate-400 hover:text-slate-700 dark:hover:text-slate-200 hover:bg-slate-100 dark:hover:bg-slate-800 cursor-pointer">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/>
                </svg>
            </button>
        </div>
        <div id="conversationModalBody" class="flex-1 overflow-y-auto p-4 space-y-3 bg-slate-50 dark:bg-slate-950 font-mono text-sm">
            {{-- Populated by openConversationModal() in calls.js --}}
        </div>
    </div>
</div>

{{-- Shared outcome-tag picker modal — one instance reused by every row's
     "+ Add tag" button (leads/_table.blade.php + leads/show.blade.php),
     same "search the real Pancake tag catalog" idea as Call Rotation's Add
     TSA POS search, but a multi-select checklist (not single-pick) so a TSA
     can tag a lead with several real POS tags at once — matches Pancake's
     own "Add tag" popup. Opened via openOutcomeTagModal(picker) (see
     calls.js), which remembers which row's .disposition-picker it's editing. --}}
<div id="outcomeTagModal" class="hidden fixed inset-0 z-50 items-center justify-center bg-black/50 p-6 opacity-0 transition-opacity duration-200">
    <div class="bg-white dark:bg-slate-900 rounded-2xl shadow-2xl w-full max-w-sm h-[70vh] flex flex-col overflow-hidden opacity-0 scale-95 transition-all duration-200">
        <div class="flex items-center justify-between px-5 py-3 border-b border-slate-200 dark:border-slate-700 shrink-0">
            <h3 class="text-sm font-bold text-slate-700 dark:text-slate-200 font-mono">Add outcome tags</h3>
            <button type="button" onclick="closeOutcomeTagModal()" aria-label="Close"
                    class="p-1.5 rounded-lg text-slate-400 hover:text-slate-700 dark:hover:text-slate-200 hover:bg-slate-100 dark:hover:bg-slate-800 cursor-pointer">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/>
                </svg>
            </button>
        </div>
        <div class="px-4 py-3 border-b border-slate-100 dark:border-slate-700 shrink-0">
            <input type="text" id="outcomeTagModalSearch" placeholder="Search tags…" autocomplete="off"
                   class="w-full text-sm font-mono border border-slate-300 dark:border-slate-600 rounded-lg px-3 py-2 bg-white dark:bg-slate-800 text-slate-800 dark:text-slate-100 focus:outline-none focus:ring-2 focus:ring-yellow-500">
        </div>
        <div id="outcomeTagModalChips" class="px-4 py-2 flex flex-wrap gap-1 empty:hidden border-b border-slate-100 dark:border-slate-700 shrink-0"></div>
        <div id="outcomeTagModalResults" class="flex-1 overflow-y-auto">
            {{-- Populated by openOutcomeTagModal()/searchOutcomeTagModal() in calls.js --}}
        </div>
        <div class="px-4 py-3 border-t border-slate-100 dark:border-slate-700 shrink-0 flex justify-end">
            <button type="button" onclick="closeOutcomeTagModal()"
                    class="bg-primary hover:bg-primary-dark text-white text-xs font-semibold font-mono px-4 py-2 rounded-lg cursor-pointer">
                Done
            </button>
        </div>
    </div>
</div>

{{-- Add Upsell modal — "+ Add Upsell" button (leads/_table.blade.php +
     leads/show.blade.php). Same "search a real Pancake catalog as you type"
     shape as the outcome tag modal above, but single-pick (each add is its
     own immediate write to a real order, not a batched local save) and
     against real sellable products+prices (LeadController::searchProducts,
     PancakeProductApi) instead of tags. Picking a result reveals a quantity
     field + Add button rather than adding instantly. Opened via
     openUpsellModal(leadId) (see calls.js). --}}
<div id="upsellModal" class="hidden fixed inset-0 z-50 items-center justify-center bg-black/50 p-6 opacity-0 transition-opacity duration-200">
    <div class="bg-white dark:bg-slate-900 rounded-2xl shadow-2xl w-full max-w-sm h-[70vh] flex flex-col overflow-hidden opacity-0 scale-95 transition-all duration-200">
        <div class="flex items-center justify-between px-5 py-3 border-b border-slate-200 dark:border-slate-700 shrink-0">
            <h3 class="text-sm font-bold text-slate-700 dark:text-slate-200 font-mono">Add upsell product</h3>
            <button type="button" onclick="closeUpsellModal()" aria-label="Close"
                    class="p-1.5 rounded-lg text-slate-400 hover:text-slate-700 dark:hover:text-slate-200 hover:bg-slate-100 dark:hover:bg-slate-800 cursor-pointer">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/>
                </svg>
            </button>
        </div>
        <div class="px-4 py-3 border-b border-slate-100 dark:border-slate-700 shrink-0">
            <input type="text" id="upsellModalSearch" placeholder="Search products…" autocomplete="off"
                   class="w-full text-sm font-mono border border-slate-300 dark:border-slate-600 rounded-lg px-3 py-2 bg-white dark:bg-slate-800 text-slate-800 dark:text-slate-100 focus:outline-none focus:ring-2 focus:ring-yellow-500">
        </div>
        <div id="upsellModalResults" class="flex-1 overflow-y-auto">
            {{-- Populated by openUpsellModal()/searchUpsellModal() in calls.js --}}
        </div>
        {{-- Hidden until a result is picked — see selectUpsellProduct() in calls.js. --}}
        <div id="upsellModalConfirm" class="hidden px-4 py-3 border-t border-slate-100 dark:border-slate-700 shrink-0">
            <p id="upsellModalConfirmName" class="text-sm font-semibold text-slate-700 dark:text-slate-200 font-mono truncate mb-2"></p>
            <div class="flex items-center gap-2">
                <label class="text-xs font-mono text-slate-400 shrink-0">Qty</label>
                <input type="number" id="upsellModalQuantity" value="1" min="1" max="99"
                       class="w-16 text-sm font-mono border border-slate-300 dark:border-slate-600 rounded-lg px-2 py-1.5 bg-white dark:bg-slate-800 text-slate-800 dark:text-slate-100 focus:outline-none focus:ring-2 focus:ring-yellow-500">
                <button type="button" onclick="submitUpsell()" id="upsellModalAddBtn"
                        class="flex-1 bg-primary hover:bg-primary-dark text-white text-xs font-semibold font-mono px-3 py-2 rounded-lg cursor-pointer">
                    Add to order
                </button>
            </div>
            <p id="upsellModalError" class="hidden text-[11px] text-red-500 font-mono mt-2"></p>
        </div>
    </div>
</div>

{{-- Order status panel (explicit request, 2026-08-22) — one shared floating
     panel reused by every row's Status pill in leads/_table.blade.php, same
     "one shared component, positioned to whichever trigger was clicked"
     pattern as calls/partials/tsa-status-panel.blade.php and the Leads tab's
     own TSA filter dropdown. Icon + label rows, same shape as
     tsa-status-panel's own options, mirroring Pancake POS's own Status
     dropdown (screenshot, 2026-08-22) — real order statuses only
     (\App\Models\Order::STATUS_ASSIGNABLE), not the two non-status rows
     that screenshot also showed ("Prioritize order" / "Create duplicate" —
     see that constant's own doc comment for why). Opened via
     openOrderStatusPill(event, leadId, currentCode) in calls.js, which also
     positions it under the clicked pill via getBoundingClientRect() — same
     technique as toggleStatusPanel()/the TSA filter dropdown, not a
     centered modal, so it's clear which row is being changed. --}}
@php
    $orderStatusIcons = [
        11 => '<svg class="w-4 h-4 text-amber-500" fill="none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M12 6v6l4 2"/><circle cx="12" cy="12" r="8.25"/></svg>',
        12 => '<svg class="w-4 h-4 text-sky-500" fill="none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M6.72 13.829c-.24.03-.48.062-.72.096m.72-.096a42.415 42.415 0 0110.56 0m-10.56 0L6.34 18m10.94-4.171c.24.03.48.062.72.096m-.72-.096L17.66 18m0 0l.229 2.523a1.125 1.125 0 01-1.12 1.227H7.231c-.662 0-1.18-.568-1.12-1.227L6.34 18m11.32 0H6.34m10.94-11.171V4.125A1.125 1.125 0 0016.155 3H7.845A1.125 1.125 0 006.72 4.125v2.704M6.72 13.829V6.829m10.56 7V6.829"/></svg>',
        13 => '<svg class="w-4 h-4 text-sky-500" fill="none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M6.72 13.829c-.24.03-.48.062-.72.096m.72-.096a42.415 42.415 0 0110.56 0m-10.56 0L6.34 18m10.94-4.171c.24.03.48.062.72.096m-.72-.096L17.66 18m0 0l.229 2.523a1.125 1.125 0 01-1.12 1.227H7.231c-.662 0-1.18-.568-1.12-1.227L6.34 18m11.32 0H6.34m10.94-11.171V4.125A1.125 1.125 0 0016.155 3H7.845A1.125 1.125 0 006.72 4.125v2.704M6.72 13.829V6.829m10.56 7V6.829"/></svg>',
        20 => '<svg class="w-4 h-4 text-indigo-500" fill="none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M19.5 14.25v-2.625a3.375 3.375 0 00-3.375-3.375h-1.5A1.125 1.125 0 0113.5 7.125v-1.5a3.375 3.375 0 00-3.375-3.375H8.25m0 12.75h7.5m-7.5 3H12M10.5 2.25H5.625c-.621 0-1.125.504-1.125 1.125v17.25c0 .621.504 1.125 1.125 1.125h12.75c.621 0 1.125-.504 1.125-1.125V11.25a9 9 0 00-9-9z"/></svg>',
        1  => '<svg class="w-4 h-4 text-blue-500" fill="none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75L11.25 15 15 9.75M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>',
        8  => '<svg class="w-4 h-4 text-purple-500" fill="none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M20.25 7.5l-.625 10.632a2.25 2.25 0 01-2.247 2.118H6.622a2.25 2.25 0 01-2.247-2.118L3.75 7.5M10 11.25h4M3.375 7.5h17.25c.621 0 1.125-.504 1.125-1.125v-1.5c0-.621-.504-1.125-1.125-1.125H3.375c-.621 0-1.125.504-1.125 1.125v1.5c0 .621.504 1.125 1.125 1.125z"/></svg>',
        9  => '<svg class="w-4 h-4 text-violet-500" fill="none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75L11.25 15 15 9.75M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>',
        2  => '<svg class="w-4 h-4 text-teal-500" fill="none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M8.25 18.75a1.5 1.5 0 01-3 0m3 0a1.5 1.5 0 00-3 0m3 0h6m-9 0H3.375a1.125 1.125 0 01-1.125-1.125V14.25m17.25 4.5a1.5 1.5 0 01-3 0m3 0a1.5 1.5 0 00-3 0m3 0h1.125c.621 0 1.129-.504 1.09-1.124a17.902 17.902 0 00-3.213-9.193 2.056 2.056 0 00-1.58-.86H14.25M16.5 18.75h-2.25m0-11.25h-8.85c-1.033 0-1.9.766-2.055 1.788l-.63 4.16A2.25 2.25 0 004.44 15.75H14.25m0-11.25v11.25"/></svg>',
        6  => '<svg class="w-4 h-4 text-red-500" fill="none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M9.75 9.75l4.5 4.5m0-4.5l-4.5 4.5M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>',
        7  => '<svg class="w-4 h-4 text-slate-400" fill="none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M14.74 9l-.346 9m-4.788 0L9.26 9m9.968-3.21c.342.052.682.107 1.022.166m-1.022-.165L18.16 19.673a2.25 2.25 0 01-2.244 2.077H8.084a2.25 2.25 0 01-2.244-2.077L4.772 5.79m14.456 0a48.108 48.108 0 00-3.478-.397m-12 .562c.34-.059.68-.114 1.022-.165m0 0a48.11 48.11 0 013.478-.397m7.5 0v-.916c0-1.18-.91-2.164-2.09-2.201a51.964 51.964 0 00-3.32 0c-1.18.037-2.09 1.022-2.09 2.201v.916m7.5 0a48.667 48.667 0 00-7.5 0"/></svg>',
    ];
@endphp
<div id="orderStatusPanel" class="hidden fixed z-50 bg-white dark:bg-slate-900 rounded-2xl shadow-2xl border border-slate-200 dark:border-slate-700 w-64" data-order-status-panel>
    <div class="px-4 py-3 border-b border-slate-100 dark:border-slate-700">
        <p class="text-xs font-bold text-slate-700 dark:text-slate-200 font-mono">Order status</p>
    </div>
    <div class="py-1 max-h-80 overflow-y-auto">
        @foreach(\App\Models\Order::STATUS_ASSIGNABLE as $code)
        @php $opt = \App\Models\Order::STATUS_PILL[$code]; @endphp
        <div class="order-status-panel-option flex items-center gap-3 px-4 py-2.5 cursor-pointer hover:bg-slate-50 dark:hover:bg-slate-800" data-code="{{ $code }}" data-label="{{ $opt['label'] }}">
            <span class="shrink-0">{!! $orderStatusIcons[$code] !!}</span>
            <p class="flex-1 text-sm font-semibold text-slate-700 dark:text-slate-200 font-mono">{{ $opt['label'] }}</p>
            <svg class="order-status-panel-check hidden w-4 h-4 text-primary shrink-0" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7"/></svg>
        </div>
        @endforeach
    </div>
</div>

{{-- Real order tags panel (follow-up, 2026-08-22) — one shared floating panel,
     same pattern as #orderStatusPanel right above, opened by clicking a row's
     tag-count badge (leads/_table.blade.php's .real-tags-badge) instead of always
     rendering the chips inline — inline chips made a row with several real tags
     visibly taller than every other row, breaking the table's uniform height.
     Populated entirely client-side from the trigger's own data-tags JSON (see
     openRealTagsPanel() in calls.js) rather than a fetch, since the table already
     rendered that data server-side for the badge's own count. --}}
<div id="realTagsPanel" class="hidden fixed z-50 bg-white dark:bg-slate-900 rounded-2xl shadow-2xl border border-slate-200 dark:border-slate-700 w-64" data-real-tags-panel>
    <div class="px-4 py-3 border-b border-slate-100 dark:border-slate-700">
        <p class="text-xs font-bold text-slate-700 dark:text-slate-200 font-mono">Order tags</p>
    </div>
    <div class="real-tags-panel-list p-3 flex flex-wrap gap-1 max-h-60 overflow-y-auto"></div>
</div>

{{-- Call recording playback modal (explicit request, 2026-08-19) — lists
     every Drive recording matching this lead's phone number
     (LeadController::recordings(), matched by phone rather than a stored
     link since nothing writes that link anywhere — the phone's own
     auto-upload is the only thing that puts a file in Drive at all).
     Picking one loads it into the <audio> element below, streamed through
     our own backend (LeadController::streamRecording()) rather than a raw
     Drive URL, since that would require exposing the shared Drive access
     token to the browser. Opened via openRecordingModal(leadId) in
     calls.js. --}}
<div id="recordingModal" class="hidden fixed inset-0 z-50 items-center justify-center bg-black/50 p-6 opacity-0 transition-opacity duration-200">
    <div class="bg-white dark:bg-slate-900 rounded-2xl shadow-2xl w-full max-w-sm max-h-[70vh] flex flex-col overflow-hidden opacity-0 scale-95 transition-all duration-200">
        <div class="flex items-center justify-between px-5 py-3 border-b border-slate-200 dark:border-slate-700 shrink-0">
            <h3 class="text-sm font-bold text-slate-700 dark:text-slate-200 font-mono flex items-center gap-2">
                <svg class="w-4 h-4 text-emerald-500" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M12 18.75a6 6 0 006-6v-1.5m-6 7.5a6 6 0 01-6-6v-1.5m6 7.5v3.75m-3.75 0h7.5M12 15.75a3 3 0 01-3-3V4.5a3 3 0 116 0v8.25a3 3 0 01-3 3z"/>
                </svg>
                Call recording
            </h3>
            <button type="button" onclick="closeRecordingModal()" aria-label="Close"
                    class="p-1.5 rounded-lg text-slate-400 hover:text-slate-700 dark:hover:text-slate-200 hover:bg-slate-100 dark:hover:bg-slate-800 cursor-pointer">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/>
                </svg>
            </button>
        </div>
        <div id="recordingModalList" class="overflow-y-auto p-2 space-y-1">
            {{-- Populated by openRecordingModal() in calls.js --}}
        </div>
        <div class="px-4 py-3 border-t border-slate-100 dark:border-slate-700 shrink-0">
            <audio id="recordingModalPlayer" controls class="w-full h-10" preload="none"></audio>
        </div>
    </div>
</div>

{{-- Calling modal — click-to-call (My Leads table + lead detail page). When
     the lead's TSA has a Dialer address configured (Call Rotation), the
     number is dialed, muted/unmuted, AND ended straight from here: all three
     hit a MacroDroid macro listening on the TSA's own phone over Wi-Fi. With
     no Dialer address configured, dialing falls back to a plain tel: handoff
     and Mute/End Call have nothing to hit, so both are hidden. Opened via
     openCallingModal(name, number, dialerHost) in calls.js. --}}
<div id="callingModal" class="hidden fixed inset-0 z-50 items-center justify-center bg-black/50 p-6 opacity-0 transition-opacity duration-200" data-dial-host="">
    <div class="bg-white dark:bg-slate-900 rounded-2xl shadow-2xl w-full max-w-xs p-6 text-center opacity-0 scale-95 transition-all duration-200">
        <div class="mx-auto w-16 h-16 rounded-full bg-green-100 dark:bg-green-950/40 flex items-center justify-center mb-4 animate-pulse">
            <svg class="w-7 h-7 text-green-600 dark:text-green-400" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" d="M3 5a2 2 0 012-2h3.28a1 1 0 01.948.684l1.498 4.493a1 1 0 01-.502 1.21l-2.257 1.13a11.042 11.042 0 005.516 5.517l1.13-2.257a1 1 0 011.21-.502l4.493 1.498a1 1 0 01.684.949V19a2 2 0 01-2 2h-1C9.716 21 3 14.284 3 6V5z"/>
            </svg>
        </div>
        <p class="text-xs font-mono text-slate-400 uppercase tracking-wide mb-1">Calling</p>
        <p id="callingModalName" class="text-lg font-bold text-slate-800 dark:text-slate-100 font-mono truncate">—</p>
        <p id="callingModalNumber" class="text-sm text-slate-400 font-mono mb-5">—</p>
        <p id="callingModalHint" class="text-[11px] text-slate-400 mb-4">Sent to your phone — check your phone if nothing happens.</p>
        <div class="flex items-center gap-2">
            {{-- Same Wi-Fi-direct-to-phone approach as End Call (data-muted
                 tracks toggle state, reset on each new call in
                 openCallingModal()) — only shown when a dial-host is
                 configured, same gate as End Call, since both hit a
                 MacroDroid macro on the TSA's own phone. --}}
            <button type="button" id="muteCallBtn" onclick="toggleMute()" data-muted="0"
                    class="hidden flex-1 items-center justify-center gap-1.5 bg-slate-100 dark:bg-slate-800 hover:bg-slate-200 dark:hover:bg-slate-700 text-slate-600 dark:text-slate-300 text-sm font-semibold font-mono px-4 py-2.5 rounded-lg cursor-pointer">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M19 11a7 7 0 01-7 7m0 0a7 7 0 01-7-7m7 7v3m-3 0h6M12 15a3 3 0 003-3V6a3 3 0 10-6 0v6a3 3 0 003 3z"/>
                </svg>
                <span id="muteCallBtnLabel">Mute</span>
            </button>
            <button type="button" id="endCallBtn" onclick="endCall()"
                    class="hidden flex-1 items-center justify-center gap-1.5 bg-red-600 hover:bg-red-700 text-white text-sm font-semibold font-mono px-4 py-2.5 rounded-lg cursor-pointer">
                <svg class="w-4 h-4" fill="currentColor" viewBox="0 0 24 24"><path d="M12 9c-1.6 0-3.15.25-4.6.72v3.1c0 .39-.23.74-.56.9-.98.49-1.87 1.12-2.66 1.85-.18.18-.43.28-.7.28-.28 0-.53-.11-.71-.29L.29 13.08a.996.996 0 010-1.41C3.34 8.78 7.46 7 12 7s8.66 1.78 11.71 4.67c.18.18.29.43.29.71 0 .28-.11.53-.29.71l-2.48 2.48c-.18.18-.43.29-.71.29-.27 0-.52-.11-.7-.28a11.27 11.27 0 00-2.67-1.85.99.99 0 01-.56-.9v-3.1A17.6 17.6 0 0012 9z" transform="rotate(135 12 12)"/></svg>
                End Call
            </button>
            <button type="button" onclick="closeCallingModal()"
                    class="flex-1 bg-slate-100 dark:bg-slate-800 hover:bg-slate-200 dark:hover:bg-slate-700 text-slate-600 dark:text-slate-300 text-sm font-semibold font-mono px-4 py-2.5 rounded-lg cursor-pointer">
                Close
            </button>
        </div>
    </div>
</div>

{{-- Resume-call banner (explicit request, 2026-08-20) — closing the Calling
     modal via "Close" (not "End Call") used to lose all track of the call:
     clicking the same lead's number again just dialed a SECOND time on top
     of the one already in progress. calls.js now remembers the in-progress
     call in sessionStorage and shows this floating pill whenever it's
     tracked but the modal itself isn't open, so a TSA has an obvious way
     back into it without hunting down the right row again. Hidden by
     default; shown/hidden entirely by JS (showResumeBanner/hideResumeBanner
     in calls.js), never server-rendered with real data. --}}
<div id="resumeCallBanner" onclick="resumeActiveCall()"
     class="hidden fixed bottom-6 right-6 z-50 items-center gap-3 bg-white dark:bg-slate-900 border border-emerald-200 dark:border-emerald-800 shadow-2xl rounded-2xl pl-4 pr-3 py-3 cursor-pointer hover:shadow-emerald-900/20 max-w-xs">
    <span class="w-2.5 h-2.5 rounded-full bg-emerald-500 animate-pulse shrink-0"></span>
    <div class="min-w-0">
        <p class="text-[10px] font-mono text-slate-400 uppercase tracking-wide">Call in progress</p>
        <p id="resumeCallBannerName" class="text-sm font-bold text-slate-800 dark:text-slate-100 font-mono truncate">—</p>
    </div>
    <span class="text-xs font-bold text-primary-dark shrink-0 ml-1">Resume →</span>
</div>
