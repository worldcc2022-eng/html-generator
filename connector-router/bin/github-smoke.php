<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }
require dirname(__DIR__).'/lib/GithubPages.php';
function cr_gp_assert(bool $test, string $label): void {
    if (!$test) throw new RuntimeException('FAIL: '.$label);
    echo 'OK: '.$label."\n";
}
$id = 'video-ufc-334-en-ufc-pass-tv487619';
$base = 'https://sample.github.io/events/id/';
cr_gp_assert(cr_gp_normalize_base($base)===$base, 'GitHub Pages directory accepted');
cr_gp_assert(cr_gp_normalize_base($base.$id.'.html')===$base, 'Sample file URL accepted');
cr_gp_assert(cr_gp_id($id.'.html')===$id, 'Existing filename ID kept');
cr_gp_assert(cr_gp_file_url($id,$base)===$base.$id.'.html', 'Direct GitHub URL matches filename');
cr_gp_assert(str_contains(cr_gp_browser_url($id),'CONNECTOR='), 'First-party browser URL contains connector');
foreach (['http://example.org/id/', 'https://127.0.0.1/id/', 'https://example.org/id/?x=1', 'https://example.org/../id/'] as $bad) {
    try { cr_gp_normalize_base($bad); throw new RuntimeException('Unsafe URL accepted: '.$bad); }
    catch (InvalidArgumentException $e) { echo "OK: rejected invalid folder URL\n"; }
}
echo "GitHub Pages mapping self-test passed. Existing data was not changed.\n";
