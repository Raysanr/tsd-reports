{{-- Picking a Team fills "Reports to" with that team's head, unless the
     user already chose someone else by hand. Works on any form that has a
     select[data-team-select] (options carry data-head) and a select[data-manager-select]. --}}
@once
@push('scripts')
<script>
(function () {
    const headOf = (sel) => sel.selectedOptions[0]?.dataset.head || '';
    const remember = (form) => {
        const team = form.querySelector('[data-team-select]');
        if (team) form.dataset.autoHead = headOf(team);
    };
    // the opener may say who is being edited (data-self-id) so nobody is set to report to themselves
    document.addEventListener('click', (e) => {
        const opener = e.target.closest('[data-modal-open]');
        const form = opener && document.getElementById(opener.dataset.modalOpen)?.querySelector('form');
        if (form) form.dataset.selfId = opener.dataset.selfId || '';
    }, true);
    document.addEventListener('arc:filled', (e) => remember(e.target), true);
    document.addEventListener('focusin', (e) => { const f = e.target.closest('form'); if (f && f.dataset.autoHead === undefined) remember(f); });
    document.addEventListener('change', (e) => {
        const team = e.target.closest('[data-team-select]');
        if (!team) return;
        const form = team.form, mgr = form.querySelector('[data-manager-select]');
        if (!mgr) return;
        const next = headOf(team), self = form.dataset.selfId || '';
        // only replace an empty value or the value we filled in ourselves
        if (mgr.value === '' || mgr.value === (form.dataset.autoHead || '')) mgr.value = next && next !== self ? next : '';
        form.dataset.autoHead = next;
    });
})();
</script>
@endpush
@endonce
