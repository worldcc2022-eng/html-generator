# EventForge v1.6.8 — dashboard cleanup and clear history

Extract into existing public_html and overwrite included files. No reinstall.

Removed the marked dashboard eyebrow, introductory subtitle, PHP/AI badge and locked-setup banner, plus the requested example/helper text. Generator, Settings and Sign Out now use bordered buttons with compact mobile spacing.

Recent Batches adds Clear History using an authenticated CSRF-protected POST request. It hides the current user's existing batches from the recent list persistently and updates the table without a reload. It does not delete generated files, change other users' history, cancel running jobs, or reset title/ID usage history. A batch generated afterwards appears normally. Existing authorized download links keep working. No new database schema is needed.
