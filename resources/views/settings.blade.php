@extends('layouts.app')
@section('title', 'Settings')
@section('subtitle', 'Connect your Pancake POS account')

@section('content')
<div class="max-w-2xl space-y-6">

    @if(session('success'))
    <div class="flex items-center gap-3 bg-green-50 border border-green-200 rounded-xl px-5 py-4">
        <svg class="w-4 h-4 text-green-500 shrink-0" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7"/>
        </svg>
        <p class="text-sm font-mono text-green-700">{{ session('success') }}</p>
    </div>
    @endif

    {{-- Step 1: Paste API Key --}}
    <div class="bg-white rounded-xl border border-yellow-100 shadow-sm overflow-hidden">
        <div class="px-6 py-5 border-b border-slate-100">
            <div class="flex items-center gap-3">
                <div class="w-7 h-7 rounded-full bg-yellow-700 text-white text-xs font-bold flex items-center justify-center">1</div>
                <div>
                    <h3 class="text-sm font-semibold text-slate-800">Paste your Pancake POS API Key</h3>
                    <p class="text-xs text-slate-500 mt-0.5">Settings → App Settings → API Key → Create</p>
                </div>
            </div>
        </div>
        <div class="px-6 py-5">
            <div class="flex gap-3">
                <div class="relative flex-1">
                    <input type="password" id="apiKeyInput"
                        placeholder="Paste your API key here..."
                        value="{{ $apiKey }}"
                        class="w-full rounded-lg border border-slate-200 px-3.5 py-2.5 pr-10 text-sm font-mono text-slate-900 placeholder:text-slate-400 focus:outline-none focus:ring-2 focus:ring-yellow-500 focus:border-transparent">
                    <button type="button"
                        onclick="(function(){
                            var inp=document.getElementById('apiKeyInput');
                            var icon=document.getElementById('eyeIcon');
                            if(inp.type==='password'){inp.type='text';icon.innerHTML='<path stroke-linecap=\'round\' stroke-linejoin=\'round\' stroke-width=\'2\' d=\'M13.875 18.825A10.05 10.05 0 0112 19c-4.478 0-8.268-2.943-9.543-7a9.97 9.97 0 011.563-3.029m5.858.908a3 3 0 114.243 4.243M9.878 9.878l4.242 4.242M9.88 9.88l-3.29-3.29m7.532 7.532l3.29 3.29M3 3l3.59 3.59m0 0A9.953 9.953 0 0112 5c4.478 0 8.268 2.943 9.543 7a10.025 10.025 0 01-4.132 5.411m0 0L21 21\'/>\';}else{inp.type='password';icon.innerHTML='<path stroke-linecap=\'round\' stroke-linejoin=\'round\' stroke-width=\'2\' d=\'M15 12a3 3 0 11-6 0 3 3 0 016 0z\'/><path stroke-linecap=\'round\' stroke-linejoin=\'round\' stroke-width=\'2\' d=\'M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z\'/>\';}
                        })()"
                        style="position:absolute;right:10px;top:50%;transform:translateY(-50%);background:none;border:none;cursor:pointer;color:#94a3b8;padding:2px;">
                        <svg id="eyeIcon" class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/>
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"/>
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
        </div>
    </div>

    {{-- Step 2: Shop confirmation (shown after detect or when already connected) --}}
    <div id="step2-card" class="bg-white rounded-xl border border-yellow-100 shadow-sm overflow-hidden {{ $shopId ? '' : 'hidden' }}">
        <div class="px-6 py-5 border-b border-slate-100">
            <div class="flex items-center gap-3">
                <div class="w-7 h-7 rounded-full bg-yellow-700 text-white text-xs font-bold flex items-center justify-center">2</div>
                <div>
                    <h3 class="text-sm font-semibold text-slate-800">Confirm your shop</h3>
                    <p class="text-xs text-slate-500 mt-0.5">All information is auto-detected from your API key</p>
                </div>
            </div>
        </div>

        {{-- Shop info display (read-only, NOT inside the save form) --}}
        <div id="shopInfoDisplay" class="px-6 pt-5">
            @if($shopId)
            <div class="flex items-start gap-4 p-4 bg-green-50 border border-green-200 rounded-xl">
                <div class="w-12 h-12 rounded-xl bg-yellow-100 flex items-center justify-center flex-shrink-0 text-yellow-700 font-bold text-lg">
                    {{ strtoupper(substr($shopName ?: 'S', 0, 1)) }}
                </div>
                <div class="flex-1 min-w-0">
                    <div class="flex items-center gap-2">
                        <p class="text-sm font-bold text-slate-900">{{ $shopName }}</p>
                        <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-xs font-medium bg-green-100 text-green-700">
                            <span class="w-1.5 h-1.5 rounded-full bg-green-500 inline-block"></span>
                            Connected
                        </span>
                    </div>
                    <p class="text-xs text-slate-500 mt-0.5 font-mono">Shop ID: {{ $shopId }}</p>
                    @if($lastSynced)
                    <p class="text-xs text-slate-400 mt-0.5">Last synced: {{ \Carbon\Carbon::parse($lastSynced)->diffForHumans() }}</p>
                    @else
                    <p class="text-xs text-slate-400 mt-0.5">Last synced: Never</p>
                    @endif
                </div>
                {{-- Disconnect is its OWN standalone form — never nested --}}
                <form method="POST" action="{{ route('settings.clear') }}">
                    @csrf
                    <button type="submit"
                            class="px-3 py-1.5 text-xs font-semibold font-mono text-red-500 border border-red-200 rounded-lg hover:bg-red-50 transition-colors cursor-pointer">
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
            <input type="hidden" name="api_key"   id="formApiKey"   value="{{ $apiKey }}">
            <input type="hidden" name="shop_id"   id="formShopId"   value="{{ $shopId }}">
            <input type="hidden" name="shop_name" id="formShopName" value="{{ $shopName }}">

            @if($errors->any())
            <div class="mx-6 mt-4 px-4 py-3 bg-red-50 border border-red-200 rounded-lg text-sm text-red-700">
                @foreach($errors->all() as $error)<div>{{ $error }}</div>@endforeach
            </div>
            @endif

            <div class="px-6 py-5 flex items-center gap-3 border-t border-slate-100 mt-4">
                <div class="flex-1">
                    <label class="block text-xs font-semibold text-slate-700 mb-1">Sync interval (minutes)</label>
                    <select name="sync_interval" class="block w-full rounded-lg border border-slate-200 px-3 py-2 text-sm text-slate-900 focus:outline-none focus:ring-2 focus:ring-yellow-500">
                        <option value="1"  {{ $syncInterval == 1  ? 'selected' : '' }}>Every minute</option>
                        <option value="5"  {{ $syncInterval == 5  ? 'selected' : '' }}>Every 5 minutes</option>
                        <option value="15" {{ $syncInterval == 15 ? 'selected' : '' }}>Every 15 minutes</option>
                        <option value="30" {{ $syncInterval == 30 ? 'selected' : '' }}>Every 30 minutes</option>
                        <option value="60" {{ $syncInterval == 60 ? 'selected' : '' }}>Every hour</option>
                    </select>
                </div>
                <button type="submit" id="connectBtn"
                    class="inline-flex items-center gap-2 px-5 py-2.5 bg-yellow-700 hover:bg-yellow-800 text-white text-sm font-semibold rounded-lg transition-colors cursor-pointer mt-4">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/>
                    </svg>
                    {{ $shopId ? 'Save Settings' : 'Connect Shop' }}
                </button>
            </div>
        </form>
    </div>

    {{-- How to get API Key --}}
    <div class="bg-yellow-50 border border-yellow-100 rounded-xl p-5">
        <h4 class="text-xs font-semibold text-yellow-800 uppercase tracking-wider mb-3">How to get your Pancake POS API Key</h4>
        <ol class="space-y-2 text-xs text-yellow-700">
            <li class="flex gap-2.5">
                <span class="w-5 h-5 rounded-full bg-yellow-200 text-yellow-800 font-bold flex-shrink-0 flex items-center justify-center">1</span>
                Log in to <strong>pos.pages.fm</strong> and open your shop
            </li>
            <li class="flex gap-2.5">
                <span class="w-5 h-5 rounded-full bg-yellow-200 text-yellow-800 font-bold flex-shrink-0 flex items-center justify-center">2</span>
                Go to <strong>Settings → App Settings → API Key</strong>
            </li>
            <li class="flex gap-2.5">
                <span class="w-5 h-5 rounded-full bg-yellow-200 text-yellow-800 font-bold flex-shrink-0 flex items-center justify-center">3</span>
                Click <strong>Create</strong> to generate your key, then copy it
            </li>
            <li class="flex gap-2.5">
                <span class="w-5 h-5 rounded-full bg-yellow-200 text-yellow-800 font-bold flex-shrink-0 flex items-center justify-center">4</span>
                Paste it above — your shop info will be <strong>auto-detected</strong>
            </li>
        </ol>
    </div>

