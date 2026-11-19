'use strict';
const vm = require('node:vm');
const fs = require('node:fs');
let handler, answer = false, confirms = 0;
const button = {disabled: false};
const form = {dataset: {paymentConfirm: 'Synthetic payment confirmation'},
    addEventListener: (_, f) => { handler = f; }, querySelectorAll: () => [button]};
vm.runInNewContext(fs.readFileSync('public/assets/progressive-payments.js', 'utf8'), {
    document: {querySelectorAll: () => [form]},
    window: {confirm: () => { confirms++; return answer; }}
});
let passed = 0;
function check(ok, text) { if (!ok) throw Error(text); passed++; }
function event() { return {defaultPrevented:false, preventDefault() {this.defaultPrevented=true;}}; }
let e=event(); handler(e); check(e.defaultPrevented && !button.disabled, 'cancel leaves form editable');
answer=true;e=event();handler(e);check(!e.defaultPrevented && button.disabled, 'confirmation allows one submit');
e=event();handler(e);check(e.defaultPrevented && confirms===2, 'duplicate prevented without a second prompt');
delete form.dataset.paymentSubmitting;button.disabled=false;e=event();e.defaultPrevented=true;handler(e);
check(confirms===2 && !button.disabled, 'respects previous validation cancellation');
console.log('PASS: '+passed+' offline confirmation checks; native browser dialogs not tested');
