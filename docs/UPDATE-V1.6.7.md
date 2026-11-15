# EventForge v1.6.7 — preserve supplied URL slash

Extract into existing public_html and overwrite included files. No reinstall.

The Domain Name field now preserves the supplied URL path and trailing slash. For example https://example.com/uploaded/virtual-tour/ produces https://example.com/uploaded/virtual-tour/?&xml=...&id=... . A URL without a trailing slash remains without one. Bare domains still receive https:// and blank input still produces query-only URLs. Existing validation remains. Existing generated batches keep their earlier URL snapshot; generate a new batch after updating.
