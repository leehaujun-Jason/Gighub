document.querySelectorAll('[data-resolution-open]').forEach((button) => {
    const dialog = document.getElementById(button.dataset.resolutionOpen);
    button.addEventListener('click', () => dialog.showModal());
    dialog.querySelectorAll('[data-resolution-close]').forEach((control) => {
        control.addEventListener('click', () => dialog.close());
    });
    dialog.addEventListener('close', () => button.focus());
    const form = dialog.querySelector('form');
    form.querySelector('textarea').dispatchEvent(new Event('input'));
    if (dialog.hasAttribute('data-resolution-reopen')) dialog.showModal();
    form.addEventListener('submit', (event) => {
        if (event.defaultPrevented) return;
        if (form.dataset.submitting) {
            event.preventDefault();
            return;
        }
        form.dataset.submitting = 'true';
        form.querySelector('[type="submit"]').disabled = true;
    });
});
