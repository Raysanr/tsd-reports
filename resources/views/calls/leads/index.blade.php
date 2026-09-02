@extends('layouts.calls')
@section('title', $view === 'overdue' ? 'Overdue Leads' : ($view === 'callbacks' ? "Today's Callbacks" : 'Leads'))
@section('subtitle', match($view) {
    'overdue'   => "Assigned but not called within {$overdueThresholdHours}h",
    'callbacks' => 'Callbacks due now or already past due',
    default     => 'Round-robin assigned leads · click to call',
})

@section('content')

<script>
// Filters only ever live in this page's own URL (?date_from=&date_to=&tsa=) —
// nothing persists them on its own. The sidebar's Leads/Overdue/Callbacks
// links carry them forward directly (layouts/calls.blade.php), but that only
// covers moving between THOSE three — clicking away to Dashboard/Analytics/
// etc. and back to Leads lands on a bare URL with nothing to forward from,
// same problem a whole new session has. Explicit request (2026-08-14 for
// dates, extended 2026-08-15 to tsa too): remember the last-applied filters
// across either kind of round trip via localStorage.
//
// date_from/date_to: synced whenever BOTH are in the URL; restored together
// when neither is (there's no "clear the date" affordance, so any URL
// missing them is presumed a fresh/bare link, not a deliberate clear).
//
// tsa: synced whenever present in the URL, INCLUDING empty ("All TSAs" is a
// real, explicitly selectable option, not just "no signal yet") — but only
// restored when the tsa key is missing from the URL entirely, so picking
// "All TSAs" (which submits tsa= with an empty value) is never silently
// overridden by an old saved value.
//
// status: same "sync when present (incl. empty), restore only when absent"
// rule as tsa above — brought back 2026-08-21 alongside the filter itself.
// Only ever meaningful on the bare Leads view (LeadController::index()
// already ignores it on Overdue/Callbacks), so restoring it there is
// harmless even though it only really does anything once back on Leads.
(function () {
    const params = new URLSearchParams(window.location.search);
    const from = params.get('date_from');
    const to   = params.get('date_to');
    const hasTsa = params.has('tsa');
    const hasStatus = params.has('status');
    const hasTeam = params.has('team');
    const hasProduct = params.has('product');

    if (from && to) localStorage.setItem('callsLeadsDateRange', JSON.stringify({ from, to }));
    if (hasTsa) localStorage.setItem('callsLeadsTsa', params.get('tsa') || '');
    if (hasStatus) localStorage.setItem('callsLeadsStatus', params.get('status') || '');
    if (hasTeam) localStorage.setItem('callsLeadsTeam', params.get('team') || '');
    if (hasProduct) localStorage.setItem('callsLeadsProduct', params.get('product') || '');

    let needsRedirect = false;

    if (!(from && to)) {
        try {
            const saved = JSON.parse(localStorage.getItem('callsLeadsDateRange') || 'null');
            if (saved?.from && saved?.to) {
                params.set('date_from', saved.from);
                params.set('date_to', saved.to);
                needsRedirect = true;
            }
        } catch (e) { /* corrupt/old value — ignore, falls back to today */ }
    }

    if (!hasTsa) {
        const savedTsa = localStorage.getItem('callsLeadsTsa');
        if (savedTsa) {
            params.set('tsa', savedTsa);
            needsRedirect = true;
        }
    }

    if (!hasStatus) {
        const savedStatus = localStorage.getItem('callsLeadsStatus');
        if (savedStatus) {
            params.set('status', savedStatus);
            needsRedirect = true;
        }
    }

    if (!hasTeam) {
        const savedTeam = localStorage.getItem('callsLeadsTeam');
        if (savedTeam) {
            params.set('team', savedTeam);
            needsRedirect = true;
        }
    }

    if (!hasProduct) {
        const savedProduct = localStorage.getItem('callsLeadsProduct');
        if (savedProduct) {
            params.set('product', savedProduct);
            needsRedirect = true;
        }
    }

    if (needsRedirect) window.location.replace(window.location.pathname + '?' + params.toString());
})();
</script>

