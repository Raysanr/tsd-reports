// Ported from call-tracker's resources/js/app.js (merged into one app
// 2026-08-12) as a SEPARATE Vite entry point — resources/js/app.js (tsd-
// reports' own) is untouched. Loaded only on /calls/* pages via
// @push('scripts') @vite('resources/js/calls.js') @endpush in each
// calls/*.blade.php view, so this 30s-polling/modal JS never loads on pages
// that don't need it. Every relative fetch URL below is prefixed with
// /calls to match this app's routes/web.php Route::prefix('calls') group
// (call-tracker's own routes were unprefixed).
import './bootstrap';
import {
    Chart, BarController, BarElement, LineController, LineElement, PointElement,
    DoughnutController, ArcElement, CategoryScale, LinearScale, Tooltip, Legend, Title, Filler,
} from 'chart.js';
Chart.register(
    BarController, BarElement, LineController, LineElement, PointElement,
    DoughnutController, ArcElement, CategoryScale, LinearScale, Tooltip, Legend, Title, Filler,
);

// ─── Dark mode toggle ────────────────────────────────────────────────────────
// Same mechanism as TSD Reports' own app.js (shared 'theme' localStorage key,
// same .dark class strategy in both CSS bundles) — the actual class is
// applied by an inline <head> script in layouts/calls.blade.php before paint;
// this just wires the button to flip it after load and persist the choice.
(function () {
    const toggle   = document.getElementById('themeToggle');
    const sunIcon  = document.getElementById('themeIconSun');
    const moonIcon = document.getElementById('themeIconMoon');
    if (!toggle) return;

    function syncIcon() {
        const isDark = document.documentElement.classList.contains('dark');
        sunIcon?.classList.toggle('hidden', !isDark);
        moonIcon?.classList.toggle('hidden', isDark);
    }
    syncIcon();

    toggle.addEventListener('click', () => {
        const isDark = document.documentElement.classList.toggle('dark');
        localStorage.setItem('theme', isDark ? 'dark' : 'light');
        syncIcon();
    });
})();

// ─── Reload button ───────────────────────────────────────────────────────────
// Plain full reload with spin feedback — Call Tracker has no softRefresh-
// equivalent AJAX view-swap yet (unlike TSD Reports' own app.js), so this is
// the simple version rather than replicating that whole mechanism.
(function () {
    const btn = document.getElementById('reloadBtn');
    if (!btn) return;

    btn.addEventListener('click', () => {
        const icon = document.getElementById('reloadIcon');
        btn.disabled = true;
        icon?.classList.add('animate-spin');
        window.location.reload();
    });
})();

// ─── Toast notifications ─────────────────────────────────────────────────────
// Ported from TSD Reports' own app.js (same #toastContainer markup in
// layouts/calls.blade.php now) — explicit request, 2026-08-17, needed for the
// Dashboard's new Sync button to give any feedback at all. window.showToast
// is the one entry point for transient feedback across Call Tracker, same as
// the TSD Reports side.
const CALLS_TOAST_VARIANTS = {
    success: {
        classes: 'bg-green-50 border-green-200', iconClasses: 'text-green-500',
        textClasses: 'text-green-700', closeClasses: 'text-green-400 hover:text-green-600',
        iconPath: 'M5 13l4 4L19 7',
    },
    error: {
        classes: 'bg-red-50 border-red-200', iconClasses: 'text-red-500',
        textClasses: 'text-red-700', closeClasses: 'text-red-400 hover:text-red-600',
        iconPath: 'M9.75 9.75l4.5 4.5m0-4.5l-4.5 4.5M21 12a9 9 0 11-18 0 9 9 0 0118 0z',
    },
    info: {
        classes: 'bg-blue-50 border-blue-200', iconClasses: 'text-blue-500',
        textClasses: 'text-blue-700', closeClasses: 'text-blue-400 hover:text-blue-600',
        iconPath: 'M11.25 11.25l.041-.02a.75.75 0 011.063.852l-.708 2.836a.75.75 0 001.063.853l.041-.021M21 12a9 9 0 11-18 0 9 9 0 0118 0zm-9-3.75h.008v.008H12V8.25z',
    },
};

window.showToast = function (message, variant = 'success') {
    const container = document.getElementById('toastContainer');
    if (!container) return;

    let v = CALLS_TOAST_VARIANTS[variant];
    if (!v) console.warn(`showToast: unknown variant "${variant}", falling back to "success"`);
    v = v || CALLS_TOAST_VARIANTS.success;
    const reduceMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;

    const toast = document.createElement('div');
    toast.setAttribute('role', variant === 'error' ? 'alert' : 'status');
    toast.className = 'pointer-events-auto flex items-center gap-3 border rounded-xl px-4 py-3 shadow-lg '
        + v.classes + ' opacity-0 transition-all duration-200 ease-out'
        + (reduceMotion ? '' : ' translate-x-4');

    toast.innerHTML = `
        <svg class="w-4 h-4 ${v.iconClasses} shrink-0" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" aria-hidden="true">
            <path stroke-linecap="round" stroke-linejoin="round" d="${v.iconPath}"/>
        </svg>
        <p class="text-sm font-mono ${v.textClasses} flex-1"></p>
        <button type="button" class="${v.closeClasses} shrink-0 cursor-pointer" aria-label="Dismiss">
            <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/>
            </svg>
        </button>
    `;
    toast.querySelector('p').textContent = message;

    container.appendChild(toast);

    requestAnimationFrame(() => {
        toast.classList.remove('opacity-0');
        toast.classList.add('opacity-100');
        if (!reduceMotion) {
            toast.classList.remove('translate-x-4');
            toast.classList.add('translate-x-0');
        }
    });

    let dismissTimer = null;
    const dismiss = () => {
        clearTimeout(dismissTimer);
        toast.classList.remove('opacity-100', 'translate-x-0');
        toast.classList.add('opacity-0');
        if (!reduceMotion) toast.classList.add('translate-x-4');
        toast.classList.replace('duration-200', 'duration-150');
        toast.classList.replace('ease-out', 'ease-in');
        setTimeout(() => toast.remove(), reduceMotion ? 0 : 150);
    };

    const startTimer = () => { dismissTimer = setTimeout(dismiss, 4000); };
    startTimer();

    toast.addEventListener('mouseenter', () => clearTimeout(dismissTimer));
    toast.addEventListener('mouseleave', startTimer);
    toast.addEventListener('focusin', () => clearTimeout(dismissTimer));
    toast.addEventListener('focusout', startTimer);
    toast.querySelector('button').addEventListener('click', dismiss);
};

// Shared scale+fade open/close for every modal here (conversation, outcome
// tag, upsell, calling) — explicit request, 2026-08-17. Each modal's own
// backdrop starts opacity-0 and its inner panel opacity-0 scale-95 (see
// modals.blade.php); this just flips them to the "open" state one frame
// later so the browser actually has something to transition FROM, and
// reverses on close, waiting out the transition before re-hiding so the
// close animation is visible instead of the element just vanishing.
const MODAL_TRANSITION_MS = 200;

function showModal(modal) {
    if (!modal) return;
    const panel = modal.querySelector(':scope > div');
    modal.classList.remove('hidden');
    modal.classList.add('flex');
    requestAnimationFrame(() => {
        modal.classList.remove('opacity-0');
        panel?.classList.remove('opacity-0', 'scale-95');
    });
}

function hideModal(modal) {
    if (!modal) return;
    const panel = modal.querySelector(':scope > div');
    modal.classList.add('opacity-0');
    panel?.classList.add('opacity-0', 'scale-95');
    setTimeout(() => {
        modal.classList.add('hidden');
        modal.classList.remove('flex');
    }, MODAL_TRANSITION_MS);
}

