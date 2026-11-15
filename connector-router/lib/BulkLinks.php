<?php
declare(strict_types=1);
require_once __DIR__ . '/Core.php';

/**
 * Build links for the first-party browser installed with Connector Router.
 * ID is exactly the basename from the generator (with optional .html removed).
 * No new IDs are minted and no generated file contents are changed.
 */
function cr_bulk_id_from_filename(string $filename): string {
    $filename = trim($filename);
    if (str_ends_with(strtolower($filename), '.html')) {
        $filename = substr($filename, 0, -5);
    }
    if (!cr_valid_id($filename)) {
        throw new InvalidArgumentException('Invalid generated filename or ID: ' . $filename);
    }
    return $filename;
}

/** Same-origin, self-hosted file browser. No externally hosted FCKeditor dependency. */
function cr_bulk_browser_url(string $filename, ?string $browserBaseUrl = null): string {
    $id = cr_bulk_id_from_filename($filename);
    $base = rtrim(cr_config()['base_url'], '/');
    $allowedBrowserUrls = [$base . "/browser.php", $base . "/legacy/browser.html"];
    $browserBaseUrl = $browserBaseUrl ?? $allowedBrowserUrls[0];
    if (!in_array($browserBaseUrl, $allowedBrowserUrls, true)) {
        throw new InvalidArgumentException("Browser URL must be a first-party browser included with this Connector installation.");
    }
    $path = (string) parse_url($base, PHP_URL_PATH);
    $connector = rtrim($path, '/') . '/id/?jd=' . rawurlencode($id);
    return $browserBaseUrl . '?Type=File&CONNECTOR=' . rawurlencode($connector);
}

function cr_bulk_links(array $filenames, bool $verifyStoredRoutes = true, ?string $browserBaseUrl = null): array {
    if (!$filenames || count($filenames) > 1000) {
        throw new InvalidArgumentException('Provide between 1 and 1,000 filenames per request.');
    }
    $result = []; $seen = [];
    foreach ($filenames as $original) {
        try {
            $id = cr_bulk_id_from_filename((string)$original);
            if (isset($seen[$id])) continue;
            $seen[$id] = true;
            if ($verifyStoredRoutes && !cr_route_get($id)) {
                throw new InvalidArgumentException('ID not registered; import it from your generator first');
            }
            $result[] = ['filename' => (string)$original, 'id' => $id, 'url' => cr_bulk_browser_url($id, $browserBaseUrl)];
        } catch (Throwable $e) {
            $result[] = ['filename' => (string)$original, 'error' => $e->getMessage()];
        }
    }
    return $result;
}
