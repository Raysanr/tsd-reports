{{-- Lead detail content — shared by the full-page view (calls/leads/show.blade.php,
     kept for direct links/bookmarks) and the modal popup (opened from the Leads
     table via openLeadModal(), see calls.js). Redesigned 2026-08-25 (explicit
     request: "same UI as in the POS ... pop up like a modal") after Pancake's own
     order popup — a wide two-column layout (order/product left, customer/activity
     right) instead of the old single dl-based Details card.

     Expects $lead (with product/tsa/calledBy/activities.user already loaded) and
     $order — the matching row in the separate TSD Reports `orders` table (same
     pancake_order_id, a different local sync pipeline than Leads — see
     LeadController::show()'s own comment), used only for the product/price card
     below since Lead itself never stores an amount. Null when that order hasn't
     synced locally yet — the card just falls back to the Product catalog name
     with no price rather than erroring. --}}
@php
    $canManage = auth()->user()->isAtLeastAdmin() || $lead->tsa_id === auth()->user()->tsa_id;
    $statusStyles = [
        'called'     => 'bg-emerald-100 dark:bg-emerald-900/40 text-emerald-700 dark:text-emerald-400',
        'assigned'   => 'bg-sky-100 dark:bg-sky-900/40 text-sky-700 dark:text-sky-400',
        'unassigned' => 'bg-slate-100 dark:bg-slate-700 text-slate-500 dark:text-slate-400',
    ];
@endphp

<div class="flex items-center justify-between gap-4 px-6 py-4 border-b border-slate-100 dark:border-slate-800 shrink-0">
    <div class="min-w-0">
        <h2 class="text-lg font-bold text-slate-800 dark:text-slate-100 truncate">{{ $lead->customer_name ?: 'Unnamed customer' }}</h2>
        <p class="text-xs font-mono text-slate-400">Order #{{ $lead->pancake_order_id }}</p>
    </div>
    <span class="inline-flex items-center px-2.5 py-1 rounded-full text-[11px] font-bold uppercase tracking-wide shrink-0 {{ $statusStyles[$lead->status] ?? 'bg-slate-100 dark:bg-slate-700 text-slate-500 dark:text-slate-400' }}">
        {{ str($lead->status)->replace('_', ' ') }}
    </span>
</div>

<div class="flex-1 overflow-y-auto p-6">
    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
        <div class="lg:col-span-2 space-y-5">
            {{-- Product card — same "photo box + name + price" shape as Pancake's
                 own order-item card, using whatever the local Order sync already
                 has (bundle_description/base_product/amount) rather than a live
                 Pancake fetch (explicit scope decision, 2026-08-25). A generic
                 package icon stands in for a real product photo — this app has
                 never stored Pancake's own product images locally. --}}
            @if(!empty($liveOrder['items']))
            {{-- Every real line item, live from Pancake (explicit follow-up
                 request, 2026-08-25: "see too the current upsell in the pos")
                 — $order's own single summarized line is deliberately just
                 the isolated upsell's own info for an upsell order (see
                 PancakeOrderTagApi::getOrderDetail()'s own doc comment), so
                 a genuine multi-item order needs the real items[] to show
                 the base product's own line/price alongside it, matching
                 what Pancake's own order popup shows.

                 Search bar pinned at the TOP of this card, not a separate
                 "+ Add upsell product" button/section below (2nd explicit
                 follow-up request, 2026-08-25: "the search products in the
                 pos is [at] the top of displaying products ... not log
                 like log outcome or upsell") — matches Pancake's own
                 Products panel layout exactly. Same search/add endpoints
                 the Leads table's own per-row "+ Add Upsell" button uses
                 (LeadController::searchProducts()/addUpsell()) — a
                 genuinely different widget/element IDs from that button's
                 own #upsellModal (calls/partials/modals.blade.php) so the
                 two never collide, but both write to the exact same real
                 order. initInlineUpsellSearch() (calls.js) re-binds this on
                 every modal open, same reason initPancakeNotesPanel() does. --}}
            @if($canManage)
            <div class="bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-700 p-4">
                <p class="text-[11px] font-bold text-slate-400 uppercase tracking-wide mb-3">Products</p>
                <div class="relative mb-3" id="inlineUpsellSearchWrap" data-lead-id="{{ $lead->id }}">
                    <input type="text" id="inlineUpsellSearch" placeholder="Search products to add…" autocomplete="off"
                           class="w-full text-sm border border-slate-300 dark:border-slate-600 rounded-lg px-3 py-2 bg-white dark:bg-slate-800 text-slate-800 dark:text-slate-100 focus:outline-none focus:ring-2 focus:ring-yellow-500">
                    <div id="inlineUpsellResults" class="hidden absolute z-20 mt-1 w-full bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-700 rounded-lg shadow-lg max-h-64 overflow-y-auto"></div>
                </div>
                {{-- Hidden until a search result is picked — see selectInlineUpsellProduct() in calls.js. --}}
                <div id="inlineUpsellConfirm" class="hidden items-center gap-2 mb-3 p-2.5 bg-slate-50 dark:bg-slate-800/60 rounded-lg">
                    <p id="inlineUpsellConfirmName" class="flex-1 min-w-0 text-sm font-semibold text-slate-700 dark:text-slate-200 truncate"></p>
                    <label class="text-xs text-slate-400 shrink-0">Qty</label>
                    <input type="number" id="inlineUpsellQuantity" value="1" min="1" max="99"
                           class="w-14 text-sm border border-slate-300 dark:border-slate-600 rounded-lg px-2 py-1.5 bg-white dark:bg-slate-800 text-slate-800 dark:text-slate-100 focus:outline-none focus:ring-2 focus:ring-yellow-500">
                    <button type="button" onclick="submitInlineUpsell()" id="inlineUpsellAddBtn"
                            class="bg-primary hover:bg-primary-dark text-white text-xs font-semibold px-3 py-2 rounded-lg cursor-pointer shrink-0">
                        Add
                    </button>
                </div>
                <p id="inlineUpsellError" class="hidden text-[11px] text-red-500 mb-3"></p>
                <div class="space-y-3">
                    @foreach($liveOrder['items'] as $item)
                    @php
                        $vi   = $item['variation_info'] ?? [];
                        $name = $vi['name'] ?? $item['product_name'] ?? '—';
                        $qty  = $item['quantity'] ?? 1;
                        $price = $vi['retail_price'] ?? 0;
                    @endphp
                    <div class="flex items-center gap-4">
                        <div class="w-12 h-12 rounded-xl bg-slate-100 dark:bg-slate-800 flex items-center justify-center shrink-0">
                            <svg class="w-6 h-6 text-slate-400" fill="none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M20.25 7.5l-.625 10.632a2.25 2.25 0 01-2.247 2.118H6.622a2.25 2.25 0 01-2.247-2.118L3.75 7.5M10 11.25h4M3.375 7.5h17.25c.621 0 1.125-.504 1.125-1.125v-1.5c0-.621-.504-1.125-1.125-1.125H3.375c-.621 0-1.125.504-1.125 1.125v1.5c0 .621.504 1.125 1.125 1.125z"/>
                            </svg>
                        </div>
                        <div class="min-w-0 flex-1">
                            <p class="font-semibold text-slate-800 dark:text-slate-100 truncate">{{ $name }}</p>
                            <p class="text-xs text-slate-400 mt-0.5">₱{{ number_format($price, 2) }} × {{ $qty }}</p>
                        </div>
                        <p class="text-base font-bold text-slate-800 dark:text-slate-100 shrink-0">₱{{ number_format($price * $qty, 2) }}</p>
                    </div>
                    @endforeach
                </div>
            </div>
            @endif
            @else
            {{-- Fallback: Pancake unreachable, or nothing synced locally
                 either — same single summarized line as before this fetch
                 existed (a generic icon stands in for a real product photo,
                 this app has never stored Pancake's own images locally). --}}
            <div class="bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-700 p-4">
                <p class="text-[11px] font-bold text-slate-400 uppercase tracking-wide mb-3">Product</p>
                <div class="flex items-center gap-4">
                    <div class="w-14 h-14 rounded-xl bg-slate-100 dark:bg-slate-800 flex items-center justify-center shrink-0">
                        <svg class="w-7 h-7 text-slate-400" fill="none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M20.25 7.5l-.625 10.632a2.25 2.25 0 01-2.247 2.118H6.622a2.25 2.25 0 01-2.247-2.118L3.75 7.5M10 11.25h4M3.375 7.5h17.25c.621 0 1.125-.504 1.125-1.125v-1.5c0-.621-.504-1.125-1.125-1.125H3.375c-.621 0-1.125.504-1.125 1.125v1.5c0 .621.504 1.125 1.125 1.125z"/>
                        </svg>
                    </div>
                    <div class="min-w-0 flex-1">
                        <p class="font-semibold text-slate-800 dark:text-slate-100 truncate">
                            {{ $order?->bundle_description ?? $order?->base_product ?? $lead->product?->display_name ?? '—' }}
                        </p>
                        <p class="text-xs font-mono text-slate-400 mt-0.5">{{ $lead->product?->display_name ?? 'Unmatched product' }}</p>
                    </div>
                    @if($order?->amount)
                    <p class="text-lg font-bold text-slate-800 dark:text-slate-100 shrink-0">₱{{ number_format($order->amount, 2) }}</p>
                    @endif
                </div>
            </div>
            @endif

            @php
                // $liveOrder !== null (the fetch itself succeeded, whether or
                // not this specific order happens to carry zero tags right
                // now) is what gates interactive add/remove below — a tag
                // pulled from $order->raw_tags (whatever this order's own
                // last local sync happened to see, best-effort fallback,
                // same "something beats nothing" reasoning as the Product
                // card's own fallback above) could be stale, so removing/
                // adding against it isn't offered, only plain display.
                $liveTags = $liveOrder !== null ? collect($liveOrder['tags'] ?? [])->pluck('name')->filter() : null;
                $displayTags = $liveTags ?? collect($order?->raw_tags ?? []);
            @endphp
            @if($lead->pancake_order_id && $canManage)
            {{-- Current POS tags (2nd explicit follow-up request,
                 2026-08-25: "the display of tags too is like there's add
                 tag too like in the pos ... not log like log outcome or
                 upsell") — an inline "+ Add tag" chip right among the
                 pills, matching Pancake's own Information panel layout,
                 instead of routing through updateDisposition()'s own
                 tag-writing (that's really about logging a call OUTCOME,
                 tags are only a side effect of it — a real Pancake tag is
                 its own concept). Remove buttons reuse the exact same
                 .real-tag-remove class + already-delegated click handler
                 the Leads table's own tag panel uses (calls.js) — no new
                 JS needed for removal, only for the add side
                 (initInlineTagsPanel() in calls.js). --}}
            <div class="bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-700 p-4">
                <p class="text-[11px] font-bold text-slate-400 uppercase tracking-wide mb-3">POS Tags</p>
                <div class="flex flex-wrap items-center gap-1.5" id="inlineTagsList" data-lead-id="{{ $lead->id }}" data-writable="{{ $liveTags !== null ? '1' : '0' }}">
                    @foreach($displayTags as $tagName)
                    <span class="real-tag-chip inline-flex items-center gap-1.5 text-xs font-semibold text-slate-600 dark:text-slate-300 bg-slate-100 dark:bg-slate-800 rounded-full pl-2.5 pr-1.5 py-1">
                        <span class="w-1.5 h-1.5 rounded-full bg-slate-400"></span>
                        {{ $tagName }}
                        @if($liveTags !== null)
                        <button type="button" class="real-tag-remove hover:text-red-600 cursor-pointer leading-none" data-lead-id="{{ $lead->id }}" data-tag="{{ $tagName }}" title="Remove tag from order" aria-label="Remove {{ $tagName }}">×</button>
                        @endif
                    </span>
                    @endforeach
                    @if($liveTags !== null)
                    <div class="relative" id="inlineTagAddWrap">
                        <button type="button" id="inlineTagAddBtn" onclick="openInlineTagAdd()"
                                class="inline-flex items-center gap-1 text-xs font-semibold text-slate-500 dark:text-slate-400 hover:text-primary-dark border border-dashed border-slate-300 dark:border-slate-600 hover:border-primary rounded-full px-2.5 py-1 cursor-pointer">
                            <svg class="w-3 h-3" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15"/>
                            </svg>
                            Add tag
                        </button>
                        <div id="inlineTagAddPanel" class="hidden absolute z-20 mt-1 w-56 bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-700 rounded-lg shadow-lg">
                            <input type="text" id="inlineTagAddSearch" placeholder="Search tags…" autocomplete="off"
                                   class="w-full text-xs border-b border-slate-100 dark:border-slate-700 px-3 py-2 bg-transparent text-slate-800 dark:text-slate-100 focus:outline-none">
                            <div id="inlineTagAddResults" class="max-h-48 overflow-y-auto"></div>
                        </div>
                    </div>
                    @endif
                </div>
            </div>
            @endif

            {{-- Details --}}
            <div class="bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-700 p-4">
                <p class="text-[11px] font-bold text-slate-400 uppercase tracking-wide mb-3">Details</p>
                <dl class="grid grid-cols-2 gap-y-3 text-sm">
                    <dt class="text-slate-400">Phone</dt>
                    <dd class="text-slate-700 dark:text-slate-200">
                        @if($lead->dialable_number)
                        <a href="tel:{{ $lead->dialable_number }}" data-name="{{ $lead->customer_name ?: 'this customer' }}"
                           data-dial-host="{{ $lead->tsa?->dialer_host }}" data-dial-number="{{ $lead->dialable_number }}" data-lead-id="{{ $lead->id }}"
                           class="text-primary font-semibold hover:text-primary-dark">{{ $lead->phone_number }}</a>
                        @else — @endif
                    </dd>

                    @if($lead->conversation_link || ($lead->pancake_page_id && $lead->pancake_conversation_id))
                    <dt class="text-slate-400">Conversation</dt>
                    <dd class="text-slate-700 dark:text-slate-200 flex items-center gap-3">
                        @if($lead->pancake_page_id && $lead->pancake_conversation_id)
                        <button type="button" onclick="openConversationModal({{ $lead->id }})"
                           class="inline-flex items-center gap-1.5 text-blue-600 dark:text-blue-400 font-semibold hover:text-blue-800 dark:hover:text-blue-300 cursor-pointer">
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M8 12h.01M12 12h.01M16 12h.01M21 12c0 4.418-4.03 8-9 8a9.863 9.863 0 01-4.255-.949L3 20l1.395-3.72C3.512 15.042 3 13.574 3 12c0-4.418 4.03-8 9-8s9 3.582 9 8z"/>
                            </svg>
                            View
                        </button>
                        @endif
                        @if($lead->conversation_link)
                        <a href="{{ $lead->conversation_link }}" target="_blank" rel="noopener" class="text-xs text-slate-400 hover:text-slate-600 dark:hover:text-slate-300">Open in Pancake ↗</a>
                        @endif
                    </dd>
                    @endif

                    <dt class="text-slate-400">TSA</dt>
                    <dd class="text-slate-700 dark:text-slate-200">{{ $lead->tsa?->display_name ?? 'Unassigned' }}</dd>

                    <dt class="text-slate-400">Assigned</dt>
                    <dd class="text-slate-700 dark:text-slate-200">{{ $lead->assigned_at?->format('M j, g:i A') ?? '—' }}</dd>

                    <dt class="text-slate-400">Called</dt>
                    <dd class="text-slate-700 dark:text-slate-200">{{ $lead->called_at?->format('M j, g:i A') ?? '—' }}</dd>

                    <dt class="text-slate-400">Disposition</dt>
                    <dd class="text-slate-700 dark:text-slate-200">{{ $lead->disposition ?? '—' }}</dd>

                    @if($lead->callback_at)
                    <dt class="text-slate-400">Callback due</dt>
                    <dd class="text-red-600 dark:text-red-400 font-semibold">{{ $lead->callback_at->format('M j, g:i A') }}</dd>
                    @endif

                    @if($lead->notes)
                    <dt class="text-slate-400">Notes</dt>
                    <dd class="text-slate-700 dark:text-slate-200">{{ $lead->notes }}</dd>
                    @endif
                </dl>
            </div>

            @if($lead->pancake_order_id && $canManage)
            <div class="bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-700 p-4" id="pancakeNotesPanel" data-lead-id="{{ $lead->id }}">
                <div class="flex items-center justify-between mb-3">
                    <p class="text-[11px] font-bold text-slate-400 uppercase tracking-wide">Pancake Notes</p>
                    <span id="pancakeNotesStatus" class="text-[11px] font-mono text-slate-400"></span>
                </div>
                <div class="flex gap-1 mb-3 border-b border-slate-100 dark:border-slate-700">
                    <button type="button" data-notes-tab="all" class="notes-tab px-3 py-1.5 text-xs font-semibold text-primary border-b-2 border-primary cursor-pointer">All</button>
                    <button type="button" data-notes-tab="note" class="notes-tab px-3 py-1.5 text-xs font-semibold text-slate-400 border-b-2 border-transparent hover:text-slate-600 dark:hover:text-slate-300 cursor-pointer">Internal</button>
                    <button type="button" data-notes-tab="note_print" class="notes-tab px-3 py-1.5 text-xs font-semibold text-slate-400 border-b-2 border-transparent hover:text-slate-600 dark:hover:text-slate-300 cursor-pointer">For printing</button>
                    @if($lead->pancake_page_id && $lead->pancake_conversation_id)
                    <button type="button" data-notes-tab="conversation" class="notes-tab px-3 py-1.5 text-xs font-semibold text-slate-400 border-b-2 border-transparent hover:text-slate-600 dark:hover:text-slate-300 cursor-pointer">Conversation</button>
                    @endif
                </div>
                <div class="space-y-3">
                    <div data-notes-block="note">
                        <textarea data-notes-field="note" rows="3" placeholder="Staff-only note…"
                                  class="w-full text-sm border border-slate-300 dark:border-slate-600 rounded-lg px-3 py-2 bg-white dark:bg-slate-800 text-slate-800 dark:text-slate-100 focus:outline-none focus:ring-2 focus:ring-yellow-500"></textarea>
                    </div>
                    <div data-notes-block="note_print">
                        <textarea data-notes-field="note_print" rows="3" placeholder="Printed on order documents…"
                                  class="w-full text-sm border border-slate-300 dark:border-slate-600 rounded-lg px-3 py-2 bg-white dark:bg-slate-800 text-slate-800 dark:text-slate-100 focus:outline-none focus:ring-2 focus:ring-yellow-500"></textarea>
                    </div>
                    {{-- Read-only view of the real Messenger thread (explicit
                         follow-up request, 2026-08-25: "add in Pancake Notes
                         CONVERSATION") — reuses the exact same read-only fetch
                         (LeadController::conversation() -> PancakeConversationApi::
                         getMessages()) the separate "View" button's own
                         #conversationModal already uses, just rendered inline
                         here instead of in a popup. Fetched once on
                         initPancakeNotesPanel() (loadPancakeConversationThread()
                         in calls.js), not on the notes' own 8s poll — a full
                         message thread is heavier than the two note fields and
                         doesn't need sub-10-second freshness for a read-only
                         reference view. Sending a NEW message from here isn't
                         built — that's a real write to the customer's live
                         conversation, a materially bigger undertaking than what
                         was asked for here. --}}
                    @if($lead->pancake_page_id && $lead->pancake_conversation_id)
                    <div data-notes-block="conversation">
                        {{-- max-h-72 (288px) was too short for this shop's real
                             message lengths (some run 700-1400+ characters) —
                             confirmed live, 2026-08-25, that scrolling to the
                             very bottom of a thread that short landed
                             mid-message, hiding the sender label/timestamp
                             entirely. Widened now that the whole modal itself
                             is much bigger (see modals.blade.php). --}}
                        <div id="pancakeConversationThread" class="space-y-2 max-h-112 overflow-y-auto bg-slate-50 dark:bg-slate-950 rounded-lg p-3">
                            <p class="text-slate-400 text-center text-xs py-6">Loading conversation…</p>
                        </div>
                    </div>
                    @endif
                </div>
                <button type="button" onclick="savePancakeNotes(this)"
                        class="mt-3 bg-primary hover:bg-primary-dark text-white text-sm font-semibold px-4 py-2 rounded-lg cursor-pointer">
                    Save
                </button>
            </div>
            @endif

            @if($lead->status !== 'called' && $lead->tsa_id && $canManage)
            @php
                // Real outcome tags this shop's own reporting already recognizes
                // (SyncTodayOrders::extractDisposition()'s own keyword list) — a
                // small fixed set of one-click buttons instead of the old full
                // free-text/search picker (explicit follow-up request,
                // 2026-08-25: "remove this log outcome because there is now POS
                // tag and also now it has Pancake Notes too" — simplified per
                // the chosen scope: keep marking the lead called/scheduling
                // callbacks since Overdue/Callbacks/Dashboard/TSA Performance
                // depend on it, but drop the redundant catalog-search UI since
                // POS Tags' own "+ Add tag" already covers picking any OTHER
                // real tag). Excludes DOUBLE ORDER (confirmed against the real
                // catalog to not exist) and UNCATERED LEADS (a system bulk-
                // sweep tag, never one a TSA manually picks). A TSA who submits
                // with nothing picked still falls through to the full-catalog
                // #outcomeTagModal (see calls.js' existing submit guard on
                // .disposition-form) as an escape hatch for anything not listed
                // here — that modal/its endpoints are untouched.
                $quickOutcomes = [
                    'CONFIRMED VIA CALL', 'CALL BACK', 'NOT ANSWERING', 'UNATTENDED',
                    'CALL DROPPED', 'REPEAT ORDER', 'RELATIVES CONFIRMATION',
                    'RUDE CUSTOMER', 'INVALID NUMBER', 'DFR', 'FSD UNCLEARED ORDER',
                ];
            @endphp
            <div class="bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-700 p-4">
                <p class="text-[11px] font-bold text-slate-400 uppercase tracking-wide mb-3">Log outcome</p>
                <form method="POST" action="{{ route('calls.leads.disposition', $lead) }}" class="space-y-3 disposition-form">
                    @csrf
                    <div class="disposition-picker" data-lead-id="{{ $lead->id }}">
                        <div class="disposition-selected-chips flex flex-wrap gap-1 empty:hidden mb-2"></div>
                        <input type="hidden" name="disposition" class="disposition-hidden-input">
                        <div class="flex flex-wrap gap-1.5">
                            @foreach($quickOutcomes as $outcome)
                            <button type="button" class="disposition-quick-tag text-xs font-semibold text-slate-500 dark:text-slate-400 hover:text-primary-dark border border-slate-200 dark:border-slate-600 hover:border-primary rounded-full px-2.5 py-1 cursor-pointer" data-text="{{ $outcome }}">
                                {{ $outcome }}
                            </button>
                            @endforeach
                        </div>
                    </div>
                    <input type="datetime-local" name="callback_at"
                           class="callback-at-input hidden w-full text-sm border border-slate-300 dark:border-slate-600 rounded-lg px-3 py-2 bg-white dark:bg-slate-800 text-slate-800 dark:text-slate-100 focus:outline-none focus:ring-2 focus:ring-yellow-500">
                    <textarea name="notes" rows="2" placeholder="Notes (optional)"
                              class="w-full text-sm border border-slate-300 dark:border-slate-600 rounded-lg px-3 py-2 bg-white dark:bg-slate-800 text-slate-800 dark:text-slate-100 focus:outline-none focus:ring-2 focus:ring-yellow-500"></textarea>
                    <button type="submit" class="bg-primary hover:bg-primary-dark text-white text-sm font-semibold px-4 py-2 rounded-lg cursor-pointer">
                        Save
                    </button>
                </form>
            </div>
            @endif
        </div>

        <div class="space-y-5">
            <div class="bg-slate-50 dark:bg-slate-950/40 rounded-2xl border border-slate-200 dark:border-slate-700 p-4">
                <p class="text-[11px] font-bold text-slate-400 uppercase tracking-wide mb-3">Customer</p>
                <p class="font-semibold text-slate-800 dark:text-slate-100">{{ $lead->customer_name ?: '—' }}</p>
                <p class="text-sm text-slate-500 dark:text-slate-400 mt-0.5">{{ $lead->phone_number ?: '—' }}</p>
            </div>

@if($liveOrder && $liveOrder['shipping_address'] && $canManage)
            {{-- Delivery (explicit follow-up request, 2026-08-25: "add
                 delivery to this like in the POS", then "make it editable
                 like in the POS") — a real editable form backed by the same
                 province -> district -> commune cascading picker Pancake's
                 own Delivery panel uses (PancakeOrderTagApi::listProvinces()/
                 listDistricts()/listCommunes(), confirmed live against the
                 real /geo/* endpoints -- country_code "63", the Philippines,
                 is every real order this shop has ever synced). Courier/
                 tracking/shipping fee stay read-only: those are set by
                 Pancake/the courier once a shipment is actually booked, not
                 something this form collects (see updateDelivery()'s own
                 doc comment). initDeliveryPanel() (calls.js) re-binds this
                 on every modal open, same reason initPancakeNotesPanel()
                 does. --}}
            @php $shipping = $liveOrder['shipping_address']; @endphp
            <div class="bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-700 p-4"
                 id="deliveryPanel" data-lead-id="{{ $lead->id }}"
                 data-province-id="{{ $shipping['province_id'] ?? '' }}"
                 data-province-name="{{ $shipping['province_name'] ?? '' }}"
                 data-district-id="{{ $shipping['district_id'] ?? '' }}"
                 data-district-name="{{ $shipping['district_name'] ?? '' }}"
                 data-commune-id="{{ $shipping['commune_id'] ?? '' }}"
                 data-commune-name="{{ $shipping['commune_name'] ?? '' }}">
                <div class="flex items-center justify-between mb-3">
                    <p class="text-[11px] font-bold text-slate-400 uppercase tracking-wide">Delivery</p>
                    <span id="deliveryStatus" class="text-[11px] font-mono text-slate-400"></span>
                </div>
                <div class="space-y-2.5">
                    <div class="grid grid-cols-2 gap-2">
                        <input type="text" id="deliveryFullName" placeholder="Recipient name" value="{{ $shipping['full_name'] ?? '' }}"
                               class="text-sm border border-slate-300 dark:border-slate-600 rounded-lg px-3 py-2 bg-white dark:bg-slate-800 text-slate-800 dark:text-slate-100 focus:outline-none focus:ring-2 focus:ring-yellow-500">
                        <input type="text" id="deliveryPhone" placeholder="Phone number" value="{{ $shipping['phone_number'] ?? '' }}"
                               class="text-sm border border-slate-300 dark:border-slate-600 rounded-lg px-3 py-2 bg-white dark:bg-slate-800 text-slate-800 dark:text-slate-100 focus:outline-none focus:ring-2 focus:ring-yellow-500">
                    </div>
                    <textarea id="deliveryAddress" rows="2" placeholder="Street / landmark"
                              class="w-full text-sm border border-slate-300 dark:border-slate-600 rounded-lg px-3 py-2 bg-white dark:bg-slate-800 text-slate-800 dark:text-slate-100 focus:outline-none focus:ring-2 focus:ring-yellow-500">{{ $shipping['address'] ?? '' }}</textarea>

                    {{-- Real province -> district -> commune picker, redesigned
                         2026-08-25 (explicit follow-up: the previous 3 native
                         <select> elements didn't match Pancake's own "Select
                         address" combobox and read as broken/unclickable) to
                         match Pancake's own widget exactly: a single search
                         box that opens a tabbed dropdown (Province/City ->
                         Choose district -> Choose commune, each tab's list
                         client-filterable by typing), collapsing into a single
                         "{province}, {district}, {commune}" chip with a ×
                         once all three are picked — same real geo catalog as
                         before (initDeliveryPanel() in calls.js), just a
                         different picker UI over it. --}}
                    <div class="flex gap-2">
                        <div class="relative flex-1 min-w-0" id="deliveryAddressPicker">
                            <div class="relative" id="deliveryAddressSearchWrap">
                                <input type="text" id="deliveryAddressSearch" placeholder="Select address" autocomplete="off"
                                       class="w-full text-sm border border-slate-300 dark:border-slate-600 rounded-lg pl-3 pr-8 py-2 bg-white dark:bg-slate-800 text-slate-800 dark:text-slate-100 focus:outline-none focus:ring-2 focus:ring-yellow-500">
                                <svg class="w-4 h-4 text-slate-400 absolute right-2.5 top-1/2 -translate-y-1/2 pointer-events-none" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M21 21l-4.35-4.35M18 10.5a7.5 7.5 0 11-15 0 7.5 7.5 0 0115 0z"/>
                                </svg>
                            </div>
                            {{-- The whole chip reopens the picker (click anywhere
                                 except ×, which clears instead) — matches
                                 Pancake's own populated address field, which is
                                 clickable to change the selection, not just an
                                 inert display + separate clear button. --}}
                            <button type="button" id="deliveryAddressChip"
                                    class="hidden w-full items-center justify-between text-sm border border-slate-300 dark:border-slate-600 rounded-lg px-3 py-2 bg-white dark:bg-slate-800 text-slate-800 dark:text-slate-100 text-left cursor-pointer hover:border-primary">
                                <span id="deliveryAddressChipText" class="truncate"></span>
                                <span id="deliveryAddressChipClear" role="button" title="Clear address" class="text-slate-400 hover:text-red-600 cursor-pointer shrink-0 ml-2">×</span>
                            </button>
                            <div id="deliveryAddressDropdown" class="hidden absolute z-20 mt-1 w-full bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-700 rounded-lg shadow-lg">
                                <div class="flex border-b border-slate-100 dark:border-slate-700 text-xs">
                                    <button type="button" data-address-level="province" class="delivery-address-tab flex-1 px-2 py-2 font-semibold cursor-pointer">Province/City</button>
                                    <button type="button" data-address-level="district" class="delivery-address-tab flex-1 px-2 py-2 font-semibold cursor-pointer" disabled>Choose district</button>
                                    <button type="button" data-address-level="commune" class="delivery-address-tab flex-1 px-2 py-2 font-semibold cursor-pointer" disabled>Choose commune</button>
                                </div>
                                <div id="deliveryAddressList" class="max-h-56 overflow-y-auto text-sm"></div>
                            </div>
                        </div>
                        <input type="text" id="deliveryPostcode" placeholder="Postcode" value="{{ $shipping['post_code'] ?? '' }}"
                               class="w-28 shrink-0 text-sm border border-slate-300 dark:border-slate-600 rounded-lg px-3 py-2 bg-white dark:bg-slate-800 text-slate-800 dark:text-slate-100 focus:outline-none focus:ring-2 focus:ring-yellow-500">
                    </div>

                    <div class="flex items-center justify-between pt-1">
                        <div class="text-xs text-slate-400">
                            <span>Courier: {{ $liveOrder['courier_name'] ?? 'Not yet assigned' }}</span>
                            @if($liveOrder['tracking_link'] && $liveOrder['courier_name'])
                            <a href="{{ $liveOrder['tracking_link'] }}" target="_blank" rel="noopener" class="text-blue-600 dark:text-blue-400 hover:underline ml-2">Track ↗</a>
                            @endif
                        </div>
                        <button type="button" onclick="saveDeliveryDetails(this)"
                                class="bg-primary hover:bg-primary-dark text-white text-sm font-semibold px-4 py-2 rounded-lg cursor-pointer">
                            Save
                        </button>
                    </div>
                </div>
            </div>
            @elseif($liveOrder && $liveOrder['shipping_address'])
            {{-- Read-only fallback for a viewer who can see this lead but
                 can't manage it (no $canManage) — same access split every
                 other write-capable card on this page already draws. --}}
            @php $shipping = $liveOrder['shipping_address']; @endphp
            <div class="bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-700 p-4">
                <p class="text-[11px] font-bold text-slate-400 uppercase tracking-wide mb-3">Delivery</p>
                <dl class="space-y-3 text-sm">
                    <div>
                        <dt class="text-slate-400 text-xs">Recipient</dt>
                        <dd class="text-slate-700 dark:text-slate-200 font-semibold">{{ $shipping['full_name'] ?? '—' }}</dd>
                        <dd class="text-slate-500 dark:text-slate-400">{{ $shipping['phone_number'] ?? '—' }}</dd>
                    </div>
                    <div>
                        <dt class="text-slate-400 text-xs">Address</dt>
                        <dd class="text-slate-700 dark:text-slate-200">{{ $shipping['full_address'] ?? '—' }}</dd>
                    </div>
                    <div>
                        <dt class="text-slate-400 text-xs">Courier</dt>
                        <dd class="text-slate-700 dark:text-slate-200">{{ $liveOrder['courier_name'] ?? 'Not yet assigned' }}</dd>
                    </div>
                </dl>
            </div>
            @endif

            <div class="bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-700 p-4">
                <p class="text-[11px] font-bold text-slate-400 uppercase tracking-wide mb-3">Activity</p>
                @if($lead->activities->isEmpty())
                <p class="text-xs text-slate-400">No activity recorded yet.</p>
                @else
                <ul class="space-y-3">
                    @foreach($lead->activities as $activity)
                    <li class="text-xs border-l-2 border-slate-200 dark:border-slate-700 pl-3">
                        <p class="text-slate-700 dark:text-slate-200">{{ $activity->description }}</p>
                        <p class="text-slate-400 mt-0.5">{{ $activity->created_at->format('M j, g:i A') }}</p>
                    </li>
                    @endforeach
                </ul>
                @endif
            </div>
        </div>
    </div>
</div>