// Sidebar badge counts — no websocket/queue infra in this stack, so a cheap
// periodic poll is the "free" version of a live notification badge.
// NOTE: tsd-reports' shared layout (layouts/app.blade.php) doesn't carry a
// data-notifications-url attribute or #badge-assigned/#badge-overdue/
// #badge-callbacks elements yet — those are added to the shared nav in a
// later phase (Phase 7 of the merge plan). This polls unconditionally but
// updateBadge() is a no-op until those elements exist, so it's effectively
// dormant until then.
function updateBadge(id, count) {
    const el = document.getElementById(id);
    if (!el) return;
    if (count > 0) {
        el.textContent = count > 99 ? '99+' : count;
        el.classList.remove('hidden');
    } else {
        el.classList.add('hidden');
    }
}

// Carries the active date range/TSA filter through to the count query, so
// the badge always matches what clicking into Leads would actually show
// instead of counting every matching lead ever. Prefers the CURRENT page's
// own URL when it has them (e.g. actually on Leads/Overdue/Callbacks), but
// falls back to the same localStorage the Leads page itself persists to
// (see leads/index.blade.php) when it doesn't — otherwise the badge flickered
// back to the unfiltered "all TSAs" count on every OTHER page (Dashboard,
// Analytics, etc.) even with a filter actively applied, only "coming back"
// once you clicked into Leads again. Falls back to "today" server-side when
// neither the URL nor localStorage has a date range.
function pollNotificationCounts() {
    const params = new URLSearchParams();
    const current = new URLSearchParams(window.location.search);

    if (current.has('date_from') && current.has('date_to')) {
        params.set('date_from', current.get('date_from'));
        params.set('date_to', current.get('date_to'));
    } else {
        try {
            const saved = JSON.parse(localStorage.getItem('callsLeadsDateRange') || 'null');
            if (saved?.from && saved?.to) {
                params.set('date_from', saved.from);
                params.set('date_to', saved.to);
            }
        } catch (e) { /* corrupt/old value — ignore */ }
    }

    if (current.has('tsa')) {
        if (current.get('tsa')) params.set('tsa', current.get('tsa'));
    } else {
        const savedTsa = localStorage.getItem('callsLeadsTsa');
        if (savedTsa) params.set('tsa', savedTsa);
    }

    const url = '/calls/api/notification-counts' + (params.toString() ? '?' + params.toString() : '');

    fetch(url, { headers: { Accept: 'application/json' } })
        .then((res) => (res.ok ? res.json() : null))
        .then((data) => {
            if (!data) return;
            updateBadge('badge-assigned', data.assigned);
            updateBadge('badge-overdue', data.overdue);
            updateBadge('badge-callbacks', data.callbacks);
        })
        .catch(() => {});
}

if (document.getElementById('badge-assigned') || document.getElementById('badge-overdue') || document.getElementById('badge-callbacks')) {
    pollNotificationCounts();
    setInterval(pollNotificationCounts, 30000);
}

// TSA status panel (calls/partials/tsa-status-panel.blade.php) — one shared
// component for both the topbar (a TSA's own LOGIN/BREAK/DNA HUDDLE/
// COACHING/LOGOUT — not wired into tsd-reports' shared header yet, see that
// partial's own doc comment) and Call Rotation's per-row control (an admin
// setting any TSA's, including the admin-only Lock). $target on the panel
// itself says which: 'self' or a tsa id — see submitStatusChange() below.
window.toggleStatusPanel = function (id) {
    // Close every other open panel first — only one should ever be visible,
    // whether that's the topbar's or another TSA's row on Call Rotation.
    document.querySelectorAll('[data-status-panel]').forEach((p) => {
        if (p.id !== `statusPanel-${id}`) p.classList.add('hidden');
    });

    const panel = document.getElementById(`statusPanel-${id}`);
    const trigger = document.getElementById(`statusTrigger-${id}`);
    if (!panel || !trigger) return;

    if (panel.classList.contains('hidden')) {
        const rect = trigger.getBoundingClientRect();
        panel.style.top = `${rect.bottom + 8}px`;
        panel.style.right = `${window.innerWidth - rect.right}px`;
        panel.style.left = '';
        panel.classList.remove('hidden');
    } else {
        panel.classList.add('hidden');
    }
};

async function submitStatusChange(target, status, panel) {
    const body = target === 'self' ? { status } : { status, tsa_id: target };

    try {
        const res = await fetch('/calls/tsa-status', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                Accept: 'application/json',
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || '',
            },
            body: JSON.stringify(body),
        });
        const data = await res.json();

        // Full reload rather than patching the trigger/checkmarks in place —
        // this is a low-frequency action (a few times a shift, not per
        // keystroke), and a reload keeps every panel's label/dot/checkmark
        // (there can be many on Call Rotation, one per row) trivially
        // correct instead of hand-syncing each one client-side.
        if (data.success) {
            location.reload();
        } else {
            panel.classList.add('hidden');
        }
    } catch (e) {
        panel.classList.add('hidden');
    }
}

document.addEventListener('click', (e) => {
    const option = e.target.closest('.status-panel-option');
    if (option) {
        const panel = option.closest('[data-status-panel]');
        submitStatusChange(panel.dataset.statusTarget, option.dataset.value, panel);
        return;
    }
    // Backdrop click (anywhere outside a trigger+panel pair) closes whatever's open.
    if (!e.target.closest('[data-status-panel-wrap]')) {
        document.querySelectorAll('[data-status-panel]').forEach((p) => p.classList.add('hidden'));
    }
});

document.addEventListener('keydown', (e) => {
    if (e.key === 'Escape') {
        document.querySelectorAll('[data-status-panel]').forEach((p) => p.classList.add('hidden'));
    }
});

// Real-time-ish Leads table — re-fetches the same filtered URL every few
// seconds and swaps in the freshly rendered table (see
// LeadController::index()'s X-Table-Refresh branch), so a lead the scheduler
// just synced shows up here without anyone hitting reload. Skipped while the
// user has a control inside the table focused (e.g. mid-way through picking
// a disposition), so a poll landing mid-edit can't wipe out unsaved input.
//
// FLIP-animates reordered rows (First/Last/Invert/Play) instead of the
// previous whole-table opacity cross-fade (explicit request, 2026-08-19: the
// old fade-everything-out-and-back-in approach read as the entire table
// blinking just to move the one row a pin toggle actually reordered).
// Matched old->new by data-lead-id (_table.blade.php) — a row not present in
// both (a freshly synced lead, or one that scrolled off this page) just
// renders in its new spot with no animation, same as before.
function pollLeadsTable() {
    const container = document.getElementById('leads-table-container');
    if (!container) return;

    if (container.contains(document.activeElement) && document.activeElement !== document.body) {
        return;
    }

    const firstRects = new Map(
        Array.from(container.querySelectorAll('tr[data-lead-id]'))
            .map((tr) => [tr.dataset.leadId, tr.getBoundingClientRect()]),
    );

    // cache: 'no-store' — root-caused 2026-08-17: the server's own
    // Cache-Control (no-cache, not no-store) still let the browser serve a
    // stale cached response for this exact URL+headers combo without
    // re-hitting the server, so a pin toggle's immediate re-poll (same URL
    // as the last regular poll) could show the OLD order. Confirmed live: a
    // manual no-store fetch returned the freshly pinned lead first while the
    // page's own (cached) poll still showed the previous one.
    fetch(container.dataset.pollUrl, { headers: { 'X-Table-Refresh': '1' }, cache: 'no-store' })
        .then((res) => (res.ok ? res.text() : null))
        .then((html) => {
            if (html === null) return;
            // Re-check focus: the fetch is async, so the user could have
            // clicked into a form while it was in flight.
            if (container.contains(document.activeElement) && document.activeElement !== document.body) {
                return;
            }

            container.innerHTML = html;

            // Last -> Invert -> Play: for every row present before AND after,
            // jump it back to its old (First) position with no transition,
            // then release that transform with one on the next frame so it
            // visibly slides from old to new. translateY only — a reorder
            // never changes column widths, so X never needs correcting.
            container.querySelectorAll('tr[data-lead-id]').forEach((tr) => {
                const before = firstRects.get(tr.dataset.leadId);
                if (!before) return;
                const delta = before.top - tr.getBoundingClientRect().top;
                if (Math.abs(delta) < 1) return;

                tr.style.transition = 'none';
                tr.style.transform = `translateY(${delta}px)`;
                tr.getBoundingClientRect(); // force layout so the jump above commits before Play
                requestAnimationFrame(() => {
                    tr.style.transition = 'transform 220ms ease';
                    tr.style.transform = '';
                });
            });
        })
        .catch(() => {});
}

