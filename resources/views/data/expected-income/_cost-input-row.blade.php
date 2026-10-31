{{-- One Tax Allocation / Product Cost row — rendered above Gross Profit on
     TSD, and Tax Allocation alone below the Shared Income row on Partners
     (see ExpectedIncomeCalculator::partnershipShareRate()). --}}
{{-- Tax Allocation on a TSA-scoped card is a computed formula from
     Cost Breakdown, never editable (explicit request, 2026-10-02: "the
     tax allocation in tsa cards is should be not editable") — same
     "computed, never editable" lock Salaries/the 20 shared pools
     already have, via $tsaScoped (the ALL view's own tsa_id-NULL cards
     have no TSA to compute anything from, so Tax Allocation stays a
     plain manual input there, same as Product Cost always is). --}}
@php($isTaxAllocationLocked = $col['key'] === 'tax_allocation' && ($tsaScoped ?? false))
<div class="grid grid-cols-[1fr_6.5rem_3.5rem] gap-x-2 items-center py-1">
    <span class="{{ ($col['blue'] ?? false) ? 'text-blue-600 dark:text-blue-400' : 'text-ink-muted dark:text-slate-400' }}">{{ $col['label'] }}</span>
    @if(($editable ?? false) && !$isTaxAllocationLocked)
        <input type="text" inputmode="decimal" value="{{ number_format($entry?->{$col['key']} ?? 0, 2) }}"
               data-field="{{ $col['key'] }}" data-money="1" @if($locked ?? false) disabled @endif
               class="ei-field w-full text-right bg-slate-50 dark:bg-slate-800 border border-line dark:border-slate-700 rounded-md px-1.5 py-0.5 text-xs font-semibold {{ ($col['blue'] ?? false) ? 'text-blue-600 dark:text-blue-400' : 'text-ink dark:text-slate-100' }} focus:ring-2 focus:ring-primary/40 focus:border-primary outline-none{{ $lockedInputClass }}">
    @else
        <span data-out="{{ $col['key'] }}" class="text-right {{ ($col['blue'] ?? false) ? 'text-blue-600 dark:text-blue-400' : 'text-ink dark:text-slate-100' }}">{{ $fmtMoney($d[$col['key']]) }}</span>
    @endif
    <span data-out="{{ $col['key'] }}_pct" class="text-right text-ink-muted dark:text-slate-500 text-xs">{{ $fmtPct($d[$col['key'] . '_pct']) }}</span>
</div>
