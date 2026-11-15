# Connector Router — safe first-party auto-open demonstration

**Deploy directory:** `/home/surprisehtml/public_html/connector-router/`
**Existing site:** `https://html.surprisestream.com/connector-router/`

This is a complete GitHub Pages mapping overlay for the **existing Connector Router** (the base Connector Router must already be installed). It does not contain the installer, credentials, config.local.php, lib/Core.php, database changes, or any HTML Generator files. Do not install it as a standalone application.

## What changes

- Keeps the manually selected GitHub Pages **folder** and existing ID-to-`<id>.html` mapping. IDs are never changed.
- The XML endpoint at `/id/?jd=<id>` returns valid XML with `<CurrentFolder path="/1 - f/" .../>` and a `<File desc="1" name="<id>.html" size="0" url="https://.../<id>.html"/>` entry.
- Adds a **first-party auto-open demonstration** (`browser.php?...&auto=1`), which loads your Connector XML, validates the expected filename-to-URL pairing, and navigates to the corresponding GitHub Pages HTML file.
- Shows **Auto-open demo** links on `github-pages.php` and `bulk-links.php` after mapping.
- Existing standard preview/open links remain available. Existing Admin 30-day auth remains unchanged.
- **Does not place iframe or JavaScript XSS payloads in XML, exploit third-party FCKeditor, or generate external FCKeditor exploit links.**

## Install (SSH)

1. Upload `connector-github-safe-auto-update.zip` to `/home/surprisehtml/public_html/connector-router/`.
2. Run:

```bash
cd /home/surprisehtml/public_html/connector-router || exit 1
BACKUP="/home/surprisehtml/connector-auto-backup-$(date +%Y%m%d-%H%M%S)"
mkdir -p "$BACKUP/lib" "$BACKUP/assets" "$BACKUP/id" "$BACKUP/legacy" "$BACKUP/bin"
for f in lib/GithubPages.php assets/browser.js assets/browser-extra.css \
    github-pages.php github-bulk-import.php bulk-links.php bulk-export.php \
    browser.php id/index.php legacy/browser.html bin/github-smoke.php; do
    if [ -f "$f" ]; then cp -p "$f" "$BACKUP/$f"; fi
done
unzip -o connector-github-safe-auto-update.zip
chown -R surprisehtml:surprisehtml lib/GithubPages.php assets/browser.js \
    assets/browser-extra.css github-pages.php github-bulk-import.php \
    bulk-links.php bulk-export.php browser.php id legacy \
    bin/demo-smoke.php bin/github-smoke.php examples/generator-adapter.php
php -l lib/GithubPages.php
php -l github-pages.php
php -l bulk-links.php
php -l bin/demo-smoke.php
php -l id/index.php
php -l github-bulk-import.php
runuser -u surprisehtml -- php bin/demo-smoke.php
```

The smoke test requires a saved default GitHub Pages folder; if it exits with code 2, sign in and set it first at `/connector-router/github-pages.php`, then rerun.

## Usage

1. Log in at `https://html.surprisestream.com/connector-router/admin.php`.
2. Open `https://html.surprisestream.com/connector-router/github-pages.php`.
3. Enter the real GitHub Pages folder holding your uploaded `.html` pages, e.g. `https://username.github.io/repository/id/`.
4. Paste existing generated `.html` filenames, one per line, then save mappings.
5. Select **XML** to inspect the response, **Open file** to open the file directly, or **Auto-open demo** for first-party browser navigation.
6. To test manually: `https://html.surprisestream.com/connector-router/id/?jd=video-ufc-334-en-ufc-pass-tv487619`.

**Important:** This maps and formats URLs; it does not check whether a GitHub Pages file was deployed or verify its contents. Publish generated HTML through your existing generator, and confirm the file resolves first. Avoid placing secrets in URLs or HTML.

## Automation

After GitHub deployment succeeds, your existing HTML Generator should call the Connector's `github-bulk-import.php` with the original `.html` filenames and the GitHub Pages folder URL, using a server-side Bearer API token. Generate/export the first-party browser URLs and optional `auto=1` demo URLs using the response IDs; do not generate extra IDs or change the pending URL count. See `GENERATOR-INTEGRATION-PROMPT.md`.
