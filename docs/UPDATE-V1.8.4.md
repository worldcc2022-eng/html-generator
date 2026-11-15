# EventForge v1.8.4

- Center the Generate Files & URLs label; keep its arrow at the right edge.
- Show a twelve-segment Apple-style activity spinner in the button and progress area during generation. Disable duplicate generation attempts and restore controls on success or failure.
- Open a polished dark success dialog only after a batch completes, with its actual file count and both download links. Dismiss using Done, Escape, or the backdrop. Downloads remain available in the main panel.
- Refresh Recent Batches via authenticated, CSRF-protected AJAX after batch creation, progress and completion. Refresh every 15 seconds while the page is visible, and when returning to it.
- Keep dynamically inserted Resume buttons working. Clearing history refreshes the table without reloading and preserves downloads and template usage history.
- Keep history refresh errors separate from generation errors; retry on the next refresh.

Extract this cumulative update into public_html and overwrite the existing application files. No reinstall or database migration is needed. Existing config.php and storage files are not included and stay in place.

Validation: PHP/JavaScript syntax checks, real HTTP/MySQL checks of history authorization/CSRF/user isolation/pending and completed batches/clear/download preservation, JavaScript interaction checks for success/failure/resume/history recovery, and title/renderer/domain regression checks. No browser visual rendering was available in this environment.
