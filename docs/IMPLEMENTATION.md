# Event File Generator

Goal: Installable standalone PHP application for cPanel Apache/LiteSpeed, extracted at the document root. Native implementation authorized by user.

Architecture: PHP 8.2+, PDO MySQL, sessions, cURL, OpenSSL and ZipArchive; no Composer, Node, cron, or workers. Installer connects to an existing user-supplied database, creates application tables and admin, writes guarded config.php, locks setup. Red #E50914, backgrounds #141414/#0D0D0F, text #F5F5F7.

Constraints: PHP creates all titles/IDs; OpenAI creates only descriptions. Modify only title elements and title/description meta content; preserve all remaining source bytes including scripts, body and structured data. Uploaded example is bundled as a replaceable template, never executed. XML wrapper and query format follow supplied examples exactly. Generate 10..100 files, 30% full-name and dated title slots. Reject ambiguous numeric dates unless a numeric-order choice is supplied. No invented dates. Short names use local aliases with optional override.

Tasks:
- [x] Test and implement generator: date validation, exact Base64/XML URL, deterministic-safe IDs, proportions, uniqueness, metadata-only editing, ZIP/TXT pairing.
- [x] Implement installer, persistent configuration, password hashing, session/CSRF protection, encrypted API settings, throttled login and authenticated downloads.
- [x] Implement themed installer/login/dashboard/settings, resumable batches of ten descriptions and atomic completed downloads.
- [x] Verify PHP syntax, generator tests, database installation and lock, authentication, CSRF and downloads with local HTTP/API fixture; package release.

Review focus: malformed source metadata must be identified without changing scripts; ambiguous/impossible dates must fail; setup must never overwrite installed accounts; API failure must preserve completed batches without partial downloads; downloads must require owner session.

Defaults: ID stem video-{event-slug}-live, suffix _ustv1..N, optional stem override. Dates use English names and numeric DMY/MDY explicitly. No attempt to infer unknown sports or player aliases through OpenAI. API model configurable; default gpt-4.1-mini. User asked to keep everything else unchanged, so existing redirect/branding/content are preserved without evaluating them.

Verification: generator suite passed for counts 10..100, exact Base64 example, numeric/text/impossible/ambiguous dates, raw script/comment/body preservation, ZIP/TXT pairing. HTTP integration passed with MariaDB 10.11 and local Responses API fixture: installation, config/lock, CSRF, login/passwords/throttling, encrypted settings, batch resume, duplicate output rejection, owner-only downloads, no partial downloads and reinstall refusal. Browser rendering could not be tested: no browser executable and the browser download returned an invalid archive. Live OpenAI and the user’s server are not accessible without deployment credentials.
