# EventForge v1.4 — manual output count

Extract into the existing public_html and overwrite included files. No reinstall or database migration. This cumulative update excludes config.php, installer and all storage/template files.

Replaces the 10/20/...100 dropdown with a manual whole-number input, 1–1,000 per batch. Counts need not be multiples of ten. The exact count is used for HTML files and URL lines. Invalid, fractional, negative, zero and above-limit values are rejected on the server. Filled ID fields share the count as evenly as possible. Small title/date proportions are rounded. Random suffixes, saved template metadata and optional AI approval flow are preserved.

ZIP creation stages each HTML file on disk and removes temporary parts after finalization to reduce memory use for larger batches. Title generation supports counts through 1,000 without exhausting the previous structural-variation restriction.

Checks cover 1, 7, 13, 35, 125, 250 and 1,000 rows with unique titles/IDs, uneven seed splits, exact downloaded 13-file ZIP/TXT counts, validation and existing generator, approval, installer, upload and download tests. API integration uses controlled provider fixtures. Live OpenAI calls and browser rendering were not reverified.