if (document.getElementById('leads-table-container')) {
    setInterval(pollLeadsTable, 15000);
}

// Pin/unpin (explicit request, 2026-08-17) — submits in the background and
// re-polls immediately instead of waiting out the normal 15s cycle, so the
// reorder to the top (or back out) is visible right away.
document.addEventListener('submit', (e) => {
    if (!e.target.matches('.pin-form')) return;
    e.preventDefault();
    const form = e.target;
    fetch(form.action, {
        method: 'POST',
        headers: { 'X-Requested-With': 'XMLHttpRequest' },
        body: new FormData(form),
    })
        .then(() => {
            // Root-caused 2026-08-17: pollLeadsTable()'s own focus-guard (meant
            // to protect an unrelated in-progress edit elsewhere in the table
            // from being wiped by a periodic poll) was ALSO silently blocking
            // this deliberate, user-initiated refresh — the just-clicked pin
            // button is itself still focused and sits inside that same
            // container, so the guard's condition was true for its own click.
            // Confirmed by monkey-patching window.fetch: the pin POST fired,
            // pollLeadsTable()'s own fetch never did. Blurring first clears
            // that guard so the reorder actually shows up without a manual
            // reload.
            form.querySelector('button')?.blur();
            pollLeadsTable();
        })
        .catch(() => {});
});

// TSA Management Save (explicit request, 2026-08-18) — submits via fetch
// instead of a normal POST so the row's already-expanded edit panel and the
// admin's scroll position stay exactly as they were: the old plain-POST form
// redirected back to a fresh full-page render, which re-collapsed every row
// (calls/tsa-management/_table.blade.php always server-renders each panel
// closed) and lost scroll position on every single Save. Same delegated
// document-level submit + FormData pattern as the pin-form above (FormData
// carries the @csrf token along automatically), confirmed with a toast
// (same convention as the dashboard's own Sync button) instead of the old
// redirect+flash-message round trip. Accept: application/json is what makes
// TsaManagementController::update() take its wantsJson() branch and return
// JSON instead of redirecting.
document.addEventListener('submit', (e) => {
    if (!e.target.matches('.tsa-update-form')) return;
    e.preventDefault();
    const form = e.target;
    const btn = form.querySelector('button[type="submit"]');
    const originalLabel = btn?.textContent;
    if (btn) {
        btn.disabled = true;
        btn.textContent = 'Saving…';
    }

    fetch(form.action, {
        method: 'POST',
        headers: { 'X-Requested-With': 'XMLHttpRequest', Accept: 'application/json' },
        body: new FormData(form),
    })
        .then((res) => (res.ok ? res.json() : Promise.reject(res)))
        .then((data) => {
            window.showToast?.(data.message || 'Saved.', 'success');
            if (btn) btn.textContent = '✓ Saved';
        })
        .catch(async (res) => {
            // A 422 here is a real validation failure (phone_number/dialer_host
            // too long, an invalid product id) — surface Laravel's own first
            // error message rather than a generic one, same as everywhere else
            // in this app that reads response.errors.
            let message = 'Could not save — try again.';
            if (res?.json) {
                try {
                    const data = await res.json();
                    message = Object.values(data.errors || {})[0]?.[0] || data.message || message;
                } catch (e) { /* not JSON — keep the generic message */ }
            }
            window.showToast?.(message, 'error');
        })
        .finally(() => {
            if (!btn) return;
            setTimeout(() => {
                btn.disabled = false;
                btn.textContent = originalLabel;
            }, 900);
        });
});

// Conversation modal — fetches real Pancake messages via our own backend
// (/calls/leads/{id}/conversation) and renders chat bubbles ourselves.
// Pancake's own page can't be embedded here (their CSP frame-ancestors
// blocks any non-Pancake domain), so this is real data, not an iframe.
// Attached to window since it's called from inline onclick= attributes in
// Blade views.
function escapeHtml(str) {
    const div = document.createElement('div');
    div.textContent = str ?? '';
    return div.innerHTML;
}

function renderMessage(msg) {
    // A TSA/admin reply has admin_id/uid; an automated broadcast from the
    // page itself has neither but its from.id equals the page's own FB ID —
    // both are "our side" of the conversation, just not a customer message.
    const isStaff = !!(msg.from && (msg.from.admin_id || msg.from.uid || (msg.page_id && msg.from.id === msg.page_id)));
    const senderName = (msg.from && msg.from.name) || (isStaff ? 'Staff' : 'Customer');
    const when = msg.inserted_at ? new Date(msg.inserted_at).toLocaleString() : '';

    const attachments = (msg.attachments || [])
        .filter((a) => a.type === 'photo' || a.type === 'sticker')
        .map((a) => `<img src="${escapeHtml(a.url)}" class="mt-2 rounded-lg max-w-[220px] max-h-[220px] object-cover">`)
        .join('');

    const text = escapeHtml(msg.original_message || msg.message || '');

    return `
    <div class="flex ${isStaff ? 'justify-end' : 'justify-start'}">
        <div class="max-w-[80%] ${isStaff ? 'bg-primary text-white' : 'bg-white dark:bg-slate-800 border border-slate-200 dark:border-slate-700 text-slate-700 dark:text-slate-200'} rounded-xl px-3 py-2">
            <p class="text-[10px] font-bold uppercase tracking-wide ${isStaff ? 'text-yellow-100' : 'text-slate-400'} mb-0.5">${escapeHtml(senderName)}</p>
            ${text ? `<p class="whitespace-pre-wrap break-words">${text}</p>` : ''}
            ${attachments}
            <p class="text-[10px] ${isStaff ? 'text-yellow-100' : 'text-slate-400'} mt-1">${escapeHtml(when)}</p>
        </div>
    </div>`;
}

window.openConversationModal = function (leadId) {
    const modal = document.getElementById('conversationModal');
    const body = document.getElementById('conversationModalBody');
    if (!modal || !body) return;

    showModal(modal);
    body.innerHTML = '<p class="text-slate-400 text-center py-10">Loading conversation…</p>';

    fetch(`/calls/leads/${leadId}/conversation`, { headers: { Accept: 'application/json' } })
        .then((res) => res.json())
        .then((data) => {
            if (!data.success) {
                body.innerHTML = `<p class="text-red-500 text-center py-10">${escapeHtml(data.error || 'Could not load this conversation.')}</p>`;
                return;
            }
            if (!data.messages.length) {
                body.innerHTML = '<p class="text-slate-400 text-center py-10">No messages yet.</p>';
                return;
            }
            // API returns newest-first; render oldest-first like a normal chat thread.
            body.innerHTML = data.messages.slice().reverse().map(renderMessage).join('');
            body.scrollTop = body.scrollHeight;
        })
        .catch(() => {
            body.innerHTML = '<p class="text-red-500 text-center py-10">Something went wrong loading this conversation.</p>';
        });
};

