'use strict';
// Dependency-free behaviour tests; actual browser checks complement this DOM fixture.
const { readFileSync } = require('node:fs');
const { resolve } = require('node:path');
const { runInNewContext } = require('node:vm');
const assert = require('node:assert/strict');
const { spawnSync } = require('node:child_process');
const { existsSync } = require('node:fs');
const php = existsSync('C:/xampp/php/php.exe') ? 'C:/xampp/php/php.exe' : 'php';
const validator = resolve(__dirname, '../app/Services/RegistrationValidator.php').replaceAll('\\', '/');
const exported = spawnSync(php, [], { input: `<?php require ${JSON.stringify(validator)}; echo json_encode(GigHub\\Services\\RegistrationValidator::passwordPolicy());`, encoding: 'utf8' });
assert.equal(exported.status, 0, 'PHP public password policy available');
const policy = JSON.parse(exported.stdout);
let checks = 0;
const check = (value, label) => { assert.ok(value, label); checks++; };
class Element {
    constructor(id, attrs = {}) {
        Object.assign(this, { id, value: '', type: '', hidden: false, disabled: false, listeners: {}, attrs, validity: '' });
    }
    addEventListener(name, fn) { this.listeners[name] = fn; }
    setCustomValidity(message) { this.validity = message; }
    getAttribute(name) { return this.attrs[name]; }
    setAttribute(name, value) { this.attrs[name] = value; }
    removeAttribute(name) { delete this.attrs[name]; }
    focus() { this.focused = true; }
}
const ids = ['role','optional-profile','client-profile','company_name','role-update','registration-form','password','password_confirmation','error-summary','password-help','confirmation-help'];
const elements = Object.fromEntries(ids.map(id => [id, new Element(id)]));
const toggles = ['password','password_confirmation'].map(id => new Element('toggle-' + id, { 'aria-controls': id }));
elements.password.type = elements.password_confirmation.type = 'password';
elements.role.value = 'client';
const form = elements['registration-form'];
form.reportValidity = () => !elements.password.validity && !elements.password_confirmation.validity;
runInNewContext(readFileSync(resolve(__dirname, '../public/assets/registration.js'), 'utf8'), {
    document: { getElementById: id => elements[id], querySelectorAll: () => toggles,
        querySelector: () => new Element('policy', { 'data-password-policy': JSON.stringify(policy) }) }, TextEncoder,
});
check(elements['password-help'].textContent === '' && !elements['confirmation-help'].textContent, 'initial guidance quiet and no premature confirmation error');
elements.password.value = 'short'; elements.password.listeners.input();
check(elements['password-help'].textContent === 'Use at least 12 characters.', 'guidance starts on input');
elements.password.value = 'Only a synthetic passphrase'; elements.password.listeners.input();
check(elements['password-help'].textContent.includes('Checked again when you submit.'), 'local success does not promise server acceptance');
elements.password_confirmation.value = 'different'; elements.password_confirmation.listeners.input();
check(!elements['confirmation-help'].textContent, 'confirmation mismatch waits for interaction completion');
elements.password_confirmation.listeners.blur();
check(elements['confirmation-help'].textContent === 'Passwords do not match.', 'confirmation mismatch after blur');
elements.password_confirmation.value = elements.password.value; elements.password_confirmation.listeners.input();
check(elements['confirmation-help'].textContent === 'Passwords match.' && !elements.password_confirmation.attrs['aria-invalid'], 'matching confirmation clears error');
elements.password.value = '123456789012'; elements.password.listeners.input();
check(elements.password.validity === policy.messages.common && elements['password-help'].textContent === policy.messages.common, 'common-password message matches server policy');
elements.password.value = 'a'.repeat(12); elements.password.listeners.input();
check(elements.password.validity === policy.messages.repeated, 'specific repetition error');
const samples = [...policy.common, 'short', 'Ab'.repeat(65), ' '.repeat(12), 'a'.repeat(12), 'x\0'.repeat(6), '😀🚀'.repeat(6), '😀🚀'.repeat(64), 'abcdefghijkl'];
const encoded = Buffer.from(JSON.stringify(samples)).toString('base64');
const compared = spawnSync(php, [], { input: `<?php require ${JSON.stringify(validator)}; $samples=json_decode(base64_decode('${encoded}'),true); echo json_encode(array_map(static fn($p)=>GigHub\\Services\\RegistrationValidator::passwordError($p),$samples));`, encoding: 'utf8' });
assert.equal(compared.status, 0);
const backendMessages = JSON.parse(compared.stdout);
samples.forEach((value,index) => {
    elements.password.value = value; elements.password.listeners.input();
    check(elements.password.validity === (backendMessages[index] || ''), 'frontend/backend password result parity ' + index);
});
check(elements['role-update'].hidden && elements['role-update'].disabled && elements['role-update'].type === 'button', 'fallback cannot intercept enhanced submission');
check(!elements['client-profile'].hidden && !elements['optional-profile'].disabled, 'client fields enabled');
elements.company_name.value = 'stale'; elements.role.value = 'freelancer'; elements.role.listeners.change();
check(elements.company_name.value === '' && elements['client-profile'].disabled && elements['client-profile'].hidden, 'role change clears and disables stale company');
check(!elements['optional-profile'].hidden, 'freelancer keeps About You');
elements.role.value = ''; elements.role.listeners.change();
check(elements['optional-profile'].hidden && elements['optional-profile'].disabled, 'no role hides optional data');
for (const [length, accepted] of [[11,false],[12,true],[128,true],[129,false]]) {
    const value = Array.from('😀🚀'.repeat(65)).slice(0,length).join('');
    elements.password.value = elements.password_confirmation.value = value;
    let prevented = false;
    form.listeners.submit({ preventDefault() { prevented = true; } });
    check(prevented === !accepted, 'Unicode boundary ' + length);
}
// Autofill-style value replacement without input/change must not leave a stale error.
elements.password.value = elements.password_confirmation.value = 'A synthetic passphrase';
let prevented = false; form.listeners.submit({ preventDefault() { prevented = true; } });
check(!prevented && elements.password.validity === '', 'submission validates autofill-style value changes');
for (const [index, toggle] of toggles.entries()) {
    const field = elements[index ? 'password_confirmation' : 'password'];
    const before = field.value;
    toggle.listeners.click();
    check(field.type === 'text' && field.value === before && toggle.attrs['aria-label'].startsWith('Hide'), 'show keeps password and updates accessible label');
    toggle.listeners.click();
    check(field.type === 'password' && field.value === before && toggle.attrs['aria-label'].startsWith('Show'), 'hide keeps password and updates accessible label');
}
check(elements['error-summary'].focused, 'summary receives focus');
const css = readFileSync(resolve(__dirname, '../public/assets/registration.css'), 'utf8');
check(/\.password-fields\s*\{[^}]*flex-direction: column/.test(css), 'vertical layout at every breakpoint');
check(/\.password-input input\s*\{[^}]*padding-right: 54px/.test(css), 'input reserves room for eye without covering text');
check(/\.password-toggle\s*\{[^}]*position: absolute[^}]*width: 44px[^}]*height: 44px/.test(css), 'compact inside-input target remains touch accessible');
check(/\.password-toggle:focus-visible/.test(css) && /\.password-feedback\s*\{[^}]*min-height/.test(css), 'visible icon focus and reserved guidance space');
const border = css.match(/input, select, textarea[^}]*border: 1px solid #([a-f0-9]{6})/)[1];
const luminance = hex => hex.match(/../g).map(v => parseInt(v,16)/255).map(v => v <= .04045 ? v/12.92 : ((v+.055)/1.055)**2.4).reduce((sum,v,i) => sum + v*[.2126,.7152,.0722][i],0);
check(1.05/(luminance(border)+.05) >= 3, 'input border contrasts at least 3:1 with white');
console.log(`PASS: ${checks} registration UI behaviour checks; no browser or database writes.`);
