# EventForge v1.8.9 — Github Connect

Adds a separate Github Connect card on the left side of Settings, with Repository URL, Branch (default main), Generate Deploy Key, Copy Public Key, Open Deploy Keys, Connect, and persisted connection status with the last successful check in Bangladesh time. This release prepares repository access; generated-file uploads are a separate feature.

## Setup

1. Extract into public_html and overwrite included files. No reinstall or database schema change. Existing config.php, storage and generated batches are excluded.
2. Open Settings → Github Connect. Enter the HTTPS repository URL and an existing branch.
3. Click Generate Deploy Key. Copy the public key or open the repository's Deploy Keys page using the provided link.
4. In GitHub, add the public key under repository Settings → Deploy keys and enable Allow write access.
5. Return to EventForge and click Connect. Successful checks display Connected — ready to upload. Existing keys are reused for the same repository; a different repository gets its own key. Adding a key to GitHub remains a manual step and requires repository administration access.

Server requirements: PHP 8.1+ with cURL, enabled proc_open, Git, OpenSSH ssh and ssh-keygen in /usr/bin, /usr/local/bin or /bin; outbound HTTPS to api.github.com and SSH to github.com on port 22. An empty repository must first be initialized with a README or other commit on the selected branch.

## Connection and key handling

Private keys are encrypted using the existing application AES-GCM helper and stored in eg_settings; keep the existing config.php secret. Private keys never enter HTML or JSON responses. Transient SSH files use a random 0700 system temporary directory and 0600 key files and are removed after checks, including ordinary failures. Generate and Connect use login, POST, CSRF and serialized database updates. No server shell interpolation is used for repository inputs; only validated github.com repository URLs are accepted.

GitHub SSH host keys come from GitHub's HTTPS metadata endpoint with TLS verification; SSH requires strict host-key verification and ignores ambient SSH identities/configuration. Commands have time/output limits. Connect checks the selected branch with ls-remote, then performs an existing-SHA push dry run to verify that GitHub permits the key to access its receive-pack service. It does not create commits, update refs, clone the repository or upload files. A dry run cannot guarantee that all branch rules or hooks will accept a future new commit. Connected status records the last successful check, not continuous monitoring. Changed fields invalidate the displayed status; attempted failures persist disconnected status.

## Verification

All new connector, real-Git dry-run, settings rendering and JavaScript interaction tests pass. They cover URL/branch validation, real Ed25519 generation, encrypted-state boundaries, public-key reuse, separate keys for repositories, missing keys, private response exclusion, process exit/timeout, temporary permissions and failure cleanup, AJAX key generation/connect/error/copy/status changes, and unchanged remote refs after the no-clone dry run. Existing generation UI, title/ID bank, keyword-template, Unicode, template renderer, manual count and random-ID tests pass. All PHP and application JavaScript syntax checks pass. Independent code review found no material issues.

Broader suite limitations in this environment: descriptions.php and update.php need the unavailable storage/template.html fixture; run.php, domain-randomizer.php, multiple-domains.php and url-domain.php need the unavailable PHP ZipArchive extension. Live GitHub authorization and MySQL locking were not exercised. A desktop/mobile render was prepared, but browser screenshot verification was unavailable because Chromium is not installed. Complete the live Connect check on the installation server after adding the generated public key to GitHub.
