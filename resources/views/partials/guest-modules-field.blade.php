{{-- Guest module picker for the Add/Edit user modal (2026-11-04). Shown only
     for the Guest role. The page's own JS calls
     window.syncGuestModules(modules) when it fills the form for editing. --}}
<div id="guestModulesField" hidden>
    <label style="display:block;font-size:12px;font-weight:600;margin-bottom:6px;">Modules this guest can view</label>
    @foreach(\App\Models\User::MODULES as $key => $moduleLabel)
    <label style="display:flex;align-items:center;gap:8px;font-size:13px;padding:3px 0;cursor:pointer;">
        <input type="checkbox" name="modules[]" value="{{ $key }}" class="guest-module-checkbox">
        {{ $moduleLabel }}
    </label>
    @endforeach
    <p style="font-size:11px;opacity:.7;margin-top:4px;">Guests can only view. They can't edit or save anything.</p>
</div>
<script>
(() => {
    const field = document.getElementById('guestModulesField');
    const role = document.getElementById('userRoleSelect');
    const boxes = [...field.querySelectorAll('.guest-module-checkbox')];
    const validate = () => {
        const missing = role.value === 'guest' && !boxes.some((b) => b.checked);
        boxes[0].setCustomValidity(missing ? 'Pick at least one module.' : '');
    };
    const refresh = () => {
        field.hidden = role.value !== 'guest';
        boxes.forEach((b) => { b.disabled = field.hidden; });
        validate();
    };
    window.syncGuestModules = (modules = []) => {
        boxes.forEach((b) => { b.checked = modules.includes(b.value); });
        refresh();
    };
    role.addEventListener('change', refresh);
    boxes.forEach((b) => b.addEventListener('change', validate));
    refresh();
})();
</script>
