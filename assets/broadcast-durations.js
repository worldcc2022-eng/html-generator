'use strict';
{
 const panel=document.querySelector('#broadcast-durations');
 if(panel){
  const rows=panel.querySelector('#broadcast-duration-rows'),template=panel.querySelector('#broadcast-duration-template');
  panel.querySelector('#add-broadcast-sport').addEventListener('click',()=>{
   const row=template.content.firstElementChild.cloneNode(true);rows.append(row);row.querySelector('input').focus();
  });
  rows.addEventListener('click',event=>{const remove=event.target.closest('[data-remove-duration]');if(remove)remove.closest('[data-duration-row]').remove();});
 }
}

{
 const type=document.querySelector('#generate-form [name="event_type"]');
 const duration=document.querySelector('#general-broadcast-duration');
 if(type&&duration){
  const sync=()=>{
   const active=type.value==='Event';duration.hidden=!active;
   duration.querySelectorAll('input,select').forEach(field=>{field.disabled=!active;});
  };
  type.addEventListener('change',sync);sync();
 }
}
