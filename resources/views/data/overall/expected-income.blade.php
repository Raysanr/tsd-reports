@extends('layouts.data')
@section('title', 'Expected Income (SH E-COM and Partners)')
@section('subtitle', 'SH E-COM and Partners expected performance side by side')

@section('content')
@php($rangeLabel = \Illuminate\Support\Carbon::parse($dateFrom)->format('F j, Y') . ($dateFrom === $dateTo ? '' : ' – ' . \Illuminate\Support\Carbon::parse($dateTo)->format('F j, Y')))
<div class="mb-6 flex items-end justify-between gap-4 flex-wrap">
    <form method="GET" class="flex items-end gap-3 flex-wrap">
        @include('data._date-range-filter', ['fromName' => 'date_from', 'toName' => 'date_to', 'fromValue' => $dateFrom, 'toValue' => $dateTo])
    </form>
    <div class="flex items-center gap-3">
    @php($allTally = collect($tally)->flatMap(fn ($t) => $t['rows'])->every(fn ($r) => $r['tallies']))
    <button type="button" id="overallTallyBtn"
            class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg border border-slate-200 dark:border-slate-700 bg-white dark:bg-slate-900 text-xs font-mono font-semibold text-ink dark:text-slate-100 hover:border-primary/50 hover:bg-amber-50 dark:hover:bg-amber-950/20 transition-colors cursor-pointer">
        <svg class="w-3.5 h-3.5 {{ $allTally ? 'text-green-600 dark:text-green-400' : 'text-red-600 dark:text-red-400' }}" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75L11.25 15 15 9.75M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
        Tally Checker
    </button>
    @include('partials.table-actions', ['target' => 'overallIncomeTopCards', 'name' => 'sh-ecom-and-partners-expected-income', 'title' => 'SH E-COM and Partners Expected Performance', 'subtitle' => $rangeLabel, 'pngOnly' => true])
    </div>
</div>

{{-- Tally Checker (2026-11-04): each total card vs the sum of its parts. --}}
<div id="overallTallyModal" class="hidden fixed inset-0 z-[70] flex items-start sm:items-center justify-center bg-slate-900/40 px-4 py-8 overflow-y-auto">
    <div class="bg-white dark:bg-slate-900 rounded-2xl shadow-2xl border border-slate-200 dark:border-slate-700 w-full max-w-2xl">
        <div class="flex items-center justify-between px-5 py-4 border-b border-line dark:border-slate-700">
            <div>
                <h3 class="text-sm font-bold text-ink dark:text-slate-100 font-mono">Tally Checker</h3>
                <p class="text-xs font-mono text-ink-muted dark:text-slate-400 mt-0.5">{{ $rangeLabel }} · card vs sum of its cards</p>
            </div>
            <button type="button" data-tally-close aria-label="Close" class="w-8 h-8 flex items-center justify-center rounded-lg text-slate-400 hover:text-slate-700 dark:hover:text-slate-200 hover:bg-slate-100 dark:hover:bg-slate-800 transition-colors cursor-pointer">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/></svg>
            </button>
        </div>
        <div class="px-5 py-5 max-h-[70vh] overflow-y-auto space-y-6">
            @foreach($tally as $section)
            <div>
                <div class="text-xs font-mono font-bold uppercase tracking-wide text-ink dark:text-slate-100 mb-1">{{ $section['title'] }}</div>
                @if(!empty($section['note']))
                <div class="mb-2 rounded-lg border border-amber-300 bg-amber-50 dark:bg-amber-950/40 dark:border-amber-800 px-3 py-2 text-[11px] font-mono text-amber-800 dark:text-amber-300" data-tally-note>{{ $section['note'] }}</div>
                @endif
                @foreach($section['rows'] as $row)
                <div class="flex items-center justify-between gap-3 py-2.5 border-t border-line dark:border-slate-700">
                    <div class="flex items-center gap-2 min-w-0">
                        @if($row['tallies'])
                        <svg class="w-4 h-4 text-green-600 dark:text-green-400 shrink-0" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75L11.25 15 15 9.75M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                        @else
                        <svg class="w-4 h-4 text-red-600 dark:text-red-400 shrink-0" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m-9.303 3.376c-.866 1.5.217 3.374 1.948 3.374h14.71c1.73 0 2.813-1.874 1.948-3.374L13.949 3.378c-.866-1.5-3.032-1.5-3.898 0L2.697 16.126zM12 15.75h.007v.008H12v-.008z"/></svg>
                        @endif
                        <span class="text-xs font-mono font-semibold text-ink dark:text-slate-200">{{ $row['label'] }}</span>
                    </div>
                    <div class="text-right text-xs font-mono {{ $row['tallies'] ? 'text-ink-muted dark:text-slate-400' : 'text-red-600 dark:text-red-400 font-semibold' }}" data-tally-row="{{ $row['tallies'] ? 'ok' : 'mismatch' }}">
                        {{ $row['card'] }} <span class="opacity-50">vs</span> {{ $row['sum'] }}
                    </div>
                </div>
                @endforeach
            </div>
            @endforeach
        </div>
    </div>
</div>

@include('data.overall._partners-warning')

<div class="overflow-x-auto ei-scroller -mx-4 md:-mx-8 px-4 md:px-8 pb-2" id="overallIncomeScroller">
    <div class="flex items-start gap-5 w-max">
        <div class="flex items-start gap-5" id="overallIncomeTopCards">
            @foreach($topCardsHtml as $html)
            {!! $html !!}
            @endforeach
        </div>
        @foreach($partnerCardsHtml as $html)
        {!! $html !!}
        @endforeach
    </div>
</div>
@push('scripts')
<script>
(() => {
    const modal = document.getElementById('overallTallyModal');
    const close = () => modal.classList.add('hidden');
    document.getElementById('overallTallyBtn').addEventListener('click', () => modal.classList.remove('hidden'));
    modal.querySelector('[data-tally-close]').addEventListener('click', close);
    modal.addEventListener('click', (e) => { if (e.target === modal) close(); });
    document.addEventListener('keydown', (e) => { if (e.key === 'Escape') close(); });
})();
</script>
@endpush
@endsection
