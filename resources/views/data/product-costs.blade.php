@extends('layouts.data')
@section('title', 'Product Costs')
@section('subtitle', 'Cost per product variation')

@section('content')

<style>
    .pc-table { font-variant-numeric: tabular-nums; }
    .pc-scroll { max-height: calc(100vh - 20rem); min-height: 16rem; overflow: auto; }
    .pc-table thead th { position: sticky; top: 0; z-index: 5; }
    .pc-header-actions { display: flex; align-items: center; gap: 0.25rem; }
    .pc-header-actions button { color: #f1f5f9; background-color: rgba(255,255,255,0.08); }
    .pc-header-actions button:hover { color: #fff; background-color: rgba(255,255,255,0.18); }
</style>

@php
    $fieldClass = 'w-full px-2 py-1 rounded-md border border-transparent bg-transparent text-ink dark:text-slate-100 hover:border-line dark:hover:border-slate-600 focus:border-primary focus:outline-none disabled:hover:border-transparent';
    $addFieldClass = 'w-full px-3 py-2 rounded-lg border border-line dark:border-slate-600 bg-white dark:bg-slate-800 text-sm text-ink dark:text-slate-100 focus:border-primary focus:outline-none';
    $labelClass = 'block text-[11px] font-semibold tracking-widest text-ink-muted dark:text-slate-400 uppercase mb-1';
@endphp

<div class="max-w-3xl space-y-4">
    @unless($locked)
    <form method="POST" action="{{ route('data.product-costs.store') }}"
          class="rounded-2xl border border-line dark:border-slate-700 bg-white dark:bg-slate-900 shadow-panel p-4 flex flex-col sm:flex-row sm:items-end gap-3">
        @csrf
        <div class="flex-1 min-w-0">
            <label for="pcNewVariation" class="{{ $labelClass }}">Variation id</label>
            <input id="pcNewVariation" name="variation" value="{{ old('variation') }}" required maxlength="255" class="{{ $addFieldClass }}">
        </div>
        <div class="sm:w-36">
            <label for="pcNewCost" class="{{ $labelClass }}">Product Costs</label>
            <input id="pcNewCost" name="cost" type="number" min="0" step="0.01" value="{{ old('cost') }}" required class="{{ $addFieldClass }}">
        </div>
        <button type="submit" class="text-sm px-4 py-2 rounded-lg bg-primary text-white font-semibold cursor-pointer">Add</button>
    </form>
    @if($errors->any())
    <p class="text-sm text-red-600 dark:text-red-400">{{ $errors->first() }}</p>
    @endif
    @endunless

    <div class="rounded-2xl border border-line dark:border-slate-700 bg-white dark:bg-slate-900 shadow-panel overflow-hidden">
        <div class="px-6 py-4 bg-slate-900 dark:bg-slate-800 flex items-center justify-between gap-3">
            <h2 class="font-mono font-bold text-sm uppercase tracking-wide text-white">Product Costs</h2>
            <div class="flex items-center gap-3">
                <span id="pcSaveStatus" class="text-xs font-mono text-slate-400 min-h-[1.25rem]"></span>
                <div class="pc-header-actions">
                    @include('partials.table-lock-toggle', ['table' => 'product-costs', 'locked' => $locked])
                    {{-- PNG only: the CSV export reads text, not input values. --}}
                    @include('partials.table-actions', ['target' => 'pcTable', 'name' => 'product-costs', 'title' => 'Product Costs', 'pngOnly' => true])
                </div>
            </div>
        </div>
        <div class="pc-scroll">
            <table id="pcTable" class="w-full text-[13px] pc-table">
                <thead>
                    <tr class="bg-yellow-100 dark:bg-yellow-800 on-accent-800 text-ink dark:text-slate-100">
                        <th class="text-left px-4 py-2.5 font-bold bg-yellow-100 dark:bg-yellow-800">Variation id</th>
                        <th class="text-right px-4 py-2.5 font-bold w-36 whitespace-nowrap bg-yellow-100 dark:bg-yellow-800">Product Costs</th>
                        @unless($locked)<th class="w-12 bg-yellow-100 dark:bg-yellow-800" data-snapshot-hide><span class="sr-only">Remove</span></th>@endunless
                    </tr>
                </thead>
                <tbody class="divide-y divide-line dark:divide-slate-700">
                    @forelse($rows as $row)
                    <tr data-pc-row data-action="{{ route('data.product-costs.update', $row) }}">
                        <td class="px-2 py-1">
                            <input data-pc-field="variation" aria-label="Variation id" value="{{ $row->variation }}" maxlength="255" @disabled($locked) class="{{ $fieldClass }}">
                        </td>
                        <td class="px-2 py-1">
                            <input data-pc-field="cost" aria-label="Product cost for {{ $row->variation }}" type="number" min="0" step="0.01" value="{{ $row->cost }}" @disabled($locked) class="{{ $fieldClass }} text-right">
                        </td>
                        @unless($locked)
                        <td class="px-2 py-1 text-center" data-snapshot-hide>
                            <button type="button" data-pc-remove title="Remove" aria-label="Remove {{ $row->variation }}"
                                    class="w-6 h-6 inline-flex items-center justify-center rounded-full text-slate-400 hover:text-red-600 cursor-pointer">&times;</button>
                        </td>
                        @endunless
                    </tr>
                    @empty
                    <tr>
                        <td colspan="3" class="px-4 py-8 text-center text-ink-muted dark:text-slate-400">No product costs yet.</td>
                    </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
</div>
@endsection

@push('scripts')
<script>
(function () {
    const headers = {
        Accept: 'application/json',
        'Content-Type': 'application/x-www-form-urlencoded',
        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || '',
    };
    const status = document.getElementById('pcSaveStatus');
    const saveTimers = new WeakMap();

    function send(url, params) {
        return fetch(url, { method: 'POST', headers, body: new URLSearchParams(params).toString() })
            .then(async (res) => {
                const data = await res.json().catch(() => ({}));
                if (!res.ok) throw new Error(data.message || 'Could not save — try again.');
                return data;
            });
    }

    function save(input) {
        status.textContent = 'Saving…';
        send(input.closest('[data-pc-row]').dataset.action, { _method: 'PATCH', [input.dataset.pcField]: input.value })
            .then(() => { status.textContent = 'Saved'; })
            .catch((err) => {
                status.textContent = 'Not saved';
                window.showToast?.(err.message, 'error');
            });
    }

    document.addEventListener('input', (e) => {
        const input = e.target.closest('[data-pc-field]');
        if (!input) return;
        clearTimeout(saveTimers.get(input));
        saveTimers.set(input, setTimeout(() => save(input), 600));
    });

    document.addEventListener('click', async (e) => {
        const removeBtn = e.target.closest('[data-pc-remove]');
        if (removeBtn) {
            const row = removeBtn.closest('[data-pc-row]');
            const name = row.querySelector('[data-pc-field="variation"]').value;
            if (!(await window.confirmDataModal(`Remove "${name}" from Product Costs?`))) return;
            send(row.dataset.action, { _method: 'DELETE' })
                .then(() => row.remove())
                .catch((err) => window.showToast?.(err.message, 'error'));
            return;
        }

        const lockBtn = e.target.closest('[data-cb-lock-toggle]');
        if (lockBtn) {
            lockBtn.disabled = true;
            send(@json(\App\Support\DataSet::url('product-costs/lock')), { _method: 'PATCH', locked: lockBtn.dataset.locked === '1' ? '0' : '1' })
                .then(() => location.reload())
                .catch((err) => {
                    lockBtn.disabled = false;
                    window.showToast?.(err.message, 'error');
                });
        }
    });
})();
</script>
@endpush
