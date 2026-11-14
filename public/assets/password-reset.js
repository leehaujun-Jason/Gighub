'use strict';

const resetKey = document.getElementById('reset_key');
const fragment = /^#reset=([a-f0-9]{64})$/.exec(window.location.hash);
if (resetKey && fragment) {
    history.replaceState(null, '', window.location.pathname + window.location.search);
    resetKey.value = fragment[1];
    resetKey.type = 'hidden';
    document.getElementById('reset-key-field').hidden = true;
}
