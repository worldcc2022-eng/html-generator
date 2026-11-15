<?php
declare(strict_types=1);
require __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/lib/GithubPages.php';
cr_no_cache();
header('Content-Type: application/json; charset=UTF-8');
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405); header('Allow: POST');
    echo json_encode(['error'=>'POST required']); exit;
}
$headers = function_exists('getallheaders') ? getallheaders() : [];
$auth = (string)($_SERVER['HTTP_AUTHORIZATION'] ?? $headers['Authorization'] ?? $headers['authorization'] ?? '');
if (!preg_match('/\ABearer ([a-f0-9]{64})\z/D', $auth, $m) || !hash_equals((string)cr_config()['api_token'], $m[1])) {
    http_response_code(401);
    echo json_encode(['error'=>'Unauthorized']); exit;
}
try {
    if ((int)($_SERVER['CONTENT_LENGTH'] ?? 0) > 2097152) throw new InvalidArgumentException('Payload too large');
    $json = file_get_contents('php://input', false, null, 0, 2097153);
    if ($json === false || strlen($json) > 2097152) throw new InvalidArgumentException('Payload too large');
    $input = json_decode($json, true, 64, JSON_THROW_ON_ERROR);
    if (!is_array($input) || !is_array($input['filenames'] ?? null) || !array_is_list($input['filenames'])) {
        throw new InvalidArgumentException('filenames must be an array of existing HTML filenames');
    }
    $base = (string)($input['github_pages_base'] ?? '');
    $results = cr_gp_map_batch($input['filenames'], $base, ($input['overwrite'] ?? false) === true);
    if (($input['save_default'] ?? false) === true) cr_gp_set_default($base);
    echo json_encode(['count'=>count($results), 'links'=>$results], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
} catch (Throwable $e) {
    http_response_code(400);
    echo json_encode(['error'=>$e->getMessage()], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
}
