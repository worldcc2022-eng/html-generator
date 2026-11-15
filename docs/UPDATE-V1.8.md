# EventForge v1.8 — multiple output domains in groups of five

Extract into existing public_html and overwrite included files. No reinstall.

Domain Names is now a multiline box, one full URL or domain per line. Empty lines and duplicate URLs are ignored; order, paths and trailing slashes are preserved. Bare domains retain the existing HTTPS default. Up to 1,000 unique domains are supported.

The output cycles through groups of five: domain A five URLs, domain B five, domain C five, then back to A. Two domains make a ten-URL cycle; three domains make a fifteen-URL cycle. Total Files & URLs remains the total output count, not a per-domain count. For example 3 domains with total 33 produce A5, B5, C5, A5, B5, C5, A3. Each line has its own unique ID and matching HTML filename. Empty domain input keeps query-only URLs; one domain prefixes all URLs as before.

Domain lists are saved with each batch. Later changes to the form do not affect that batch. Old queued batches using the single-domain field still work, and existing finished ZIP/TXT files are unchanged. XML input, Base64 encoding, descriptions, image selection, title handling, literal UTF-8 output and all v1.7.3 features are retained.
