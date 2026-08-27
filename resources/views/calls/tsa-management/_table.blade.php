{{-- Team color coding reused from Leads Setup's own table (gold = SH
     Naturals/reporting, teal = Eyecare) rather than inventing a new palette. --}}
@php
    $teamStyles = [
        'SH Naturals'  => ['dot' => 'bg-primary',  'text' => 'text-primary-dark dark:text-yellow-400', 'bg' => 'bg-primary/10'],
        'Eyecare Team' => ['dot' => 'bg-teal-600',  'text' => 'text-teal-700 dark:text-teal-400',       'bg' => 'bg-teal-500/10'],
    ];
    // Same status pill colors as Dashboard's own TSA Performance Overview
    // table (explicit request, 2026-08-24: replace the old manual "Active"
    // toggle/column with the TSA's real live status instead).
    $statusDot = fn ($status) => match (true) {
        $status === \App\Models\TsaShift::STATUS_LOGIN  => 'bg-emerald-500',
        $status === \App\Models\TsaShift::STATUS_LOGOUT => 'bg-slate-300 dark:bg-slate-600',
        $status === \App\Models\TsaShift::STATUS_LOCKED => 'bg-red-500',
        default => 'bg-amber-500',
    };
    $statusBadge = fn ($status) => match (true) {
        $status === \App\Models\TsaShift::STATUS_LOGIN  => 'bg-emerald-100 dark:bg-emerald-900/40 text-emerald-700 dark:text-emerald-400',
        $status === \App\Models\TsaShift::STATUS_LOGOUT => 'bg-slate-100 dark:bg-slate-700 text-slate-600 dark:text-slate-300',
        $status === \App\Models\TsaShift::STATUS_LOCKED => 'bg-red-100 dark:bg-red-900/40 text-red-700 dark:text-red-400',
        default => 'bg-amber-100 dark:bg-amber-900/40 text-amber-700 dark:text-amber-400',
    };
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
                        <span class="inline-flex items-center gap-1.5 text-[11px] font-bold uppercase tracking-wide rounded-full px-2.5 py-1 {{ $statusBadge($tsa->status) }}">
                            <span class="w-1.5 h-1.5 rounded-full {{ $statusDot($tsa->status) }}"></span>
                            {{ $statuses[$tsa->status]['label'] ?? $tsa->status }}
                        </span>
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
                                  class="tsa-update-form bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-700 shadow-sm p-6">
                                @csrf

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
                                     the third ends it. Setup steps for all three (Macros 2–4) now
                                     live in the unified guide under "Phone call automation" below,
                                     alongside call logging's own Macro 1 — merged into one ordered
                                     tutorial (explicit request) instead of two separate accordions
                                     that used to split dial/mute/hangup from call logging. --}}
                                <div class="mb-4">
                                    <label class="block text-xs font-mono font-semibold text-slate-500 dark:text-slate-400 uppercase tracking-wide mb-1">Dialer address (auto-dial over Wi-Fi)</label>
                                    <input type="text" name="dialer_host" value="{{ $tsa->dialer_host }}" placeholder="192.168.1.42:8080"
                                           class="w-full sm:w-64 text-sm font-mono border border-slate-300 dark:border-slate-600 rounded-lg px-3 py-2 bg-white dark:bg-slate-800 text-slate-800 dark:text-slate-100 focus:outline-none focus:ring-2 focus:ring-yellow-500">
                                    <p class="text-[11px] text-slate-400 mt-1">{{ $tsa->display_name }}'s phone's own local IP:port, from Macros 2–4 in the setup guide below (Phone call automation card). Leave blank to skip auto-dial — clicking their leads' phone numbers still shows the number, it just won't dial by itself.</p>
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

                            {{-- Account link — explicit request, 2026-08-26: confirmed live
                                 that every TSA already has a real account in User Management
                                 (added the normal way, role 'normal', real Gmail addresses
                                 already in daily use) — this connects that EXISTING account to
                                 this TSA's row rather than creating a new one (a "give login"
                                 flow was tried and reverted the same day for exactly this
                                 reason: real accounts already existed, a second flow would
                                 only make duplicates). A plain <select>, not a search combobox
                                 — $linkableUsers is normally small (unlinked 'normal'-role
                                 accounts only), and showing every candidate's email inline
                                 matters here: User Management confirmed two real accounts both
                                 named "Julie Francisco" with different emails, so the admin
                                 needs to see both to pick the right one, not just a name. --}}
                            <div class="bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-700 shadow-sm p-6">
                                <h3 class="text-xs font-mono font-semibold text-slate-500 dark:text-slate-400 uppercase tracking-wide mb-3">Account link</h3>
                                @if($tsa->user)
                                <div class="flex items-center justify-between gap-4">
                                    <div>
                                        <p class="text-sm text-slate-700 dark:text-slate-200">{{ $tsa->user->name }}</p>
                                        <p class="text-[11px] text-slate-400 mt-0.5">{{ $tsa->user->email }} — signs in as {{ $tsa->display_name }}, sees only their own leads.</p>
                                    </div>
                                    <form method="POST" action="{{ route('calls.tsa-management.unlink-user', $tsa) }}"
                                          onsubmit="return confirm('This only removes the link — {{ $tsa->user->name }}\'s account itself (role, login) is untouched. Continue?');">
                                        @csrf
                                        <button type="submit" class="text-xs font-semibold text-red-600 dark:text-red-400 hover:text-red-700 border border-red-200 dark:border-red-900 hover:border-red-400 rounded-lg px-3 py-1.5 cursor-pointer whitespace-nowrap">
                                            Unlink
                                        </button>
                                    </form>
                                </div>
                                @elseif($linkableUsers->isEmpty())
                                <p class="text-xs font-mono text-slate-400">No unlinked accounts to connect — add {{ $tsa->display_name }} in User Management first (role: Normal User).</p>
                                @else
                                <form method="POST" action="{{ route('calls.tsa-management.link-user', $tsa) }}" class="flex items-center gap-2">
                                    @csrf
                                    <select name="user_id" required
                                            class="flex-1 text-sm font-mono border border-slate-300 dark:border-slate-600 rounded-lg px-3 py-2 bg-white dark:bg-slate-800 text-slate-800 dark:text-slate-100 focus:outline-none focus:ring-2 focus:ring-yellow-500">
                                        <option value="" disabled selected>Select {{ $tsa->display_name }}'s account…</option>
                                        @foreach($linkableUsers as $candidate)
                                        <option value="{{ $candidate->id }}">{{ $candidate->name }} ({{ $candidate->email }})</option>
                                        @endforeach
                                    </select>
                                    <button type="submit" class="text-xs font-semibold text-white bg-primary hover:bg-primary-dark rounded-lg px-3 py-2 cursor-pointer whitespace-nowrap">
                                        Link
                                    </button>
                                </form>
                                @endif
                            </div>

                            @include('calls.tsa-management._token-card', ['tsa' => $tsa])

                            {{-- Call recording auto-upload — replaced 2026-08-19 (explicit
                                 request) with a phone-side Drive sync, no PC/OBS/api_token
                                 involved at all: {{ $tsa->display_name }}'s own phone already
                                 records calls locally (built-in recorder), this just mirrors
                                 that folder into the exact Drive location
                                 SyncCallRecordings already reads from (see that command's own
                                 doc comment) to feed real AHT/OPT data on their TSA
                                 Performance page. Shown regardless of api_token — this no
                                 longer depends on it. --}}
                            <div class="bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-700 shadow-sm p-6">
                                <h3 class="text-xs font-mono font-semibold text-slate-500 dark:text-slate-400 uppercase tracking-wide mb-3">Call recording auto-upload (Google Drive)</h3>
                                <details class="text-xs font-mono text-slate-500 dark:text-slate-400">
                                    <summary class="cursor-pointer text-primary-dark hover:underline">Setup steps ↓</summary>
                                    <ol class="list-decimal list-inside mt-2 space-y-3 text-slate-600 dark:text-slate-300">
                                        <li>
                                            <strong>Find the phone's own recordings folder.</strong> Most Android phones auto-record calls locally with no setup — check Settings → Call app, or look for a "Call recordings" folder in the Files app. The exact path depends on the phone's brand (e.g. Samsung: <code>Call/</code>, Xiaomi/MIUI: <code>MIUI/sound_recorder/call_rec/</code>). If nothing's there, the phone's built-in dialer likely needs call recording turned on first.
                                        </li>
                                        <li>
                                            <strong>Create {{ $tsa->display_name }}'s Drive folder</strong> (once, if it doesn't already exist): in the shared <code>TSD 2026 RECORDING</code> folder → <code>TEAM {{ strtoupper($tsa->team) }}</code> → this month's folder (create it if it doesn't exist yet, named the full month, e.g. <code>{{ now('Asia/Manila')->format('F') }}</code>) → a new folder named exactly <code>{{ $tsa->tsa_key }}</code>. Any day-subfolders {{ $tsa->display_name }} makes for themselves inside that (however they like to name them) are fine — the sync only reads the recording filenames, never the subfolder names.
                                        </li>
                                        <li>
                                            <strong>Install "Autosync for Google Drive"</strong> (by MetaCtrl) from the Play Store on {{ $tsa->display_name }}'s phone — free, no PC needed.
                                        </li>
                                        <li>
                                            <strong>Sign in</strong> with whichever Google account has access to the shared <code>TSD 2026 RECORDING</code> folder, then create one sync pair:
                                            <span class="block mt-1 bg-slate-50 dark:bg-slate-800 border border-slate-200 dark:border-slate-700 rounded-lg px-2 py-1.5">Local: (the recordings folder from step 1) &nbsp;→&nbsp; Drive: TSD 2026 RECORDING / TEAM {{ strtoupper($tsa->team) }} / {{ now('Asia/Manila')->format('F') }} / {{ $tsa->tsa_key }}</span>
                                            Next month, update the sync pair's Drive side to point at that new month's folder instead (create it the same way as step 1).
                                        </li>
                                        <li>
                                            <strong>Set the sync direction to upload-only</strong> (phone → Drive, not the reverse) and pick a frequency — every 15–30 minutes is plenty; this doesn't need to be instant.
                                        </li>
                                        <li>
                                            <strong>Test it for real.</strong> Make one actual call, hang up, and either wait for the next scheduled sync or trigger one manually from the app. Check the <code>{{ $tsa->tsa_key }}</code> folder in Drive for the new file.
                                        </li>
                                    </ol>
                                </details>
                            </div>
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
