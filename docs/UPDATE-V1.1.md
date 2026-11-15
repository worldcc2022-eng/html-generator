# EventForge v1.1 update

Extract eventforge-update-v1.1.zip into your existing application document root and overwrite the included application files. No reinstallation or database migration is needed. This update contains no config.php, installer, lock file, storage directory or template; your login, key, template and generated batches are retained. Refresh the dashboard after extraction. Re-upload the HTML once to record its original filename and upload timestamp if it was uploaded under v1.0.

Changes:
- Accept 31 October and other yearless month/day inputs; infer year from the event keyword or current Bangladesh year.
- Accept tournament/event names such as Vienna Open 2026; shorten the year out of short-name title slots.
- Clearly label the existing XML path field XML URL; accept full http(s) URLs or a domain/path.
- Require Event URL for new batches; replace only the single destination string in the matching Googlebot bypass script.
- Add five visible optional ID seed fields, distributing the chosen total evenly among the filled fields. Counter suffixes increment; otherwise append _1, _2, etc. All generated IDs are unique in a batch. No .html in URL IDs.
- Save uploads as versioned files, then atomically select the active upload through existing settings. Show original filename and upload date/time (Asia/Dhaka). Settings reload and API-only saves preserve these details.

Validation: original generator regression suite, new yearless-date/ID/redirect tests and HTTP/MySQL tests with a local OpenAI response fixture. Upload metadata persistence, active template selection, Event URL replacement, five-ID count distribution and all prior install/authentication/download protections are tested. Live provider calls, browser rendering and target-server deployment remain outside this local test.