<div class="mb-6 flex items-center gap-3 flex-wrap">
    <form method="GET" class="flex items-center gap-3 flex-wrap">
        @if($view)<input type="hidden" name="view" value="{{ $view }}">@endif

        @php
            // Team dot colors — same gold/teal accent pair Leads Setup's own
            // table already uses per team (round-robin-setup/_table.blade.php),
            // reused here so a team reads the same way on both pages.
            $teamDotColors = ['SH Naturals' => 'bg-primary', 'Eyecare Team' => 'bg-teal-600'];
            $selectedTsaModel = $selectedTsa ? $tsas->firstWhere('id', $selectedTsa) : null;
            $selectedProductModel = $selectedProduct ? $products->firstWhere('id', $selectedProduct) : null;
            $statusLabels = ['catered' => 'Catered', 'uncatered' => 'Uncatered'];
        @endphp

        @if(auth()->user()->isAtLeastAdmin())
        {{-- Custom dropdown (explicit request, 2026-08-20; the same
             trigger+floating-panel design was then extended to Team/Product/
             Status below, explicit request 2026-08-28) instead of a plain
             native <select>: a real hidden input carries the actual value
             the surrounding GET form submits, clicking a row just sets it
             and submits, same end result as a <select onchange="submit()">.
             Generic data-filter-* JS (resources/js/calls.js) drives every
             dropdown below too — see its own doc comment. Each row's avatar
             circle reuses TSA Management's own initials-circle style so a
             TSA reads the same way here as it does there. --}}
        <div class="relative" data-filter-wrap>
            <input type="hidden" name="tsa" value="{{ $selectedTsa ?: '' }}" data-filter-input>
            <button type="button" data-filter-trigger
                    class="inline-flex items-center gap-2 text-sm font-mono font-semibold text-slate-700 dark:text-slate-200 border border-slate-300 dark:border-slate-600 rounded-lg px-3 py-2 bg-white dark:bg-slate-800 hover:bg-slate-50 dark:hover:bg-slate-700 cursor-pointer">
                @if($selectedTsaModel)
                <span class="w-5 h-5 rounded-full bg-slate-800 dark:bg-slate-700 text-white flex items-center justify-center text-[9px] font-bold shrink-0">{{ strtoupper(substr($selectedTsaModel->display_name, 0, 2)) }}</span>
                @else
                <svg class="w-4 h-4 text-slate-400 shrink-0" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M18 18.72a9.094 9.094 0 003.741-.479 3 3 0 00-4.682-2.72m.94 3.198l.001.031c0 .225-.012.447-.037.666A11.944 11.944 0 0112 21c-2.17 0-4.207-.576-5.963-1.584A6.062 6.062 0 016 18.719m12 0a5.971 5.971 0 00-.941-3.197m0 0A5.995 5.995 0 0012 12.75a5.995 5.995 0 00-5.058 2.772m0 0a3 3 0 00-4.681 2.72 8.986 8.986 0 003.74.477m.94-3.197a5.971 5.971 0 00-.94 3.197M15 6.75a3 3 0 11-6 0 3 3 0 016 0zm6 3a2.25 2.25 0 11-4.5 0 2.25 2.25 0 014.5 0zm-13.5 0a2.25 2.25 0 11-4.5 0 2.25 2.25 0 014.5 0z"/></svg>
                @endif
                <span>{{ $selectedTsaModel?->display_name ?? 'All TSAs' }}</span>
                <svg class="w-3.5 h-3.5 text-slate-400" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M19 9l-7 7-7-7"/>
                </svg>
            </button>

            <div class="hidden fixed z-50 bg-white dark:bg-slate-900 rounded-2xl shadow-2xl border border-slate-200 dark:border-slate-700 w-64 max-h-96 overflow-y-auto" data-filter-panel>
                <div class="py-1">
                    <div class="filter-option flex items-center gap-3 px-4 py-2.5 cursor-pointer hover:bg-slate-50 dark:hover:bg-slate-800" data-value="">
                        <svg class="w-5 h-5 text-slate-400 shrink-0" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M18 18.72a9.094 9.094 0 003.741-.479 3 3 0 00-4.682-2.72m.94 3.198l.001.031c0 .225-.012.447-.037.666A11.944 11.944 0 0112 21c-2.17 0-4.207-.576-5.963-1.584A6.062 6.062 0 016 18.719m12 0a5.971 5.971 0 00-.941-3.197m0 0A5.995 5.995 0 0012 12.75a5.995 5.995 0 00-5.058 2.772m0 0a3 3 0 00-4.681 2.72 8.986 8.986 0 003.74.477m.94-3.197a5.971 5.971 0 00-.94 3.197M15 6.75a3 3 0 11-6 0 3 3 0 016 0zm6 3a2.25 2.25 0 11-4.5 0 2.25 2.25 0 014.5 0zm-13.5 0a2.25 2.25 0 11-4.5 0 2.25 2.25 0 014.5 0z"/></svg>
                        <span class="flex-1 text-sm font-semibold text-slate-700 dark:text-slate-200 font-mono">All TSAs</span>
                        @if(!$selectedTsa)
                        <svg class="w-4 h-4 text-primary shrink-0" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7"/></svg>
                        @endif
                    </div>
                    @foreach($tsas as $tsa)
                    <div class="filter-option flex items-center gap-3 px-4 py-2.5 cursor-pointer hover:bg-slate-50 dark:hover:bg-slate-800" data-value="{{ $tsa->id }}">
                        <span class="w-5 h-5 rounded-full bg-slate-800 dark:bg-slate-700 text-white flex items-center justify-center text-[9px] font-bold shrink-0">{{ strtoupper(substr($tsa->display_name, 0, 2)) }}</span>
                        <span class="flex-1 text-sm font-semibold text-slate-700 dark:text-slate-200 font-mono">{{ $tsa->display_name }}</span>
                        @if($selectedTsa === $tsa->id)
                        <svg class="w-4 h-4 text-primary shrink-0" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7"/></svg>
                        @endif
                    </div>
                    @endforeach
                </div>
            </div>
        </div>

        {{-- Team filter (explicit request, 2026-08-28) — narrows both the
             leads list and the Product filter's own options below to that
             team's products (Product::team, the same order_team string
             TsaShift::team already uses — see config('teams')'s own doc
             comment). Picking a team clears any already-picked product via
             the hidden product input below — otherwise a product left over
             from the OTHER team would stay in the URL and silently produce
             zero results instead of just widening back out. --}}
        <div class="relative" data-filter-wrap data-clears="product">
            <input type="hidden" name="team" value="{{ $selectedTeam ?: '' }}" data-filter-input>
            <button type="button" data-filter-trigger
                    class="inline-flex items-center gap-2 text-sm font-mono font-semibold text-slate-700 dark:text-slate-200 border border-slate-300 dark:border-slate-600 rounded-lg px-3 py-2 bg-white dark:bg-slate-800 hover:bg-slate-50 dark:hover:bg-slate-700 cursor-pointer">
                @if($selectedTeam)
                <span class="w-2.5 h-2.5 rounded-full shrink-0 {{ $teamDotColors[$selectedTeam] ?? 'bg-slate-400' }}"></span>
                @else
                <svg class="w-4 h-4 text-slate-400 shrink-0" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M3.75 6A2.25 2.25 0 016 3.75h2.25A2.25 2.25 0 0110.5 6v2.25a2.25 2.25 0 01-2.25 2.25H6a2.25 2.25 0 01-2.25-2.25V6zM3.75 15.75A2.25 2.25 0 016 13.5h2.25a2.25 2.25 0 012.25 2.25V18a2.25 2.25 0 01-2.25 2.25H6A2.25 2.25 0 013.75 18v-2.25zM13.5 6a2.25 2.25 0 012.25-2.25H18A2.25 2.25 0 0120.25 6v2.25A2.25 2.25 0 0118 10.5h-2.25a2.25 2.25 0 01-2.25-2.25V6zM13.5 15.75a2.25 2.25 0 012.25-2.25H18a2.25 2.25 0 012.25 2.25V18A2.25 2.25 0 0118 20.25h-2.25A2.25 2.25 0 0113.5 18v-2.25z"/></svg>
                @endif
                <span>{{ $selectedTeam ?: 'All Teams' }}</span>
                <svg class="w-3.5 h-3.5 text-slate-400" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M19 9l-7 7-7-7"/>
                </svg>
            </button>

            <div class="hidden fixed z-50 bg-white dark:bg-slate-900 rounded-2xl shadow-2xl border border-slate-200 dark:border-slate-700 w-56 overflow-y-auto" data-filter-panel>
                <div class="py-1">
                    <div class="filter-option flex items-center gap-3 px-4 py-2.5 cursor-pointer hover:bg-slate-50 dark:hover:bg-slate-800" data-value="">
                        <svg class="w-5 h-5 text-slate-400 shrink-0" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M3.75 6A2.25 2.25 0 016 3.75h2.25A2.25 2.25 0 0110.5 6v2.25a2.25 2.25 0 01-2.25 2.25H6a2.25 2.25 0 01-2.25-2.25V6zM3.75 15.75A2.25 2.25 0 016 13.5h2.25a2.25 2.25 0 012.25 2.25V18a2.25 2.25 0 01-2.25 2.25H6A2.25 2.25 0 013.75 18v-2.25zM13.5 6a2.25 2.25 0 012.25-2.25H18A2.25 2.25 0 0120.25 6v2.25A2.25 2.25 0 0118 10.5h-2.25a2.25 2.25 0 01-2.25-2.25V6zM13.5 15.75a2.25 2.25 0 012.25-2.25H18a2.25 2.25 0 012.25 2.25V18A2.25 2.25 0 0118 20.25h-2.25A2.25 2.25 0 0113.5 18v-2.25z"/></svg>
                        <span class="flex-1 text-sm font-semibold text-slate-700 dark:text-slate-200 font-mono">All Teams</span>
                        @if(!$selectedTeam)
                        <svg class="w-4 h-4 text-primary shrink-0" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7"/></svg>
                        @endif
                    </div>
                    @foreach($teams as $team)
                    <div class="filter-option flex items-center gap-3 px-4 py-2.5 cursor-pointer hover:bg-slate-50 dark:hover:bg-slate-800" data-value="{{ $team }}">
                        <span class="w-2.5 h-2.5 rounded-full shrink-0 {{ $teamDotColors[$team] ?? 'bg-slate-400' }}"></span>
                        <span class="flex-1 text-sm font-semibold text-slate-700 dark:text-slate-200 font-mono">{{ $team }}</span>
                        @if($selectedTeam === $team)
                        <svg class="w-4 h-4 text-primary shrink-0" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7"/></svg>
                        @endif
                    </div>
                    @endforeach
                </div>
            </div>
        </div>
        @endif

        {{-- Product filter — admin-only above this closed with the Team
             filter: Product/Status/Search work for a TSA too (explicit
             follow-up, 2026-09-02: "add product, status, search in the
             tsa(normal user) in leads") — only the Team/TSA pickers stay
             admin-only, since narrowing to a specific TSA/team is
             inherently an admin-only concept (a TSA already only ever sees
             their own queue). Options are scoped to the team picked above
             for an admin, or to that TSA's own leads for a TSA
             (LeadController::index()'s own 'products' query), so this can
             never offer a product with nothing to show. --}}
        <div class="relative" data-filter-wrap>
            <input type="hidden" name="product" value="{{ $selectedProduct ?: '' }}" data-filter-input>
            <button type="button" data-filter-trigger
                    class="inline-flex items-center gap-2 text-sm font-mono font-semibold text-slate-700 dark:text-slate-200 border border-slate-300 dark:border-slate-600 rounded-lg px-3 py-2 bg-white dark:bg-slate-800 hover:bg-slate-50 dark:hover:bg-slate-700 cursor-pointer">
                <svg class="w-4 h-4 text-slate-400 shrink-0" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M20.25 7.5l-.625 10.632a2.25 2.25 0 01-2.247 2.118H6.622a2.25 2.25 0 01-2.247-2.118L3.75 7.5M10 11.25h4M3.375 7.5h17.25c.621 0 1.125-.504 1.125-1.125v-1.5c0-.621-.504-1.125-1.125-1.125H3.375c-.621 0-1.125.504-1.125 1.125v1.5c0 .621.504 1.125 1.125 1.125z"/></svg>
                <span>{{ $selectedProductModel?->display_name ?? 'All Products' }}</span>
                <svg class="w-3.5 h-3.5 text-slate-400" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M19 9l-7 7-7-7"/>
                </svg>
            </button>

            <div class="hidden fixed z-50 bg-white dark:bg-slate-900 rounded-2xl shadow-2xl border border-slate-200 dark:border-slate-700 w-64 max-h-96 overflow-y-auto" data-filter-panel>
                <div class="py-1">
                    <div class="filter-option flex items-center gap-3 px-4 py-2.5 cursor-pointer hover:bg-slate-50 dark:hover:bg-slate-800" data-value="">
                        <span class="flex-1 text-sm font-semibold text-slate-700 dark:text-slate-200 font-mono">All Products</span>
                        @if(!$selectedProduct)
                        <svg class="w-4 h-4 text-primary shrink-0" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7"/></svg>
                        @endif
                    </div>
                    @foreach($products as $product)
                    <div class="filter-option flex items-center gap-3 px-4 py-2.5 cursor-pointer hover:bg-slate-50 dark:hover:bg-slate-800" data-value="{{ $product->id }}">
                        <span class="flex-1 text-sm font-semibold text-slate-700 dark:text-slate-200 font-mono">{{ $product->display_name }}</span>
                        @if($selectedProduct === $product->id)
                        <svg class="w-4 h-4 text-primary shrink-0" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7"/></svg>
                        @endif
                    </div>
                    @endforeach
                </div>
            </div>
        </div>

        {{-- Status filter, brought back (explicit request, 2026-08-21) — see
             LeadController::index()'s own comment for why this only applies
             on the bare Leads view, not Overdue/Callbacks. Upgraded to the
             same custom dropdown design as TSA/Team/Product above (explicit
             request, 2026-08-28), a dot color standing in for the avatar/
             team-dot the others use. Catered/Uncatered added (explicit
             request, 2026-08-26) — same "Catered" language the Call Tracker
             Dashboard KPI already uses (see LeadController::index()'s own
             comment on this). The original Unassigned/Assigned/Called
             options these replaced are removed from the UI (explicit
             request, same day) — the controller still recognizes those
             values via STATUS_FILTER_VALUES for any old bookmarked/typed
             URL, this is only about what the dropdown itself now offers. --}}
        @if(!$view)
        <div class="relative" data-filter-wrap>
            <input type="hidden" name="status" value="{{ $selectedStatus ?: '' }}" data-filter-input>
            <button type="button" data-filter-trigger
                    class="inline-flex items-center gap-2 text-sm font-mono font-semibold text-slate-700 dark:text-slate-200 border border-slate-300 dark:border-slate-600 rounded-lg px-3 py-2 bg-white dark:bg-slate-800 hover:bg-slate-50 dark:hover:bg-slate-700 cursor-pointer">
                @if($selectedStatus)
                <span class="w-2.5 h-2.5 rounded-full shrink-0 {{ $selectedStatus === 'catered' ? 'bg-emerald-500' : 'bg-amber-500' }}"></span>
                @else
                <svg class="w-4 h-4 text-slate-400 shrink-0" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M10.5 6h9.75M10.5 6a1.5 1.5 0 11-3 0m3 0a1.5 1.5 0 10-3 0M3.75 6H7.5m3 12h9.75m-9.75 0a1.5 1.5 0 01-3 0m3 0a1.5 1.5 0 00-3 0m-3.75 0H7.5m9-6h3.75m-3.75 0a1.5 1.5 0 01-3 0m3 0a1.5 1.5 0 00-3 0m-9.75 0h9.75"/></svg>
                @endif
                <span>{{ $statusLabels[$selectedStatus] ?? 'All Statuses' }}</span>
                <svg class="w-3.5 h-3.5 text-slate-400" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M19 9l-7 7-7-7"/>
                </svg>
            </button>

            <div class="hidden fixed z-50 bg-white dark:bg-slate-900 rounded-2xl shadow-2xl border border-slate-200 dark:border-slate-700 w-52 overflow-y-auto" data-filter-panel>
                <div class="py-1">
                    <div class="filter-option flex items-center gap-3 px-4 py-2.5 cursor-pointer hover:bg-slate-50 dark:hover:bg-slate-800" data-value="">
                        <svg class="w-5 h-5 text-slate-400 shrink-0" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M10.5 6h9.75M10.5 6a1.5 1.5 0 11-3 0m3 0a1.5 1.5 0 10-3 0M3.75 6H7.5m3 12h9.75m-9.75 0a1.5 1.5 0 01-3 0m3 0a1.5 1.5 0 00-3 0m-3.75 0H7.5m9-6h3.75m-3.75 0a1.5 1.5 0 01-3 0m3 0a1.5 1.5 0 00-3 0m-9.75 0h9.75"/></svg>
                        <span class="flex-1 text-sm font-semibold text-slate-700 dark:text-slate-200 font-mono">All Statuses</span>
                        @if(!$selectedStatus)
                        <svg class="w-4 h-4 text-primary shrink-0" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7"/></svg>
                        @endif
                    </div>
                    @foreach($statusLabels as $value => $label)
                    <div class="filter-option flex items-center gap-3 px-4 py-2.5 cursor-pointer hover:bg-slate-50 dark:hover:bg-slate-800" data-value="{{ $value }}">
                        <span class="w-2.5 h-2.5 rounded-full shrink-0 {{ $value === 'catered' ? 'bg-emerald-500' : 'bg-amber-500' }}"></span>
                        <span class="flex-1 text-sm font-semibold text-slate-700 dark:text-slate-200 font-mono">{{ $label }}</span>
                        @if($selectedStatus === $value)
                        <svg class="w-4 h-4 text-primary shrink-0" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7"/></svg>
                        @endif
                    </div>
                    @endforeach
                </div>
            </div>
        </div>
        @endif

        {{-- Explicit request (2026-08-20): this is what replaces TSA
             Management's old per-row status control — NOT a filter on this
             list (that's what the first attempt at this got wrong). Picking
             a TSA above reveals this same tsa-status-panel component TSA
             Management used to render per-row (target = that TSA's id,
             options = SELF_SERVICE_STATUSES — the same Login/Break/
             Coaching/DNA Huddle/Huddle/Logout set it always offered),
             letting an admin change THAT TSA's status right here instead of
             a separate page. Only makes sense once a specific TSA is
             picked — "All TSAs" has no single status to show/change, so
             this stays hidden until one is. Admin-only: $selectedTsaModel
             is never set for a TSA viewer (they have no TSA-picker to set
             it from), so this is naturally already scoped correctly. --}}
        @if(!$view && $selectedTsaModel)
        @include('calls.partials.tsa-status-panel', [
            'id'      => 'leads-tsa-filter',
            'options' => \App\Models\TsaShift::SELF_SERVICE_STATUSES,
            'current' => $selectedTsaModel->status,
            'target'  => (string) $selectedTsaModel->id,
        ])
        @endif

        @if(!auth()->user()->isAtLeastAdmin() && auth()->user()->tsa)
        {{-- A logged-in TSA sees their own name here, same avatar+name shape
             as the admin Team/TSA dropdowns above minus the chevron/click
             handler — explicit request, 2026-08-26: "the one tsa can only
             see their name and has no dropdown." Narrowing to a specific
             TSA/team is inherently an admin-only concept (a TSA already
             only ever sees their own queue, per the base query in
             LeadController::index()), so this stays as a static label
             while Product/Status/Search below are no longer admin-only
             (explicit follow-up, 2026-09-02: "add product, status, search
             in the tsa(normal user) in leads"). --}}
        <div class="inline-flex items-center gap-2 text-sm font-mono font-semibold text-slate-700 dark:text-slate-200 border border-slate-300 dark:border-slate-600 rounded-lg px-3 py-2 bg-white dark:bg-slate-800">
            <span class="w-5 h-5 rounded-full bg-slate-800 dark:bg-slate-700 text-white flex items-center justify-center text-[9px] font-bold shrink-0">{{ strtoupper(substr(auth()->user()->tsa->display_name, 0, 2)) }}</span>
            <span>{{ auth()->user()->tsa->display_name }}</span>
        </div>
        @endif

        <div class="relative">
            <svg class="w-4 h-4 text-slate-300 dark:text-slate-600 absolute left-3 top-1/2 -translate-y-1/2 pointer-events-none" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" d="M21 21l-4.35-4.35M18 10.5a7.5 7.5 0 11-15 0 7.5 7.5 0 0115 0z"/>
            </svg>
            <input type="text" name="q" value="{{ $q }}" placeholder="Search name, phone, order ID…"
                   class="text-sm font-mono border border-slate-300 dark:border-slate-600 rounded-lg pl-9 pr-3 py-2 bg-white dark:bg-slate-800 text-slate-800 dark:text-slate-100 focus:outline-none focus:ring-2 focus:ring-yellow-500 w-64">
        </div>
        <button type="submit" class="text-sm font-mono font-semibold text-white bg-primary hover:bg-primary-dark rounded-lg px-4 py-2 cursor-pointer">Search</button>

        @if(auth()->user()->isAtLeastAdmin())
        @include('partials.date-picker', [
            'mode' => 'range', 'id' => 'callsLeadsDrp',
            'dateFrom' => \Illuminate\Support\Carbon::parse($dateFrom ?: now()),
            'dateTo'   => \Illuminate\Support\Carbon::parse($dateTo ?: now()),
        ])
        @endif
    </form>
</div>

<div id="leads-table-container" data-poll-url="{{ url()->full() }}">
    @include('calls.leads._table')
</div>

{{-- Bulk actions — admin-only (explicit request, 2026-08-26): matching
     Product Management's own checkbox + bulk-bar pattern, but restricted
     entirely to admins — both Pin/Unpin and Transfer require isAtLeastAdmin()
     server-side now (LeadController::bulkPin()/bulkTransfer()), so a TSA
     never sees a checkbox to select with in the first place (see
     _table.blade.php's own guard on the checkbox column). Sticky bottom bar,
     same shape as product-management.blade.php's own — but submitted via
     fetch (calls.js), not a full-page POST, since this table live-polls
     every 15s and a full-page reload would be a jarring step backward
     from that. --}}
@if(auth()->user()->isAtLeastAdmin())
<div id="bulkLeadsBar" class="hidden fixed bottom-0 left-0 right-0 md:left-64 z-30 px-4 py-3">
    <div class="max-w-3xl mx-auto bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-700 rounded-xl shadow-2xl px-5 py-3 flex flex-wrap items-center gap-3">
        <span id="bulkLeadsCount" class="text-xs font-semibold text-slate-700 dark:text-slate-200 whitespace-nowrap">0 selected</span>
        <button type="button" id="bulkLeadsClear" class="text-xs font-mono text-slate-400 hover:text-slate-600 dark:hover:text-slate-200 cursor-pointer">Clear</button>
        <div class="flex-1"></div>
        <div class="flex flex-wrap items-center gap-2">
            <button type="button" id="bulkLeadsPin" class="px-3 py-1.5 text-xs font-semibold text-slate-600 dark:text-slate-300 border border-slate-200 dark:border-slate-700 rounded-lg hover:bg-slate-50 dark:hover:bg-slate-800 transition-colors cursor-pointer">Pin</button>
            <button type="button" id="bulkLeadsUnpin" class="px-3 py-1.5 text-xs font-semibold text-slate-600 dark:text-slate-300 border border-slate-200 dark:border-slate-700 rounded-lg hover:bg-slate-50 dark:hover:bg-slate-800 transition-colors cursor-pointer">Unpin</button>
            <select id="bulkLeadsTsaSelect" class="rounded-lg border border-slate-200 dark:border-slate-700 bg-white dark:bg-slate-800 px-2 py-1.5 text-xs text-slate-900 dark:text-slate-100 focus:outline-none focus:ring-2 focus:ring-yellow-500">
                @foreach($tsas as $tsa)
                <option value="{{ $tsa->id }}">{{ $tsa->display_name }}</option>
                @endforeach
            </select>
            <button type="button" id="bulkLeadsTransfer" class="px-3 py-1.5 text-xs font-semibold text-yellow-700 dark:text-yellow-400 border border-yellow-200 dark:border-yellow-900 rounded-lg hover:bg-yellow-50 dark:hover:bg-yellow-950/40 transition-colors cursor-pointer">Transfer</button>
        </div>
    </div>
</div>
@endif

@include('calls.partials.modals')

@endsection
