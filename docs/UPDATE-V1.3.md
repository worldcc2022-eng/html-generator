# EventForge v1.3 — randomized filename suffixes

Extract into the existing application document root and overwrite included application files. No reinstall or database migration. No config.php, installer, lock, template, or storage files are included. This cumulative update retains prior description approval and five-ID features.

New batches use varied suffixes, mixing streaming words and random numbers with random 3–8 letter strings. Examples: _liv, _onlinn, _us17, _ustv3, _ghvf, _ghdvhg. The application no longer generates _ustv1, _ustv2, _ustv3 sequences. Old streaming suffixes at the end of an input seed are replaced. Seed allocation is shuffled but remains balanced across the filled fields. IDs and suffixes are unique within each batch; each new batch uses fresh random choices.

Every HTML filename remains exactly {URL id}.html. URL IDs have no extension. Previously generated and queued batches retain their existing ID snapshots. Generate a new batch to get the new randomized IDs.

Verified: counts 10–100, duplicate seeds, five-field distribution, random suffix variety, ZIP/TXT filename pairing and all existing generator/approval/upload/authentication regressions. Live OpenAI and browser rendering were not retested; controlled provider fixtures were used for integration checks.
