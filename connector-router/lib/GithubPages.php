<?php
declare(strict_types=1);
require_once __DIR__ . '/Core.php';

/** GitHub Pages file mapping: no network requests or arbitrary HTML execution. */
function cr_gp_id(string $input): string {
    $id = trim($input);
    if (str_ends_with(strtolower($id), '.html')) $id = substr($id, 0, -5);
    if (!cr_valid_id($id)) {
        throw new InvalidArgumentException('Use an existing generated ID or .html filename (lowercase letters, digits, hyphens; 8-180 chars).');
    }
    return $id;
}

/** Accepts a manually entered HTTPS directory or a sample HTML file URL. */
function cr_gp_normalize_base(string $input): string {
    $url = trim($input);
    if (strlen($url) > 1700 || $url === '' || preg_match('/[\x00-\x20\x7F\\\\]/', $url) || !filter_var($url, FILTER_VALIDATE_URL)) {
        throw new InvalidArgumentException('Enter a complete HTTPS GitHub Pages folder URL.');
    }
    $p = parse_url($url);
    if (!is_array($p) || strtolower((string)($p['scheme'] ?? '')) !== 'https'
        || empty($p['host']) || isset($p['user']) || isset($p['pass']) || isset($p['port'])
        || isset($p['query']) || isset($p['fragment']) || !cr_valid_host(strtolower((string)$p['host']))) {
        throw new InvalidArgumentException('Use a normal HTTPS domain URL without a query string, credentials or custom port.');
    }
    $path = (string)($p['path'] ?? '/');
    if (str_contains($path, '//')) throw new InvalidArgumentException('Folder URL has repeated slashes.');
    $decodedPath = rawurldecode($path);
    if (preg_match('~(?:^|/)(?:\.|\.\.)(?:/|$)~', $decodedPath) || str_contains($decodedPath, '\\') || str_contains($decodedPath, '?') || str_contains($decodedPath, '#') || preg_match('/[\x00-\x1F\x7F]/', $decodedPath)) {
        throw new InvalidArgumentException('Invalid folder URL path.');
    }
    // If a complete sample /folder/example-id.html was supplied, use its directory.
    if (preg_match('~(?:^|/)[a-z0-9][a-z0-9-]{7,179}\.html$~i', $path)) {
        $path = substr($path, 0, (int)strrpos($path, '/') + 1);
    } elseif (str_ends_with(strtolower($path), '.html')) {
        throw new InvalidArgumentException('The sample .html filename must match the generator ID format.');
    }
    if (!str_ends_with($path, '/')) $path .= '/';
    return 'https://' . strtolower((string)$p['host']) . $path;
}

/** Extract a filename ID without fetching the CONNECTOR URL. */
function cr_gp_request_id(array $query): string {
    foreach (['jd', 'id'] as $key) {
        if (array_key_exists($key, $query)) {
            if (!is_string($query[$key])) throw new InvalidArgumentException('Invalid generated ID');
            return cr_gp_id($query[$key]);
        }
    }
    if (!array_key_exists('CONNECTOR', $query)) return '';
    $connector = $query['CONNECTOR'];
    if (!is_string($connector) || strlen($connector) > 4096) throw new InvalidArgumentException('Invalid CONNECTOR parameter');
    $nestedQuery = parse_url($connector, PHP_URL_QUERY);
    if ($nestedQuery === false) throw new InvalidArgumentException('Invalid CONNECTOR URL');
    $nested = [];
    if (is_string($nestedQuery)) parse_str($nestedQuery, $nested);
    foreach (['jd', 'id'] as $key) {
        if (array_key_exists($key, $nested)) {
            if (!is_string($nested[$key])) throw new InvalidArgumentException('Invalid generated ID');
            return cr_gp_id($nested[$key]);
        }
    }
    return '';
}

