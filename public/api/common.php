<?php
declare(strict_types=1);
ini_set('memory_limit', '256M');
$rootDir = dirname(__DIR__, 2);
require_once $rootDir . '/vendor/autoload.php';
Config::init($rootDir . '/config/app.config.php');
$_runtimeSettings = $rootDir . '/config/runtime_settings.json';
if (is_readable($_runtimeSettings)) {
    $overrides = json_decode(file_get_contents($_runtimeSettings), true) ?? [];
    Config::merge($overrides);
}
unset($_runtimeSettings);

// Session init
$timeout = Config::get('session_timeout', 1800);
ini_set('session.gc_maxlifetime', (string) $timeout);
session_set_cookie_params(['httponly' => true, 'samesite' => 'Strict']);
session_start();

// Auto-expire session
if (isset($_SESSION['last_active']) && (time() - $_SESSION['last_active']) > $timeout) {
    session_unset();
    session_destroy();
    session_start();
}
$_SESSION['last_active'] = time();

function api_response(bool $ok, mixed $data = null, ?string $err = null, int $status = 200): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['success' => $ok, 'data' => $data, 'error' => $err], JSON_UNESCAPED_UNICODE);
    exit;
}

function api_require_auth(): array
{
    if (empty($_SESSION['user_id'])) {
        api_response(false, null, 'UNAUTHORIZED', 401);
    }
    $stmt = Database::pdo()->prepare('SELECT * FROM users WHERE id=? AND is_active=1');
    $stmt->execute([$_SESSION['user_id']]);
    $user = $stmt->fetch();
    if (!$user) api_response(false, null, 'UNAUTHORIZED', 401);
    return $user;
}

function api_require_role(string ...$roles): void
{
    $user = api_require_auth();
    if (!in_array($user['role'], $roles)) {
        api_response(false, null, 'FORBIDDEN', 403);
    }
}

function api_check_csrf(): void
{
    $token = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    if (!hash_equals($_SESSION['csrf_token'] ?? '', $token)) {
        api_response(false, null, 'CSRF token mismatch', 403);
    }
}

function api_json_body(): array
{
    $body = json_decode(file_get_contents('php://input'), true);
    return is_array($body) ? $body : [];
}

function api_ip(): string
{
    return $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? '';
}
