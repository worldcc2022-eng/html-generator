'use strict';
for(const form of document.querySelectorAll('form[data-busy]'))form.addEventListener('submit',()=>{const b=form.querySelector('button');if(b){b.disabled=true;b.textContent=form.dataset.busy;}});
const form=document.querySelector('#generate-form');
if(form){
 const unicodePercent=form.querySelector('[name=unicode_percent]'),freshPercent=form.querySelector('[name=fresh_percent]');
 if(unicodePercent&&freshPercent){
  for(const [input,other] of [[unicodePercent,freshPercent],[freshPercent,unicodePercent]])input.addEventListener('input',()=>{
   if(input.value.trim()==='')return;
   const percent=Number(input.value);if(Number.isInteger(percent)&&percent>=0&&percent<=100)other.value=String(100-percent);
  });
 }
 let busy=false,descriptionBusy=false,review=null,requestVersion=0,statusTimer;
 const error=document.querySelector('#generation-error'),progress=document.querySelector('#progress'),label=document.querySelector('#progress-label'),bar=document.querySelector('#progress-bar'),downloads=document.querySelector('#downloads');
 const csrf=form.querySelector('[name=csrf]').value;
 const toggle=document.querySelector('#use-openai'),panel=document.querySelector('#ai-description-panel'),descriptionError=document.querySelector('#description-error'),descriptionReview=document.querySelector('#description-review'),descriptionText=document.querySelector('#description-text'),wordCount=document.querySelector('#description-word-count'),descriptionStatus=document.querySelector('#description-status');
 const generateDescription=document.querySelector('#generate-description'),approveDescription=document.querySelector('#approve-description'),generateFiles=document.querySelector('#generate');
 const timePanel=document.querySelector('#schema-time-review'),timeProposal=document.querySelector('#schema-time-proposal'),timeStatus=document.querySelector('#schema-time-status'),approveTime=document.querySelector('#approve-schema-time'),generationTime=document.querySelector('#use-generation-time');
 const manualDate=document.querySelector('#schema-manual-date'),manualTime=document.querySelector('#schema-manual-time'),useManualTime=document.querySelector('#use-manual-time');
 async function request(path,data){
  const response=await fetch(path,{method:'POST',body:data,credentials:'same-origin',headers:{Accept:'application/json'}});
  if(!(response.headers.get('content-type')||'').includes('application/json'))throw new Error('Server did not return JSON. Sign in again and retry.');
  const result=await response.json();if(!response.ok||result.error)throw new Error(result.error||'Request failed.');return result;
 }
 function updateButtons(){
  generateFiles.disabled=busy||descriptionBusy||(Boolean(toggle?.checked)&&!review?.approved);
  generateFiles.classList.toggle('is-generating',busy);generateFiles.setAttribute('aria-busy',String(busy));
  generateFiles.querySelector('.generate-label').textContent=busy?'Generating…':'Generate Files & URLs';
  generateFiles.querySelector('.activity-spinner').hidden=!busy;
  if(generateDescription)generateDescription.disabled=busy||descriptionBusy;
  if(approveDescription)approveDescription.disabled=busy||descriptionBusy;
  if(approveTime)approveTime.disabled=busy||descriptionBusy||!review?.time_review?.available||review?.time_review?.approved===true;
  if(generationTime)generationTime.disabled=busy||descriptionBusy;
  if(useManualTime)useManualTime.disabled=busy||descriptionBusy||!review||!manualDate?.value||!manualTime?.value;
  if(manualDate)manualDate.disabled=busy||descriptionBusy;
  if(manualTime)manualTime.disabled=busy||descriptionBusy;
  document.querySelectorAll('.resume').forEach(b=>b.disabled=busy||descriptionBusy);
 }
 function showReview(out){
  review=out?.description?out:null;descriptionReview.hidden=!review;
  if(timePanel){const time=review?.time_review;timePanel.hidden=!review;timeProposal.textContent=time?.available?`Event starts: ${time.start_label}. Schema time: ${time.schema_label} (30 minutes earlier).`:'No verified event start time is available.';timeStatus.textContent=time?.approved?'Approved — NewsArticle and VideoObject will use the proposed schema time.':'Not approved — schemas will use the file-generation time.';approveTime.hidden=!time?.available;generationTime.hidden=!time?.available;if(manualDate)manualDate.value=time?.manual_date||'';if(manualTime)manualTime.value=time?.manual_time||'';}

  const sources=document.querySelector('#description-sources');
  if(sources){sources.replaceChildren();sources.hidden=true;
   if(review?.searched_at){const note=document.createElement('p');note.className='hint';note.textContent=review.viewing_details_found?'Web search completed. Sources:':'Web search completed. No event-specific viewing details confirmed; using your SurpriseStream fallback.';sources.append(note);sources.hidden=false;}
   for(const source of review?.sources??[]){let url;try{url=new URL(source.url);if(!['http:','https:'].includes(url.protocol))continue;}catch{continue;}const link=document.createElement('a');link.href=url.href;link.target='_blank';link.rel='noopener noreferrer';link.textContent=source.title||url.hostname;sources.append(link);sources.hidden=false;}
  }
  if(review){descriptionText.textContent=review.description;wordCount.textContent=`${review.words} / 100 words`;descriptionStatus.textContent=(review.approved?'Approved. This description will be reused in every file until you regenerate it.':'Review this draft, then approve it or request another version.')+(review.start_date?` Verified dates: ${review.start_date}${review.end_date&&review.end_date!==review.start_date?' to '+review.end_date:''}.`:'');}
  generateDescription.textContent=review?'Regenerate Description':'Generate Description';
  approveDescription.hidden=!review||review.approved;updateButtons();
 }
 function context(){return ['event','date','date_order'].map(n=>form.elements[n].value).join('\n');}
 async function descriptionAction(action){
  if(descriptionBusy||busy)return;
  const event=form.elements.event.value.trim(),date=form.elements.date.value.trim();
  if(!event){showReview(null);if(action!=='status'){descriptionError.textContent='Enter the event name first.';descriptionError.hidden=false;}return;}
  const version=++requestVersion,snapshot=context(),data=new FormData(form);data.set('action',action);if(review)data.set('revision',review.revision);
  descriptionError.hidden=true;descriptionBusy=true;updateButtons();
  generateDescription.textContent=action==='generate'?'Searching & Generating…':action==='approve'?'Approving…':'Loading Saved Description…';
  if(action==='manual_time'&&useManualTime)useManualTime.textContent='Saving…';
  if(action==='approve_time'&&approveTime)approveTime.textContent='Saving…';if(action==='use_generation_time'&&generationTime)generationTime.textContent='Saving…';
  try{const out=await request('/api/description',data);if(version===requestVersion&&snapshot===context())showReview(out);}
  catch(e){if(version===requestVersion){descriptionError.textContent=e.message;descriptionError.hidden=false;}}
  finally{descriptionBusy=false;if(useManualTime)useManualTime.textContent='Use Manual Time';if(approveTime)approveTime.textContent='Approve Schema Time';if(generationTime)generationTime.textContent='Use Generation Time';generateDescription.textContent=review?'Regenerate Description':'Generate Description';updateButtons();if(snapshot!==context()&&toggle?.checked){clearTimeout(statusTimer);statusTimer=setTimeout(()=>descriptionAction('status'),0);}}
 }
 function changed(){
  ++requestVersion;showReview(null);descriptionError.hidden=true;clearTimeout(statusTimer);
  if(toggle?.checked)statusTimer=setTimeout(()=>descriptionAction('status'),400);
 }
 if(toggle)for(const name of ['event','date','date_order'])form.elements[name]?.addEventListener(name==='date_order'?'change':'input',changed);
 toggle?.addEventListener('change',()=>{panel.hidden=!toggle?.checked;document.querySelector('#template-description-note').hidden=toggle?.checked;updateButtons();if(toggle?.checked)descriptionAction('status');});
 generateDescription?.addEventListener('click',()=>descriptionAction('generate'));
 approveDescription?.addEventListener('click',()=>descriptionAction('approve'));
 useManualTime?.addEventListener('click',()=>descriptionAction('manual_time'));
 manualDate?.addEventListener('input',updateButtons);manualTime?.addEventListener('input',updateButtons);
 approveTime?.addEventListener('click',()=>descriptionAction('approve_time'));
 generationTime?.addEventListener('click',()=>descriptionAction('use_generation_time'));
 const historyBody=document.querySelector('#recent-batches-body'),historyMessage=document.querySelector('#history-message'),clearHistory=document.querySelector('#clear-history'),successDialog=document.querySelector('#generation-success');
 let historyVersion=0;
 async function refreshHistory(){
  const version=++historyVersion,data=new FormData();data.set('csrf',csrf);
  try{const out=await request('/api/history',data);if(version===historyVersion){historyBody.innerHTML=out.html;historyMessage.hidden=true;document.querySelectorAll('.resume').forEach(b=>b.disabled=busy||descriptionBusy);}}
  catch(e){if(version===historyVersion){historyMessage.textContent='History could not refresh. It will retry automatically.';historyMessage.className='notice error';historyMessage.hidden=false;}}
 }
 clearHistory.addEventListener('click',async()=>{
  ++historyVersion;clearHistory.disabled=true;clearHistory.textContent='Clearing…';historyMessage.hidden=true;
  try{const data=new FormData();data.set('csrf',csrf);await request('/api/history-clear',data);await refreshHistory();}
  catch(e){historyMessage.textContent=e.message;historyMessage.className='notice error';historyMessage.hidden=false;}
  finally{clearHistory.disabled=false;clearHistory.textContent='Clear History';}
 });
 document.querySelector('#close-success').addEventListener('click',()=>successDialog.close());
 successDialog.addEventListener('click',e=>{if(e.target===successDialog){const r=successDialog.getBoundingClientRect();if(e.clientX<r.left||e.clientX>r.right||e.clientY<r.top||e.clientY>r.bottom)successDialog.close();}});
 function setBusy(value){busy=value;updateButtons();}
 async function run(id){
  while(true){const data=new FormData();data.set('csrf',csrf);data.set('id',id);const out=await request('/api/step',data);bar.max=out.total;bar.value=out.completed;label.textContent=`${out.completed} of ${out.total} files prepared`;
   await refreshHistory();
   if(out.complete){for(const type of ['zip','txt']){const url=`/download?id=${encodeURIComponent(id)}&type=${type}`;document.querySelector(`#${type}-download`).href=url;document.querySelector(`#success-${type}`).href=url;}progress.hidden=true;downloads.hidden=false;document.querySelector('#success-detail').textContent=`${Number(out.total).toLocaleString()} HTML files${out.generation_mode==='conn'?' + '+Number(out.total).toLocaleString()+' XML files':''} and ${Number(out.total).toLocaleString()} URLs are ready to download.`;successDialog.dispatchEvent?.(new CustomEvent('batch-ready',{detail:{id,generator:out.generation_mode||'event'}}));if(!successDialog.open)successDialog.showModal();return;}
  }
 }
 async function start(id=null){
  if(busy||descriptionBusy)return;document.querySelector('#retry-batch')?.remove();setBusy(true);error.hidden=true;downloads.hidden=true;document.querySelector('#empty-output').hidden=true;progress.hidden=false;label.textContent='Preparing batch…';bar.value=0;
  try{if(!id){const data=new FormData(form);data.set('use_openai',toggle?.checked?'1':'0');if(toggle?.checked&&review)data.set('description_revision',review.revision);const out=await request('/api/start',data);id=out.id;}await refreshHistory();await run(id);}
  catch(e){progress.hidden=true;error.textContent=e.message;error.hidden=false;if(id){const b=document.createElement('button');b.id='retry-batch';b.className='secondary';b.textContent='Resume This Batch';b.onclick=()=>{b.remove();start(id);};error.after(b);}}
  finally{setBusy(false);refreshHistory();}
 }
 form.addEventListener('submit',e=>{e.preventDefault();if(form.reportValidity())start();});
 historyBody.addEventListener('click',e=>{const b=e.target.closest('.resume');if(b)start(b.dataset.id);});
 setInterval(()=>{if(!document.hidden&&!busy&&!clearHistory.disabled)refreshHistory();},15000);
 document.addEventListener('visibilitychange',()=>{if(!document.hidden&&!busy&&!clearHistory.disabled)refreshHistory();});
 updateButtons();
}
// Server/proxy rejection pages are not evidence of a full disk.
function bankImportResponseError(response){
 const status=Number(response.status)||0,code=`HTTP ${status||'unknown'}`;
 if(response.redirected||status===401)return new Error(`Sign in again, then retry the import (${code}). Your pasted list has been kept.`);
 if(status===403)return new Error(`The server denied the import (${code}). Check ModSecurity/WAF logs and access rules.`);
 if(status===413)return new Error(`The server rejected the request size (${code}). Check PHP and web-server upload limits.`);
 if([502,503,504].includes(status))return new Error(`The web server could not complete the import (${code}). Check the server error log before retrying.`);
 return new Error(`The server returned an unexpected import response (${code}). Check the server error log for the cause.`);
}
const titleBankForm=document.querySelector('#title-bank-form');
if(titleBankForm)titleBankForm.addEventListener('submit',async e=>{
 e.preventDefault();const button=document.querySelector('#save-title-bank'),result=document.querySelector('#title-bank-result');
 const data=new FormData(titleBankForm);button.disabled=true;button.textContent='Saving Titles…';result.hidden=true;let uploadId=null;
 async function bankRequest(body){
  const response=await fetch('/api/title-bank',{method:'POST',body,credentials:'same-origin',headers:{Accept:'application/json'}});
  if(!(response.headers.get('content-type')||'').includes('application/json'))throw bankImportResponseError(response);
  let out;try{out=await response.json();}catch{throw bankImportResponseError(response);}if(!out||typeof out!=='object'||Array.isArray(out))throw bankImportResponseError(response);if(!response.ok||out.error)throw new Error(out.error||'Could not save title templates.');return out;
 }
 function uploadData(action){const body=new FormData();body.set('csrf',data.get('csrf'));body.set('generator',data.get('generator')||'event');body.set('action',action);if(uploadId)body.set('upload_id',uploadId);return body;}
 try{
  const file=titleBankForm.querySelector('[type=file]').files[0],blob=file||new Blob([data.get('title_templates')||''],{type:'text/plain;charset=utf-8'});let out;
  if(blob.size>128*1024*1024)throw new Error('Choose a title list up to 128 MB.');
  if(!blob.size)throw new Error('Paste templates or select a UTF-8 TXT file first.');
  // Stage small lists too: do not submit template contents as raw form arguments.
  {
   if(file&&!file.name.toLowerCase().endsWith('.txt'))throw new Error('Upload a UTF-8 TXT title list.');
   let body=uploadData('begin');body.set('size',String(blob.size));body.set('filename',file?file.name:'Pasted titles.txt');body.set('title_mode',data.get('title_mode'));uploadId=(await bankRequest(body)).upload_id;
   for(let offset=0,index=0;offset<blob.size;offset+=524288,index++){
    body=uploadData('chunk');body.set('index',String(index));body.set('chunk',blob.slice(offset,offset+524288),'chunk.bin');await bankRequest(body);button.textContent=`Uploading Titles… ${Math.round(Math.min(offset+524288,blob.size)/blob.size*100)}%`;
   }
   button.textContent='Saving Title Bank…';out=await bankRequest(uploadData('finish'));uploadId=null;
  }
  document.querySelector('#title-bank-export').hidden=false;
  document.querySelector('#title-bank-summary').textContent=`${Number(out.count).toLocaleString()} saved titles · Last saved: ${out.saved_at}`;
  result.textContent=`Saved ${Number(out.count).toLocaleString()} titles (${Number(out.added).toLocaleString()} added).`;result.className='notice success';
  titleBankForm.querySelector('textarea').value='';titleBankForm.querySelector('[type=file]').value='';
 }catch(error){result.textContent=error.message;result.className='notice error';if(uploadId){try{await bankRequest(uploadData('cancel'));}catch{}}}
 finally{result.hidden=false;button.disabled=false;button.textContent='Save Titles';}
});

