<?php
declare(strict_types=1);
require __DIR__.'/bootstrap.php';
cr_no_cache();
header('Content-Type: application/json; charset=UTF-8');
echo json_encode(['status'=>'ok','service'=>'connector-router','base_url'=>cr_config()['base_url'],'xml_endpoint'=>rtrim(cr_config()['base_url'],'/').'/connector.php'], JSON_UNESCAPED_SLASHES);
