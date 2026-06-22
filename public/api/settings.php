<?php
require_once __DIR__ . '/common.php';
api_require_role('admin');
$method = $_SERVER['REQUEST_METHOD'];

$settingsFile = dirname(__DIR__, 2) . '/config/runtime_settings.json';

function read_runtime_settings(string $path): array
{
    if (!is_readable($path)) return [];
    return json_decode(file_get_contents($path), true) ?? [];
}

// GET /api/settings
if ($method === 'GET') {
    $rt = read_runtime_settings($settingsFile);
    $token = $rt['line_notify_token'] ?? Config::get('line_notify_token');
    api_response(true, [
        'notify_mode'              => $rt['notify_mode'] ?? Config::get('notify_mode', 'failure_only'),
        'line_notify_token_set'    => !empty($token),
        'line_notify_token_masked' => !empty($token) ? '****' . substr($token, -4) : null,
    ]);
}

// PUT /api/settings
if ($method === 'PUT') {
    api_check_csrf();
    $b  = api_json_body();
    $rt = read_runtime_settings($settingsFile);

    $allowed = ['notify_mode', 'line_notify_token'];
    foreach ($allowed as $key) {
        if (array_key_exists($key, $b)) {
            if ($b[$key] === '' || $b[$key] === null) {
                unset($rt[$key]);
            } else {
                $rt[$key] = $b[$key];
            }
        }
    }

    if (file_put_contents($settingsFile, json_encode($rt, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), LOCK_EX) === false) {
        api_response(false, null, 'Failed to write settings file — check directory permissions', 500);
    }
    (new AuditLogger(Database::pdo()))->log('settings.update', (int)($_SESSION['user_id'] ?? 0), 'system', 0, api_ip());
    api_response(true);
}

// POST /api/settings/test-notify
$uri = trim(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH), '/');
if ($method === 'POST' && str_ends_with($uri, 'settings/test-notify')) {
    api_check_csrf();
    $b     = api_json_body();
    $token = $b['line_notify_token'] ?? null
          ?: (read_runtime_settings($settingsFile)['line_notify_token'] ?? null)
          ?: Config::get('line_notify_token');
    if (empty($token)) {
        api_response(false, null, 'No LINE Notify token configured', 400);
    }
    $ns = new NotificationService($token, 'all');
    $ns->notifyBackupSuccess(0, 'BRS Test Notification', 0, 0);
    api_response(true, ['message' => 'Test notification sent']);
}

api_response(false, null, 'NOT_FOUND', 404);
