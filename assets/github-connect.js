'use strict';

{
 const githubForm=document.querySelector('#github-connect-form');
 if(githubForm){
  const repo=githubForm.querySelector('[name=repository_url]'),branch=githubForm.querySelector('[name=branch]');
  const status=document.querySelector('#github-status'),error=document.querySelector('#github-error'),checked=document.querySelector('#github-checked'),keyPanel=document.querySelector('#github-key-panel'),key=document.querySelector('#github-public-key'),fingerprint=document.querySelector('#github-fingerprint'),keyLink=document.querySelector('#github-key-link'),copy=document.querySelector('#github-copy-key'),generate=document.querySelector('#github-generate'),connect=document.querySelector('#github-connect');
  const regenerate=document.querySelector('#github-regenerate');
  let busy=false,savedUrl=repo.value,connected=connect.className.includes('is-connected');
  const canonical=url=>url.trim().replace(/\/$/,'').replace(/\.git$/,'');
  function buttonState(button,label,spinning=false){button.querySelector('.github-button-label').textContent=label;button.querySelector('.activity-spinner').hidden=!spinning;button.setAttribute('aria-busy',String(spinning));}
  function connectionState(active){connected=active;generate.hidden=active;if(regenerate)regenerate.hidden=!key.value;keyPanel.hidden=active||!key.value||canonical(repo.value)!==canonical(savedUrl);connect.className='primary'+(active?' is-connected':'');buttonState(connect,active?'Connected':'Connect');}
  connectionState(connected);buttonState(generate,'Generate Deploy Key');
  function changed(){connectionState(false);status.className='notice info';status.textContent='Connection settings changed — connect to verify.';checked.textContent='';error.hidden=true;if(canonical(repo.value)!==canonical(savedUrl))keyPanel.hidden=true;}
  repo.addEventListener('input',changed);branch.addEventListener('input',changed);
  githubForm.addEventListener('submit',async event=>{
   event.preventDefault();if(busy)return;
   const action=event.submitter?.value||'connect',data=new FormData(githubForm);data.set('action',action);
   const preparing=action==='generate'||action==='regenerate',activeButton=action==='regenerate'&&regenerate?regenerate:preparing?generate:connect;
   busy=true;error.hidden=true;status.className='notice info';status.textContent=preparing?'Preparing deploy key…':'Checking repository access…';checked.textContent='';
   const controls=githubForm.querySelectorAll('input,button');controls.forEach(control=>control.disabled=true);connectionState(false);buttonState(activeButton,preparing?'Generating…':'Connecting…',true);githubForm.setAttribute('aria-busy','true');
   try{
    const response=await fetch('/api/github-connect',{method:'POST',body:data,credentials:'same-origin',headers:{Accept:'application/json'}});
    let result;try{result=await response.json();}catch{throw new Error('Could not read the connection result. Sign in again and retry.');}
    if(!response.ok||result.error)throw new Error(result.error||'Connection request failed.');
    const state=result.connection;if(!state)throw new Error('Connection response is missing. Please retry.');
    savedUrl=state.repository_url;key.value=state.public_key||'';fingerprint.textContent=state.fingerprint||'';
    keyPanel.hidden=!key.value||canonical(repo.value)!==canonical(savedUrl);keyLink.href=(state.repository_url||'https://github.com')+'/settings/keys';copy.textContent='Copy Public Key';
    if(state.error)throw new Error(state.error);
    repo.value=state.repository_url;branch.value=state.branch;
    connectionState(state.status==='connected');status.className='notice '+(connected?'success':'info');status.textContent=connected?'Connected — ready to upload':'Deploy key ready — add it to GitHub, then connect.';
    if(connected&&state.checked_at)checked.textContent='Last checked: '+new Intl.DateTimeFormat('en-GB',{dateStyle:'medium',timeStyle:'medium',timeZone:'Asia/Dhaka'}).format(new Date(state.checked_at))+' (Bangladesh time)';
   }catch(e){connectionState(false);status.className='notice info';status.textContent='Connection not verified';error.textContent=e.message;error.hidden=false;}
   finally{busy=false;controls.forEach(control=>control.disabled=false);buttonState(generate,'Generate Deploy Key');if(regenerate)buttonState(regenerate,'Regenerate Deploy Key');buttonState(connect,connected?'Connected':'Connect');githubForm.setAttribute('aria-busy','false');}
  });
  copy.addEventListener('click',async()=>{
   try{if(navigator.clipboard?.writeText)await navigator.clipboard.writeText(key.value);else{key.select();if(!document.execCommand('copy'))throw new Error('Copy unavailable');}copy.textContent='Copied';}
   catch{key.select();copy.textContent='Select & Copy Key';}
  });
 }
}
