'use strict';
{
 const panel=document.querySelector('#event-keywords');
 if(panel){
  const rows=panel.querySelector('#event-keyword-fields'),template=panel.querySelector('#event-keyword-template'),add=panel.querySelector('#add-event-keyword');
  add.addEventListener('click',()=>{
   if(rows.children.length>=999)return;
   const row=template.content.firstElementChild.cloneNode(true);rows.append(row);row.querySelector('input').focus();add.disabled=rows.children.length>=999;
  });
  rows.addEventListener('click',event=>{
   const remove=event.target.closest('[data-remove-keyword]');if(!remove)return;
   remove.closest('[data-keyword-row]').remove();add.disabled=false;
  });
 }
}
