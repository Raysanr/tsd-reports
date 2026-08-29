<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>TSA Management · Seller's Hub TSD</title>
<link rel="icon" href="data:image/svg+xml,<svg xmlns=%22http://www.w3.org/2000/svg%22 viewBox=%220 0 24 24%22><text y=%22.9em%22 font-size=%2222%22>🧑‍💼</text></svg>">
<style>
    /* Same design tokens/fonts as hub.blade.php/hub-user-management.blade.php
       — this page is reached FROM the Hub and should read as the same
       product, not a jump into the internal dashboard's dark-sidebar chrome. */
    @import url('https://fonts.googleapis.com/css2?family=Fira+Code:wght@500;600;700&family=Fira+Sans:wght@400;500;600;700;800&display=swap');

    :root {
        --primary:      #CA8A04;
        --primary-dark: #854D0E;
        --accent:       #A16207;
        --bg:           #F5F3EC;
    }
    * { box-sizing: border-box; }
    html, body { height: 100%; }
    body {
        margin: 0;
        min-height: 100vh;
        display: flex;
        flex-direction: column;
        font-family: 'Fira Sans', ui-sans-serif, system-ui, sans-serif;
        background: var(--bg);
        background-image: radial-gradient(circle, #E2DFD3 1px, transparent 1px);
        background-size: 22px 22px;
        color: #1e293b;
    }
    header {
        width: 100%;
        padding: 28px clamp(24px, 4vw, 72px) 0;
    }
    .eyebrow {
        font-family: 'Fira Code', ui-monospace, monospace;
        font-size: 12px;
        font-weight: 700;
        letter-spacing: 0.14em;
        color: var(--accent);
        text-transform: uppercase;
        margin: 0;
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 16px;
    }
    .eyebrow a.back {
        color: var(--accent);
        text-decoration: none;
        display: inline-flex;
        align-items: center;
        gap: 6px;
    }
    .eyebrow a.back:hover { color: var(--primary-dark); }
    .eyebrow .greeting {
        color: #94a3b8;
        font-weight: 600;
        letter-spacing: normal;
        text-transform: none;
    }
    .eyebrow form { margin: 0; }
    .eyebrow .signout {
        font-family: inherit; font-size: inherit; font-weight: inherit;
        background: none; border: none; color: #94a3b8; cursor: pointer;
        padding: 0; text-decoration: underline; text-underline-offset: 2px;
    }
    .eyebrow .signout:hover { color: var(--accent); }

    main {
        flex: 1;
        width: 100%;
        max-width: 1180px;
        margin: 0 auto;
        padding: 48px clamp(24px, 4vw, 72px) 96px;
    }
    h1 {
        font-size: clamp(36px, 5vw, 52px);
        font-weight: 800;
        line-height: 1.05;
        margin: 0 0 10px;
        color: #0f172a;
        letter-spacing: -0.015em;
    }
    .subtitle {
        font-size: 16px;
        color: #64748b;
        margin: 0 0 40px;
        max-width: 62ch;
        line-height: 1.55;
    }

    .flash {
        padding: 14px 18px;
        border-radius: 12px;
        font-size: 13.5px;
        margin-bottom: 20px;
    }
    .flash.success { background: #ecfdf5; color: #047857; border: 1px solid #a7f3d0; }
    .flash.error   { background: #fef2f2; color: #b91c1c; border: 1px solid #fecaca; }

    .toolbar { display: flex; align-items: center; justify-content: space-between; gap: 16px; margin-bottom: 18px; }
    .toolbar p { font-size: 13px; color: #94a3b8; margin: 0; max-width: 52ch; line-height: 1.5; }
    .btn-primary {
        font-family: 'Fira Sans', sans-serif;
        display: inline-flex; align-items: center; gap: 8px;
        background: var(--primary); color: #fff; border: none;
        padding: 11px 20px; border-radius: 10px;
        font-size: 13.5px; font-weight: 700;
        cursor: pointer; white-space: nowrap; flex-shrink: 0;
        transition: background 150ms ease;
    }
    .btn-primary:hover { background: var(--primary-dark); }

    .layout { display: flex; gap: 24px; align-items: flex-start; flex-wrap: wrap; }
    .main-col { flex: 1; min-width: 320px; display: flex; flex-direction: column; gap: 24px; }
    .side-col { width: 320px; flex-shrink: 0; }
    @media (max-width: 900px) { .side-col { width: 100%; } }

    .panel {
        background: #fff;
        border-radius: 16px;
        box-shadow: 0 1px 2px rgba(15, 23, 42, 0.04), 0 14px 28px -18px rgba(133,77,14,0.16);
        overflow: hidden;
    }
    .panel-head {
        padding: 16px 22px;
        border-bottom: 1px solid #f1f5f9;
        display: flex;
        align-items: center;
        gap: 12px;
    }
    .panel-head .avatar {
        width: 28px; height: 28px; border-radius: 50%;
        background: var(--primary);
        color: #fff; display: flex; align-items: center; justify-content: center; flex-shrink: 0;
    }
    .panel-head .avatar svg { width: 14px; height: 14px; }
    .panel-head h3 { margin: 0; font-size: 14px; font-weight: 700; color: #0f172a; }
    .panel-head p { margin: 2px 0 0; font-size: 12px; color: #94a3b8; }

    .row {
        padding: 14px 22px;
        display: flex; align-items: center; gap: 16px; flex-wrap: wrap;
        border-bottom: 1px solid #f1f5f9;
    }
    .row:last-child { border-bottom: none; }
    .empty-row { padding: 40px 22px; text-align: center; color: #94a3b8; font-size: 13.5px; }

    .row-checkbox { width: 16px; height: 16px; accent-color: var(--primary); cursor: pointer; flex-shrink: 0; }
    .row-avatar {
        width: 28px; height: 28px; border-radius: 50%; flex-shrink: 0;
        display: flex; align-items: center; justify-content: center;
        color: #fff; font-size: 10px; font-weight: 700;
    }
    .row-who { display: flex; align-items: center; gap: 10px; width: 220px; flex-shrink: 0; }
    .row-name-input {
        font-family: 'Fira Code', monospace; font-weight: 600; font-size: 13.5px; color: #1e293b;
        border: none; border-bottom: 1px solid transparent; background: transparent;
        padding: 2px 0; width: 100%;
    }
    .row-name-input:hover { border-bottom-color: #e2e8f0; }
    .row-name-input:focus { outline: none; border-bottom-color: var(--primary); }
    .row-key { font-family: 'Fira Code', monospace; font-size: 10.5px; color: #94a3b8; margin-top: 1px; }

    .shift-fields { display: flex; align-items: center; gap: 8px; flex: 1; min-width: 220px; }
    .shift-fields label { font-family: 'Fira Code', monospace; font-size: 11.5px; color: #94a3b8; flex-shrink: 0; }
    .shift-fields input[type="time"] {
        border: 1px solid #e2e8f0; border-radius: 8px; padding: 5px 8px;
        font-family: 'Fira Code', monospace; font-size: 12px; color: #334155; background: #fff;
    }
    .shift-fields .range { font-size: 11.5px; color: #94a3b8; }

    .row-actions { display: flex; align-items: center; gap: 2px; flex-shrink: 0; margin-left: auto; }
    .icon-btn {
        width: 30px; height: 30px; border-radius: 8px; border: none; background: none;
        display: flex; align-items: center; justify-content: center;
        color: #94a3b8; cursor: pointer; transition: background 150ms ease, color 150ms ease;
    }
    .icon-btn:hover { background: rgba(202,138,4,0.1); color: var(--primary-dark); }
    .icon-btn.danger:hover { background: #fef2f2; color: #b91c1c; }
    .icon-btn svg { width: 14px; height: 14px; }

    .save-bar {
        display: flex; align-items: center; justify-content: space-between; gap: 16px;
        padding: 16px 22px;
    }
    .save-bar p { font-size: 12px; color: #94a3b8; margin: 0; }

    .unassigned-note {
        background: #fefce8; border: 1px solid #fde68a; border-radius: 12px;
        padding: 14px 18px; font-size: 12.5px; color: #854d0e;
    }
    .unassigned-note strong { display: block; margin-bottom: 3px; }

    details.panel summary {
        padding: 16px 22px; cursor: pointer; font-size: 13.5px; font-weight: 700; color: #334155;
        list-style: none;
    }
    details.panel summary::-webkit-details-marker { display: none; }
    details.panel summary:hover { background: #fafaf9; }
    details.panel .row { opacity: 0.65; }

    .badge-btn {
        font-family: 'Fira Sans', sans-serif; font-size: 11.5px; font-weight: 700;
        padding: 6px 12px; border-radius: 8px; cursor: pointer; white-space: nowrap;
    }
    .badge-btn.restore { color: var(--accent); background: none; border: 1px solid #fde68a; }
    .badge-btn.restore:hover { background: #fefce8; }
    .badge-btn.force-delete { color: #dc2626; background: none; border: 1px solid #fecaca; }
    .badge-btn.force-delete:hover { background: #fef2f2; }

    /* Rest-day calendar sidebar */
    .cal-nav { display: flex; align-items: center; justify-content: space-between; }
    .cal-nav a { color: #94a3b8; display: flex; padding: 4px; border-radius: 6px; }
    .cal-nav a:hover { color: var(--primary-dark); background: rgba(202,138,4,0.1); }
    .cal-nav a svg { width: 16px; height: 16px; }
    .cal-body { padding: 16px; }
    .cal-weekdays, .cal-days { display: grid; grid-template-columns: repeat(7, 1fr); gap: 4px; }
    .cal-weekdays { font-family: 'Fira Code', monospace; font-size: 10px; color: #94a3b8; text-align: center; margin-bottom: 8px; }
    .cal-day {
        aspect-ratio: 1; border-radius: 8px; border: 1px solid #f1f5f9; background: none;
        display: flex; flex-direction: column; align-items: center; justify-content: flex-start;
        padding: 4px 2px; cursor: pointer; transition: background 120ms ease, border-color 120ms ease;
    }
    .cal-day:hover { border-color: #fde68a; background: #fefce8; }
    .cal-day .d { font-family: 'Fira Code', monospace; font-size: 11px; color: #64748b; }
    .cal-day .off { font-family: 'Fira Code', monospace; font-size: 9px; color: var(--accent); line-height: 1.15; text-align: center; }
    .cal-blank { aspect-ratio: 1; }

    /* Add/Edit + Rest-day modal */
    .modal-backdrop {
        display: none;
        position: fixed; inset: 0; z-index: 50;
        background: rgba(15,23,42,0.45);
        align-items: center; justify-content: center; padding: 16px;
    }
    .modal-backdrop.open { display: flex; }
    .modal {
        background: #fff; border-radius: 18px; width: 100%; max-width: 440px;
        overflow: hidden; box-shadow: 0 24px 60px rgba(15,23,42,0.25);
        max-height: calc(100vh - 32px); display: flex; flex-direction: column;
    }
    .modal-head { padding: 24px 26px 18px; border-bottom: 1px solid #f1f5f9; flex-shrink: 0; }
    .modal-head h3 { margin: 0; font-size: 17px; font-weight: 700; color: #0f172a; }
    .modal-head p { margin: 6px 0 0; font-size: 12.5px; color: #94a3b8; }
    .modal-body { padding: 22px 26px; display: flex; flex-direction: column; gap: 16px; overflow-y: auto; }
    .field { position: relative; }
    .field label { display: block; font-size: 12.5px; font-weight: 700; color: #334155; margin-bottom: 6px; }
    .field label .opt { font-weight: 400; color: #94a3b8; }
    .field input, .field select {
        width: 100%; font-family: inherit; font-size: 14px;
        border: 1px solid #e2e8f0; border-radius: 10px; padding: 10px 12px;
        color: #0f172a; background: #fff;
    }
    .field input:focus, .field select:focus { outline: 2px solid var(--primary); outline-offset: 1px; }
    .field .hint { font-size: 11px; color: #94a3b8; margin-top: 5px; line-height: 1.4; }
    .field .linked-hint { font-size: 11px; color: #16a34a; margin-top: 5px; display: none; align-items: center; gap: 4px; }
    .field .linked-hint svg { width: 12px; height: 12px; }
    .search-results {
        display: none; position: absolute; z-index: 10; margin-top: 4px; width: 100%;
        background: #fff; border: 1px solid #e2e8f0; border-radius: 10px;
        box-shadow: 0 12px 28px rgba(15,23,42,0.14); max-height: 200px; overflow-y: auto;
    }
    .search-results .result-row { padding: 8px 12px; font-size: 13.5px; color: #334155; cursor: pointer; display: flex; align-items: center; justify-content: space-between; gap: 8px; }
    .search-results .result-row:hover { background: #fefce8; color: var(--primary-dark); }
    .search-results .result-row.disabled { color: #cbd5e1; cursor: default; }
    .search-results .result-row .note { font-size: 10.5px; color: #cbd5e1; flex-shrink: 0; }
    .tag-chips { display: none; flex-wrap: wrap; gap: 6px; margin-bottom: 8px; }
    .tag-chip {
        display: inline-flex; align-items: center; gap: 5px; padding: 3px 5px 3px 10px; border-radius: 999px;
        background: #fefce8; border: 1px solid #fde68a; font-family: 'Fira Code', monospace; font-size: 11px; color: #854d0e;
    }
    .tag-chip button { border: none; background: none; color: #854d0e; cursor: pointer; font-size: 13px; line-height: 1; padding: 2px; }
    .modal-actions { display: flex; justify-content: flex-end; gap: 10px; padding-top: 4px; flex-shrink: 0; }
    .btn-ghost {
        font-family: inherit; font-size: 13px; font-weight: 600; color: #64748b;
        background: none; border: 1px solid #e2e8f0; border-radius: 9px;
        padding: 9px 16px; cursor: pointer;
    }
    .btn-ghost:hover { background: #f8fafc; }
    .btn-danger {
        font-family: 'Fira Sans', sans-serif; font-size: 13.5px; font-weight: 700;
        color: #fff; background: #dc2626; border: none; border-radius: 10px;
        padding: 9px 18px; cursor: pointer; transition: background 150ms ease;
    }
    .btn-danger:hover { background: #b91c1c; }
    .rest-day-list { display: flex; flex-direction: column; gap: 10px; max-height: 320px; overflow-y: auto; }
    .rest-day-list label { display: flex; align-items: center; gap: 8px; font-size: 13.5px; color: #334155; }

    /* Bulk action bar */
    .bulk-bar {
        display: none;
        position: fixed; bottom: 20px; left: 50%; transform: translateX(-50%); z-index: 30;
        background: #fff; border: 1px solid #e2e8f0; border-radius: 14px;
        box-shadow: 0 16px 40px rgba(15,23,42,0.18);
        padding: 12px 18px; align-items: center; gap: 14px; flex-wrap: wrap; max-width: calc(100vw - 32px);
    }
    .bulk-bar.open { display: flex; }
    .bulk-bar .count { font-size: 13px; font-weight: 700; color: #334155; white-space: nowrap; }
    .bulk-bar .clear { font-family: 'Fira Code', monospace; font-size: 11.5px; color: #94a3b8; background: none; border: none; cursor: pointer; }
    .bulk-bar select {
        font-family: inherit; font-size: 12.5px; border: 1px solid #e2e8f0; border-radius: 8px; padding: 6px 8px; background: #fff;
    }
    .bulk-bar .move-btn { color: var(--accent); background: none; border: 1px solid #fde68a; border-radius: 8px; padding: 7px 14px; font-size: 12.5px; font-weight: 700; cursor: pointer; }
    .bulk-bar .move-btn:hover { background: #fefce8; }
    .bulk-bar .delete-btn { color: #fff; background: #dc2626; border: none; border-radius: 8px; padding: 7px 14px; font-size: 12.5px; font-weight: 700; cursor: pointer; }
    .bulk-bar .delete-btn:hover { background: #b91c1c; }

    @media (max-width: 640px) {
        header { padding: 24px 20px 0; }
        main { padding: 32px 20px 96px; }
        .row-who { width: 100%; }
        .row-actions { margin-left: 0; }
    }
</style>
</head>
<body>
<header>
    <div class="eyebrow">
        <a class="back" href="{{ route('hub') }}">
            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M15 19l-7-7 7-7"/></svg>
            Hub
        </a>
        <span class="greeting">
            Signed in as {{ auth()->user()->name }} ·
            <form method="POST" action="{{ route('logout') }}" style="display:inline">@csrf<button type="submit" class="signout">Sign out</button></form>
        </span>
    </div>
</header>
<main>
    <h1>TSA Management</h1>
    <p class="subtitle">Roster, teams, and shift schedules — add, edit, or remove agents, all reflected immediately on TSA Performance and Leads Report.</p>

    @if(session('success'))
    <div class="flash success">{{ session('success') }}</div>
    @endif
    @if($errors->any())
    <div class="flash error">
        @foreach($errors->all() as $error)<div>{{ $error }}</div>@endforeach
    </div>
    @endif

    <div class="toolbar">
        <p>Changes to names, shifts, teams, and rest days apply immediately.</p>
        <button type="button" id="addTsaBtn" class="btn-primary">
            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4v16m8-8H4"/></svg>
            Add TSA
        </button>
    </div>

    <div class="layout">
        <div class="main-col">

            <form method="POST" action="{{ route('settings.shifts') }}" id="shiftsForm">
                @csrf
                <input type="hidden" name="_redirect_route" value="hub.tsa-management">

                @php $teamAvatarColors = ['#0891B2', '#059669']; @endphp
                @foreach($teamGroups as $group)
                @php $teamColor = $teamAvatarColors[$loop->index % count($teamAvatarColors)]; @endphp
                <div class="panel" style="margin-bottom: 16px;">
                    <div class="panel-head">
                        <div class="avatar" style="background:{{ $teamColor }}">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0"/></svg>
                        </div>
                        <div>
                            <h3>{{ $group['name'] }}</h3>
                            <p>{{ $group['shifts']->count() }} {{ \Illuminate\Support\Str::plural('agent', $group['shifts']->count()) }}</p>
                        </div>
                    </div>

                    @if($group['shifts']->isEmpty())
                    <div class="empty-row">No TSAs on this team yet</div>
                    @else
                    @foreach($group['shifts'] as $shift)
                    <div class="row">
                        <input type="checkbox" class="row-checkbox tsaCheckbox" data-id="{{ $shift->id }}">
                        <div class="row-who">
                            <div class="row-avatar" style="background:{{ $teamColor }}">{{ strtoupper(substr($shift->display_name, 0, 2)) }}</div>
                            <div>
                                <input type="text" class="row-name-input" name="shifts[{{ $shift->tsa_key }}][display_name]" value="{{ $shift->display_name }}" placeholder="Full name">
                                <div class="row-key">{{ $shift->tsa_key }}</div>
                            </div>
                        </div>
                        <div class="shift-fields">
                            <label>Shift</label>
                            <input type="time" name="shifts[{{ $shift->tsa_key }}][shift_start]" value="{{ $shift->shift_start }}">
                            <span class="range">to</span>
                            <input type="time" name="shifts[{{ $shift->tsa_key }}][shift_end]" value="{{ $shift->shift_end }}">
                            @if($shift->shift_start && $shift->shift_end)
                            <span class="range">{{ $shift->shift_range }}</span>
                            @endif
                        </div>
                        <div class="row-actions">
                            <button type="button" class="icon-btn editTsaBtn" title="Edit"
                                data-id="{{ $shift->id }}"
                                data-tsa-key="{{ $shift->tsa_key }}"
                                data-display-name="{{ $shift->display_name }}"
                                data-team="{{ $shift->team }}"
                                data-extra="{{ $shift->extra_tag_keywords }}"
                                data-pos-user-id="{{ $shift->pos_user_id }}"
                                data-rest-day="{{ $shift->rest_day_of_week }}">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"/></svg>
                            </button>
                            <button type="button" class="icon-btn danger deleteTsaBtn" title="Remove" data-id="{{ $shift->id }}" data-name="{{ $shift->display_name }}">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6M9 7V4a1 1 0 011-1h4a1 1 0 011 1v3M4 7h16"/></svg>
                            </button>
                        </div>
                    </div>
                    @endforeach
                    @endif
                </div>
                @endforeach

                @if($unassigned->isNotEmpty())
                <div class="unassigned-note" style="margin-bottom:16px;">
                    <strong>Unassigned team</strong>
                    {{ $unassigned->pluck('display_name')->join(', ') }} — team value doesn't match a configured team.
                </div>
                @endif

                <div class="panel save-bar">
                    <p>Changes apply immediately on TSA Performance and Leads Report</p>
                    <button type="submit" class="btn-primary">
                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7"/></svg>
                        Save Schedules
                    </button>
                </div>
            </form>

            @if($trashedShifts->isNotEmpty())
            <details class="panel">
                <summary>Removed ({{ $trashedShifts->count() }})</summary>
                @foreach($trashedShifts as $shift)
                <div class="row">
                    <div class="row-who" style="width:auto; flex:1;">
                        <div class="row-avatar" style="background:#cbd5e1">{{ strtoupper(substr($shift->display_name, 0, 2)) }}</div>
                        <div>
                            <div class="row-name-input" style="border:none; padding:0;">{{ $shift->display_name }}</div>
                            <div class="row-key">{{ $shift->team }} — removed {{ $shift->deleted_at->diffForHumans() }}</div>
                        </div>
                    </div>
                    <div class="row-actions" style="margin-left:0;">
                        <form method="POST" action="{{ route('tsa-management.restore', $shift->id) }}" style="display:inline">
                            @csrf
                            <input type="hidden" name="_redirect_route" value="hub.tsa-management">
                            <button type="submit" class="badge-btn restore">Restore</button>
                        </form>
                        <button type="button" class="badge-btn force-delete forceDeleteTsaBtn" data-id="{{ $shift->id }}" data-name="{{ $shift->display_name }}">Delete forever</button>
                    </div>
                </div>
                @endforeach
            </details>
            @endif

        </div>

        <div class="side-col">
            <div class="panel">
                <div class="panel-head cal-nav">
                    <a href="{{ route('hub.tsa-management', ['month' => $calendar['prev_month']]) }}">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M15 19l-7-7 7-7"/></svg>
                    </a>
                    <h3>{{ $calendar['month_label'] }}</h3>
                    <a href="{{ route('hub.tsa-management', ['month' => $calendar['next_month']]) }}">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M9 5l7 7-7 7"/></svg>
                    </a>
                </div>
                <div class="cal-body">
                    <div class="cal-weekdays">
                        <div>Sun</div><div>Mon</div><div>Tue</div><div>Wed</div><div>Thu</div><div>Fri</div><div>Sat</div>
                    </div>
                    <div class="cal-days">
                        @for($i = 0; $i < $calendar['leading_blanks']; $i++)
                        <div class="cal-blank"></div>
                        @endfor
                        @foreach($calendar['days'] as $dayData)
                        <button type="button" class="cal-day restDayCell" data-date="{{ $dayData['date'] }}" data-off="{{ $dayData['off_tsas']->pluck('tsa_key')->join(',') }}">
                            <span class="d">{{ $dayData['day'] }}</span>
                            @if($dayData['off_tsas']->isNotEmpty())
                            <span class="off">{{ $dayData['off_tsas']->pluck('initials')->join(' ') }}</span>
                            @endif
                        </button>
                        @endforeach
                    </div>
                </div>
            </div>
        </div>
    </div>
</main>

{{-- Shared Add / Edit modal --}}
<div id="tsaModal" class="modal-backdrop">
    <div class="modal">
        <div class="modal-head">
            <h3 id="tsaModalTitle">Add a new TSA</h3>
            <p id="tsaModalSubtitle">They'll be recognized starting with the next sync</p>
        </div>
        <form id="tsaForm" method="POST" action="{{ route('tsa-management.store') }}">
            @csrf
            <input type="hidden" name="_method" id="tsaFormMethod" value="">
            <input type="hidden" name="_redirect_route" value="hub.tsa-management">
            <input type="hidden" name="pos_user_id" id="tsaPosUserId" value="">
            <div class="modal-body">
                <div class="field">
                    <label>TSA name</label>
                    <input type="text" id="tsaNameInput" name="display_name" required autocomplete="off" placeholder="Search Pancake POS accounts…">
                    <div id="tsaNameResults" class="search-results"></div>
                    <p id="tsaLinkedHint" class="linked-hint">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7"/></svg>
                        Linked to a real POS account
                    </p>
                </div>
                <div class="field">
                    <label>Team</label>
                    <select name="team" id="tsaTeamSelect" required>
                        @foreach($teamsConfig as $team)
                        <option value="{{ $team['order_team'] }}">{{ $team['name'] }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="field">
                    <label>Also matches <span class="opt">(optional)</span></label>
                    <input type="hidden" name="extra_keywords" id="tsaExtraInput" value="">
                    <div id="tsaTagChips" class="tag-chips"></div>
                    <input type="text" id="tsaTagSearch" autocomplete="off" placeholder="Search Pancake tags…">
                    <div id="tsaTagResults" class="search-results"></div>
                    <p class="hint">Their first name is matched automatically — pick any other Pancake tags that should also count as theirs.</p>
                </div>
                <div class="field">
                    <label>Rest day <span class="opt">(optional)</span></label>
                    <select name="rest_day_of_week" id="tsaRestDaySelect">
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
                <div class="modal-actions">
                    <button type="button" id="cancelTsaModal" class="btn-ghost">Cancel</button>
                    <button type="submit" id="tsaSubmitBtn" class="btn-primary">Add TSA</button>
                </div>
            </div>
        </form>
    </div>
</div>

{{-- Rest Day modal --}}
<div id="restDayModal" class="modal-backdrop">
    <div class="modal">
        <div class="modal-head">
            <h3 id="restDayModalTitle">Rest days</h3>
        </div>
        <form id="restDayForm" method="POST">
            @csrf
            <input type="hidden" name="_redirect_route" value="hub.tsa-management">
            <div class="modal-body">
                <div class="rest-day-list">
                    @foreach($shifts as $shift)
                    <label>
                        <input type="checkbox" name="tsas[]" value="{{ $shift->tsa_key }}" class="restDayCheckbox" data-tsa-key="{{ $shift->tsa_key }}">
                        {{ $shift->display_name }}
                    </label>
                    @endforeach
                </div>
                <div class="modal-actions">
                    <button type="button" id="cancelRestDayModal" class="btn-ghost">Cancel</button>
                    <button type="submit" class="btn-primary">Save</button>
                </div>
            </div>
        </form>
    </div>
</div>

{{-- Delete confirm modal — this page deliberately has no shared
     window.showConfirm (no app.js pulled in, same reasoning as
     hub-user-management.blade.php) — a bespoke modal instead, reused for
     both the remove and permanent-delete actions via a small state var. --}}
<div id="confirmModal" class="modal-backdrop">
    <div class="modal">
        <div class="modal-head">
            <h3 id="confirmModalTitle">Are you sure?</h3>
            <p id="confirmModalMessage"></p>
        </div>
        <div class="modal-body">
            <div class="modal-actions">
                <button type="button" id="cancelConfirmModal" class="btn-ghost">Cancel</button>
                <button type="button" id="confirmModalBtn" class="btn-danger">Confirm</button>
            </div>
        </div>
    </div>
</div>

<form id="deleteTsaForm" method="POST" style="display:none">
    @csrf
    @method('DELETE')
    <input type="hidden" name="_redirect_route" value="hub.tsa-management">
</form>
<form id="forceDeleteTsaForm" method="POST" style="display:none">
    @csrf
    @method('DELETE')
    <input type="hidden" name="_redirect_route" value="hub.tsa-management">
</form>
<form id="bulkTsaForm" method="POST" action="{{ route('tsa-management.bulk') }}" style="display:none">
    @csrf
    <input type="hidden" name="_redirect_route" value="hub.tsa-management">
    <input type="hidden" name="action" id="bulkTsaAction" value="">
</form>

<div id="bulkTsaBar" class="bulk-bar">
    <span id="bulkTsaCount" class="count">0 selected</span>
    <button type="button" id="bulkTsaClear" class="clear">Clear</button>
    <select id="bulkTsaTeamSelect">
        @foreach($teamsConfig as $team)
        <option value="{{ $team['order_team'] }}">{{ $team['name'] }}</option>
        @endforeach
    </select>
    <button type="button" id="bulkTsaMove" class="move-btn">Move</button>
    <button type="button" id="bulkTsaDelete" class="delete-btn">Delete</button>
</div>

<script>
(function () {
    // Bespoke confirm() replacement — see #confirmModal's own comment.
    let confirmResolve = null;
    const confirmModal   = document.getElementById('confirmModal');
    const confirmTitle   = document.getElementById('confirmModalTitle');
    const confirmMessage = document.getElementById('confirmModalMessage');
    const confirmBtn     = document.getElementById('confirmModalBtn');

    window.showConfirm = function (message, opts) {
        opts = opts || {};
        confirmTitle.textContent = opts.title || 'Are you sure?';
        confirmMessage.textContent = message;
        confirmBtn.textContent = opts.confirmText || 'Confirm';
        confirmModal.classList.add('open');
        return new Promise((resolve) => { confirmResolve = resolve; });
    };
    function closeConfirm(result) {
        confirmModal.classList.remove('open');
        if (confirmResolve) { confirmResolve(result); confirmResolve = null; }
    }
    document.getElementById('cancelConfirmModal').addEventListener('click', () => closeConfirm(false));
    confirmBtn.addEventListener('click', () => closeConfirm(true));
    confirmModal.addEventListener('click', (e) => { if (e.target === confirmModal) closeConfirm(false); });
})();
</script>
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

    function currentBaseKey() {
        if (currentTsaKey) return currentTsaKey.toUpperCase();
        const firstWord = (nameInput.value.trim().split(/\s+/)[0] || '').replace(/[^A-Za-z]/g, '');
        return firstWord.toUpperCase();
    }

    function openModal() { modal.classList.add('open'); }
    function closeModal() {
        modal.classList.remove('open');
        resultsBox.style.display = 'none';
        tagResults.style.display = 'none';
    }

    function setSelectedTags(tags) {
        selectedTags = tags;
        extraInput.value = selectedTags.join(',');
        renderTagChips();
    }

    function renderTagChips() {
        tagChips.innerHTML = '';
        if (!selectedTags.length) { tagChips.style.display = 'none'; return; }
        tagChips.style.display = 'flex';

        selectedTags.forEach(tag => {
            const chip = document.createElement('span');
            chip.className = 'tag-chip';
            const label = document.createElement('span');
            label.textContent = tag;
            chip.appendChild(label);
            const removeBtn = document.createElement('button');
            removeBtn.type = 'button';
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
        linkedHint.style.display = 'none';
        modalTitle.textContent = 'Add a new TSA';
        modalSubtitle.textContent = "They'll be recognized starting with the next sync";
        submitBtn.textContent = 'Add TSA';
    }

    document.getElementById('addTsaBtn').addEventListener('click', () => { resetForm(); openModal(); });
    document.getElementById('cancelTsaModal').addEventListener('click', closeModal);
    modal.addEventListener('click', (e) => { if (e.target === modal) closeModal(); });

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
            if (btn.dataset.posUserId) linkedHint.style.display = 'flex';
            modalTitle.textContent = 'Edit TSA';
            modalSubtitle.textContent = 'Changes apply starting with the next sync';
            submitBtn.textContent = 'Save Changes';
            openModal();
        });
    });

    const deleteForm = document.getElementById('deleteTsaForm');
    document.querySelectorAll('.deleteTsaBtn').forEach(btn => {
        btn.addEventListener('click', async () => {
            const name = btn.dataset.name || 'this TSA';
            if (!await window.showConfirm(`Remove "${name}" from the roster? You can restore it from the Removed list below.`, { confirmText: 'Remove' })) return;
            deleteForm.action = storeUrl + '/' + btn.dataset.id;
            deleteForm.submit();
        });
    });

    const forceDeleteForm = document.getElementById('forceDeleteTsaForm');
    document.querySelectorAll('.forceDeleteTsaBtn').forEach(btn => {
        btn.addEventListener('click', async () => {
            const name = btn.dataset.name || 'this TSA';
            if (!await window.showConfirm(`Permanently delete "${name}"? This cannot be undone — their call recordings and status history go with them, and there is no way to restore any of it after this.`, { confirmText: 'Delete forever' })) return;
            forceDeleteForm.action = storeUrl + '/' + btn.dataset.id + '/force';
            forceDeleteForm.submit();
        });
    });

    let debounceTimer = null;
    nameInput.addEventListener('input', () => {
        posUserIdInput.value = '';
        linkedHint.style.display = 'none';
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
            resultsBox.style.display = 'none';
        }
    }

    function renderResults(users) {
        if (!users.length) { resultsBox.style.display = 'none'; resultsBox.innerHTML = ''; return; }
        resultsBox.innerHTML = '';
        users.forEach(u => {
            const row = document.createElement('div');
            row.className = 'result-row';
            row.textContent = u.name;
            row.addEventListener('mousedown', (e) => {
                e.preventDefault();
                nameInput.value = u.name;
                posUserIdInput.value = u.id;
                linkedHint.style.display = 'flex';
                resultsBox.style.display = 'none';
            });
            resultsBox.appendChild(row);
        });
        resultsBox.style.display = 'block';
    }

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
        const requestId = ++tagRequestId;
        try {
            const res = await fetch(`{{ route('tsa-management.tags') }}?q=` + encodeURIComponent(q));
            const tags = await res.json();
            if (requestId !== tagRequestId) return;
            renderTagResults(tags);
        } catch (e) {
            if (requestId === tagRequestId) tagResults.style.display = 'none';
        }
    }

    function renderTagResults(tags) {
        const base = currentBaseKey();
        const visible = tags.filter(t => !selectedTags.includes(t.name));
        tagResults.innerHTML = '';
        if (!visible.length) { tagResults.style.display = 'none'; return; }

        visible.forEach(t => {
            const isOwnBase = t.name.toUpperCase() === base;
            const row = document.createElement('div');
            row.className = 'result-row' + (isOwnBase ? ' disabled' : '');

            const label = document.createElement('span');
            label.textContent = t.name;
            row.appendChild(label);

            const note = document.createElement('span');
            note.className = 'note';
            note.textContent = isOwnBase ? 'auto-matched already' : t.count;
            row.appendChild(note);

            if (!isOwnBase) {
                row.addEventListener('mousedown', (e) => {
                    e.preventDefault();
                    setSelectedTags([...selectedTags, t.name]);
                    tagSearch.value = '';
                    tagResults.style.display = 'none';
                    tagSearch.focus();
                });
            }

            tagResults.appendChild(row);
        });

        tagResults.style.display = 'block';
    }

    document.addEventListener('click', (e) => {
        if (!resultsBox.contains(e.target) && e.target !== nameInput) resultsBox.style.display = 'none';
        if (!tagResults.contains(e.target) && e.target !== tagSearch) tagResults.style.display = 'none';
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
            restDayModal.classList.add('open');
        });
    });

    document.getElementById('cancelRestDayModal').addEventListener('click', () => {
        restDayModal.classList.remove('open');
    });
    restDayModal.addEventListener('click', (e) => {
        if (e.target === restDayModal) restDayModal.classList.remove('open');
    });
})();
</script>
<script>
(function () {
    const selectedIds     = new Set();
    const bulkBar         = document.getElementById('bulkTsaBar');
    const bulkCount       = document.getElementById('bulkTsaCount');
    const bulkForm        = document.getElementById('bulkTsaForm');
    const bulkActionInput = document.getElementById('bulkTsaAction');
    const bulkTeamSelect  = document.getElementById('bulkTsaTeamSelect');

    function updateBulkBar() {
        const n = selectedIds.size;
        if (n > 0) {
            bulkBar.classList.add('open');
            bulkCount.textContent = `${n} selected`;
        } else {
            bulkBar.classList.remove('open');
        }
    }

    document.querySelectorAll('.tsaCheckbox').forEach(cb => {
        cb.addEventListener('change', () => {
            const id = cb.dataset.id;
            if (cb.checked) selectedIds.add(id); else selectedIds.delete(id);
            updateBulkBar();
        });
    });

    document.getElementById('bulkTsaClear').addEventListener('click', () => {
        selectedIds.clear();
        document.querySelectorAll('.tsaCheckbox').forEach(cb => { cb.checked = false; });
        updateBulkBar();
    });

    function submitBulk(action, extra) {
        bulkForm.querySelectorAll('input[name="ids[]"], input[name="team"]').forEach(el => el.remove());

        selectedIds.forEach(id => {
            const input = document.createElement('input');
            input.type  = 'hidden';
            input.name  = 'ids[]';
            input.value = id;
            bulkForm.appendChild(input);
        });

        bulkActionInput.value = action;

        if (extra && extra.team) {
            const teamInput = document.createElement('input');
            teamInput.type  = 'hidden';
            teamInput.name  = 'team';
            teamInput.value = extra.team;
            bulkForm.appendChild(teamInput);
        }

        bulkForm.submit();
    }

    document.getElementById('bulkTsaMove').addEventListener('click', () => submitBulk('move', { team: bulkTeamSelect.value }));
    document.getElementById('bulkTsaDelete').addEventListener('click', async () => {
        const n = selectedIds.size;
        if (!await window.showConfirm(`Remove ${n} TSA(s)? You can restore them from the Removed list below.`, { confirmText: 'Remove' })) return;
        submitBulk('delete');
    });
})();
</script>
</body>
</html>
