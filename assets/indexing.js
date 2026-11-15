function indexingTimeWindowLabel(start,total,size,gap){
 if(!/^(?:[01][0-9]|2[0-3]):[0-5][0-9]$/.test(start))return '—';
 const [hour,minute]=start.split(':').map(Number),first=hour*60+minute,duration=Math.max(0,Math.ceil(Math.max(0,Number(total)||0)/Math.max(1,Number(size)||1))-1)*Math.max(0,Number(gap)||0);
 const format=value=>{const minutes=value%1440,hour=Math.floor(minutes/60),minute=minutes%60;return (hour%12||12)+(minute?':'+String(minute).padStart(2,'0'):'')+(hour<12?'am':'pm');};
 return format(first)+' - '+format(first+duration);
}
function indexingDurationLabel(total,size,gap){
 const minutes=Math.max(0,Math.ceil(Math.max(0,Number(total)||0)/Math.max(1,Number(size)||1))-1)*Math.max(0,Number(gap)||0),parts=[];
 const days=Math.floor(minutes/1440),hours=Math.floor(minutes%1440/60),mins=minutes%60;
 if(days)parts.push(`${days} ${days===1?'day':'days'}`);if(hours)parts.push(`${hours} hr`);if(mins||!parts.length)parts.push(`${mins} min`);return parts.join(' ');
}
'use strict';
function indexingProjectionLabel(meta,pending){
 const cost=Number(meta.cost)>0?Number(meta.cost):10,required=pending*cost,after=Math.max(0,Number(meta.balance)||0)-required;
 return `Pending: ${pending} URLs · Required: ${required} credits\n`+(after>=0?`Estimated after pending: ${after} credits · ${Math.floor(after/cost)} URLs remaining`:`Shortfall: ${-after} credits · ${Math.ceil(-after/cost)} URLs over balance`);
}
function indexingPaintWorker(worker){
 const badge=document.querySelector('#indexing-worker-status');if(!badge)return;
 const running=worker.running!==false,label=running?'Online':'Offline';badge.textContent=label;badge.className='button secondary indexing-worker-status '+(running?'is-online':'is-offline');badge.title='Indexing worker · Last seen: '+worker.last_seen+' (Bangladesh)';badge.setAttribute('aria-label','Indexing worker: '+label);
}
function indexingCompareOrder(a,b,mode='time'){
 if(mode==='name')return Number(Number(a.sort_group)===3)-Number(Number(b.sort_group)===3)||(a.event||'').localeCompare(b.event||'',undefined,{numeric:true,sensitivity:'base'})||(a.id||'').localeCompare(b.id||'');
 return Number(a.sort_group)-Number(b.sort_group)||Number(a.sort_time)-Number(b.sort_time)||(b.created_at||'').localeCompare(a.created_at||'')||(a.id||'').localeCompare(b.id||'');
}
function indexingCountdownLabel(info,now,workerRunning=true){
 if(info.countdown_state!=='scheduled'||!Number(info.next_at))return info.countdown_label||'—';
 const seconds=Math.max(0,Math.ceil(Number(info.next_at)-now));
 if(!seconds)return workerRunning?'Due now':'Due now — worker offline';
 const days=Math.floor(seconds/86400),hours=Math.floor((seconds%86400)/3600),minutes=Math.floor((seconds%3600)/60),rest=seconds%60;
 return 'In '+(days?days+'d ':'')+[hours,minutes,rest].map(v=>String(v).padStart(2,'0')).join(':');
}
{
 async function request(path,data){
  const controller=new AbortController();let timer;
  const deadline=new Promise((_,reject)=>{timer=setTimeout(()=>{reject(new Error('Request timed out. The server may still finish this action. Check the updated counts and submission history before trying again.'));controller.abort();},45000);});
  try{return await Promise.race([deadline,(async()=>{
   const response=await fetch(path,{method:'POST',body:data,credentials:'same-origin',headers:{Accept:'application/json'},signal:controller.signal});
   if(!(response.headers.get('content-type')||'').includes('application/json'))throw new Error('The server did not return an Indexing result. Sign in again and retry.');
   const out=await response.json();if(!response.ok||out.error)throw new Error(out.error||'Indexing request failed.');return out;
  })()]);}finally{clearTimeout(timer);}
 }
 const move=document.querySelector('#move-indexer'),dialog=document.querySelector('#generation-success');
 if(move&&dialog){
  let current='';const states=new Map(),label=move.querySelector('.move-indexer-label'),spinner=move.querySelector('.activity-spinner'),result=document.querySelector('#move-indexer-result'),csrf=document.querySelector('#generate-form [name=csrf]').value;
  function render(){const state=states.get(current)||{};move.disabled=!current||state.status==='moving'||state.status==='done';spinner.hidden=state.status!=='moving';label.textContent=state.status==='moving'?'Moving…':state.status==='done'?'Moved to Indexing':'Move Indexer';result.replaceChildren();result.hidden=!state.message;result.className='notice '+(state.status==='error'?'error':'success');if(state.message){const text=document.createElement('span');text.textContent=state.message;result.append(text);if(state.status==='done'){const link=document.createElement('a');link.href='/indexing';link.className='indexing-open-link';link.textContent='Open Indexing';result.append(link);}}}
  dialog.addEventListener('batch-ready',event=>{current=event.detail.id;render();});
  move.addEventListener('click',async()=>{const id=current;if(!/^[a-f0-9]{32}$/.test(id)||['moving','done'].includes(states.get(id)?.status))return;states.set(id,{status:'moving'});render();try{const data=new FormData();data.set('csrf',csrf);data.set('id',id);const out=await request('/api/indexing-move',data);if(!out.batch)throw new Error('Could not verify the moved batch.');states.set(id,{status:'done',message:`${out.batch.total} URLs moved. Mode: Manual.`});}catch(e){states.set(id,{status:'error',message:e.message});}finally{render();}});render();
 }
 const page=document.querySelector('#indexing-page');
 if(!page){
  const badge=document.querySelector('#indexing-worker-status');
  if(badge?.dataset?.csrf){
   let checking=false;
   async function refreshWorker(){if(checking||document.hidden)return;checking=true;try{const data=new FormData();data.set('csrf',badge.dataset.csrf);const out=await request('/api/indexing-worker-status',data);indexingPaintWorker(out.worker);}catch(e){/* Keep the last confirmed worker status during a connection interruption. */}finally{checking=false;}}
   setInterval(refreshWorker,5000);void refreshWorker();document.addEventListener('visibilitychange',()=>{if(!document.hidden)void refreshWorker();});
  }
 }
 if(page){
  const connection=document.querySelector('#indexing-connection-form'),csrf=connection.querySelector('[name=csrf]').value;
  const countdowns=new Map();let workerRunning=page.dataset?.workerRunning!=='false';
  const cardOrder=new Map(),sortSelect=document.querySelector('#indexing-sort'),sortKey='eventforge-indexing-sort-'+(page.dataset?.sortUser||'default');let sortMode='time';
  try{if(globalThis.localStorage?.getItem(sortKey)==='name')sortMode='name';}catch{}
  if(sortSelect){sortSelect.value=sortMode;sortSelect.addEventListener('change',()=>{sortMode=sortSelect.value==='name'?'name':'time';try{globalThis.localStorage?.setItem(sortKey,sortMode);}catch{}orderCards();});}
  const pendingCounts=new Map();
  for(const card of page.querySelectorAll('[data-index-batch]'))pendingCounts.set(card.dataset.indexBatch,card.dataset.provider==='rocketindexer'?0:Math.max(0,Number(card.querySelector('[data-field=remaining]').textContent)||0));
  function orderCards(){const ordered=Array.from(cardOrder.entries()).sort((a,b)=>indexingCompareOrder(a[1],b[1],sortMode));ordered.forEach(([card],i)=>{if(card.isConnected===false){cardOrder.delete(card);return;}card.style.order=String(i);});}
  for(const card of page.querySelectorAll('[data-index-batch]'))cardOrder.set(card,{id:card.dataset.indexBatch,event:card.querySelector('h2')?.textContent||'',sort_group:Number(card.dataset.sortGroup),sort_time:Number(card.dataset.sortTime),created_at:card.dataset.sortCreated});
  orderCards();
  function trackCountdown(node,info){if(!node)return;countdowns.set(node,{info,observed:Date.now(),server:Number(info.server_now)||Date.now()/1000});}
  function tickCountdowns(){for(const [node,snapshot] of countdowns){if(node.isConnected===false){countdowns.delete(node);continue;}node.textContent=indexingCountdownLabel(snapshot.info,snapshot.server+(Date.now()-snapshot.observed)/1000,workerRunning);}}
  for(const node of page.querySelectorAll('[data-field=countdown]'))trackCountdown(node,{countdown_state:node.dataset.countdownState,next_at:Number(node.dataset.nextAt),server_now:Number(node.dataset.serverNow),countdown_label:node.textContent});
  tickCountdowns();
  function busy(button,value,label){button.querySelector('.activity-spinner').hidden=!value;button.querySelector('.index-action-label').textContent=label;button.setAttribute('aria-busy',String(value));}
  let balanceRefreshing=null,connected=page.dataset?.indexbearConnected==='true',balanceMeta={balance:Number(page.dataset?.balanceCredits)||0,cost:Number(page.dataset?.urlCost)||10};
  function updateProjection(){const result=document.querySelector('#indexing-projection');if(!result)return;result.hidden=!connected;result.textContent=indexingProjectionLabel(balanceMeta,Array.from(pendingCounts.values()).reduce((sum,n)=>sum+n,0));}
  updateProjection();
  function showBalance(meta){
   connected=true;balanceMeta=meta;updateProjection();document.querySelector('#indexing-credit-cost').textContent=meta.cost;
   const result=document.querySelector('#indexing-connection-result'),cost=Number(meta.cost)>0?Number(meta.cost):10;
   result.className='notice success';result.textContent=`Connected · Balance: ${Math.floor(Math.max(0,Number(meta.balance))/cost)} URLs Remaining`;
  }
  function refreshBalance(){
   if(!connected||document.hidden)return Promise.resolve();if(balanceRefreshing)return balanceRefreshing;
   const data=new FormData();data.set('csrf',csrf);
   balanceRefreshing=request('/api/indexing-balance',data).then(out=>{if(out.connection)showBalance(out.connection);}).catch(e=>{const result=document.querySelector('#indexing-connection-result');result.className='notice error';result.textContent='Balance refresh failed: '+e.message;}).finally(()=>{balanceRefreshing=null;});return balanceRefreshing;
  }
  connection.addEventListener('submit',async event=>{
   event.preventDefault();const button=connection.querySelector('button'),result=document.querySelector('#indexing-connection-result'),data=new FormData(connection);button.disabled=true;busy(button,true,'Connecting…');
   try{const out=await request('/api/indexing-connection',data);connection.querySelector('[name=api_key]').value='';showBalance(out.connection);}
   catch(e){result.className='notice error';result.textContent=e.message;}
   finally{button.disabled=false;busy(button,false,'Check Connection');}
  });
  function updateDuration(card){
   const output=card.querySelector('[data-field=duration]');if(!output)return;
   output.textContent=indexingTimeWindowLabel(card.querySelector('[name=start_time]').value,card.querySelector('[data-field=total]').textContent,card.querySelector('[name=per_submit]').value,card.querySelector('[name=gap_minutes]').value);
  }
  function updateBatch(b){
   pendingCounts.set(b.id,b.provider==='rocketindexer'?0:Math.max(0,Number(b.remaining)||0));updateProjection();globalThis.eventforgeRocketUpdate?.(b);
   const card=page.querySelector(`[data-index-batch="${b.id}"]`);if(!card)return;
   if(b.provider){card.dataset.provider=b.provider;const provider=card.querySelector('[name=provider]');if(provider){if(provider.value===provider.dataset.savedProvider)provider.value=b.provider;provider.dataset.savedProvider=b.provider;provider.dataset.locked=String(Boolean(b.provider_locked));if(!provider.disabled)provider.disabled=Boolean(b.provider_locked);}}
   if(b.sort_group!==undefined){cardOrder.set(card,b);orderCards();}
   if(b.accepted>Number(card.querySelector('[data-field=accepted]').textContent))void refreshBalance();
   const heading=card.querySelector('h2');if(heading)heading.textContent=b.event;
   for(const name of ['total','accepted','remaining','completed','failed','next_label']){const node=card.querySelector(`[data-field="${name}"]`);if(node)node.textContent=b[name];}
   trackCountdown(card.querySelector('[data-field=countdown]'),b);tickCountdowns();
   const badge=card.querySelector('[data-field=status]');badge.textContent=b.status_label||'Ready';badge.className='indexing-state indexing-badge '+(b.status_class||'ready');
   const progress=card.querySelector('[data-field=progress]');progress.max=b.total;progress.value=b.accepted;progress.classList.toggle('is-submitting',b.status==='running');
   const error=card.querySelector('[data-field=last_error]');error.textContent=b.last_error;error.hidden=!b.last_error;
   card.querySelector('[data-field=history_html]').innerHTML=b.history_html;
   const randomize=card.querySelector('.index-domain-randomize');if(randomize){randomize.dataset.randomized=b.randomized_at?'true':'false';if(!randomize.disabled)randomize.querySelector('.index-action-label').textContent=b.randomized_at?'Randomized':'Randomize';}
   const domains=card.querySelector('[data-field=domains_html]');if(domains&&b.domains_html!==undefined)domains.innerHTML=b.domains_html;
   const submit=card.querySelector('.index-submit-now');submit.hidden=false;
   const pause=card.querySelector('.index-pause');if(pause){pause.dataset.paused=b.paused?'1':'0';pause.hidden=b.mode!=='automatic'||b.remaining===0;if(!pause.disabled)busy(pause,false,b.paused?'Resume':'Pause');}
   updateDuration(card);
   const reduceCount=card.querySelector('[data-field=reduce_remaining]');if(reduceCount)reduceCount.textContent=b.remaining;
   const reduceInput=card.querySelector('[name=remove_count]');if(reduceInput)reduceInput.max=b.remaining;
   const domainReduce=card.querySelector('.index-domain-reduce-form');
   if(domainReduce&&Array.isArray(b.domains)){
    const selected=domainReduce.querySelector('[name=domain]').value,pending=b.domains.find(d=>d.base===selected)?.pending??0;
    domainReduce.querySelector('[name=remove_count]').max=pending;
    domainReduce.querySelector('.index-domain-reduce-source').textContent=selected+' · '+pending+' pending URLs';
   }
  }
  for(const form of page.querySelectorAll('.indexing-batch-form')){
   let submitting=false;const provider=form.querySelector('[name=provider]');if(provider){provider.addEventListener('change',()=>{const size=form.querySelector('[name=per_submit]');size.max=provider.value==='rocketindexer'?100:1000;});}
   for(const name of ['start_time','per_submit','gap_minutes']){const input=form.querySelector(`[name=${name}]`);if(input)input.addEventListener('input',()=>updateDuration(form.closest('[data-index-batch]')));}
   const pause=form.closest?.('[data-index-batch]')?.querySelector('.index-pause');
   if(pause)pause.addEventListener('click',async()=>{
    if(pause.disabled)return;const data=new FormData(form),message=form.querySelector('.index-action-message');data.set('paused',pause.dataset.paused==='1'?'0':'1');pause.disabled=true;busy(pause,true,pause.dataset.paused==='1'?'Resuming…':'Pausing…');message.hidden=true;
    try{const out=await request('/api/indexing-pause',data);updateBatch(out.batch);message.className='notice success';message.textContent=out.batch.paused?'Automatic submissions paused.':'Automatic submissions resumed.';}
    catch(e){message.className='notice error';message.textContent=e.message;}
    finally{pause.disabled=false;busy(pause,false,pause.dataset.paused==='1'?'Resume':'Pause');message.hidden=false;}
   });
   const remove=form.querySelector('.index-delete');
   if(remove)remove.addEventListener('click',async()=>{
    const message=form.querySelector('.index-action-message'),data=new FormData(form),id=data.get('id');remove.disabled=true;busy(remove,true,'Deleting…');message.hidden=true;
    try{const out=await request('/api/indexing-delete',data);if(out.deleted!==id)throw new Error('Could not verify the deletion.');page.querySelector(`[data-index-batch="${id}"]`)?.remove();pendingCounts.delete(id);globalThis.eventforgeRocketRemove?.(id);updateProjection();}
    catch(e){message.className='notice error';message.textContent=e.message;message.hidden=false;}
    finally{remove.disabled=false;busy(remove,false,'Delete');}
   });
   form.addEventListener('submit',async event=>{
    event.preventDefault();if(submitting)return;submitting=true;const action=event.submitter?.value||'save',button=event.submitter||form.querySelector('button'),message=form.querySelector('.index-action-message'),data=new FormData(form),controls=form.querySelectorAll('input,select,button');controls.forEach(c=>c.disabled=true);busy(button,true,action==='submit'?'Submitting…':'Saving…');message.hidden=true;
    try{if(action==='submit'&&provider&&provider.value!==provider.dataset.savedProvider)throw new Error('Save the indexer selection before Submit Now.');const out=await request(action==='submit'?'/api/indexing-submit':'/api/indexing-configure',data);updateBatch(out.batch);const dateField=form.querySelector('[name=start_date]');if(dateField&&action!=='submit')dateField.value=out.batch.start_date||'';message.className='notice '+(out.batch.last_error?'error':'success');message.textContent=out.batch.last_error|| (action==='submit'?`${out.batch.accepted} accepted · ${out.batch.remaining} remaining`:out.batch.mode==='automatic'?`Automatic schedule saved. Next: ${out.batch.next_label} Bangladesh time.`:'Manual mode saved.');}
    catch(e){message.className='notice error';message.textContent=e.message;}
    finally{submitting=false;message.hidden=false;controls.forEach(c=>c.disabled=false);if(provider)provider.disabled=provider.dataset.locked==='true';busy(button,false,action==='submit'?'Submit Now':'Save Settings');}
   });
  }
  for(const form of page.querySelectorAll('.index-reduce-form')){
   const card=form.closest('[data-index-batch]'),open=card.querySelector('.index-reduce-open'),input=form.querySelector('[name=remove_count]'),button=form.querySelector('button[type=submit]'),cancel=form.querySelector('.index-reduce-cancel'),result=form.querySelector('.index-reduce-result');let reducing=false;
   open.addEventListener('click',()=>{if(reducing)return;form.hidden=!form.hidden;if(!form.hidden){result.hidden=true;input.focus();}});
   cancel.addEventListener('click',()=>{if(!reducing)form.hidden=true;});
   form.addEventListener('submit',async event=>{
    event.preventDefault();if(reducing)return;reducing=true;const data=new FormData(form),controls=form.querySelectorAll('input,button');controls.forEach(c=>c.disabled=true);busy(button,true,'Saving…');result.hidden=true;
    try{const out=await request('/api/indexing-reduce',data);updateBatch(out.batch);input.value='';result.className='notice success';result.textContent=`${out.removed} pending URLs removed · ${out.batch.total} total · ${out.batch.remaining} remaining.`;}
    catch(e){result.className='notice error';result.textContent=e.message;}
    finally{reducing=false;controls.forEach(c=>c.disabled=false);busy(button,false,'Save');result.hidden=false;}
   });
  }
  for(const section of page.querySelectorAll('.indexing-domains')){
   const form=section.querySelector('.index-domain-form'),result=section.querySelector('.index-domain-result'),old=form.querySelector('[name=old_domain]'),input=form.querySelector('[name=new_domain]'),button=form.querySelector('button[type=submit]');let replacing=false;
   const addForm=section.querySelector('.index-domain-add-form');
   const reduceForm=section.querySelector('.index-domain-reduce-form');
   if(reduceForm){
    const reduceInput=reduceForm.querySelector('[name=remove_count]'),reduceButton=reduceForm.querySelector('button[type=submit]');
    reduceForm.querySelector('.index-domain-reduce-cancel').addEventListener('click',()=>{if(!replacing)reduceForm.hidden=true;});
    reduceForm.addEventListener('submit',async event=>{
     event.preventDefault();if(replacing)return;replacing=true;const data=new FormData(reduceForm),controls=reduceForm.querySelectorAll('input,button');controls.forEach(c=>c.disabled=true);busy(reduceButton,true,'Saving…');result.hidden=true;
     try{const out=await request('/api/indexing-reduce-domain',data);updateBatch(out.batch);reduceForm.hidden=true;reduceInput.value='';result.className='notice success';result.textContent=`${out.removed} pending URLs removed from the selected domain · ${out.batch.total} total · ${out.batch.remaining} remaining.`;}
     catch(e){result.className='notice error';result.textContent=e.message;}
     finally{replacing=false;controls.forEach(c=>c.disabled=false);busy(reduceButton,false,'Save');result.hidden=false;}
    });
   }
   const randomize=section.querySelector('.index-domain-randomize');
   if(randomize)randomize.addEventListener('click',async()=>{
    if(replacing)return;replacing=true;const data=new FormData(form);randomize.disabled=true;busy(randomize,true,'Randomizing…');result.hidden=true;
    try{const out=await request('/api/indexing-randomize',data);updateBatch(out.batch);result.className='notice success';result.textContent=out.randomized?`${out.randomized} pending URLs randomized. Full URLs, query strings and IDs are unchanged. Download URLs TXT to inspect the new order.`:'Fewer than two pending URLs remain; their order is unchanged.';}
    catch(e){result.className='notice error';result.textContent=e.message;}
    finally{replacing=false;randomize.disabled=false;busy(randomize,false,randomize.dataset.randomized==='true'?'Randomized':'Randomize');result.hidden=false;}
   });
   if(addForm){
    const open=section.querySelector('.index-domain-add-open'),addInput=addForm.querySelector('[name=new_domains]'),addButton=addForm.querySelector('button[type=submit]');
    open.addEventListener('click',()=>{if(replacing)return;addForm.hidden=false;form.hidden=true;if(reduceForm)reduceForm.hidden=true;result.hidden=true;addInput.focus();});
    addForm.querySelector('.index-domain-add-cancel').addEventListener('click',()=>{if(!replacing)addForm.hidden=true;});
    addForm.addEventListener('submit',async event=>{
     event.preventDefault();if(replacing)return;replacing=true;const data=new FormData(addForm),controls=addForm.querySelectorAll('input,textarea,button');controls.forEach(c=>c.disabled=true);busy(addButton,true,'Adding…');result.hidden=true;
     try{const out=await request('/api/indexing-add-domains',data);updateBatch(out.batch);addForm.hidden=true;addInput.value='';result.className='notice success';result.textContent=out.added?`${out.redistributed??0} pending URLs redistributed · ${out.batch.total} total · ${out.batch.remaining} remaining. IDs, XML and schedule unchanged.`:'No redistribution needed. Domains already exist or no pending URLs remain.';}
     catch(e){result.className='notice error';result.textContent=e.message;}
     finally{replacing=false;controls.forEach(c=>c.disabled=false);busy(addButton,false,'Add Domains');result.hidden=false;}
    });
   }
   section.addEventListener('click',async event=>{

    const reduce=event.target.closest('.index-domain-reduce-open');
    if(reduce){
     if(reduce.disabled||replacing||!reduceForm)return;
     form.hidden=true;if(addForm)addForm.hidden=true;
     reduceForm.querySelector('[name=domain]').value=reduce.dataset.domainBase;
     const count=reduceForm.querySelector('[name=remove_count]');count.value='';count.max=reduce.dataset.domainPending;
     reduceForm.querySelector('.index-domain-reduce-source').textContent=reduce.dataset.domainBase+' · '+reduce.dataset.domainPending+' pending URLs';
     reduceForm.hidden=false;result.hidden=true;count.focus();return;
    }
    const remove=event.target.closest('.index-domain-remove');
    if(remove){
     if(remove.disabled||replacing)return;replacing=true;const data=new FormData(form);data.set('domain',remove.dataset.domainBase);remove.disabled=true;busy(remove,true,'Removing…');result.hidden=true;
     try{const out=await request('/api/indexing-remove-domain',data);updateBatch(out.batch);form.hidden=true;if(addForm)addForm.hidden=true;if(reduceForm)reduceForm.hidden=true;result.className='notice success';result.textContent=`${out.removed} URLs removed · ${out.batch.total} total · ${out.batch.remaining} remaining.`;}
     catch(e){result.className='notice error';result.textContent=e.message;}
     finally{replacing=false;remove.disabled=false;busy(remove,false,'Remove');result.hidden=false;}
     return;
    }
    const open=event.target.closest('.index-domain-open');if(!open||open.disabled||replacing)return;if(addForm)addForm.hidden=true;if(reduceForm)reduceForm.hidden=true;old.value=open.dataset.domainBase;input.value=old.value;section.querySelector('.index-domain-source').textContent='Replacing: '+old.value;form.hidden=false;result.hidden=true;input.focus();});
   form.querySelector('.index-domain-cancel').addEventListener('click',()=>{if(!replacing)form.hidden=true;});
   form.addEventListener('submit',async event=>{
    event.preventDefault();if(replacing)return;replacing=true;const data=new FormData(form),controls=form.querySelectorAll('input,button');controls.forEach(c=>c.disabled=true);busy(button,true,'Replacing…');result.hidden=true;
    try{const out=await request('/api/indexing-replace-domain',data);updateBatch(out.batch);form.hidden=true;result.className='notice success';result.textContent=`${out.replaced} pending URLs updated. The replacement will be used from the next submission.`;}
    catch(e){result.className='notice error';result.textContent=e.message;}
    finally{replacing=false;controls.forEach(c=>c.disabled=false);busy(button,false,'Replace');result.hidden=false;}
   });
  }
  let refreshing=false;
  async function refresh(){if(refreshing||document.hidden)return;refreshing=true;const error=document.querySelector('#indexing-refresh-error');try{const data=new FormData();data.set('csrf',csrf);const out=await request('/api/indexing-refresh',data);workerRunning=out.worker.running!==false;pendingCounts.clear();globalThis.eventforgeRocketSnapshot?.(out.batches);out.batches.forEach(updateBatch);updateProjection();tickCountdowns();indexingPaintWorker(out.worker);error.hidden=true;}catch(e){error.textContent=e.message;error.hidden=false;}finally{refreshing=false;}}
  setInterval(refresh,5000);setInterval(tickCountdowns,1000);setInterval(refreshBalance,60000);void refreshBalance();document.addEventListener('visibilitychange',()=>{if(!document.hidden){refresh();void refreshBalance();}});
 }
}