window.closeConversationModal = function () {
    hideModal(document.getElementById('conversationModal'));
};

document.addEventListener('keydown', (e) => {
    if (e.key === 'Escape') window.closeConversationModal();
});

document.addEventListener('click', (e) => {
    if (e.target && e.target.id === 'conversationModal') window.closeConversationModal();
});

// Outcome picker — a shared modal (see #outcomeTagModal in
// calls/partials/modals.blade.php) showing a multi-select checklist of the
// lead's own Pancake page's REAL tag catalog (LeadController::searchTags()),
// matching Pancake's own "Add tag" popup: the full catalog shows right away
// (not just narrow-as-you-type results), typing filters it further, and a
// TSA can pick several tags for one outcome — every picked tag gets written
// back to the real conversation in Pancake
// (LeadController::tagOutcomeInPancake()), not just the first one. Each row
// keeps its own chips/hidden-field state (so the picked tags are visible
// without the modal open); the modal itself is one shared instance whose
// content gets pointed at whichever row's "+ Add tag" button was clicked.
// Delegated where needed (not bound per-row) so it keeps working after the
// 15s table poll above replaces the table's rows out from under any
// per-element listeners — the Leads table and the lead detail page
// (leads/show.blade.php) share the exact same markup/classes, so one set of
// handlers covers both.
let activeDispositionPicker = null;
let outcomeModalDebounce = null;

function getSelectedTags(picker) {
    try {
        return JSON.parse(picker.dataset.selected || '[]');
    } catch (e) {
        return [];
    }
}

function renderChipsInto(container, tags) {
    container.innerHTML = tags.map((text) => `
        <span class="inline-flex items-center gap-1 bg-yellow-100 dark:bg-yellow-900/40 text-primary-dark dark:text-yellow-300 text-[11px] font-mono font-semibold pl-2 pr-1 py-0.5 rounded-full">
            ${escapeHtml(text)}
            <button type="button" class="disposition-chip-remove hover:text-red-600 cursor-pointer leading-none" data-text="${escapeHtml(text)}" aria-label="Remove ${escapeHtml(text)}">×</button>
        </span>
    `).join('');
}

function setSelectedTags(picker, tags) {
    picker.dataset.selected = JSON.stringify(tags);
    picker.querySelector('.disposition-hidden-input').value = tags.join(', ');
    renderChipsInto(picker.querySelector('.disposition-selected-chips'), tags);

    const callbackInput = picker.closest('form').querySelector('.callback-at-input');
    if (callbackInput) {
        callbackInput.classList.toggle('hidden', !tags.some((t) => t.toLowerCase().includes('call back')));
    }

    // If the modal is open for THIS row, keep its own chip strip + result
    // checkmarks in sync too.
    if (activeDispositionPicker === picker) {
        renderChipsInto(document.getElementById('outcomeTagModalChips'), tags);
        searchOutcomeTagModal(document.getElementById('outcomeTagModalSearch').value.trim());
    }
}

function toggleDispositionTag(picker, text) {
    const selected = getSelectedTags(picker);
    const idx = selected.indexOf(text);
    if (idx === -1) selected.push(text); else selected.splice(idx, 1);
    setSelectedTags(picker, selected);
}

window.openOutcomeTagModal = function (picker) {
    activeDispositionPicker = picker;
    const modal = document.getElementById('outcomeTagModal');
    const search = document.getElementById('outcomeTagModalSearch');
    showModal(modal);
    search.value = '';
    renderChipsInto(document.getElementById('outcomeTagModalChips'), getSelectedTags(picker));
    searchOutcomeTagModal('');
    search.focus();
};

window.closeOutcomeTagModal = function () {
    hideModal(document.getElementById('outcomeTagModal'));
    activeDispositionPicker = null;
};

async function searchOutcomeTagModal(q) {
    if (!activeDispositionPicker) return;
    const leadId = activeDispositionPicker.dataset.leadId;

    try {
        const res = await fetch(`/calls/leads/${leadId}/tags?q=` + encodeURIComponent(q));
        const data = await res.json();
        renderOutcomeTagModalResults(data.success ? data.tags : []);
    } catch (e) {
        renderOutcomeTagModalResults([]);
    }
}

function renderOutcomeTagModalResults(tags) {
    const results = document.getElementById('outcomeTagModalResults');
    if (!activeDispositionPicker) return;

    if (!tags.length) {
        results.innerHTML = '<p class="text-slate-400 text-center text-xs font-mono py-8">No tags found.</p>';
        return;
    }

    const selected = getSelectedTags(activeDispositionPicker);
    results.innerHTML = tags.map((t) => {
        const isSelected = selected.includes(t.text);
        const dotColor = t.color || '#94a3b8';
        return `
        <div class="outcome-modal-result-row flex items-center gap-2 px-4 py-2.5 text-sm font-mono cursor-pointer hover:bg-yellow-50 dark:hover:bg-yellow-950/40 border-b border-slate-50 dark:border-slate-800 ${isSelected ? 'bg-yellow-50 dark:bg-yellow-950/40 text-primary-dark dark:text-yellow-300 font-semibold' : 'text-slate-700 dark:text-slate-200'}" data-text="${escapeHtml(t.text)}">
            <span class="w-3 h-3 rounded-full shrink-0 border border-black/10" style="background-color:${escapeHtml(dotColor)}"></span>
            <span class="flex-1">${escapeHtml(t.text)}</span>
            ${isSelected ? '<svg class="w-4 h-4 text-primary shrink-0" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7"/></svg>' : ''}
        </div>`;
    }).join('');
}

document.getElementById('outcomeTagModalSearch')?.addEventListener('input', (e) => {
    clearTimeout(outcomeModalDebounce);
    outcomeModalDebounce = setTimeout(() => searchOutcomeTagModal(e.target.value.trim()), 250);
});

document.addEventListener('mousedown', (e) => {
    const row = e.target.closest('.outcome-modal-result-row');
    if (!row || !activeDispositionPicker) return;
    // mousedown fires before the search input's blur. Toggling (not
    // selecting-and-closing) is what makes multi-select possible — the
    // modal stays open so a TSA can keep picking more tags.
    e.preventDefault();
    toggleDispositionTag(activeDispositionPicker, row.dataset.text);
});

document.addEventListener('click', (e) => {
    const removeBtn = e.target.closest('.disposition-chip-remove');
    if (removeBtn) {
        // The remove × can be clicked either on a row's own chip strip or
        // the modal's chip strip — either way it belongs to whichever
        // picker is currently open, or the row itself if the modal is closed.
        const picker = activeDispositionPicker || removeBtn.closest('.disposition-picker');
        toggleDispositionTag(picker, removeBtn.dataset.text);
        return;
    }
    if (e.target.id === 'outcomeTagModal') window.closeOutcomeTagModal(); // backdrop click
});

document.addEventListener('keydown', (e) => {
    if (e.key === 'Escape') window.closeOutcomeTagModal();
});

