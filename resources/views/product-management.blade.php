@extends('layouts.app')
@section('title', 'Product Management')
@section('subtitle', 'Products and which team each one belongs to')

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
        <p class="text-xs text-slate-400 font-mono">Add, edit, or remove products and set which team each belongs to — reflected immediately on TSA Performance and syncing.</p>
        <button type="button" id="addProductBtn"
            class="inline-flex items-center gap-1.5 px-4 py-2 bg-yellow-700 hover:bg-yellow-800 text-white text-xs font-semibold rounded-lg transition-colors cursor-pointer whitespace-nowrap shrink-0 ml-4">
            <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/>
            </svg>
            Add Product
        </button>
    </div>

    @foreach($teamGroups as $group)
    <div class="bg-white rounded-xl border border-yellow-100 shadow-sm overflow-hidden">
        <div class="px-6 py-4 border-b border-slate-100">
            <h3 class="text-sm font-semibold text-slate-800">{{ $group['name'] }}</h3>
            <p class="text-xs text-slate-500 mt-0.5">{{ $group['products']->count() }} {{ \Illuminate\Support\Str::plural('product', $group['products']->count()) }}</p>
        </div>

        @if($group['products']->isEmpty())
        <div class="py-10 text-center text-sm text-slate-400 font-mono">No products for this team yet</div>
        @else
        <div class="divide-y divide-slate-100">
            @foreach($group['products'] as $product)
            <div class="px-6 py-3 flex items-center gap-4 {{ $product->is_hidden ? 'opacity-50' : '' }}">
                <div class="flex-1">
                    <div class="flex items-center gap-2">
                        <p class="text-sm font-mono font-semibold text-slate-700">{{ $product->display_name }}</p>
                        @if($product->is_hidden)
                        <span class="text-[10px] font-semibold text-slate-500 bg-slate-100 px-1.5 py-0.5 rounded">Hidden</span>
                        @endif
                    </div>
                    @if($product->match_keyword)
                    <p class="text-[10px] text-slate-400 font-mono">matches: {{ $product->match_keyword }}</p>
                    @endif
                </div>

                <div class="flex items-center gap-1 shrink-0">
                    <button type="button"
                        class="editProductBtn p-1.5 rounded-lg text-slate-400 hover:text-yellow-600 hover:bg-yellow-50 transition-colors cursor-pointer"
                        title="Edit"
                        data-id="{{ $product->id }}"
                        data-display-name="{{ $product->display_name }}"
                        data-match-keyword="{{ $product->match_keyword }}"
                        data-team="{{ $product->team }}">
                        <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"/>
                        </svg>
                    </button>
                    <button type="button"
                        class="toggleHiddenBtn p-1.5 rounded-lg text-slate-400 hover:text-blue-600 hover:bg-blue-50 transition-colors cursor-pointer"
                        title="{{ $product->is_hidden ? 'Unhide' : 'Hide' }}"
                        data-id="{{ $product->id }}">
                        @if($product->is_hidden)
                        <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3.98 8.223A10.477 10.477 0 001.934 12C3.226 16.338 7.244 19.5 12 19.5c.993 0 1.953-.138 2.863-.395M6.228 6.228A10.45 10.45 0 0112 4.5c4.756 0 8.773 3.162 10.065 7.498a10.523 10.523 0 01-4.293 5.774M6.228 6.228L3 3m3.228 3.228l3.65 3.65m7.894 7.894L21 21m-3.228-3.228l-3.65-3.65m0 0a3 3 0 10-4.243-4.243m4.242 4.242L9.88 9.88"/>
                        </svg>
                        @else
                        <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.036 12.322a1.012 1.012 0 010-.639C3.423 7.51 7.36 4.5 12 4.5c4.638 0 8.573 3.007 9.963 7.178.07.207.07.431 0 .639C20.577 16.49 16.64 19.5 12 19.5c-4.638 0-8.573-3.007-9.963-7.178z"/>
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/>
                        </svg>
                        @endif
                    </button>
                    <button type="button"
                        class="deleteProductBtn p-1.5 rounded-lg text-slate-400 hover:text-red-600 hover:bg-red-50 transition-colors cursor-pointer"
                        title="Remove"
                        data-id="{{ $product->id }}"
                        data-name="{{ $product->display_name }}">
                        <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6M9 7V4a1 1 0 011-1h4a1 1 0 011 1v3M4 7h16"/>
                        </svg>
                    </button>
                </div>
            </div>
            @endforeach
        </div>
        @endif
    </div>
    @endforeach

    @if($unassigned->isNotEmpty())
    <div class="bg-yellow-50 border border-yellow-200 rounded-xl px-6 py-4">
        <p class="text-xs font-semibold text-yellow-800 mb-1">Unassigned team</p>
        <p class="text-xs text-yellow-700">{{ $unassigned->pluck('display_name')->join(', ') }} — team value doesn't match a configured team.</p>
    </div>
    @endif

