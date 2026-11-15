<?php
declare(strict_types=1);
/** Public read-only connector; returns only validated filename metadata. */
header('Content-Type: text/xml; charset=UTF-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');

function labEsc(string $v): string {
    return htmlspecialchars($v, ENT_XML1 | ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}
function labXml(string $command, string $type, string $base, ?string $name = null): string {
    $xml = '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
    $xml .= '<Connector command="' . labEsc($command) . '" resourceType="' . labEsc($type) . '">' . "\n";
    $xml .= '  <CurrentFolder path="/" url="' . labEsc($base) . '"/>' . "\n";
    $xml .= '  <Folders/>' . "\n";
    if ($command === 'GetFoldersAndFiles') {
        $xml .= '  <Files>';
        if ($name !== null && $base !== '') {
            $xml .= '<File desc="1" name="' . labEsc($name) . '" size="0"/>';
        }
        $xml .= '</Files>' . "\n";
    }
    return $xml . "</Connector>\n";
}
$command = (string)($_GET['Command'] ?? 'GetFoldersAndFiles');
$type = (string)($_GET['Type'] ?? 'File');
$folder = (string)($_GET['CurrentFolder'] ?? '/');
$id = $_GET['jd'] ?? '';
if (!is_string($id) || !preg_match('/\A[a-z0-9][a-z0-9-]{7,179}\z/D', $id)) {
    http_response_code(400);
    echo '<Connector><Error number="1" text="Invalid ID"/></Connector>';
    exit;
}
if (!in_array($command, ['GetFolders', 'GetFoldersAndFiles'], true)
    || !in_array($type, ['File', 'Document'], true) || $folder !== '/') {
    http_response_code(400);
    echo '<Connector><Error number="1" text="Unsupported command"/></Connector>';
    exit;
}
$root = dirname(__DIR__);
if (!is_file($root . '/bootstrap.php') || !is_file($root . '/lib/GithubPages.php')) {
    http_response_code(503);
    echo '<Connector><Error number="1" text="Connector Router not installed"/></Connector>';
    exit;
}
require_once $root . '/bootstrap.php';
require_once $root . '/lib/GithubPages.php';
try {
    $base = (string)cr_gp_base_for_id($id);
    // No mapping yet: return valid empty XML, not an HTML error page.
    if ($base === '') { echo labXml($command, $type, ''); exit; }
    echo labXml($command, $type, $base, $id . '.html');
} catch (Throwable $e) {
    // Return valid empty listing to the legacy XML parser.
    echo labXml($command, $type, '');
}
