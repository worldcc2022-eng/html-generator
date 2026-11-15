<?php
declare(strict_types=1);
require __DIR__.'/bootstrap.php';
require_once __DIR__.'/lib/GithubPages.php';
cr_require_admin();
$error=''; $message=''; $rows=[]; $input='';
$base = cr_gp_default_base();
if ($_SERVER['REQUEST_METHOD']==='POST') {
    cr_csrf_check();
    $input = (string)($_POST['filenames'] ?? '');
    $base = trim((string)($_POST['github_pages_base'] ?? ''));
    try {
        if (strlen($input)>200000) throw new InvalidArgumentException('Input too large');
        $lines = array_values(array_filter(array_map('trim',preg_split('/\R/u',$input)),'strlen'));
        $rows = cr_gp_map_batch($lines,$base,!empty($_POST['overwrite']));
        if (!empty($_POST['save_default'])) cr_gp_set_default($base);
        $message = count($rows).' matching file URLs created; uploaded GitHub files are unchanged.';
    } catch (Throwable $e) { $error=$e->getMessage(); http_response_code(400); }
}
cr_html_headers();
?><!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Bulk GitHub Pages links</title><link rel="stylesheet" href="assets/style.css"></head><body><main>
<div class="top"><h1>Bulk GitHub Pages Links</h1><a href="admin.php">Admin</a></div>
<p>Use the original IDs assigned by your existing HTML Generator. Paste the GitHub Pages folder containing the uploaded files. Each ID maps to exactly one <code>.html</code> file; no new IDs or files are created here.</p>
<?php if($error): ?><div class="error"><?=cr_str($error)?></div><?php endif;?>
<?php if($message): ?><div class="success"><?=cr_str($message)?></div><?php endif;?>
<form method="post"><input type="hidden" name="csrf" value="<?=cr_str(cr_csrf())?>">
<label>GitHub Pages folder URL<input type="url" name="github_pages_base" required value="<?=cr_str($base)?>" placeholder="https://mygithubpage.io/id/"></label>
<label>Generated filenames / IDs (one per line)<textarea name="filenames" rows="9" required placeholder="video-ufc-334-en-ufc-pass-tv487619.html"><?=cr_str($input)?></textarea></label>
<label class="check"><input type="checkbox" name="overwrite" value="1">Overwrite IDs previously mapped to a different folder</label>
<label class="check"><input type="checkbox" name="save_default" value="1" checked>Save this folder as the default for future batches</label>
<button type="submit">Generate matching URLs</button></form>
<?php if ($rows): ?><section><h2>Results (<?=count($rows)?> unique IDs)</h2>
<label>Copy-ready browser links<textarea readonly rows="8"><?php foreach($rows as $r) echo cr_str($r['url'])."\n"; ?></textarea></label>
<label>Copy-ready GitHub Pages file links<textarea readonly rows="8"><?php foreach($rows as $r) echo cr_str($r['github_url'])."\n"; ?></textarea></label>
<table><thead><tr><th>Filename</th><th>GitHub HTML</th><th>XML</th><th>First-party demo</th></tr></thead><tbody>
<?php foreach($rows as $r): ?><tr><td><?=cr_str($r['filename'])?></td><td class="wrap"><a href="<?=cr_str($r['github_url'])?>" target="_blank" rel="noopener noreferrer"><?=cr_str($r['github_url'])?></a></td><td><a href="<?=cr_str(rtrim(cr_config()['base_url'],'/').'/id/?jd='.rawurlencode($r['id']))?>" target="_blank" rel="noopener noreferrer">View XML</a></td><td><a href="<?=cr_str(cr_gp_demo_browser_url($r['id']))?>" rel="noopener noreferrer" target="_blank">Auto-open demo</a></td></tr><?php endforeach; ?></tbody></table></section><?php endif;?>
<p>The auto-open demo is hosted by your own file browser and does not inject JavaScript into XML.</p>
<p><a href="github-pages.php">GitHub Pages settings</a> · <a href="browser.php">Test file browser</a></p>
</main></body></html>
