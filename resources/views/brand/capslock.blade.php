{{-- Caps Lock warning for every password field, including fields Livewire adds later (event delegation). --}}
<script>
(() => {
    if (window.__wqCaps) return;
    window.__wqCaps = true;
    const NOTE = 'زر الأحرف الكبيرة (Caps Lock) مفعّل — قد تُكتب كلمة المرور خطأً';
    const holder = (input) => input.closest('.fi-input-wrp') || input.parentElement;
    const toggle = (e) => {
        const input = e.target;
        if (!(input instanceof HTMLInputElement) || !e.getModifierState) return;
        if (input.type !== 'password' && !input.dataset.wqWasPassword) return;
        input.dataset.wqWasPassword = '1';   // keep warning when the eye button reveals the password
        const box = holder(input);
        let note = box.parentElement.querySelector(':scope > .wq-caps');
        const on = e.getModifierState('CapsLock');
        if (on && !note) {
            note = document.createElement('p');
            note.className = 'wq-caps';
            note.setAttribute('role', 'status');
            note.textContent = NOTE;
            box.insertAdjacentElement('afterend', note);
        } else if (!on && note) {
            note.remove();
        }
    };
    ['keydown', 'keyup'].forEach((t) => document.addEventListener(t, toggle, true));
    document.addEventListener('focusout', (e) => {
        if (e.target instanceof HTMLInputElement && e.target.dataset.wqWasPassword) {
            holder(e.target).parentElement.querySelector(':scope > .wq-caps')?.remove();
        }
    }, true);
})();
</script>
