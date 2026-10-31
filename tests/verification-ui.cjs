'use strict';
// Small DOM fixture; this is not a browser or assistive-technology audit.
const { readFileSync } = require('node:fs');
const { resolve } = require('node:path');
const { runInNewContext } = require('node:vm');
const assert = require('node:assert/strict');
let checks = 0;
const check = (condition, label) => { assert.ok(condition, label); checks++; };
const sourcePath = resolve(__dirname, '../public/assets/verification.js');
check(require('node:fs').existsSync(sourcePath), 'verification enhancement exists');
const source = readFileSync(sourcePath, 'utf8');
const key = '4294967295.' + 'a'.repeat(64);
function fixture(hash, options = {}) {
    const events = [];
    const elements = Object.fromEntries(['verification-key', 'verification-fallback', 'verification-link-status', 'verification-form', 'error-summary'].map(id => [id, {
        value: '', textContent: '', open: false, listeners: {},
        addEventListener(name, callback) { this.listeners[name] = callback; },
        focus() { this.focused = true; },
    }]));
    const location = { hash, pathname: '/GigHub/public/index.php', search: '?route=/verify-email' };
    const history = { replaceState(state, title, url) { events.push(['clear', url]); if (options.clearFails) throw Error('unavailable'); } };
    Object.defineProperty(elements['verification-key'], 'value', { get() { return this.stored || ''; }, set(value) { events.push(['prefill']); this.stored = value; } });
    runInNewContext(source, { document: { getElementById: id => elements[id] }, window: { location, history } });
    return { elements, events };
}
let result = fixture('#verification=' + key);
check(result.elements['verification-key'].value === key, 'valid fragment prefilled locally');
check(result.events[0][0] === 'clear' && !result.events[0][1].includes('#'), 'fragment cleared before prefill');
check(!result.elements['verification-fallback'].open, 'manual fallback stays secondary for valid link');
check(!source.includes('.submit(') && !source.includes('fetch('), 'no automatic submission or network request');
for (const fragment of ['#verification=0.' + 'a'.repeat(64), '#verification=4294967296.' + 'a'.repeat(64), '#verification=01.' + 'a'.repeat(64), '#verification=1.' + 'A'.repeat(64), '#verification=%', '#unexpected=data', '#verification=' + key + '&x=1', '#verification=' + key + '\n']) {
    result = fixture(fragment);
    check(result.events[0][0] === 'clear', 'all malformed fragments are removed');
    check(result.elements['verification-key'].value === '', 'malformed fragments never prefill');
    check(!result.elements['verification-link-status'].textContent.includes(fragment), 'malformed key never reflected in feedback');
}
result = fixture('');
check(result.events.length === 0 && result.elements['verification-key'].value === '', 'no fragment leaves manual entry available');
result = fixture('#verification=' + key, { clearFails: true });
check(result.elements['verification-key'].value === '', 'failure to clear stops fragment processing');
check(fixture('').elements['error-summary'].focused, 'server error summary receives focus');
console.log(`PASS: ${checks} verification UI checks.`);
