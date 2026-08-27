@extends('layouts.app')
@section('title', 'Insights')
@section('subtitle', 'Smart, rule-based flags — not AI guesses, just numbers worth a second look')

@section('content')

@push('topbar-right')
<div class="flex items-center gap-3 flex-wrap">

    {{-- Same ALL/SH Naturals/Eyecare filter as the Dashboard's own — explicit
         request, 2026-08-27: "team filter too in the top bar like in the
         dashboard." Plain GET form so app.js's generic submit-intercept
         soft-refreshes in place; the hidden date_from/view fields carry the
         currently viewed day and tab along with it, so switching teams never
         resets either back to their default. --}}
    <form method="GET" action="{{ route('insights') }}" class="contents">
        <input type="hidden" name="date_from" value="{{ $date->toDateString() }}">
        <input type="hidden" name="view" value="{{ $view }}">

        <div class="flex rounded-lg border border-slate-300 dark:border-slate-600 overflow-hidden">
            @foreach($teams as $key => $label)
            <button type="submit" name="team" value="{{ $key }}" data-filter-btn
                    class="px-3 py-1.5 text-xs font-semibold font-mono cursor-pointer transition-colors duration-200 motion-reduce:transition-none
                           {{ $selectedTeam === $key ? 'bg-primary text-white' : 'bg-white dark:bg-slate-900 text-slate-500 dark:text-slate-400 hover:bg-slate-50 dark:hover:bg-slate-800' }}">
                {{ $label }}
            </button>
            @endforeach
        </div>
    </form>

    {{-- Single-day picker — explicit request, 2026-08-27: "supervisors report
         today but yesterday data," so this page needs to be viewable as of any
         past day, not just today. mode='single' + submit='navigate' still sends
         BOTH date_from/date_to (see partials/date-picker.blade.php's own doc
         comment on the applyBtn handler) — InsightsController reads date_from
         as the single reference date. --}}
    @include('partials.date-picker', [
        'mode' => 'single', 'id' => 'drp', 'date' => $date->toDateString(),
        'submit' => 'navigate', 'navigateBase' => route('insights'),
    ])

</div>
@endpush

{{-- Insights / Action Plan toggle — explicit request, 2026-08-27: "make the
     insights/action plan filter not in the top bar, i want to make it it is
     in the page as is" — moved from the topbar into the page body itself,
     as a proper tab row above the content it switches. Same GET-form
     pattern as the team filter (date_from/team hidden fields preserve the
     rest of the current view). --}}
<form method="GET" action="{{ route('insights') }}" class="mb-5">
    <input type="hidden" name="date_from" value="{{ $date->toDateString() }}">
    <input type="hidden" name="team" value="{{ $selectedTeam }}">

    <div class="inline-flex rounded-lg border border-slate-300 dark:border-slate-600 overflow-hidden">
        @foreach(['insights' => 'INSIGHTS', 'action-plan' => 'ACTION PLAN'] as $key => $label)
        <button type="submit" name="view" value="{{ $key }}" data-filter-btn
                class="px-4 py-2 text-xs font-semibold font-mono cursor-pointer transition-colors duration-200 motion-reduce:transition-none
                       {{ $view === $key ? 'bg-primary text-white' : 'bg-white dark:bg-slate-900 text-slate-500 dark:text-slate-400 hover:bg-slate-50 dark:hover:bg-slate-800' }}">
            {{ $label }}
        </button>
        @endforeach
    </div>
</form>

