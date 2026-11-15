# Automation prompt for my existing PHP HTML Generator development chat

I am hosting a standalone **Connector Router** module at:

`https://html.surprisestream.com/connector-router/`

I have a ZIP containing working PHP source files, an installation README, a protected Admin interface, XML endpoint, route endpoint and token-protected import endpoint. The external test destination is initially `https://www.ub.edu/`, but I need to be able to enter/change approved third-party HTTPS destination domains manually in the administrator UI.

**Your task:** inspect my existing HTML Generator code and integrate this package without breaking any existing functions. Do not assume the package replaces my generator. Implement the following steps in my actual code, not pseudocode.

1. Inspect the generator's existing installation, MySQL database schema, settings, title templates, ID templates, event URL input, event date normalization, batch history, generated HTML layout, GitHub upload, server upload, download ZIP/TXT, XML inclusion settings and pending URL domain distribution.
2. Backup all changed files and preserve existing schemas and features. Avoid editing unrelated files.
3. Add a new **Connector & Routing** settings group consistent with the existing UI. Include: enable/disable toggle; module base URL; server-side API token input (store encrypted/protected and never print in HTML); default routing mode (302, optionally 301); destination mode (existing Event URL or separately entered approved HTTPS URL); a read-only connector XML URL; a connection test; and import status/logs.
4. Allow the administrator to manually enter any external destination hostname in the module's *approved host list*. For the initial test, use `www.ub.edu` with `https://www.ub.edu/` as the **destination** and later let me change it. Treat the external reference host as informational. Do **not** generate XSS payloads, injected FCKeditor markup, or `CONNECTOR` URLs that execute code on third-party websites.
5. During every enabled batch, after successful generation of event IDs, prepare an API request to `https://html.surprisestream.com/connector-router/import.php`. POST JSON containing `routes:[{id,destination,title,mode}]` with `Authorization: Bearer API_TOKEN`. Use server-side HTTP client (PHP cURL) with TLS verification, timeout, retry only on transient failures, and structured error handling. Never expose the token to visitors, generated HTML, HTML source, or the browser.
6. Reuse each existing unique ID exactly as generated; do not regenerate IDs for routing. Preserve Event URL, date, title, random seeds, URL query parameters, and output count. The connector route URL format is `https://html.surprisestream.com/connector-router/go.php?id={existing-id}`. Add this as an optional output column/export, without replacing my existing URLs unless I explicitly select that output mode.
7. Preserve domain distribution: If I request 300 outputs and configure 3 domains, total stays 300. If I add/remove a domain, redistribute **pending** URLs only, leaving IDs, encoded XML, metadata, destinations, and published URLs untouched. Published routes should never be silently changed.
8. Process large batches in chunks of at most 1,000 records (smaller if needed), track successful and failed import counts, avoid duplicate imports, and support explicit retries. Do not mark a route as deployed when its API call failed. Keep import actions idempotent where appropriate and avoid overwriting published IDs unless explicitly authorized.
9. Add a non-destructive **Test Router** action: verify `/health.php`, verify `connector.php?Command=GetFoldersAndFiles&Type=File` returns well-formed XML, create or verify a single test ID, and check that `/go.php?id=video-test-university-0001` returns HTTP 302 with `Location: https://www.ub.edu/`. Make the test stop before following the external redirect. Confirm the test only uses my own module endpoint.
10. If server deployment is selected, deploy normal generated HTML as before. If GitHub upload is selected, preserve static generation. Do not assume GitHub Pages can execute PHP. Maintain existing title replacement, image rotation, date fields, XML fields, and other output modifications.
11. Use secure input validation, HTTPS-only destinations, exact approved-host matching, CSRF protection, auth, no arbitrary open redirects, private credentials, and escaped metadata. Do not proxy credentials to third-party services.
12. Provide the exact changed files, complete working code, any migration instructions, rollback steps, test results, and commands to verify deployment. Keep the UI consistent with my current HTML Generator, and do not create a second admin page inside my main generator if its settings system can be extended.

**Important boundaries and acceptance criteria:**
- I manually control the destination host and can change it later.
- Initially the test destination is the previously observed `www.ub.edu` domain, but only as a normal HTTPS redirect target from my own site.
- No reliance on vulnerabilities or script injection on external sites.
- Existing generator outputs must be byte-for-byte unchanged when the connector toggle is off.
- Changes must be fully implemented and tested in the actual generator, not merely described.
- Do not delete or rewrite the existing generator.
