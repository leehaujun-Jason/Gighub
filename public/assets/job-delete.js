'use strict';

for (const disclosure of document.querySelectorAll('[data-job-delete-fallback]')) {
    const dialog = document.createElement('dialog');
    if (typeof dialog.showModal !== 'function') continue;
    const trigger = document.createElement('button');
    trigger.type = 'button';
    const summary = disclosure.querySelector('summary');
    trigger.className = summary.className;
    trigger.title = summary.title;
    trigger.setAttribute('aria-label', summary.getAttribute('aria-label'));
    trigger.setAttribute('aria-haspopup', 'dialog');
    trigger.append(...summary.childNodes);
    dialog.className = 'gh-profile-card gh-avatar-dialog';
    dialog.setAttribute('aria-label', 'Confirm job removal');
    dialog.append(disclosure.querySelector('[data-job-confirm]'));
    const cancel = dialog.querySelector('[data-job-cancel]');
    cancel.hidden = false;
    cancel.addEventListener('click', () => dialog.close());
    trigger.addEventListener('click', () => dialog.showModal());
    dialog.addEventListener('close', () => trigger.focus());
    disclosure.replaceWith(trigger, dialog);
}
