{{-- One prior-day slot, shown as a panel under the Aurora chart. The date
     stays calendar-picker-only: the native input is invisible and
     non-interactive, and only the icon (data-tss-date-trigger) opens the
     picker, so a click can never land on a native date segment. --}}
<div class="tsa-box tsa-day" data-tss-small data-date="{{ $date }}">
    <div class="tsa-day-top">
        <div class="tsa-day-h relative">
            <input type="date" data-tss-date-input value="{{ $date }}" max="{{ now()->toDateString() }}" class="tsa-date-input" tabindex="-1" aria-hidden="true">
            <span data-tss-date-label data-date-format="short"></span>
            <svg data-tss-date-trigger data-snapshot-hide class="tsa-cal" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" aria-label="Pick a date">
                <path stroke-linecap="round" stroke-linejoin="round" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"/>
            </svg>
        </div>
        <button type="button" data-tss-small-save data-snapshot-hide class="tsa-mini">Save</button>
    </div>
    <div class="tsa-pair">
        <div>
            <span class="tsa-k">Gross Sales</span>
            <div class="tsa-money"><span class="tsa-peso" role="img" aria-label="peso">P</span><input type="text" inputmode="decimal" data-field="gross_sales" data-money-field aria-label="Gross sales" value="{{ $summary?->gross_sales }}" placeholder="0.00" class="tsa-in"></div>
        </div>
        <div>
            <span class="tsa-k">Net Income</span>
            <div class="tsa-money tsa-pos"><span class="tsa-peso" role="img" aria-label="peso">P</span><input type="text" inputmode="decimal" data-field="net_income" data-money-field aria-label="Net income" value="{{ $summary?->net_income }}" placeholder="0.00" class="tsa-in"></div>
        </div>
    </div>
</div>
