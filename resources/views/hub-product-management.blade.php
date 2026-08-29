<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Product Management · Seller's Hub TSD</title>
<link rel="icon" href="data:image/svg+xml,<svg xmlns=%22http://www.w3.org/2000/svg%22 viewBox=%220 0 24 24%22><text y=%22.9em%22 font-size=%2222%22>📦</text></svg>">
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
        max-width: 820px;
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
        max-width: 56ch;
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
    .toolbar p { font-size: 13px; color: #94a3b8; margin: 0; max-width: 46ch; line-height: 1.5; }
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

    .panel {
        background: #fff;
        border-radius: 16px;
        box-shadow: 0 1px 2px rgba(15, 23, 42, 0.04), 0 14px 28px -18px rgba(133,77,14,0.16);
        overflow: hidden;
        margin-bottom: 16px;
    }
    .panel-head { padding: 16px 22px; border-bottom: 1px solid #f1f5f9; }
    .panel-head h3 { margin: 0; font-size: 14px; font-weight: 700; color: #0f172a; }
    .panel-head p { margin: 2px 0 0; font-size: 12px; color: #94a3b8; }

    .row {
        padding: 14px 22px;
        display: flex; align-items: center; gap: 16px;
        border-bottom: 1px solid #f1f5f9;
    }
    .row:last-child { border-bottom: none; }
    .row.hidden-row { opacity: 0.55; }
    .empty-row { padding: 40px 22px; text-align: center; color: #94a3b8; font-size: 13.5px; }

    .row-checkbox { width: 16px; height: 16px; accent-color: var(--primary); cursor: pointer; flex-shrink: 0; }
    .row-who { flex: 1; min-width: 0; }
    .row-name-line { display: flex; align-items: center; gap: 8px; flex-wrap: wrap; }
    .row-name { font-family: 'Fira Code', monospace; font-weight: 600; font-size: 13.5px; color: #1e293b; }
    .badge-hidden {
        font-family: 'Fira Code', monospace; font-size: 10px; font-weight: 700;
        color: #64748b; background: #f1f5f9; padding: 2px 7px; border-radius: 5px;
    }
    .row-match { font-family: 'Fira Code', monospace; font-size: 10.5px; color: #94a3b8; margin-top: 2px; }

    .row-actions { display: flex; align-items: center; gap: 2px; flex-shrink: 0; }
    .icon-btn {
        width: 30px; height: 30px; border-radius: 8px; border: none; background: none;
        display: flex; align-items: center; justify-content: center;
        color: #94a3b8; cursor: pointer; transition: background 150ms ease, color 150ms ease;
    }
    .icon-btn:hover { background: rgba(202,138,4,0.1); color: var(--primary-dark); }
    .icon-btn.blue:hover { background: #eff6ff; color: #2563eb; }
    .icon-btn.danger:hover { background: #fef2f2; color: #b91c1c; }
    .icon-btn svg { width: 14px; height: 14px; }

    .unassigned-note {
        background: #fefce8; border: 1px solid #fde68a; border-radius: 12px;
        padding: 14px 18px; font-size: 12.5px; color: #854d0e; margin-bottom: 16px;
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

    /* Add/Edit modal */
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
    .search-results {
        display: none; position: absolute; z-index: 10; margin-top: 4px; width: 100%;
        background: #fff; border: 1px solid #e2e8f0; border-radius: 10px;
        box-shadow: 0 12px 28px rgba(15,23,42,0.14); max-height: 200px; overflow-y: auto;
    }
    .search-results .result-row { padding: 8px 12px; font-size: 13.5px; color: #334155; cursor: pointer; display: flex; align-items: center; justify-content: space-between; gap: 8px; }
    .search-results .result-row:hover { background: #fefce8; color: var(--primary-dark); }
    .search-results .result-row.disabled { color: #cbd5e1; cursor: default; }
    .search-results .result-row .note { font-size: 10.5px; color: #cbd5e1; flex-shrink: 0; }
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

    /* Bulk action bar */
    .bulk-bar {
        display: none;
        position: fixed; bottom: 20px; left: 50%; transform: translateX(-50%); z-index: 30;
        background: #fff; border: 1px solid #e2e8f0; border-radius: 14px;
        box-shadow: 0 16px 40px rgba(15,23,42,0.18);
        padding: 12px 18px; align-items: center; gap: 12px; flex-wrap: wrap; max-width: calc(100vw - 32px);
    }
    .bulk-bar.open { display: flex; }
    .bulk-bar .count { font-size: 13px; font-weight: 700; color: #334155; white-space: nowrap; }
    .bulk-bar .clear { font-family: 'Fira Code', monospace; font-size: 11.5px; color: #94a3b8; background: none; border: none; cursor: pointer; }
    .bulk-bar select {
        font-family: inherit; font-size: 12.5px; border: 1px solid #e2e8f0; border-radius: 8px; padding: 6px 8px; background: #fff;
    }
    .bulk-bar .ghost-btn { color: #64748b; background: none; border: 1px solid #e2e8f0; border-radius: 8px; padding: 7px 14px; font-size: 12.5px; font-weight: 700; cursor: pointer; }
    .bulk-bar .ghost-btn:hover { background: #f8fafc; }
    .bulk-bar .move-btn { color: var(--accent); background: none; border: 1px solid #fde68a; border-radius: 8px; padding: 7px 14px; font-size: 12.5px; font-weight: 700; cursor: pointer; }
    .bulk-bar .move-btn:hover { background: #fefce8; }
    .bulk-bar .delete-btn { color: #fff; background: #dc2626; border: none; border-radius: 8px; padding: 7px 14px; font-size: 12.5px; font-weight: 700; cursor: pointer; }
    .bulk-bar .delete-btn:hover { background: #b91c1c; }

    @media (max-width: 640px) {
        header { padding: 24px 20px 0; }
        main { padding: 32px 20px 96px; }
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
    <h1>Product Management</h1>
    <p class="subtitle">Products and which team each one belongs to — add, edit, or remove products, reflected immediately on TSA Performance and syncing.</p>

    @if(session('success'))
    <div class="flash success">{{ session('success') }}</div>
    @endif
    @if($errors->any())
    <div class="flash error">
        @foreach($errors->all() as $error)<div>{{ $error }}</div>@endforeach
    </div>
    @endif

    <div class="toolbar">
        <p>Changes apply immediately on TSA Performance and syncing.</p>
        <button type="button" id="addProductBtn" class="btn-primary">
            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4v16m8-8H4"/></svg>
            Add Product
        </button>
    </div>

    @foreach($teamGroups as $group)
    <div class="panel">
        <div class="panel-head">
            <h3>{{ $group['name'] }}</h3>
            <p>{{ $group['products']->count() }} {{ \Illuminate\Support\Str::plural('product', $group['products']->count()) }}</p>
        </div>

        @if($group['products']->isEmpty())
        <div class="empty-row">No products for this team yet</div>
        @else
        @foreach($group['products'] as $product)
        <div class="row {{ $product->is_hidden ? 'hidden-row' : '' }}">
            <input type="checkbox" class="row-checkbox productCheckbox" data-id="{{ $product->id }}">
            <div class="row-who">
                <div class="row-name-line">
                    <span class="row-name">{{ $product->display_name }}</span>
                    @if($product->is_hidden)
                    <span class="badge-hidden">Hidden</span>
                    @endif
                </div>
                @if($product->match_keyword)
                <div class="row-match">matches: {{ $product->match_keyword }}</div>
                @endif
            </div>
            <div class="row-actions">
                <button type="button" class="icon-btn editProductBtn" title="Edit"
                    data-id="{{ $product->id }}"
                    data-display-name="{{ $product->display_name }}"
                    data-match-keyword="{{ $product->match_keyword }}"
                    data-team="{{ $product->team }}">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"/></svg>
                </button>
                <button type="button" class="icon-btn blue toggleHiddenBtn" title="{{ $product->is_hidden ? 'Unhide' : 'Hide' }}" data-id="{{ $product->id }}">
                    @if($product->is_hidden)
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M3.98 8.223A10.477 10.477 0 001.934 12C3.226 16.338 7.244 19.5 12 19.5c.993 0 1.953-.138 2.863-.395M6.228 6.228A10.45 10.45 0 0112 4.5c4.756 0 8.773 3.162 10.065 7.498a10.523 10.523 0 01-4.293 5.774M6.228 6.228L3 3m3.228 3.228l3.65 3.65m7.894 7.894L21 21m-3.228-3.228l-3.65-3.65m0 0a3 3 0 10-4.243-4.243m4.242 4.242L9.88 9.88"/></svg>
                    @else
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M2.036 12.322a1.012 1.012 0 010-.639C3.423 7.51 7.36 4.5 12 4.5c4.638 0 8.573 3.007 9.963 7.178.07.207.07.431 0 .639C20.577 16.49 16.64 19.5 12 19.5c-4.638 0-8.573-3.007-9.963-7.178z"/><path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/></svg>
                    @endif
                </button>
                <button type="button" class="icon-btn danger deleteProductBtn" title="Remove" data-id="{{ $product->id }}" data-name="{{ $product->display_name }}">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6M9 7V4a1 1 0 011-1h4a1 1 0 011 1v3M4 7h16"/></svg>
                </button>
            </div>
        </div>
        @endforeach
        @endif
    </div>
    @endforeach

    @if($trashedProducts->isNotEmpty())
    <details class="panel">
        <summary>Removed ({{ $trashedProducts->count() }})</summary>
        @foreach($trashedProducts as $product)
        <div class="row">
            <div class="row-who">
                <div class="row-name">{{ $product->display_name }}</div>
                <div class="row-match">removed {{ $product->deleted_at->diffForHumans() }}</div>
            </div>
            <div class="row-actions">
                <form method="POST" action="{{ route('product-management.restore', $product->id) }}" style="display:inline">
                    @csrf
                    <input type="hidden" name="_redirect_route" value="hub.product-management">
                    <button type="submit" class="badge-btn restore">Restore</button>
                </form>
                <button type="button" class="badge-btn force-delete forceDeleteProductBtn" data-id="{{ $product->id }}" data-name="{{ $product->display_name }}">Delete forever</button>
            </div>
        </div>
        @endforeach
    </details>
    @endif

    @if($unassigned->isNotEmpty())
    <div class="unassigned-note">
        <strong>Unassigned team</strong>
        {{ $unassigned->pluck('display_name')->join(', ') }} — team value doesn't match a configured team.
    </div>
    @endif
</main>

{{-- Shared Add / Edit modal --}}
<div id="productModal" class="modal-backdrop">
    <div class="modal">
        <div class="modal-head">
            <h3 id="productModalTitle">Add a new product</h3>
            <p id="productModalSubtitle">Recognized starting with the next sync</p>
        </div>
        <form id="productForm" method="POST" action="{{ route('product-management.store') }}">
            @csrf
            <input type="hidden" name="_method" id="productFormMethod" value="">
            <input type="hidden" name="_redirect_route" value="hub.product-management">
            <div class="modal-body">
                <div class="field">
                    <label>Display name</label>
                    <input type="text" id="productNameInput" name="display_name" required>
                </div>
                <div class="field">
                    <label>Team</label>
                    <select name="team" id="productTeamSelect" required>
                        @foreach($teamsConfig as $team)
                        <option value="{{ $team['order_team'] }}">{{ $team['name'] }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="field">
                    <label>Match keywords <span class="opt">(optional, comma-separated)</span></label>
                    <input type="text" name="match_keyword" id="productKeywordInput" placeholder="e.g. PTERYGIUM, PteryFix — every cart-name variant of this product">
                    <input type="text" id="productKeywordSearch" autocomplete="off" placeholder="Search POS products to add…" style="margin-top:8px;">
                    <div id="productKeywordResults" class="search-results"></div>
                    <p class="hint">Leave blank to match on the display name itself. Matching ignores case, spaces and punctuation, and an order counts if it matches ANY keyword — add every alias the POS cart uses, or unclaimed leads for that variant won't be attributed to your team.</p>
                </div>
                <div class="modal-actions">
                    <button type="button" id="cancelProductModal" class="btn-ghost">Cancel</button>
                    <button type="submit" id="productSubmitBtn" class="btn-primary">Add Product</button>
                </div>
            </div>
        </form>
    </div>
</div>

{{-- Delete confirm modal — this page deliberately has no shared
     window.showConfirm (no app.js pulled in, same reasoning as
     hub-user-management.blade.php) — a bespoke modal instead, reused for
     remove / permanent-delete / bulk-move via a small state var. --}}
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

<form id="deleteProductForm" method="POST" style="display:none">
    @csrf
    @method('DELETE')
    <input type="hidden" name="_redirect_route" value="hub.product-management">
</form>
<form id="forceDeleteProductForm" method="POST" style="display:none">
    @csrf
    @method('DELETE')
    <input type="hidden" name="_redirect_route" value="hub.product-management">
</form>
<form id="toggleHiddenProductForm" method="POST" style="display:none">
    @csrf
    @method('PATCH')
    <input type="hidden" name="_redirect_route" value="hub.product-management">
</form>
<form id="bulkProductForm" method="POST" action="{{ route('product-management.bulk') }}" style="display:none">
    @csrf
    <input type="hidden" name="_redirect_route" value="hub.product-management">
    <input type="hidden" name="action" id="bulkProductAction" value="">
</form>

<div id="bulkProductBar" class="bulk-bar">
    <span id="bulkProductCount" class="count">0 selected</span>
    <button type="button" id="bulkProductClear" class="clear">Clear</button>
    <button type="button" id="bulkProductHide" class="ghost-btn">Hide</button>
    <button type="button" id="bulkProductUnhide" class="ghost-btn">Unhide</button>
    <select id="bulkProductTeamSelect">
        @foreach($teamsConfig as $team)
        <option value="{{ $team['order_team'] }}">{{ $team['name'] }}</option>
        @endforeach
    </select>
    <button type="button" id="bulkProductMove" class="move-btn">Move</button>
    <button type="button" id="bulkProductDelete" class="delete-btn">Delete</button>
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
    const modal       = document.getElementById('productModal');
    const modalTitle  = document.getElementById('productModalTitle');
    const modalSubtitle = document.getElementById('productModalSubtitle');
    const form        = document.getElementById('productForm');
    const methodInput = document.getElementById('productFormMethod');
    const nameInput   = document.getElementById('productNameInput');
    const teamSelect  = document.getElementById('productTeamSelect');
    const keywordInput = document.getElementById('productKeywordInput');
    const keywordSearch = document.getElementById('productKeywordSearch');
    const keywordResults = document.getElementById('productKeywordResults');
    const submitBtn   = document.getElementById('productSubmitBtn');
    const storeUrl    = form.action;
    const toggleHiddenForm = document.getElementById('toggleHiddenProductForm');

    function openModal() { modal.classList.add('open'); }
    function closeModal() { modal.classList.remove('open'); }

    function resetForm() {
        form.action = storeUrl;
        methodInput.value = '';
        nameInput.value = '';
        keywordInput.value = '';
        teamSelect.selectedIndex = 0;
        modalTitle.textContent = 'Add a new product';
        modalSubtitle.textContent = 'Recognized starting with the next sync';
        submitBtn.textContent = 'Add Product';
        keywordSearch.value = '';
        keywordResults.style.display = 'none';
    }

    let keywordDebounceTimer = null;
    let keywordRequestId = 0;

    keywordSearch.addEventListener('input', () => {
        clearTimeout(keywordDebounceTimer);
        const q = keywordSearch.value.trim();
        keywordDebounceTimer = setTimeout(() => fetchPosProducts(q), 250);
    });
    keywordSearch.addEventListener('focus', () => {
        if (keywordSearch.value.trim() !== '') fetchPosProducts(keywordSearch.value.trim());
    });

    async function fetchPosProducts(q) {
        const requestId = ++keywordRequestId;
        try {
            const res = await fetch(`{{ route('product-management.search-pos-products') }}?q=` + encodeURIComponent(q));
            const products = await res.json();
            if (requestId !== keywordRequestId) return;
            renderKeywordResults(products);
        } catch (e) {
            if (requestId === keywordRequestId) keywordResults.style.display = 'none';
        }
    }

    function currentKeywords() {
        return keywordInput.value.split(',').map(k => k.trim()).filter(k => k !== '');
    }

    function renderKeywordResults(products) {
        const existingUpper = currentKeywords().map(k => k.toUpperCase());
        keywordResults.innerHTML = '';

        if (!products.length) { keywordResults.style.display = 'none'; return; }

        products.forEach(p => {
            const alreadyAdded = existingUpper.includes(p.name.toUpperCase());
            const row = document.createElement('div');
            row.className = 'result-row' + (alreadyAdded ? ' disabled' : '');

            const label = document.createElement('span');
            label.textContent = p.name;
            row.appendChild(label);

            if (alreadyAdded) {
                const note = document.createElement('span');
                note.className = 'note';
                note.textContent = 'added';
                row.appendChild(note);
            } else {
                row.addEventListener('mousedown', (e) => {
                    e.preventDefault();
                    const kws = currentKeywords();
                    kws.push(p.name);
                    keywordInput.value = kws.join(', ');
                    keywordSearch.value = '';
                    keywordResults.style.display = 'none';
                    keywordSearch.focus();
                });
            }

            keywordResults.appendChild(row);
        });

        keywordResults.style.display = 'block';
    }

    document.addEventListener('click', (e) => {
        if (!keywordResults.contains(e.target) && e.target !== keywordSearch) {
            keywordResults.style.display = 'none';
        }
    });

    document.getElementById('addProductBtn').addEventListener('click', () => { resetForm(); openModal(); });
    document.getElementById('cancelProductModal').addEventListener('click', closeModal);
    modal.addEventListener('click', (e) => { if (e.target === modal) closeModal(); });

    document.querySelectorAll('.editProductBtn').forEach(btn => {
        btn.addEventListener('click', () => {
            resetForm();
            const id = btn.dataset.id;
            form.action = storeUrl + '/' + id;
            methodInput.value = 'PUT';
            nameInput.value = btn.dataset.displayName || '';
            teamSelect.value = btn.dataset.team || '';
            keywordInput.value = btn.dataset.matchKeyword || '';
            modalTitle.textContent = 'Edit product';
            modalSubtitle.textContent = 'Changes apply starting with the next sync';
            submitBtn.textContent = 'Save Changes';
            openModal();
        });
    });

    const deleteForm = document.getElementById('deleteProductForm');
    document.querySelectorAll('.deleteProductBtn').forEach(btn => {
        btn.addEventListener('click', async () => {
            const name = btn.dataset.name || 'this product';
            if (!await window.showConfirm(`Remove "${name}"? You can restore it from the Removed list below.`, { confirmText: 'Remove' })) return;
            deleteForm.action = storeUrl + '/' + btn.dataset.id;
            deleteForm.submit();
        });
    });

    const forceDeleteForm = document.getElementById('forceDeleteProductForm');
    document.querySelectorAll('.forceDeleteProductBtn').forEach(btn => {
        btn.addEventListener('click', async () => {
            const name = btn.dataset.name || 'this product';
            if (!await window.showConfirm(`Permanently delete "${name}"? This cannot be undone — there is no way to restore it after this.`, { confirmText: 'Delete forever' })) return;
            forceDeleteForm.action = storeUrl + '/' + btn.dataset.id + '/force';
            forceDeleteForm.submit();
        });
    });

    document.querySelectorAll('.toggleHiddenBtn').forEach(btn => {
        btn.addEventListener('click', () => {
            toggleHiddenForm.action = storeUrl + '/' + btn.dataset.id + '/toggle-hidden';
            toggleHiddenForm.submit();
        });
    });

    const selectedIds     = new Set();
    const bulkBar          = document.getElementById('bulkProductBar');
    const bulkCount         = document.getElementById('bulkProductCount');
    const bulkForm          = document.getElementById('bulkProductForm');
    const bulkActionInput   = document.getElementById('bulkProductAction');
    const bulkTeamSelect    = document.getElementById('bulkProductTeamSelect');

    function updateBulkBar() {
        const n = selectedIds.size;
        if (n > 0) {
            bulkBar.classList.add('open');
            bulkCount.textContent = `${n} selected`;
        } else {
            bulkBar.classList.remove('open');
        }
    }

    document.querySelectorAll('.productCheckbox').forEach(cb => {
        cb.addEventListener('change', () => {
            const id = cb.dataset.id;
            if (cb.checked) selectedIds.add(id); else selectedIds.delete(id);
            updateBulkBar();
        });
    });

    document.getElementById('bulkProductClear').addEventListener('click', () => {
        selectedIds.clear();
        document.querySelectorAll('.productCheckbox').forEach(cb => { cb.checked = false; });
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

    document.getElementById('bulkProductHide').addEventListener('click', () => submitBulk('hide'));
    document.getElementById('bulkProductUnhide').addEventListener('click', () => submitBulk('unhide'));
    document.getElementById('bulkProductMove').addEventListener('click', async () => {
        const n = selectedIds.size;
        const teamName = bulkTeamSelect.options[bulkTeamSelect.selectedIndex].text;
        if (!await window.showConfirm(`Move ${n} product(s) to "${teamName}"? This changes which team's reports they count toward.`, { confirmText: 'Move' })) return;
        submitBulk('move', { team: bulkTeamSelect.value });
    });
    document.getElementById('bulkProductDelete').addEventListener('click', async () => {
        const n = selectedIds.size;
        if (!await window.showConfirm(`Remove ${n} product(s)? You can restore them from the Removed list below.`, { confirmText: 'Remove' })) return;
        submitBulk('delete');
    });
})();
</script>
</body>
</html>