// A TSA who never picked a real tag has nothing in the hidden field to
// submit — block the save and refocus (opening the modal back up) instead
// of posting an empty disposition.
document.addEventListener('submit', (e) => {
    if (!e.target.matches('.disposition-form')) return;
    const picker = e.target.querySelector('.disposition-picker');
    const hidden = picker?.querySelector('.disposition-hidden-input');
    if (hidden && !hidden.value) {
        e.preventDefault();
        if (picker) window.openOutcomeTagModal(picker);
    }
});

// Add Upsell modal — search a real Pancake product catalog, pick one, set
// quantity, add. Single-pick + immediate write (unlike the outcome tag
// modal's multi-select-then-batch-save), since each add is its own real
// write to a live Pancake order via LeadController::addUpsell(), not a
// locally-batched change.
let activeUpsellLeadId = null;
let selectedUpsellProduct = null;
let upsellModalDebounce = null;

window.openUpsellModal = function (leadId) {
    activeUpsellLeadId = leadId;
    selectedUpsellProduct = null;
    const modal = document.getElementById('upsellModal');
    const search = document.getElementById('upsellModalSearch');
    showModal(modal);
    document.getElementById('upsellModalConfirm').classList.add('hidden');
    document.getElementById('upsellModalError').classList.add('hidden');
    search.value = '';
    document.getElementById('upsellModalResults').innerHTML =
        '<p class="text-slate-400 text-center text-xs font-mono py-8">Type a product name to search…</p>';
    search.focus();
};

window.closeUpsellModal = function () {
    hideModal(document.getElementById('upsellModal'));
    activeUpsellLeadId = null;
    selectedUpsellProduct = null;
};

async function searchUpsellModal(q) {
    if (!activeUpsellLeadId) return;
    const results = document.getElementById('upsellModalResults');

    if (!q) {
        results.innerHTML = '<p class="text-slate-400 text-center text-xs font-mono py-8">Type a product name to search…</p>';
        return;
    }

    try {
        const res = await fetch(`/calls/leads/${activeUpsellLeadId}/products?q=` + encodeURIComponent(q));
        const data = await res.json();
        renderUpsellModalResults(data.success ? data.products : []);
    } catch (e) {
        renderUpsellModalResults([]);
    }
}

function renderUpsellModalResults(products) {
    const results = document.getElementById('upsellModalResults');

    if (!products.length) {
        results.innerHTML = '<p class="text-slate-400 text-center text-xs font-mono py-8">No products found.</p>';
        return;
    }

    results.innerHTML = products.map((p, i) => `
        <div class="upsell-modal-result-row flex items-center justify-between gap-2 px-4 py-2.5 text-sm font-mono cursor-pointer hover:bg-yellow-50 dark:hover:bg-yellow-950/40 border-b border-slate-50 dark:border-slate-800 text-slate-700 dark:text-slate-200" data-index="${i}">
            <span class="flex-1 truncate">${escapeHtml(p.name)}</span>
            <span class="text-primary-dark dark:text-yellow-300 font-semibold shrink-0">₱${Number(p.retail_price).toLocaleString()}</span>
        </div>`).join('');

    // Stashed on the container (not re-fetched) so a click can recover the
    // full {variation_id, product_id, name, retail_price} object — the DOM
    // row itself only ever renders name + price.
    results.dataset.products = JSON.stringify(products);
}

function selectUpsellProduct(product) {
    selectedUpsellProduct = product;
    document.getElementById('upsellModalConfirmName').textContent = `${product.name} — ₱${Number(product.retail_price).toLocaleString()}`;
    document.getElementById('upsellModalQuantity').value = 1;
    document.getElementById('upsellModalError').classList.add('hidden');
    document.getElementById('upsellModalConfirm').classList.remove('hidden');
}

window.submitUpsell = async function () {
    if (!activeUpsellLeadId || !selectedUpsellProduct) return;

    const qty = Math.max(1, parseInt(document.getElementById('upsellModalQuantity').value, 10) || 1);
    const btn = document.getElementById('upsellModalAddBtn');
    const errorEl = document.getElementById('upsellModalError');
    btn.disabled = true;
    btn.textContent = 'Adding…';
    errorEl.classList.add('hidden');

    try {
        const res = await fetch(`/calls/leads/${activeUpsellLeadId}/upsell`, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                Accept: 'application/json',
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || '',
            },
            body: JSON.stringify({
                variation_id: selectedUpsellProduct.variation_id,
                product_id: selectedUpsellProduct.product_id,
                name: selectedUpsellProduct.name,
                retail_price: selectedUpsellProduct.retail_price,
                quantity: qty,
            }),
        });
        const data = await res.json();

        if (data.success) {
            btn.textContent = '✓ Added';
            setTimeout(() => window.closeUpsellModal(), 900);
        } else {
            errorEl.textContent = data.error || 'Could not add this product — try again.';
            errorEl.classList.remove('hidden');
            btn.disabled = false;
            btn.textContent = 'Add to order';
        }
    } catch (e) {
        errorEl.textContent = 'Could not reach the server — try again.';
        errorEl.classList.remove('hidden');
        btn.disabled = false;
        btn.textContent = 'Add to order';
    }
};

document.getElementById('upsellModalSearch')?.addEventListener('input', (e) => {
    clearTimeout(upsellModalDebounce);
    upsellModalDebounce = setTimeout(() => searchUpsellModal(e.target.value.trim()), 250);
});

document.addEventListener('click', (e) => {
    const row = e.target.closest('.upsell-modal-result-row');
    if (!row) return;
    const products = JSON.parse(document.getElementById('upsellModalResults').dataset.products || '[]');
    const product = products[parseInt(row.dataset.index, 10)];
    if (product) selectUpsellProduct(product);
});

document.addEventListener('click', (e) => {
    if (e.target.id === 'upsellModal') window.closeUpsellModal(); // backdrop click
});

document.addEventListener('keydown', (e) => {
    if (e.key === 'Escape') window.closeUpsellModal();
});

// Click-to-call (tel: links, Leads table + lead detail page) — clicking one
// hands off to whatever the OS/browser has registered for tel: (Phone Link,
// a phone's own dialer app, etc.).
//
// Deliberately NOT intercepted/redirected through a hidden iframe — Chrome
// silently suppresses external-protocol handoffs (tel:/mailto:/custom
// schemes) triggered from a display:none iframe specifically, since that's
// a known malvertising pattern. A plain, real <a href="tel:..."> click
// doesn't have this problem. This handler only adds the visual "Calling…"
// modal (#callingModal, calls/partials/modals.blade.php) — the TSA's phone
// gives this page no way to know if/when the call is actually answered or
// ends, so it just stays up until dismissed (or until End Call is pressed,
// for a lead whose TSA has a dial-host configured).
window.openCallingModal = function (name, number, dialHost) {
    const modal = document.getElementById('callingModal');
    if (!modal) return;
    document.getElementById('callingModalName').textContent = name || 'this customer';
    document.getElementById('callingModalNumber').textContent = number || '';
    modal.dataset.dialHost = dialHost || '';

    // End Call/Mute only have anything to hit when this lead's TSA has a
    // dial-host configured — same MacroDroid macro family the auto-dial
    // itself uses, just extra Wi-Fi-triggered actions (Call Reject / mic
    // mute toggle) instead of Make Call.
    const endBtn = document.getElementById('endCallBtn');
    const muteBtn = document.getElementById('muteCallBtn');
    const hint = document.getElementById('callingModalHint');
    endBtn.classList.toggle('hidden', !dialHost);
    endBtn.classList.toggle('flex', !!dialHost);
    muteBtn.classList.toggle('hidden', !dialHost);
    muteBtn.classList.toggle('flex', !!dialHost);
    hint.textContent = dialHost
        ? 'Dialing from your phone via Wi-Fi — check your phone if nothing happens.'
        : 'Sent to your phone — check your phone if nothing happens.';

    // A new call always starts unmuted — reset the toggle rather than
    // carrying over whatever state the previous call in this modal left it in.
    muteBtn.dataset.muted = '0';
    document.getElementById('muteCallBtnLabel').textContent = 'Mute';

    showModal(modal);
};

