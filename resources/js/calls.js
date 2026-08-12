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
    Chart, BarController, BarElement, CategoryScale, LinearScale, Tooltip, Legend, Title,
} from 'chart.js';
Chart.register(BarController, BarElement, CategoryScale, LinearScale, Tooltip, Legend, Title);

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

function pollNotificationCounts() {
    fetch('/calls/api/notification-counts', { headers: { Accept: 'application/json' } })
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
function pollLeadsTable() {
    const container = document.getElementById('leads-table-container');
    if (!container) return;

    if (container.contains(document.activeElement) && document.activeElement !== document.body) {
        return;
    }

    fetch(container.dataset.pollUrl, { headers: { 'X-Table-Refresh': '1' } })
        .then((res) => (res.ok ? res.text() : null))
        .then((html) => {
            if (html === null) return;
            // Re-check focus: the fetch is async, so the user could have
            // clicked into a form while it was in flight.
            if (container.contains(document.activeElement) && document.activeElement !== document.body) {
                return;
            }
            container.innerHTML = html;
        })
        .catch(() => {});
}

if (document.getElementById('leads-table-container')) {
    setInterval(pollLeadsTable, 15000);
}

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

    modal.classList.remove('hidden');
    modal.classList.add('flex');
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
    const modal = document.getElementById('conversationModal');
    if (!modal) return;
    modal.classList.add('hidden');
    modal.classList.remove('flex');
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
    modal.classList.remove('hidden');
    modal.classList.add('flex');
    search.value = '';
    renderChipsInto(document.getElementById('outcomeTagModalChips'), getSelectedTags(picker));
    searchOutcomeTagModal('');
    search.focus();
};

window.closeOutcomeTagModal = function () {
    const modal = document.getElementById('outcomeTagModal');
    modal.classList.add('hidden');
    modal.classList.remove('flex');
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
    modal.classList.remove('hidden');
    modal.classList.add('flex');
    document.getElementById('upsellModalConfirm').classList.add('hidden');
    document.getElementById('upsellModalError').classList.add('hidden');
    search.value = '';
    document.getElementById('upsellModalResults').innerHTML =
        '<p class="text-slate-400 text-center text-xs font-mono py-8">Type a product name to search…</p>';
    search.focus();
};

window.closeUpsellModal = function () {
    const modal = document.getElementById('upsellModal');
    modal.classList.add('hidden');
    modal.classList.remove('flex');
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

    // End Call only has anything to hit when this lead's TSA has a dial-host
    // configured — same MacroDroid macro family the auto-dial itself uses,
    // just a second Wi-Fi-triggered action (Call Reject) instead of Make Call.
    const endBtn = document.getElementById('endCallBtn');
    const hint = document.getElementById('callingModalHint');
    endBtn.classList.toggle('hidden', !dialHost);
    endBtn.classList.toggle('flex', !!dialHost);
    hint.textContent = dialHost
        ? 'Dialing from your phone via Wi-Fi — check your phone if nothing happens.'
        : 'Sent to your phone — check your phone if nothing happens.';

    modal.classList.remove('hidden');
    modal.classList.add('flex');
};

window.closeCallingModal = function () {
    const modal = document.getElementById('callingModal');
    if (!modal) return;
    modal.classList.add('hidden');
    modal.classList.remove('flex');
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

// Sidebar Leads group (a future nav addition, see Phase 7 of the merge
// plan) — a sibling button next to the Leads link, not nested inside it (a
// <button> can't legally nest inside an <a>), so this only toggles the
// Overdue/Callbacks submenu without navigating. No-op today since
// #leadsNavSubmenu doesn't exist in tsd-reports' shared nav yet.
window.toggleLeadsNav = function (e) {
    e.stopPropagation();
    const submenu = document.getElementById('leadsNavSubmenu');
    const chevron = document.getElementById('leadsNavChevron');
    if (!submenu) return;
    submenu.classList.toggle('hidden');
    chevron?.classList.toggle('rotate-180');
};

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

    // No TSA has a single logged call in this range — an empty/all-zero bar
    // chart reads as broken, not as "no data yet" (empty-data-state
    // guideline). Show a plain message instead of three blank axis frames.
    if (!data.hasAnyCalls) {
        emptyState?.classList.remove('hidden');
        chartsWrap?.classList.add('hidden');
        return;
    }

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
