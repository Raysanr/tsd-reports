{{-- Team color coding reused from Leads Setup's own table (gold = SH
     Naturals/reporting, teal = Eyecare) rather than inventing a new palette. --}}
@php
    $teamStyles = [
        'SH Naturals'  => ['dot' => 'bg-primary',  'text' => 'text-primary-dark dark:text-yellow-400', 'bg' => 'bg-primary/10'],
        'Eyecare Team' => ['dot' => 'bg-teal-600',  'text' => 'text-teal-700 dark:text-teal-400',       'bg' => 'bg-teal-500/10'],
    ];
@endphp

<div class="bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-700 shadow-sm overflow-hidden">
    @if($tsas->isEmpty())
    <div class="py-20 flex flex-col items-center justify-center gap-3">
        <svg class="w-10 h-10 text-slate-200 dark:text-slate-700" fill="none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" d="M17 20h5v-2a4 4 0 00-3-3.87M9 20H4v-2a4 4 0 013-3.87m6-8.13a4 4 0 110 8 4 4 0 010-8zm6 8a4 4 0 00-3-3.87M5 12a4 4 0 013.87-3"/>
        </svg>
        <p class="text-sm font-mono text-slate-400">No TSAs match this filter.</p>
    </div>
    @else
    <div class="overflow-x-auto">
    <table class="w-full text-sm">
        <thead>
            <tr class="border-b border-slate-200 dark:border-slate-700">
                <th class="px-5 py-3 text-left text-[11px] font-bold font-mono text-slate-400 uppercase tracking-wide">TSA</th>
                <th class="px-5 py-3 text-left text-[11px] font-bold font-mono text-slate-400 uppercase tracking-wide">Team</th>
                <th class="px-5 py-3 text-left text-[11px] font-bold font-mono text-slate-400 uppercase tracking-wide">Status</th>
                <th class="px-5 py-3 text-left text-[11px] font-bold font-mono text-slate-400 uppercase tracking-wide">Active</th>
                <th class="px-5 py-3 text-left text-[11px] font-bold font-mono text-slate-400 uppercase tracking-wide">Handles</th>
                <th class="px-5 py-3"></th>
            </tr>
        </thead>
        <tbody class="divide-y divide-slate-100 dark:divide-slate-800">
            @foreach($tsas as $tsa)
                @php
                    $style        = $teamStyles[$tsa->team] ?? ['dot' => 'bg-slate-400', 'text' => 'text-slate-500', 'bg' => 'bg-slate-100'];
                    $tsaProducts  = $assignments[$tsa->id] ?? [];
                    $handledNames = $products->whereIn('id', $tsaProducts)->pluck('display_name');
                    // Only THIS TSA's own team's products in the edit form below —
                    // the old grid showed every product from both teams mixed
                    // together (confirmed confusing: an SH Naturals TSA saw
                    // Eyecare checkboxes she'd never use, and vice versa).
                    $teamProducts = $products->where('team', $tsa->team);
                @endphp
                <tr class="hover:bg-slate-50 dark:hover:bg-slate-800/60 transition-colors duration-150 cursor-pointer" data-tsa-row-toggle="{{ $tsa->id }}">
                    <td class="px-5 py-4">
                        <div class="flex items-center gap-3">
                            <div class="w-9 h-9 rounded-full bg-slate-800 dark:bg-slate-700 text-white flex items-center justify-center text-[11px] font-bold font-mono shrink-0">
                                {{ strtoupper(substr($tsa->display_name, 0, 2)) }}
                            </div>
                            <div class="min-w-0">
                                <div class="font-semibold text-slate-800 dark:text-slate-100 truncate">{{ $tsa->display_name }}</div>
                                <div class="text-[11px] text-slate-400 font-mono">{{ $tsa->tsa_key }}</div>
                            </div>
                        </div>
                    </td>
                    <td class="px-5 py-4">
                        <span class="inline-flex items-center gap-1.5 text-xs font-mono font-semibold {{ $style['text'] }} {{ $style['bg'] }} rounded-full px-2.5 py-1">
                            <span class="w-1.5 h-1.5 rounded-full {{ $style['dot'] }}"></span>
                            {{ $tsa->team }}
                        </span>
                    </td>
                    <td class="px-5 py-4">
                        @include('calls.partials.tsa-status-panel', [
                            'id'      => 'tsa-' . $tsa->id,
                            'options' => array_keys(\App\Models\TsaShift::STATUSES),
                            'current' => $tsa->status,
                            'target'  => (string) $tsa->id,
                        ])
                    </td>
                    <td class="px-5 py-4">
                        @if($tsa->active)
                        <span class="inline-flex items-center gap-1.5 text-[11px] font-bold text-emerald-700 dark:text-emerald-400 bg-emerald-50 dark:bg-emerald-950/40 rounded-full px-2.5 py-1">
                            <span class="w-1.5 h-1.5 rounded-full bg-emerald-500"></span>
                            Active
                        </span>
                        @else
                        <span class="inline-flex items-center gap-1.5 text-[11px] font-bold text-slate-500 dark:text-slate-400 bg-slate-100 dark:bg-slate-800 rounded-full px-2.5 py-1">
                            <span class="w-1.5 h-1.5 rounded-full bg-slate-400"></span>
                            Inactive
                        </span>
                        @endif
                    </td>
                    <td class="px-5 py-4">
                        @if($handledNames->isEmpty())
                        <span class="text-xs font-mono text-slate-400">None</span>
                        @else
                        <span class="text-xs font-mono font-semibold text-slate-600 dark:text-slate-300" title="{{ $handledNames->implode(', ') }}">
                            {{ $handledNames->count() }} {{ Str::plural('product', $handledNames->count()) }}
                        </span>
                        @endif
                    </td>
                    <td class="px-5 py-4 text-right">
                        <button type="button" data-tsa-row-toggle="{{ $tsa->id }}"
                                class="inline-flex items-center gap-1.5 text-xs font-mono font-semibold text-slate-500 dark:text-slate-400 hover:text-primary-dark cursor-pointer">
                            Edit
                            <svg id="tsaChevron-{{ $tsa->id }}" class="w-3.5 h-3.5 transition-transform duration-150" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M19 9l-7 7-7-7"/>
                            </svg>
                        </button>
                    </td>
                </tr>
                {{-- Always in the DOM (no `hidden`) — collapse/expand is a
                     grid-template-rows 0fr/1fr transition on the wrapper div
                     below instead, which is what makes it animate smoothly.
                     A plain height/max-height transition can't do this
                     cleanly for content whose real height isn't known ahead
                     of time (this panel's height varies a lot — collapsed
                     "Handles" details vs. expanded MacroDroid steps). The
                     inner overflow-hidden div is what actually clips the
                     content while grid-template-rows is mid-transition. --}}
                <tr id="tsaDetail-{{ $tsa->id }}">
                    <td colspan="6" class="px-5 bg-slate-50 dark:bg-slate-950/40">
                        <div data-tsa-detail-grid="{{ $tsa->id }}" class="grid transition-[grid-template-rows] duration-300 ease-in-out" style="grid-template-rows: 0fr;">
                        <div class="overflow-hidden">
                        <div class="space-y-4 pt-4 pb-6">
                            <form method="POST" action="{{ route('calls.tsa-management.update', $tsa) }}"
                                  class="bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-700 shadow-sm p-6">
                                @csrf

                                <div class="flex items-center justify-end mb-4">
                                    <label class="flex items-center gap-2 text-xs font-mono font-semibold text-slate-500 dark:text-slate-400 uppercase tracking-wide cursor-pointer">
                                        <input type="checkbox" name="active" value="1" {{ $tsa->active ? 'checked' : '' }}
                                               class="rounded border-slate-300 dark:border-slate-600 text-primary focus:ring-yellow-500">
                                        Active
                                    </label>
                                </div>

                                <div class="mb-4">
                                    <label class="block text-xs font-mono font-semibold text-slate-500 dark:text-slate-400 uppercase tracking-wide mb-1">Phone number</label>
                                    <input type="text" name="phone_number" value="{{ $tsa->phone_number }}" placeholder="0917 123 4567"
                                           class="w-full sm:w-64 text-sm font-mono border border-slate-300 dark:border-slate-600 rounded-lg px-3 py-2 bg-white dark:bg-slate-800 text-slate-800 dark:text-slate-100 focus:outline-none focus:ring-2 focus:ring-yellow-500">
                                    <p class="text-[11px] text-slate-400 mt-1">The number on their phone's own SIM — used to label their calls, not required for call logging itself.</p>
                                </div>

                                {{-- Auto-dial + Mute + End Call: neither Windows Phone Link nor
                                     macOS Continuity Calls can bridge a Mac/Windows browser to an
                                     Android phone, so click-to-call in Leads instead hits this
                                     address directly over Wi-Fi — three MacroDroid macros on the
                                     phone listen here: one places the call, one toggles mic mute,
                                     the third ends it. See the setup steps below for wiring all
                                     three up. --}}
                                <div class="mb-4">
                                    <label class="block text-xs font-mono font-semibold text-slate-500 dark:text-slate-400 uppercase tracking-wide mb-1">Dialer address (auto-dial over Wi-Fi)</label>
                                    <input type="text" name="dialer_host" value="{{ $tsa->dialer_host }}" placeholder="192.168.1.42:8080"
                                           class="w-full sm:w-64 text-sm font-mono border border-slate-300 dark:border-slate-600 rounded-lg px-3 py-2 bg-white dark:bg-slate-800 text-slate-800 dark:text-slate-100 focus:outline-none focus:ring-2 focus:ring-yellow-500">
                                    <p class="text-[11px] text-slate-400 mt-1">{{ $tsa->display_name }}'s phone's own local IP:port, from the MacroDroid macros below. Leave blank to skip auto-dial — clicking their leads' phone numbers still shows the number, it just won't dial by itself.</p>

                                    <details class="text-xs font-mono text-slate-500 dark:text-slate-400 mt-2">
                                        <summary class="cursor-pointer text-primary-dark hover:underline">Auto-dial + Mute + End Call setup steps ↓</summary>
                                        <p class="mt-2 font-bold text-slate-700 dark:text-slate-200">Macro 1 of 3 — dialing</p>
                                        <ol class="list-decimal list-inside mt-2 space-y-2.5 text-slate-600 dark:text-slate-300">
                                            <li>
                                                On {{ $tsa->display_name }}'s phone, open <strong>MacroDroid</strong> (already installed for call logging) and start a <strong>second, separate macro</strong> — tap <strong>+</strong>.
                                            </li>
                                            <li>
                                                <strong>Trigger:</strong> tap <strong>+</strong> → search <strong>HTTP Server Request</strong> → select it.
                                                <ul class="list-disc list-inside ml-4 mt-1 space-y-1">
                                                    <li><strong>Path</strong> → type <code>dial</code>.</li>
                                                    <li>Scroll to <strong>Variable Whitelist</strong> → add a variable named exactly <code>number</code>. This lets a request like <code>?number=0917...</code> automatically fill a <code>{v=number}</code> variable you'll use in the next step.</li>
                                                    <li>Tap <strong>✓</strong> to confirm.</li>
                                                </ul>
                                            </li>
                                            <li>
                                                <strong>Action:</strong> Actions tab → <strong>+</strong> → search <strong>Make Call</strong> → select it.
                                                <ul class="list-disc list-inside ml-4 mt-1 space-y-1">
                                                    <li>Choose <strong>[Select Number]</strong>, tap the magic-text <strong>{v}</strong> button, and insert the <code>number</code> variable — the field should show <code>{v=number}</code>.</li>
                                                    <li>If the phone has dual SIMs, pick the correct one under <strong>Sim card</strong>.</li>
                                                    <li>Tap <strong>✓</strong> to confirm, then save/name the macro (e.g. <em>"Remote Dial"</em>) and make sure it's <strong>on</strong>.</li>
                                                </ul>
                                            </li>
                                            <li>
                                                <strong>Grant the phone-call permission</strong> if MacroDroid prompts for it — without it, "Make Call" silently does nothing.
                                            </li>
                                        </ol>

                                        <p class="mt-4 font-bold text-slate-700 dark:text-slate-200">Macro 2 of 3 — muting</p>
                                        <ol class="list-decimal list-inside mt-2 space-y-2.5 text-slate-600 dark:text-slate-300">
                                            <li>
                                                Start another new macro (tap <strong>+</strong> on MacroDroid's main list again).
                                            </li>
                                            <li>
                                                <strong>Trigger:</strong> same as before — search <strong>HTTP Server Request</strong> → select it → <strong>Path</strong> → type <code>mute</code> this time. No variable needed here. Tap <strong>✓</strong>.
                                            </li>
                                            <li>
                                                <strong>Action:</strong> Actions tab → <strong>+</strong> → search <strong>Mute</strong> and pick whichever mic-mute action your MacroDroid version offers (e.g. <strong>Toggle Microphone Mute</strong>) → tap <strong>✓</strong>.
                                                <p class="mt-1">The exact action name varies by MacroDroid/Android version and can require Accessibility permission to reach the in-call mute control — if you don't see a mic-specific option, this macro isn't available on this phone and the popup's Mute button just won't do anything, same as leaving the Dialer address blank.</p>
                                            </li>
                                            <li>
                                                Save/name the macro (e.g. <em>"Remote Mute"</em>) and make sure it's <strong>on</strong>.
                                            </li>
                                        </ol>

                                        <p class="mt-4 font-bold text-slate-700 dark:text-slate-200">Macro 3 of 3 — ending the call</p>
                                        <ol class="list-decimal list-inside mt-2 space-y-2.5 text-slate-600 dark:text-slate-300">
                                            <li>
                                                Start another new macro (tap <strong>+</strong> on MacroDroid's main list again).
                                            </li>
                                            <li>
                                                <strong>Trigger:</strong> same as before — search <strong>HTTP Server Request</strong> → select it → <strong>Path</strong> → type <code>hangup</code> this time. No variable needed here. Tap <strong>✓</strong>.
                                            </li>
                                            <li>
                                                <strong>Action:</strong> Actions tab → <strong>+</strong> → search <strong>Call Reject</strong> → select it (no options to configure) → tap <strong>✓</strong>.
                                                <p class="mt-1">MacroDroid's own documentation confirms this specific action ends an already-in-progress call, not just a still-ringing one — that's why it's the right action here, not "Answer Call" or anything dial-related.</p>
                                            </li>
                                            <li>
                                                Save/name the macro (e.g. <em>"Remote Hang Up"</em>) and make sure it's <strong>on</strong>.
                                            </li>
                                        </ol>

                                        <p class="mt-4 font-bold text-slate-700 dark:text-slate-200">Finishing up</p>
                                        <ol class="list-decimal list-inside mt-2 space-y-2.5 text-slate-600 dark:text-slate-300">
                                            <li>
                                                <strong>Find the address to put above:</strong> open any of the three HTTP Server Request triggers you just made — it shows the full URL, something like <code>http://192.168.1.42:8080/dial</code>. Copy just the <code>IP:port</code> part (before <code>/dial</code>, <code>/mute</code>, or <code>/hangup</code>) into the <strong>Dialer address</strong> field above — all three macros share the same phone, so the same address covers all of them. (Port defaults to 8080 — MacroDroid Settings → HTTP Server Settings if you need to check or change it.)
                                            </li>
                                            <li>
                                                <strong>Both devices must stay on the same Wi-Fi network</strong> — this only works over the local network, not the internet. If the phone reconnects to Wi-Fi later, its IP can change and this field will need updating (a static IP reservation on your router avoids that).
                                            </li>
                                            <li>
                                                Save this form, then test: click one of {{ $tsa->display_name }}'s leads' phone numbers in Leads — the phone should start dialing within a second or two, no tap needed on the phone itself, and the popup's <strong>Mute</strong> and <strong>End Call</strong> buttons should control it from there.
                                            </li>
                                        </ol>
                                    </details>
                                </div>

                                <p class="text-xs font-mono font-semibold text-slate-500 dark:text-slate-400 uppercase tracking-wide mb-2">Handles ({{ $tsa->team }} only)</p>
                                <div class="grid grid-cols-2 sm:grid-cols-3 gap-2 mb-4">
                                    @foreach($teamProducts as $product)
                                        <label class="flex items-center gap-2 text-sm text-slate-700 dark:text-slate-200 border border-slate-200 dark:border-slate-700 rounded-lg px-3 py-2 cursor-pointer hover:bg-slate-50 dark:hover:bg-slate-800">
                                            <input type="checkbox" name="products[]" value="{{ $product->id }}"
                                                   {{ in_array($product->id, $tsaProducts) ? 'checked' : '' }}
                                                   class="rounded border-slate-300 dark:border-slate-600 text-primary focus:ring-yellow-500">
                                            {{ $product->display_name }}
                                        </label>
                                    @endforeach
                                </div>

                                <button type="submit"
                                        class="bg-primary hover:bg-primary-dark text-white text-sm font-semibold font-mono px-4 py-2 rounded-lg cursor-pointer">
                                    Save
                                </button>
                            </form>

                            {{-- Call automation setup — separate card/form from the one above:
                                 regenerating a token is a deliberate action (see
                                 TsaManagementController::regenerateApiToken()'s own doc
                                 comment), not something that should happen as a side effect of
                                 saving the "Handles" checkboxes. --}}
                            <div class="bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-700 shadow-sm p-6">
                                <div class="flex items-center justify-between gap-4 mb-3">
                                    <h3 class="text-xs font-mono font-semibold text-slate-500 dark:text-slate-400 uppercase tracking-wide">Phone call automation (MacroDroid)</h3>
                                    <form method="POST" action="{{ route('calls.tsa-management.regenerate-token', $tsa) }}"
                                          @if($tsa->api_token) onsubmit="return confirm('This invalidates their current token — MacroDroid on their phone needs updating with the new one right after. Continue?');" @endif>
                                        @csrf
                                        <button type="submit" class="text-xs font-semibold text-slate-500 dark:text-slate-400 hover:text-primary-dark border border-slate-200 dark:border-slate-700 hover:border-primary rounded-lg px-3 py-1.5 cursor-pointer">
                                            {{ $tsa->api_token ? 'Regenerate token' : 'Generate token' }}
                                        </button>
                                    </form>
                                </div>

                                @if($tsa->api_token)
                                <div class="grid grid-cols-1 sm:grid-cols-2 gap-3 mb-3">
                                    <div>
                                        <label class="block text-[11px] text-slate-400 mb-1">Webhook URL</label>
                                        <input type="text" readonly value="{{ url('/api/call-events') }}"
                                               onclick="this.select()"
                                               class="w-full text-xs font-mono border border-slate-200 dark:border-slate-700 rounded-lg px-2 py-1.5 bg-slate-50 dark:bg-slate-800 text-slate-600 dark:text-slate-300">
                                    </div>
                                    <div>
                                        <label class="block text-[11px] text-slate-400 mb-1">api_token</label>
                                        <input type="text" readonly value="{{ $tsa->api_token }}"
                                               onclick="this.select()"
                                               class="w-full text-xs font-mono border border-slate-200 dark:border-slate-700 rounded-lg px-2 py-1.5 bg-slate-50 dark:bg-slate-800 text-slate-600 dark:text-slate-300">
                                    </div>
                                </div>

                                <details class="text-xs font-mono text-slate-500 dark:text-slate-400">
                                    <summary class="cursor-pointer text-primary-dark hover:underline">MacroDroid setup steps ↓</summary>
                                    <ol class="list-decimal list-inside mt-2 space-y-3 text-slate-600 dark:text-slate-300">
                                        <li>
                                            <strong>Install the app.</strong> On {{ $tsa->display_name }}'s phone, open the Play Store, search <strong>MacroDroid</strong> (by "Arlosoft"), tap Install, then Open.
                                        </li>
                                        <li>
                                            <strong>Grant every permission it asks for on first launch</strong> — it'll step through several one-by-one prompts (Phone/Call Log access, Notifications, "Display over other apps", and a battery-optimization exemption). Allow all of them. If Phone/Call Log access is denied, the trigger in step 4 can never fire or read the number/duration — this is the #1 reason a setup silently does nothing.
                                        </li>
                                        <li>
                                            <strong>Start a new macro.</strong> On MacroDroid's main screen (it lists your macros — empty on a fresh install), tap the <strong>+</strong> floating button in the bottom-right corner. This opens "Add Trigger" — every macro must start with a trigger.
                                        </li>
                                        <li>
                                            <strong>Add the trigger.</strong> Tap <strong>+</strong> → scroll the category list to <strong>Call/SMS</strong> → tap it to expand → tap <strong>Call Ended</strong>.
                                            <ul class="list-disc list-inside ml-4 mt-1 space-y-1">
                                                <li>A config screen may appear asking which number(s) to match — leave <strong>Any number</strong> selected.</li>
                                                <li>Tap the <strong>✓</strong> (checkmark) top-right to confirm and return to the macro editor.</li>
                                                <li>Do NOT pick "HTTP Server Request" anywhere in this process — that's an unrelated trigger that turns the phone itself into a web server, not what we want.</li>
                                            </ul>
                                        </li>
                                        <li>
                                            <strong>Switch to the Actions tab.</strong> You're now on the macro editor, which has 3 tabs across the top: <strong>Triggers | Constraints | Actions</strong>. Tap <strong>Actions</strong>, then tap <strong>+</strong> to add one.
                                        </li>
                                        <li>
                                            <strong>Find the HTTP Request action by searching, not browsing.</strong> On the "Add Action" screen, tap the <strong>magnifying-glass icon</strong> at the top (don't scroll through categories — which category it's filed under changes between MacroDroid versions and isn't worth hunting for). Type <strong>HTTP Request</strong> and tap the single matching result.
                                        </li>
                                        <li>
                                            <strong>Configure the request:</strong>
                                            <ul class="list-disc list-inside ml-4 mt-1 space-y-1">
                                                <li><strong>Request method</strong> → tap the dropdown, choose <strong>POST</strong>.</li>
                                                <li><strong>URL</strong> → tap the field, paste the Webhook URL from the box above.</li>
                                                <li>Scroll down to the <strong>Body</strong>/content section. Tap into it and paste the line below exactly (tap the gray box, it auto-selects the whole string, then copy it):
                                                    <div class="mt-1 bg-slate-50 dark:bg-slate-800 border border-slate-200 dark:border-slate-700 rounded-lg px-2 py-1.5 select-all">api_token={{ $tsa->api_token }}&amp;phone_number=[call_number]&amp;direction=[call_type]&amp;duration_seconds=[call_duration]</div>
                                                </li>
                                                <li>The bracketed parts (<code>[call_number]</code>, <code>[call_type]</code>, <code>[call_duration]</code>) are MacroDroid's own placeholders for the real call data. If typing them out by hand doesn't work, delete them and instead tap the <strong>{v}</strong> variable-picker button next to the Body field and insert "Call Number" / "Call Type" / "Call Duration" from MacroDroid's own list — same result, just guaranteed to substitute correctly.</li>
                                                <li>Leave the remaining options (Timeout, Follow redirects, etc.) on their defaults.</li>
                                                <li>Tap the <strong>✓</strong> checkmark top-right to save this action.</li>
                                            </ul>
                                        </li>
                                        <li>
                                            <strong>About [call_type]:</strong> MacroDroid reports it as <code>Outgoing</code>/<code>Incoming</code>/<code>Missed</code> (capitalized) — this system already accepts that as-is, no extra step needed. Only add a "Search & Replace" action before the HTTP Request if you ever see it come through as something else entirely.
                                        </li>
                                        <li>
                                            <strong>Save and name the macro.</strong> Tap the <strong>save/disk icon</strong> (or the back arrow, which prompts the same thing) in the top corner. Give it a clear name, e.g. <em>"{{ $tsa->display_name }} – Call Log Upload"</em>, then confirm.
                                        </li>
                                        <li>
                                            <strong>Confirm it's enabled.</strong> Back on MacroDroid's main macro list, find the one you just made and make sure its toggle switch is <strong>on</strong> (usually shown at the right edge of the row).
                                        </li>
                                        <li>
                                            <strong>Test it for real.</strong> Make or receive one actual phone call, then hang up. Within a few seconds it should appear on the <a href="{{ route('calls.call-log') }}" class="text-primary hover:underline">Call Log</a> page here.
                                        </li>
                                        <li>
                                            <strong>If nothing shows up:</strong> open MacroDroid, tap the macro, then its <strong>⋮</strong> (three-dot) menu → <strong>View history</strong> (or long-press the macro on the main list) to see whether it ran at all and what HTTP response code came back:
                                            <ul class="list-disc list-inside ml-4 mt-1 space-y-1">
                                                <li><strong>Didn't run at all</strong> → the Call Ended trigger isn't firing; re-check the Phone/Call Log permission from step 2.</li>
                                                <li><strong>401</strong> → the api_token in the Body doesn't match the one shown above (was it regenerated since?).</li>
                                                <li><strong>422</strong> → one of the bracketed variables didn't get filled in — redo step 7 using the {v} picker instead of typed brackets.</li>
                                                <li><strong>Connection error / timeout</strong> → the phone had no internet at that moment, or the Webhook URL was mistyped.</li>
                                            </ul>
                                        </li>
                                    </ol>
                                </details>
                                @else
                                <p class="text-xs font-mono text-slate-400">No token yet — click "Generate token" above, then follow the MacroDroid setup steps that appear.</p>
                                @endif
                            </div>

                            {{-- Call recording auto-upload — a separate setup from the
                                 MacroDroid one above: that one logs WHICH number was called and
                                 for how long, this one uploads the actual audio. Uses the same
                                 api_token, kept as its own card since it needs extra software
                                 (Phone Link + a recorder) the call-log setup doesn't. --}}
                            @if($tsa->api_token)
                            <div class="bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-700 shadow-sm p-6">
                                <h3 class="text-xs font-mono font-semibold text-slate-500 dark:text-slate-400 uppercase tracking-wide mb-3">Call recording auto-upload</h3>
                                <details class="text-xs font-mono text-slate-500 dark:text-slate-400">
                                    <summary class="cursor-pointer text-primary-dark hover:underline">Setup steps ↓</summary>
                                    <ol class="list-decimal list-inside mt-2 space-y-3 text-slate-600 dark:text-slate-300">
                                        <li>
                                            <strong>Set up Phone Link</strong> on {{ $tsa->display_name }}'s PC and pair it with their phone (search "Phone Link" in the Windows Start menu, or Settings → Bluetooth & devices). Confirm calls show up on the PC when the phone rings — Phone Link only mirrors the audio, it doesn't save a file on its own.
                                        </li>
                                        <li>
                                            <strong>Install a free recorder</strong> — <a href="https://obsproject.com" target="_blank" rel="noopener" class="text-primary hover:underline">OBS Studio</a> is recommended. Add an Audio Output source, set it to record only that source (not the screen), and set the output format to mp3 or wav in Settings → Output. Pick a recording folder you'll remember, e.g. <code>C:\Users\{{ Str::slug($tsa->display_name, '') }}\Videos\Call Recordings</code>.
                                        </li>
                                        <li>
                                            <strong>Get the auto-upload script.</strong> In the app's code repository, under <code>tools/recording-uploader/</code>, there's <code>upload-recordings.ps1</code> and a full <code>README.md</code> with these same steps. Copy that folder onto {{ $tsa->display_name }}'s PC.
                                        </li>
                                        <li>
                                            <strong>Edit the script</strong> (right-click → Edit in Notepad) and fill in the top 3 lines:
                                            <div class="mt-1 bg-slate-50 dark:bg-slate-800 border border-slate-200 dark:border-slate-700 rounded-lg px-2 py-1.5 select-all">$ApiToken = "{{ $tsa->api_token }}"</div>
                                            plus <code>$WatchFolder</code> (the exact folder from step 2 — must match exactly).
                                        </li>
                                        <li>
                                            <strong>Run it</strong>: right-click the script → "Run with PowerShell", and leave the window open — it watches the folder and uploads each new recording automatically, moving it into an "Uploaded" subfolder afterward so nothing uploads twice.
                                        </li>
                                        <li>
                                            <strong>Test it for real.</strong> Make one actual call, hang up, let the recorder finish saving. It should upload within a few seconds and appear on the <a href="{{ route('calls.call-recordings') }}" class="text-primary hover:underline">Call Recordings</a> page.
                                        </li>
                                        <li>
                                            <strong>Start it automatically every day:</strong> press <kbd>Win+R</kbd>, type <code>shell:startup</code>, press Enter, then paste a shortcut to the script into that folder — it'll launch on its own every time this PC starts.
                                        </li>
                                    </ol>
                                </details>
                            </div>
                            @endif
                        </div>
                        </div>
                        </div>
                    </td>
                </tr>
            @endforeach
        </tbody>
    </table>
    </div>
    @endif
</div>