const idBankForm=document.querySelector('#id-bank-form');
if(idBankForm)idBankForm.addEventListener('submit',async e=>{
 e.preventDefault();const button=document.querySelector('#save-id-bank'),result=document.querySelector('#id-bank-result');
 const data=new FormData(idBankForm);button.disabled=true;button.textContent='Saving IDs…';result.hidden=true;let uploadId=null;
 async function bankRequest(body){
  const response=await fetch('/api/id-bank',{method:'POST',body,credentials:'same-origin',headers:{Accept:'application/json'}});
  if(!(response.headers.get('content-type')||'').includes('application/json'))throw bankImportResponseError(response);
  let out;try{out=await response.json();}catch{throw bankImportResponseError(response);}if(!out||typeof out!=='object'||Array.isArray(out))throw bankImportResponseError(response);if(!response.ok||out.error)throw new Error(out.error||'Could not save ID templates.');return out;
 }
 function uploadData(action){const body=new FormData();body.set('csrf',data.get('csrf'));body.set('generator',data.get('generator')||'event');body.set('action',action);if(uploadId)body.set('upload_id',uploadId);return body;}
 try{
  const file=idBankForm.querySelector('[type=file]').files[0],blob=file||new Blob([data.get('id_templates')||''],{type:'text/plain;charset=utf-8'});let out;
  if(blob.size>128*1024*1024)throw new Error('Choose an ID list up to 128 MB.');
  if(!blob.size)throw new Error('Paste templates or select a UTF-8 TXT file first.');
  // Stage small lists too: do not submit template contents as raw form arguments.
  {
   if(file&&!file.name.toLowerCase().endsWith('.txt'))throw new Error('Upload a UTF-8 TXT ID list.');
   let body=uploadData('begin');body.set('size',String(blob.size));body.set('filename',file?file.name:'Pasted IDs.txt');body.set('id_mode',data.get('id_mode'));uploadId=(await bankRequest(body)).upload_id;
   for(let offset=0,index=0;offset<blob.size;offset+=524288,index++){
    body=uploadData('chunk');body.set('index',String(index));body.set('chunk',blob.slice(offset,offset+524288),'chunk.bin');await bankRequest(body);button.textContent=`Uploading IDs… ${Math.round(Math.min(offset+524288,blob.size)/blob.size*100)}%`;
   }
   button.textContent='Saving ID Bank…';out=await bankRequest(uploadData('finish'));uploadId=null;
  }
  document.querySelector('#id-bank-export').hidden=false;
  document.querySelector('#id-bank-summary').textContent=`${Number(out.count).toLocaleString()} saved IDs · Last saved: ${out.saved_at}`;
  result.textContent=`Saved ${Number(out.count).toLocaleString()} IDs (${Number(out.added).toLocaleString()} added).`;result.className='notice success';
  idBankForm.querySelector('textarea').value='';idBankForm.querySelector('[type=file]').value='';
 }catch(error){result.textContent=error.message;result.className='notice error';if(uploadId){try{await bankRequest(uploadData('cancel'));}catch{}}}
 finally{result.hidden=false;button.disabled=false;button.textContent='Save IDs';}
});

