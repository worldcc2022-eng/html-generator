<?php
declare(strict_types=1);
/**
 * Example glue code for an already-running PHP generator.
 * Call AFTER the generator has chosen and stored its own IDs and HTML files.
 * Store the API token in server-side configuration, never inside HTML output.
 *
 * This example intentionally does not assume generator-specific database tables,
 * domain rotation, template upload locations, GitHub credentials or file paths.
 */
function connectorCall(string $endpoint, string $token, array $payload): array {
    $body = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    $curl = curl_init($endpoint);
    curl_setopt_array($curl, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $body,
        CURLOPT_HTTPHEADER => [
            'Authorization: Bearer '.$token,
            'Content-Type: application/json',
            'Accept: application/json',
        ],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_FOLLOWLOCATION => false,
    ]);
    $result = curl_exec($curl);
    $status = (int)curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
    $error = curl_error($curl);
    curl_close($curl);
    if ($result === false || $status >= 400 || $status === 207) {
        throw new RuntimeException('Connector request failed (HTTP '.$status.'): '.($error ?: (string)$result));
    }
    return json_decode((string)$result, true, 64, JSON_THROW_ON_ERROR);
}

function connectorRegisterAndLink(array $generatedFiles, string $token): array {
    // $generatedFiles is the generator's existing list, NOT freshly created IDs:
    // [['filename'=>'video-event-abc123.html', 'destination'=>'https://your-approved-domain/page', 'title'=>'Event']]
    $base = 'https://html.surprisestream.com/connector-router/';
    $routes = []; $filenames = [];
    foreach ($generatedFiles as $file) {
        $filename = (string)$file['filename'];
        $id = preg_replace('/\.html$/i', '', $filename);
        $routes[] = [
            'id' => $id,
            'destination' => (string)$file['destination'],
            'title' => (string)($file['title'] ?? $id),
            'mode' => 302,
        ];
        $filenames[] = $filename;
    }
    $registered = connectorCall($base.'import.php', $token, ['overwrite'=>false, 'routes'=>$routes]);
    $links = connectorCall($base.'bulk-export.php', $token, ['filenames'=>$filenames, 'verify_routes'=>true, 'browser_url'=>$base.'browser.php']);
    if (count($registered['created'] ?? []) !== count($filenames) || count($links['links'] ?? []) !== count($filenames)) {
        throw new RuntimeException('Connector results differ from original file count. Inspect partial errors.');
    }
    return $links['links'];
}
