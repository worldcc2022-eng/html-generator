# EventForge v1.6.4 — manual ID prefix order

Extract into existing public_html and overwrite included files. No reinstall.

Manual/automatic IDs now follow video-{event-slug}-{manual-seed}-{random-suffix}. Example: watch-livv-on-tv-uk2 for PSG vs Barcelona becomes video-psg-vs-barcelona-watch-livv-on-tv-uk2-ywnh. If the supplied seed already starts with video- or contains the full event slug, those parts are moved to the front without duplication. All separators remain dashes. Saved ID templates retain their explicit placeholder placement. Existing batches are preserved; generate a fresh batch.
