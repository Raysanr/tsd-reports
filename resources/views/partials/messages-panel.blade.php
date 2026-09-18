{{--
    Direct messaging — topbar icon + slide-over panel (explicit request,
    2026-09-18: "is it possible that can have a feature that can message
    the tsa"). Shared via @include across both layouts/app.blade.php and
    layouts/calls.blade.php (not role-gated — every signed-in user, admin
    or TSA, can message any other) so the same markup/JS never drifts
    between the two apps. Same "small icon button in the topbar row" shape
    the reload/dark-mode toggle buttons already use in both layouts.

    Self-contained: this partial owns its own JS (no separate app.js/
    calls.js wiring needed) since it's identical behavior on both sides —
    poll unread count, open the panel, load a conversation, send a message,
    poll the open thread for new messages. Same 20s-ish cadence
    pollNotificationCounts() already uses for Overdue/Callbacks, so a new
    message shows up without a page reload but without hammering the
    server either.
--}}
<div class="relative shrink-0">
    <button id="messagesToggle" type="button" aria-label="Messages" title="Messages"
            class="inline-flex items-center justify-center w-8 h-8 rounded-full bg-yellow-50 dark:bg-yellow-950/40 border border-yellow-200 dark:border-yellow-900 hover:bg-yellow-100 dark:hover:bg-yellow-900/40 transition-colors cursor-pointer relative">
        <svg class="w-4 h-4 text-yellow-600 dark:text-yellow-400" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" d="M8 10h.01M12 10h.01M16 10h.01M9 16H5a2 2 0 01-2-2V6a2 2 0 012-2h14a2 2 0 012 2v8a2 2 0 01-2 2h-6l-4 4v-4z"/>
        </svg>
        <span id="messagesBadge" class="hidden absolute -top-1 -right-1 bg-red-500 text-white text-[9px] font-bold rounded-full min-w-[16px] h-[16px] px-1 flex items-center justify-center">0</span>
    </button>
</div>

{{-- Backdrop + slide-over panel — fixed/overlay so it works identically
     regardless of which layout included it, no dependency on either
     layout's own scroll containers. --}}
<div id="messagesBackdrop" class="hidden fixed inset-0 bg-black/30 z-40"></div>
<div id="messagesPanel" class="hidden fixed top-0 right-0 h-full w-full sm:w-96 bg-white dark:bg-slate-900 border-l border-slate-200 dark:border-slate-700 shadow-2xl z-50 flex flex-col">
    {{-- Conversation LIST view --}}
    <div id="messagesListView" class="flex flex-col h-full">
        <div class="flex items-center justify-between px-4 py-3 border-b border-slate-200 dark:border-slate-700 shrink-0">
            <h3 class="text-sm font-bold text-slate-800 dark:text-slate-100 font-mono">Messages</h3>
            <div class="flex items-center gap-1">
                <button type="button" id="messagesNewBtn" title="New message"
                        class="w-7 h-7 flex items-center justify-center rounded-lg text-slate-400 hover:text-primary-dark hover:bg-slate-100 dark:hover:bg-slate-800 cursor-pointer">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15"/></svg>
                </button>
                <button type="button" id="messagesCloseBtn" aria-label="Close"
                        class="w-7 h-7 flex items-center justify-center rounded-lg text-slate-400 hover:text-slate-700 dark:hover:text-slate-200 hover:bg-slate-100 dark:hover:bg-slate-800 cursor-pointer">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/></svg>
                </button>
            </div>
        </div>

        {{-- New-conversation search — hidden until messagesNewBtn is clicked. --}}
        <div id="messagesNewSearchWrap" class="hidden px-4 py-3 border-b border-slate-100 dark:border-slate-800 shrink-0">
            <input type="text" id="messagesNewSearch" placeholder="Search people…" autocomplete="off"
                   class="w-full text-sm font-mono border border-slate-300 dark:border-slate-600 rounded-lg px-3 py-2 bg-white dark:bg-slate-800 text-slate-800 dark:text-slate-100 focus:outline-none focus:ring-2 focus:ring-yellow-500">
            <div id="messagesNewResults" class="mt-2 max-h-56 overflow-y-auto"></div>
        </div>

        <div id="messagesConversationList" class="flex-1 overflow-y-auto">
            <p class="text-slate-400 text-center text-xs font-mono py-10">Loading…</p>
        </div>
    </div>

    {{-- Thread view — hidden until a conversation is opened. --}}
    <div id="messagesThreadView" class="hidden flex-col h-full">
        <div class="flex items-center gap-2 px-4 py-3 border-b border-slate-200 dark:border-slate-700 shrink-0">
            <button type="button" id="messagesBackBtn" aria-label="Back"
                    class="w-7 h-7 flex items-center justify-center rounded-lg text-slate-400 hover:text-slate-700 dark:hover:text-slate-200 hover:bg-slate-100 dark:hover:bg-slate-800 cursor-pointer shrink-0">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M15 19l-7-7 7-7"/></svg>
            </button>
            <h3 id="messagesThreadName" class="text-sm font-bold text-slate-800 dark:text-slate-100 font-mono truncate flex-1">&nbsp;</h3>
            <button type="button" id="messagesThreadCloseBtn" aria-label="Close"
                    class="w-7 h-7 flex items-center justify-center rounded-lg text-slate-400 hover:text-slate-700 dark:hover:text-slate-200 hover:bg-slate-100 dark:hover:bg-slate-800 cursor-pointer shrink-0">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/></svg>
            </button>
        </div>
        <div id="messagesThreadBody" class="flex-1 overflow-y-auto px-4 py-3 flex flex-col gap-2"></div>
        <form id="messagesSendForm" class="flex items-end gap-2 px-4 py-3 border-t border-slate-200 dark:border-slate-700 shrink-0">
            <textarea id="messagesSendInput" rows="1" placeholder="Type a message…" maxlength="2000"
                      class="flex-1 resize-none text-sm font-mono border border-slate-300 dark:border-slate-600 rounded-lg px-3 py-2 bg-white dark:bg-slate-800 text-slate-800 dark:text-slate-100 focus:outline-none focus:ring-2 focus:ring-yellow-500"></textarea>
            <button type="submit" class="shrink-0 bg-primary hover:bg-primary-dark text-white text-xs font-semibold font-mono px-4 py-2.5 rounded-lg cursor-pointer">Send</button>
        </form>
    </div>
</div>

@push('scripts')
<script>
(function () {
    let currentPartnerId = null;
    let threadPollInterval = null;
    let unreadPollInterval = null;

    // Server-rendered once here (not inline route() calls scattered through
    // the JS below) — a single Blade-to-JS boundary is easier to read and
    // avoids any ambiguity between Blade's own single-quoted route() calls
    // and the JS string literals they'd otherwise sit inside.
    const routes = {
        unreadCount: "{{ route('messages.unread-count') }}",
        inbox: "{{ route('messages.inbox') }}",
        users: "{{ route('messages.users') }}",
        threadBase: "{{ url('/messages') }}",
    };

    const toggle = document.getElementById('messagesToggle');
    const backdrop = document.getElementById('messagesBackdrop');
    const panel = document.getElementById('messagesPanel');
    const listView = document.getElementById('messagesListView');
    const threadView = document.getElementById('messagesThreadView');
    const badge = document.getElementById('messagesBadge');
    const closeBtn = document.getElementById('messagesCloseBtn');
    const backBtn = document.getElementById('messagesBackBtn');
    const threadCloseBtn = document.getElementById('messagesThreadCloseBtn');
    const newBtn = document.getElementById('messagesNewBtn');
    const newSearchWrap = document.getElementById('messagesNewSearchWrap');
    const newSearch = document.getElementById('messagesNewSearch');
    const newResults = document.getElementById('messagesNewResults');
    const conversationList = document.getElementById('messagesConversationList');
    const threadName = document.getElementById('messagesThreadName');
    const threadBody = document.getElementById('messagesThreadBody');
    const sendForm = document.getElementById('messagesSendForm');
    const sendInput = document.getElementById('messagesSendInput');

    function csrfToken() {
        return document.querySelector('meta[name="csrf-token"]')?.content || '';
    }

    function escapeHtml(str) {
        const div = document.createElement('div');
        div.textContent = str ?? '';
        return div.innerHTML;
    }

    function updateBadge(count) {
        if (count > 0) {
            badge.textContent = count > 99 ? '99+' : String(count);
            badge.classList.remove('hidden');
        } else {
            badge.classList.add('hidden');
        }
    }

    function pollUnreadCount() {
        fetch(routes.unreadCount, { headers: { Accept: 'application/json' } })
            .then((res) => (res.ok ? res.json() : null))
            .then((data) => { if (data) updateBadge(data.count); })
            .catch(() => {});
    }

    function openPanel() {
        backdrop.classList.remove('hidden');
        panel.classList.remove('hidden');
        showListView();
        loadConversationList();
    }

    function closePanel() {
        backdrop.classList.add('hidden');
        panel.classList.add('hidden');
        stopThreadPoll();
    }

    function showListView() {
        listView.classList.remove('hidden');
        listView.classList.add('flex');
        threadView.classList.add('hidden');
        threadView.classList.remove('flex');
        newSearchWrap.classList.add('hidden');
        newSearch.value = '';
        newResults.innerHTML = '';
        stopThreadPoll();
        currentPartnerId = null;
    }

    function loadConversationList() {
        fetch(routes.inbox, { headers: { Accept: 'application/json' } })
            .then((res) => res.ok ? res.json() : null)
            .then((data) => renderConversationList(data?.conversations || []))
            .catch(() => renderConversationList([]));
    }

    function renderConversationList(conversations) {
        if (!conversations.length) {
            conversationList.innerHTML = '<p class="text-slate-400 text-center text-xs font-mono py-10">No conversations yet — tap + to start one.</p>';
            return;
        }
        conversationList.innerHTML = conversations.map((c) => `
            <div class="messages-conversation-row flex items-center gap-3 px-4 py-3 cursor-pointer hover:bg-slate-50 dark:hover:bg-slate-800 border-b border-slate-100 dark:border-slate-800" data-id="${c.id}" data-name="${escapeHtml(c.name)}">
                <span class="w-9 h-9 rounded-full bg-primary/10 text-primary-dark dark:text-yellow-400 flex items-center justify-center text-xs font-bold shrink-0">${escapeHtml((c.name || '?').charAt(0).toUpperCase())}</span>
                <div class="flex-1 min-w-0">
                    <div class="flex items-center justify-between gap-2">
                        <p class="text-sm font-semibold text-slate-700 dark:text-slate-200 truncate">${escapeHtml(c.name)}</p>
                        <span class="text-[10px] text-slate-400 shrink-0">${escapeHtml(c.label)}</span>
                    </div>
                    <div class="flex items-center justify-between gap-2">
                        <p class="text-xs text-slate-400 truncate">${escapeHtml(c.preview)}</p>
                        ${c.unreadCount > 0 ? `<span class="bg-red-500 text-white text-[9px] font-bold rounded-full min-w-[16px] h-[16px] px-1 flex items-center justify-center shrink-0">${c.unreadCount > 99 ? '99+' : c.unreadCount}</span>` : ''}
                    </div>
                </div>
            </div>`).join('');
    }

    document.addEventListener('DOMContentLoaded', () => {
        toggle?.addEventListener('click', openPanel);
        closeBtn?.addEventListener('click', closePanel);
        backdrop?.addEventListener('click', closePanel);
        backBtn?.addEventListener('click', showListView);
        threadCloseBtn?.addEventListener('click', closePanel);

        newBtn?.addEventListener('click', () => {
            newSearchWrap.classList.toggle('hidden');
            if (!newSearchWrap.classList.contains('hidden')) {
                newSearch.focus();
                searchNewPartners('');
            }
        });

        let newSearchDebounce = null;
        newSearch?.addEventListener('input', (e) => {
            clearTimeout(newSearchDebounce);
            newSearchDebounce = setTimeout(() => searchNewPartners(e.target.value.trim()), 250);
        });

        sendForm?.addEventListener('submit', (e) => {
            e.preventDefault();
            sendMessage();
        });
        sendInput?.addEventListener('keydown', (e) => {
            if (e.key === 'Enter' && !e.shiftKey) {
                e.preventDefault();
                sendMessage();
            }
        });

        pollUnreadCount();
        unreadPollInterval = setInterval(pollUnreadCount, 20000);
    });

    // A direct /messages/{user} visit (messages/thread.blade.php's own
    // fallback page) opens the panel straight into that thread, instead of
    // that page duplicating a second full chat UI.
    document.addEventListener('messages:open-thread', (e) => {
        openPanel();
        openThread(e.detail.id, e.detail.name);
    });

    function searchNewPartners(q) {
        fetch(routes.users + '?q=' + encodeURIComponent(q), { headers: { Accept: 'application/json' } })
            .then((res) => res.json())
            .then((data) => renderNewSearchResults(data.users || []))
            .catch(() => renderNewSearchResults([]));
    }

    function renderNewSearchResults(users) {
        if (!users.length) {
            newResults.innerHTML = '<p class="text-slate-400 text-center text-xs font-mono py-4">No one found.</p>';
            return;
        }
        newResults.innerHTML = users.map((u) => `
            <div class="messages-new-result flex items-center gap-2 px-2 py-2 text-sm rounded-lg cursor-pointer hover:bg-yellow-50 dark:hover:bg-yellow-950/40 text-slate-700 dark:text-slate-200" data-id="${u.id}" data-name="${escapeHtml(u.name)}">
                <span class="w-7 h-7 rounded-full bg-primary/10 text-primary-dark dark:text-yellow-400 flex items-center justify-center text-[11px] font-bold shrink-0">${escapeHtml((u.name || '?').charAt(0).toUpperCase())}</span>
                <span class="truncate">${escapeHtml(u.name)}</span>
            </div>`).join('');
    }

    newResults.addEventListener('click', (e) => {
        const row = e.target.closest('.messages-new-result');
        if (!row) return;
        openThread(parseInt(row.dataset.id, 10), row.dataset.name);
    });

    conversationList.addEventListener('click', (e) => {
        const row = e.target.closest('.messages-conversation-row');
        if (!row) return;
        openThread(parseInt(row.dataset.id, 10), row.dataset.name);
    });

    function openThread(userId, userName) {
        currentPartnerId = userId;
        listView.classList.add('hidden');
        listView.classList.remove('flex');
        threadView.classList.remove('hidden');
        threadView.classList.add('flex');
        threadName.textContent = userName || '';
        threadBody.innerHTML = '<p class="text-slate-400 text-center text-xs font-mono py-8">Loading…</p>';
        loadThread(userId, true);
        stopThreadPoll();
        threadPollInterval = setInterval(() => loadThread(userId, false), 8000);
    }

    function stopThreadPoll() {
        if (threadPollInterval) {
            clearInterval(threadPollInterval);
            threadPollInterval = null;
        }
    }

    function loadThread(userId, scrollToBottom) {
        fetch(`${routes.threadBase}/${userId}`, { headers: { Accept: 'application/json' } })
            .then((res) => res.json())
            .then((data) => {
                if (!data.success || currentPartnerId !== userId) return;
                renderThreadMessages(data.messages);
                if (scrollToBottom) threadBody.scrollTop = threadBody.scrollHeight;
                pollUnreadCount();
            })
            .catch(() => {});
    }

    function renderThreadMessages(messages) {
        if (!messages.length) {
            threadBody.innerHTML = '<p class="text-slate-400 text-center text-xs font-mono py-8">No messages yet — say hello.</p>';
            return;
        }
        const wasAtBottom = threadBody.scrollTop + threadBody.clientHeight >= threadBody.scrollHeight - 20;
        threadBody.innerHTML = messages.map((m) => `
            <div class="flex ${m.fromMe ? 'justify-end' : 'justify-start'}">
                <div class="max-w-[80%] ${m.fromMe ? 'bg-primary text-white' : 'bg-slate-100 dark:bg-slate-800 text-slate-700 dark:text-slate-200'} rounded-xl px-3 py-2">
                    <p class="whitespace-pre-wrap break-words text-sm">${escapeHtml(m.body)}</p>
                    <p class="text-[10px] mt-1 ${m.fromMe ? 'text-yellow-100' : 'text-slate-400'}">${escapeHtml(m.label)}</p>
                </div>
            </div>`).join('');
        if (wasAtBottom) threadBody.scrollTop = threadBody.scrollHeight;
    }

    function sendMessage() {
        const body = sendInput.value.trim();
        if (!body || !currentPartnerId) return;

        sendInput.value = '';
        fetch(`${routes.threadBase}/${currentPartnerId}`, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                Accept: 'application/json',
                'X-CSRF-TOKEN': csrfToken(),
            },
            body: JSON.stringify({ body }),
        })
            .then((res) => res.json())
            .then((data) => {
                if (data.success) loadThread(currentPartnerId, true);
            })
            .catch(() => {
                window.showToast?.('Could not send — try again.', 'error');
            });
    }
})();
</script>
@endpush
