# EventForge v1.6.6 — multiline manual IDs

Extract into existing public_html and overwrite included files. No reinstall.

The dashboard replaces Five ID Fields with one ID Seeds textarea. Paste one seed per line (up to 1,000 unique nonblank lines; 256 KB input limit). Blank lines and exact duplicate lines are ignored. CRLF, LF and CR line endings are supported. The selected output count is distributed across the seeds, using the existing randomized suffixes and video-{event-name}-{seed}-{suffix} order. Leave blank for automatic event IDs. Saved ID Templates remain independent. Legacy API calls using id_seed_1 through id_seed_5 still work; the new id_seeds field takes precedence when present.