window.closeCallingModal = function () {
    hideModal(document.getElementById('callingModal'));
};

// End Call — same Wi-Fi-direct-to-phone approach as auto-dial (see the click
// handler below): hits a second MacroDroid macro on the TSA's own phone
// (Trigger: HTTP Server Request path "hangup" → Action: Call Reject, which
// MacroDroid's own docs confirm also ends an already-in-progress call, not
// just a still-ringing incoming one).
window.endCall = function () {
    const modal = document.getElementById('callingModal');
    const dialHost = modal?.dataset.dialHost;
    if (!dialHost) return;

    fetch(`http://${dialHost}/hangup`, { mode: 'no-cors' }).catch(() => {});
    window.closeCallingModal();
};

// Mute/unmute — same Wi-Fi-direct-to-phone approach as End Call, hitting a
// third MacroDroid macro on the TSA's own phone (Trigger: HTTP Server
// Request path "mute" → Action: a mic-mute toggle — see the "Mute" setup
// steps on TSA Management for wiring this one up). Toggles locally rather
// than polling the phone for real mute state, since the HTTP trigger has no
// response payload to read state back from — this button's label reflects
// what WAS sent, not a confirmed device state.
window.toggleMute = function () {
    const modal = document.getElementById('callingModal');
    const dialHost = modal?.dataset.dialHost;
    if (!dialHost) return;

    const btn = document.getElementById('muteCallBtn');
    const nowMuted = btn.dataset.muted !== '1';

    fetch(`http://${dialHost}/mute`, { mode: 'no-cors' }).catch(() => {});

    btn.dataset.muted = nowMuted ? '1' : '0';
    document.getElementById('muteCallBtnLabel').textContent = nowMuted ? 'Unmute' : 'Mute';
};

