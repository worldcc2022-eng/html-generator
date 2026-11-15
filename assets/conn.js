'use strict';
{
 const save=document.querySelector('#conn-template-save'),template=document.querySelector('#conn-xml-template');
 if(save&&template){
  const result=document.querySelector('#conn-template-result'),form=document.querySelector('#generate-form');let busy=false;
  save.addEventListener('click',async()=>{
   if(busy)return;busy=true;save.disabled=true;template.disabled=true;save.textContent='Saving…';result.hidden=true;
   const controller=new AbortController(),timer=setTimeout(()=>controller.abort(),30000);
   try{
    const body=new FormData();body.set('csrf',form.elements.csrf.value);body.set('xml_template',new Blob([template.value],{type:'application/xml'}),'template.xml');
    const response=await fetch('/api/conn-template',{method:'POST',body,credentials:'same-origin',headers:{Accept:'application/json'},signal:controller.signal});
    if(!(response.headers.get('content-type')||'').includes('application/json'))throw new Error('The server did not return JSON. Refresh and sign in again if needed.');
    const out=await response.json();if(!response.ok||out.error||!out.saved)throw new Error(out.error||'Could not save XML template.');
    result.textContent='XML template saved.';result.className='notice success';
   }catch(error){result.textContent=error.name==='AbortError'?'Save timed out. Check the saved template after refreshing.':error.message;result.className='notice error';}
   finally{clearTimeout(timer);busy=false;save.disabled=false;template.disabled=false;save.textContent='Save XML Template';result.hidden=false;}
  });
 }
 const dialog=document.querySelector('#generation-success');
 dialog?.addEventListener('batch-ready',event=>{
  const conn=event.detail.generator==='conn';const git=document.querySelector('#success-git-upload');if(git)git.hidden=conn;
  for(const id of ['success-zip','zip-download']){const link=document.querySelector('#'+id);if(link)link.textContent=conn?'Download HTML + XML ZIP':'Download HTML ZIP';}
 });
}
