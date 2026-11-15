# EventForge v1.6.3 — event names and dashed IDs

Extract into existing public_html and overwrite included files. No reinstall.

Every newly generated manual or automatic ID contains the full lowercase event slug. If a seed already includes the event slug, it is not duplicated. Otherwise it is appended before the random suffix. Uppercase and underscore separators in manual seeds normalize to lowercase and dashes. Old trailing _ustv1-style streaming counters are still stripped. The final suffix is joined with a dash. For example watch-livv-on-tv-uk2 with PSG vs Barcelona generates watch-livv-on-tv-uk2-psg-vs-barcelona-ywnh. Long combined seed/event stems over 230 characters are rejected so filenames stay within filesystem limits.

Saved templates also convert underscores to dashes at import and render time, so existing saved patterns work without re-uploading. The {event_name} placeholder retains its spelling. Final filename IDs contain letters, numbers and dashes only. Generated ZIP files keep .html; URL IDs omit it. Existing generated batches are preserved.

Verified PHP lint, all PHP unit suites and HTTP/MySQL batch generation, including full event names in manual seeds, five-field balance, uniqueness, underscore conversion, matching ZIP/TXT IDs, imports, history, title bank and description approval.
