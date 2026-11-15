'use strict';
{
 for(const panel of document.querySelectorAll('[data-xml-target]')){
 const choice=panel.querySelector('[name="xml_target"]'),field=panel.querySelector('.xml-url-row input');
 const sync=()=>{field.required=choice.checked&&!field.readOnly;};
 document.querySelectorAll('[name="xml_target"]').forEach(radio=>radio.addEventListener('change',sync));sync();
 const button=panel.querySelector('button');if(button){
  const input=panel.querySelector('.xml-url-row input'),result=panel.querySelector('.notice'),spinner=button.querySelector('.activity-spinner'),label=button.querySelector('.xml-save-label');
  let saved=button.dataset.saved==='true',editing=!saved;
  button.addEventListener('click',async()=>{
   if(button.disabled)return;
   if(saved&&!editing){editing=true;input.readOnly=false;sync();label.textContent='Save';input.focus();result.hidden=true;return;}
   const data=new FormData();data.set('csrf',document.querySelector('#generate-form [name=csrf]').value);data.set('xml_path',input.value);data.set('xml_target',panel.dataset.xmlTarget);
   const controller=new AbortController();let timer;
   button.disabled=true;input.readOnly=true;spinner.hidden=false;label.textContent='Saving…';button.setAttribute('aria-busy','true');result.hidden=true;
   try{
    const deadline=new Promise((_,reject)=>{timer=setTimeout(()=>{reject(new Error('Saving timed out. Reload to check whether the URL was saved.'));controller.abort();},45000);});
    const out=await Promise.race([deadline,(async()=>{
     const response=await fetch('/api/xml-url',{method:'POST',body:data,credentials:'same-origin',headers:{Accept:'application/json'},signal:controller.signal});
     if(!(response.headers.get('content-type')||'').includes('application/json'))throw new Error('Sign in again, then retry saving the XML URL.');
     const out=await response.json();if(!response.ok||out.error)throw new Error(out.error||'Could not save the XML URL.');
     if(typeof out.xml_url!=='string'||!out.xml_url)throw new Error('Could not verify the saved XML URL.');return out;
    })()]);
    input.value=out.xml_url;input.required=false;saved=true;editing=false;button.dataset.saved='true';result.className='notice success';result.textContent='XML URL saved.';
   }catch(e){result.className='notice error';result.textContent=e.message;}
   finally{clearTimeout(timer);input.readOnly=saved&&!editing;sync();button.disabled=false;spinner.hidden=true;label.textContent=saved&&!editing?'Replace':'Save';button.setAttribute('aria-busy','false');result.hidden=false;}
  });
 }
 }
}