document.addEventListener('click', (e) => {
    if (e.target.id === 'callingModal') window.closeCallingModal(); // backdrop click

    const link = e.target.closest('a[href^="tel:"]');
    if (!link) return;

    window.openCallingModal(link.dataset.name, link.textContent.trim(), link.dataset.dialHost);

    // A click here should show up on TSA Logs, not just real status changes
    // — see LeadController::logCallClick()'s own doc comment for why this
    // is a LeadActivity, not a TsaStatusLog row. Fire-and-forget, same
    // reasoning as the auto-dial request below: nothing in this click
    // depends on the response.
    if (link.dataset.leadId) {
        fetch(`/calls/leads/${link.dataset.leadId}/call-click`, {
            method: 'POST',
            headers: { 'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || '' },
        }).catch(() => {});
    }

    // Auto-dial (dial-host set on this lead's TSA, Call Rotation → Dialer
    // address) — neither Windows Phone Link nor macOS Continuity Calls can
    // bridge a browser to an Android phone, so this hits a second MacroDroid
    // macro on the TSA's own phone directly over the local Wi-Fi network
    // instead, which places the call itself. Fire-and-forget: no-cors since
    // MacroDroid's HTTP server sends no CORS headers and the response isn't
    // needed anyway, just the side effect of it receiving the request.
    if (link.dataset.dialHost) {
        e.preventDefault(); // this path actually dials — a tel: handoff on top would be redundant/confusing
        const url = `http://${link.dataset.dialHost}/dial?number=${encodeURIComponent(link.dataset.dialNumber || '')}`;
        fetch(url, { mode: 'no-cors' }).catch(() => {});
        return;
    }

    // No dial-host configured for this TSA yet — fall back to a plain tel:
    // handoff (no preventDefault, so the real click reaches the browser
    // natively). Best-effort only: whether anything happens depends on
    // whatever's registered for tel: on whoever's viewing this page.
});

// Sidebar Leads group — a sibling button next to the Leads link, not nested
// inside it (a <button> can't legally nest inside an <a>), so this only
// toggles the Overdue/Callbacks submenu without navigating. Animated via a
// grid-template-rows 0fr/1fr swap (see leadsNavSubmenu in calls.blade.php)
// instead of the `hidden` class, so open/close transitions smoothly rather
// than snapping — plus an opacity fade on the inner content so text doesn't
// pop in/out mid-collapse.
function setLeadsNavOpen(open) {
    const submenu = document.getElementById('leadsNavSubmenu');
    const inner = document.getElementById('leadsNavSubmenuInner');
    const chevron = document.getElementById('leadsNavChevron');
    if (!submenu) return;
    submenu.classList.toggle('grid-rows-[1fr]', open);
    submenu.classList.toggle('grid-rows-[0fr]', !open);
    inner?.classList.toggle('opacity-100', open);
    inner?.classList.toggle('opacity-0', !open);
    chevron?.classList.toggle('rotate-180', open);
}

window.toggleLeadsNav = function (e) {
    e.stopPropagation();
    const submenu = document.getElementById('leadsNavSubmenu');
    if (!submenu) return;
    setLeadsNavOpen(submenu.classList.contains('grid-rows-[0fr]'));
};

// Clicking any other sidebar nav link while the Leads submenu is open
// collapses it with the same animation first, instead of it just vanishing
// under the new page. Excludes the Leads link itself (#leadsNavLink) — going
// to the Leads index is still part of this group, so it should stay open —
// and anything inside the submenu (navigating within it shouldn't self-close).
document.querySelectorAll('aside nav a').forEach((link) => {
    if (link.id === 'leadsNavLink' || link.closest('#leadsNavSubmenu')) return;
    link.addEventListener('click', () => setLeadsNavOpen(false));
});

// Clicking the "Leads" label itself is a real link (not just the arrow), so
// it used to jump straight to the Leads page with the submenu snapping open
// on the new page — no animation, since there's nothing to animate across a
// full reload. Explicit request (2026-08-14): play the open animation first,
// then navigate, so the label feels the same as the arrow instead of an
// abrupt page swap. Skipped for modified clicks (new tab/window, middle
// click) so that standard browser behavior still works, and skipped
// entirely if the submenu is already open — nothing to animate, navigate
// immediately like a normal link.
document.getElementById('leadsNavLink')?.addEventListener('click', function (e) {
    if (e.button !== 0 || e.metaKey || e.ctrlKey || e.shiftKey || e.altKey) return;
    const submenu = document.getElementById('leadsNavSubmenu');
    if (!submenu || !submenu.classList.contains('grid-rows-[0fr]')) return;
    e.preventDefault();
    setLeadsNavOpen(true);
    setTimeout(() => { window.location.href = this.href; }, 220);
});

document.addEventListener('keydown', (e) => {
    if (e.key === 'Escape') window.closeCallingModal();
});

// Call Analytics charts (calls/analytics.blade.php) — data comes from a JSON
// script tag (#analyticsChartData), not inline JS, so TSA names never need
// escaping into a JS string literal. Confirm/No-Answer Rate deliberately use
// separate green/red semantic colors instead of the brand palette, since
// "good outcome" vs "needs follow-up" is a different kind of meaning than
// the brand accent and shouldn't share it. Guarded the same way every other
// page-specific block in this file is — canvases only exist on the Call
// Analytics page, so this is a no-op everywhere else.
(function initAnalyticsCharts() {
    const dataEl = document.getElementById('analyticsChartData');
    if (!dataEl) return;

    const data = JSON.parse(dataEl.textContent);
    const emptyState = document.getElementById('analyticsChartsEmpty');
    const chartsWrap = document.getElementById('analyticsChartsWrap');

    const isDark = () => document.documentElement.classList.contains('dark');
    const tooltipBase = {
        backgroundColor: '#201C12',
        titleColor: '#FFFFFF',
        bodyColor: '#FEF9E7',
        padding: 10,
        cornerRadius: 8,
        titleFont: { family: 'Fira Sans', weight: '600' },
        bodyFont: { family: 'Fira Code' },
    };
    const gridBase = { color: isDark() ? '#334155' : '#E7DFC9', drawTicks: false };
    const tickColor = isDark() ? '#94A3B8' : '#756B52';
    const tickFont = { family: 'Fira Code', size: 11 };

    // Formats seconds as "3m 42s" for tooltips — matches the AHT table's own
    // format (see analytics.blade.php) so a chart and its table never
    // disagree about how a duration reads.
    const formatSeconds = (s) => `${Math.floor(s / 60)}m ${s % 60}s`;

    // Separate from the 3 charts below (and NOT gated on data.hasAnyCalls, a
    // completely different data source — Lead status vs real CallEvent
    // durations). Used to be exposed as window.buildAhtCharts and called
    // lazily on the AHT tab's first reveal, since a canvas built while its
    // container is display:none measures 0x0 and draws blank — now that both
    // sections render together with nothing ever hidden (explicit request,
    // 2026-08-19: no more tab switcher), it just runs immediately below.
    const buildAhtCharts = function () {
        if (!data.hasAnyAht) return;

        const byTsaCanvas = document.getElementById('chartAhtByTsa');
        if (byTsaCanvas) {
            const withAht = data.labels
                .map((label, i) => ({ label, seconds: data.ahtSeconds[i] }))
                .filter((r) => r.seconds !== null);

            new Chart(byTsaCanvas, {
                type: 'bar',
                data: {
                    labels: withAht.map((r) => r.label),
                    datasets: [{ data: withAht.map((r) => r.seconds), backgroundColor: '#CA8A04', borderRadius: 4, maxBarThickness: 28 }],
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: { display: false },
                        tooltip: { ...tooltipBase, callbacks: { label: (ctx) => `Avg: ${formatSeconds(ctx.raw)}` } },
                    },
                    scales: {
                        x: { grid: { display: false }, ticks: { ...tickFont, color: tickColor } },
                        y: { beginAtZero: true, grid: gridBase, ticks: { ...tickFont, color: tickColor, callback: (v) => formatSeconds(v) } },
                    },
                },
            });
        }

        const trendCanvas = document.getElementById('chartAhtTrend');
        if (trendCanvas) {
            new Chart(trendCanvas, {
                type: 'line',
                data: {
                    labels: data.ahtTrendLabels,
                    datasets: [{
                        data: data.ahtTrendSeconds,
                        borderColor: '#CA8A04',
                        backgroundColor: 'rgba(202,138,4,0.12)',
                        fill: true,
                        tension: 0.3,
                        pointRadius: 3,
                        pointBackgroundColor: '#CA8A04',
                    }],
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: { display: false },
                        tooltip: { ...tooltipBase, callbacks: { label: (ctx) => `Avg: ${formatSeconds(ctx.raw)}` } },
                    },
                    scales: {
                        x: { grid: { display: false }, ticks: { ...tickFont, color: tickColor } },
                        y: { beginAtZero: true, grid: gridBase, ticks: { ...tickFont, color: tickColor, callback: (v) => formatSeconds(v) } },
                    },
                },
            });
        }
    };
    buildAhtCharts();

    // No TSA has a single logged call in this range — an empty/all-zero bar
    // chart reads as broken, not as "no data yet" (empty-data-state
    // guideline). Show a plain message instead of three blank axis frames.
    if (!data.hasAnyCalls) {
        emptyState?.classList.remove('hidden');
        chartsWrap?.classList.add('hidden');
        return;
    }

    // Chart 1 — Call Volume & Coverage: Total Leads (capacity, muted) vs
    // Called (actual work done, brand primary) side by side per TSA. The
    // gap between the two bars for a TSA IS the insight (uncalled backlog),
    // which is why these are grouped, not stacked.
    const volumeCanvas = document.getElementById('chartCallVolume');
    if (volumeCanvas) {
        new Chart(volumeCanvas, {
            type: 'bar',
            data: {
                labels: data.labels,
                datasets: [
                    { label: 'Total Leads', data: data.total, backgroundColor: isDark() ? '#475569' : '#D8CCA4', borderRadius: 4, maxBarThickness: 28 },
                    { label: 'Called', data: data.called, backgroundColor: '#CA8A04', borderRadius: 4, maxBarThickness: 28 },
                ],
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: { position: 'top', align: 'end', labels: { font: { family: 'Fira Sans', size: 12 }, color: tickColor, boxWidth: 12, boxHeight: 12 } },
                    tooltip: tooltipBase,
                },
                scales: {
                    x: { grid: { display: false }, ticks: { ...tickFont, color: tickColor } },
                    y: { beginAtZero: true, grid: gridBase, ticks: { ...tickFont, color: tickColor, precision: 0 } },
                },
            },
        });
    }

    // Chart 2 — Outcome Quality: Confirm Rate vs No-Answer Rate, both as %.
    // Semantic green/red, not the brand palette. Y axis pinned 0-100 (a
    // rate, not an open-ended count) so bar heights are comparable across
    // TSAs and across a page reload with different data.
    const qualityCanvas = document.getElementById('chartOutcomeQuality');
    if (qualityCanvas) {
        new Chart(qualityCanvas, {
            type: 'bar',
            data: {
                labels: data.labels,
                datasets: [
                    { label: 'Confirm Rate', data: data.confirmRate, backgroundColor: '#16A34A', borderRadius: 4, maxBarThickness: 28 },
                    { label: 'No-Answer Rate', data: data.noAnswerRate, backgroundColor: '#DC2626', borderRadius: 4, maxBarThickness: 28 },
                ],
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: { position: 'top', align: 'end', labels: { font: { family: 'Fira Sans', size: 12 }, color: tickColor, boxWidth: 12, boxHeight: 12 } },
                    tooltip: { ...tooltipBase, callbacks: { label: (ctx) => `${ctx.dataset.label}: ${ctx.raw ?? 0}%` } },
                },
                scales: {
                    x: { grid: { display: false }, ticks: { ...tickFont, color: tickColor } },
                    y: { beginAtZero: true, max: 100, grid: gridBase, ticks: { ...tickFont, color: tickColor, callback: (v) => `${v}%` } },
                },
            },
        });
    }

    // Chart 3 — Avg Response Time: horizontal, sorted fastest-first (lower
    // is better here, unlike the other two charts where taller = better) —
    // reading top-to-bottom as a ranking only works if the best performer is
    // actually on top, so this sorts its own copy of the data rather than
    // reusing $rows' sort_order, which orders by roster position, not speed.
    const responseCanvas = document.getElementById('chartResponseTime');
    if (responseCanvas) {
        const withTimes = data.labels
            .map((label, i) => ({ label, mins: data.avgResponseMins[i] }))
            .filter((r) => r.mins !== null)
            .sort((a, b) => a.mins - b.mins);

        new Chart(responseCanvas, {
            type: 'bar',
            data: {
                labels: withTimes.map((r) => r.label),
                datasets: [{ data: withTimes.map((r) => r.mins), backgroundColor: '#CA8A04', borderRadius: 4, maxBarThickness: 24 }],
            },
            options: {
                indexAxis: 'y',
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: { display: false },
                    tooltip: { ...tooltipBase, callbacks: { label: (ctx) => `${ctx.raw} min avg response` } },
                },
                scales: {
                    x: { beginAtZero: true, grid: gridBase, ticks: { ...tickFont, color: tickColor, callback: (v) => `${v}m` } },
                    y: { grid: { display: false }, ticks: { ...tickFont, color: tickColor } },
                },
            },
        });
    }
})();

