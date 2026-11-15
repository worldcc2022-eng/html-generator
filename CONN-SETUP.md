# EventForge CONN — hosting setup (v1.9.89)

This version separates the output lab endpoint from HTML/XML hosting. The
currently authorized lab endpoint is the URL you supplied on html.surprisestream.com;
arbitrary external browser endpoints are not supported by this test integration.

## 1. Install this patch

Back up the EventForge application, then extract `eventforge-patch-v1.9.89.zip` (after the CONN v1.9.86 installation)
into its existing application directory:

```text
/home/surprisehtml/public_html
```

Allow its application files to replace the previous versions. Do not extract
this patch into the public file-hosting domain. Refresh EventForge with Ctrl+F5;
the header now has a **CONN** tab. No database migration or worker restart is
required. Existing Generator and Movies modes retain their generation logic.

## 2. Choose the hosting location

The hosting domain must run PHP 8.2 or newer, with Apache 2.4-compatible rewrite
rules enabled (including LiteSpeed's compatible rewriting). EventForge itself
needs its existing PHP ZIP and mbstring extensions. Keep directory ownership
under the same cPanel account so EventForge can publish files there.

For URLs beginning `https://eliveplay.stream/video/posts/`, use that domain's
actual document root. In your File Manager layout the expected root is:

| Setting | Example |
| --- | --- |
| Settings → Upload to Server → Server Directory | `/home/surprisehtml/eliveplay.stream` |
| Settings → Upload to Server → Public URL | `https://eliveplay.stream/` |
| CONN → Domain Names | Your supplied editor-lab `browser.html` endpoint |

Verify the document root in cPanel → Domains. Do not insert an extra
`public_html` component unless cPanel actually shows one for that domain.

Click **Save Destination**, then **Check Connection**. Connected confirms the
directory write/read/rename/delete checks; it does not test public HTTP routing.

The server destination is shared with the existing Upload to Server button.
Changing it affects subsequent server uploads from every generation tab.
Previously uploaded files remain where they were. Existing Generator/Movies
URL domain inputs remain independent of their storage destination.

A subdirectory also works: use its existing physical directory, matching Public
URL. The CONN output endpoint is independent of that hosting URL. For example, `/nov12thu/` adds that prefix to
all three CONN routes. Do not create `/video/posts/` manually: those are virtual
routes managed by PHP.

## 3. Save XML and generate

1. Open **CONN**.
2. Paste your complete XML in **CONN XML Template**, with `{url}` where its
   matched HTML address belongs. Click **Save XML Template**. The template is
   saved separately for your login.
3. Enter event keywords, title percentages, event URL, count and any existing
   description/schema options you need. In **Domain Names**, enter your lab endpoint:
   `https://html.surprisestream.com/connector-router/editor-lab/fckeditor/editor/filemanager/browser/default/browser.html`.
   HTML/XML URLs use **Settings → Upload to Server → Public URL**, independently
   of that endpoint. Save the server destination before starting a CONN batch. CONN uses the same saved HTML template and title
   library as Generator; it generates its own numeric IDs.
4. Click **Generate Files & URLs**. Each requested file produces one HTML and
   one XML. A count of 100 produces 100 HTML files, 100 XML files and 100 output
   URLs. The XML and hosting URL are frozen into that batch; later settings
   edits do not silently change a completed batch.
5. In the completion popup, click **Upload to Server**. Wait for every file to
   be uploaded **and verified**, then use **Download URLs TXT** or **Move
   Indexer**. For 100 pairs the upload progress counts 200 generated files.
   Retrying the same upload safely checks identical files already present.

Only exact `{url}` tokens in the XML are substituted. No XML nodes or other
template text are rewritten. Save XML edits before generating another batch.
CONN's GitHub action is hidden and its backend rejects CONN GitHub uploads in
this version; server hosting is the supported destination.

## 4. Exact example

With saved server Public URL `https://mydomain.com/`, ID `930674851126575`, and example UUID
`1794747480213`:

**HTML URL placed into XML `{url}`:**

```text
https://mydomain.com/video/posts/930674851126575?Command=GetFoldersAndFiles
```

**XML URL:**

```text
https://mydomain.com/video/&GetFoldersAndFiles=930674851126575?Command=GetFoldersAndFiles&Type=File&CurrentFolder=/&uuid=1794747480213
```

**Output URL:**

```text
https://html.surprisestream.com/connector-router/editor-lab/fckeditor/editor/filemanager/browser/default/browser.html?Type=File&CONNECTOR=%2F%5C%2Fmydomain.com%2Fvideo%2F%26GetFoldersAndFiles=930674851126575
```

The generated numeric ID ties the HTML and XML together. The query parameters
are URL parameters, not physical filenames. The output opens your specified lab browser with the hosted XML address.
What the XML does afterward is controlled by your saved XML template. A blank
Domain Names input uses the hosting router’s local XML route instead.
The router does not fetch arbitrary connector URLs or provide file browsing.

## 5. What upload installs

In the selected hosting directory:

```text
eventforge-conn.php
.htaccess                         (CONN block prepended; old content preserved)
.eventforge-conn/.htaccess         (blocks direct access)
.eventforge-conn/930674851126575.html
.eventforge-conn/930674851126575.xml
```

EventForge retains a private backup of a nonempty existing root `.htaccess`
inside the batch storage before merging its routing. Existing CORS headers,
`Options +Indexes` and other configuration are preserved. Different files that
already use the same generated filename are rejected instead of overwritten.

**Cross-origin lab loading:** If the XML host differs from the lab host, its
HTTP response must permit the lab origin through CORS. Your existing
`Access-Control-Allow-Origin "*"` configuration is preserved; otherwise configure
CORS on your own XML hosting domain. URL generation alone does not establish
that the lab browser can load or interpret an XML template.

**Manual ZIP upload:** The generated HTML + XML ZIP includes the protected data
directory, router, `conn-mapping.json`, and `CONN-ROUTING.txt`. Extract it into
the matching hosting directory, preserving hidden files. Add the contents of
`CONN-ROUTING.txt` at the top of the hosting directory's existing `.htaccess`;
do not replace its existing contents. The automatic upload handles this merge
for you. `conn-mapping.json` documents every pair and output URL.

## 6. Test one small batch first

Generate two files, upload them and use their exact URLs from the ZIP's
`conn-mapping.json`:

- Open `html_url`: it should return the matching generated HTML.
- Open `xml_url`: inspect its source to confirm the exact matched HTML URL.
- Open `output_url`: it opens the supplied lab browser. Check its Network tab
  for the matching XML request and CORS status. XML interpretation depends on
  your template and that browser implementation. The live lab page could not be
  fetched from this workspace, so this final browser step has not been verified.

If the HTML route returns 404, confirm that Hosting URL and the actual cPanel
document root match, that `eventforge-conn.php` exists there, and that the CONN
block is first in the correct `.htaccess`. If your server rejects `[END]`, check
its Apache/LiteSpeed rewrite compatibility before changing rules. A PHP source
download indicates PHP is not configured on the hosting domain.

If the app reports a destination mismatch, point Settings to the batch's
original XML hosting Public URL and directory, or generate a new batch for the
new hosting destination. The lab output prefix does not need to match hosting. Changing a saved destination does not rewrite old pairs.

Local verification covered numeric IDs, exact URL bytes, XML preservation,
root/subdirectory route parsing, API authentication/ownership, pair packaging,
filesystem upload hashes, retries, existing settings preservation and popup
actions. Native PHP ZIP and Apache/LiteSpeed rewrite execution require the
small live hosting test above; the local runtime used a ZIP transport adapter.
