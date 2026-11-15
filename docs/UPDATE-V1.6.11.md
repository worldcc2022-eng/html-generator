# EventForge v1.6.11 — favicon policy fix

Extract into existing public_html and overwrite included files. No reinstall.

The supplied Surprise Sports favicon was blocked by the shared Content Security Policy, which allowed only same-origin images and data URLs. The image policy now explicitly permits https://surprisesports.com/favicon.png. The favicon link adds a fixed version query to refresh cached failures. Other policy directives remain unchanged.

Verified the remote endpoint returns HTTP 200 image/png and tested actual PHP HTTP response headers and favicon markup.
