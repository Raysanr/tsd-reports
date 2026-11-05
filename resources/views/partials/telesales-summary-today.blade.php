@php
    // Keyed by slug so a saved count survives a team rename (the stored
    // JSON also carries the name AS OF when it was saved, but slug is what
    // ties a row back to $teams — see storeTelesalesSummary()'s own comment).
    $savedCountsBySlug = collect($summary->sub_team_counts ?? [])->keyBy('slug');
@endphp
{{-- Keep the hero gross/net inputs above the nested small slots in the DOM:
     tssField() returns the first match, so today's Save reads them by order. --}}
<div data-tss-today data-date="{{ $date }}">
    <div class="tsa-pad">
        <div class="tsa-head">
            <h2>TELESALES DEPARTMENT</h2>
            <div class="tsa-head-r">
                <div class="tsa-date relative">
                    <input type="date" data-tss-date-input value="{{ $date }}" max="{{ now()->toDateString() }}" class="tsa-date-input" tabindex="-1" aria-hidden="true">
                    <span data-tss-date-label data-date-format="full"></span>
                    <svg data-tss-date-trigger data-snapshot-hide class="tsa-cal" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" aria-label="Pick a date">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"/>
                    </svg>
                </div>
                <button type="button" data-export-png="telesalesSummaryCard" data-export-name="telesales-department" data-export-bg="transparent" data-export-keep-theme data-snapshot-hide
                        title="Save as image" aria-label="Save summary as image" class="tsa-icon">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M6.827 6.175A2.31 2.31 0 015.186 7.23c-.38.054-.757.112-1.134.175C2.999 7.58 2.25 8.507 2.25 9.574V18a2.25 2.25 0 002.25 2.25h15A2.25 2.25 0 0021.75 18V9.574c0-1.067-.75-1.994-1.802-2.169a47.865 47.865 0 00-1.134-.175 2.31 2.31 0 01-1.64-1.055l-.822-1.316a2.192 2.192 0 00-1.736-1.039 48.774 48.774 0 00-5.232 0 2.192 2.192 0 00-1.736 1.039l-.821 1.316z"/>
                        <path stroke-linecap="round" stroke-linejoin="round" d="M16.5 12.75a4.5 4.5 0 11-9 0 4.5 4.5 0 019 0z"/>
                    </svg>
                </button>
            </div>
        </div>

        <div class="tsa-tot">
            <div>
                <div class="tsa-k">Gross sales today</div>
                <div class="tsa-g tsa-money"><span class="tsa-peso" role="img" aria-label="peso">P</span><input type="text" inputmode="decimal" data-field="gross_sales" data-money-field aria-label="Gross sales today" value="{{ $summary?->gross_sales }}" placeholder="0.00" class="tsa-in"></div>
            </div>
            <div>
                <div class="tsa-k">Net income today</div>
                <div class="tsa-n tsa-money"><span class="tsa-peso" role="img" aria-label="peso">P</span><input type="text" inputmode="decimal" data-field="net_income" data-money-field aria-label="Net income today" value="{{ $summary?->net_income }}" placeholder="0.00" class="tsa-in"></div>
                <span class="tsa-up" data-tsa-delta></span>
            </div>
        </div>

        <div class="tsa-plot" data-tsa-plot></div>
    </div>

    <div class="tsa-days">
        @foreach($priorDates as $i => $priorDate)
            @include('partials.telesales-summary-small', ['date' => $priorDate, 'summary' => $priorSummaries[$i]])
        @endforeach
    </div>

    <div class="tsa-stats">
        <div><span class="tsa-k">Net margin</span><b data-tsa-margin>—</b></div>
        @foreach($teams as $team)
            @php $saved = $savedCountsBySlug->get($team['slug']); @endphp
            <div data-subteam-row data-subteam-slug="{{ $team['slug'] }}" data-subteam-name="{{ $team['name'] }}">
                <label class="tsa-k" for="tsa-count-{{ $team['slug'] }}">{{ $team['name'] }}</label>
                <input id="tsa-count-{{ $team['slug'] }}" type="number" min="0" data-subteam-count value="{{ $saved['count'] ?? '' }}" placeholder="0" class="tsa-in">
            </div>
        @endforeach
        <div><span class="tsa-k">Working TSAs</span><b data-overall-tsas>{{ $savedCountsBySlug->sum('count') ?: 0 }}</b></div>
    </div>

    <div class="tsa-duo">
        @foreach([['seller', 'top_seller', 'Top seller of the day', 'TSA name'], ['team', 'top_team', 'Top team of the day', 'Team name']] as [$key, $field, $title, $placeholder])
            <div class="tsa-box">
                <div class="tsa-who">
                    <div class="tsa-ava" data-tsa-avatar="{{ $key }}">—</div>
                    <div>
                        <div class="tsa-k">{{ $title }}</div>
                        <input type="text" data-field="{{ $field }}_name" aria-label="{{ $title }}" placeholder="{{ $placeholder }}" value="{{ $summary?->{$field.'_name'} }}" class="tsa-in tsa-name">
                    </div>
                </div>
                <div class="tsa-pair">
                    <div>
                        <span class="tsa-k">Gross Sales</span>
                        <div class="tsa-money"><span class="tsa-peso" role="img" aria-label="peso">P</span><input type="text" inputmode="decimal" data-field="{{ $field }}_gross_sales" data-money-field aria-label="{{ $title }} gross" value="{{ $summary?->{$field.'_gross_sales'} }}" placeholder="0.00" class="tsa-in"></div>
                    </div>
                    <div>
                        <span class="tsa-k">Net Income</span>
                        <div class="tsa-money tsa-pos"><span class="tsa-peso" role="img" aria-label="peso">P</span><input type="text" inputmode="decimal" data-field="{{ $field }}_net_income" data-money-field aria-label="{{ $title }} net" value="{{ $summary?->{$field.'_net_income'} }}" placeholder="0.00" class="tsa-in"></div>
                    </div>
                </div>
                <div class="tsa-bar"><i data-tsa-bar="{{ $key }}"></i></div>
                <div class="tsa-k" data-tsa-share="{{ $key }}"></div>
            </div>
        @endforeach
    </div>

    <div class="tsa-save-row" data-snapshot-hide>
        <button type="button" data-tss-today-save class="tsa-save">
            <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2.4" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M4.5 12.75l6 6 9-13.5"/></svg>
            Save today
        </button>
    </div>
</div>
