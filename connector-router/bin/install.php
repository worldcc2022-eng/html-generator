<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }
require dirname(__DIR__).'/lib/Core.php';
$baseUrl = rtrim((string)(getenv('CR_BASE_URL') ?: 'https://html.surprisestream.com/connector-router'), '/');
$pass = (string)(getenv('CR_ADMIN_PASSWORD') ?: '');
if (strlen($pass) < 12) { fwrite(STDERR, "Set CR_ADMIN_PASSWORD to a strong password of 12+ characters.\n"); exit(1); }
if (!filter_var($baseUrl, FILTER_VALIDATE_URL) || parse_url($baseUrl, PHP_URL_SCHEME) !== 'https') { fwrite(STDERR,"CR_BASE_URL must be an HTTPS URL.\n"); exit(1); }
$configFile = dirname(__DIR__).'/config.local.php';
if (file_exists($configFile)) { fwrite(STDERR,"Already installed: config.local.php exists. No changes made.\n"); exit(1); }
$dataDir = (string)(getenv('CR_DATA_DIR') ?: dirname(__DIR__, 3).'/connector-router-private');
if (!str_starts_with($dataDir, '/') || str_contains($dataDir, '..')) { fwrite(STDERR, "Use an absolute data directory without .. segments.\n"); exit(1); }
if (str_starts_with(rtrim($dataDir, '/').'/', dirname(__DIR__, 2).'/')) { fwrite(STDERR, "Data directory must be outside public_html.\n"); exit(1); }
if (!is_dir($dataDir) && !mkdir($dataDir, 0700, true) && !is_dir($dataDir)) throw new RuntimeException('Could not create data directory');
chmod($dataDir, 0700);
$token = bin2hex(random_bytes(32));
$config = [
    'base_url'=>$baseUrl,
    'data_dir'=>$dataDir,
    'admin_password_hash'=>password_hash($pass, PASSWORD_DEFAULT),
    'api_token'=>$token,
];
$code = "<?php\ndeclare(strict_types=1);\nreturn ".var_export($config, true).";\n";
if (file_put_contents($configFile, $code, LOCK_EX) === false) throw new RuntimeException('Could not save config');
chmod($configFile, 0600);
cr_json_write($dataDir.'/settings.json', ['allowed_hosts'=>['www.ub.edu'],'reference_host'=>'www.ub.edu','test_destination'=>'https://www.ub.edu/']);
echo "Installed successfully.\nBase URL: {$baseUrl}\nData directory: {$dataDir}\n";
echo "Import API token (KEEP PRIVATE, do not embed in HTML): {$token}\n";
echo "Admin page: {$baseUrl}/admin.php\n";
echo "Test external destination: https://www.ub.edu/ (no third-party script injection)\n";
