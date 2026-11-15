<?php
declare(strict_types=1);
require __DIR__.'/bootstrap.php';
cr_no_cache();
header('Content-Type: text/html; charset=UTF-8');
header("Content-Security-Policy: default-src 'none'; script-src 'self'; style-src 'self'; connect-src 'self'; img-src 'self'; frame-src 'self' https:; base-uri 'none'; form-action 'none'; frame-ancestors 'self'");
$base = rtrim(cr_config()['base_url'], '/');
?><!doctype html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>First-party Event File Browser</title><link rel="stylesheet" href="assets/style.css"><link rel="stylesheet" href="assets/browser-extra.css"></head>
<body><main>
<div class="top"><h1>Event File Browser</h1><a href="admin.php">Admin</a></div>
<p>Reads a valid XML listing from this site's own connector. The generated filename is preserved as the event ID.</p>
<div id="browser-status" role="status" aria-live="polite">Loading file list…</div>
<table><thead><tr><th>Filename</th><th>Description</th><th>Action</th></tr></thead><tbody id="file-results"></tbody></table>
<h2>Selected page preview</h2>
<p>ID links open their matching page automatically. Add auto=0 to the browser URL to use this preview instead.</p>
<iframe id="event-preview" title="Event page preview" sandbox="allow-scripts" referrerpolicy="no-referrer" loading="lazy" class="connector-preview"></iframe>
</main><script src="assets/browser.js?v=20261115-id" defer></script></body></html>
