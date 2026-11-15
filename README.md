# EventForge 1.5.2

## Install on cPanel / LiteSpeed

1. Select PHP 8.2 or newer for the subdomain. Enable PDO MySQL, cURL, OpenSSL, mbstring and ZIP.
2. In cPanel, create an empty MySQL/MariaDB database and database user. Assign the user **All Privileges** for that database. Include cPanel prefixes in the names.
3. Extract this ZIP directly into the subdomain's `public_html` / document root. `index.php`, `install.php`, `.htaccess`, `app/`, `assets/` and `storage/` must be at the root, not in another folder. Enable viewing hidden files to see `.htaccess`.
4. Open `https://your-domain/install`. Enter database host, port, name, user, password; choose an admin username and a password of at least 12 characters. The installer creates tables in your existing database. It does not create a cPanel database or database user.
5. Sign in at `/login`. Delete **install.php** using File Manager. Setup is already disabled by config.php and storage/installed.lock.
6. Open **Settings** and enter your OpenAI API key. Only descriptions use the API, when enabled on the dashboard. Model ID is editable; default is `gpt-4.1-mini`, requiring Responses API and Structured Outputs access on your account. Keys are encrypted in the database using a random key in guarded config.php. Back up config.php securely with the database.
7. Your supplied template is bundled. You may replace it in Settings using an HTML, HTM or TXT file (UTF-8, up to 3 MB).

Requires Apache/LiteSpeed with mod_rewrite and AllowOverride for the included `.htaccess` rules. Nginx needs equivalent routing and deny rules; this package targets the requested cPanel Apache/LiteSpeed setup. The application assumes installation at the root of a dedicated subdomain, not a subdirectory. Use HTTPS. Config is written mode 0600; PHP must run as the cPanel account owner (normal cPanel setup). Do not make directories world-writable.

## Generate

Enter the event, XML URL, Event URL, event date, manually entered file count (1–1,000), and optional ID seeds. PHP selects titles from your saved title bank and creates IDs and Base64. Save templates in Settings before generating.

The dashboard's **Generate Description Using OpenAI** toggle defaults to off. With it off, uploaded description metadata is preserved exactly and no API key or provider call is needed. With it on, click **Generate Description**, review the full text and word count, then choose **Approve Description** or **Regenerate Description**. The maximum is 100 words, enforced server-side. The description is based on the event name and selected date; no live event facts are looked up.

One approved description is reused across every file and future batches for that event/date, without another API call. Approval survives reloads; switch the toggle on to load it. Changing event/date requires a matching approval. Successful regeneration invalidates the previous approval; failed regeneration preserves it. File generation is blocked until a current draft is approved. Existing generated batches keep their original snapshots.

Click **Generate Files & URLs** after approval (or directly in template mode). Download ZIP and TXT separately. Batch generation itself does not call OpenAI for new batches. Older pending batches from before v1.2 still resume their original per-file description workflow.

The optional ID Prefix control overrides filename stems. Title wording comes entirely from Settings → Title Templates. Each line must contain {event_name}; optional {date} (or {event_date}) inserts the selected date. There is no automatic sport/short-name wording or forced date percentage. Save those variations in your own template bank.

IDs default to `video-{event-slug}-live_{random-suffix}`. Each batch mixes short streaming tokens (liv, onlinn, us, ustv, online, tv, watch, stream, live, hd, onair, cast), sometimes with a random number, and random 3–8 letter strings. About 40% are streaming tokens and 60% random letters, shuffled in the output. There is no sequential counter. The generator prevents duplicate IDs and suffixes within a batch and creates fresh random choices for new batches. Downloads remain in batch storage; back up or remove old storage batch directories together with their eg_jobs database records as needed.

## Date input

English month-name dates (31 October 2026, October 31, 2026, 31 Oct 2026), ISO dates (2026-10-31), and numeric day/month or month/day formats with slash, dash or dot separators are supported. You may omit the year, for example 31 October or 31/10. The event keyword year is used when present; otherwise the current year in Asia/Dhaka is used. Impossible dates fail. Ambiguous `03/04/2026` requires choosing numeric date order. This cannot accept literally every language or arbitrary free-text date string. Titles include the date only where your saved pattern contains {event_date}.

## Exact XML and URL format

Input: `spor14.github.io/mcq/cast.xml`

Encoded bytes (backslash retained exactly as supplied):

```xml
<krpano><include url="/\/spor14.github.io/mcq/cast.xml"/></krpano>
```

TXT lines:

```text
?&xml=data:video/mp4;base64,{BASE64_XML}&id={ID}
```

The URL `id` has **no `.html` extension**. ZIP entries are `{ID}.html`. These are query suffixes, matching the supplied examples, not complete URLs with an added host. Base64 is left as standard Base64 to match the requested structure, not converted to Base64URL. A consuming URL parser must preserve literal `+` in that value; parsers that treat `+` as a space must percent-encode it before transmission or decode the raw query correctly.

