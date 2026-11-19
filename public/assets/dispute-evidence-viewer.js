'use strict';
document.querySelectorAll('[data-evidence-gallery]').forEach(gallery => {
    if (gallery.dataset.viewerReady) return;
    gallery.dataset.viewerReady = 'true';
    const dialog = gallery.nextElementSibling;
    if (!dialog || !dialog.matches('[data-evidence-viewer]')) return;
    const image = dialog.querySelector('[data-evidence-viewer-image]');
    const name = dialog.querySelector('[data-evidence-viewer-name]');
    const error = dialog.querySelector('[data-evidence-viewer-error]');
    let trigger;
    gallery.querySelectorAll('a').forEach(link => link.addEventListener('click', event => {
        event.preventDefault();
        trigger = link;
        name.textContent = link.querySelector('img').alt;
        image.alt = name.textContent;
        image.hidden = false;
        error.hidden = true;
        image.src = link.href;
        dialog.showModal();
    }));
    image.addEventListener('error', () => { image.hidden = true; error.hidden = false; });
    dialog.querySelector('[data-evidence-viewer-close]').addEventListener('click', () => dialog.close());
    dialog.addEventListener('click', event => { if (event.target === dialog) dialog.close(); });
    dialog.addEventListener('close', () => {
        image.removeAttribute('src');
        if (trigger) trigger.focus();
    });
});