</div>
@endsection

@push('scripts')
<script>
const detectBtn    = document.getElementById('detectBtn');
const apiInput     = document.getElementById('apiKeyInput');
const statusEl     = document.getElementById('detectStatus');
const step2Card    = document.getElementById('step2-card');
const shopDisplay  = document.getElementById('shopInfoDisplay');
const formApiKey   = document.getElementById('formApiKey');
const formShopId   = document.getElementById('formShopId');
const formShopName = document.getElementById('formShopName');
const connectBtn   = document.getElementById('connectBtn');

detectBtn.addEventListener('click', async () => {
    const key = apiInput.value.trim();
    if (!key) { showStatus('error', 'Please paste your API key first.'); return; }

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
    <div class="flex items-start gap-4 p-4 bg-green-50 border border-green-200 rounded-xl">
        <div class="w-12 h-12 rounded-xl bg-yellow-100 flex items-center justify-center flex-shrink-0 text-yellow-700 font-bold text-lg">
            ${shop.name.charAt(0).toUpperCase()}
        </div>
        <div>
            <div class="flex items-center gap-2">
                <p class="text-sm font-bold text-slate-900">${shop.name}</p>
                <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-xs font-medium bg-green-100 text-green-700">
                    <span class="w-1.5 h-1.5 rounded-full bg-green-500 inline-block"></span>
                    Detected
                </span>
            </div>
            <p class="text-xs text-slate-500 mt-0.5 font-mono">Shop ID: ${shop.id}</p>
        </div>
    </div>`;
}

function showStatus(type, msg) {
    statusEl.classList.remove('hidden');
    const styles = { loading: 'text-yellow-600', success: 'text-green-600', error: 'text-red-600' };
    statusEl.className = `mt-3 text-xs ${styles[type] ?? 'text-slate-600'}`;
    statusEl.textContent = msg;
}
</script>
@endpush
