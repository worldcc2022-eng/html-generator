<?php
declare(strict_types=1);
require dirname(__DIR__).'/lib/GithubPages.php';
$id = 'coverage-watch-now-wrlwc-2026-final-jillaroos-vs-kiwi-ferns-live-livv-hd36';
$outer = ['CONNECTOR'=>'/connector-router/id/?jd='.$id, 'Type'=>'File'];
if (!function_exists('cr_gp_request_id')) throw new RuntimeException('Nested jd extraction is missing');
if (cr_gp_request_id($outer) !== $id) throw new RuntimeException('Nested jd did not preserve filename');
if (cr_gp_request_id(['jd'=>$id]) !== $id) throw new RuntimeException('Direct jd mismatch');
if (cr_gp_request_id(['id'=>$id]) !== $id) throw new RuntimeException('Legacy id mismatch');
$expected = 'https://worldcc2022-eng.github.io/november14-events-world/coverage-watch-now-wrlwc-2026-final-jillaroos-vs-kiwi-ferns-live-livv-hd36.html';
if (cr_gp_file_url($id, 'https://worldcc2022-eng.github.io/november14-events-world/') !== $expected) throw new RuntimeException('Filename destination mismatch');
foreach ([['jd'=>'../outside'], ['jd'=>[$id]], ['CONNECTOR'=>['bad']], ['jd'=>''], ['CONNECTOR'=>'/id/?jd[]=bad']] as $bad) {
    try { cr_gp_request_id($bad); } catch (InvalidArgumentException $e) { continue; }
    throw new RuntimeException('Invalid ID accepted');
}
echo "PASS: nested jd, direct jd, legacy id, exact filename, malformed IDs\n";
