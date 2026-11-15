# EventForge v1.6.5 — output URL domain

Extract into existing public_html and overwrite included files. No reinstall.

Dashboard adds Domain Name (Optional) above XML URL. Enter https://example.com or example.com; bare names default to HTTPS. Trailing slashes are removed. A path is supported if needed, but query strings, fragments and login details are rejected. Domain is stored in the batch snapshot and prepended to each TXT URL: https://example.com?&xml=data:video/mp4;base64,{BASE64}&id={ID}. ID has no .html. HTML ZIP contents, Event URL redirect and XML include URL are independent and unchanged.

Leave the domain blank to retain query-only URLs. Older queued batches without the domain field continue working. Existing finished batches keep their existing TXT; generate a new batch to use the new field.
