const fs=require('node:fs'),vm=require('node:vm'),assert=require('node:assert/strict');
const source=fs.readFileSync(__dirname+'/../assets/github-connect.js','utf8');
assert.ok(source,'Github Connect interaction handler exists');
class Element {constructor(){this.value='';this.hidden=false;this.textContent='';this.className='';this.disabled=false;this.listeners={};}addEventListener(n,fn){this.listeners[n]=fn;}querySelector(s){return this.children?.[s]||null;}setAttribute(){}select(){this.selected=true;}}
const ids=['github-connect-form','github-status','github-error','github-checked','github-key-panel','github-public-key','github-fingerprint','github-key-link','github-generate','github-connect','github-copy-key'];
const elements=Object.fromEntries(ids.map(k=>['#'+k,new Element()]));
const repo=new Element(),branch=new Element(),spinner=new Element();
for(const id of ['#github-generate','#github-connect'])elements[id].children={'.activity-spinner':new Element(),'.github-button-label':new Element()};repo.value='https://github.com/owner/repo';branch.value='main';
const form=elements['#github-connect-form'];form.querySelector=s=>s==='[name=repository_url]'?repo:s==='[name=branch]'?branch:spinner;
form.querySelectorAll=()=>[repo,branch,elements['#github-generate'],elements['#github-connect']];
let release=null;
let response={connection:{repository_url:repo.value,branch:'main',public_key:'ssh-ed25519 AAAA',fingerprint:'SHA256:test',status:'disconnected',checked_at:'',error:''}},calls=0;
const context={document:{querySelector:s=>elements[s]||null,execCommand:()=>true},navigator:{clipboard:{writeText:async text=>{assert.equal(text,'ssh-ed25519 AAAA');}}},FormData:class{set(k,v){this[k]=v;}},fetch:async()=>{calls++;if(release)await new Promise(resolve=>{release=resolve;});return {ok:true,json:async()=>response};},Intl,Date,console};
vm.runInNewContext(source,context);
(async()=>{
 const submit=value=>form.listeners.submit({preventDefault(){},submitter:{value}});
 await submit('generate');assert.equal(elements['#github-public-key'].value,'ssh-ed25519 AAAA');assert.equal(elements['#github-key-panel'].hidden,false);assert.match(elements['#github-status'].textContent,/Deploy key ready/);assert.equal(elements['#github-generate'].children['.activity-spinner'].hidden,true);assert.equal(repo.disabled,false);
 release=true;const pending=submit('connect');assert.equal(elements['#github-connect'].children['.activity-spinner'].hidden,false);assert.equal(elements['#github-connect'].children['.github-button-label'].textContent,'Connecting…');assert.equal(elements['#github-connect'].disabled,true);release();release=null;await pending;assert.equal(elements['#github-connect'].children['.activity-spinner'].hidden,true);
 response.connection.status='connected';response.connection.checked_at='2026-11-02T00:00:00Z';await submit('connect');assert.match(elements['#github-status'].textContent,/Connected — ready to upload/);assert.match(elements['#github-checked'].textContent,/Bangladesh time/);assert.match(elements['#github-connect'].className,/is-connected/);assert.equal(elements['#github-connect'].children['.github-button-label'].textContent,'Connected');
 repo.value='https://github.com/owner/new-repo';repo.listeners.input();assert.doesNotMatch(elements['#github-status'].textContent,/ready to upload/);assert.equal(elements['#github-key-panel'].hidden,true);
 response.connection.status='disconnected';response.connection.error='Generate a deploy key first.';await submit('connect');assert.equal(elements['#github-error'].hidden,false);assert.doesNotMatch(elements['#github-status'].textContent,/ready to upload/);
 response.connection.error='';response.connection.repository_url=repo.value;await submit('generate');await elements['#github-copy-key'].listeners.click();assert.equal(elements['#github-copy-key'].textContent,'Copied');assert.ok(calls===5);
 console.log('PASS Github settings AJAX generate, connect, edit invalidation, failed connect, copy, and busy cleanup');
})().catch(e=>{console.error(e);process.exitCode=1;});
