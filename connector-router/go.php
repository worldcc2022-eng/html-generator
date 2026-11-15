<?php
declare(strict_types=1);
require __DIR__.'/bootstrap.php';
cr_no_cache();
$id = (string)($_GET['id'] ?? '');
$route = cr_route_get($id);
if (!$route) { http_response_code(404); exit('Unknown route'); }
try { $target = cr_validate_destination($route['destination']); }
catch (Throwable $e) { http_response_code(403); exit('Destination no longer approved'); }
header('Location: '.$target, true, $route['mode']);
exit;
