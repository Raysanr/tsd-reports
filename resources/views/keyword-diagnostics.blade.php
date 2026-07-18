@extends('layouts.app')
@section('title', 'Keyword Diagnostics')
@section('subtitle', 'Find ambiguous keyword configuration before it silently misattributes an order')

@section('content')

{{-- HEADER EXPLANATION — this page's whole point: two TSAs or two products
     configured with overlapping keywords means an order that matches either
     one silently attributes to whichever wins iteration order (see
     SyncTodayOrders::loadTsaMaps/extractTsaInfo, Product::matchesText) —
     nothing errors, nothing warns, it's just quietly wrong. --}}
<div class="mb-6 bg-white dark:bg-slate-900 rounded-xl border border-slate-200 dark:border-slate-700 shadow-sm px-5 py-4">
    <p class="text-sm text-slate-600 dark:text-slate-300 leading-relaxed">
        Checks TSA and product keyword configuration for ambiguity — two entries set up so an order could match
        either one, with only one silently winning. TSA name tags match <span class="font-mono font-semibold">exactly</span>
        (a duplicate tag on two TSAs is the conflict); TSA seller accounts and product keywords both match by
        <span class="font-mono font-semibold">substring</span> (one keyword containing another is the conflict). Use the tool
        below to test a real tag or cart name against all three matching mechanisms at once before saving a new keyword.
    </p>
</div>

{{-- TEST THIS KEYWORD — live AJAX tool, debounced like the topbar global
     search (resources/js/app.js). Runs the sample through all three real
     matching mechanisms and shows EVERY match found, not just the one that
     would silently win, so ambiguity is visible directly. --}}
<div class="mb-8 bg-white dark:bg-slate-900 rounded-xl border border-slate-200 dark:border-slate-700 shadow-sm px-5 py-4">
    <h2 class="text-sm font-bold text-slate-700 dark:text-slate-200 font-mono mb-1">Test this keyword</h2>
    <p class="text-xs font-mono text-slate-400 mb-3">Type a tag or cart/product name to see which TSAs and products it would match.</p>

    <input type="text" id="keywordTestInput" placeholder="e.g. Clear Sight 3.0, KATH, gemma diaz…"
           autocomplete="off"
           class="w-full max-w-md rounded-lg border border-slate-200 dark:border-slate-700 bg-white dark:bg-slate-800 px-3 py-2 text-sm font-mono text-slate-800 dark:text-slate-100 placeholder-slate-400 dark:placeholder-slate-500 focus:outline-none focus:ring-2 focus:ring-yellow-500">

    <div id="keywordTestResults" class="mt-4 hidden space-y-3"></div>
</div>

@php
    // Small helper so each section's empty/count header is identical everywhere.
    $sectionHeader = fn ($title, $count) => "{$title} ({$count})";
@endphp

{{-- SECTION 1 — duplicate TSA tag keywords (exact match conflict). --}}
<div class="mb-8 bg-white dark:bg-slate-900 rounded-xl border border-slate-200 dark:border-slate-700 shadow-sm overflow-hidden">
    <div class="px-5 py-4 border-b border-slate-100 dark:border-slate-700">
        <h2 class="text-sm font-bold text-slate-700 dark:text-slate-200 font-mono">{{ $sectionHeader('Duplicate TSA tag keywords', count($tsaTagDuplicates)) }}</h2>
        <p class="text-xs font-mono text-slate-400 mt-0.5">Two TSAs configured with the exact same tag keyword — whichever loads last silently wins every order tagged with it.</p>
    </div>

    @if(empty($tsaTagDuplicates))
    <div class="py-10 flex flex-col items-center justify-center text-center gap-2">
        <svg class="w-8 h-8 text-emerald-300 dark:text-emerald-700" fill="none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75L11.25 15 15 9.75M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>
        </svg>
        <p class="text-sm font-mono text-slate-400">No conflicts found.</p>
    </div>
    @else
    <div class="overflow-x-auto">
    <table class="w-full text-sm">
        <thead>
            <tr class="bg-slate-50 dark:bg-slate-800 text-xs font-mono text-slate-400 uppercase tracking-wide">
                <th class="px-5 py-2.5 text-left">Tag keyword</th>
                <th class="px-4 py-2.5 text-left">Shared by</th>
            </tr>
        </thead>
        <tbody class="divide-y divide-slate-100 dark:divide-slate-700">
            @foreach($tsaTagDuplicates as $row)
            <tr class="hover:bg-slate-50 dark:hover:bg-slate-800 transition-colors">
                <td class="px-5 py-3 font-mono text-xs font-semibold text-rose-700 dark:text-rose-400">{{ $row['keyword'] }}</td>
                <td class="px-4 py-3">
                    <div class="flex flex-wrap gap-1.5">
                        @foreach($row['tsas'] as $tsa)
                        <span class="inline-flex items-center px-2 py-0.5 rounded-full text-[10px] font-semibold bg-rose-100 dark:bg-rose-900/40 text-rose-800 dark:text-rose-400 whitespace-nowrap">{{ $tsa->display_name }}</span>
                        @endforeach
                    </div>
                </td>
            </tr>
            @endforeach
        </tbody>
    </table>
    </div>
    @endif
</div>

{{-- SECTION 2 — overlapping TSA seller keywords (substring conflict). --}}
<div class="mb-8 bg-white dark:bg-slate-900 rounded-xl border border-slate-200 dark:border-slate-700 shadow-sm overflow-hidden">
    <div class="px-5 py-4 border-b border-slate-100 dark:border-slate-700">
        <h2 class="text-sm font-bold text-slate-700 dark:text-slate-200 font-mono">{{ $sectionHeader('Overlapping TSA seller keywords', count($tsaSellerOverlaps)) }}</h2>
        <p class="text-xs font-mono text-slate-400 mt-0.5">One TSA's seller-account keyword is a substring of another's — whichever is checked first in load order silently wins.</p>
    </div>

    @if(empty($tsaSellerOverlaps))
    <div class="py-10 flex flex-col items-center justify-center text-center gap-2">
        <svg class="w-8 h-8 text-emerald-300 dark:text-emerald-700" fill="none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75L11.25 15 15 9.75M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>
        </svg>
        <p class="text-sm font-mono text-slate-400">No conflicts found.</p>
    </div>
    @else
    <div class="overflow-x-auto">
    <table class="w-full text-sm">
        <thead>
            <tr class="bg-slate-50 dark:bg-slate-800 text-xs font-mono text-slate-400 uppercase tracking-wide">
                <th class="px-5 py-2.5 text-left">TSA A</th>
                <th class="px-4 py-2.5 text-left">Keyword A</th>
                <th class="px-4 py-2.5 text-left">TSA B</th>
                <th class="px-4 py-2.5 text-left">Keyword B</th>
            </tr>
        </thead>
        <tbody class="divide-y divide-slate-100 dark:divide-slate-700">
            @foreach($tsaSellerOverlaps as $pair)
            <tr class="hover:bg-slate-50 dark:hover:bg-slate-800 transition-colors">
                <td class="px-5 py-3 font-mono text-xs text-slate-700 dark:text-slate-200">{{ $pair['tsaA']->display_name }}</td>
                <td class="px-4 py-3 font-mono text-xs font-semibold text-rose-700 dark:text-rose-400">{{ $pair['keywordA'] }}</td>
                <td class="px-4 py-3 font-mono text-xs text-slate-700 dark:text-slate-200">{{ $pair['tsaB']->display_name }}</td>
                <td class="px-4 py-3 font-mono text-xs font-semibold text-rose-700 dark:text-rose-400">{{ $pair['keywordB'] }}</td>
            </tr>
            @endforeach
        </tbody>
    </table>
    </div>
    @endif
</div>

{{-- SECTION 3 — overlapping product keywords (substring conflict). --}}
<div class="mb-8 bg-white dark:bg-slate-900 rounded-xl border border-slate-200 dark:border-slate-700 shadow-sm overflow-hidden">
    <div class="px-5 py-4 border-b border-slate-100 dark:border-slate-700">
        <h2 class="text-sm font-bold text-slate-700 dark:text-slate-200 font-mono">{{ $sectionHeader('Overlapping product keywords', count($productKeywordOverlaps)) }}</h2>
        <p class="text-xs font-mono text-slate-400 mt-0.5">One product's keyword is a substring of another's — <code class="font-mono">sort_order</code> decides which one silently wins.</p>
    </div>

    @if(empty($productKeywordOverlaps))
    <div class="py-10 flex flex-col items-center justify-center text-center gap-2">
        <svg class="w-8 h-8 text-emerald-300 dark:text-emerald-700" fill="none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75L11.25 15 15 9.75M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>
        </svg>
        <p class="text-sm font-mono text-slate-400">No conflicts found.</p>
    </div>
    @else
    <div class="overflow-x-auto">
    <table class="w-full text-sm">
        <thead>
            <tr class="bg-slate-50 dark:bg-slate-800 text-xs font-mono text-slate-400 uppercase tracking-wide">
                <th class="px-5 py-2.5 text-left">Product A</th>
                <th class="px-4 py-2.5 text-left">Keyword A</th>
                <th class="px-4 py-2.5 text-left">Product B</th>
                <th class="px-4 py-2.5 text-left">Keyword B</th>
            </tr>
        </thead>
        <tbody class="divide-y divide-slate-100 dark:divide-slate-700">
            @foreach($productKeywordOverlaps as $pair)
            <tr class="hover:bg-slate-50 dark:hover:bg-slate-800 transition-colors">
                <td class="px-5 py-3 font-mono text-xs text-slate-700 dark:text-slate-200">{{ $pair['productA']->display_name }}</td>
                <td class="px-4 py-3 font-mono text-xs font-semibold text-rose-700 dark:text-rose-400">{{ $pair['keywordA'] }}</td>
                <td class="px-4 py-3 font-mono text-xs text-slate-700 dark:text-slate-200">{{ $pair['productB']->display_name }}</td>
                <td class="px-4 py-3 font-mono text-xs font-semibold text-rose-700 dark:text-rose-400">{{ $pair['keywordB'] }}</td>
            </tr>
            @endforeach
        </tbody>
    </table>
    </div>
    @endif
</div>

@endsection

@push('scripts')
<script>
// "Test this keyword" tool — mirrors the topbar global-search debounce
// pattern (resources/js/app.js, ~line 460): 250ms debounce, stale-response
// guard via an incrementing request id. This page has no partials/live-indicator
// and no GET filter form, so window.softRefresh() never swaps <main> here —
// direct element binding (not document-delegation) is safe.
(function () {
    const input   = document.getElementById('keywordTestInput');
    const results = document.getElementById('keywordTestResults');
    if (!input || !results) return;

    let debounceTimer  = null;
    let currentRequest = 0;

    const escapeHtml = (s) => String(s).replace(/[&<>"']/g, (c) => ({
        '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;',
    })[c]);

    const pill = (label, cls) => `<span class="inline-flex items-center px-2 py-0.5 rounded-full text-[10px] font-semibold ${cls} whitespace-nowrap mr-1 mb-1">${escapeHtml(label)}</span>`;

    function row(label, bodyHtml) {
        return `
            <div class="flex flex-col sm:flex-row sm:items-start gap-1 sm:gap-3">
                <p class="text-xs font-mono font-semibold text-slate-400 uppercase tracking-wide shrink-0 sm:w-56">${label}</p>
                <div class="flex flex-wrap">${bodyHtml}</div>
            </div>
        `;
    }

    function render(data) {
        const parts = [];

        parts.push(row(
            'Matches TSA (by tag)',
            data.tsaByTag
                ? pill(`${data.tsaByTag.name} · ${data.tsaByTag.team ?? '—'}`, 'bg-slate-100 dark:bg-slate-700 text-slate-700 dark:text-slate-200')
                : '<span class="text-xs font-mono text-slate-300 dark:text-slate-600">No match</span>'
        ));

        parts.push(row(
            'Matches TSA (by seller account)',
            data.tsaBySeller.length
                ? data.tsaBySeller.map(t => pill(`${t.name} · ${t.team ?? '—'}`, 'bg-slate-100 dark:bg-slate-700 text-slate-700 dark:text-slate-200')).join('')
                : '<span class="text-xs font-mono text-slate-300 dark:text-slate-600">No match</span>'
        ));

        // Multiple matching products = live ambiguity — visibly warn, not just list.
        const multiProducts = data.products.length > 1;
        const productBody = data.products.length
            ? data.products.map(p => pill(
                `${p.name} · ${p.team ?? '—'}`,
                multiProducts
                    ? 'bg-rose-100 dark:bg-rose-900/40 text-rose-800 dark:text-rose-400'
                    : 'bg-slate-100 dark:bg-slate-700 text-slate-700 dark:text-slate-200'
              )).join('')
            : '<span class="text-xs font-mono text-slate-300 dark:text-slate-600">No match</span>';

        parts.push(row('Matches Product(s)', productBody));

        if (multiProducts) {
            parts.push(`
                <div class="flex items-start gap-2 bg-rose-50 dark:bg-rose-950/40 border border-rose-200 dark:border-rose-800 rounded-lg px-3 py-2">
                    <svg class="w-4 h-4 text-rose-500 shrink-0 mt-0.5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v2m0 4h.01M10.29 3.86L1.82 18a2 2 0 001.71 3h16.94a2 2 0 001.71-3L13.71 3.86a2 2 0 00-3.42 0z"/>
                    </svg>
                    <p class="text-xs font-mono text-rose-700 dark:text-rose-400">Ambiguous — ${data.products.length} products match this text. Only the first by sort order would silently win.</p>
                </div>
            `);
        }

        results.innerHTML = parts.join('');
        results.classList.remove('hidden');
    }

    input.addEventListener('input', () => {
        clearTimeout(debounceTimer);
        const query = input.value.trim();

        if (query.length < 1) {
            results.classList.add('hidden');
            results.innerHTML = '';
            currentRequest++;
            return;
        }

        debounceTimer = setTimeout(() => {
            const requestId = ++currentRequest;
            fetch('{{ route('keyword-diagnostics.test') }}?sample=' + encodeURIComponent(query))
                .then(r => r.json())
                .then(data => {
                    if (requestId === currentRequest) render(data);
                })
                .catch(() => {});
        }, 250);
    });
})();
</script>
@endpush
