# EventForge v1.8.8 — Skip duplicate template words

The main event name remains exactly as entered. If it contains a standalone Free, Stream, Streaming, Livestream, Livestreaming, Online, Live or Watch, the matching word is skipped in saved title and ID template literals before placeholders are filled. Matching ignores case and recognizes common bold, italic and fullwidth Latin styling. Different words and partial matches remain unchanged. Stored templates and their history are not edited; only newly generated outputs use this behavior. IDs retain the existing lowercase slug conversion and dashed formatting.

Example: event `Chelsea vs Arsenal Free`, title template `{event_name} Free Stream` gives `Chelsea vs Arsenal Free Stream`; ID template `video-{event_name}-free-stream` gives `video-chelsea-vs-arsenal-free-stream`.

Overwrite included files in public_html and generate a new batch. No reinstall or database change is needed.

Verification: the original duplication was reproduced by a failing test before the change. All ten new regression cases, title-bank, id-bank, text-encoding, template-renderer, manual-count and random-ID tests pass, and all PHP syntax checks pass. Other existing suites were attempted: descriptions.php and update.php require the absent storage/template.html fixture; run.php, domain-randomizer.php, multiple-domains.php and url-domain.php require the unavailable PHP ZipArchive extension. Full database/HTTP generation was not tested in this environment.
