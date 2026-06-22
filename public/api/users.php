<?php
require_once __DIR__ . '/common.php';
api_require_role('admin');
$method = $_SERVER['REQUEST_METHOD'];
$uri    = trim(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH), '/');
$pdo    = Database::pdo();

preg_match('#api/users(?:/(\d+)(?:/(reset-password))?)?$#', $uri, $m);
$id     = isset($m[1]) ? (int)$m[1] : null;
$action = $m[2] ?? null;

// GET /api/users
if ($method === 'GET' && !$id) {
    $rows = $pdo->query('SELECT id,username,full_name,role,is_active,last_login_at,created_at FROM users')->fetchAll();
    api_response(true, $rows);
}

// POST /api/users
if ($method === 'POST' && !$id) {
    api_check_csrf();
    $b = api_json_body();
    if (empty($b['username']) || empty($b['password'])) api_response(false, null, 'username and password required', 400);
    $hash = password_hash($b['password'], PASSWORD_ARGON2ID);
    $pdo->prepare('INSERT INTO users (username,password_hash,full_name,role) VALUES(?,?,?,?)')
        ->execute([$b['username'], $hash, $b['full_name'] ?? null, $b['role'] ?? 'viewer']);
    api_response(true, ['id' => (int)$pdo->lastInsertId()], null, 201);
}

// PUT /api/users/{id}
if ($method === 'PUT' && $id) {
    api_check_csrf();
    $b = api_json_body();
    $pdo->prepare('UPDATE users SET full_name=?,role=?,is_active=? WHERE id=?')
        ->execute([$b['full_name']??null, $b['role']??'viewer', $b['is_active']??1, $id]);
    api_response(true, ['id' => $id]);
}

// DELETE /api/users/{id}
if ($method === 'DELETE' && $id) {
    api_check_csrf();
    $user = api_require_auth();
    if ((int)$user['id'] === $id) api_response(false, null, 'Cannot delete yourself', 400);
    $pdo->prepare('DELETE FROM users WHERE id=?')->execute([$id]);
    api_response(true);
}

// POST /api/users/{id}/reset-password
if ($method === 'POST' && $id && $action === 'reset-password') {
    api_check_csrf();
    $b    = api_json_body();
    $hash = password_hash($b['password'] ?? '', PASSWORD_ARGON2ID);
    $pdo->prepare('UPDATE users SET password_hash=? WHERE id=?')->execute([$hash, $id]);
    api_response(true);
}

api_response(false, null, 'NOT_FOUND', 404);
