# EventForge v1.8.6 — Domain Randomizer

The Generator has an Enable Domain Randomizer switch below Domain Names. Default: off.

When enabled, the TXT export keeps each existing five-URL domain group intact and independently shuffles the groups in each round. A round contains up to one group per configured domain. The final partial round/group retains exactly the URLs it would contain in serial mode. Every complete URL string, domain-to-ID pairing, requested output count, HTML filename and HTML content is preserved. Three empty lines remain between groups. Each round is independently random; a repeated order can occur by chance.

When disabled, serial output is unchanged. Empty/single-domain exports are unchanged. The choice is saved in the batch snapshot and remains in effect when resumed. Older snapshots default to off. Existing completed downloads are not rewritten; generate a new batch to use the switch. No OpenAI call is required for randomization.

Install: extract this cumulative update into public_html and overwrite application files. No reinstall or database migration. config.php, install.php and storage contents are excluded.

Validation: repeated randomized exports with 0, 1, 2, 3 and 5 domains; complete and partial rounds; exact URL and HTML-content preservation; separators; disabled behavior. Real HTTP/MySQL checks cover the dashboard switch, snapshot persistence, packaging, downloads and older snapshots. Generation UI and URL-domain regression checks passed. No browser visual rendering was performed.
