'use strict';

const role = document.getElementById('role');
const optionalProfile = document.getElementById('optional-profile');
const clientProfile = document.getElementById('client-profile');
const companyName = document.getElementById('company_name');
const fallback = document.getElementById('role-update');

if (role && optionalProfile && clientProfile && companyName && fallback) {
    const updateRole = () => {
        const validRole = role.value === 'client' || role.value === 'freelancer';
        optionalProfile.hidden = !validRole;
        optionalProfile.disabled = !validRole;
        clientProfile.hidden = role.value !== 'client';
        clientProfile.disabled = role.value !== 'client';
        if (role.value !== 'client') companyName.value = '';
    };
    role.addEventListener('change', updateRole);
    updateRole();
    fallback.type = 'button';
    fallback.disabled = true;
    fallback.hidden = true;
}

const form = document.getElementById('registration-form');
const password = document.getElementById('password');
const confirmation = document.getElementById('password_confirmation');
if (form && password && confirmation) {
    const policy = JSON.parse(document.querySelector('[data-password-policy]').getAttribute('data-password-policy'));
    const help = document.getElementById('password-help');
    const confirmationHelp = document.getElementById('confirmation-help');
    let passwordStarted = false;
    let confirmationTouched = false;
    const passwordError = (value) => {
        const points = Array.from(value);
        const messages = policy.messages;
        if (points.some(point => point === '\0' || (point.codePointAt(0) >= 0xD800 && point.codePointAt(0) <= 0xDFFF))) return messages.encoding;
        if (points.length < policy.min) return messages.min;
        if (points.length > policy.max) return messages.max;
        if (new TextEncoder().encode(value).length > policy.max_bytes) return messages.bytes;
        if (/^[ \t\n\r\v]*$/.test(value)) return messages.blank;
        if (points.length > 1 && points.every(point => point === points[0])) return messages.repeated;
        if (policy.common.includes(value.replace(/[A-Z]/g, letter => letter.toLowerCase()))) return messages.common;
        return '';
    };
    const clearServerError = (field) => {
        const error = document.getElementById(field.id + '-error');
        if (error) error.hidden = true;
        field.setAttribute('aria-describedby', field === password ? 'password-help' : 'confirmation-help');
    };
    const feedback = (field, target, message, error) => {
        if (target.textContent !== message) target.textContent = message;
        target.setAttribute('data-state', message ? (error ? 'error' : 'success') : '');
        if (error) field.setAttribute('aria-invalid', 'true');
        else field.removeAttribute('aria-invalid');
    };
    const updatePassword = () => {
        clearServerError(password);
        const error = passwordError(password.value);
        password.setCustomValidity(error);
        feedback(password, help, passwordStarted ? (error || 'Basic checks passed. Checked again when you submit.') : '', passwordStarted && !!error);
    };
    const updateConfirmation = () => {
        if (!confirmationTouched) return;
        clearServerError(confirmation);
        const error = confirmation.value === '' ? policy.messages.confirm_empty
            : (confirmation.value !== password.value ? policy.messages.mismatch : '');
        confirmation.setCustomValidity(error);
        feedback(confirmation, confirmationHelp, error || 'Passwords match.', !!error);
    };
    form.addEventListener('submit', (event) => {
        passwordStarted = true;
        confirmationTouched = true;
        updatePassword();
        updateConfirmation();
        if (!form.reportValidity()) event.preventDefault();
    });
    // Validate at submission time, including autofill that emits no input event.
    // Without this enhancement native required/email checks and the server apply.
    form.noValidate = true;
    for (const event of ['input', 'change']) {
        password.addEventListener(event, () => {
            passwordStarted = true;
            updatePassword();
            updateConfirmation();
        });
        confirmation.addEventListener(event, updateConfirmation);
    }
    confirmation.addEventListener('blur', () => { confirmationTouched = true; updateConfirmation(); });
    // Leave server errors visible until editing; reserve guidance space to reduce jumps.
    if (!document.getElementById('password-error')) help.textContent = '';
}

for (const button of document.querySelectorAll('[data-password-toggle]')) {
    const field = document.getElementById(button.getAttribute('aria-controls'));
    if (!field) continue;
    button.addEventListener('click', () => {
        const show = field.type === 'password';
        field.type = show ? 'text' : 'password';
        const label = (show ? 'Hide' : 'Show') + (field.id === 'password' ? ' password' : ' password confirmation');
        button.setAttribute('aria-label', label);
        button.setAttribute('data-visible', String(show));
    });
    button.hidden = false;
}

// Focus a single summary after a rejected submission, then let links target fields.
document.getElementById('error-summary')?.focus();
