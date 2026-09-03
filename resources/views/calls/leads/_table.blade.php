<div class="bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-700 shadow-sm overflow-hidden">
    @if($leads->isEmpty())
    <div class="py-20 flex flex-col items-center justify-center gap-3">
        <svg class="w-10 h-10 text-slate-200 dark:text-slate-700" fill="none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" d="M3 5a2 2 0 012-2h3.28a1 1 0 01.948.684l1.498 4.493a1 1 0 01-.502 1.21l-2.257 1.13a11.042 11.042 0 005.516 5.517l1.13-2.257a1 1 0 011.21-.502l4.493 1.498a1 1 0 01.684.949V19a2 2 0 01-2 2h-1C9.716 21 3 14.284 3 6V5z"/>
        </svg>
        <p class="text-sm font-mono text-slate-400">No leads to show yet.</p>
        <p class="text-xs font-mono text-slate-300 dark:text-slate-600">New leads round-robin in automatically as Pancake orders come in.</p>
    </div>
    @else
    {{-- Scrollable table body (explicit request, 2026-08-26) — a full day's
         worth of leads made this table grow taller than the viewport, pushing
         the filter bar/pagination out of reach without scrolling the whole
         page. max-h-[calc(100vh-260px)]+overflow-y-auto mirrors User
         Management's own scrollable list (resources/views/user-management.
         blade.php), and the header row is sticky so column labels stay
         visible while scrolling instead of scrolling away with row 1. --}}
    <div class="overflow-x-auto max-h-[calc(100vh-260px)] overflow-y-auto">
    <table class="w-full text-sm font-mono">
        <thead class="sticky top-0 z-10 bg-slate-100 dark:bg-slate-700 border-b border-slate-200 dark:border-slate-700">
            <tr>
                {{-- Bulk select — admin-only (explicit request, 2026-08-26): the two
                     actions it drives (bulk Transfer, bulk Pin/Unpin) are both
                     admin-only now, so the checkbox itself has nothing to do for a
                     TSA. Shares one cell with the pin-toggle column instead of its
                     own (previously two narrow columns crowded side by side) —
                     tighter, and reads as one "row controls" cluster rather than
                     two unrelated icon columns. selectAllLeadsCheckbox is wired in
                     calls.js, delegated (not bound here) since this whole table
                     gets replaced wholesale on every 15s poll — see
                     pollLeadsTable()'s own comment. --}}
                <th class="w-16 px-2 py-3 text-left">
                    {{-- text-left is load-bearing here, not decorative — a bare
                         table header cell defaults to center-aligned text in every
                         browser (a plain data cell defaults left), so without this
                         the checkbox centers in its 16-unit column while the row
                         checkboxes below stay flush left, visibly misaligned. Every
                         other header cell in this table already declares text-left
                         explicitly for the same reason — this one was just missed
                         when the column was added. --}}
                    @if(auth()->user()->isAtLeastAdmin())
                    <input type="checkbox" id="selectAllLeadsCheckbox" class="w-3.5 h-3.5 rounded border-slate-300 dark:border-slate-600 text-primary focus:ring-2 focus:ring-primary/40 focus:ring-offset-0 bg-white dark:bg-slate-800 cursor-pointer">
                    @endif
                </th>
                <th class="px-4 py-3 text-left text-[11px] font-bold text-slate-400 uppercase tracking-wide">Order ID</th>
                <th class="px-4 py-3 text-left text-[11px] font-bold text-slate-400 uppercase tracking-wide">Customer</th>
                <th class="px-4 py-3 text-left text-[11px] font-bold text-slate-400 uppercase tracking-wide">Phone</th>
                <th class="px-4 py-3 text-left text-[11px] font-bold text-slate-400 uppercase tracking-wide">Product</th>
                @if(auth()->user()->isAtLeastAdmin())
                <th class="px-4 py-3 text-left text-[11px] font-bold text-slate-400 uppercase tracking-wide">TSA</th>
                @endif
                <th class="px-4 py-3 text-left text-[11px] font-bold text-slate-400 uppercase tracking-wide">Status</th>
                @if($view === 'callbacks')
                <th class="px-4 py-3 text-left text-[11px] font-bold text-slate-400 uppercase tracking-wide">Callback due</th>
                @endif
                <th class="px-4 py-3 text-left text-[11px] font-bold text-slate-400 uppercase tracking-wide">Outcome</th>
                <th class="px-4 py-3 text-left text-[11px] font-bold text-slate-400 uppercase tracking-wide">Upsell</th>
                <th class="px-4 py-3 text-left text-[11px] font-bold text-slate-400 uppercase tracking-wide">Save</th>
            </tr>
        </thead>
        <tbody class="divide-y divide-slate-100 dark:divide-slate-700">
            @foreach($leads as $lead)
            {{-- has-[.leadCheckbox:checked]: (Tailwind 4's native :has() variant) tints
                 a selected row the same primary color the bulk bar itself uses, so
                 which rows are queued for a bulk action is obvious at a glance
                 instead of only visible in the tiny checkbox square — explicit
                 request, 2026-08-26, "make it clean in the eyes of the users." Takes
                 priority over the existing pinned-row tint via source order (a
                 pinned AND selected row reads as selected while checked). --}}
            <tr data-lead-id="{{ $lead->id }}" class="hover:bg-slate-50 dark:hover:bg-slate-800 transition-colors duration-150 {{ $lead->pinned_at ? 'bg-yellow-50/60 dark:bg-yellow-900/10' : '' }} {{ auth()->user()->isAtLeastAdmin() ? 'has-[.leadCheckbox:checked]:bg-primary/10 dark:has-[.leadCheckbox:checked]:bg-primary/15' : '' }}">
                <td class="px-2 py-3">
                    <div class="flex items-center gap-1">
                        @if(auth()->user()->isAtLeastAdmin())
                        <input type="checkbox" class="leadCheckbox w-3.5 h-3.5 rounded border-slate-300 dark:border-slate-600 text-primary focus:ring-2 focus:ring-primary/40 focus:ring-offset-0 bg-white dark:bg-slate-800 cursor-pointer" data-id="{{ $lead->id }}">
                        @endif
                        {{-- Pin toggle (explicit request, 2026-08-17) — pinned leads sort
                             to the top (see LeadController::index()'s orderByRaw). Submits
                             via fetch (see calls.js) and re-polls the table immediately so
                             the reorder is visible right away instead of waiting out the
                             normal 15s poll. Button sized/colored for an easy hit target
                             (revised: the first pass was 24x24 and very faint when
                             unpinned — hard to see or click precisely) — w-9 h-9 with a
                             visible hover background, same convention as the sidebar's
                             own icon buttons elsewhere in this app. --}}
                        <form method="POST" action="{{ route('calls.leads.pin', $lead) }}" class="pin-form">
                            @csrf
                            <button type="submit" title="{{ $lead->pinned_at ? 'Unpin' : 'Pin to top' }}"
                                    class="w-9 h-9 flex items-center justify-center rounded-lg cursor-pointer transition-colors {{ $lead->pinned_at ? 'text-yellow-600 bg-yellow-50 hover:bg-yellow-100 dark:bg-yellow-900/20 dark:hover:bg-yellow-900/40' : 'text-slate-400 dark:text-slate-500 hover:text-slate-600 dark:hover:text-slate-300 hover:bg-slate-100 dark:hover:bg-slate-800' }}">
                                {{-- Bookmark-ribbon glyph (explicit request, 2026-08-19) —
                                     replaces the earlier thumbtack shape. Same fill/stroke
                                     toggle as before: filled amber when pinned, outline
                                     gray otherwise (the button's own classes above). --}}
                                <svg class="w-4 h-4" viewBox="0 0 24 24" fill="{{ $lead->pinned_at ? 'currentColor' : 'none' }}" stroke="currentColor" stroke-width="1.8">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M6.32 2.577a49.255 49.255 0 0111.36 0c1.497.174 2.57 1.46 2.57 2.93V21a.75.75 0 01-1.085.67L12 18.089l-7.165 3.583A.75.75 0 013.75 21V5.507c0-1.47 1.073-2.756 2.57-2.93z"/>
                                </svg>
                            </button>
                        </form>
                    </div>
                </td>
                <td class="px-4 py-3 text-slate-500 dark:text-slate-400 tabular-nums">#{{ $lead->pancake_order_id }}</td>
                <td class="px-4 py-3 font-semibold text-slate-700 dark:text-slate-200">
                    {{-- Modal by default (explicit request, 2026-08-25: "same
                         UI as in the POS ... pop up like a modal"), real href
                         underneath so right-click/open-in-new-tab/direct-link
                         still work — see openLeadModalFromLink() in calls.js. --}}
                    <a href="{{ route('calls.leads.show', $lead) }}" onclick="return openLeadModalFromLink(event, {{ $lead->id }})"
                       class="hover:text-primary hover:underline">{{ $lead->customer_name ?: '—' }}</a>
                </td>
                <td class="px-4 py-3">
                    <div class="flex items-center gap-2">
                        @if($lead->dialable_number)
                        <a href="tel:{{ $lead->dialable_number }}" data-name="{{ $lead->customer_name ?: 'this customer' }}"
                           data-dial-host="{{ $lead->tsa?->dialer_host }}" data-dial-number="{{ $lead->dialable_number }}" data-lead-id="{{ $lead->id }}"
                           class="inline-flex items-center gap-1.5 text-primary hover:text-primary-dark font-semibold tabular-nums">
                            <svg class="w-3.5 h-3.5 shrink-0" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M3 5a2 2 0 012-2h3.28a1 1 0 01.948.684l1.498 4.493a1 1 0 01-.502 1.21l-2.257 1.13a11.042 11.042 0 005.516 5.517l1.13-2.257a1 1 0 011.21-.502l4.493 1.498a1 1 0 01.684.949V19a2 2 0 01-2 2h-1C9.716 21 3 14.284 3 6V5z"/>
                            </svg>
                            {{ $lead->phone_number }}
                        </a>
                        @else
                        <span class="text-slate-300 dark:text-slate-600">no number</span>
                        @endif
                        {{-- Dialed indicator (explicit request, 2026-08-22) —
                             the table had no way to see whether a TSA had
                             actually called this customer yet, only whether
                             an outcome had already been logged for it
                             (called_at, a much later step — see the
                             recording button below for that one). dialed_at
                             stamps on every tel: click (LeadController::
                             logCallClick()), so this shows up the moment a
                             TSA dials, no outcome required yet. --}}
                        @if($lead->dialed_at)
                        <span class="dialed-indicator inline-flex items-center justify-center w-5 h-5 rounded-full text-emerald-600 dark:text-emerald-400 bg-emerald-50 dark:bg-emerald-950/40 shrink-0"
                              title="Called {{ $lead->dialed_at->diffForHumans() }}">
                            <svg class="w-3 h-3" fill="none" stroke="currentColor" stroke-width="3" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M4.5 12.75l6 6 9-13.5"/>
                            </svg>
                        </span>
                        @endif
                        @if($lead->pancake_page_id && $lead->pancake_conversation_id)
                        <button type="button" onclick="openConversationModal({{ $lead->id }})" title="View conversation"
                           class="inline-flex items-center justify-center w-5 h-5 rounded text-blue-500 hover:text-blue-700 dark:hover:text-blue-300 hover:bg-blue-50 dark:hover:bg-blue-950/40 cursor-pointer">
                            <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M8 12h.01M12 12h.01M16 12h.01M21 12c0 4.418-4.03 8-9 8a9.863 9.863 0 01-4.255-.949L3 20l1.395-3.72C3.512 15.042 3 13.574 3 12c0-4.418 4.03-8 9-8s9 3.582 9 8z"/>
                            </svg>
                        </button>
                        @endif
                        {{-- Listen to the call recording (explicit request, 2026-08-19)
                             — only shown once the TSA has actually called this customer
                             ($lead->called_at set by updateDisposition()); nothing to
                             play before then. Opens a popup that lists whatever Drive
                             recording(s) match this lead's phone number in real time
                             (LeadController::recordings()) rather than assuming one
                             exists — the phone's own auto-upload may not have synced
                             yet even after a real call. --}}
                        @if($lead->called_at)
                        <button type="button" onclick="openRecordingModal({{ $lead->id }})" title="Listen to call recording"
                           class="inline-flex items-center justify-center w-5 h-5 rounded text-emerald-500 hover:text-emerald-700 dark:hover:text-emerald-300 hover:bg-emerald-50 dark:hover:bg-emerald-950/40 cursor-pointer">
                            <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M12 18.75a6 6 0 006-6v-1.5m-6 7.5a6 6 0 01-6-6v-1.5m6 7.5v3.75m-3.75 0h7.5M12 15.75a3 3 0 01-3-3V4.5a3 3 0 116 0v8.25a3 3 0 01-3 3z"/>
                            </svg>
                        </button>
                        @endif
                    </div>
                </td>
                <td class="px-4 py-3 text-slate-600 dark:text-slate-300">
                    {{-- Variation badge, POS-style (explicit request,
                         2026-09-03: "5 Pterygium Drops" like the POS shows) —
                         orderBaseProducts is the locally-synced order's own
                         variation label (LeadController::index()'s own
                         comment), not a live Pancake fetch (the earlier
                         attempt at this made the page slow — reverted), so it
                         can be null when the order hasn't synced yet; falls
                         back to the local catalog product name alone, same
                         text this cell always showed before. --}}
                    @php $baseProduct = $orderBaseProducts[$lead->pancake_order_id] ?? null; @endphp
                    @if($baseProduct)
                    <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-semibold bg-pink-50 text-pink-600 dark:bg-pink-900/20 dark:text-pink-300">{{ $baseProduct }}</span>
                    @else
                    {{ $lead->product?->display_name ?? '—' }}
                    @endif
                </td>
                @if(auth()->user()->isAtLeastAdmin())
                <td class="px-4 py-3 text-slate-600 dark:text-slate-300">
                    {{-- Transfer to another TSA (explicit request, 2026-08-19) —
                         admin-only, same guard as this column itself. Submits on
                         change via fetch (see calls.js's .transfer-form listener,
                         same pattern as the pin-form above), then re-polls the
                         table so the select reflects whatever actually persisted
                         even if the request failed. --}}
                    <form method="POST" action="{{ route('calls.leads.transfer', $lead) }}" class="transfer-form">
                        @csrf
                        <select name="tsa_id" class="transfer-select text-xs font-mono bg-transparent border border-transparent hover:border-slate-300 dark:hover:border-slate-600 rounded-lg px-1.5 py-1 -ml-1.5 cursor-pointer focus:outline-none focus:ring-2 focus:ring-primary focus:border-transparent">
                            @if(!$lead->tsa_id)
                            <option value="" disabled selected>Unassigned</option>
                            @endif
                            @foreach($tsas as $tsa)
                            <option value="{{ $tsa->id }}" {{ $lead->tsa_id === $tsa->id ? 'selected' : '' }}>{{ $tsa->display_name }}</option>
                            @endforeach
                        </select>
                    </form>
                </td>
                @endif
                <td class="px-4 py-3">
                    {{-- Order status pill (explicit request, 2026-08-22) — mirrors
                         Pancake POS's own Status control (screenshot: colored pill
                         trigger + icon dropdown of real order statuses) instead of
                         this column's old called/assigned/unassigned badge, which is
                         still visible via the Outcome column's own shape (a logged
                         disposition vs. an open "+ Add tag" picker) — see
                         \App\Models\Order::STATUS_PILL/STATUS_ASSIGNABLE for the full
                         status list and colors this reads. Opens the shared floating
                         #orderStatusPanel (see calls/partials/modals.blade.php),
                         positioned under whichever pill was clicked — same
                         trigger+floating-panel shape as the Leads tab's own TSA
                         filter dropdown / status panel (openOrderStatusPill in
                         calls.js), not a centered modal, since a centered modal would
                         lose the "which row am I even changing" context a screenshot-
                         accurate anchored dropdown keeps. --}}
                    @php $orderStatusCode = $orderStatuses[$lead->pancake_order_id] ?? null; @endphp
                    @if($orderStatusCode !== null && (\App\Models\Order::STATUS_PILL[$orderStatusCode] ?? null))
                        @php $pill = \App\Models\Order::STATUS_PILL[$orderStatusCode]; @endphp
                        @if($lead->pancake_order_id && (auth()->user()->isAtLeastAdmin() || $lead->tsa_id === auth()->user()->tsa_id))
                        <button type="button"
                                onclick="openOrderStatusPill(event, {{ $lead->id }}, {{ $orderStatusCode }})"
                                data-lead-id="{{ $lead->id }}" data-status-code="{{ $orderStatusCode }}"
                                class="order-status-pill-trigger order-status-pill-{{ $lead->id }} inline-flex items-center gap-1 px-2.5 py-1 rounded-full text-[10px] font-bold uppercase tracking-wide cursor-pointer hover:opacity-80 bg-{{ $pill['color'] }}-100 dark:bg-{{ $pill['color'] }}-900/40 text-{{ $pill['color'] }}-700 dark:text-{{ $pill['color'] }}-400">
                            <span class="order-status-pill-label">{{ $pill['label'] }}</span>
                            <svg class="w-2.5 h-2.5" fill="none" stroke="currentColor" stroke-width="3" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M19 9l-7 7-7-7"/>
                            </svg>
                        </button>
                        @else
                        <span class="inline-flex px-2.5 py-1 rounded-full text-[10px] font-bold uppercase tracking-wide bg-{{ $pill['color'] }}-100 dark:bg-{{ $pill['color'] }}-900/40 text-{{ $pill['color'] }}-700 dark:text-{{ $pill['color'] }}-400">
                            {{ $pill['label'] }}
                        </span>
                        @endif
                    @else
                        <span class="text-slate-300 dark:text-slate-600">—</span>
                    @endif
                </td>
                @if($view === 'callbacks')
                <td class="px-4 py-3 text-red-600 dark:text-red-400 font-semibold">{{ $lead->callback_at?->format('M j, g:i A') }}</td>
                @endif
                <td class="px-4 py-3">
                    {{-- Real order tags (explicit request, 2026-08-22) — mirrors
                         Pancake POS's own tag chips (color dot + name + × remove),
                         reading the order's ACTUAL current tag list
                         (\App\Models\Order::raw_tags, synced locally — same "trust
                         the periodic sync" convention as the Status pill above) —
                         distinct from the disposition-picker below, which only
                         reflects what a TSA is choosing to log as THIS call's
                         outcome, not everything already on the order (TSA
                         assignment tag, product tag, any earlier upsell tag, etc).
                         Shown as a compact count badge, not inline chips (follow-up
                         fix, 2026-08-22: inline chips made a row with several real
                         tags visibly taller than every other row, breaking the
                         table's uniform row height) — click opens the shared
                         floating #realTagsPanel (calls/partials/modals.blade.php),
                         same anchored-panel pattern as the Status pill's own
                         dropdown, where the full list + remove × actually lives.
                         Removing one writes live to Pancake (LeadController::
                         removeTag(), same GET-then-PUT pattern as every other order
                         mutation on this page) — see calls.js' delegated
                         .real-tag-remove click handler. --}}
                    @php
                        $realTags = $orderTags[$lead->pancake_order_id] ?? [];
                        $realTagsPayload = collect($realTags)->map(fn ($t) => ['name' => $t, 'color' => $tagColors[strtolower($t)] ?? '#94a3b8'])->values();
                    @endphp
                    @if(count($realTags))
                    <button type="button" onclick="openRealTagsPanel(event, this)"
                            data-lead-id="{{ $lead->id }}" data-tags="{{ $realTagsPayload->toJson() }}"
                            class="real-tags-badge inline-flex items-center gap-1 px-1.5 py-0.5 mb-1.5 rounded-full text-[10px] font-mono font-semibold text-slate-500 dark:text-slate-400 border border-slate-200 dark:border-slate-700 hover:bg-slate-50 dark:hover:bg-slate-800 cursor-pointer">
                        <svg class="w-3 h-3" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M9.568 3H5.25A2.25 2.25 0 003 5.25v4.318c0 .597.237 1.17.659 1.591l9.581 9.581c.699.699 1.78.872 2.607.33a18.095 18.095 0 005.223-5.223c.542-.827.369-1.908-.33-2.607L11.16 3.66A2.25 2.25 0 009.568 3z"/>
                            <path stroke-linecap="round" stroke-linejoin="round" d="M6 6h.008v.008H6V6z"/>
                        </svg>
                        {{ count($realTags) }}
                    </button>
                    @endif
                    @if($lead->status === 'called')
                        <span class="text-slate-700 dark:text-slate-200 font-semibold">{{ $lead->disposition }}</span>
                        @if($lead->notes)
                        <p class="text-slate-400 text-xs mt-0.5">{{ $lead->notes }}</p>
                        @endif
                    @elseif($lead->tsa_id && (auth()->user()->isAtLeastAdmin() || $lead->tsa_id === auth()->user()->tsa_id))
                        {{-- Save button lives in its own last column now (explicit
                             request, 2026-08-17) — the <form> itself still only
                             wraps the tag picker + callback input, and the button
                             references it by id via the form="" attribute, which
                             is valid HTML5 and fires the exact same 'submit' event
                             the .disposition-form listener in calls.js already
                             expects (e.target is still the <form>, regardless of
                             which associated button triggered it). --}}
                        <form method="POST" action="{{ route('calls.leads.disposition', $lead) }}"
                              id="disposition-form-{{ $lead->id }}" class="flex items-center gap-2 disposition-form">
                            @csrf
                            <div class="disposition-picker w-36" data-lead-id="{{ $lead->id }}">
                                <div class="disposition-selected-chips flex flex-wrap gap-1 empty:hidden mb-1"></div>
                                <input type="hidden" name="disposition" class="disposition-hidden-input">
                                <button type="button" onclick="openOutcomeTagModal(this.closest('.disposition-picker'))"
                                        class="inline-flex items-center gap-1 text-xs font-mono text-slate-500 dark:text-slate-400 hover:text-primary-dark border border-dashed border-slate-300 dark:border-slate-600 hover:border-primary rounded-lg px-2 py-1.5 cursor-pointer">
                                    <svg class="w-3 h-3" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15"/>
                                    </svg>
                                    Add tag
                                </button>
                            </div>
                            <input type="datetime-local" name="callback_at"
                                   class="callback-at-input hidden text-xs font-mono border border-slate-300 dark:border-slate-600 rounded-lg px-2 py-1.5 bg-white dark:bg-slate-800 text-slate-800 dark:text-slate-100 focus:outline-none focus:ring-2 focus:ring-yellow-500">
                        </form>
                    @else
                        <span class="text-slate-300 dark:text-slate-600">—</span>
                    @endif
                </td>
                <td class="px-4 py-3">
                    @if($lead->pancake_order_id && (auth()->user()->isAtLeastAdmin() || $lead->tsa_id === auth()->user()->tsa_id))
                    <button type="button" onclick="openUpsellModal({{ $lead->id }})"
                            class="inline-flex items-center gap-1 text-xs font-mono text-slate-500 dark:text-slate-400 hover:text-primary-dark border border-dashed border-slate-300 dark:border-slate-600 hover:border-primary rounded-lg px-2 py-1.5 cursor-pointer">
                        <svg class="w-3 h-3" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15"/>
                        </svg>
                        Add Upsell
                    </button>
                    @else
                        <span class="text-slate-300 dark:text-slate-600">—</span>
                    @endif
                </td>
                <td class="px-4 py-3">
                    @if($lead->status !== 'called' && $lead->tsa_id && (auth()->user()->isAtLeastAdmin() || $lead->tsa_id === auth()->user()->tsa_id))
                    <button type="submit" form="disposition-form-{{ $lead->id }}"
                            class="text-xs font-semibold text-white bg-primary hover:bg-primary-dark rounded-lg px-3 py-1.5 cursor-pointer">
                        Save
                    </button>
                    @else
                        <span class="text-slate-300 dark:text-slate-600">—</span>
                    @endif
                </td>
            </tr>
            @endforeach
        </tbody>
    </table>
    </div>
    <div class="px-4 py-3 border-t border-slate-100 dark:border-slate-700">
        {{ $leads->links('partials.pagination') }}
    </div>
    @endif
</div>
