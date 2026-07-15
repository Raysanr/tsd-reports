@extends('layouts.app')
@section('title', 'User Management')
@section('subtitle', 'Accounts and roles')

@section('content')
<div class="max-w-3xl space-y-6">

    @if(session('success'))
    <div class="flex items-center gap-3 bg-green-50 border border-green-200 rounded-xl px-5 py-4">
        <svg class="w-4 h-4 text-green-500 shrink-0" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7"/>
        </svg>
        <p class="text-sm font-mono text-green-700">{{ session('success') }}</p>
    </div>
    @endif

    @if($errors->any())
    <div class="px-5 py-4 bg-red-50 border border-red-200 rounded-xl text-sm text-red-700">
        @foreach($errors->all() as $error)<div>{{ $error }}</div>@endforeach
    </div>
    @endif

    <div class="flex items-center justify-between">
        <p class="text-xs text-slate-400 font-mono">Add teammates by their Google account email, and set what they're allowed to see and do.</p>
        <button type="button" id="addUserBtn"
            class="inline-flex items-center gap-1.5 px-4 py-2 bg-yellow-700 hover:bg-yellow-800 text-white text-xs font-semibold rounded-lg transition-colors cursor-pointer whitespace-nowrap shrink-0 ml-4">
            <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/>
            </svg>
            Add User
        </button>
    </div>

    <div class="bg-white rounded-xl border border-yellow-100 shadow-sm overflow-hidden">
        <div class="divide-y divide-slate-100">
            @foreach($users as $user)
            <div class="px-6 py-3 flex items-center gap-4 {{ $user->is_active ? '' : 'opacity-50' }}">
                <div class="flex-1 min-w-0">
                    <div class="flex items-center gap-2">
                        <p class="text-sm font-mono font-semibold text-slate-700 truncate">{{ $user->name }}</p>
                        <span class="text-[10px] font-semibold text-yellow-800 bg-yellow-100 px-1.5 py-0.5 rounded shrink-0">{{ \App\Models\User::ROLE_LABELS[$user->role] ?? $user->role }}</span>
                        @if(!$user->is_active)
                        <span class="text-[10px] font-semibold text-slate-500 bg-slate-100 px-1.5 py-0.5 rounded shrink-0">Deactivated</span>
                        @endif
                    </div>
                    <p class="text-[11px] text-slate-400 font-mono truncate">{{ $user->email }}</p>
                </div>

                @if(auth()->user()->canManage($user))
                <div class="flex items-center gap-1 shrink-0">
                    <button type="button"
                        class="editUserBtn p-1.5 rounded-lg text-slate-400 hover:text-yellow-600 hover:bg-yellow-50 transition-colors cursor-pointer"
                        title="Edit"
                        data-id="{{ $user->id }}"
                        data-name="{{ $user->name }}"
                        data-email="{{ $user->email }}"
                        data-role="{{ $user->role }}">
                        <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"/>
                        </svg>
                    </button>
                    <button type="button"
                        class="toggleActiveBtn p-1.5 rounded-lg text-slate-400 hover:text-blue-600 hover:bg-blue-50 transition-colors cursor-pointer"
                        title="{{ $user->is_active ? 'Deactivate' : 'Reactivate' }}"
                        data-id="{{ $user->id }}">
                        @if($user->is_active)
                        <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M18.36 6.64a9 9 0 11-12.73 0M12 3v9"/>
                        </svg>
                        @else
                        <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M14.828 14.828a4 4 0 01-5.656 0M9 10h1.5m5.5 0h-1.5M3 12a9 9 0 1118 0 9 9 0 01-18 0z"/>
                        </svg>
                        @endif
                    </button>
                </div>
                @endif
            </div>
            @endforeach
        </div>
    </div>
</div>

{{-- Shared Add / Edit modal --}}
<div id="userModal" class="hidden fixed inset-0 z-50 flex items-center justify-center bg-slate-900/40 px-4">
    <div class="bg-white rounded-2xl shadow-2xl w-full max-w-sm overflow-hidden">
        <div class="px-6 py-5 border-b border-slate-100">
            <h3 id="userModalTitle" class="text-sm font-bold text-slate-800">Add a new user</h3>
            <p id="userModalSubtitle" class="text-xs text-slate-500 mt-0.5">They sign in with "Sign in with Google" using this email</p>
        </div>
        <form id="userForm" method="POST" action="{{ route('user-management.store') }}" class="px-6 py-5 space-y-4">
            @csrf
            <input type="hidden" name="_method" id="userFormMethod" value="">

            <div>
                <label class="block text-xs font-semibold text-slate-700 mb-1">Full name</label>
                <input type="text" id="userNameInput" name="name" required
                    class="w-full rounded-lg border border-slate-200 px-3 py-2 text-sm text-slate-900 focus:outline-none focus:ring-2 focus:ring-yellow-500">
            </div>

            <div>
                <label class="block text-xs font-semibold text-slate-700 mb-1">Google account email</label>
                <input type="email" id="userEmailInput" name="email" required
                    placeholder="their.name@gmail.com"
                    class="w-full rounded-lg border border-slate-200 px-3 py-2 text-sm text-slate-900 focus:outline-none focus:ring-2 focus:ring-yellow-500">
                <p class="text-[11px] text-slate-400 mt-1">Must be the exact email on their Google account — that's how they'll sign in.</p>
            </div>

            <div>
                <label class="block text-xs font-semibold text-slate-700 mb-1">Role</label>
                <select name="role" id="userRoleSelect" required
                    class="w-full rounded-lg border border-slate-200 px-3 py-2 text-sm text-slate-900 focus:outline-none focus:ring-2 focus:ring-yellow-500">
                    @foreach($assignableRoles as $role)
                    <option value="{{ $role }}">{{ \App\Models\User::ROLE_LABELS[$role] }}</option>
                    @endforeach
                </select>
            </div>

            <div class="flex items-center justify-end gap-2 pt-2">
                <button type="button" id="cancelUserModal" class="px-3 py-2 text-xs font-mono text-slate-600 hover:text-slate-800 border border-slate-200 rounded-lg hover:bg-slate-50 transition-colors cursor-pointer">Cancel</button>
                <button type="submit" id="userSubmitBtn" class="px-4 py-2 text-xs font-semibold text-white bg-yellow-700 hover:bg-yellow-800 rounded-lg transition-colors cursor-pointer">Add User</button>
            </div>
        </form>
    </div>
</div>

<form id="toggleActiveUserForm" method="POST" style="display:none">
    @csrf
    @method('PATCH')
</form>

@push('scripts')
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

    function openModal() { modal.classList.remove('hidden'); }
    function closeModal() { modal.classList.add('hidden'); }

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
@endpush
@endsection
