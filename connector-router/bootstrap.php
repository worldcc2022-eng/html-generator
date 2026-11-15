<?php
declare(strict_types=1);
require_once __DIR__.'/lib/Core.php';
try { cr_config(); } catch (Throwable $e) { http_response_code(503); exit('Connector Router is not installed.'); }
