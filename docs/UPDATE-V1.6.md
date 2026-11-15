# EventForge v1.6 — saved ID templates

Extract this cumulative update into your existing public_html and overwrite included files. No reinstall. config.php, install.php, uploaded HTML, credentials, API key and existing data are not included or overwritten. Open Settings once; new ID tables are created automatically.

Settings now includes ID Templates beside Title Templates. Paste one pattern per line or upload UTF-8 TXT, including large lists through chunked uploads. Append or replace, automatic duplicate removal, saved count/time and TXT export match the title-bank workflow.

Examples:

video-{event_name}-live_liv
watch-{event_name}_ustv3
{event_name}-online_ghvf

For PSG vs Barcelona these become video-psg-vs-barcelona-live_liv, watch-psg-vs-barcelona_ustv3 and psg-vs-barcelona-online_ghvf. Every {event_name} occurrence is substituted. Uppercase literal template text is converted to lowercase on save. Punctuation in the event name is normalized to dashes. Only lowercase ASCII letters, numbers, underscores and dashes are allowed in final IDs. Maximum 240 characters; omit .html, URLs and paths from templates. Other placeholders are rejected in ID templates.

Dashboard ID Source defaults to Saved ID Templates when a bank exists; otherwise manual/automatic IDs. Saved mode ignores the five manual seeds/prefix, picks templates randomly and uses their exact rendered IDs without adding suffixes. Each rendered ID and template can be used once per normalized keyword, across batches/users, even after reimport/replacement. If too few remain, generation stops without consuming IDs or titles. New saved-ID history begins with this update; prior manual IDs are not backfilled. Manual mode retains the five fields/random suffix behavior.

ZIP entries are ID.html; URL lines use the same ID without .html. Snapshot jobs and existing title placeholder behavior are preserved. Title and ID reservations share the batch transaction.

Verified with PHP lint, ID safety/substitution unit tests and real HTTP/MySQL integration: large chunked ID import, deduplication, append/replace/export, CSRF/authentication, invalid inputs, usage history, shortage rollback, exact ZIP/TXT pairing and manual mode. Existing application regression suite also passes. OpenAI uses local fixtures in integration; no live API request or browser rendering was verified.
