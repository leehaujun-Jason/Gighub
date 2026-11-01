'use strict';
(() => {
    const input = document.getElementById('password');
    const button = document.getElementById('password-visibility');
    const slash = document.getElementById('visibility-slash');
    if (input && button) {
        button.hidden = false;
        button.addEventListener('click', () => {
            const visible = input.type === 'password';
            input.type = visible ? 'text' : 'password';
            button.setAttribute('aria-label', visible ? 'Hide password' : 'Show password');
            button.setAttribute('aria-pressed', String(visible));
            if (slash) { slash.toggleAttribute('hidden', !visible); }
        });
    }
    const summary = document.getElementById('error-summary');
    if (summary) { summary.focus(); }
})();
