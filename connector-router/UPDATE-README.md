# Connector Router: first-party bulk browser update

**Deployment:** Existing installation at `/home/surprisehtml/public_html/connector-router/` on `https://html.surprisestream.com/connector-router/`.

This update adds a fully functioning first-party file-browser workflow that follows the *network phases* from the inspected log: load browser page → request XML with `Command`, `Type`, `CurrentFolder`, `uuid` → parse XML file list → allow user-initiated opening/iframe preview of a generated route. It **does not** use the `/\\/external-domain/` FCKeditor injection bypass or embed executable scripts in XML metadata. That technique targets another site's browser. Hosting the XML on your domain does not establish authorization over a separate FCKeditor host.

## Deployment without overwriting the generator

This is an **update ZIP**, not the original installer. Do **not** run `bin/install.php` again. Do not extract over `/public_html/`. Upload `connector-firstparty-bulk-update.zip` to `/home/surprisehtml/public_html/connector-router/`, then:

```bash
cd /home/surprisehtml/public_html/connector-router
cp -p browser.php "browser.php.before-bulk-$(date +%Y%m%d-%H%M%S)"
unzip -o connector-firstparty-bulk-update.zip
chown -R surprisehtml:surprisehtml browser.php bulk-links.php lib/BulkLinks.php id assets/browser.js bin/bulk-smoke.php
runuser -u surprisehtml -- php bin/bulk-smoke.php
```

`config.local.php`, private storage, existing generated routes, and your existing HTML Generator are not overwritten by the ZIP.

The new routes are:

- `https://html.surprisestream.com/connector-router/bulk-links.php` (admin login required; Domain / browser URL field defaults to the owned browser page)
- `https://html.surprisestream.com/connector-router/legacy/browser.html` (optional first-party HTML browser URL)
- `https://html.surprisestream.com/connector-router/browser.php?Type=File&CONNECTOR=%2Fconnector-router%2Fid%2F%3Fjd%3Dvideo-ufc-334-en-ufc-pass-tv487619`
- `https://html.surprisestream.com/connector-router/id/?jd=video-ufc-334-en-ufc-pass-tv487619&Command=GetFoldersAndFiles&Type=File&CurrentFolder=%2F`

The `id/` endpoint delegates to your existing `connector.php` and uses the generator's exact original ID. For a populated file list, import the ID from your existing generator using the package's existing `import.php` API. An unknown ID correctly returns an empty XML file list, not an injection payload.

## Existing HTML Generator integration

1. Keep the existing HTML template uploader, filename algorithm, title metadata, XML fields, GitHub deployment, server deployment, and domain distribution unchanged.
2. After generating a batch, import the same IDs and approved destination URLs through the already provided authenticated `import.php` endpoint. Store the token only on the PHP server.
3. Call `cr_bulk_browser_url($originalFilename)` for each filename to get one first-party browser URL, with no new IDs and no change to the original count.
4. Reuse the same domain distribution and pending-URL records. Choose between standard generated HTML URLs and first-party browser URLs as an output mode; changing output mode must not overwrite previously published URLs.
5. Deploy or test with first-party browser pages you control. A third-party FCKeditor instance may reject cross-origin XML and must not be used to run injected scripts.

## Verify XML

```bash
curl -sS 'https://html.surprisestream.com/connector-router/id/?jd=video-ufc-334-en-ufc-pass-tv487619&Command=GetFoldersAndFiles&Type=File' | head -c 3000
```

Expected root element: `<Connector command="GetFoldersAndFiles" resourceType="Document">`. Empty `<Files>` means the ID has not been imported.

### Automated integration API

The new `bulk-export.php` endpoint accepts server-side POST JSON with the **existing** filenames, then returns exactly one browser URL per unique ID. Authenticate using the same existing Bearer API token as `import.php`.

Example payload:

```json
{
  "filenames": ["video-ufc-334-en-ufc-pass-tv487619.html"],
  "verify_routes": true
}
```

Before export, register each ID and its approved destination with the existing `import.php` endpoint. For PHP generator-side code, see `examples/generator-adapter.php`. `verify_routes` prevents exporting broken links for IDs that have not been imported. Existing IDs require idempotent import behavior in the caller; don't blindly reimport with `overwrite:false` on retries.

The `browser_url` field can be specified in the authenticated bulk-export JSON API. For this release it must be exactly the own-host `/browser.php` or `/legacy/browser.html` entry point; an external FCKeditor URL is deliberately rejected.
