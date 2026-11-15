<?php
declare(strict_types=1);
require __DIR__.'/bootstrap.php';
require_once __DIR__.'/lib/GithubPages.php';
cr_no_cache();
header('Content-Type: application/json; charset=UTF-8');
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405); header('Allow: POST');
    echo json_encode(['error'=>'POST required']); exit;
}
$headers = function_exists('getallheaders') ? getallheaders() : [];
$auth = (string)($_SERVER['HTTP_AUTHORIZATION'] ?? $headers['Authorization'] ?? $headers['authorization'] ?? '');
if (!preg_match('/\ABearer ([a-f0-9]{64})\z/D', $auth, $m) || !hash_equals((string)cr_config()['api_token'], $m[1])) {
    http_response_code(401); echo json_encode(['error'=>'Unauthorized']); exit;
}
try {
    if ((int)($_SERVER['CONTENT_LENGTH'] ?? 0) > 2097152) throw new InvalidArgumentException('Payload too large');
    $raw = file_get_contents('php://input', false, null, 0, 2097153);
    if ($raw === false || strlen($raw) > 2097152) throw new InvalidArgumentException('Payload too large');
    $payload = json_decode($raw, true, 64, JSON_THROW_ON_ERROR);
    if (!is_array($payload) || !is_array($payload['filenames'] ?? null) || !array_is_list($payload['filenames'])) {
        throw new InvalidArgumentException('Provide filenames array');
    }
    $base = (string)($payload['github_pages_base'] ?? cr_gp_default_base());
    if ($base === '') throw new InvalidArgumentException('Configure a GitHub Pages folder URL first');
    $rows = cr_gp_map_batch($payload['filenames'], $base, ($payload['overwrite'] ?? false) === true);
    if (($payload['save_default'] ?? false) === true) cr_gp_set_default($base);
    echo json_encode(['count'=>count($rows),'error_count'=>0,'links'=>$rows,'errors'=>[]], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
} catch (Throwable $e) {
    http_response_code(400);
    echo json_encode(['error'=>$e->getMessage()], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
}
