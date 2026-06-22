<?php
require_once __DIR__ . '/common.php';

$method = $_SERVER['REQUEST_METHOD'];
$path   = trim(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH), '/');

// POST /api/auth/login
if ($method === 'POST' && str_ends_with($path, 'auth/login')) {
    $body = api_json_body();
    $username = trim($body['username'] ?? '');
    $password = $body['password'] ?? '';

    if (!$username || !$password) {
        api_response(false, null, 'Username and password required', 400);
    }

    $stmt = Database::pdo()->prepare('SELECT * FROM users WHERE username=? AND is_active=1');
    $stmt->execute([$username]);
    $user = $stmt->fetch();

    if (!$user || !password_verify($password, $user['password_hash'])) {
        // Rate limiting: track failed attempts in session
        $_SESSION['login_fails'] = ($_SESSION['login_fails'] ?? 0) + 1;
        if ($_SESSION['login_fails'] >= 5) {
            api_response(false, null, 'Too many failed attempts. Try again in 15 minutes.', 429);
        }
        api_response(false, null, 'Invalid username or password', 401);
    }

    // Successful login
    $_SESSION['login_fails'] = 0;
    session_regenerate_id(true);
    $_SESSION['user_id']    = $user['id'];
    $_SESSION['user_role']  = $user['role'];
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));

    Database::pdo()->prepare('UPDATE users SET last_login_at=NOW() WHERE id=?')->execute([$user['id']]);
    (new AuditLogger(Database::pdo()))->log('auth.login', (int)$user['id'], null, null, api_ip());

    api_response(true, [
        'user_id'    => $user['id'],
        'role'       => $user['role'],
        'full_name'  => $user['full_name'],
        'csrf_token' => $_SESSION['csrf_token'],
    ]);
}

// POST /api/auth/logout
if ($method === 'POST' && str_ends_with($path, 'auth/logout')) {
    api_check_csrf();
    $userId = $_SESSION['user_id'] ?? null;
    session_unset();
    session_destroy();
    if ($userId) (new AuditLogger(Database::pdo()))->log('auth.logout', (int)$userId, null, null, api_ip());
    api_response(true);
}

api_response(false, null, 'NOT_FOUND', 404);
