{{-- Reachable from both TSD Reports' own Config section (route 'settings')
     and Call Tracker's own sidebar (route 'calls.settings') — one shared
     form/data either way (see SettingsController), rendered inside
     whichever area's layout the visitor actually came from. --}}
@extends($layout ?? 'layouts.app')
@section('title', 'Settings')
@section('subtitle', 'Connect your Pancake POS account')

@section('content')
{{-- Explicit redesign, 2026-09-02 ("use tailwind css components", matching a
     reference screenshot): tab navigation across the top switches between
     setting groups (client-side show/hide below, no page reload), each
     section is a plain divided row — no bordered/shadowed "card" boxes —
     with its own title+description on the left and its fields on the
     right, replacing the old stacked-cards layout. Every element id, form
     action, and field name below is UNCHANGED from before so the existing
     @push('scripts') JS (Detect Shop, Drive sync polling, password-toggle,
     etc.) keeps working with zero changes. --}}
<div class="max-w-5xl">

    <div class="border-b border-slate-200 dark:border-slate-700 mb-8">
        <nav class="flex gap-6 overflow-x-auto" role="tablist" aria-label="Settings sections">
            @foreach([
                'connection'  => 'Pancake Connection',
                'team-names'  => 'Team Names',
                'google-drive'=> 'Google Drive',
                'access-token'=> 'Access Token',
            ] as $tabKey => $tabLabel)
            <button type="button" role="tab" data-settings-tab="{{ $tabKey }}"
                    class="settings-tab-btn shrink-0 px-1 pb-3 text-sm font-semibold border-b-2 border-transparent text-slate-400 dark:text-slate-500 hover:text-slate-600 dark:hover:text-slate-300 transition-colors cursor-pointer whitespace-nowrap">
                {{ $tabLabel }}
            </button>
            @endforeach
        </nav>
    </div>

    {{-- ================= Pancake Connection ================= --}}
    <div data-settings-panel="connection" class="space-y-10">

        <div class="grid grid-cols-1 lg:grid-cols-3 gap-6 lg:gap-10 pb-8 border-b border-slate-100 dark:border-slate-800">
            <div>
                <h3 class="text-sm font-semibold text-slate-800 dark:text-slate-100">Paste your Pancake POS API Key</h3>
                <p class="text-xs text-slate-500 dark:text-slate-400 mt-1">Settings → App Settings → API Key → Create</p>
            </div>
            <div class="lg:col-span-2">
                <div class="flex gap-3">
                    <div class="relative flex-1">
                        {{-- Masked (last 4 chars only, see SettingsController::mask()) — never
                             the real saved key. Clearing this and clicking "Detect Shop" is how
                             an admin actually changes it; leaving it as-is and just hitting
                             "Save Settings" keeps the existing key untouched. --}}
                        <input type="password" id="apiKeyInput"
                            placeholder="Paste your API key here..."
                            value="{{ $apiKeyMasked }}"
                            class="w-full rounded-lg border border-slate-200 dark:border-slate-700 bg-white dark:bg-slate-800 px-3.5 py-2.5 pr-10 text-sm font-mono text-slate-900 dark:text-slate-100 placeholder:text-slate-400 focus:outline-none focus:ring-2 focus:ring-yellow-500 focus:border-transparent">
                        <button type="button" id="toggleApiKeyBtn"
                            style="position:absolute;right:10px;top:50%;transform:translateY(-50%);background:none;border:none;cursor:pointer;color:#94a3b8;padding:2px;">
                            <svg id="eyeIcon" class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/>
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"/>
                            </svg>
                            <svg id="eyeSlashIcon" class="w-4 h-4 hidden" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13.875 18.825A10.05 10.05 0 0112 19c-4.478 0-8.268-2.943-9.543-7a9.97 9.97 0 011.563-3.029m5.858.908a3 3 0 114.243 4.243M9.878 9.878l4.242 4.242M9.88 9.88l-3.29-3.29m7.532 7.532l3.29 3.29M3 3l3.59 3.59m0 0A9.953 9.953 0 0112 5c4.478 0 8.268 2.943 9.543 7a10.025 10.025 0 01-4.132 5.411m0 0L21 21"/>
                            </svg>
                        </button>
                    </div>
                    <button type="button" id="detectBtn"
                        class="inline-flex items-center gap-2 px-4 py-2.5 bg-yellow-700 hover:bg-yellow-800 text-white text-sm font-semibold rounded-lg transition-colors cursor-pointer whitespace-nowrap">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/>
                        </svg>
                        Detect Shop
                    </button>
                </div>
                <div id="detectStatus" class="mt-3 text-xs hidden"></div>

                <div class="mt-4 rounded-xl bg-yellow-50 dark:bg-yellow-950/40 border border-yellow-100 dark:border-yellow-900 p-4">
                    <h4 class="text-xs font-semibold text-yellow-800 dark:text-yellow-400 uppercase tracking-wider mb-3">How to get your Pancake POS API Key</h4>
                    <ol class="space-y-2 text-xs text-yellow-700 dark:text-yellow-400">
                        <li class="flex gap-2.5">
                            <span class="w-5 h-5 rounded-full bg-yellow-200 dark:bg-yellow-900/60 text-yellow-800 dark:text-yellow-200 font-bold flex-shrink-0 flex items-center justify-center">1</span>
                            Log in to <strong>pos.pages.fm</strong> and open your shop
                        </li>
                        <li class="flex gap-2.5">
                            <span class="w-5 h-5 rounded-full bg-yellow-200 dark:bg-yellow-900/60 text-yellow-800 dark:text-yellow-200 font-bold flex-shrink-0 flex items-center justify-center">2</span>
                            Go to <strong>Settings → App Settings → API Key</strong>
                        </li>
                        <li class="flex gap-2.5">
                            <span class="w-5 h-5 rounded-full bg-yellow-200 dark:bg-yellow-900/60 text-yellow-800 dark:text-yellow-200 font-bold flex-shrink-0 flex items-center justify-center">3</span>
                            Click <strong>Create</strong> to generate your key, then copy it
                        </li>
                        <li class="flex gap-2.5">
                            <span class="w-5 h-5 rounded-full bg-yellow-200 dark:bg-yellow-900/60 text-yellow-800 dark:text-yellow-200 font-bold flex-shrink-0 flex items-center justify-center">4</span>
                            Paste it above — your shop info will be <strong>auto-detected</strong>
                        </li>
                    </ol>
                </div>
            </div>
        </div>

        {{-- Step 2: Shop confirmation (shown after detect or when already connected) --}}
        <div id="step2-card" class="grid grid-cols-1 lg:grid-cols-3 gap-6 lg:gap-10 pb-8 border-b border-slate-100 dark:border-slate-800 {{ $shopId ? '' : 'hidden' }}">
            <div>
                <h3 class="text-sm font-semibold text-slate-800 dark:text-slate-100">Confirm your shop</h3>
                <p class="text-xs text-slate-500 dark:text-slate-400 mt-1">All information is auto-detected from your API key</p>
            </div>
            <div class="lg:col-span-2">
                {{-- Shop info display (read-only, NOT inside the save form) --}}
                <div id="shopInfoDisplay">
                    @if($shopId)
                    <div class="flex items-start gap-4 p-4 bg-green-50 dark:bg-green-950/40 border border-green-200 dark:border-green-800 rounded-xl">
                        <div class="w-12 h-12 rounded-xl bg-yellow-100 dark:bg-yellow-900/40 flex items-center justify-center flex-shrink-0 text-yellow-700 dark:text-yellow-400 font-bold text-lg">
                            {{ strtoupper(substr($shopName ?: 'S', 0, 1)) }}
                        </div>
                        <div class="flex-1 min-w-0">
                            <div class="flex items-center gap-2">
                                <p class="text-sm font-bold text-slate-900 dark:text-slate-100">{{ $shopName }}</p>
                                <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-xs font-medium bg-green-100 dark:bg-green-900/40 text-green-700 dark:text-green-400">
                                    <span class="w-1.5 h-1.5 rounded-full bg-green-500 inline-block"></span>
                                    Connected
                                </span>
                            </div>
                            <p class="text-xs text-slate-500 dark:text-slate-400 mt-0.5 font-mono">Shop ID: {{ $shopId }}</p>
                            @if($lastSynced)
                            <p class="text-xs text-slate-400 mt-0.5">Last synced: {{ \Carbon\Carbon::parse($lastSynced)->diffForHumans() }}</p>
                            @else
                            <p class="text-xs text-slate-400 mt-0.5">Last synced: Never</p>
                            @endif
                        </div>
                        {{-- Disconnect is its OWN standalone form — never nested --}}
                        <form method="POST" action="{{ route('settings.clear') }}">
                            @csrf
                            <input type="hidden" name="_redirect_route" value="{{ request()->routeIs('calls.*') ? 'calls.settings' : 'settings' }}">
                            <button type="submit"
                                    class="px-3 py-1.5 text-xs font-semibold font-mono text-red-500 dark:text-red-400 border border-red-200 dark:border-red-800 rounded-lg hover:bg-red-50 dark:hover:bg-red-950/40 transition-colors cursor-pointer">
                                Disconnect
                            </button>
                        </form>
                    </div>
                    @else
                    <div class="text-center py-6 text-slate-400 text-sm">
                        Paste your API key above and click "Detect Shop"
                    </div>
                    @endif
                </div>

                {{-- Save form — only contains sync interval + hidden fields, no nested forms --}}
                <form method="POST" action="{{ route('settings.save') }}" id="connectForm">
                    @csrf
                    <input type="hidden" name="_redirect_route" value="{{ request()->routeIs('calls.*') ? 'calls.settings' : 'settings' }}">
                    {{-- api_key starts EMPTY, not the real saved key — only "Detect Shop"
                         succeeding (JS below) fills it in, when the admin is actually
                         changing it. SettingsController::save() treats an empty submission
                         as "key unchanged", not "clear the key". shop_id/shop_name aren't
                         secrets, so those still just reflect the real current values. --}}
                    <input type="hidden" name="api_key"   id="formApiKey"   value="">
                    <input type="hidden" name="shop_id"   id="formShopId"   value="{{ $shopId }}">
                    <input type="hidden" name="shop_name" id="formShopName" value="{{ $shopName }}">

                    @if($errors->any())
                    <div class="mt-4 px-4 py-3 bg-red-50 dark:bg-red-950/40 border border-red-200 dark:border-red-800 rounded-lg text-sm text-red-700 dark:text-red-400">
                        @foreach($errors->all() as $error)<div>{{ $error }}</div>@endforeach
                    </div>
                    @endif

                    <div class="flex flex-wrap items-end gap-3 mt-4">
                        <div class="flex-1 min-w-[160px]">
                            <label class="block text-xs font-semibold text-slate-700 dark:text-slate-200 mb-1">Sync interval (minutes)</label>
                            <select name="sync_interval" class="block w-full rounded-lg border border-slate-200 dark:border-slate-700 bg-white dark:bg-slate-800 px-3 py-2 text-sm text-slate-900 dark:text-slate-100 focus:outline-none focus:ring-2 focus:ring-yellow-500">
                                <option value="1"  {{ $syncInterval == 1  ? 'selected' : '' }}>Every minute</option>
                                <option value="5"  {{ $syncInterval == 5  ? 'selected' : '' }}>Every 5 minutes</option>
                                <option value="15" {{ $syncInterval == 15 ? 'selected' : '' }}>Every 15 minutes</option>
                                <option value="30" {{ $syncInterval == 30 ? 'selected' : '' }}>Every 30 minutes</option>
                                <option value="60" {{ $syncInterval == 60 ? 'selected' : '' }}>Every hour</option>
                            </select>
                        </div>
                        {{-- Ported from call-tracker (merged into one app 2026-08-12)
                             — how long an assigned-but-uncalled lead sits before
                             Call Tracker's Overdue view surfaces it. --}}
                        <div class="flex-1 min-w-[160px]">
                            <label class="block text-xs font-semibold text-slate-700 dark:text-slate-200 mb-1">Overdue threshold (hours)</label>
                            <input type="number" name="overdue_threshold_hours" min="1" max="72"
                                value="{{ old('overdue_threshold_hours', $overdueThresholdHours) }}"
                                class="block w-full rounded-lg border border-slate-200 dark:border-slate-700 bg-white dark:bg-slate-800 px-3 py-2 text-sm text-slate-900 dark:text-slate-100 focus:outline-none focus:ring-2 focus:ring-yellow-500">
                        </div>
                        <button type="submit" id="connectBtn"
                            class="inline-flex items-center gap-2 px-5 py-2.5 bg-yellow-700 hover:bg-yellow-800 text-white text-sm font-semibold rounded-lg transition-colors cursor-pointer">
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/>
                            </svg>
                            {{ $shopId ? 'Save Settings' : 'Connect Shop' }}
                        </button>
                    </div>
                </form>
            </div>
        </div>

    </div>

    {{-- ================= Team Names ================= --}}
    <div data-settings-panel="team-names" class="hidden">
        {{-- Team Names — explicit request, 2026-09-02: "is it possible that the
             team sh naturals and eyecare is editable." Only the DISPLAY name is
             editable here, not which orders/products belong to a team — see
             Teams::config()'s own doc comment for why the underlying slug/
             order_team stay fixed. Renaming a team here changes its label
             everywhere it's shown (Leads Report, TSA Performance, Dashboard,
             Insights, etc.) without touching any of the data those pages read. --}}
        <div class="grid grid-cols-1 lg:grid-cols-3 gap-6 lg:gap-10 pb-8 border-b border-slate-100 dark:border-slate-800">
            <div>
                <h3 class="text-sm font-semibold text-slate-800 dark:text-slate-100">Team Names</h3>
                <p class="text-xs text-slate-500 dark:text-slate-400 mt-1">Renames how each team is labeled throughout the app</p>
            </div>
            <div class="lg:col-span-2">
                <form method="POST" action="{{ route('settings.team-names') }}">
                    @csrf
                    <input type="hidden" name="_redirect_route" value="{{ request()->routeIs('calls.*') ? 'calls.settings' : 'settings' }}">
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                        @foreach($teamsConfig as $slug => $team)
                        <div>
                            {{-- Positional label ("Team 1"), NOT the current/original name —
                                 labeling this field with the very name you're renaming (e.g.
                                 "SH Naturals") stays confusingly unchanged even after you've
                                 typed a new value into the box right below it. --}}
                            <label class="block text-xs font-semibold text-slate-700 dark:text-slate-200 mb-1">Team {{ $loop->iteration }}</label>
                            <input type="text" name="team_names[{{ $slug }}]" value="{{ old('team_names.' . $slug, $team['name']) }}"
                                class="w-full rounded-lg border border-slate-200 dark:border-slate-700 bg-white dark:bg-slate-800 px-3.5 py-2.5 text-sm font-mono text-slate-900 dark:text-slate-100 focus:outline-none focus:ring-2 focus:ring-slate-500 focus:border-transparent">
                        </div>
                        @endforeach
                    </div>
                    <div class="mt-4">
                        <button type="submit"
                            class="inline-flex items-center gap-2 px-4 py-2 bg-slate-700 hover:bg-slate-800 text-white text-sm font-semibold rounded-lg transition-colors cursor-pointer">
                            Save Team Names
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    {{-- ================= Google Drive ================= --}}
    <div data-settings-panel="google-drive" class="hidden">
        {{-- Google Drive Call Recordings — feeds real OPT/AHT data on TSA Performance's
             individual TSA page (SyncCallRecordings). One-time OAuth setup happens
             outside this app (Google Cloud Console); this form just stores the
             resulting credentials so the scheduled sync can use them. --}}
        <div class="grid grid-cols-1 lg:grid-cols-3 gap-6 lg:gap-10 pb-8 border-b border-slate-100 dark:border-slate-800">
            <div>
                <div class="flex items-center gap-2">
                    <h3 class="text-sm font-semibold text-slate-800 dark:text-slate-100">Google Drive Call Recordings</h3>
                    @if($driveConnected)
                    <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-xs font-medium bg-green-100 dark:bg-green-900/40 text-green-700 dark:text-green-400 whitespace-nowrap">
                        <span class="w-1.5 h-1.5 rounded-full bg-green-500 inline-block"></span>
                        Connected
                    </span>
                    @endif
                </div>
                <p class="text-xs text-slate-500 dark:text-slate-400 mt-1">Feeds real OPT/AHT data on TSA Performance</p>

                @if($driveConnected)
                {{-- Standalone forms — never nested inside the save form below.
                     Date input (not just "sync today"): recordings only get
                     picked up by whichever sync run happens to execute while
                     they already exist in Drive — one uploaded after the last
                     run of a given day was previously stuck that way forever,
                     with no way to go back and catch it. Defaults to today so
                     the common case (re-check right now) still needs no typing.

                     Submitted via JS (initDriveSyncNow() below), not a plain
                     POST, so a real loading state can show (explicit request:
                     "i want to add loading when sync in the gdrive") — the
                     actual sync still runs as a detached background process
                     server-side either way (syncDriveNow()), this just keeps
                     the admin on the page and polls driveSyncStatus() instead
                     of redirecting immediately into an ambiguous "did it
                     start?" full-page reload. --}}
                <div class="mt-4 flex items-center gap-1.5 flex-wrap">
                    <form id="driveSyncNowForm" method="POST" action="{{ route('settings.drive.sync-now') }}" class="flex items-center gap-1.5">
                        @csrf
                        <input type="hidden" name="_redirect_route" value="{{ request()->routeIs('calls.*') ? 'calls.settings' : 'settings' }}">
                        <input type="date" name="date" id="driveSyncDate" value="{{ now('Asia/Manila')->toDateString() }}"
                               max="{{ now('Asia/Manila')->toDateString() }}"
                               aria-label="Date to sync"
                               class="px-2 py-1.5 text-xs font-mono rounded-lg border border-emerald-200 dark:border-emerald-800 bg-white dark:bg-slate-800 text-slate-700 dark:text-slate-200 focus:outline-none focus:ring-2 focus:ring-emerald-500">
                        <button type="submit" id="driveSyncNowBtn" data-initially-running="{{ $driveSyncRunning ? '1' : '0' }}"
                                @if($driveSyncRunning) disabled @endif
                                class="inline-flex items-center gap-1.5 px-3 py-1.5 text-xs font-semibold font-mono text-emerald-700 dark:text-emerald-400 border border-emerald-200 dark:border-emerald-800 rounded-lg hover:bg-emerald-50 dark:hover:bg-emerald-950/40 transition-colors cursor-pointer whitespace-nowrap disabled:opacity-60 disabled:cursor-not-allowed">
                            <svg id="driveSyncNowSpinner" class="{{ $driveSyncRunning ? '' : 'hidden' }} w-3.5 h-3.5 animate-spin" fill="none" viewBox="0 0 24 24">
                                <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                                <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path>
                            </svg>
                            <span id="driveSyncNowLabel">{{ $driveSyncRunning ? 'Syncing…' : 'Sync Now' }}</span>
                        </button>
                    </form>
                    <form method="POST" action="{{ route('settings.drive.clear') }}" onsubmit="return confirm('Disconnect Google Drive? Real OPT/AHT data will stop syncing until reconnected.');">
                        @csrf
                        <input type="hidden" name="_redirect_route" value="{{ request()->routeIs('calls.*') ? 'calls.settings' : 'settings' }}">
                        <button type="submit" class="px-3 py-1.5 text-xs font-semibold font-mono text-red-500 dark:text-red-400 border border-red-200 dark:border-red-800 rounded-lg hover:bg-red-50 dark:hover:bg-red-950/40 transition-colors cursor-pointer">
                            Disconnect
                        </button>
                    </form>
                </div>
                @endif
                <div id="driveSyncStatusLine">
                    @if($driveSyncRunning)
                    <div class="mt-3 flex items-center gap-2 text-xs">
                        <svg class="w-3.5 h-3.5 text-emerald-500 animate-spin" fill="none" viewBox="0 0 24 24">
                            <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                            <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path>
                        </svg>
                        <span class="text-emerald-600 dark:text-emerald-400 font-semibold">Sync in progress…</span>
                    </div>
                    @elseif($driveSyncLastRun)
                    <div class="mt-3 flex items-center gap-2 text-xs">
                        @if($driveSyncLastStatus === 'success')
                            <span class="inline-flex items-center gap-1 text-green-600 dark:text-green-400">
                                <span class="w-1.5 h-1.5 rounded-full bg-green-500 inline-block"></span> Last sync succeeded
                            </span>
                        @elseif($driveSyncLastStatus === 'failed')
                            <span class="inline-flex items-center gap-1 text-red-600 dark:text-red-400">
                                <span class="w-1.5 h-1.5 rounded-full bg-red-500 inline-block"></span> Last sync failed
                            </span>
                        @elseif($driveSyncLastStatus === 'error')
                            <span class="inline-flex items-center gap-1 text-red-600 dark:text-red-400">
                                <span class="w-1.5 h-1.5 rounded-full bg-red-500 inline-block"></span> Last sync errored
                            </span>
                        @endif
                        <span class="text-slate-400">— {{ \Carbon\Carbon::parse($driveSyncLastRun)->diffForHumans() }}</span>
                    </div>
                    @if($driveSyncLastMessage)
                    <p class="mt-1 text-xs text-slate-500 dark:text-slate-400 font-mono">{{ $driveSyncLastMessage }}</p>
                    @endif
                    @else
                    <p class="mt-3 text-xs text-slate-400">Never run yet — runs automatically every 2 hours once connected.</p>
                    @endif
                </div>
            </div>

            <div class="lg:col-span-2">
                <form method="POST" action="{{ route('settings.drive.save') }}">
                    @csrf
                    <input type="hidden" name="_redirect_route" value="{{ request()->routeIs('calls.*') ? 'calls.settings' : 'settings' }}">
                    @if($errors->has('drive_refresh_token'))
                    <div class="mb-4 px-4 py-3 bg-red-50 dark:bg-red-950/40 border border-red-200 dark:border-red-800 rounded-lg text-sm text-red-700 dark:text-red-400">
                        {{ $errors->first('drive_refresh_token') }}
                    </div>
                    @endif

                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                        <div class="sm:col-span-2">
                            <label class="block text-xs font-semibold text-slate-700 dark:text-slate-200 mb-1">Client ID</label>
                            <input type="text" name="drive_client_id" value="{{ old('drive_client_id', $driveClientId) }}"
                                class="w-full rounded-lg border border-slate-200 dark:border-slate-700 bg-white dark:bg-slate-800 px-3.5 py-2.5 text-sm font-mono text-slate-900 dark:text-slate-100 focus:outline-none focus:ring-2 focus:ring-emerald-500 focus:border-transparent">
                        </div>
                        <div>
                            <label class="block text-xs font-semibold text-slate-700 dark:text-slate-200 mb-1">Client Secret</label>
                            {{-- Deliberately starts BLANK (not the masked string as a value) —
                                 a masked placeholder submitted back as-is would look like a
                                 genuinely new value to the server (see saveDrive()'s "blank
                                 means unchanged" check), overwriting the real secret with
                                 literal dots. The masked hint lives in the placeholder
                                 attribute instead, purely informational, never submitted.
                                 old() still redisplays what the admin actually typed if a
                                 save attempt just failed. --}}
                            <input type="password" name="drive_client_secret" value="{{ old('drive_client_secret') }}"
                                placeholder="{{ $driveClientSecretMasked ? 'Saved: ' . $driveClientSecretMasked . ' — leave blank to keep it' : 'Paste your Client Secret' }}"
                                class="w-full rounded-lg border border-slate-200 dark:border-slate-700 bg-white dark:bg-slate-800 px-3.5 py-2.5 text-sm font-mono text-slate-900 dark:text-slate-100 placeholder:text-slate-400 focus:outline-none focus:ring-2 focus:ring-emerald-500 focus:border-transparent">
                        </div>
                        <div>
                            <label class="block text-xs font-semibold text-slate-700 dark:text-slate-200 mb-1">Refresh Token</label>
                            <input type="password" name="drive_refresh_token" value="{{ old('drive_refresh_token') }}"
                                placeholder="{{ $driveRefreshTokenMasked ? 'Saved: ' . $driveRefreshTokenMasked . ' — leave blank to keep it' : 'Paste your Refresh Token' }}"
                                class="w-full rounded-lg border border-slate-200 dark:border-slate-700 bg-white dark:bg-slate-800 px-3.5 py-2.5 text-sm font-mono text-slate-900 dark:text-slate-100 placeholder:text-slate-400 focus:outline-none focus:ring-2 focus:ring-emerald-500 focus:border-transparent">
                        </div>
                        {{-- Folder tree confirmed live 2026-08-25 (see
                             GoogleDriveClient::resolveTsaFolder()'s own doc comment
                             for the full history) — each ID below is the TEAM-level
                             folder id, i.e. whatever's at TSD 2026 RECORDING > TEAM
                             SH NATURALS (or > TEAM EYECARE) — copy it from that
                             folder's URL in Drive, the long id after /folders/.
                             Inside each, SyncCallRecordings expects a folder per
                             month (e.g. "AUGUST"), then one subfolder per TSA
                             (named either their tsa_key or full display name — see
                             TSA Management for which); day-subfolders under a TSA
                             can be named however that TSA happens to name them —
                             never matched by name, only walked. --}}
                        <div>
                            <label class="block text-xs font-semibold text-slate-700 dark:text-slate-200 mb-1">SH Naturals Folder ID</label>
                            <input type="text" name="drive_folder_sh_naturals" value="{{ old('drive_folder_sh_naturals', $driveFolderShNaturals) }}"
                                placeholder="TSD 2026 RECORDING &gt; TEAM SH NATURALS folder id"
                                class="w-full rounded-lg border border-slate-200 dark:border-slate-700 bg-white dark:bg-slate-800 px-3.5 py-2.5 text-sm font-mono text-slate-900 dark:text-slate-100 placeholder:text-slate-400 focus:outline-none focus:ring-2 focus:ring-emerald-500 focus:border-transparent">
                        </div>
                        <div>
                            <label class="block text-xs font-semibold text-slate-700 dark:text-slate-200 mb-1">Eyecare Folder ID</label>
                            <input type="text" name="drive_folder_eyecare" value="{{ old('drive_folder_eyecare', $driveFolderEyecare) }}"
                                placeholder="TSD 2026 RECORDING &gt; TEAM EYECARE folder id"
                                class="w-full rounded-lg border border-slate-200 dark:border-slate-700 bg-white dark:bg-slate-800 px-3.5 py-2.5 text-sm font-mono text-slate-900 dark:text-slate-100 placeholder:text-slate-400 focus:outline-none focus:ring-2 focus:ring-emerald-500 focus:border-transparent">
                        </div>
                    </div>

                    <div class="flex items-center justify-end mt-4">
                        <button type="submit" class="inline-flex items-center gap-2 px-5 py-2.5 bg-emerald-700 hover:bg-emerald-800 text-white text-sm font-semibold rounded-lg transition-colors cursor-pointer">
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/>
                            </svg>
                            Save &amp; Verify
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    {{-- ================= Pancake Access Token ================= --}}
    <div data-settings-panel="access-token" class="hidden">
        {{-- Pancake Access Token — ported from call-tracker (merged into one app
             2026-08-12). A personal Pancake login session JWT, distinct from the
             Pancake API key above (which is a stable integration key) — feeds
             Call Tracker's conversation-message viewer, which the public orders
             API has no equivalent for. Expires (~90 days); this shows when. --}}
        <div class="grid grid-cols-1 lg:grid-cols-3 gap-6 lg:gap-10 pb-8 border-b border-slate-100 dark:border-slate-800">
            <div>
                <h3 class="text-sm font-semibold text-slate-800 dark:text-slate-100">Pancake Access Token</h3>
                <p class="text-xs text-slate-500 dark:text-slate-400 mt-1">Feeds Call Tracker's conversation viewer</p>
                @if($accessTokenExpiresAt)
                <p class="mt-2 text-xs {{ $accessTokenExpiresAt->isPast() ? 'text-red-600 dark:text-red-400' : 'text-slate-500 dark:text-slate-400' }}">
                    {{ $accessTokenExpiresAt->isPast() ? 'Expired' : 'Expires' }} {{ $accessTokenExpiresAt->diffForHumans() }}
                </p>
                @endif
                @if($accessTokenMasked)
                <form method="POST" action="{{ route('settings.access-token.clear') }}" onsubmit="return confirm('Clear the Pancake access token? Conversation history will stop loading in Call Tracker until a new one is saved.');" class="mt-3">
                    @csrf
                    <input type="hidden" name="_redirect_route" value="{{ request()->routeIs('calls.*') ? 'calls.settings' : 'settings' }}">
                    <button type="submit" class="px-3 py-1.5 text-xs font-semibold font-mono text-red-500 dark:text-red-400 border border-red-200 dark:border-red-800 rounded-lg hover:bg-red-50 dark:hover:bg-red-950/40 transition-colors cursor-pointer">
                        Clear
                    </button>
                </form>
                @endif
            </div>
            <div class="lg:col-span-2">
                <form method="POST" action="{{ route('settings.access-token.save') }}">
                    @csrf
                    <input type="hidden" name="_redirect_route" value="{{ request()->routeIs('calls.*') ? 'calls.settings' : 'settings' }}">
                    @if($errors->has('pancake_access_token'))
                    <div class="mb-4 px-4 py-3 bg-red-50 dark:bg-red-950/40 border border-red-200 dark:border-red-800 rounded-lg text-sm text-red-700 dark:text-red-400">
                        {{ $errors->first('pancake_access_token') }}
                    </div>
                    @endif
                    <div class="flex items-end gap-3">
                        <div class="flex-1">
                            <label class="block text-xs font-semibold text-slate-700 dark:text-slate-200 mb-1">Session Token</label>
                            <input type="text" name="pancake_access_token" placeholder="{{ $accessTokenMasked ?: 'Paste your Pancake session token' }}"
                                class="w-full rounded-lg border border-slate-200 dark:border-slate-700 bg-white dark:bg-slate-800 px-3.5 py-2.5 text-sm font-mono text-slate-900 dark:text-slate-100 focus:outline-none focus:ring-2 focus:ring-sky-500 focus:border-transparent">
                        </div>
                        <button type="submit" class="inline-flex items-center gap-2 px-5 py-2.5 bg-sky-700 hover:bg-sky-800 text-white text-sm font-semibold rounded-lg transition-colors cursor-pointer">
                            Save
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

</div>
@endsection

@push('scripts')
<script>
{{-- Tab switching — plain show/hide, no page reload. Persisted in
     sessionStorage (per-tab, not per-user) so returning to this page after a
     same-tab form submit/redirect (every save action here redirects back to
     'settings') reopens whichever section the admin was actually working in,
     instead of always resetting to the first tab and losing their place
     right after they hit Save. --}}
(function () {
    const tabButtons = document.querySelectorAll('.settings-tab-btn');
    const panels      = document.querySelectorAll('[data-settings-panel]');
    const STORAGE_KEY = 'settingsActiveTab';

    function activate(tabKey) {
        panels.forEach(p => p.classList.toggle('hidden', p.dataset.settingsPanel !== tabKey));
        tabButtons.forEach(b => {
            const isActive = b.dataset.settingsTab === tabKey;
            b.classList.toggle('text-primary', isActive);
            b.classList.toggle('border-primary', isActive);
            b.classList.toggle('text-slate-400', !isActive);
            b.classList.toggle('dark:text-slate-500', !isActive);
            b.classList.toggle('border-transparent', !isActive);
        });
        try { sessionStorage.setItem(STORAGE_KEY, tabKey); } catch (e) {}
    }

    tabButtons.forEach(btn => btn.addEventListener('click', () => activate(btn.dataset.settingsTab)));

    let initial = 'connection';
    try {
        const stored = sessionStorage.getItem(STORAGE_KEY);
        if (stored && document.querySelector(`[data-settings-panel="${stored}"]`)) initial = stored;
    } catch (e) {}
    activate(initial);
})();

const detectBtn    = document.getElementById('detectBtn');
const apiInput     = document.getElementById('apiKeyInput');
const statusEl     = document.getElementById('detectStatus');
const step2Card    = document.getElementById('step2-card');
const shopDisplay  = document.getElementById('shopInfoDisplay');
const formApiKey   = document.getElementById('formApiKey');
const formShopId   = document.getElementById('formShopId');
const formShopName = document.getElementById('formShopName');
const connectBtn   = document.getElementById('connectBtn');
const toggleApiKeyBtn = document.getElementById('toggleApiKeyBtn');
const eyeIcon         = document.getElementById('eyeIcon');
const eyeSlashIcon    = document.getElementById('eyeSlashIcon');

toggleApiKeyBtn.addEventListener('click', () => {
    const isHidden = apiInput.type === 'password';
    apiInput.type = isHidden ? 'text' : 'password';
    eyeIcon.classList.toggle('hidden', isHidden);
    eyeSlashIcon.classList.toggle('hidden', !isHidden);
});

// The input's own value after Save (or on a fresh page load with an
// already-connected key) is this masked placeholder (see
// SettingsController::mask()), never the real secret — comparing against it
// lets "Detect Shop" recognize an untouched field and say so directly,
// instead of sending literal bullet characters to Pancake as an "api_key"
// and having it fail with the same generic message a truly wrong key would
// (confirmed report, 2026-08-14: "detect shop... then save... then detect
// shop again... invalid api key" — the field was never cleared/retyped in
// between, so this masked string is exactly what got sent).
const maskedPlaceholder = @json($apiKeyMasked);

detectBtn.addEventListener('click', async () => {
    const key = apiInput.value.trim();
    if (!key) { showStatus('error', 'Please paste your API key first.'); return; }
    if (maskedPlaceholder && key === maskedPlaceholder) {
        showStatus('info', 'That\'s your saved key\'s masked display, not a real key — clear the field and paste the actual key to test a different one.');
        return;
    }

    detectBtn.disabled = true;
    detectBtn.innerHTML = `<svg class="w-4 h-4 animate-spin" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v8z"/></svg> Detecting...`;
    showStatus('loading', 'Connecting to Pancake POS...');

    try {
        const csrfToken = document.querySelector('meta[name=csrf-token]').content;
        const res = await fetch('{{ route('settings.detect') }}', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': csrfToken,
                'Accept': 'application/json',
            },
            body: JSON.stringify({ api_key: key }),
        });

        const data = await res.json();

        if (data.success && data.shops?.length) {
            const shop = data.shops[0];
            formApiKey.value   = key;
            formShopId.value   = shop.id;
            formShopName.value = shop.name;
            connectBtn.textContent = `Connect "${shop.name}"`;

            shopDisplay.innerHTML = buildShopCard(shop);
            step2Card.classList.remove('hidden');
            showStatus('success', `Shop detected: ${shop.name} (ID: ${shop.id})`);
        } else {
            showStatus('error', data.message ?? 'No shops found for this API key.');
        }
    } catch (e) {
        showStatus('error', 'Detection failed: ' + e.message);
    } finally {
        detectBtn.disabled = false;
        detectBtn.innerHTML = `<svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/></svg> Detect Shop`;
    }
});

function buildShopCard(shop) {
    return `
    <div class="flex items-start gap-4 p-4 bg-green-50 dark:bg-green-950/40 border border-green-200 dark:border-green-800 rounded-xl">
        <div class="w-12 h-12 rounded-xl bg-yellow-100 dark:bg-yellow-900/40 flex items-center justify-center flex-shrink-0 text-yellow-700 dark:text-yellow-400 font-bold text-lg">
            ${shop.name.charAt(0).toUpperCase()}
        </div>
        <div>
            <div class="flex items-center gap-2">
                <p class="text-sm font-bold text-slate-900 dark:text-slate-100">${shop.name}</p>
                <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-xs font-medium bg-green-100 dark:bg-green-900/40 text-green-700 dark:text-green-400">
                    <span class="w-1.5 h-1.5 rounded-full bg-green-500 inline-block"></span>
                    Detected
                </span>
            </div>
            <p class="text-xs text-slate-500 dark:text-slate-400 mt-0.5 font-mono">Shop ID: ${shop.id}</p>
        </div>
    </div>`;
}

function showStatus(type, msg) {
    statusEl.classList.remove('hidden');
    const styles = { loading: 'text-yellow-600 dark:text-yellow-400', success: 'text-green-600 dark:text-green-400', error: 'text-red-600 dark:text-red-400' };
    statusEl.className = `mt-3 text-xs ${styles[type] ?? 'text-slate-600 dark:text-slate-400'}`;
    statusEl.textContent = msg;
}

// Google Drive "Sync Now" loading state (explicit request: "i want to add
// loading when sync in the gdrive") — the sync itself runs as a detached
// background process server-side either way (SettingsController::
// syncDriveNow()), so this is purely about giving the click real feedback
// instead of a full-page redirect that leaves it ambiguous whether
// anything actually started. Submits via fetch (Accept: application/json,
// see that same controller method's JSON branch) so the page never
// navigates away, then polls driveSyncStatus() until drive_sync_running
// flips back off.
(function () {
    const form      = document.getElementById('driveSyncNowForm');
    if (!form) return; // not connected yet — this card isn't rendered at all

    const dateInput = document.getElementById('driveSyncDate');
    const btn       = document.getElementById('driveSyncNowBtn');
    const spinner   = document.getElementById('driveSyncNowSpinner');
    const label     = document.getElementById('driveSyncNowLabel');
    const statusLine = document.getElementById('driveSyncStatusLine');
    const statusUrl  = '{{ route("settings.drive.sync-status") }}';
    let pollInterval = null;

    function setLoading(isLoading) {
        btn.disabled = isLoading;
        dateInput.disabled = isLoading;
        spinner.classList.toggle('hidden', !isLoading);
        label.textContent = isLoading ? 'Syncing…' : 'Sync Now';
    }

    function renderStatusLine(data) {
        if (data.running) {
            statusLine.innerHTML = `
                <div class="mt-3 flex items-center gap-2 text-xs">
                    <svg class="w-3.5 h-3.5 text-emerald-500 animate-spin" fill="none" viewBox="0 0 24 24">
                        <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                        <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path>
                    </svg>
                    <span class="text-emerald-600 dark:text-emerald-400 font-semibold">Sync in progress…</span>
                </div>`;
            return;
        }

        const badges = {
            success: '<span class="inline-flex items-center gap-1 text-green-600 dark:text-green-400"><span class="w-1.5 h-1.5 rounded-full bg-green-500 inline-block"></span> Last sync succeeded</span>',
            failed:  '<span class="inline-flex items-center gap-1 text-red-600 dark:text-red-400"><span class="w-1.5 h-1.5 rounded-full bg-red-500 inline-block"></span> Last sync failed</span>',
            error:   '<span class="inline-flex items-center gap-1 text-red-600 dark:text-red-400"><span class="w-1.5 h-1.5 rounded-full bg-red-500 inline-block"></span> Last sync errored</span>',
        };
        const badge = badges[data.lastStatus] ?? '';
        const when = data.lastRun ? new Date(data.lastRun).toLocaleString() : '';

        statusLine.innerHTML = data.lastRun
            ? `<div class="mt-3 flex items-center gap-2 text-xs">${badge}<span class="text-slate-400">— ${when}</span></div>`
                + (data.lastMessage ? `<p class="mt-1 text-xs text-slate-500 dark:text-slate-400 font-mono">${escapeHtmlLocal(data.lastMessage)}</p>` : '')
            : '<p class="mt-3 text-xs text-slate-400">Never run yet — runs automatically every 2 hours once connected.</p>';
    }

    function escapeHtmlLocal(s) {
        const div = document.createElement('div');
        div.textContent = s;
        return div.innerHTML;
    }

    async function poll() {
        try {
            const res = await fetch(statusUrl, { headers: { Accept: 'application/json' } });
            const data = await res.json();
            renderStatusLine(data);
            if (!data.running) {
                setLoading(false);
                clearInterval(pollInterval);
                pollInterval = null;
            }
        } catch (e) {
            // Silent — a missed poll tick just tries again next interval.
        }
    }

    function startPolling() {
        if (pollInterval) return;
        poll();
        pollInterval = setInterval(poll, 4000);
    }

    // Resume polling on load if a sync was already running when this page
    // rendered (e.g. triggered from a different tab, or the scheduled job).
    if (btn.dataset.initiallyRunning === '1') startPolling();

    form.addEventListener('submit', async (e) => {
        e.preventDefault();
        setLoading(true);

        try {
            const res = await fetch(form.action, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    Accept: 'application/json',
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || '',
                },
                body: JSON.stringify({ date: dateInput.value }),
            });
            const data = await res.json();

            if (!data.success) {
                setLoading(false);
                window.showToast?.(data.error || 'Could not start the sync — try again.', 'error');
                return;
            }

            startPolling();
        } catch (e) {
            setLoading(false);
            window.showToast?.('Could not reach the server — try again.', 'error');
        }
    });
})();
</script>
@endpush
