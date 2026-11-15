'use strict';
{
 async function post(url,data,timeout){
  const controller=new AbortController(),timer=setTimeout(()=>controller.abort(),timeout);
  try{
   const response=await fetch(url,{method:'POST',body:data,credentials:'same-origin',headers:{Accept:'application/json'},signal:controller.signal});
   if(!(response.headers.get('content-type')||'').includes('application/json'))throw new Error('The server did not return a result. Refresh the page and sign in again if needed.');
   const result=await response.json();if(!response.ok||result.error)throw new Error(result.error||'The action could not finish. Retry shortly.');return result;
  }catch(error){if(error.name==='AbortError')throw new Error('The request timed out. It may still finish on the server; retrying this upload safely checks files already present.');throw error;}
  finally{clearTimeout(timer);}
 }
 const form=document.querySelector('#server-upload-settings-form');
 if(form){
  const button=form.querySelector('button[type="submit"]'),check=form.querySelector('#server-check-connection'),result=document.querySelector('#server-upload-settings-result'),status=document.querySelector('#server-connection-status'),checked=document.querySelector('#server-connection-checked');
  const directory=form.querySelector('[name="directory"]'),publicUrl=form.querySelector('[name="public_url"]');let busy=false,connected=check?.dataset.connected==='true';
  const connectionView=(message,ok=false,kind='info')=>{connected=ok;if(status){status.textContent=message;status.className='notice '+(ok?'success server-connected':kind);}if(check){check.textContent=ok?'Connected':'Check Connection';check.className=ok?'secondary server-connected':'secondary';}};
  async function run(action){
   if(busy)return;busy=true;const data=new FormData(form);data.set('action',action);button.disabled=true;if(check)check.disabled=true;directory.disabled=true;publicUrl.disabled=true;button.textContent=action==='save'?'Saving…':'Save Destination';if(check&&action==='check')check.textContent='Checking…';result.hidden=true;
   try{
    const out=await post('/api/server-settings',data,30000);if(!out.destination?.directory||!out.destination?.public_url||!out.connection?.checked_at)throw new Error('The destination could not be verified. Retry the connection check.');
    if(out.saved){directory.value=out.destination.directory;publicUrl.value=out.destination.public_url;}
    const ok=out.connection.status==='connected'&&out.saved===true;connectionView(ok?'Connected — directory verified and ready to upload':'Directory verified — save this destination to use it for uploads',ok);
    if(checked)checked.textContent='Checked: '+new Intl.DateTimeFormat('en-GB',{dateStyle:'medium',timeStyle:'medium',timeZone:'Asia/Dhaka'}).format(new Date(out.connection.checked_at))+' (Bangladesh time)';
    result.textContent=action==='save'?'Saved. Future server uploads use '+out.destination.directory:ok?'Connection check passed.':'Check passed. Click Save Destination to apply these changes.';result.className='notice success';
   }catch(error){connectionView('Not Connected',false,'error');if(checked)checked.textContent='';result.textContent=error.message;result.className='notice error';}
   finally{busy=false;button.disabled=false;button.textContent='Save Destination';directory.disabled=false;publicUrl.disabled=false;if(check){check.disabled=false;check.textContent=connected?'Connected':'Check Connection';}result.hidden=false;}
  }
  form.addEventListener('submit',event=>{event.preventDefault();return run('save');});
  check?.addEventListener('click',()=>{if(form.reportValidity())return run('check');});
  for(const field of [directory,publicUrl])field.addEventListener('input',()=>{connectionView('Changes not saved — save or check this destination');if(checked)checked.textContent='';result.hidden=true;});
 }
 const button=document.querySelector('#success-server-upload'),dialog=document.querySelector('#generation-success');
 if(button&&dialog){
  const spinner=button.querySelector('.activity-spinner'),label=button.querySelector('.server-upload-label'),result=document.querySelector('#server-upload-result'),csrf=document.querySelector('#generate-form [name="csrf"]').value;
  const progressBox=document.querySelector('#server-upload-progress'),bar=document.querySelector('#server-upload-bar'),count=document.querySelector('#server-upload-count');
  const states=new Map();let current='';
  function render(){
   const state=states.get(current)||{},busy=state.status==='uploading',p=state.progress;
   button.disabled=!current||busy;spinner.hidden=!busy;label.textContent=busy?'Uploading…':'Upload to Server';button.setAttribute('aria-busy',String(busy));result.textContent=state.message||'';result.className='notice '+(state.status==='error'?'error':'success');result.hidden=!state.message;
   if(progressBox&&bar&&count){progressBox.hidden=!p;if(p){const total=Number(p.total)||0,uploaded=Number(p.uploaded)||0,verified=Number(p.verified)||0;bar.value=total?Math.min(state.status==='done'?100:99,Math.floor((uploaded+verified)*100/(2*total))):0;count.textContent=total?`${uploaded} / ${total} uploaded · ${verified} / ${total} verified${state.status==='done'?' · Complete':''}`:'Preparing files…';}}
  }
  function completed(id,attempt,upload){
   if(states.get(id)?.attempt!==attempt||states.get(id)?.status==='error')return;
   if(!Number.isInteger(upload.uploaded)||upload.uploaded<1||upload.verified!==upload.uploaded||!upload.public_url)throw new Error('All files could not be verified. Retry the upload.');
   states.set(id,{attempt,status:'done',progress:{total:upload.uploaded,uploaded:upload.uploaded,verified:upload.verified},message:`All ${upload.verified} ${upload.file_kind==='conn'?'HTML/XML files':'HTML files'} uploaded and verified in ${upload.public_url}${upload.unchanged?` (${upload.unchanged} already present)`:''}.`});
  }
  dialog.addEventListener('batch-ready',event=>{current=event.detail.id;render();});
  button.addEventListener('click',async()=>{
   const id=current;if(!/^[a-f0-9]{32}$/.test(id)||states.get(id)?.status==='uploading')return;
   states.set(id,{attempt:'',status:'uploading',progress:{total:0,uploaded:0,verified:0},message:''});render();let timer=null,attempt='';
   const accept=progress=>{
    const state=states.get(id);if(state?.attempt!==attempt||state.status!=='uploading'||progress?.attempt!==attempt)return;
    if(progress.status==='complete'){completed(id,attempt,{uploaded:progress.uploaded,verified:progress.verified,public_url:progress.public_url,unchanged:progress.unchanged,file_kind:progress.file_kind});}
    else if(progress.status==='error')states.set(id,{...state,status:'error',progress,message:progress.error||'The upload could not finish. Retry shortly.'});
    else if(['preparing','uploading','verifying'].includes(progress.status))states.set(id,{...state,progress});render();
   };
   const poll=async()=>{
    if(states.get(id)?.attempt!==attempt||states.get(id)?.status!=='uploading')return;
    try{const data=new FormData();data.set('csrf',csrf);data.set('id',id);data.set('attempt',attempt);accept((await post('/api/server-upload-status',data,10000)).progress);}catch{}
    if(states.get(id)?.attempt===attempt&&states.get(id)?.status==='uploading')timer=setTimeout(poll,750);
   };
   try{
    attempt=Array.from(crypto.getRandomValues(new Uint8Array(16)),value=>value.toString(16).padStart(2,'0')).join('');states.set(id,{...states.get(id),attempt});
    const data=new FormData();data.set('csrf',csrf);data.set('id',id);data.set('attempt',attempt);timer=setTimeout(poll,500);
    const out=await post('/api/server-upload',data,300000);if(!out.upload)throw new Error('The upload result could not be verified. Retry the upload.');completed(id,attempt,out.upload);
   }catch(error){if(states.get(id)?.attempt===attempt&&states.get(id)?.status!=='done')states.set(id,{...states.get(id),status:'error',message:error.message});}
   finally{clearTimeout(timer);render();}
  });render();
 }
}
