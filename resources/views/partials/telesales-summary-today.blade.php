@php
    $subTeamCounts = $summary->sub_team_counts ?? [];
@endphp
<div class="tss-today rounded-xl border-2 border-amber-300 dark:border-amber-800 overflow-hidden flex-1 flex flex-col min-h-0" data-tss-today data-date="{{ $date }}">
    <div class="bg-amber-50 dark:bg-amber-950/30 px-3 py-1.5 flex items-center justify-center border-b border-amber-200 dark:border-amber-900 shrink-0">
        <input type="date" data-tss-date-input value="{{ $date }}" max="{{ now()->toDateString() }}"
               class="bg-transparent text-center text-sm font-bold font-mono text-slate-700 dark:text-slate-200 border-0 p-0 focus:ring-0 cursor-pointer">
    </div>

    {{-- Gross Sales / Daily Net Income --}}
    <div class="grid grid-cols-2 divide-x divide-amber-200 dark:divide-amber-900 border-b border-amber-200 dark:border-amber-900 shrink-0">
        <div class="px-3 py-2 text-center">
            <p class="text-[10px] font-mono font-semibold text-slate-400 uppercase tracking-wide mb-1">Gross Sales</p>
            <div class="flex items-center justify-center gap-1">
                <span class="text-base font-mono text-slate-400">₱</span>
                <input type="number" step="0.01" min="0" data-field="gross_sales"
                       value="{{ $summary?->gross_sales }}" placeholder="0.00"
                       class="w-32 bg-white dark:bg-slate-800 border border-slate-200 dark:border-slate-600 rounded-md px-2 py-1 text-center text-lg font-bold font-mono text-slate-800 dark:text-slate-100 focus:ring-2 focus:ring-primary focus:border-primary transition-colors" style="font-variant-numeric: tabular-nums">
            </div>
        </div>
        <div class="px-3 py-2 text-center">
            <p class="text-[10px] font-mono font-semibold text-slate-400 uppercase tracking-wide mb-1">Daily Net Income</p>
            <div class="flex items-center justify-center gap-1">
                <span class="text-base font-mono text-slate-400">₱</span>
                <input type="number" step="0.01" data-field="net_income"
                       value="{{ $summary?->net_income }}" placeholder="0.00"
                       class="w-32 bg-white dark:bg-slate-800 border border-slate-200 dark:border-slate-600 rounded-md px-2 py-1 text-center text-lg font-bold font-mono text-slate-800 dark:text-slate-100 focus:ring-2 focus:ring-primary focus:border-primary transition-colors" style="font-variant-numeric: tabular-nums">
            </div>
        </div>
    </div>

    {{-- Top Seller / Top Team --}}
    <div class="grid grid-cols-2 divide-x divide-amber-200 dark:divide-amber-900 border-b border-amber-200 dark:border-amber-900 shrink-0">
        <div class="px-3 py-2">
            <p class="text-[10px] font-mono font-semibold text-amber-500 uppercase tracking-wide mb-1 text-center">🏆 Top Seller of the Day!</p>
            <input type="text" data-field="top_seller_name" placeholder="TSA name"
                   value="{{ $summary?->top_seller_name }}"
                   class="w-full bg-transparent text-center text-sm font-bold font-mono text-slate-800 dark:text-slate-100 border-0 border-b border-dashed border-slate-200 dark:border-slate-700 px-0 py-0.5 mb-1 focus:ring-0 focus:border-primary">
            <div class="flex items-center justify-center gap-3 text-xs font-mono">
                <label class="flex items-center gap-1 text-slate-400">Gross ₱
                    <input type="number" step="0.01" min="0" data-field="top_seller_gross_sales"
                           value="{{ $summary?->top_seller_gross_sales }}" placeholder="0.00"
                           class="w-20 bg-white dark:bg-slate-800 border border-slate-200 dark:border-slate-600 rounded px-1.5 py-0.5 text-slate-700 dark:text-slate-200 focus:ring-1 focus:ring-primary">
                </label>
                <label class="flex items-center gap-1 text-slate-400">Net ₱
                    <input type="number" step="0.01" data-field="top_seller_net_income"
                           value="{{ $summary?->top_seller_net_income }}" placeholder="0.00"
                           class="w-20 bg-white dark:bg-slate-800 border border-slate-200 dark:border-slate-600 rounded px-1.5 py-0.5 text-slate-700 dark:text-slate-200 focus:ring-1 focus:ring-primary">
                </label>
            </div>
        </div>

        <div class="px-3 py-2">
            <p class="text-[10px] font-mono font-semibold text-cyan-600 uppercase tracking-wide mb-1 text-center">🥇 Top Team of the Day</p>
            <input type="text" data-field="top_team_name" placeholder="Team name"
                   value="{{ $summary?->top_team_name }}"
                   class="w-full bg-transparent text-center text-sm font-bold font-mono text-slate-800 dark:text-slate-100 border-0 border-b border-dashed border-slate-200 dark:border-slate-700 px-0 py-0.5 mb-1 focus:ring-0 focus:border-primary">
            <div class="flex items-center justify-center gap-3 text-xs font-mono">
                <label class="flex items-center gap-1 text-slate-400">Gross ₱
                    <input type="number" step="0.01" min="0" data-field="top_team_gross_sales"
                           value="{{ $summary?->top_team_gross_sales }}" placeholder="0.00"
                           class="w-20 bg-white dark:bg-slate-800 border border-slate-200 dark:border-slate-600 rounded px-1.5 py-0.5 text-slate-700 dark:text-slate-200 focus:ring-1 focus:ring-primary">
                </label>
                <label class="flex items-center gap-1 text-slate-400">Net ₱
                    <input type="number" step="0.01" data-field="top_team_net_income"
                           value="{{ $summary?->top_team_net_income }}" placeholder="0.00"
                           class="w-20 bg-white dark:bg-slate-800 border border-slate-200 dark:border-slate-600 rounded px-1.5 py-0.5 text-slate-700 dark:text-slate-200 focus:ring-1 focus:ring-primary">
                </label>
            </div>
        </div>
    </div>

    {{-- Sub-team counts + Overall Working TSA's — plain stacked list matching
         the whiteboard's own handwritten layout EXACTLY (explicit request,
         2026-09-09, "IT SHOULD BE LIKE THIS": "Team Lhiza - 4" / "Team
         Gretchen - 5" / "Overall Working TSA's - Ⓝ", each its own centered
         line, Overall's count circled) — not the pill/chip rows this used to
         render. flex-1 so this section fills any remaining vertical space. --}}
    <div class="px-3 py-2 bg-amber-50/40 dark:bg-amber-950/10 flex-1 flex flex-col justify-center min-h-0">
        <div data-subteam-rows class="flex flex-col items-center gap-1.5 mb-2">
            @foreach($subTeamCounts as $row)
            <div class="group flex items-center gap-2 text-sm font-mono" data-subteam-row>
                <input type="text" data-subteam-name value="{{ $row['name'] }}" placeholder="Team name"
                       class="text-right font-semibold text-slate-700 dark:text-slate-200 bg-transparent border-0 border-b border-transparent hover:border-slate-200 dark:hover:border-slate-700 focus:border-primary px-0 py-0 focus:ring-0" style="width: 16ch">
                <span class="text-slate-400">-</span>
                <input type="number" min="0" data-subteam-count value="{{ $row['count'] }}" placeholder="0"
                       class="w-10 text-center font-bold text-slate-700 dark:text-slate-200 bg-transparent border-0 border-b border-transparent hover:border-slate-200 dark:hover:border-slate-700 focus:border-primary px-0 py-0 focus:ring-0" style="font-variant-numeric: tabular-nums">
                <button type="button" data-subteam-remove
                        class="opacity-0 group-hover:opacity-100 text-slate-300 hover:text-red-500 transition-opacity cursor-pointer">
                    <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/>
                    </svg>
                </button>
            </div>
            @endforeach
            <button type="button" data-subteam-add data-snapshot-hide
                    class="inline-flex items-center gap-1 px-2 py-0.5 rounded-lg text-xs font-mono font-semibold text-primary border border-dashed border-primary/40 hover:bg-primary/5 transition-colors cursor-pointer">
                <svg class="w-3 h-3" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15"/>
                </svg>
                Add team
            </button>
        </div>

        <div class="flex items-center justify-center gap-2 pt-2 border-t border-amber-200 dark:border-amber-900 text-sm font-mono">
            <span class="font-bold text-slate-700 dark:text-slate-200">Overall Working TSA's</span>
            <span class="text-slate-400">-</span>
            <input type="number" min="0" data-field="overall_working_tsas"
                   value="{{ $summary?->overall_working_tsas }}" placeholder="0"
                   class="w-14 h-9 rounded-full border-2 border-primary text-center font-bold font-mono text-primary bg-transparent focus:ring-2 focus:ring-primary focus:outline-none" style="font-variant-numeric: tabular-nums">

            <button type="button" data-tss-today-save
                    class="ml-3 inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg text-xs font-semibold font-mono bg-primary text-white hover:opacity-90 transition-opacity cursor-pointer disabled:opacity-50 disabled:cursor-wait">
                <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M4.5 12.75l6 6 9-13.5"/>
                </svg>
                Save
            </button>
        </div>
    </div>
</div>
