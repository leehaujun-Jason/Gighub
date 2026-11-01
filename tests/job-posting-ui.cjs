 'use strict';
const fs=require('node:fs');const vm=require('node:vm');const path=require('node:path');
let count=0;function check(ok,label){if(!ok)throw new Error(label);count++;}
const root=path.join(__dirname,'..');const form=fs.readFileSync(path.join(root,'app/Views/client/jobs/form.php'),'utf8');
const js=fs.readFileSync(path.join(root,'public/assets/jobs.js'),'utf8');const css=fs.readFileSync(path.join(root,'public/assets/jobs.css'),'utf8');
check(form.indexOf('value="save_draft"')<form.indexOf('value="publish"'),'first submit saves draft');
check(!js.includes('preventDefault')&&!js.includes('keydown'),'native Enter and textareas retained');
for(const field of ['title','description','budget','deadline']) {
 check(form.includes('for="'+field+'"')&&form.includes('id="'+field+'"'),'label '+field);
 check(form.includes(field+'-hint')&&form.includes(field+'-error'),'hint/error association '+field);
}
check(form.includes('name="csrf_token"'),'CSRF present');check(form.includes('type="checkbox"'),'native skill controls');
check(form.includes('inputmode="decimal"'),'decimal keyboard');check(css.includes('@media(max-width:600px)'),'responsive stacking');
check(css.includes(':focus-visible'),'visible keyboard focus');check(form.includes('$skills===[]'),'empty catalogue handling');
let focused=0;vm.runInNewContext(js,{document:{getElementById:id=>id==='job-errors'?{focus(){focused++;}}:null}});check(focused===1,'focus error summary');
vm.runInNewContext(js,{document:{getElementById:()=>null}});check(true,'no-error page safe');
console.log(`PASS: ${count} Job Posting UI source/DOM fixture checks; no browser or network.`);