// Dashboard overview charts (calls/dashboard.blade.php) — same JSON-script-
// tag + isDark()-aware palette convention as initAnalyticsCharts() above,
// but scoped to the KPI numbers already shown in the 5 cards above them
// (Total Leads/Total Catered Leads/AHT/Unproductive Time) rather than
// duplicating Analytics' own per-TSA breakdown, which stays the one place
// for that. Two independent empty states: the bar/donut pair reflects the
// picked date range and can legitimately be all-zero (a quiet day), while
// the AHT/Unproductive trend line is its own always-on 7-day window.
(function initDashboardCharts() {
    const dataEl = document.getElementById('dashboardChartData');
    if (!dataEl) return;

    const data = JSON.parse(dataEl.textContent);
    const isDark = () => document.documentElement.classList.contains('dark');

    const tooltipBase = {
        backgroundColor: '#201C12',
        titleColor: '#FFFFFF',
        bodyColor: '#FEF9E7',
        padding: 10,
        cornerRadius: 8,
        titleFont: { family: 'Fira Sans', weight: '600' },
        bodyFont: { family: 'Fira Code' },
    };
    const gridBase = { color: isDark() ? '#334155' : '#E7DFC9', drawTicks: false };
    const tickColor = isDark() ? '#94A3B8' : '#756B52';
    const tickFont = { family: 'Fira Code', size: 11 };

    // "6m 42s" for AHT tooltips/ticks — matches the Analytics AHT table's
    // own format (see calls/analytics.blade.php) so this chart and that
    // table never disagree about how a duration reads.
    const formatSeconds = (s) => `${Math.floor(s / 60)}m ${s % 60}s`;
    const formatMinutes = (m) => `${Math.floor(m / 60)}h ${Math.round(m % 60)}m`;

    const overviewEmpty = document.getElementById('dashboardOverviewEmpty');
    const overviewWrap = document.getElementById('dashboardOverviewWrap');
    if (!data.hasOverviewData) {
        overviewEmpty?.classList.remove('hidden');
        overviewWrap?.classList.add('hidden');
    } else {
        const barCanvas = document.getElementById('chartLeadsOverview');
        if (barCanvas) {
            new Chart(barCanvas, {
                type: 'bar',
                data: {
                    labels: data.leadsOverview.labels,
                    datasets: [{
                        data: [data.leadsOverview.total, data.leadsOverview.catered],
                        backgroundColor: ['#EAB308', '#16A34A'],
                        borderRadius: 6,
                        maxBarThickness: 56,
                    }],
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: { legend: { display: false }, tooltip: tooltipBase },
                    scales: {
                        x: { grid: { display: false }, ticks: { ...tickFont, color: tickColor } },
                        y: { beginAtZero: true, grid: gridBase, ticks: { ...tickFont, color: tickColor, precision: 0 } },
                    },
                },
            });
        }

        // Catered Leads Rate — catered vs. missed (total - catered), with
        // the %age itself rendered server-side as an absolutely-positioned
        // overlay in the middle of the donut (calls/dashboard.blade.php),
        // not a Chart.js plugin — Chart.js has no built-in center-text
        // support, and a plain positioned <span> is simpler than a custom
        // plugin for one static number that doesn't need to redraw.
        const donutCanvas = document.getElementById('chartLeadsSplit');
        if (donutCanvas) {
            const missed = Math.max(0, data.leadsOverview.total - data.leadsOverview.catered);
            new Chart(donutCanvas, {
                type: 'doughnut',
                data: {
                    labels: ['Catered Leads', 'Missed Leads'],
                    datasets: [{
                        data: [data.leadsOverview.catered, missed],
                        backgroundColor: ['#16A34A', '#EAB308'],
                        borderWidth: 0,
                        hoverOffset: 4,
                    }],
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    cutout: '72%',
                    plugins: {
                        legend: { position: 'bottom', labels: { font: { family: 'Fira Sans', size: 11 }, color: tickColor, boxWidth: 10, boxHeight: 10, padding: 12 } },
                        tooltip: tooltipBase,
                    },
                },
            });
        }
    }

    const trendEmpty = document.getElementById('dashboardTrendEmpty');
    const trendWrap = document.getElementById('dashboardTrendWrap');
    if (!data.hasTrendData) {
        trendEmpty?.classList.remove('hidden');
        trendWrap?.classList.add('hidden');
        return;
    }

    // AHT & Unproductive Time trend — dual y-axes, not a shared one: AHT
    // runs in seconds (a single call, usually single-digit minutes) while
    // Unproductive Time runs in minutes across a whole shift (can be
    // hours) — sharing one axis would flatten the AHT line to near-zero
    // next to it. spanGaps bridges a day with no scoped TSAs working (see
    // DashboardController::index()'s own comment) rather than breaking the
    // line at that point.
    const trendCanvas = document.getElementById('chartTrend');
    if (trendCanvas) {
        new Chart(trendCanvas, {
            type: 'line',
            data: {
                labels: data.trend.labels,
                datasets: [
                    {
                        label: 'AHT', data: data.trend.ahtSeconds, borderColor: '#CA8A04', backgroundColor: 'rgba(202,138,4,0.12)',
                        fill: true, tension: 0.35, pointRadius: 3, pointBackgroundColor: '#CA8A04', pointBorderColor: '#fff', pointBorderWidth: 1.5,
                        yAxisID: 'yAht', spanGaps: true,
                    },
                    {
                        label: 'Unproductive Time', data: data.trend.unproductive, borderColor: '#DC2626', backgroundColor: 'rgba(220,38,38,0.10)',
                        fill: true, tension: 0.35, pointRadius: 3, pointBackgroundColor: '#DC2626', pointBorderColor: '#fff', pointBorderWidth: 1.5,
                        yAxisID: 'yUnproductive', spanGaps: true,
                    },
                ],
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: { position: 'top', align: 'end', labels: { font: { family: 'Fira Sans', size: 12 }, color: tickColor, boxWidth: 12, boxHeight: 12 } },
                    tooltip: {
                        ...tooltipBase,
                        callbacks: {
                            label: (ctx) => ctx.dataset.yAxisID === 'yAht'
                                ? `AHT: ${formatSeconds(ctx.raw)}`
                                : `Unproductive: ${formatMinutes(ctx.raw)}`,
                        },
                    },
                },
                scales: {
                    x: { grid: { display: false }, ticks: { ...tickFont, color: tickColor } },
                    yAht: {
                        beginAtZero: true, position: 'left', grid: gridBase,
                        ticks: { ...tickFont, color: tickColor, callback: (v) => formatSeconds(v) },
                        title: { display: true, text: 'AHT', font: { family: 'Fira Sans', size: 10 }, color: tickColor },
                    },
                    yUnproductive: {
                        beginAtZero: true, position: 'right', grid: { display: false },
                        ticks: { ...tickFont, color: tickColor, callback: (v) => formatMinutes(v) },
                        title: { display: true, text: 'Unproductive Time', font: { family: 'Fira Sans', size: 10 }, color: tickColor },
                    },
                },
            },
        });
    }
})();