@php
    // Same severity vocabulary throughout — one place to change the palette
    // rather than repeating four color names inline on every card. Reuses
    // this app's existing amber/red/emerald/sky convention (Order::
    // STATUS_PILL, the Overdue badge, etc.) rather than inventing a new one.
    $severityStyle = [
        'critical' => ['border' => 'border-red-400 dark:border-red-600', 'bg' => 'bg-red-50 dark:bg-red-950/30', 'iconBg' => 'bg-red-100 dark:bg-red-900/40', 'label' => 'text-red-700 dark:text-red-400'],
        'warning'  => ['border' => 'border-amber-400 dark:border-amber-600', 'bg' => 'bg-amber-50 dark:bg-amber-950/20', 'iconBg' => 'bg-amber-100 dark:bg-amber-900/40', 'label' => 'text-amber-700 dark:text-amber-400'],
        'info'     => ['border' => 'border-sky-400 dark:border-sky-600', 'bg' => 'bg-sky-50 dark:bg-sky-950/20', 'iconBg' => 'bg-sky-100 dark:bg-sky-900/40', 'label' => 'text-sky-700 dark:text-sky-400'],
        'positive' => ['border' => 'border-emerald-400 dark:border-emerald-600', 'bg' => 'bg-emerald-50 dark:bg-emerald-950/20', 'iconBg' => 'bg-emerald-100 dark:bg-emerald-900/40', 'label' => 'text-emerald-700 dark:text-emerald-400'],
    ];
    // Daily narrative — explicit request, 2026-08-27: "overall insights...
    // paragraph... reasons behind of all data... everyday is changing, not
    // by format" (a rule-based synthesis, not an AI call — see
    // InsightsGenerator::dailyNarrativeCard()'s own doc comment). Pulled out
    // of $cards and rendered separately as a full-width prose block, not a
    // grid card — the summary strip/grid/Action Plan below all use
    // $gridCards so this doesn't get double-counted as a severity pill or
    // show up twice.
    $narrativeCard = $cards->firstWhere('category', 'Overview');
    $gridCards = $cards->reject(fn ($c) => $c['category'] === 'Overview')->values();

    $counts = $gridCards->countBy('severity');

    // Action Plan — explicit request, 2026-08-27: same underlying cards,
    // filtered to the ones with a concrete next step (a positive/info card
    // like "top performer" or "New Leads up" still gets one — see
    // InsightsGenerator — so this isn't just the warning/critical subset).
    $actionCards = $gridCards->filter(fn ($c) => $c['action'] !== null)->values();
@endphp

@if($narrativeCard)
{{-- Full-width prose block, deliberately NOT a grid card — this is the
     "why" narrative a supervisor's own EOD report leads with, not one more
     discrete flag. Shown on both tabs since it's a summary of the whole
     day, not specific to either lens. --}}
<div class="bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-700 shadow-sm p-6 mb-6">
    <p class="text-[10px] font-bold uppercase tracking-widest text-slate-400 dark:text-slate-500 mb-2">Overview</p>
    <p class="text-sm text-slate-700 dark:text-slate-200 leading-relaxed">{{ $narrativeCard['message'] }}</p>
</div>
@endif

@if($view === 'action-plan')

@if($actionCards->isEmpty())
<div class="bg-white dark:bg-slate-900 rounded-xl border border-slate-200 dark:border-slate-700 shadow-sm py-24 flex flex-col items-center justify-center gap-4">
    <svg class="w-12 h-12 text-slate-200 dark:text-slate-700" fill="none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24">
        <path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75L11.25 15 15 9.75M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>
    </svg>
    <p class="text-sm font-mono text-slate-400">Nothing needs action right now.</p>
    <p class="text-xs font-mono text-slate-300 dark:text-slate-600 max-w-sm text-center">Action items come from the same cards as the Insights tab — a quiet stretch there means an empty plan here too.</p>
</div>
@else

<div class="flex flex-wrap items-center gap-3 mb-6">
    <span class="inline-flex items-center gap-1.5 text-xs font-mono font-semibold px-3 py-1.5 rounded-full bg-slate-100 dark:bg-slate-800 text-slate-600 dark:text-slate-300 border border-slate-200 dark:border-slate-700">
        {{ $actionCards->count() }} action {{ Str::plural('item', $actionCards->count()) }}
    </span>
</div>

{{-- Same 2-column grid container as the Insights tab's own card grid below —
     explicit request, 2026-08-27: "make the UI consistent." This used to be
     a single-column flex-col list, which read as a different, unrelated UI
     from the Insights tab rather than the same card system in a different
     lens. --}}
