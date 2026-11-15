# EventForge v1.8.7 — Correct randomized domain batches

Fixes the Domain Randomizer's batch interpretation. With the switch on, each full batch contains exactly one URL per configured domain. Nine domains produce nine URLs per batch, in independently shuffled order. Three blank lines separate batches. Repeat until the requested total is reached; the last batch may be shorter. Domain/ID URLs are constructed one per domain per round, then complete URL lines are shuffled within that round.

The previous v1.8.6 implementation incorrectly retained five consecutive URLs per domain and shuffled five-URL groups. That behavior is removed from randomizer mode. With the switch off, the established serial mode of five consecutive URLs per domain remains unchanged. HTML content, filenames, total output count, XML/Base64 construction and description generation are unchanged.

Repeated random orders may coincide by chance. No fixed permutation or alternating pattern is imposed. Randomization uses Fisher–Yates with random_int for every batch.

Extract into public_html and overwrite application files. No reinstall or schema change. config.php, install.php and storage contents are excluded. Enable Domain Randomizer and generate a new batch; completed ZIP/TXT downloads are not rewritten.

Validation: regression test reproduced the original failure before the fix. Repeated unit exports now pass for nine domains with 18, 100 and 7 outputs, plus other domain counts. Tests check one domain occurrence per round, exact total/IDs, separators, unchanged HTML content, random order variation and disabled serial compatibility. Real HTTP/MySQL checks verify nine-domain export batches, final partial batch, snapshot/resume behavior, downloads and old snapshots. PHP syntax and generation UI regressions passed.