Your existing XML loader and JavaScript are not regenerated or edited: the supplied remote cast.xml remains where you host it. The generator creates the include wrapper, HTML ZIP and URL TXT. No XML loader deployment, external publishing or scheduling is performed.

## Exactly what changes in HTML

The editor replaces text inside `<title>` and content values of meta tags named/property `title`, `og:title`, `twitter:title`, `x:title`, `description`, `og:description`, `twitter:description`, `x:description`. It recognizes the supplied malformed `og:description"` tag and unnamed description meta tags beginning with the exact old document title plus a comma. These existing malformed tag attributes are preserved; only their content values change. New missing meta tags are not inserted. For standard metadata markup, upload a corrected template yourself.

The Event URL field replaces only the destination string in the existing Googlebot bypass redirect. The rest of the uploaded file remains byte-for-byte intact: line endings, body/headings, remaining embedded JavaScript, JSON-LD, images, third-party branding and links. The source template has unrelated pre-existing sports content; that remains per the requested scope. The selected count is the total across all filled ID fields. Five filled fields and count 50 produce ten variants per field, randomly distributed in the URL list. The fields accept filename-safe IDs, without .html. Old trailing streaming suffixes such as _ustv1 are replaced with a varied random suffix; other seed text is retained as the filename stem. Blank fields are skipped. Duplicate seeds still produce unique filenames. The source is never executed or previewed by the generator. Metadata uses HTML escaping for quotes, angle brackets and ampersands.

## Security / operations

Passwords use PHP password_hash; login is throttled by IP; sessions expire after two hours of inactivity; POST actions require CSRF tokens; downloads require an authenticated batch owner; template/config/batch storage is denied direct access by .htaccess. Root routing also denies private files. API keys are never returned in forms or errors. Encryption protects database-only exposure; anyone with config.php plus the database can decrypt the key. Treat both as secrets.

Installer refuses to overwrite any database containing an existing eg_users admin, even if install.php is restored. For an intentional fresh installation use a new empty database and a fresh extraction. Do not delete configuration/locks on a production installation.

## Checks

Run `php tests/run.php` for generator tests. HTTP/database checks were performed separately against a disposable test database and a local API fixture. No development credentials or fixture overrides are included in this package. A real OpenAI request still needs your dashboard key and API credits. New description output is validated as nonempty plain text with a maximum of 100 whitespace-separated words. Legacy pending-job output retains its original count, duplicate and character-length checks. API failures do not silently generate fallback descriptions or release partial downloads.

OpenAI reference: https://developers.openai.com/api/docs/guides/structured-outputs

## Saved template details

New uploads record the original filename and upload timestamp, displayed in Bangladesh time. A versioned source file and an atomic database setting select the active upload; saving the API key alone does not reset it. Previously uploaded files remain available but their original names/times cannot be reconstructed from v1.0. Re-upload once to record those details.

## Manual output count

The count field is a required numeric input, not a dropdown. Enter any whole number from 1 to 1,000. The exact count determines both ZIP entries and URL lines. Counts are split across filled ID seeds with at most one file difference when division is uneven. Titles are sampled without repeats from your saved template bank; add more templates when a keyword exhausts the bank. ZIP contents are staged on disk and cleaned after finalization to reduce PHP memory usage for larger batches.

## Saved title bank

See docs/UPDATE-V1.5.md for the full workflow. Settings accepts pasted templates and UTF-8 TXT uploads, one title per line using {event_name}. A 100,005-title list was tested through chunked upload. Add or replace the bank; used-title history remains. The generator selects only saved wording and never silently falls back to built-in patterns or repeats a used title for the same normalized keyword. Server-side transactions protect concurrent batches; failed attempts do not consume titles. Back up the MySQL eg_title_entries, eg_title_used and eg_settings tables along with existing application tables.

## Event type placeholder

Use {event} for the dashboard Event Type value (Boxing, Football, Tennis, etc.). Manual Event Type overrides the dropdown when filled. {event_name} remains the actual event keyword; {date} and {event_date} remain the selected calendar date. Existing literal sport words in templates are not automatically rewritten; replace them with {event} yourself.

## Saved ID templates (v1.6)

See docs/UPDATE-V1.6.md. Save ID patterns in Settings with {event_name}; the event becomes lowercase with dashes. Select Saved ID Templates on the dashboard to use exact patterns randomly without repeats for that keyword.

## Article rendering and image URLs (v1.7)

See docs/UPDATE-V1.7.md. New batches synchronize article summaries/captions and malformed Open Graph descriptions, format article dates, randomize related headline titles, and optionally use a saved image URL library.