function cr_gp_settings_path(): string { return rtrim(cr_data_dir(), '/') . '/github-pages.json'; }
function cr_gp_default_base(): string {
    $s = cr_json_read(cr_gp_settings_path());
    $base = $s['default_base'] ?? '';
    return is_string($base) ? $base : '';
}
function cr_gp_set_default(string $base): string {
    $base = cr_gp_normalize_base($base);
    cr_json_write(cr_gp_settings_path(), ['default_base'=>$base, 'updated_at'=>gmdate('c')]);
    cr_audit('github_default_updated', ['base'=>$base]);
    return $base;
}
function cr_gp_mapping_path(string $id): string {
    if (!cr_valid_id($id)) throw new InvalidArgumentException('Invalid generated ID');
    return rtrim(cr_data_dir(), '/') . '/github-pages-routes/' . substr(hash('sha256', $id), 0, 2) . '/' . $id . '.json';
}
function cr_gp_mapping_get(string $id): ?array {
    if (!cr_valid_id($id)) return null;
    $map = cr_json_read(cr_gp_mapping_path($id));
    return ($map['id'] ?? '') === $id && is_string($map['base'] ?? null) ? $map : null;
}
function cr_gp_base_for_id(string $id): string {
    $map = cr_gp_mapping_get($id);
    return $map['base'] ?? cr_gp_default_base();
}
function cr_gp_file_url(string $id, ?string $overrideBase = null): string {
    $id = cr_gp_id($id);
    $base = $overrideBase !== null ? cr_gp_normalize_base($overrideBase) : cr_gp_base_for_id($id);
    if ($base === '') throw new RuntimeException('GitHub Pages URL is not configured. Save it in Admin settings first.');
    return $base . rawurlencode($id) . '.html';
}
function cr_gp_demo_browser_url(string $id): string {
    return cr_gp_browser_url($id) . '&auto=1';
}

function cr_gp_browser_url(string $id): string {
    $id = cr_gp_id($id);
    $root = rtrim(cr_config()['base_url'], '/');
    $path = (string)(parse_url($root, PHP_URL_PATH) ?: '');
    $connector = rtrim($path, '/') . '/id/?jd=' . rawurlencode($id);
    return $root . '/browser.php?Type=File&CONNECTOR=' . rawurlencode($connector);
}

/** Prepare before writing: reject invalid IDs and incompatible existing mappings. */
function cr_gp_map_batch(array $filenames, string $base, bool $overwrite = false): array {
    if (count($filenames) < 1 || count($filenames) > 1000) throw new InvalidArgumentException('Enter 1–1000 filenames in a batch.');
    $base = cr_gp_normalize_base($base);
    $unique = [];
    foreach ($filenames as $raw) {
        if (!is_string($raw)) throw new InvalidArgumentException('Each filename must be a string.');
        $id = cr_gp_id($raw);
        if (!array_key_exists($id, $unique)) $unique[$id] = trim($raw);
    }
    foreach ($unique as $id => $_) {
        $existing = cr_gp_mapping_get($id);
        if ($existing && $existing['base'] !== $base && !$overwrite) {
            throw new InvalidArgumentException('ID already mapped to another GitHub Pages folder: ' . $id . '. Enable overwrite only if you intend to remap it.');
        }
    }
    $rows = [];
    foreach ($unique as $id => $original) {
        $old = cr_gp_mapping_get($id);
        if (!$old || $old['base'] !== $base) {
            cr_json_write(cr_gp_mapping_path($id), [
                'id'=>$id, 'base'=>$base, 'created_at'=>$old['created_at'] ?? gmdate('c'), 'updated_at'=>gmdate('c')
            ]);
        }
        $rows[] = [
            'filename'=>$original,
            'id'=>$id,
            'github_url'=>cr_gp_file_url($id, $base),
            'url'=>cr_gp_browser_url($id),
            'demo_url'=>cr_gp_demo_browser_url($id),
            'status'=>$old ? (($old['base'] === $base) ? 'unchanged' : 'updated') : 'created',
        ];
    }
    cr_audit('github_batch_map', ['count'=>count($rows), 'base'=>$base]);
    return $rows;
}

/** All attributes are XML-escaped text. Never place scripts or iframes in metadata. */
function cr_gp_xml_document(string $id): string {
    $id = cr_gp_id($id);
    $base = cr_gp_base_for_id($id);
    if ($base === '') throw new RuntimeException('No GitHub Pages mapping/default for this ID.');
    $url = cr_gp_file_url($id);
    $xml = '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
    $xml .= '<Connector command="GetFoldersAndFiles" resourceType="Document">' . "\n";
    $xml .= '  <CurrentFolder path="/1 - f/" url="' . cr_xml($base) . '"/>' . "\n";
    $xml .= "  <Folders/>\n  <Files>\n";
    $xml .= '    <File desc="1" name="' . cr_xml($id . '.html') . '" size="0" url="' . cr_xml($url) . '"/>' . "\n";
    $xml .= "  </Files>\n</Connector>\n";
    return $xml;
}
