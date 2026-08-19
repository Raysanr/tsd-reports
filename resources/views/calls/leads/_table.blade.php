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
    <div class="overflow-x-auto">
    <table class="w-full text-sm font-mono">
        <thead class="bg-slate-100 dark:bg-slate-700 border-b border-slate-200 dark:border-slate-700">
            <tr>
                <th class="w-11 px-1 py-3"></th>
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
            <tr data-lead-id="{{ $lead->id }}" class="hover:bg-slate-50 dark:hover:bg-slate-800 transition-colors duration-150 {{ $lead->pinned_at ? 'bg-yellow-50/60 dark:bg-yellow-900/10' : '' }}">
                <td class="px-1 py-3">
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
                </td>
                <td class="px-4 py-3 text-slate-500 dark:text-slate-400 tabular-nums">#{{ $lead->pancake_order_id }}</td>
                <td class="px-4 py-3 font-semibold text-slate-700 dark:text-slate-200">
                    <a href="{{ route('calls.leads.show', $lead) }}" class="hover:text-primary hover:underline">{{ $lead->customer_name ?: '—' }}</a>
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
                        @if($lead->pancake_page_id && $lead->pancake_conversation_id)
                        <button type="button" onclick="openConversationModal({{ $lead->id }})" title="View conversation"
                           class="inline-flex items-center justify-center w-5 h-5 rounded text-blue-500 hover:text-blue-700 dark:hover:text-blue-300 hover:bg-blue-50 dark:hover:bg-blue-950/40 cursor-pointer">
                            <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M8 12h.01M12 12h.01M16 12h.01M21 12c0 4.418-4.03 8-9 8a9.863 9.863 0 01-4.255-.949L3 20l1.395-3.72C3.512 15.042 3 13.574 3 12c0-4.418 4.03-8 9-8s9 3.582 9 8z"/>
                            </svg>
                        </button>
                        @endif
                    </div>
                </td>
                <td class="px-4 py-3 text-slate-600 dark:text-slate-300">{{ $lead->product?->display_name ?? '—' }}</td>
                @if(auth()->user()->isAtLeastAdmin())
                <td class="px-4 py-3 text-slate-600 dark:text-slate-300">{{ $lead->tsa?->display_name ?? '—' }}</td>
                @endif
                <td class="px-4 py-3">
                    <span @class([
                        'inline-flex px-2 py-0.5 rounded-full text-[10px] font-bold uppercase tracking-wide',
                        'bg-emerald-100 dark:bg-emerald-900/40 text-emerald-700 dark:text-emerald-400' => $lead->status === 'called',
                        'bg-yellow-100 dark:bg-yellow-900/40 text-yellow-700 dark:text-yellow-400'   => $lead->status === 'assigned',
                        'bg-red-100 dark:bg-red-900/40 text-red-700 dark:text-red-400'         => $lead->status === 'unassigned',
                    ])>
                        {{ $lead->status }}
                    </span>
                </td>
                @if($view === 'callbacks')
                <td class="px-4 py-3 text-red-600 dark:text-red-400 font-semibold">{{ $lead->callback_at?->format('M j, g:i A') }}</td>
                @endif
                <td class="px-4 py-3">
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
