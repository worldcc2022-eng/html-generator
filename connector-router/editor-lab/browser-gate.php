<?php
declare(strict_types=1);
/**
 * Public compatibility gate for the stock FCKeditor file browser.
 * It permits only this application's read-only, same-origin XML connector.
 * The original browser.html URL is retained for relative JS and CSS paths.
 */
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');

$id = $_GET['jd'] ?? 'video-ufc-334-en-ufc-pass-tv487619';
if (!is_string($id) || !preg_match('/\A[a-z0-9][a-z0-9-]{7,179}\z/D', $id)) {
    http_response_code(400);
    exit('Invalid generated file ID');
}
$connector = '/connector-router/editor-lab/connector.php?jd=' . rawurlencode($id);
$expected = 'Type=File&jd=' . rawurlencode($id) . '&Connector=' . rawurlencode($connector);
$actual = $_GET['Connector'] ?? null;
$queryOk = isset($_GET['Type'], $_GET['jd'], $_GET['Connector'])
    && $_GET['Type'] === 'File'
    && $_GET['jd'] === $id
    && is_string($actual)
    && hash_equals($connector, $actual)
    && count($_GET) === 3;

if (!$queryOk) {
    // Never copy a user-provided connector URL into the canonical location.
    header('Location: /connector-router/editor-lab/fckeditor/editor/filemanager/browser/default/browser.html?' . $expected, true, 302);
    exit;
}
$file = __DIR__ . '/fckeditor/editor/filemanager/browser/default/browser.html';
if (!is_file($file) || !is_readable($file)) {
    http_response_code(503);
    header('Content-Type: text/plain; charset=UTF-8');
    exit('FCKeditor browser files are missing. Verify the FCKeditor installation.');
}
header('Content-Type: text/html; charset=UTF-8');
readfile($file);
