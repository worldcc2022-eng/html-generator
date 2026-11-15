<?php
declare(strict_types=1);
/**
 * Optional helper to copy into the existing PHP generator project after review.
 * Call only AFTER successful GitHub upload/deployment (not from browser JavaScript).
 * Does not generate or alter HTML files or IDs; doesn't access GitHub itself.
 */
function registerUploadedGitHubPagesFiles(
    array $existingFilenames,
    string $githubPagesDirectory,
    string $connectorToken,
    string $connectorEndpoint = 'https://html.surprisestream.com/connector-router/github-bulk-import.php'
): array {
    if (!function_exists('curl_init')) throw new RuntimeException('PHP cURL extension is required');
    if (count($existingFilenames) === 0) throw new InvalidArgumentException('No uploaded files supplied');
    if (!preg_match('/\A[a-f0-9]{64}\z/D', $connectorToken)) throw new InvalidArgumentException('Invalid connector API token');
    $combined = [];
    foreach (array_chunk($existingFilenames, 1000) as $chunk) {
        $payload = json_encode([
            'github_pages_base' => $githubPagesDirectory,
            'filenames' => array_values($chunk),
            'overwrite' => false,
            'save_default' => false,
        ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $ch = curl_init($connectorEndpoint);
        if ($ch === false) throw new RuntimeException('Unable to initialize cURL');
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $payload,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'Accept: application/json',
                'Authorization: Bearer ' . $connectorToken,
            ],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => 60,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ]);
        $response = curl_exec($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $failure = curl_error($ch);
        curl_close($ch);
        if ($response === false) throw new RuntimeException('Connector API request failed: ' . $failure);
        $json = json_decode((string)$response, true, 64, JSON_THROW_ON_ERROR);
        if ($status !== 200 || !is_array($json) || !is_array($json['links'] ?? null)) {
            throw new RuntimeException('Connector API HTTP ' . $status . ': ' . substr((string)$response, 0, 500));
        }
        $combined = array_merge($combined, $json['links']);
    }
    return $combined;
}
