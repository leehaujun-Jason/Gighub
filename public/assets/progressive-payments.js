'use strict';
document.querySelectorAll('form[data-payment-confirm]').forEach(form => {
    form.addEventListener('submit', event => {
        if (event.defaultPrevented) return;
        if (form.dataset.paymentSubmitting || !window.confirm(form.dataset.paymentConfirm)) {
            event.preventDefault();
            return;
        }
        form.dataset.paymentSubmitting = 'true';
        form.querySelectorAll('[type="submit"]').forEach(button => { button.disabled = true; });
    });
});
