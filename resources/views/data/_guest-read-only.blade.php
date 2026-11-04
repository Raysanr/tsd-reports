{{-- Guests are view-only (EnsureGuestModuleAccess refuses their saves on the
     server); this makes the Data Management pages look it (2026-11-04). Edit
     boxes become plain text and write controls disappear. Filters, snapshot
     and Tally Checker stay. Covers re-rendered sections via the observer. --}}
<style>
    [data-pj-lock-toggle], [data-ei-lock-toggle], [data-dsppr-lock-toggle], [data-tsr-lock-toggle],
    [data-add-custom-row], [data-remove-custom-row], [data-ungroup], [data-ei-label-edit],
    #pjAddProjectionBtn, .pj-row-handle, form[method="post" i]:not([action$="/logout"]) { display: none !important; }
    [data-guest-static] {
        border-color: transparent !important; background: transparent !important; box-shadow: none !important;
        outline: none !important; pointer-events: none; opacity: 1 !important; color: inherit; appearance: none;
    }
</style>
<script>
(() => {
    const main = document.querySelector('main');
    const keep = 'form[method="get" i], .month-picker-field, #eiTsaNameFilter';
    const lock = () => {
        main.querySelectorAll('input:not([type="hidden"]):not([data-guest-static]), select:not([data-guest-static]), textarea:not([data-guest-static])').forEach((el) => {
            if (el.closest(keep)) return;
            el.readOnly = true;
            if (el.tagName === 'SELECT' || el.type === 'checkbox' || el.type === 'radio') el.disabled = true;
            el.tabIndex = -1;
            el.setAttribute('data-guest-static', '');
        });
        main.querySelectorAll('[draggable="true"]').forEach((el) => el.setAttribute('draggable', 'false'));
    };
    lock();
    new MutationObserver(lock).observe(main, { childList: true, subtree: true });
    document.addEventListener('dragstart', (e) => e.preventDefault(), true);
})();
</script>
