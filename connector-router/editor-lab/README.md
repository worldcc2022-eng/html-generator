# FCKeditor 2.x — Private Compatibility Lab

This addon installs the genuine historical **FCKeditor 2.6.11** distribution from CKSource onto the *user-owned* website, inside the existing Connector Router. It uses the existing GitHub Pages mapping, with read-only XML metadata and no XSS payload or third-party file-browser injection.

**Important:** FCKeditor is unmaintained. Only run this in a password-protected test environment. Legacy server-side upload and connector scripts are blocked by `.htaccess`. Do not expose its browser to anonymous users.

## Install

Upload `fckeditor-private-lab-kit.zip` to `/home/surprisehtml/public_html/connector-router/`, then run:

```bash
cd /home/surprisehtml/public_html/connector-router
unzip -n fckeditor-private-lab-kit.zip
bash editor-lab/install.sh
```

The script downloads the **official** distribution from:
`https://download.cksource.com/FCKeditor/FCKeditor/2.6.11/FCKeditor_2.6.11.zip`

You will choose a separate Basic Auth password. Username: `labadmin`.

## Use

1. Log in to your Connector admin at `/connector-router/admin.php` (existing login).
2. Visit `/connector-router/github-pages.php` and set the GitHub Pages **base folder**. Register an existing filename if necessary.
3. Visit `/connector-router/editor-lab/index.php?jd=video-ufc-334-en-ufc-pass-tv487619`.
4. Enter your separate lab password to open the editor.
5. Click **Open legacy file browser**, or inside the editor use **Link → Browse Server**.
6. The legacy browser requests `/connector-router/editor-lab/connector.php?jd=...&Command=GetFoldersAndFiles&Type=File&CurrentFolder=/`, which returns XML referencing the matching `id.html` file in the configured GitHub Pages directory.

The actual original browser is located at:
`/connector-router/editor-lab/fckeditor/editor/filemanager/browser/default/browser.html`

## Example XML

```xml
<?xml version="1.0" encoding="UTF-8"?>
<Connector command="GetFoldersAndFiles" resourceType="File">
  <CurrentFolder path="/" url="https://username.github.io/repository/id/"/>
  <Folders/>
  <Files><File name="video-ufc-334-en-ufc-pass-tv487619.html" size="0" desc="1" url="https://username.github.io/repository/id/video-ufc-334-en-ufc-pass-tv487619.html"/></Files>
</Connector>
```

## Troubleshooting

- **401:** Enter separate `labadmin` Basic Auth credentials; if the auth file is unreadable by LiteSpeed, check ownership/access logs.
- **403:** The original package's server-side file manager connectors are deliberately disabled; use the read-only `editor-lab/connector.php` adaptor.
- **XML parser error:** Check the connector URL and confirm you configured a GitHub Pages folder. The lab accepts `GetFolders` and `GetFoldersAndFiles`, not uploads or folder creation.
- **404 for editor JS:** The official ZIP may not have downloaded; verify `editor-lab/fckeditor/fckeditor.js`.
- **Editor UI does not load:** Legacy FCKeditor may have browser compatibility issues in modern Chrome. The file browser and XML adaptor can still be tested independently.

## No conflict with HTML Generator

Everything is installed inside `connector-router/editor-lab/`. Your root `/home/surprisehtml/public_html/` generator, GitHub upload, Connector admin, and authentication remain unchanged.

## Remove the lab

After testing, delete `/home/surprisehtml/public_html/connector-router/editor-lab/`. This does not remove your Connector Router or its stored GitHub mappings.
