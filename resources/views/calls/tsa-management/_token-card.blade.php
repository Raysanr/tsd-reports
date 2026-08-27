{{-- Call automation setup — separate card/form from the "Handles"/dialer one
     above: regenerating a token is a deliberate action (see
     TsaManagementController::regenerateApiToken()'s own doc comment), not
     something that should happen as a side effect of saving those checkboxes.

     Extracted into its own partial (explicit request, 2026-08-27: "i want
     when in every generate token it is not resetting the whole page ... a
     small pop up") so TsaManagementController::regenerateApiToken() can
     re-render just this fragment and hand it back as JSON — the calls.js
     handler swaps it in over the id below instead of the old plain form
     submit's full-page redirect, and shows the confirmation as a toast
     instead. The id is per-TSA since this partial renders once per row in
     the expanded-panel table above. --}}
<div id="token-card-{{ $tsa->id }}" class="bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-700 shadow-sm p-6">
    <div class="flex items-center justify-between gap-4 mb-3">
        <h3 class="text-xs font-mono font-semibold text-slate-500 dark:text-slate-400 uppercase tracking-wide">Phone call automation (MacroDroid)</h3>
        {{-- data-confirm (not an inline onsubmit) — the JS handler reads this
             itself before deciding whether to prompt, so the same confirm
             behavior still applies after this card has been swapped in via
             AJAX, not just on the very first server render. --}}
        <form method="POST" action="{{ route('calls.tsa-management.regenerate-token', $tsa) }}"
              class="tsa-regenerate-token-form"
              @if($tsa->api_token) data-confirm="This invalidates their current token — MacroDroid on their phone needs updating with the new one right after. Continue?" @endif>
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

    {{-- Unified setup guide — all 4 macros this TSA's phone needs
         (call logging, dial, mute, hang up), merged into one ordered
         tutorial instead of two separate accordions (one used to live
         under Dialer address above, covering only dial/mute/hangup;
         call logging had its own, separate "Macro 1 of 1"). Explicit
         request: real per-TSA values (macro name, webhook URL,
         api_token, dialer_host) inlined and select-all/copy-pastable
         directly in the steps, concrete "tap this" phrasing over
         prose, and the mute macro restored (it was accidentally
         dropped from an earlier draft of this same merge). --}}
    <details class="text-xs font-mono text-slate-500 dark:text-slate-400">
        <summary class="cursor-pointer text-primary-dark hover:underline">MacroDroid setup guide — 4 macros ↓</summary>

        <p class="mt-2 font-bold text-slate-700 dark:text-slate-200">Before you start</p>
        <ul class="list-disc list-inside mt-1 space-y-1 text-slate-600 dark:text-slate-300">
            <li>Install <strong>MacroDroid</strong> (by "Arlosoft") from the Play Store on {{ $tsa->display_name }}'s phone and open it.</li>
            <li>Grant every permission it asks for on first launch (Phone/Call Log, Notifications, "Display over other apps", battery-optimization exemption) — deny Phone/Call Log and Macros 1 and 2 below can't work at all.</li>
            <li>Confirm the <strong>Webhook URL</strong> below is actually reachable from {{ $tsa->display_name }}'s phone, not just this computer — if it shows <code>localhost</code> or <code>127.0.0.1</code>, replace that part with this machine's real LAN IP (or your production domain) before pasting it into Macro 1.</li>
        </ul>

        <p class="mt-4 font-bold text-slate-700 dark:text-slate-200">Keep it running when the phone isn't actively being used</p>
        <p class="mt-1">Macros 2–4 below use an <strong>HTTP Server Request</strong> trigger — MacroDroid keeps a tiny local web server alive on the phone waiting for that click, and Android's battery management will happily kill that the moment it decides the app is "inactive." If Remote dial/mute/hang up only work while MacroDroid is open on screen, one of these is why:</p>
        <ul class="list-disc list-inside mt-1 space-y-1 text-slate-600 dark:text-slate-300">
            <li>Don't swipe MacroDroid away in the Recent Apps switcher — pressing Home is fine, swiping the card away force-kills it (and its local server) on most phones.</li>
            <li>Battery → find MacroDroid → set to <strong>Unrestricted</strong> (not "Optimized"), not just the one-time popup on first launch.</li>
            <li>Keep MacroDroid's own persistent notification turned on — it deliberately shows one so Android treats it as a foreground service and leaves it alone. Don't disable notifications for the app.</li>
            <li>On Xiaomi/MIUI, Oppo/ColorOS, Vivo, and Samsung phones there's a <em>second</em>, manufacturer-specific battery manager on top of stock Android's — look for "Autostart" (MIUI) or "Allow background activity" (ColorOS/Vivo) or "Sleeping apps" (Samsung, make sure it's <strong>not</strong> in that list) and allow MacroDroid there too. This is the most common reason it still stops even after the two steps above.</li>
        </ul>

        <p class="mt-4 font-bold text-slate-700 dark:text-slate-200">Macro 1 of 4 — "{{ $tsa->tsa_key }}" (call logging)</p>
        <ol class="list-decimal list-inside mt-2 space-y-2 text-slate-600 dark:text-slate-300">
            <li>Tap <strong>+</strong> to start a new macro.</li>
            <li><strong>Trigger:</strong> search <strong>Call/SMS</strong> → <strong>Call Ended</strong> → leave <strong>Any number</strong> selected → <strong>✓</strong>. (Not "HTTP Server Request" — that's a different trigger, used in Macros 2–4 below.)</li>
            <li><strong>Actions</strong> tab → <strong>+</strong> → search <strong>HTTP Request</strong> → select it.</li>
            <li><strong>Request method</strong> → <strong>POST</strong>.</li>
            <li><strong>URL</strong> → paste:
                <div class="mt-1 bg-slate-50 dark:bg-slate-800 border border-slate-200 dark:border-slate-700 rounded-lg px-2 py-1.5 select-all">{{ url('/api/call-events') }}</div>
            </li>
            <li><strong>Body</strong> → paste this exactly (tap the gray box below, it auto-selects the whole string):
                <div class="mt-1 bg-slate-50 dark:bg-slate-800 border border-slate-200 dark:border-slate-700 rounded-lg px-2 py-1.5 select-all">api_token={{ $tsa->api_token }}&amp;phone_number=[call_number]&amp;direction=[call_type]&amp;duration_seconds=[call_duration]</div>
                If the bracketed parts don't substitute correctly when typed by hand, delete them and use the <strong>{v}</strong> variable-picker button next to the Body field instead → insert <strong>Call Number</strong> / <strong>Call Type</strong> / <strong>Call Duration</strong> from MacroDroid's own list. (<code>[call_type]</code> comes through as <code>Outgoing</code>/<code>Incoming</code>/<code>Missed</code> — accepted as-is, no extra step needed.)
            </li>
            <li>Tap <strong>✓</strong> to save the action, then save/name the macro <strong>"{{ $tsa->tsa_key }}"</strong> and confirm its toggle is <strong>on</strong>.</li>
            <li>Test: make or receive one real call, hang up — it should appear on <a href="{{ route('calls.call-log') }}" class="text-primary hover:underline">Call Log</a> within a few seconds.</li>
        </ol>

        <p class="mt-4 font-bold text-slate-700 dark:text-slate-200">Macro 2 of 4 — "Remote dial"</p>
        <ol class="list-decimal list-inside mt-2 space-y-2 text-slate-600 dark:text-slate-300">
            <li>New macro, tap <strong>+</strong>.</li>
            <li><strong>Trigger:</strong> search <strong>HTTP Server Request</strong> → select it → <strong>Path</strong> → <code>dial</code> → scroll to <strong>Variable Whitelist</strong> → add a variable named exactly <code>number</code> → <strong>✓</strong>.</li>
            <li><strong>Actions</strong> tab → <strong>+</strong> → search <strong>Make Call</strong> → select it.</li>
            <li><strong>[Select Number]</strong> → tap <strong>{v}</strong> → insert the <code>number</code> variable — the field shows <code>{lv=number}</code> (that's correct: <code>lv</code> is a <em>local</em> variable, scoped to this trigger — not <code>{v=number}</code>).</li>
            <li>Dual-SIM phone? Pick the right one under <strong>Sim card</strong>.</li>
            <li>Tap <strong>✓</strong>, save/name the macro <strong>"Remote dial"</strong>, confirm it's <strong>on</strong>. Grant the phone-call permission if prompted — without it, "Make Call" silently does nothing.</li>
        </ol>

        <p class="mt-4 font-bold text-slate-700 dark:text-slate-200">Macro 3 of 4 — "Remote mute"</p>
        <ol class="list-decimal list-inside mt-2 space-y-2 text-slate-600 dark:text-slate-300">
            <li>New macro, tap <strong>+</strong>.</li>
            <li><strong>Trigger:</strong> <strong>HTTP Server Request</strong> → <strong>Path</strong> → <code>mute</code> — no variable needed → <strong>✓</strong>.</li>
            <li><strong>Actions</strong> tab → <strong>+</strong> → search <strong>UI Interaction</strong> and pick the "click on-screen text" action your MacroDroid version offers (e.g. <strong>Click Text On Screen</strong>) → set the text to match to <strong>mute</strong> (case-insensitive, partial match is fine) → <strong>✓</strong>. This taps the phone's own in-call Mute button directly instead of relying on a separate microphone-toggle action, so it works even on phones with no dedicated mic-mute action — requires Accessibility permission (MacroDroid prompts for this the first time a UI Interaction action actually runs). If the in-call screen's Mute control has no visible "Mute" text on this phone (icon-only, or a different label), this action won't find anything to click, same as leaving Dialer address blank.</li>
            <li>Save/name the macro <strong>"Remote mute"</strong>, confirm it's <strong>on</strong>.</li>
        </ol>

        <p class="mt-4 font-bold text-slate-700 dark:text-slate-200">Macro 4 of 4 — "Remote hang up"</p>
        <ol class="list-decimal list-inside mt-2 space-y-2 text-slate-600 dark:text-slate-300">
            <li>New macro, tap <strong>+</strong>.</li>
            <li><strong>Trigger:</strong> <strong>HTTP Server Request</strong> → <strong>Path</strong> → <code>hangup</code> — no variable needed → <strong>✓</strong>.</li>
            <li><strong>Actions</strong> tab → <strong>+</strong> → search <strong>Call Reject</strong> → select it (nothing to configure) → <strong>✓</strong>. This specific action ends an already-in-progress call, not just a still-ringing one — that's why it's the right one here, not "Answer Call".</li>
            <li>Save/name the macro <strong>"Remote hang up"</strong>, confirm it's <strong>on</strong>.</li>
        </ol>

        <p class="mt-4 font-bold text-slate-700 dark:text-slate-200">Finishing up</p>
        <ol class="list-decimal list-inside mt-2 space-y-2 text-slate-600 dark:text-slate-300">
            <li>Open either HTTP Server Request trigger (<code>dial</code> or <code>hangup</code>) from Macros 2–4 — it shows the full URL, e.g. <code>http://192.168.1.42:8080/dial</code>. Copy just the <code>IP:port</code> part into the <strong>Dialer address</strong> field above{{ $tsa->dialer_host ? " (already filled in as {$tsa->dialer_host})" : '' }} — all 3 macros share the same phone, so one address covers all of them.</li>
            <li>{{ $tsa->display_name }}'s phone and whoever's using Leads must stay on the <strong>same Wi-Fi network</strong> — this only works over the local network, not the internet. If the phone reconnects to Wi-Fi later its IP can change and this field will need updating (a static IP reservation on your router avoids that).</li>
            <li>Save this form, then test: click one of {{ $tsa->display_name }}'s leads' phone numbers in Leads — the phone should dial within a second or two, no tap needed on the phone itself, and the popup's <strong>Mute</strong>/<strong>End Call</strong> buttons should control it.</li>
        </ol>

        <p class="mt-4 font-bold text-slate-700 dark:text-slate-200">If Macro 1 doesn't show up on Call Log</p>
        <p class="mt-1">Open MacroDroid → tap the macro → <strong>⋮</strong> (three-dot menu) → <strong>View history</strong> (or long-press the macro on the main list) to see whether it ran and what response code came back:</p>
        <ul class="list-disc list-inside ml-4 mt-1 space-y-1 text-slate-600 dark:text-slate-300">
            <li><strong>Didn't run at all</strong> → the Call Ended trigger isn't firing; re-check the Phone/Call Log permission.</li>
            <li><strong>401</strong> → the api_token in the Body doesn't match the one shown above (was it regenerated since?).</li>
            <li><strong>422</strong> → one of the bracketed variables didn't get filled in — redo Macro 1's Body step using the {v} picker instead of typed brackets.</li>
            <li><strong>Connection error / timeout</strong> → the phone had no internet at that moment, or the Webhook URL is mistyped or unreachable from the phone (see "Before you start" above).</li>
        </ul>
    </details>
    @else
    <p class="text-xs font-mono text-slate-400">No token yet — click "Generate token" above, then follow the 4-macro setup guide that appears.</p>
    @endif
</div>
