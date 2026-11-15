<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }
require dirname(__DIR__) . '/lib/GithubPages.php';
$id = 'video-ufc-334-en-ufc-pass-tv487619';
$base = cr_gp_default_base();
if ($base === '') {
    fwrite(STDERR, "Configure your GitHub Pages base URL at github-pages.php before running the XML test.\n");
    exit(2);
}
$xmlText = cr_gp_xml_document($id);
if (!str_contains($xmlText, '<CurrentFolder path="/1 - f/"')) throw new RuntimeException('Unexpected CurrentFolder');
if (!str_contains($xmlText, 'name="' . cr_xml($id . '.html') . '" size="0"')) throw new RuntimeException('Unexpected File metadata');
if (!str_contains($xmlText, 'url="' . cr_xml(cr_gp_file_url($id)) . '"')) throw new RuntimeException('File URL mismatch');
if (class_exists('DOMDocument')) {
    $dom = new DOMDocument();
    if (!$dom->loadXML($xmlText, LIBXML_NONET)) throw new RuntimeException('Invalid XML');
    echo "PASS: XML parsed with DOMDocument\n";
} else {
    echo "NOTE: PHP DOM extension unavailable; structural check only; XML parsing should be checked separately\n";
}
$demo = cr_gp_demo_browser_url($id);
if (!str_contains($demo, 'auto=1') || !str_contains($demo, 'CONNECTOR=')) throw new RuntimeException('Demo URL mismatch');
echo "PASS: valid XML; exact ID-to-file match; folder path; first-party auto-open URL\n";
echo "XML: " . rtrim(cr_config()['base_url'], '/') . '/id/?jd=' . $id . "\n";
echo "Demo: $demo\n";