// Progressive enhancement: both banks remain available if JavaScript is disabled.
const bankTabs=document.querySelector('.template-bank-tabs');
if(bankTabs){
 const tabs=Array.from(bankTabs.querySelectorAll('[role="tab"]'));
 function selectBank(tab,focus=false){
  tabs.forEach(item=>{
   const active=item===tab,panel=document.getElementById(item.getAttribute('aria-controls'));
   item.setAttribute('aria-selected',String(active));item.tabIndex=active?0:-1;
   panel.hidden=!active;panel.setAttribute('role','tabpanel');panel.setAttribute('aria-labelledby',item.id);
  });
  if(focus)tab.focus();
 }
 bankTabs.hidden=false;selectBank(tabs[0]);
 tabs.forEach((tab,index)=>{
  tab.addEventListener('click',()=>selectBank(tab));
  tab.addEventListener('keydown',e=>{
   let next;if(e.key==='ArrowRight')next=(index+1)%tabs.length;
   else if(e.key==='ArrowLeft')next=(index+tabs.length-1)%tabs.length;
   else if(e.key==='Home')next=0;else if(e.key==='End')next=tabs.length-1;
   else return;e.preventDefault();selectBank(tabs[next],true);
  });
 });
}


const imageValues=document.querySelector('#image-url-values');
if(imageValues){
 const boxes=document.querySelector('#image-url-boxes'),actions=document.querySelector('#image-url-actions'),apply=document.querySelector('#apply-image-urls');
 const read=()=>imageValues.value.split(/\r\n|\r|\n/).map(v=>v.trim()).filter(Boolean);
 function addImage(value=''){
  const row=document.createElement('div');row.className='image-url-row';
  const input=document.createElement('input');input.type='url';input.placeholder='https://example.com/image.jpg';input.value=value;input.setAttribute('aria-label','Image URL');input.dataset.imageUrl='';
  const remove=document.createElement('button');remove.type='button';remove.className='secondary';remove.textContent='Remove';remove.addEventListener('click',()=>row.remove());
  row.append(input,remove);boxes.append(row);return input;
 }
 read().forEach(v=>addImage(v));if(!boxes.children.length)addImage();
 imageValues.hidden=true;boxes.hidden=false;actions.hidden=false;
 document.querySelector('#add-image-url').addEventListener('click',()=>addImage().focus());
 document.querySelector('#paste-image-urls').addEventListener('click',()=>{imageValues.value='';imageValues.hidden=false;apply.hidden=false;imageValues.focus();});
 apply.addEventListener('click',()=>{read().forEach(v=>addImage(v));imageValues.hidden=true;apply.hidden=true;});
 imageValues.form.addEventListener('submit',()=>{const values=Array.from(boxes.querySelectorAll('[data-image-url]')).map(i=>i.value.trim()).filter(Boolean);if(!imageValues.hidden)values.push(...read());imageValues.value=Array.from(new Set(values)).join('\n');});
}
