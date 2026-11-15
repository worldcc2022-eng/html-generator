# EventForge v1.2 — description approval

Extract this update into the application's document root and overwrite the included files. No reinstall or database migration is required. This is a cumulative application update for v1.0/v1.1; config.php, install.php, storage, templates, logins, API settings and existing batches are not overwritten.

## Dashboard behavior

- Toggle off (default): the saved HTML template's description metadata content stays exactly as uploaded. OpenAI is not called and an API key is not required. Titles, IDs and the Event URL continue to be generated/replaced as before.
- Toggle on: enter event name and event date, then click Generate Description. OpenAI returns one plain-text description, maximum 100 whitespace-separated words. The prompt uses the supplied event and normalized calendar date, and PHP rejects an over-limit result.
- Review the full description and word count. Choose Approve Description or Regenerate Description. Generating files is disabled until approval, and the server independently enforces that approval.
- Approval is saved in the database per administrator for the current event/date and survives page reloads. Toggle on again to load it; loading and approving do not call OpenAI. The same approved text is used across every file and later batches for that event/date until regeneration.
- Changing event/date requires approval for the matching event/date. A successful regeneration replaces the draft and invalidates the previous approval. Failed regeneration leaves the previous saved approval intact. Regeneration returning identical text is rejected.
- A batch snapshots its approved description when it starts. Regenerating afterward does not alter previously generated files. Pre-v1.2 queued jobs retain their previous processing behavior and can still resume.

## Verification

Tests cover original template description bytes, single shared description across all files, no-key/no-provider-call template mode, event/date prompt data, max-word validation, review before approval, stale revisions, event/date changes, persistent approval, reuse without additional provider calls, regeneration/error behavior, legacy job resume, upload metadata, IDs/URLs, installer and authentication. Provider responses are controlled local fixtures; live OpenAI and browser rendering have not been tested in this environment.
