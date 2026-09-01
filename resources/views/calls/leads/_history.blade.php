{{-- Extracted from _detail.blade.php (explicit follow-up: "i want real
     time like in the pos in all leads detail history") into its own
     partial so LeadController::history() can return just this card's HTML
     for polling, without re-rendering (and disrupting) the rest of the
     modal — Products/Tags/Delivery/Notes stay exactly as the TSA left
     them while only this panel refreshes. Same $lead/$liveOrder inputs as
     the parent, unchanged formatting/fallback logic — this is a pure
     extraction, not a rewrite. initHistoryPanel() (calls.js) starts the
     poll and re-binds this on every modal open, same pattern
     initPancakeNotesPanel() already uses for its own 8s poll. --}}
@php
    // Restyled to match Pancake POS's own order-history popup (explicit
    // follow-up: "add history like in the POS (status, detail)", then
    // "can you fetch the history from pos?" once it became clear the
    // local LeadActivity log — only what THIS app did — was far too
    // sparse next to Pancake's own real history, which includes every
    // edit made directly in POS or via the API too). Detail is real
    // Pancake data (PancakeOrderHistoryFormatter, fed by
    // $liveOrder['histories']), Status is $liveOrder['status_history'] —
    // both already fetched in the same getOrderDetail() call this page
    // already makes, no second request. Falls back to the local
    // LeadActivity log when $liveOrder is unavailable (Pancake
    // unreachable, or no linked order yet) — something beats nothing,
    // same fallback reasoning the Product/Tags cards on the parent
    // already use for the same condition. "Message" isn't included: this
    // app has no messaging/SMS feature, so there is no real data to put
    // there.
    // $historyRows/$statusHistory null (not just empty) is the "Pancake
    // unreachable, fall back to local data" signal both tabs below check
    // for — distinct from a genuinely empty real result, same null-vs-
    // empty contract the parent's Product/Tags cards already use for the
    // same $liveOrder-unreachable condition.
    $historyRows = $liveOrder ? \App\Support\PancakeOrderHistoryFormatter::format($liveOrder) : null;
    // Oldest-first (not sortByDesc like Detail below) — confirmed against
    // Pancake's own real Status tab, which reads as a chronological
    // timeline top-to-bottom (e.g. "New" at 11:31 above "Ordered" at
    // 13:08), unlike Detail's own newest-first convention. The two tabs
    // are allowed to differ since Pancake's own real UI does.
    $statusHistory = $liveOrder ? collect($liveOrder['status_history'] ?? [])->sortBy('updated_at')->values() : null;
    $fallbackActivities = $lead->activities->sortByDesc('created_at')->values();
@endphp
<div class="bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-700 p-4 history-panel" id="historyPanel" data-lead-id="{{ $lead->id }}">
    <div class="flex items-center gap-4 border-b border-slate-100 dark:border-slate-700 mb-3 -mx-4 px-4">
        <button type="button" class="history-tab-btn text-xs font-semibold pb-2.5 pt-1 border-b-2 border-transparent text-slate-400 hover:text-slate-600 dark:hover:text-slate-300 cursor-pointer" data-tab="status" onclick="switchHistoryTab(this, 'status')">Status</button>
        <button type="button" class="history-tab-btn active text-xs font-semibold pb-2.5 pt-1 border-b-2 border-primary text-primary-dark dark:text-yellow-300 cursor-pointer" data-tab="detail" onclick="switchHistoryTab(this, 'detail')">Detail</button>
    </div>

    {{-- Bug fix: this panel was rendered VISIBLE by default (no `hidden`)
         while the Detail tab button was marked `active` — the two were
         never in sync, so every render (initial load AND every history
         poll) actually showed Status's sparse content under what looked
         like an active "Detail" tab. Real symptom this caused: real
         Detail data ("Add tag X", "Edit internal note...") would show
         only when a TSA happened to click into Detail manually (which
         correctly toggles both the button AND panel via
         window.switchHistoryTab), but any fresh render — including every
         8s poll — reverted back to this default, mismatched state,
         reading exactly like "Detail flickers back into Status's content
         after a few seconds." --}}
    <div class="history-tab-panel hidden" data-panel="status">
        @if($statusHistory !== null && $statusHistory->isNotEmpty())
        {{-- Rebuilt to match Pancake's own real Status tab (explicit
             follow-up, comparing screenshots side by side): a colored
             status pill (not "from X to Y" sentence text) per entry, a
             connecting timeline line/dot between entries, an actor avatar
             (real avatar_url when Pancake has one, else an initial-letter
             circle — matches Pancake's own "S / System" fallback for a
             system-driven status like the very first "New"), and same-
             calendar-day entries showing time only, matching Pancake's
             own "11:31" / "13:08" with no date repeated. --}}
        <ul class="relative">
            @foreach($statusHistory as $entry)
            @php
                // STATUS_PILL (not STATUS_LABELS — the two maps disagree,
                // e.g. code 20 is "Purchased" in one and "Ordered" in the
                // other; STATUS_PILL is the one actually driving this
                // page's own visible Status pill elsewhere in this modal).
                $pill = \App\Models\Order::STATUS_PILL[$entry['status'] ?? null] ?? null;
                $pillLabel = $pill['label'] ?? ('#' . ($entry['status'] ?? '?'));
                $pillColor = $pill['color'] ?? 'slate';
                // Pancake's raw updated_at has no timezone suffix (confirmed
                // live: "2026-09-01T05:08:09") but IS UTC — verified against
                // this exact order's real Pancake popup showing "13:08" for
                // this same timestamp (05:08 UTC + 8h = 13:08 Manila).
                // Carbon::parse() alone would otherwise display the raw
                // 05:08 unconverted, 8 hours off from what Pancake's own UI
                // (and this app's own Asia/Manila app timezone) shows.
                $entryTime = \Illuminate\Support\Carbon::parse($entry['updated_at'], 'UTC')->setTimezone('Asia/Manila');
                $editorName = $entry['editor']['name'] ?? 'System';
                $editorAvatar = $entry['editor']['avatar_url'] ?? null;
            @endphp
            <li class="relative flex items-start gap-3 pb-5 last:pb-0">
                @unless($loop->last)
                {{-- Connecting line between this dot and the next entry's —
                     matches Pancake's own timeline, absolutely positioned
                     behind the dot/avatar column so it reads as one
                     continuous line down the list, not per-row segments. --}}
                <span class="absolute left-[7px] top-4 bottom-0 w-px bg-slate-200 dark:bg-slate-700"></span>
                @endunless
                <span class="relative z-10 mt-1 w-3.5 h-3.5 rounded-full border-2 border-primary bg-white dark:bg-slate-900 shrink-0"></span>
                <div class="min-w-0 flex-1">
                    <span class="inline-flex items-center px-2.5 py-1 rounded-full text-[11px] font-bold uppercase tracking-wide bg-{{ $pillColor }}-100 dark:bg-{{ $pillColor }}-900/40 text-{{ $pillColor }}-700 dark:text-{{ $pillColor }}-400">
                        {{ $pillLabel }}
                    </span>
                    <div class="flex items-center gap-1.5 mt-1.5 text-xs">
                        <svg class="w-3.5 h-3.5 text-slate-400 shrink-0" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                        <span class="text-slate-400">
                            {{ $entryTime->isToday() ? $entryTime->format('g:i A') : $entryTime->format('M j, g:i A') }}
                        </span>
                    </div>
                    <div class="flex items-center gap-1.5 mt-1">
                        @if($editorAvatar)
                        <img src="{{ $editorAvatar }}" alt="" class="w-5 h-5 rounded-full object-cover shrink-0">
                        @else
                        <span class="w-5 h-5 rounded-full bg-teal-700 text-white text-[10px] font-bold flex items-center justify-center shrink-0">{{ strtoupper(substr($editorName, 0, 1)) }}</span>
                        @endif
                        <span class="text-xs font-semibold text-primary-dark dark:text-yellow-300">{{ $editorName }}</span>
                    </div>
                </div>
            </li>
            @endforeach
        </ul>
        @elseif($statusHistory !== null)
        <p class="text-xs text-slate-400">No status history yet.</p>
        @elseif($fallbackActivities->isEmpty())
        <p class="text-xs text-slate-400">No activity recorded yet.</p>
        @else
        {{-- Pancake unreachable — falls back to this app's own local
             activity log (something beats nothing, same reasoning the
             parent's Product/Tags cards already use). --}}
        <ul class="space-y-3">
            @foreach($fallbackActivities as $activity)
            <li class="flex items-start gap-2.5 text-xs">
                <svg class="w-3.5 h-3.5 text-slate-400 mt-0.5 shrink-0" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                <div class="min-w-0">
                    <p class="text-slate-700 dark:text-slate-200">{{ $activity->description }}</p>
                    <p class="text-slate-400 mt-0.5">{{ $activity->created_at->format('M j, g:i A') }}</p>
                </div>
            </li>
            @endforeach
        </ul>
        @endif
    </div>

    <div class="history-tab-panel" data-panel="detail">
        @if($historyRows !== null && $historyRows->isNotEmpty())
        <ul class="space-y-3">
            @foreach($historyRows as $row)
            <li class="flex items-start gap-2.5 text-xs">
                <svg class="w-3.5 h-3.5 text-slate-400 mt-0.5 shrink-0" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"/></svg>
                <div class="min-w-0">
                    @foreach($row['sentences'] as $sentence)
                    <p class="text-slate-700 dark:text-slate-200 whitespace-pre-line">{{ $sentence }}</p>
                    @endforeach
                    <p class="text-slate-400 mt-0.5">
                        {{-- Same UTC-source, Asia/Manila-display fix as
                             the Status tab above — Pancake's raw
                             updated_at has no timezone suffix but is UTC. --}}
                        {{ $row['updated_at'] ? \Illuminate\Support\Carbon::parse($row['updated_at'], 'UTC')->setTimezone('Asia/Manila')->format('M j, g:i A') : '—' }}
                        · {{ $row['editor_name'] }}
                    </p>
                </div>
            </li>
            @endforeach
        </ul>
        @elseif($historyRows !== null)
        <p class="text-xs text-slate-400">No detail changes yet.</p>
        @elseif($fallbackActivities->isEmpty())
        <p class="text-xs text-slate-400">No activity recorded yet.</p>
        @else
        {{-- Pancake unreachable — same local-log fallback as the Status
             tab above. --}}
        <ul class="space-y-3">
            @foreach($fallbackActivities as $activity)
            <li class="flex items-start gap-2.5 text-xs">
                <svg class="w-3.5 h-3.5 text-slate-400 mt-0.5 shrink-0" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"/></svg>
                <div class="min-w-0">
                    <p class="text-slate-700 dark:text-slate-200">{{ $activity->description }}</p>
                    <p class="text-slate-400 mt-0.5">{{ $activity->created_at->format('M j, g:i A') }}</p>
                </div>
            </li>
            @endforeach
        </ul>
        @endif
    </div>
</div>
