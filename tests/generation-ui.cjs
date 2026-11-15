// Interaction checks for generation feedback and AJAX history, without browser dependencies.
const fs=require('node:fs'),vm=require('node:vm'),assert=require('node:assert/strict');
class Element{
 constructor(){this.checked=false;this.hidden=false;this.disabled=false;this.textContent='';this.innerHTML='';this.handlers={};this.attrs={};this.classes=new Set();this.classList={toggle:(n,on)=>on?this.classes.add(n):this.classes.delete(n)};}
 addEventListener(n,fn){(this.handlers[n]??=[]).push(fn);}
 fire(n,event={}){for(const fn of this.handlers[n]??[])fn({preventDefault(){},target:this,...event});}
 setAttribute(k,v){this.attrs[k]=v;}
 querySelector(s){return this.children?.[s]??null;}
 showModal(){this.open=true;this.opens=(this.opens??0)+1;}
 close(){this.open=false;}
 replaceChildren(){this.items=[];}
 append(e){(this.items??=[]).push(e);}
 after(e){this.afterElement=e;}
 remove(){this.removed=true;}
}
async function main(){
 const selectors=['#generate-form','#generation-error','#progress','#progress-label','#progress-bar','#downloads','#use-openai','#ai-description-panel','#description-error','#description-review','#description-text','#description-word-count','#description-status','#generate-description','#approve-description','#generate','#recent-batches-body','#history-message','#clear-history','#generation-success','#close-success','#empty-output','#zip-download','#txt-download','#success-zip','#success-txt','#success-detail','#description-sources','#template-description-note'];
 const els=Object.fromEntries(selectors.map(s=>[s,new Element()]));const form=els['#generate-form'],button=els['#generate'],dialog=els['#generation-success'];
 form.elements=Object.fromEntries(['event','date','date_order'].map(n=>[n,new Element()]));form.elements.event.value='Test Event';form.elements.date.value='31 October 2026';form.reportValidity=()=>true;form.children={'[name=csrf]':{value:'token'}};
 button.children={'.generate-label':new Element(),'.activity-spinner':new Element()};
 const intervals=[],requests=[],history=els['#recent-batches-body'];let stepResolve,stepCalls=0,fail=false,historyFail=false,cleared=false,reviewResponse=null;
 const document={hidden:false,querySelector:s=>els[s]??null,querySelectorAll:()=>[],addEventListener(){},createElement:()=>new Element()};
 const fetch=async(path)=>{requests.push(path);let out={};if(path==='/api/description')out=reviewResponse??{};if(path==='/api/start'){cleared=false;out={id:'a'.repeat(32)};}if(path==='/api/history'){if(historyFail)throw Error('Offline');out={html:cleared?'empty':stepCalls>=2?'complete 2 / 2':'pending'};}if(path==='/api/history-clear')cleared=true;
  if(path==='/api/step'){stepCalls++;if(fail)throw Error('Generation failed');if(stepCalls===1)await new Promise(r=>stepResolve=r);out={total:2,completed:stepCalls===1?1:2,complete:stepCalls>=2};}
  return {ok:true,headers:{get:()=> 'application/json'},json:async()=>out};};
 class Data{set(){}}
 vm.runInNewContext(fs.readFileSync(__dirname+'/../assets/app.js','utf8'),{document,fetch,FormData:Data,setInterval:fn=>intervals.push(fn),setTimeout,clearTimeout,console,URL});
 const settle=async()=>{for(let i=0;i<12;i++)await new Promise(r=>setImmediate(r));};
 form.fire('submit');await settle();assert(button.disabled);assert.equal(button.attrs['aria-busy'],'true');assert.equal(button.children['.activity-spinner'].hidden,false);assert.equal(button.children['.generate-label'].textContent,'Generating…');assert.equal(history.innerHTML,'pending');assert(!dialog.open);
 stepResolve();await settle();assert.equal(history.innerHTML,'complete 2 / 2');assert.equal(dialog.opens,1);assert.equal(dialog.open,true);assert.equal(els['#downloads'].hidden,false);assert.equal(button.disabled,false);assert.equal(button.children['.activity-spinner'].hidden,true);assert.equal(button.children['.generate-label'].textContent,'Generate Files & URLs');assert(els['#success-detail'].textContent.includes('2 HTML files and 2 URLs'));assert.equal(els['#success-zip'].href,els['#zip-download'].href);assert.equal(els['#success-txt'].href,els['#txt-download'].href);
 els['#close-success'].fire('click');assert.equal(dialog.open,false);
 els['#clear-history'].fire('click');await settle();assert.equal(history.innerHTML,'empty');assert.equal(els['#clear-history'].disabled,false);
 const before=requests.length;intervals[0]();await settle();assert(requests.length>before);document.hidden=true;const hiddenCount=requests.length;intervals[0]();await settle();assert.equal(requests.length,hiddenCount);document.hidden=false;
 // Delegated Resume works for rows inserted by AJAX. Failed generation never opens a success popup.
 fail=true;history.fire('click',{target:{closest:()=>({dataset:{id:'b'.repeat(32)}})}});await settle();assert.equal(dialog.opens,1);assert.equal(els['#generation-error'].textContent,'Generation failed');assert.equal(button.disabled,false);assert.equal(button.children['.activity-spinner'].hidden,true);assert.equal(els['#generation-error'].afterElement.textContent,'Resume This Batch');
 // A history outage must not convert successful generation into an error or suppress its downloads.
 fail=false;historyFail=true;form.fire('submit');await settle();assert.equal(dialog.opens,2);assert.equal(els['#downloads'].hidden,false);assert.equal(els['#generation-error'].hidden,true);assert.equal(els['#history-message'].hidden,false);
 historyFail=false;intervals[0]();await settle();assert.equal(els['#history-message'].hidden,true);
 reviewResponse={description:'A researched viewing guide.',words:5,revision:'revision',approved:false,searched_at:'2026-10-31',viewing_details_found:true,sources:[{url:'https://broadcaster.example/schedule',title:'<TV Schedule>'},{url:'javascript:alert(1)',title:'Unsafe'},{url:'not a URL',title:'Invalid'}]};
 els['#use-openai'].checked=true;els['#use-openai'].fire('change');await settle();const sources=els['#description-sources'];assert.equal(sources.hidden,false);assert.equal(sources.items.length,2);assert.equal(sources.items[1].textContent,'<TV Schedule>');assert.equal(sources.items[1].href,'https://broadcaster.example/schedule');assert.equal(sources.items[1].rel,'noopener noreferrer');assert.equal(button.disabled,true);
 reviewResponse={...reviewResponse,viewing_details_found:false,sources:[]};els['#generate-description'].fire('click');await settle();assert(sources.items[0].textContent.includes('SurpriseStream fallback'));assert.equal(sources.items.length,1);
 console.log('PASS research source links use safe URLs and text content; fallback status shown separately from description');
 console.log('PASS centered-button loading state, success popup links, automatic history refresh, clear, delegated resume, generation failure, and history outage recovery');
}
main().catch(e=>{console.error(e);process.exit(1)});
