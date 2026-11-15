'use strict';
(() => {
  const status = document.getElementById('browser-status');
  const list = document.getElementById('file-results');
  const frame = document.getElementById('event-preview');
  const params = new URLSearchParams(window.location.search);
  const value = params.get('CONNECTOR');
  const type = params.get('Type') || 'File';
  // ID-specific URLs open their matching HTML file. auto=0 keeps the preview UI.
  let autoNavigate = params.get('auto') === '1';
  const root = new URL(document.documentElement.dataset.connectorRoot || '.', location.href);
  const allowed = new Set([root.pathname + 'id/', root.pathname + 'connector.php']);
  if (!['File','Document'].includes(type)) { status.textContent = 'Unsupported resource type'; return; }
  let request;
  try {
    request = new URL(value || 'connector.php', root);
    if (request.origin !== location.origin || !allowed.has(request.pathname)) {
      throw new Error('Only this installation\'s authorized connector can be loaded');
    }
    if (params.get('auto') !== '0' && request.searchParams.has('jd')) autoNavigate = true;
    request.searchParams.set('Command', 'GetFoldersAndFiles');
    request.searchParams.set('Type', type);
    request.searchParams.set('CurrentFolder', '/');
    request.searchParams.set('uuid', String(Date.now()));
  } catch (err) { status.textContent = err.message; return; }
  fetch(request, {credentials:'same-origin', redirect:'error'})
    .then(async response => {
      const payload = await response.text();
      if (!response.ok) throw new Error('HTTP ' + response.status + ': ' + payload.slice(0,120));
      return payload;
    })
    .then(xml => {
      const doc = new DOMParser().parseFromString(xml, 'application/xml');
      if (doc.querySelector('parsererror') || doc.documentElement.nodeName !== 'Connector') throw new Error('Invalid XML response');
      const failure = doc.querySelector('Error');
      if (failure) throw new Error(failure.getAttribute('text') || 'Connector XML error');
      const folder = doc.querySelector('CurrentFolder');
      const baseText = folder?.getAttribute('url') || '';
      const base = baseText ? new URL(baseText) : null;
      const files = [...doc.querySelectorAll('Files > File')];
      let displayed = 0;
      for (const file of files) {
        const filename = file.getAttribute('name') || '';
        const id = filename.replace(/\.html$/i,'');
        if (!/^[a-z0-9][a-z0-9-]{7,179}$/.test(id) || filename !== id+'.html') continue;
        let target;
        if (base && base.protocol === 'https:') {
          target = new URL(id+'.html',base);
          const declared = file.getAttribute('url');
          if (declared && target.href !== declared) continue;
        } else {
          target = new URL('go.php?id='+encodeURIComponent(id),root);
        }
        if (autoNavigate) {
          status.textContent = 'Opening matching generated page…';
          window.location.replace(target.href);
          return;
        }
        const row = document.createElement('tr');
        row.insertCell().textContent = filename;
        row.insertCell().textContent = file.getAttribute('desc') || '';
        const action = row.insertCell();
        const link = document.createElement('a');
        link.href = target.href; link.textContent = 'Open'; link.target = '_blank'; link.rel = 'noopener noreferrer';
        action.appendChild(link);
        action.appendChild(document.createTextNode(' · '));
        const btn = document.createElement('button');
        btn.type = 'button'; btn.textContent = 'Preview';
        btn.addEventListener('click',()=>{ frame.src=target.href; });
        action.appendChild(btn);
        list.appendChild(row);
        displayed++;
      }
      status.textContent = displayed + ' matching file(s) loaded' + (autoNavigate ? '; no matching file to open' : '');
    })
    .catch(err=>{status.textContent='XML request failed: '+err.message;});
})();
