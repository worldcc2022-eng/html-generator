# EventForge v1.5 — saved title bank

Extract into the existing application public_html and overwrite included application files. No reinstallation. The app creates two new MySQL tables on the first Settings visit/generation, using your existing database credentials. Existing config, installer, storage, HTML templates, API settings, description approvals and batches are not overwritten. Ensure the database user still has CREATE privileges.

## Settings → Title Templates

Paste one template per line, or upload a UTF-8 TXT file. Every line must contain `{event_name}`. Optional `{event_date}` renders the selected event date as `31 October 2026`.

Examples:

```text
++[Watch Live]TV!] {event_name} 𝐋𝐈𝐕𝐄 𝐒𝐭𝐫𝐞𝐚𝐦 Ｔｖ Ｃｈａｎｎｅｌ
[𝐋𝐈𝐕𝐄ＳＴＲＥＡＭ] {event_name} ＬＩＶＥ Ｓｔｒｅａｍｓ ＯＮ Ｔｖ
[LIVE@TV] {event_name} — {event_date}
```

Use Add to saved titles (default) to extend the bank, or Replace saved title bank to switch lists. Duplicate patterns are removed after normalizing case and whitespace. Blank lines are ignored. Titles may contain up to 2,048 Unicode characters. A selected file takes priority over the paste box. Invalid imports leave the active bank intact. The saved count and date/time are displayed; Download Saved Titles exports the entire current bank. The paste box is an import box, so it clears after success instead of loading 100,000+ rows into the page.

Lists up to 128 MB are supported. Larger browser inputs upload in 512 KiB chunks, so ordinary PHP upload_max_filesize/post_max_size limits do not require a single huge request. The server joins the chunks privately and imports with bounded batches. Tab closure during upload can leave a private staging directory named storage/title-upload-{user}-{random}; an abandoned staging directory may be removed manually later. The saved bank and usage history live in MySQL and must be included in database backups.

## Generation / no repeats

The generator uses only saved templates, preserving their wording and Unicode formatting apart from substituting the placeholders. It no longer uses the built-in random title patterns, sport wording, short-name transformations or forced full/short/date percentages. Control the style and date distribution through your template list. Random IDs and all other features remain unchanged.

Selection starts at a random position in an indexed random ordering, then shuffles a candidate window. Selection is atomic with batch creation. A transaction and unique database keys prevent the same template or rendered title from being used again for a keyword, even in concurrent sessions. Keyword case and extra whitespace are normalized. History is shared across app users and is not reset by date changes, bank replacement or re-importing.

Available titles from pre-update batch state files are backfilled once to avoid reusing previously generated title strings. Missing old state files cannot be reconstructed. Previously generated ZIP/TXT files retain their original snapshots.

If the requested count exceeds the available unused titles, generation stops and asks for new patterns or a lower count. That failed attempt consumes no titles. Empty banks do not fall back to built-in wording. Existing queued batches still use their saved titles.

## Validation

A 100,005-template UTF-8 TXT import passed through the chunked HTTP workflow using default PHP upload limits. Two independent concurrent DB sessions reserved 2,000 titles for one keyword without overlap. Tests verify persisted bank/export counts, failed imports, deduplication, exact saved wording, keyword normalization, no-repeat history after bank replacement/re-import, exhaustion rollback, optional date rendering and prior batch history. Existing count, IDs, descriptions, installer, upload and authentication regressions also pass. API responses are local controlled fixtures; live OpenAI calls and browser rendering remain unverified.