<div class="grid grid-cols-1 lg:grid-cols-2 gap-4">
    @foreach($actionCards as $card)
    @php $style = $severityStyle[$card['severity']]; @endphp
    <div class="bg-white dark:bg-slate-900 rounded-2xl border-l-4 {{ $style['border'] }} border-y border-r border-slate-200 dark:border-slate-700 shadow-sm p-5 flex items-start gap-4">
        <div class="w-10 h-10 rounded-xl {{ $style['iconBg'] }} flex items-center justify-center text-xl shrink-0">
            {{ $card['icon'] }}
        </div>
        <div class="min-w-0">
            <p class="text-[10px] font-bold uppercase tracking-widest {{ $style['label'] }} mb-1">{{ $card['category'] }}</p>
            <p class="text-sm font-semibold text-slate-800 dark:text-slate-100 leading-relaxed">{{ $card['action'] }}</p>
            <p class="text-xs text-slate-500 dark:text-slate-400 leading-relaxed mt-1">{{ $card['message'] }}</p>
        </div>
    </div>
    @endforeach
</div>
@endif

@else

@if($gridCards->isEmpty())
<div class="bg-white dark:bg-slate-900 rounded-xl border border-slate-200 dark:border-slate-700 shadow-sm py-24 flex flex-col items-center justify-center gap-4">
    <svg class="w-12 h-12 text-slate-200 dark:text-slate-700" fill="none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24">
        <path stroke-linecap="round" stroke-linejoin="round"
              d="M9.813 15.904L9 18.75l-.813-2.846a4.5 4.5 0 00-3.09-3.09L2.25 12l2.846-.813a4.5 4.5 0 003.09-3.09L9 5.25l.813 2.846a4.5 4.5 0 003.09 3.09L15.75 12l-2.846.813a4.5 4.5 0 00-3.09 3.09z"/>
    </svg>
    <p class="text-sm font-mono text-slate-400">Nothing worth flagging right now.</p>
    <p class="text-xs font-mono text-slate-300 dark:text-slate-600 max-w-sm text-center">Every card here needs enough real volume to trust the number behind it — a quiet stretch means fewer cards, not a broken page.</p>
</div>
@else

{{-- Summary strip — same "quick counts before the detail" shape as the KPI
     row atop Dashboard/Analytics, so this page opens the same way every
     other report page in this app does. --}}
<div class="flex flex-wrap items-center gap-3 mb-6">
    @foreach(['critical' => 'Needs attention', 'warning' => 'Worth watching', 'positive' => 'Going well', 'info' => 'FYI'] as $sev => $label)
    @if($counts->get($sev, 0) > 0)
    <span class="inline-flex items-center gap-1.5 text-xs font-mono font-semibold px-3 py-1.5 rounded-full {{ $severityStyle[$sev]['bg'] }} {{ $severityStyle[$sev]['label'] }} border {{ $severityStyle[$sev]['border'] }}">
        {{ $counts->get($sev, 0) }} {{ $label }}
    </span>
    @endif
    @endforeach
</div>

<div class="grid grid-cols-1 lg:grid-cols-2 gap-4">
    @foreach($gridCards as $card)
    @php $style = $severityStyle[$card['severity']]; @endphp
    <div class="bg-white dark:bg-slate-900 rounded-2xl border-l-4 {{ $style['border'] }} border-y border-r border-slate-200 dark:border-slate-700 shadow-sm p-5 flex items-start gap-4">
        <div class="w-10 h-10 rounded-xl {{ $style['iconBg'] }} flex items-center justify-center text-xl shrink-0">
            {{ $card['icon'] }}
        </div>
        <div class="min-w-0">
            <p class="text-[10px] font-bold uppercase tracking-widest {{ $style['label'] }} mb-1">{{ $card['category'] }}</p>
            <p class="text-sm text-slate-700 dark:text-slate-200 leading-relaxed">{{ $card['message'] }}</p>
        </div>
    </div>
    @endforeach
</div>
@endif

@endif
@endsection
