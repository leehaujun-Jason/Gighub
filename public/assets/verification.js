'use strict';
(() => {
    // Clear every fragment before interpreting it, including malformed links.
    const fragment = window.location.hash;
    let cleared = true;
    if (fragment) {
        try { window.history.replaceState(null, '', window.location.pathname + window.location.search); }
        catch (_) { cleared = false; }
    }
    const input = document.getElementById('verification-key');
    const status = document.getElementById('verification-link-status');
    if (fragment && input && status) {
        const match = cleared ? /^#verification=([1-9][0-9]{0,9})\.([0-9a-f]{64})$/.exec(fragment) : null;
        if (match && Number(match[1]) <= 4294967295) {
            input.value = match[1] + '.' + match[2];
            status.textContent = 'Your verification link is ready. Choose Confirm Verification to continue.';
        } else {
            status.textContent = 'This link could not be read. Paste the verification key below or request a new link.';
        }
    }
    const summary = document.getElementById('error-summary');
    if (summary) { summary.focus(); }
})();
