# Public FCKeditor file-browser fix

This patch makes the **editor UI and read-only file browser public** while keeping original server-side upload handlers blocked. It does **not** allow arbitrary third-party XML connectors or iframe JavaScript injection.

## Files changed

- `editor-lab/.htaccess`: removes Basic Auth and routes the legacy browser entry through a connector allowlist.
- `editor-lab/browser-gate.php`: forces same-origin `/editor-lab/connector.php?jd=...` so users cannot supply an arbitrary connector through the public file browser.
- `editor-lab/connector.php`: returns valid FCKeditor XML, including an empty file list if a GitHub mapping is not configured.
- `editor-lab/index.php`: public entry page with filename ID, file browser and editor.

## Install

Extract `fckeditor-public-browser-fix.zip` into:

`/home/surprisehtml/public_html/connector-router/`

From SSH (as root):

```bash
cd /home/surprisehtml/public_html/connector-router || exit 1
BACKUP="/home/surprisehtml/editor-lab-backup-$(date +%s)"
mkdir -p "$BACKUP"
cp -a editor-lab/.htaccess editor-lab/index.php editor-lab/connector.php "$BACKUP/" 2>/dev/null || true
unzip -o fckeditor-public-browser-fix.zip
chown surprisehtml:surprisehtml editor-lab/.htaccess editor-lab/index.php editor-lab/connector.php editor-lab/browser-gate.php
chmod 644 editor-lab/.htaccess editor-lab/index.php editor-lab/connector.php editor-lab/browser-gate.php
runuser -u surprisehtml -- php -l editor-lab/browser-gate.php
runuser -u surprisehtml -- php -l editor-lab/connector.php
runuser -u surprisehtml -- php -l editor-lab/index.php
```

Verify you can open:

`https://html.surprisestream.com/connector-router/editor-lab/`

`https://html.surprisestream.com/connector-router/editor-lab/fckeditor/editor/filemanager/browser/default/browser.html`

The second URL should redirect to the same path with first-party connector query parameters, then show the file browser without XML parsing alerts. If its folder is empty, configure an ID to GitHub Pages mapping first.

## Test headers and XML

```bash
curl -sSI 'https://html.surprisestream.com/connector-router/editor-lab/' | head -10
curl -sSI 'https://html.surprisestream.com/connector-router/editor-lab/fckeditor/editor/filemanager/browser/default/browser.html' | head -12
curl -sS 'https://html.surprisestream.com/connector-router/editor-lab/connector.php?jd=video-ufc-334-en-ufc-pass-tv487619&Command=GetFoldersAndFiles&Type=File&CurrentFolder=%2F' | head -c 2000
```

**Note:** Installing the public browser while leaving an unmaintained editor accessible still carries security risk. Keep the server-side connectors disabled; for production authoring, prefer an actively maintained editor.
