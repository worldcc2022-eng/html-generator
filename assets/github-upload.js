'use strict';
{
 const button=document.querySelector('#success-git-upload'),dialog=document.querySelector('#generation-success');
 if(button&&dialog){
  const spinner=button.querySelector('.activity-spinner'),label=button.querySelector('.git-upload-label'),result=document.querySelector('#git-upload-result'),csrf=document.querySelector('#generate-form [name=csrf]').value;
  const states=new Map();let current='';
  function render(){
   const state=states.get(current)||{};const uploading=state.status==='uploading',done=state.status==='done';
   button.disabled=!current||uploading||done;spinner.hidden=!uploading;label.textContent=uploading?'Uploading…':done?'Uploaded to Git':'Upload to Git';button.className='button secondary'+(done?' is-uploaded':'');button.setAttribute('aria-busy',String(uploading));
   result.textContent=state.message||'';result.className='notice '+(state.status==='error'?'error':'success');result.hidden=!state.message;
  }
  dialog.addEventListener('batch-ready',event=>{current=event.detail.id;render();});
  button.addEventListener('click',async()=>{
   const id=current;if(!/^[a-f0-9]{32}$/.test(id)||['uploading','done'].includes(states.get(id)?.status))return;
   states.set(id,{status:'uploading',message:'Uploading the HTML files to your repository root…'});render();
   try{
    const data=new FormData();data.set('csrf',csrf);data.set('id',id);
    const response=await fetch('/api/github-upload',{method:'POST',body:data,credentials:'same-origin',headers:{Accept:'application/json'}});
    if(!(response.headers.get('content-type')||'').includes('application/json'))throw new Error('The server did not return an upload result. Sign in again, then retry.');
    const out=await response.json();if(!response.ok||out.error)throw new Error(out.error||'Upload failed. Please retry.');
    const upload=out.upload;if(!upload||!Number.isInteger(upload.uploaded)||upload.uploaded<1)throw new Error('The upload result could not be verified. Retry the upload.');
    states.set(id,{status:'done',message:`${upload.uploaded} HTML files ${upload.unchanged?'are already present':'uploaded'} in ${upload.repository} (${upload.branch}) at the repository root.`});
   }catch(e){states.set(id,{status:'error',message:e.message});}
   finally{render();}
  });
  render();
 }
}
