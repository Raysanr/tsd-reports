@php
    // Keyed by slug so a saved count survives a team rename (the stored
    // JSON also carries the name AS OF when it was saved, but slug is what
    // ties a row back to $teams — see storeTelesalesSummary()'s own comment).
    $savedCountsBySlug = collect($summary->sub_team_counts ?? [])->keyBy('slug');
@endphp
<div class="tss-today rounded-xl border-2 border-black overflow-hidden flex flex-col" data-tss-today data-date="{{ $date }}">
    {{-- Same calendar-picker-only trick as the small columns' own partial
         (see that file's own comment for the full "why" — 2026-09-11
         follow-up fixing a native segment/label visual clash). --}}
    <div class="relative bg-amber-50 dark:bg-amber-950/30 px-3 py-1.5 flex items-center justify-center border-b border-black shrink-0 cursor-pointer" data-tss-date-trigger>
        <input type="date" data-tss-date-input value="{{ $date }}" max="{{ now()->toDateString() }}"
               class="absolute inset-0 w-full h-full opacity-0 pointer-events-none border-0 p-0" tabindex="-1" aria-hidden="true">
        <span data-tss-date-label class="text-sm font-bold font-mono text-slate-700 dark:text-slate-200"></span>
    </div>

    {{-- Gross Sales / Daily Net Income --}}
    <div class="grid grid-cols-2 divide-x divide-black border-b border-black shrink-0">
        <div class="px-3 py-2 text-center">
            <p class="text-[10px] font-mono font-semibold text-slate-400 uppercase tracking-wide mb-1">Gross Sales</p>
            <div class="flex items-center justify-center gap-1">
                <span class="text-base font-mono text-slate-400">₱</span>
                <input type="text" inputmode="decimal" data-field="gross_sales" data-money-field
                       value="{{ $summary?->gross_sales }}" placeholder="0.00"
                       class="w-32 bg-white dark:bg-slate-800 border border-black rounded-md px-2 py-1 text-center text-lg font-bold font-mono text-slate-800 dark:text-slate-100 focus:ring-2 focus:ring-primary focus:border-primary transition-colors" style="font-variant-numeric: tabular-nums">
            </div>
        </div>
        <div class="px-3 py-2 text-center">
            <p class="text-[10px] font-mono font-semibold text-slate-400 uppercase tracking-wide mb-1">Daily Net Income</p>
            <div class="flex items-center justify-center gap-1">
                <span class="text-base font-mono text-slate-400">₱</span>
                <input type="text" inputmode="decimal" data-field="net_income" data-money-field
                       value="{{ $summary?->net_income }}" placeholder="0.00"
                       class="w-32 bg-white dark:bg-slate-800 border border-black rounded-md px-2 py-1 text-center text-lg font-bold font-mono text-slate-800 dark:text-slate-100 focus:ring-2 focus:ring-primary focus:border-primary transition-colors" style="font-variant-numeric: tabular-nums">
            </div>
        </div>
    </div>

    {{-- Top Seller / Top Team --}}
    <div class="grid grid-cols-2 divide-x divide-black border-b border-black shrink-0">
        <div class="px-3 py-2">
            <p class="text-[10px] font-mono font-semibold text-amber-500 uppercase tracking-wide mb-1 text-center">🏆 Top Seller of the Day!</p>
            <input type="text" data-field="top_seller_name" placeholder="TSA name"
                   value="{{ $summary?->top_seller_name }}"
                   class="w-full bg-transparent text-center text-sm font-bold font-mono text-slate-800 dark:text-slate-100 border-0 border-b border-dashed border-black px-0 py-0.5 mb-1 focus:ring-0 focus:border-primary">
            <div class="flex items-center justify-center gap-3 text-xs font-mono">
                <label class="flex items-center gap-1 text-slate-400">Gross ₱
                    <input type="text" inputmode="decimal" data-field="top_seller_gross_sales" data-money-field
                           value="{{ $summary?->top_seller_gross_sales }}" placeholder="0.00"
                           class="w-20 bg-white dark:bg-slate-800 border border-black rounded px-1.5 py-0.5 text-slate-700 dark:text-slate-200 focus:ring-1 focus:ring-primary">
                </label>
                <label class="flex items-center gap-1 text-slate-400">Net ₱
                    <input type="text" inputmode="decimal" data-field="top_seller_net_income" data-money-field
                           value="{{ $summary?->top_seller_net_income }}" placeholder="0.00"
                           class="w-20 bg-white dark:bg-slate-800 border border-black rounded px-1.5 py-0.5 text-slate-700 dark:text-slate-200 focus:ring-1 focus:ring-primary">
                </label>
            </div>
        </div>

        <div class="px-3 py-2">
            <p class="text-[10px] font-mono font-semibold text-cyan-600 uppercase tracking-wide mb-1 text-center">🥇 Top Team of the Day</p>
            <input type="text" data-field="top_team_name" placeholder="Team name"
                   value="{{ $summary?->top_team_name }}"
                   class="w-full bg-transparent text-center text-sm font-bold font-mono text-slate-800 dark:text-slate-100 border-0 border-b border-dashed border-black px-0 py-0.5 mb-1 focus:ring-0 focus:border-primary">
            <div class="flex items-center justify-center gap-3 text-xs font-mono">
                <label class="flex items-center gap-1 text-slate-400">Gross ₱
                    <input type="text" inputmode="decimal" data-field="top_team_gross_sales" data-money-field
                           value="{{ $summary?->top_team_gross_sales }}" placeholder="0.00"
                           class="w-20 bg-white dark:bg-slate-800 border border-black rounded px-1.5 py-0.5 text-slate-700 dark:text-slate-200 focus:ring-1 focus:ring-primary">
                </label>
                <label class="flex items-center gap-1 text-slate-400">Net ₱
                    <input type="text" inputmode="decimal" data-field="top_team_net_income" data-money-field
                           value="{{ $summary?->top_team_net_income }}" placeholder="0.00"
                           class="w-20 bg-white dark:bg-slate-800 border border-black rounded px-1.5 py-0.5 text-slate-700 dark:text-slate-200 focus:ring-1 focus:ring-primary">
                </label>
            </div>
        </div>
    </div>

    {{-- Per-team working-TSA counts + auto-summed Overall — plain stacked
         list matching the whiteboard's own handwritten layout (explicit
         request, 2026-09-09/10: "Team Lhiza - 4" / "Team Gretchen - 5" /
         "Overall Working TSA's - Ⓝ") — fixed to the app's own current teams
         now, not a free-form add/remove list (follow-up, 2026-09-10:
         "remove add team and then... make like the current teams like team
         opening and closing"). Each team's own count is still manually
         typed in; "Overall" is derived (read-only, data-overall-tsas) as the
         live sum of those two fields as they're edited (app.js), not a
         third manually-typed number that could disagree with them. --}}
    <div class="px-4 py-4 bg-amber-50/40 dark:bg-amber-950/10">
        <div data-subteam-rows class="flex flex-col items-center gap-1.5 mb-2">
            @foreach($teams as $team)
            @php $saved = $savedCountsBySlug->get($team['slug']); @endphp
            <div class="flex items-center gap-2 text-sm font-mono" data-subteam-row data-subteam-slug="{{ $team['slug'] }}" data-subteam-name="{{ $team['name'] }}">
                <span class="text-right font-semibold text-slate-700 dark:text-slate-200" style="width: 16ch">{{ $team['name'] }}</span>
                <span class="text-slate-400">-</span>
                <input type="number" min="0" data-subteam-count value="{{ $saved['count'] ?? '' }}" placeholder="0"
                       class="w-10 text-center font-bold text-slate-700 dark:text-slate-200 bg-transparent border-0 border-b border-transparent hover:border-black focus:border-primary px-0 py-0 focus:ring-0" style="font-variant-numeric: tabular-nums">
            </div>
            @endforeach
        </div>

        <div class="flex items-center justify-center gap-2 pt-2 border-t border-black text-sm font-mono">
            <span class="font-bold text-slate-700 dark:text-slate-200">Overall Working TSA's</span>
            <span class="text-slate-400">-</span>
            <span data-overall-tsas
                  class="inline-flex items-center justify-center w-14 h-9 rounded-full border-2 border-black text-center font-bold font-mono text-primary" style="font-variant-numeric: tabular-nums">
                {{ $savedCountsBySlug->sum('count') ?: 0 }}
            </span>

            <button type="button" data-tss-today-save data-snapshot-hide
                    class="ml-3 inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg text-xs font-semibold font-mono bg-primary text-white hover:opacity-90 transition-opacity cursor-pointer disabled:opacity-50 disabled:cursor-wait">
                <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M4.5 12.75l6 6 9-13.5"/>
                </svg>
                Save
            </button>
        </div>
    </div>
</div>
