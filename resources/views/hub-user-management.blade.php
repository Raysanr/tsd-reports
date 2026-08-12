<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>User Management · Seller's Hub TSD</title>
<link rel="icon" href="data:image/svg+xml,<svg xmlns=%22http://www.w3.org/2000/svg%22 viewBox=%220 0 24 24%22><text y=%22.9em%22 font-size=%2222%22>👤</text></svg>">
<style>
    /* Same design tokens/fonts as hub.blade.php — this page is reached
       FROM the Hub and should read as the same product, not a jump back
       into the internal dashboard's dark-sidebar chrome. */
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
        max-width: 760px;
        margin: 0 auto;
        padding: 48px clamp(24px, 4vw, 72px) 72px;
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

    .list {
        background: #fff;
        border-radius: 16px;
        box-shadow: 0 1px 2px rgba(15, 23, 42, 0.04), 0 14px 28px -18px rgba(133,77,14,0.16);
        overflow: hidden;
    }
    .row {
        padding: 16px 22px;
        display: flex; align-items: center; gap: 16px;
        border-bottom: 1px solid #f1f5f9;
    }
    .row:last-child { border-bottom: none; }
    .row.inactive { opacity: 0.5; }
    .row .who { flex: 1; min-width: 0; }
    .row .name-line { display: flex; align-items: center; gap: 8px; flex-wrap: wrap; }
    .row .name { font-family: 'Fira Code', monospace; font-weight: 600; font-size: 14px; color: #1e293b; }
    .badge {
        font-family: 'Fira Code', monospace; font-size: 10px; font-weight: 700;
        letter-spacing: 0.04em; text-transform: uppercase;
        background: rgba(202,138,4,0.12); color: var(--primary-dark);
        padding: 2px 7px; border-radius: 5px; flex-shrink: 0;
    }
    .badge.off { background: #f1f5f9; color: #64748b; }
    .row .email { font-family: 'Fira Code', monospace; font-size: 11.5px; color: #94a3b8; margin-top: 2px; }
    .row .actions { display: flex; align-items: center; gap: 4px; flex-shrink: 0; }
    .icon-btn {
        width: 32px; height: 32px; border-radius: 8px; border: none; background: none;
        display: flex; align-items: center; justify-content: center;
        color: #94a3b8; cursor: pointer; transition: background 150ms ease, color 150ms ease;
    }
    .icon-btn:hover { background: rgba(202,138,4,0.1); color: var(--primary-dark); }
    .icon-btn.danger:hover { background: #fef2f2; color: #b91c1c; }
    .icon-btn svg { width: 15px; height: 15px; }

    .empty { padding: 48px 22px; text-align: center; color: #94a3b8; font-size: 14px; }

    /* Modal — same pattern as user-management.blade.php's, restyled to this
       page's own visual language instead of the internal dashboard's. */
    .modal-backdrop {
        display: none;
        position: fixed; inset: 0; z-index: 50;
        background: rgba(15,23,42,0.45);
        align-items: center; justify-content: center; padding: 16px;
    }
    .modal-backdrop.open { display: flex; }
    .modal {
        background: #fff; border-radius: 18px; width: 100%; max-width: 420px;
        overflow: hidden; box-shadow: 0 24px 60px rgba(15,23,42,0.25);
    }
    .modal-head { padding: 24px 26px 18px; border-bottom: 1px solid #f1f5f9; }
    .modal-head h3 { margin: 0; font-size: 17px; font-weight: 700; color: #0f172a; }
    .modal-head p { margin: 6px 0 0; font-size: 12.5px; color: #94a3b8; }
    .modal-body { padding: 22px 26px; display: flex; flex-direction: column; gap: 16px; }
    .field label { display: block; font-size: 12.5px; font-weight: 700; color: #334155; margin-bottom: 6px; }
    .field input, .field select {
        width: 100%; font-family: inherit; font-size: 14px;
        border: 1px solid #e2e8f0; border-radius: 10px; padding: 10px 12px;
        color: #0f172a; background: #fff;
    }
    .field input:focus, .field select:focus { outline: 2px solid var(--primary); outline-offset: 1px; }
    .field .hint { font-size: 11px; color: #94a3b8; margin-top: 5px; line-height: 1.4; }
    .modal-actions { display: flex; justify-content: flex-end; gap: 10px; padding-top: 4px; }
    .btn-ghost {
        font-family: inherit; font-size: 13px; font-weight: 600; color: #64748b;
        background: none; border: 1px solid #e2e8f0; border-radius: 9px;
        padding: 9px 16px; cursor: pointer;
    }
    .btn-ghost:hover { background: #f8fafc; }

    @media (max-width: 640px) {
        header { padding: 24px 20px 0; }
        main { padding: 32px 20px 56px; }
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
    <h1>User Management</h1>
    <p class="subtitle">Accounts and roles across the telesales operation. Add teammates by their Google account email — that's how they sign in, no password to set.</p>

    @if(session('success'))
    <div class="flash success">{{ session('success') }}</div>
    @endif
    @if($errors->any())
    <div class="flash error">
        @foreach($errors->all() as $error)<div>{{ $error }}</div>@endforeach
    </div>
    @endif

    <div class="toolbar">
        <p>{{ $users->count() }} {{ \Illuminate\Support\Str::plural('account', $users->count()) }}</p>
        <button type="button" id="addUserBtn" class="btn-primary">
            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4v16m8-8H4"/></svg>
            Add User
        </button>
    </div>

    <div class="list">
        @forelse($users as $user)
        <div class="row {{ $user->is_active ? '' : 'inactive' }}">
            <div class="who">
                <div class="name-line">
                    <span class="name">{{ $user->name }}</span>
                    <span class="badge">{{ \App\Models\User::ROLE_LABELS[$user->role] ?? $user->role }}</span>
                    @if(!$user->is_active)
                    <span class="badge off">Deactivated</span>
                    @endif
                </div>
                <div class="email">{{ $user->email }}</div>
            </div>

            @if(auth()->user()->canManage($user))
            <div class="actions">
                <button type="button" class="icon-btn editUserBtn" title="Edit"
                    data-id="{{ $user->id }}" data-name="{{ $user->name }}" data-email="{{ $user->email }}" data-role="{{ $user->role }}">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"/></svg>
                </button>
                <button type="button" class="icon-btn {{ $user->is_active ? 'danger' : '' }} toggleActiveBtn" title="{{ $user->is_active ? 'Deactivate' : 'Reactivate' }}" data-id="{{ $user->id }}">
                    @if($user->is_active)
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M18.36 6.64a9 9 0 11-12.73 0M12 3v9"/></svg>
                    @else
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M14.828 14.828a4 4 0 01-5.656 0M9 10h1.5m5.5 0h-1.5M3 12a9 9 0 1118 0 9 9 0 01-18 0z"/></svg>
                    @endif
                </button>
            </div>
            @endif
        </div>
        @empty
        <div class="empty">No accounts yet.</div>
        @endforelse
    </div>
</main>

<div id="userModal" class="modal-backdrop">
    <div class="modal">
        <div class="modal-head">
            <h3 id="userModalTitle">Add a new user</h3>
            <p id="userModalSubtitle">They sign in with "Sign in with Google" using this email</p>
        </div>
        <form id="userForm" method="POST" action="{{ route('hub.users.store') }}">
            @csrf
            <input type="hidden" name="_method" id="userFormMethod" value="">
            <input type="hidden" name="_redirect_route" value="hub.users">
            <div class="modal-body">
                <div class="field">
                    <label>Full name</label>
                    <input type="text" id="userNameInput" name="name" required>
                </div>
                <div class="field">
                    <label>Google account email</label>
                    <input type="email" id="userEmailInput" name="email" required placeholder="their.name@gmail.com">
                    <p class="hint">Must be the exact email on their Google account — that's how they'll sign in.</p>
                </div>
                <div class="field">
                    <label>Role</label>
                    <select name="role" id="userRoleSelect" required>
                        <option value="" disabled selected>Select a role…</option>
                        @foreach($assignableRoles as $role)
                        <option value="{{ $role }}">{{ \App\Models\User::ROLE_LABELS[$role] }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="modal-actions">
                    <button type="button" id="cancelUserModal" class="btn-ghost">Cancel</button>
                    <button type="submit" id="userSubmitBtn" class="btn-primary">Add User</button>
                </div>
            </div>
        </form>
    </div>
</div>

<form id="toggleActiveUserForm" method="POST" style="display:none">
    @csrf
    @method('PATCH')
    <input type="hidden" name="_redirect_route" value="hub.users">
</form>

<script>
(function () {
    const modal        = document.getElementById('userModal');
    const modalTitle    = document.getElementById('userModalTitle');
    const modalSubtitle = document.getElementById('userModalSubtitle');
    const form          = document.getElementById('userForm');
    const methodInput   = document.getElementById('userFormMethod');
    const nameInput     = document.getElementById('userNameInput');
    const emailInput    = document.getElementById('userEmailInput');
    const roleSelect    = document.getElementById('userRoleSelect');
    const submitBtn     = document.getElementById('userSubmitBtn');
    const storeUrl      = form.action;
    const toggleActiveForm = document.getElementById('toggleActiveUserForm');

    function openModal() { modal.classList.add('open'); }
    function closeModal() { modal.classList.remove('open'); }

    function resetForm() {
        form.action = storeUrl;
        methodInput.value = '';
        nameInput.value = '';
        emailInput.value = '';
        roleSelect.selectedIndex = 0;
        modalTitle.textContent = 'Add a new user';
        modalSubtitle.textContent = 'They sign in with "Sign in with Google" using this email';
        submitBtn.textContent = 'Add User';
    }

    document.getElementById('addUserBtn').addEventListener('click', () => { resetForm(); openModal(); });
    document.getElementById('cancelUserModal').addEventListener('click', closeModal);
    modal.addEventListener('click', (e) => { if (e.target === modal) closeModal(); });
    document.addEventListener('keydown', (e) => { if (e.key === 'Escape') closeModal(); });

    document.querySelectorAll('.editUserBtn').forEach(btn => {
        btn.addEventListener('click', () => {
            resetForm();
            const id = btn.dataset.id;
            form.action = storeUrl + '/' + id;
            methodInput.value = 'PUT';
            nameInput.value = btn.dataset.name || '';
            emailInput.value = btn.dataset.email || '';
            roleSelect.value = btn.dataset.role || '';
            modalTitle.textContent = 'Edit user';
            modalSubtitle.textContent = 'Changes apply immediately';
            submitBtn.textContent = 'Save Changes';
            openModal();
        });
    });

    document.querySelectorAll('.toggleActiveBtn').forEach(btn => {
        btn.addEventListener('click', () => {
            toggleActiveForm.action = storeUrl + '/' + btn.dataset.id + '/toggle-active';
            toggleActiveForm.submit();
        });
    });
})();
</script>
</body>
</html>
