# EventForge 1.0 verification

- PHP 8.3 syntax validation: all packaged PHP files passed.
- Generator checks: 156 assertions passed, counts 10 through 100.
- HTTP/MySQL/API-fixture checks: 68 assertions passed with MariaDB 10.11.
- Exact supplied Base64/XML example verified.
- ZIP/TXT count and ID/filename pairing verified.
- Bundled source body, JavaScript and JSON-LD preserved byte-for-byte.
- Installer creates tables and hashed admin; setup locks and refuses existing-admin overwrite.
- CSRF, login rejection/throttling, encrypted API settings, anonymous and cross-user download protection verified.
- Provider error and duplicate description responses rejected; checkpoints resume without partial downloads.
- Frontend JavaScript syntax checked with Node.
- Final code review performed by the implementer in native mode, without subagents.

Limits: Live OpenAI output/billing was not tested because no user API key was supplied. The provider integration was tested using a local Responses-compatible HTTP fixture in a separate disposable application copy; no fixture URL or credentials ship. Browser rendering was not verified because the runtime lacked a browser executable and its browser download returned an invalid archive. Target-server Apache/LiteSpeed rewrite behavior requires deployment; HTTP tests used equivalent private-route/routing rules in a local PHP development router. The package targets a dedicated subdomain root.

The source template's existing malformed meta attributes remain intact per the preservation requirement; their content values are replaced. Existing redirect destination, body and third-party branding are unchanged. See README for supported date formats and optional short-event/sport/ID overrides.
