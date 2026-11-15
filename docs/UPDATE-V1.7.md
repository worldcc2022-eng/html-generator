# EventForge v1.7 — article fields, malformed OG descriptions and image library

Extract into your existing public_html and overwrite included files. No reinstall. config.php, install.php and storage files are excluded; existing credentials, uploaded template, saved banks and batches remain intact. Generate a fresh batch to apply these changes.

New batches update these supplied-template targets:
- Standard description metadata and the malformed bare og:description marker. The parser handles > characters inside quoted attribute content; malformed OG attributes are repaired to property="og:description".
- sdc-article-header__long-title and ui-media-caption__caption-text: generated title, then the chosen description.
- sdc-article-date__date-time: normalized YYYY-MM-DD from the selected event date.
- Every sdc-site-tile__headline-text: a randomly shuffled title from the batch. The title pool cycles when there are more tiles than batch titles; a one-file batch has one title to use. Bank usage reservations are unchanged.

AI mode uses the approved description. With AI off, the uploaded primary description is used (standard description meta, or the unnamed description meta found in the supplied template; social-description fallback when needed). All description fields are synchronized to that source. No AI call is made when off. Executable scripts and unrelated body bytes remain unchanged.

Settings adds Image URLs in the left column. Each saved URL has its own input box and Remove button; Add Image URL creates another. Paste URL List accepts hundreds of lines at once; Apply URL List adds them to the boxes. Save Settings persists the list. With JavaScript disabled, a multiline text box remains available. Supports 500+ URLs and up to 10,000 unique URLs within an 8 MB list; accepts valid http(s) URLs and skips blanks/duplicates.

Each generated HTML file randomly selects one saved image and uses it consistently wherever https://sports2live.online/cdn/images1.jpg appears, including old query-string variants, src/srcset, social metadata and JSON-LD image references. The selected URL's own query parameters are preserved and escaped for their context. An empty list keeps original images. No image downloads are performed. Images may repeat across generated files.

Batch snapshots preserve the selected date and image library even if Settings changes before generation finishes. Older queued batches without the new render options retain their prior rendering behavior. Output URL domains (including trailing slash), IDs, XML Base64, downloads, descriptions approval and other v1.6.11 behavior remain.

Validation: real supplied HTML fixture, malformed OG parsing/repair, all 12 related headline spans, article summary/caption/date, image replacements including JSON-LD/query variants, 600 URL import/persistence, invalid URL rejection, random per-file selection, snapshot stability, AI-off reuse, script/body preservation, existing PHP unit suites and HTTP/MySQL/API-fixture integration. PHP lint and JavaScript syntax pass. Browser rendering and live OpenAI calls were not verified.
