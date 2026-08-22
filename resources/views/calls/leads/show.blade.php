@extends('layouts.calls')
@section('title', $lead->customer_name ?: 'Lead #' . $lead->pancake_order_id)
@section('subtitle', 'Order #' . $lead->pancake_order_id)

@section('content')

<a href="{{ url()->previous() }}" class="inline-flex items-center gap-1.5 text-xs font-mono font-semibold text-slate-500 dark:text-slate-400 hover:text-slate-800 dark:hover:text-slate-100 mb-4">
    ← Back
</a>

<div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
    <div class="lg:col-span-2 space-y-6">
        <div class="bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-700 shadow-sm p-6">
            <h2 class="text-sm font-bold text-slate-700 dark:text-slate-200 font-mono mb-4">Details</h2>
            <dl class="grid grid-cols-2 gap-y-3 text-sm font-mono">
                <dt class="text-slate-400">Customer</dt>
                <dd class="text-slate-700 dark:text-slate-200 font-semibold">{{ $lead->customer_name ?: '—' }}</dd>

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
                <dd class="text-slate-700 dark:text-slate-200 flex items-center gap-4">
                    @if($lead->pancake_page_id && $lead->pancake_conversation_id)
                    <button type="button" onclick="openConversationModal({{ $lead->id }})"
                       class="inline-flex items-center gap-1.5 text-blue-600 dark:text-blue-400 font-semibold hover:text-blue-800 dark:hover:text-blue-300 cursor-pointer">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M8 12h.01M12 12h.01M16 12h.01M21 12c0 4.418-4.03 8-9 8a9.863 9.863 0 01-4.255-.949L3 20l1.395-3.72C3.512 15.042 3 13.574 3 12c0-4.418 4.03-8 9-8s9 3.582 9 8z"/>
                        </svg>
                        View Conversation
                    </button>
                    @endif
                    @if($lead->conversation_link)
                    <a href="{{ $lead->conversation_link }}" target="_blank" rel="noopener" class="text-xs text-slate-400 hover:text-slate-600 dark:hover:text-slate-300">
                        Open in Pancake ↗
                    </a>
                    @endif
                </dd>
                @endif

                <dt class="text-slate-400">Product</dt>
                <dd class="text-slate-700 dark:text-slate-200">{{ $lead->product?->display_name ?? '—' }}</dd>

                <dt class="text-slate-400">TSA</dt>
                <dd class="text-slate-700 dark:text-slate-200">{{ $lead->tsa?->display_name ?? 'Unassigned' }}</dd>

                <dt class="text-slate-400">Status</dt>
                <dd class="text-slate-700 dark:text-slate-200">{{ $lead->status }}</dd>

                <dt class="text-slate-400">Assigned</dt>
                <dd class="text-slate-700 dark:text-slate-200">{{ $lead->assigned_at?->format('M j, Y g:i A') ?? '—' }}</dd>

                <dt class="text-slate-400">Called</dt>
                <dd class="text-slate-700 dark:text-slate-200">{{ $lead->called_at?->format('M j, Y g:i A') ?? '—' }}</dd>

                <dt class="text-slate-400">Disposition</dt>
                <dd class="text-slate-700 dark:text-slate-200">{{ $lead->disposition ?? '—' }}</dd>

                @if($lead->callback_at)
                <dt class="text-slate-400">Callback due</dt>
                <dd class="text-red-600 dark:text-red-400 font-semibold">{{ $lead->callback_at->format('M j, Y g:i A') }}</dd>
                @endif

                @if($lead->notes)
                <dt class="text-slate-400">Notes</dt>
                <dd class="text-slate-700 dark:text-slate-200">{{ $lead->notes }}</dd>
                @endif
            </dl>
        </div>

        {{-- Pancake Notes (explicit request, 2026-08-22) — Pancake POS's own
             order notes (Internal / For printing, its only two real note
             fields — "Conversation" in POS's own note panel isn't a third
             note field, it's the message thread already available here via
             "View Conversation" above), readable AND writable from Call
             Tracker so a TSA never has to leave this page to check or add
             one. Polled live (calls.js pollPancakeNotes()) so an edit made
             directly in POS shows up here without a reload — distinct from
             this lead's own local "Notes (optional)" field in Log outcome
             below, which is a TSD Reports-only disposition note, never
             written to Pancake at all. --}}
        @if($lead->pancake_order_id && (auth()->user()->isAtLeastAdmin() || $lead->tsa_id === auth()->user()->tsa_id))
        <div class="bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-700 shadow-sm p-6" id="pancakeNotesPanel" data-lead-id="{{ $lead->id }}">
            <div class="flex items-center justify-between mb-3">
                <h2 class="text-sm font-bold text-slate-700 dark:text-slate-200 font-mono">Pancake Notes</h2>
                <span id="pancakeNotesStatus" class="text-[11px] font-mono text-slate-400"></span>
            </div>
            <div class="flex gap-1 mb-3 border-b border-slate-100 dark:border-slate-700">
                <button type="button" data-notes-tab="all" class="notes-tab px-3 py-1.5 text-xs font-mono font-semibold text-primary border-b-2 border-primary cursor-pointer">All</button>
                <button type="button" data-notes-tab="note" class="notes-tab px-3 py-1.5 text-xs font-mono font-semibold text-slate-400 border-b-2 border-transparent hover:text-slate-600 dark:hover:text-slate-300 cursor-pointer">Internal</button>
                <button type="button" data-notes-tab="note_print" class="notes-tab px-3 py-1.5 text-xs font-mono font-semibold text-slate-400 border-b-2 border-transparent hover:text-slate-600 dark:hover:text-slate-300 cursor-pointer">For printing</button>
            </div>
            <div class="space-y-4">
                <div data-notes-block="note">
                    <p class="text-[11px] font-mono font-semibold text-slate-400 uppercase tracking-wide mb-1">Internal</p>
                    <textarea data-notes-field="note" rows="3" placeholder="Staff-only note…"
                              class="w-full text-sm font-mono border border-slate-300 dark:border-slate-600 rounded-lg px-3 py-2 bg-white dark:bg-slate-800 text-slate-800 dark:text-slate-100 focus:outline-none focus:ring-2 focus:ring-yellow-500"></textarea>
                </div>
                <div data-notes-block="note_print">
                    <p class="text-[11px] font-mono font-semibold text-slate-400 uppercase tracking-wide mb-1">For printing</p>
                    <textarea data-notes-field="note_print" rows="3" placeholder="Printed on order documents…"
                              class="w-full text-sm font-mono border border-slate-300 dark:border-slate-600 rounded-lg px-3 py-2 bg-white dark:bg-slate-800 text-slate-800 dark:text-slate-100 focus:outline-none focus:ring-2 focus:ring-yellow-500"></textarea>
                </div>
            </div>
            <button type="button" onclick="savePancakeNotes(this)"
                    class="mt-3 bg-primary hover:bg-primary-dark text-white text-sm font-semibold font-mono px-4 py-2 rounded-lg cursor-pointer">
                Save
            </button>
        </div>
        @endif

        @if($lead->status !== 'called' && $lead->tsa_id && (auth()->user()->isAtLeastAdmin() || $lead->tsa_id === auth()->user()->tsa_id))
        <div class="bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-700 shadow-sm p-6">
            <h2 class="text-sm font-bold text-slate-700 dark:text-slate-200 font-mono mb-4">Log outcome</h2>
            <form method="POST" action="{{ route('calls.leads.disposition', $lead) }}" class="space-y-3 disposition-form">
                @csrf
                <div class="disposition-picker" data-lead-id="{{ $lead->id }}">
                    <div class="disposition-selected-chips flex flex-wrap gap-1 empty:hidden mb-1"></div>
                    <input type="hidden" name="disposition" class="disposition-hidden-input">
                    <button type="button" onclick="openOutcomeTagModal(this.closest('.disposition-picker'))"
                            class="inline-flex items-center gap-1.5 text-sm font-mono text-slate-500 dark:text-slate-400 hover:text-primary-dark border border-dashed border-slate-300 dark:border-slate-600 hover:border-primary rounded-lg px-3 py-2 cursor-pointer">
                        <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15"/>
                        </svg>
                        Add tag
                    </button>
                </div>
                <input type="datetime-local" name="callback_at"
                       class="callback-at-input hidden w-full text-sm font-mono border border-slate-300 dark:border-slate-600 rounded-lg px-3 py-2 bg-white dark:bg-slate-800 text-slate-800 dark:text-slate-100 focus:outline-none focus:ring-2 focus:ring-yellow-500">
                <textarea name="notes" rows="2" placeholder="Notes (optional)"
                          class="w-full text-sm font-mono border border-slate-300 dark:border-slate-600 rounded-lg px-3 py-2 bg-white dark:bg-slate-800 text-slate-800 dark:text-slate-100 focus:outline-none focus:ring-2 focus:ring-yellow-500"></textarea>
                <button type="submit" class="bg-primary hover:bg-primary-dark text-white text-sm font-semibold font-mono px-4 py-2 rounded-lg cursor-pointer">
                    Save
                </button>
            </form>
        </div>
        @endif

        @if($lead->pancake_order_id && (auth()->user()->isAtLeastAdmin() || $lead->tsa_id === auth()->user()->tsa_id))
        <div class="bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-700 shadow-sm p-6">
            <h2 class="text-sm font-bold text-slate-700 dark:text-slate-200 font-mono mb-4">Upsell</h2>
            <button type="button" onclick="openUpsellModal({{ $lead->id }})"
                    class="inline-flex items-center gap-1.5 text-sm font-mono text-slate-500 dark:text-slate-400 hover:text-primary-dark border border-dashed border-slate-300 dark:border-slate-600 hover:border-primary rounded-lg px-3 py-2 cursor-pointer">
                <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15"/>
                </svg>
                Add upsell product
            </button>
        </div>
        @endif
    </div>

    <div class="bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-700 shadow-sm p-6">
        <h2 class="text-sm font-bold text-slate-700 dark:text-slate-200 font-mono mb-4">Activity</h2>
        @if($lead->activities->isEmpty())
        <p class="text-xs font-mono text-slate-400">No activity recorded yet.</p>
        @else
        <ul class="space-y-4">
            @foreach($lead->activities as $activity)
            <li class="text-xs font-mono border-l-2 border-slate-200 dark:border-slate-700 pl-3">
                <p class="text-slate-700 dark:text-slate-200">{{ $activity->description }}</p>
                <p class="text-slate-400 mt-0.5">{{ $activity->created_at->format('M j, g:i A') }}</p>
            </li>
            @endforeach
        </ul>
        @endif
    </div>
</div>

@include('calls.partials.modals')

@endsection
