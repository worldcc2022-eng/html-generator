# Existing HTML Generator — connector auto-import and first-party test links

Enhance the existing PHP HTML Generator. Inspect the current application source and database before editing. Do not replace its template uploader, generated filenames, title templates, GitHub upload integration, domain rotation, URL counts, pending URL redistribution, or private credentials.

Existing Connector deployment: `https://html.surprisestream.com/connector-router/` (`/home/surprisehtml/public_html/connector-router/`). The Connector already supports a manually configured GitHub Pages folder URL and a server-to-server `github-bulk-import.php` POST API.

## Functional requirements

1. Once GitHub Pages deployment for a batch succeeds, collect the exact successfully uploaded `.html` filenames and the public GitHub Pages folder URL for that deployment. For each file, use its original filename as-is (no regenerated IDs and no new files).
2. Use a server-side authenticated POST to `https://html.surprisestream.com/connector-router/github-bulk-import.php`, with `Authorization: Bearer <API_TOKEN>` (stored in private server configuration) and JSON `{"github_pages_base":"https://example.github.io/repo/id/","filenames":["video-ufc-334-en-ufc-pass-tv487619.html"],"overwrite":false,"save_default":false}`. Split batches of more than 1000 filenames without changing the total count or creating duplicate IDs.
3. Make this an optional post-deployment action and show separate statuses for GitHub deployed, Connector mapped, XML verified, and demo URL produced. Mark failure accurately and offer manual retry; never report success from merely constructing a URL.
4. Preserve the existing generator's Domain field and its live URL generation rules. Provide an explicit separate choice to export Connector first-party browser links. Do not rewrite external domain input into an injected FCKeditor URL.
5. For each mapped ID, the XML lookup is `/connector-router/id/?jd=<id>` and the GitHub file is `<configured_folder>/<id>.html`. First-party browser URL returned by Connector is authoritative. For authorized demonstration on our own browser, append `&auto=1` to that browser URL.
6. Keep GitHub Pages folder URLs configurable, attached to the correct batch or individual ID. Do not remap previously published IDs by changing a global default. Preserve current domain distribution and the total generated URL count.
7. Add a small **Verify** action that checks the Connector XML via HTTP, parses XML, asserts File name matches `<id>.html`, and verifies URL matches configured GitHub Pages file. Optionally test GitHub Pages file HEAD/GET carefully with appropriate timeouts. Avoid checking thousands of files simultaneously.
8. Never expose the Bearer token in browser source, client JavaScript, generated HTML, logs, or URL parameters.
9. Treat XML `size`, `name` and `desc` as data; do not inject scripts, iframes, or redirects into XML attributes. The `auto=1` mode exists only on our own `browser.php` application.
10. Create rollback and compatibility tests. Deliver working updated PHP files, exact paths, and tests; preserve existing login, upload and server settings.
