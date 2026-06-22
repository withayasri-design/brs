<?php
require_once __DIR__ . '/common.php';
$user   = api_require_auth();
$method = $_SERVER['REQUEST_METHOD'];
$uri    = trim(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH), '/');
$pdo    = Database::pdo();

preg_match('#api/storage-targets(?:/(\d+)(?:/(test))?)?$#', $uri, $m);
$id     = isset($m[1]) ? (int)$m[1] : null;
$action = $m[2] ?? null;

// GET /api/storage-targets
if ($method === 'GET' && !$id) {
    $rows = $pdo->query('SELECT id,target_name,provider_type,is_active,last_test_status,last_test_at,created_at FROM storage_targets')->fetchAll();
    api_response(true, $rows);
}

// POST /api/storage-targets
if ($method === 'POST' && !$id) {
    api_require_role('admin');
    api_check_csrf();
    $b   = api_json_body();
    $enc = new EncryptionService(Config::get('encryption_key_path'));
    $cfg = json_encode($enc->encryptString(json_encode($b['config'] ?? []), 'credential'));
    $pdo->prepare('INSERT INTO storage_targets (target_name,provider_type,config_json) VALUES(?,?,?)')
        ->execute([$b['target_name'], $b['provider_type'], $b['config'] ? json_encode($b['config']) : '{}']);
    $newId = (int)$pdo->lastInsertId();
    (new AuditLogger($pdo))->log('storage.create',(int)$user['id'],'storage_target',$newId,api_ip());
    api_response(true, ['id' => $newId], null, 201);
}

// PUT /api/storage-targets/{id}
if ($method === 'PUT' && $id) {
    api_require_role('admin'); api_check_csrf();
    $b = api_json_body();
    $pdo->prepare('UPDATE storage_targets SET target_name=?,provider_type=?,config_json=?,is_active=? WHERE id=?')
        ->execute([$b['target_name'],$b['provider_type'],json_encode($b['config']??[]),$b['is_active']??1,$id]);
    api_response(true, ['id' => $id]);
}

// DELETE /api/storage-targets/{id}
if ($method === 'DELETE' && $id) {
    api_require_role('admin'); api_check_csrf();
    $pdo->prepare('DELETE FROM storage_targets WHERE id=?')->execute([$id]);
    api_response(true);
}

// POST /api/storage-targets/{id}/test
if ($method === 'POST' && $id && $action === 'test') {
    api_check_csrf();
    $stmt = $pdo->prepare('SELECT * FROM storage_targets WHERE id=?');
    $stmt->execute([$id]);
    $target = $stmt->fetch();
    if (!$target) api_response(false, null, 'NOT_FOUND', 404);
    $enc    = new EncryptionService(Config::get('encryption_key_path'));
    $result = StorageAdapterFactory::create($target, $enc)->testConnection();
    $pdo->prepare('UPDATE storage_targets SET last_test_status=?,last_test_at=NOW() WHERE id=?')
        ->execute([$result['status'], $id]);
    api_response(true, $result);
}

api_response(false, null, 'NOT_FOUND', 404);
