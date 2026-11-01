 'use strict';
(() => {
    const summary = document.getElementById('job-errors');
    if (summary) summary.focus();
    // The first submit button and the server's missing-intent default both save a draft.
    // No key interception: textarea newlines and native keyboard submission stay intact.
})();
