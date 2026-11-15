# EventForge v1.7.2 — restore literal UTF-8 titles

Extract into existing public_html and overwrite included files. No reinstall. Generate a new batch.

Removes the v1.7.1 numeric-entity conversion and automatic encoding repair from title import, title rendering and HTML output. Saved Unicode text is used as supplied, with placeholder substitution and ordinary HTML escaping only. No attempt is made to reinterpret or rewrite characters. The obsolete TextEncoding compatibility helper is also replaced with pass-through behavior. All v1.7 article, date, caption, image and metadata fixes remain. Existing ZIPs are not rewritten.
