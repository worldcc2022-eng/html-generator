<?php
declare(strict_types=1);
require __DIR__.'/bootstrap.php';
cr_no_cache();
header('Content-Type: application/json; charset=UTF-8');
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); header('Allow: POST'); echo json_encode(['error'=>'POST required']); exit; }
$headers = function_exists('getallheaders') ? getallheaders() : [];
$auth = (string)($_SERVER['HTTP_AUTHORIZATION'] ?? $headers['Authorization'] ?? $headers['authorization'] ?? '');
if (!preg_match('/\ABearer ([a-f0-9]{64})\z/D', $auth, $m) || !hash_equals(cr_config()['api_token'], $m[1])) {
    http_response_code(401); echo json_encode(['error'=>'Unauthorized']); exit;
}
if ((int)($_SERVER['CONTENT_LENGTH'] ?? 0) > 2097152) { http_response_code(413); echo json_encode(['error'=>'Payload too large']); exit; }
$input = file_get_contents('php://input', false, null, 0, 2097153);
if ($input === false || strlen($input) > 2097152) { http_response_code(413); echo json_encode(['error'=>'Payload too large']); exit; }
try {
    $payload = json_decode($input, true, 128, JSON_THROW_ON_ERROR);
    if (!is_array($payload)) throw new InvalidArgumentException('Expected JSON object');
    $routes = $payload['routes'] ?? null;
    if (!is_array($routes) || !array_is_list($routes) || !$routes || count($routes) > 1000) throw new InvalidArgumentException('routes must contain 1–1000 items');
    $overwrite = ($payload['overwrite'] ?? false) === true;
    $created = []; $errors = [];
    foreach ($routes as $route) {
        if (!is_array($route)) { $errors[] = ['id'=>null,'error'=>'Invalid route']; continue; }
        $id = (string)($route['id'] ?? '');
        try { cr_route_save($route, $overwrite); $created[] = ['id'=>$id, 'url'=>cr_route_url($id)]; }
        catch (Throwable $e) { $errors[] = ['id'=>$id,'error'=>$e->getMessage()]; }
    }
    http_response_code($errors ? 207 : 201);
    echo json_encode(['created_count'=>count($created),'error_count'=>count($errors),'created'=>$created,'errors'=>$errors], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
} catch (Throwable $e) {
    http_response_code(400);
    echo json_encode(['error'=>$e->getMessage()], JSON_UNESCAPED_SLASHES);
}
