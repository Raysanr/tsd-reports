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
                </div>
                <button type="button" onclick="savePancakeNotes(this)"
                        class="mt-3 bg-primary hover:bg-primary-dark text-white text-sm font-semibold px-4 py-2 rounded-lg cursor-pointer">
                    Save
                </button>
            </div>
            @endif

            @if($lead->status !== 'called' && $lead->tsa_id && $canManage)
            <div class="bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-700 p-4">
                <p class="text-[11px] font-bold text-slate-400 uppercase tracking-wide mb-3">Log outcome</p>
                <form method="POST" action="{{ route('calls.leads.disposition', $lead) }}" class="space-y-3 disposition-form">
                    @csrf
                    <div class="disposition-picker" data-lead-id="{{ $lead->id }}">
                        <div class="disposition-selected-chips flex flex-wrap gap-1 empty:hidden mb-1"></div>
                        <input type="hidden" name="disposition" class="disposition-hidden-input">
                        <button type="button" onclick="openOutcomeTagModal(this.closest('.disposition-picker'))"
                                class="inline-flex items-center gap-1.5 text-sm text-slate-500 dark:text-slate-400 hover:text-primary-dark border border-dashed border-slate-300 dark:border-slate-600 hover:border-primary rounded-lg px-3 py-2 cursor-pointer">
                            <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15"/>
                            </svg>
                            Add tag
                        </button>
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

            @if($lead->pancake_order_id && $canManage)
            <div class="bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-700 p-4">
                <p class="text-[11px] font-bold text-slate-400 uppercase tracking-wide mb-3">Upsell</p>
                <button type="button" onclick="openUpsellModal({{ $lead->id }})"
                        class="inline-flex items-center gap-1.5 text-sm text-slate-500 dark:text-slate-400 hover:text-primary-dark border border-dashed border-slate-300 dark:border-slate-600 hover:border-primary rounded-lg px-3 py-2 cursor-pointer">
                    <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15"/>
                    </svg>
                    Add upsell product
                </button>
            </div>
            @endif
        </div>

        <div class="space-y-5">
            <div class="bg-slate-50 dark:bg-slate-950/40 rounded-2xl border border-slate-200 dark:border-slate-700 p-4">
                <p class="text-[11px] font-bold text-slate-400 uppercase tracking-wide mb-3">Customer</p>
                <p class="font-semibold text-slate-800 dark:text-slate-100">{{ $lead->customer_name ?: '—' }}</p>
                <p class="text-sm text-slate-500 dark:text-slate-400 mt-0.5">{{ $lead->phone_number ?: '—' }}</p>
            </div>

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
