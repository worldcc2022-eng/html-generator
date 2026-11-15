'use strict';
{
 const form=document.querySelector('#generate-form');
 const spread=form?.querySelector('[name="spread_schema_time"]'),hours=form?.querySelector('[name="spread_hours"]');
 if(spread&&hours){const syncHours=()=>{hours.disabled=!spread.checked;};spread.addEventListener('change',syncHours);syncHours();}
 const broadcast=form?.querySelector('input[type="checkbox"][name="broadcast_enabled"]'),percent=form?.querySelector('[name="broadcast_percent"]');
 if(broadcast&&percent){const syncPercent=()=>{percent.disabled=!broadcast.checked;};broadcast.addEventListener('change',syncPercent);syncPercent();}
 const domains=form?.querySelector('[name="url_domains"]'),randomizer=form?.querySelector('input[type="checkbox"][name="randomize_domains"]');
 if(form?.querySelector('[name="spread_schema_time"]')&&domains&&randomizer){
  let previous=0;
  const sync=()=>{
   const distinct=new Set();
   for(let value of domains.value.split(/\r\n|\n|\r/)){
    value=value.trim();if(!value||/\s/.test(value))continue;
    if(!/^https?:\/\//i.test(value))value='https://'+value;
    try{const url=new URL(value);if(!['http:','https:'].includes(url.protocol)||url.username||url.password||url.search||url.hash)continue;distinct.add(value);}catch{}
   }
   if(distinct.size>1&&distinct.size!==previous)randomizer.checked=true;
   previous=distinct.size;
  };
  domains.addEventListener('input',sync);domains.addEventListener('change',sync);sync();
 }
}
