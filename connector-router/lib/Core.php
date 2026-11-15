<?php
declare(strict_types=1);

/** Standalone, first-party connector/router for authorized deployment only. */
function cr_config(): array {
    static $cfg;
    if (isset($cfg)) return $cfg;
    $file = dirname(__DIR__) . '/config.local.php';
    if (!is_file($file)) throw new RuntimeException('Not installed. Run php bin/install.php.');
    $loaded = require $file;
    if (!is_array($loaded)) throw new RuntimeException('Invalid configuration.');
    return $cfg = $loaded;
}
function cr_data_dir(): string { return cr_config()['data_dir']; }
function cr_json_read(string $file, array $default = []): array {
    if (!is_file($file)) return $default;
    $data = json_decode((string)file_get_contents($file), true);
    return is_array($data) ? $data : $default;
}
function cr_json_write(string $file, array $data): void {
    $dir = dirname($file);
    if (!is_dir($dir) && !mkdir($dir, 0700, true) && !is_dir($dir)) throw new RuntimeException('Could not create data directory');
    $temp = tempnam($dir, '.tmp-');
    if ($temp === false) throw new RuntimeException('Could not create temporary file');
    try {
        if (file_put_contents($temp, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)) === false) throw new RuntimeException('Could not write JSON');
        chmod($temp, 0600);
        if (!rename($temp, $file)) throw new RuntimeException('Atomic rename failed');
    } finally { if (is_file($temp)) unlink($temp); }
}
function cr_settings(): array {
    return cr_json_read(cr_data_dir().'/settings.json', [
        'allowed_hosts' => ['www.ub.edu'],
        'reference_host' => 'www.ub.edu',
        'test_destination' => 'https://www.ub.edu/',
    ]);
}
function cr_str(string $value): string { return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
function cr_xml(string $value): string { return htmlspecialchars($value, ENT_XML1 | ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
function cr_valid_id(string $id): bool { return (bool)preg_match('/\A[a-z0-9][a-z0-9-]{7,179}\z/D', $id); }
function cr_valid_host(string $host): bool {
    return strlen($host) <= 253 && (bool)preg_match('/\A(?=.{1,253}\z)(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}\z/iD', $host);
}
function cr_normalize_host(string $input): string {
    $host = strtolower(trim($input));
    if (!cr_valid_host($host)) throw new InvalidArgumentException('Enter a DNS hostname such as www.ub.edu (not a URL).');
    return $host;
}
function cr_validate_destination(string $url, ?array $settings = null): string {
    $url = trim($url);
    if (strlen($url) > 2000 || preg_match('/[\x00-\x1F\x7F]/', $url) || !filter_var($url, FILTER_VALIDATE_URL)) {
        throw new InvalidArgumentException('Invalid destination URL.');
    }
    $p = parse_url($url);
    if (!is_array($p) || strtolower((string)($p['scheme'] ?? '')) !== 'https' || !isset($p['host']) || isset($p['user'], $p['pass']) || isset($p['user']) || isset($p['port'])) {
        throw new InvalidArgumentException('Only HTTPS URLs without credentials or custom ports are allowed.');
    }
    $host = strtolower($p['host']);
    if (!cr_valid_host($host)) throw new InvalidArgumentException('Invalid DNS host.');
    $settings ??= cr_settings();
    $allowed = array_map('strtolower', $settings['allowed_hosts'] ?? []);
    if (!in_array($host, $allowed, true)) throw new InvalidArgumentException('Host is not approved. Add it in Admin Settings first.');
    return $url;
}
function cr_route_path(string $id): string {
    if (!cr_valid_id($id)) throw new InvalidArgumentException('Invalid ID');
    return cr_data_dir().'/routes/'.substr(hash('sha256', $id), 0, 2).'/'.$id.'.json';
}
function cr_route_get(string $id): ?array {
    if (!cr_valid_id($id)) return null;
    $data = cr_json_read(cr_route_path($id));
    return isset($data['id'], $data['destination']) && $data['id'] === $id ? $data : null;
}
function cr_route_save(array $route, bool $overwrite = false): void {
    $id = (string)($route['id'] ?? '');
    if (!cr_valid_id($id)) throw new InvalidArgumentException('Invalid route ID: '.$id);
    $destination = cr_validate_destination((string)($route['destination'] ?? ''));
    $mode = (int)($route['mode'] ?? 302);
    if (!in_array($mode, [301, 302], true)) throw new InvalidArgumentException('Mode must be 301 or 302.');
    $title = substr(trim((string)($route['title'] ?? $id)), 0, 150);
    $path = cr_route_path($id);
    $data = ['id'=>$id, 'destination'=>$destination, 'mode'=>$mode, 'title'=>$title, 'updated_at'=>gmdate('c')];
    $dir = dirname($path);
    if (!is_dir($dir) && !mkdir($dir, 0700, true) && !is_dir($dir)) throw new RuntimeException('Could not create route shard');
    // Exclusive creation prevents accidental collision for concurrent importers.
    if (!$overwrite) {
        $handle = @fopen($path, 'x');
        if (!$handle) throw new RuntimeException('ID already exists: '.$id);
        try {
            if (!flock($handle, LOCK_EX)) throw new RuntimeException('Unable to lock route');
            $encoded = json_encode($data, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
            if (fwrite($handle, $encoded) === false) throw new RuntimeException('Cannot write route');
            fflush($handle);
            flock($handle, LOCK_UN);
        } finally { fclose($handle); }
        chmod($path, 0600);
    } else {
        cr_json_write($path, $data);
    }
    cr_audit('route_upsert', ['id'=>$id, 'overwrite'=>$overwrite]);
}
function cr_audit(string $event, array $details = []): void {
    $line = json_encode(['timestamp'=>gmdate('c'),'event'=>$event,'details'=>$details], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n";
    $file = cr_data_dir().'/audit.jsonl';
    file_put_contents($file, $line, FILE_APPEND | LOCK_EX);
    @chmod($file, 0600);
}
function cr_latest_routes(int $limit = 30): array {
    $log = cr_data_dir().'/audit.jsonl';
    if (!is_file($log)) return [];
    $file = new SplFileObject($log, 'r');
    $file->seek(PHP_INT_MAX);
    $line = $file->key();
    $result = [];
    for ($i = $line; $i >= 0 && count($result) < $limit; $i--) {
        $file->seek($i);
        $row = json_decode(trim((string)$file->current()), true);
        if (!is_array($row) || ($row['event'] ?? '') !== 'route_upsert') continue;
        $id = (string)($row['details']['id'] ?? '');
        $route = cr_route_get($id);
        if ($route && !isset($result[$id])) $result[$id] = $route;
    }
    return array_values($result);
}
function cr_route_url(string $id): string {
    return rtrim(cr_config()['base_url'], '/').'/go.php?id='.rawurlencode($id);
}
function cr_no_cache(): void {
    header('Cache-Control: no-store, private');
    header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: no-referrer');
}
function cr_html_headers(): void {
    cr_no_cache();
    header("Content-Security-Policy: default-src 'none'; style-src 'self'; form-action 'self'; base-uri 'none'; frame-ancestors 'none'");
    header('Content-Type: text/html; charset=UTF-8');
}
/**
 * 30-day login. PHP sessions use a persistent cookie and private, revocable
 * remember-me credentials recover admin access when shared-host PHP session
 * garbage collection removes session files earlier than their cookie expiry.
 */
function cr_auth_lifetime(): int { return 30 * 24 * 60 * 60; }
function cr_auth_secure_cookie(): bool {
    return parse_url(cr_config()['base_url'], PHP_URL_SCHEME) === 'https';
}
function cr_auth_cookie_path(): string {
    // Use the actual deployed script directory: a previously configured URL
    // may have a stale path after moving the installation.
    $script = (string)($_SERVER['SCRIPT_NAME'] ?? '');
    if ($script !== '' && str_starts_with($script, '/') && str_ends_with(strtolower($script), '.php')) {
        $dir = str_replace('\\', '/', dirname($script));
        if ($dir !== '.' && $dir !== '/' && !str_contains($dir, '..')) return rtrim($dir, '/') . '/';
    }
    $fallback = (string)(parse_url(cr_config()['base_url'], PHP_URL_PATH) ?: '/');
    return rtrim($fallback, '/') . '/';
}
function cr_auth_cookie_options(int $expires): array {
    return [
        'expires' => $expires,
        'path' => cr_auth_cookie_path(),
        'secure' => cr_auth_secure_cookie(),
        'httponly' => true,
        'samesite' => 'Lax',
    ];
}
function cr_session(): void {
    if (session_status() === PHP_SESSION_ACTIVE) return;
    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');
    ini_set('session.gc_maxlifetime', (string)cr_auth_lifetime());
    session_name('CRSESSID');
    session_set_cookie_params([
        'lifetime' => cr_auth_lifetime(),
        'path' => cr_auth_cookie_path(),
        'secure' => cr_auth_secure_cookie(),
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
}
function cr_remember_tokens_path(): string { return rtrim(cr_data_dir(), '/') . '/remember-tokens.json'; }
function cr_remember_write(callable $callback): void {
    $file = cr_remember_tokens_path();
    $lock = @fopen($file . '.lock', 'c');
    if ($lock === false) throw new RuntimeException('Cannot open private login token lock');
    @chmod($file . '.lock', 0600);
    try {
        if (!flock($lock, LOCK_EX)) throw new RuntimeException('Cannot lock private login tokens');
        $tokens = cr_json_read($file);
        foreach ($tokens as $selector => $record) {
            if (!is_array($record) || (int)($record['expires'] ?? 0) <= time()) unset($tokens[$selector]);
        }
        $callback($tokens);
        cr_json_write($file, $tokens);
        flock($lock, LOCK_UN);
    } finally { fclose($lock); }
}
function cr_remember_issue(): void {
    if (headers_sent()) throw new RuntimeException('Headers already sent; cannot set remember cookie');
    $selector = bin2hex(random_bytes(16));
    $validator = bin2hex(random_bytes(32));
    $expiry = time() + cr_auth_lifetime();
    cr_remember_write(static function (array &$tokens) use ($selector, $validator, $expiry): void {
        // Keep a bounded number of sessions, without invalidating other devices.
        if (count($tokens) >= 50) {
            uasort($tokens, static fn(array $a, array $b): int => ($a['expires'] ?? 0) <=> ($b['expires'] ?? 0));
            $tokens = array_slice($tokens, -49, null, true);
        }
        $tokens[$selector] = ['hash'=>hash('sha256', $validator), 'expires'=>$expiry];
    });
    if (!setcookie('CRREMEMBER', $selector . ':' . $validator, cr_auth_cookie_options($expiry))) {
        throw new RuntimeException('Could not issue remember cookie');
    }
}
function cr_remember_clear_cookie(): void {
    if (!headers_sent()) setcookie('CRREMEMBER', '', cr_auth_cookie_options(time() - 3600));
    unset($_COOKIE['CRREMEMBER']);
}
function cr_remember_revoke_current(): void {
    $cookie = (string)($_COOKIE['CRREMEMBER'] ?? '');
    if (preg_match('/\A([a-f0-9]{32}):([a-f0-9]{64})\z/D', $cookie, $m)) {
        $selector = $m[1];
        cr_remember_write(static function (array &$tokens) use ($selector): void {
            unset($tokens[$selector]);
        });
    }
    cr_remember_clear_cookie();
}
function cr_remember_restore(): bool {
    $cookie = (string)($_COOKIE['CRREMEMBER'] ?? '');
    if (!preg_match('/\A([a-f0-9]{32}):([a-f0-9]{64})\z/D', $cookie, $m)) return false;
    $tokens = cr_json_read(cr_remember_tokens_path());
    $record = $tokens[$m[1]] ?? null;
    $valid = is_array($record)
        && (int)($record['expires'] ?? 0) > time()
        && is_string($record['hash'] ?? null)
        && hash_equals($record['hash'], hash('sha256', $m[2]));
    if (!$valid) { cr_remember_clear_cookie(); return false; }
    session_regenerate_id(true);
    $_SESSION['admin'] = true;
    $_SESSION['admin_until'] = (int)$record['expires'];
    $_SESSION['csrf'] = bin2hex(random_bytes(24));
    return true;
}
function cr_csrf(): string {
    cr_session();
    if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(24));
    return $_SESSION['csrf'];
}
function cr_csrf_check(): void {
    cr_session();
    if (!hash_equals((string)($_SESSION['csrf'] ?? ''), (string)($_POST['csrf'] ?? ''))) {
        http_response_code(403); exit('Invalid CSRF token');
    }
}
function cr_is_admin(): bool {
    cr_session();
    if (!empty($_SESSION['admin']) && $_SESSION['admin'] === true) {
        if ((int)($_SESSION['admin_until'] ?? 0) > time()) return true;
        unset($_SESSION['admin'], $_SESSION['admin_until']);
    }
    return cr_remember_restore();
}
function cr_require_admin(): void { if (!cr_is_admin()) { http_response_code(401); exit('Admin authentication required'); } }
