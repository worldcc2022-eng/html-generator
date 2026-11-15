<?php
declare(strict_types=1);
require dirname(__DIR__) . '/bootstrap.php';
require_once dirname(__DIR__) . '/lib/BulkLinks.php';
$id = 'video-ufc-334-en-ufc-pass-tv487619';
$link = cr_bulk_browser_url($id . '.html');
$expectedPrefix = rtrim(cr_config()['base_url'], '/') . '/browser.php?Type=File&CONNECTOR=';
if (!str_starts_with($link, $expectedPrefix)) throw new RuntimeException('Browser URL prefix mismatch');
$q = (string)parse_url($link, PHP_URL_QUERY);
parse_str($q, $params);
if (($params['CONNECTOR'] ?? '') !== rtrim(parse_url(cr_config()['base_url'], PHP_URL_PATH), '/') . '/id/?jd=' . $id) {
    throw new RuntimeException('ID/connector mismatch');
}
echo "PASS: filename unchanged; ID embedded in same-origin connector; valid browser query\n";
echo "Example: $link\n";
