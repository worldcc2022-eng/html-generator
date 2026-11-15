<?php
declare(strict_types=1);
$id = $_GET['jd'] ?? 'video-ufc-334-en-ufc-pass-tv487619';
if (!is_string($id) || !preg_match('/\A[a-z0-9][a-z0-9-]{7,179}\z/D', $id)) {
    http_response_code(400); exit('Invalid ID');
}
$connector = '/connector-router/editor-lab/connector.php?jd=' . rawurlencode($id);
$browser = 'fckeditor/editor/filemanager/browser/default/browser.html?Type=File&jd=' . rawurlencode($id) . '&Connector=' . rawurlencode($connector);
$xml = $connector . '&Command=GetFoldersAndFiles&Type=File&CurrentFolder=%2F';
$e = static fn(string $s): string => htmlspecialchars($s, ENT_QUOTES|ENT_SUBSTITUTE, 'UTF-8');
header('Content-Type: text/html; charset=UTF-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
?><!doctype html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>FCKeditor file browser test</title>
<style>
body{font:16px system-ui,sans-serif;max-width:1100px;margin:24px auto;padding:0 16px;color:#22303e}
input{padding:10px;width:min(550px,90vw)}button,a.action{display:inline-block;padding:10px 14px;border:0;border-radius:6px;background:#245c9d;color:white;text-decoration:none;cursor:pointer}label{display:block;margin:10px 0 6px}
pre{white-space:pre-wrap;word-break:break-all;background:#f4f6f9;padding:12px}iframe{width:100%;min-height:460px;border:1px solid #ccd4dd}small{color:#5a6674}
</style></head><body>
<h1>FCKeditor — File browser test</h1>
<p>Public editor and read-only GitHub Pages connector. Legacy server upload handlers remain disabled.</p>
<form method="get"><label for="jd">Generated filename ID (without .html)</label>
<input id="jd" name="jd" required value="<?= $e($id) ?>" pattern="[a-z0-9][a-z0-9-]{7,179}">
<button type="submit">Load file ID</button></form>
<p><a class="action" href="<?= $e($browser) ?>" target="_blank" rel="noopener">Open original file browser</a> 
<a class="action" href="<?= $e($xml) ?>" target="_blank" rel="noopener">Inspect connector XML</a></p>
<p><small>File list uses the GitHub Pages base URL configured in Connector Router. Missing mappings show an empty folder rather than malformed XML.</small></p>
<h2>Legacy file browser</h2><iframe src="<?= $e($browser) ?>" title="FCKeditor file browser"></iframe>
<h2>Legacy editor</h2><div id="editor-placeholder"></div>
<script src="fckeditor/fckeditor.js"></script>
<script>
(function(){
  if (typeof FCKeditor !== 'function') {
    document.getElementById('editor-placeholder').textContent = 'Editor script not found. Check the official FCKeditor installation.';
    return;
  }
  var editor = new FCKeditor('PublicTestEditor');
  editor.BasePath = 'fckeditor/';
  editor.Height = '380'; editor.Width = '100%';
  editor.Value = '<p>Public compatibility test. Edits here are not saved to the server.</p>';
  editor.Config['LinkBrowser'] = true;
  editor.Config['LinkBrowserURL'] = <?= json_encode($browser, JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_UNESCAPED_SLASHES) ?>;
  editor.Config['LinkUpload'] = false;
  editor.Config['ImageUpload'] = false;
  editor.Config['FlashUpload'] = false;
  editor.Create();
})();
</script>
</body></html>
