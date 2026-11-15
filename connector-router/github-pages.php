<?php
declare(strict_types=1);
require __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/lib/GithubPages.php';
cr_require_admin();
$error = ''; $message = ''; $results = [];
$base = cr_gp_default_base(); $input = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    cr_csrf_check();
    $action = (string)($_POST['action'] ?? '');
    $base = trim((string)($_POST['github_pages_base'] ?? ''));
    try {
        if ($action === 'save_default') {
            $base = cr_gp_set_default($base);
            $message = 'Default GitHub Pages folder saved for new IDs. Existing ID mappings are unchanged.';
        } elseif ($action === 'map_files') {
            $input = (string)($_POST['filenames'] ?? '');
            if (strlen($input) > 200000) throw new InvalidArgumentException('Filename list is too large.');
            $names = array_values(array_filter(array_map('trim', preg_split('/\R/u', $input)), 'strlen'));
            $results = cr_gp_map_batch($names, $base, !empty($_POST['overwrite']));
            if (!empty($_POST['save_default'])) cr_gp_set_default($base);
            $message = 'Mapped ' . count($results) . ' unique generated file IDs. GitHub files themselves are not uploaded or checked here.';
        } else {
            throw new InvalidArgumentException('Unknown action.');
        }
    } catch (Throwable $e) {
        http_response_code(400); $error = $e->getMessage();
    }
}
cr_html_headers();
?><!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>GitHub Pages file mappings</title><link rel="stylesheet" href="assets/style.css"></head>
<body><main><div class="top"><h1>GitHub Pages File Mapping</h1><a href="admin.php">Admin</a></div>
<p>Paste the HTTPS <strong>folder URL containing your already-uploaded .html files</strong>. The Connector will use each existing generated filename/ID to construct its matching file URL. Your existing HTML Generator still uploads the actual files to GitHub.</p>
<?php if ($message): ?><div class="success"><?=cr_str($message)?></div><?php endif; ?>
<?php if ($error): ?><div class="error"><?=cr_str($error)?></div><?php endif; ?>
<section><h2>1. Default GitHub Pages folder</h2>
<form method="post"><input type="hidden" name="csrf" value="<?=cr_str(cr_csrf())?>"><input type="hidden" name="action" value="save_default">
<label>GitHub Pages folder URL<input type="url" name="github_pages_base" required placeholder="https://mygithubpage.io/id/" value="<?=cr_str($base)?>"></label>
<button type="submit">Save default folder</button></form>
<p>Supports <code>https://username.github.io/repository/id/</code> and custom domains. You can also paste a sample generated <code>.html</code> file URL; its directory is used.</p></section>
<section><h2>2. Match existing HTML filenames in bulk</h2>
<form method="post"><input type="hidden" name="csrf" value="<?=cr_str(cr_csrf())?>"><input type="hidden" name="action" value="map_files">
<label>GitHub Pages folder URL<input type="url" name="github_pages_base" required value="<?=cr_str($base)?>" placeholder="https://mygithubpage.io/id/"></label>
<label>Existing filenames or IDs (one per line)<textarea name="filenames" rows="9" required placeholder="video-ufc-334-en-ufc-pass-tv487619.html"><?=cr_str($input)?></textarea></label>
<label class="check"><input type="checkbox" name="overwrite" value="1">Replace existing mapping for IDs that are already assigned to a different folder</label>
<label class="check"><input type="checkbox" name="save_default" value="1" checked>Remember this folder as the default for new batches</label>
<button type="submit">Generate and save matching URLs</button></form></section>
<?php if ($results): ?><section><h2>Generated URLs (<?=count($results)?>)</h2>
<label>First-party browser URLs<textarea readonly rows="8"><?php foreach ($results as $r) echo cr_str($r['url'])."\n"; ?></textarea></label>
<label>Direct GitHub Pages URLs<textarea readonly rows="8"><?php foreach ($results as $r) echo cr_str($r['github_url'])."\n"; ?></textarea></label>
<table><thead><tr><th>Filename</th><th>GitHub Pages file</th><th>Connector XML</th><th>First-party demo</th></tr></thead><tbody>
<?php foreach ($results as $r): ?><tr><td><?=cr_str($r['filename'])?></td><td class="wrap"><a href="<?=cr_str($r['github_url'])?>" rel="noopener noreferrer" target="_blank">Open file</a></td><td><a href="<?=cr_str(rtrim(cr_config()['base_url'],'/').'/id/?jd='.rawurlencode($r['id']))?>" rel="noopener noreferrer" target="_blank">XML</a></td><td><a href="<?=cr_str(cr_gp_demo_browser_url($r['id']))?>" rel="noopener noreferrer" target="_blank">Auto-open demo</a></td></tr><?php endforeach; ?></tbody></table></section><?php endif; ?>
<p><strong>Auto-open demo</strong> uses your own <code>browser.php</code> and redirects to the mapped GitHub file after validating the XML. It never uses scripts inside XML metadata or third-party FCKeditor websites.</p>
<p><a href="bulk-links.php">Bulk Links</a> · <a href="browser.php">First-party browser</a>. These pages do not create scripts in third-party file browsers.</p>
</main></body></html>
