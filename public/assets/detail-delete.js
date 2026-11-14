'use strict';

for (const link of document.querySelectorAll('[data-detail-delete]')) {
    const dialog = link.nextElementSibling;
    if (typeof dialog.showModal !== 'function') continue;
    link.addEventListener('click', event => {
        event.preventDefault();
        dialog.showModal();
    });
    dialog.querySelector('[data-detail-cancel]').addEventListener('click', () => dialog.close());
    dialog.addEventListener('close', () => link.focus());
}
