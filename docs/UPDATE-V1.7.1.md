# EventForge v1.7.1 — Unicode-safe generated titles

Extract into existing public_html and overwrite included files. No reinstall. Generate a fresh batch; existing ZIPs keep their old contents.

Inserted title and description characters above ASCII now use numeric HTML entities. Browsers decode these to the intended mathematical bold, fullwidth and multilingual characters. This protects generated field text from being interpreted through an incorrect external character encoding. The underlying title-bank database connection remains utf8mb4.

Title import and rendering also try conservative, reversible repairs for encoding corruption: a reverse conversion is accepted only when it round-trips exactly, produces valid UTF-8 without replacement/control characters and restores more mathematical/fullwidth letters. Both entire text and non-ASCII runs are checked so English prefixes remain unchanged. Correct Unicode titles and ordinary multilingual content are preserved in the tested cases. Stored templates and prior job snapshots are not rewritten in bulk. If original characters have already been lost (for example replacement question marks), the original UTF-8 title bank is needed; the patch does not guess missing text.

The screenshot alone does not establish the original corruption point. Local correct UTF-8 input was preserved before this patch. Tests cover lossless recovery examples and numeric-entity protection rather than claiming access to the user's deployed database or file bytes.

Verified PHP lint, all PHP suites, fullwidth/mathematical Unicode preservation, mixed English and recoverable corrupted runs, legitimate multilingual text, entity round-trip equality, and real HTTP/MySQL import-to-ZIP generation with Unicode templates. The existing v1.7 renderer, description/date/image and application regressions also pass.