</div>

{{-- Shared Add / Edit modal --}}
<div id="productModal" class="hidden fixed inset-0 z-50 flex items-center justify-center bg-slate-900/40 px-4">
    <div class="bg-white rounded-2xl shadow-2xl w-full max-w-sm overflow-hidden">
        <div class="px-6 py-5 border-b border-slate-100">
            <h3 id="productModalTitle" class="text-sm font-bold text-slate-800">Add a new product</h3>
            <p id="productModalSubtitle" class="text-xs text-slate-500 mt-0.5">Recognized starting with the next sync</p>
        </div>
        <form id="productForm" method="POST" action="{{ route('product-management.store') }}" class="px-6 py-5 space-y-4">
            @csrf
            <input type="hidden" name="_method" id="productFormMethod" value="">

            <div>
                <label class="block text-xs font-semibold text-slate-700 mb-1">Display name</label>
                <input type="text" id="productNameInput" name="display_name" required
                    class="w-full rounded-lg border border-slate-200 px-3 py-2 text-sm text-slate-900 focus:outline-none focus:ring-2 focus:ring-yellow-500">
            </div>

            <div>
                <label class="block text-xs font-semibold text-slate-700 mb-1">Team</label>
                <select name="team" id="productTeamSelect" required
                    class="w-full rounded-lg border border-slate-200 px-3 py-2 text-sm text-slate-900 focus:outline-none focus:ring-2 focus:ring-yellow-500">
                    @foreach($teamsConfig as $team)
                    <option value="{{ $team['order_team'] }}">{{ $team['name'] }}</option>
                    @endforeach
                </select>
            </div>

            <div>
                <label class="block text-xs font-semibold text-slate-700 mb-1">
                    Match keywords <span class="text-slate-400 font-normal">(optional, comma-separated)</span>
                </label>
                <input type="text" name="match_keyword" id="productKeywordInput"
                    placeholder="e.g. PTERYGIUM, PteryFix — every cart-name variant of this product"
                    class="w-full rounded-lg border border-slate-200 px-3 py-2 text-sm text-slate-900 focus:outline-none focus:ring-2 focus:ring-yellow-500">
                <p class="text-[11px] text-slate-400 mt-1">Leave blank to match on the display name itself. Matching ignores case, spaces and punctuation, and an order counts if it matches ANY keyword — add every alias the POS cart uses, or unclaimed leads for that variant won't be attributed to your team.</p>
            </div>

            <div class="flex items-center justify-end gap-2 pt-2">
                <button type="button" id="cancelProductModal" class="px-3 py-2 text-xs font-mono text-slate-600 hover:text-slate-800 border border-slate-200 rounded-lg hover:bg-slate-50 transition-colors cursor-pointer">Cancel</button>
                <button type="submit" id="productSubmitBtn" class="px-4 py-2 text-xs font-semibold text-white bg-yellow-700 hover:bg-yellow-800 rounded-lg transition-colors cursor-pointer">Add Product</button>
            </div>
        </form>
    </div>
</div>

<form id="deleteProductForm" method="POST" style="display:none">
    @csrf
    @method('DELETE')
</form>

<form id="toggleHiddenProductForm" method="POST" style="display:none">
    @csrf
    @method('PATCH')
</form>

@push('scripts')
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
    const submitBtn   = document.getElementById('productSubmitBtn');
    const storeUrl    = form.action;
    const toggleHiddenForm = document.getElementById('toggleHiddenProductForm');

    function openModal() { modal.classList.remove('hidden'); }
    function closeModal() { modal.classList.add('hidden'); }

    function resetForm() {
        form.action = storeUrl;
        methodInput.value = '';
        nameInput.value = '';
        keywordInput.value = '';
        teamSelect.selectedIndex = 0;
        modalTitle.textContent = 'Add a new product';
        modalSubtitle.textContent = 'Recognized starting with the next sync';
        submitBtn.textContent = 'Add Product';
    }

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
        btn.addEventListener('click', () => {
            const name = btn.dataset.name || 'this product';
            if (!confirm(`Remove "${name}"? This can't be undone.`)) return;
            deleteForm.action = storeUrl + '/' + btn.dataset.id;
            deleteForm.submit();
        });
    });

    document.querySelectorAll('.toggleHiddenBtn').forEach(btn => {
        btn.addEventListener('click', () => {
            toggleHiddenForm.action = storeUrl + '/' + btn.dataset.id + '/toggle-hidden';
            toggleHiddenForm.submit();
        });
    });
})();
</script>
@endpush
@endsection
