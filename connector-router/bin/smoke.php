<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') exit('CLI only');
require dirname(__DIR__).'/lib/Core.php';
function verify(bool $condition, string $label): void {
    if (!$condition) { fwrite(STDERR, "FAIL: {$label}\n"); exit(1); }
    echo "PASS: {$label}\n";
}
verify(cr_valid_id('video-test-university-0001'), 'Valid generated ID');
verify(!cr_valid_id('../etc/passwd'), 'Path traversal rejected');
verify(cr_valid_host('www.ub.edu'), 'Valid external reference host');
verify(!cr_valid_host('127.0.0.1'), 'IP not accepted as external host');
verify(str_contains(cr_xml('a"<script>'), '&lt;script&gt;'), 'XML metadata safely escaped');
if (is_file(dirname(__DIR__).'/config.local.php')) {
    verify(cr_validate_destination('https://www.ub.edu/') === 'https://www.ub.edu/', 'Approved test destination');
    try { cr_validate_destination('https://unapproved.example/'); verify(false, 'Disallowed host rejected'); }
    catch (InvalidArgumentException $e) { verify(true, 'Disallowed host rejected'); }
    $id = 'video-test-university-0001';
    $existing = cr_route_get($id);
    if (!$existing) cr_route_save(['id'=>$id,'destination'=>'https://www.ub.edu/','mode'=>302,'title'=>'External destination test']);
    verify(cr_route_get($id) !== null, 'Route can be loaded');
    echo "TEST URL: ".cr_route_url($id)."\n";
} else {
    echo "Installation not detected; run php bin/install.php before integration tests.\n";
}
