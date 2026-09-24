<?php

/**
 * Router for the PHP built-in web server emulating OVHcloud AI Endpoints (see OvhTranscriberTest).
 *
 * URL: /{scenario}/{run id}/audio/transcriptions. Every request is recorded as JSON in
 * $VMAI_TEST_STATE_DIR so that tests can inspect it and count attempts.
 */

declare(strict_types=1);

[$scenario, $runId] = explode('/', trim((string) parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH), '/')) + ['', ''];
$prefix = sprintf('%s/%s-%s-', getenv('VMAI_TEST_STATE_DIR'), $scenario, $runId);
$attempt = count(glob($prefix . '*.json') ?: []) + 1;

file_put_contents($prefix . $attempt . '.json', json_encode([
    'method' => $_SERVER['REQUEST_METHOD'],
    'authorization' => $_SERVER['HTTP_AUTHORIZATION'] ?? null,
    'fields' => $_POST,
    'file' => isset($_FILES['file']) ? [
        'name' => $_FILES['file']['name'],
        'type' => $_FILES['file']['type'],
        'size' => $_FILES['file']['size'],
    ] : null,
], JSON_THROW_ON_ERROR));

[$status, $body] = match ($scenario) {
    'ok' => [200, '{"text": "  Bonjour, rappelez-moi.  ", "language": "french", "duration": 3.5}'],
    'flaky' => $attempt === 1 ? [503, 'Service Unavailable'] : [200, '{"text": "Bonjour"}'],
    'down' => [503, 'Service Unavailable'],
    'bad-request' => [400, '{"error": "Invalid model"}'],
    'invalid-json' => [200, 'not json'],
    'no-text' => [200, '{"language": "fr"}'],
    default => [404, 'Unknown scenario'],
};

http_response_code($status);
header('Content-Type: application/json');
echo $body;
