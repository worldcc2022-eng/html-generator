'use strict';
(()=>{
 const root=document.querySelector('#rocket-connection');if(!root)return;
 const form=document.querySelector('#rocket-connection-form'),button=form.querySelector('button'),result=document.querySelector('#rocket-connection-result'),projection=document.querySelector('#rocket-projection'),csrf=form.querySelector('[name=csrf]').value;
 let connected=root.dataset.connected==='true',balance=Number(root.dataset.balance)||0,checking=false,refreshing=false;
 const pending=new Map();for(const card of document.querySelectorAll('[data-index-batch]'))pending.set(card.dataset.indexBatch,card.dataset.provider==='rocketindexer'?Math.max(0,Number(card.querySelector('[data-field=remaining]').textContent)||0):0);
 function paint(){const count=Array.from(pending.values()).reduce((a,b)=>a+b,0),after=balance-count;projection.hidden=!connected;projection.textContent=`Pending: ${count} URLs · Required: ${count} credits\n`+(after>=0?`Estimated after pending: ${after} credits · ${after} URLs remaining`:`Shortfall: ${-after} credits · ${-after} URLs over balance`);}
 globalThis.eventforgeRocketUpdate=batch=>{const before=pending.get(batch.id)||0;pending.set(batch.id,batch.provider==='rocketindexer'?Math.max(0,Number(batch.remaining)||0):0);paint();if(batch.provider==='rocketindexer'&&pending.get(batch.id)<before)void refresh();};
 globalThis.eventforgeRocketSnapshot=batches=>{const before=Array.from(pending.values()).reduce((a,b)=>a+b,0);pending.clear();for(const batch of batches)pending.set(batch.id,batch.provider==='rocketindexer'?Math.max(0,Number(batch.remaining)||0):0);paint();if(Array.from(pending.values()).reduce((a,b)=>a+b,0)<before)void refresh();};
 globalThis.eventforgeRocketRemove=id=>{pending.delete(id);paint();};
 async function request(path,data){
  const controller=new AbortController();let timer;
  const deadline=new Promise((_,reject)=>{timer=setTimeout(()=>{reject(new Error('Request timed out. Check the connection before trying again.'));controller.abort();},45000);});
  try{return await Promise.race([deadline,(async()=>{const response=await fetch(path,{method:'POST',body:data,credentials:'same-origin',headers:{Accept:'application/json'},signal:controller.signal});if(!(response.headers.get('content-type')||'').includes('application/json'))throw new Error('The server did not return a connection result. Sign in again and retry.');const out=await response.json();if(!response.ok||out.error)throw new Error(out.error||'RocketIndexer request failed.');return out;})()]);}finally{clearTimeout(timer);}
 }
 function show(meta){if(!meta)return;connected=true;balance=Math.max(0,Number(meta.balance)||0);result.className='notice success';result.textContent=`Connected · Balance: ${balance} URLs Remaining`;paint();}
 form.addEventListener('submit',async event=>{
  event.preventDefault();if(checking)return;checking=true;const data=new FormData(form);data.set('provider','rocketindexer');button.disabled=true;
  try{button.querySelector('.activity-spinner').hidden=false;button.querySelector('.index-action-label').textContent='Connecting…';const out=await request('/api/indexing-connection',data);form.querySelector('[name=api_key]').value='';show(out.connection);}
  catch(e){result.className='notice error';result.textContent=e.message;}
  finally{checking=false;button.disabled=false;button.querySelector('.activity-spinner').hidden=true;button.querySelector('.index-action-label').textContent='Check Connection';}
 });
 async function refresh(){if(!connected||refreshing||checking||document.hidden)return;refreshing=true;try{const data=new FormData();data.set('csrf',csrf);data.set('provider','rocketindexer');const out=await request('/api/indexing-balance',data);show(out.connection);}catch(e){/* Keep the last saved balance during an interruption. */}finally{refreshing=false;}}
 paint();setInterval(refresh,60000);document.addEventListener('visibilitychange',()=>{if(!document.hidden)void refresh();});void refresh();
})();
