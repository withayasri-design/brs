<?php
require_once __DIR__ . '/common.php';
$user   = api_require_auth();
$method = $_SERVER['REQUEST_METHOD'];
$uri    = trim(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH), '/');
$pdo    = Database::pdo();

function makeRestoreEngine(PDO $pdo): RestoreEngine {
    return new RestoreEngine($pdo,
        new EncryptionService(Config::get('encryption_key_path')),
        new ChecksumService(), new AuditLogger($pdo),
        new NotificationService(Config::get('line_notify_token'), Config::get('notify_mode')),
        Config::get('temp_dir'), Config::get('mysql_path'));
}

// POST /api/restore/validate
if ($method === 'POST' && str_ends_with($uri, 'restore/validate')) {
    api_check_csrf();
    $b   = api_json_body();
    $id  = (int)($b['backup_log_id'] ?? 0);
    if (!$id) api_response(false, null, 'backup_log_id required', 400);
    try {
        $result = makeRestoreEngine($pdo)->validate($id);
        api_response(true, $result);
    } catch (\RuntimeException $e) {
        api_response(false, null, $e->getMessage(), 422);
    }
}

// POST /api/restore/execute
if ($method === 'POST' && str_ends_with($uri, 'restore/execute')) {
    api_require_role('admin', 'operator');
    api_check_csrf();
    $b = api_json_body();
    $blId    = (int)($b['backup_log_id'] ?? 0);
    $mode    = $b['mode'] ?? 'dry_run';
    $target  = $b['restore_target'] ?? 'original';
    $altPath = $b['alternate_path'] ?? null;
    $altDb   = $b['alternate_db_name'] ?? null;
    $confirm = $b['confirm_job_name'] ?? null;

    if ($mode === 'real' && $target === 'original' && !$confirm) {
        api_response(false, null, 'confirm_job_name required for real restore', 400);
    }
    if ($confirm) {
        $stmt = $pdo->prepare('SELECT bj.job_name FROM backup_logs bl JOIN backup_jobs bj ON bj.id=bl.job_id WHERE bl.id=?');
        $stmt->execute([$blId]);
        $row = $stmt->fetch();
        if (!$row || $row['job_name'] !== $confirm) {
            api_response(false, null, 'confirm_job_name does not match job name', 400);
        }
    }
    if ($altPath && !PathValidator::isWithinAllowedBase($altPath, 'C:\\xampp\\htdocs')) {
        api_response(false, null, 'alternate_path is outside allowed directory', 400);
    }
    try {
        $rlId = makeRestoreEngine($pdo)->execute($blId, $mode, $target, $altPath, $altDb, (int)$user['id']);
        api_response(true, ['restore_log_id' => $rlId, 'status' => 'success'], null, 202);
    } catch (\RuntimeException $e) {
        api_response(false, null, $e->getMessage(), $e->getCode() ?: 500);
    }
}

// GET /api/restore-logs/{id}/status
if ($method === 'GET' && preg_match('#api/restore-logs/(\d+)/status$#', $uri, $m)) {
    $stmt = $pdo->prepare('SELECT status,error_message FROM restore_logs WHERE id=?');
    $stmt->execute([(int)$m[1]]);
    $row = $stmt->fetch();
    if (!$row) api_response(false, null, 'NOT_FOUND', 404);
    api_response(true, $row);
}

// POST /api/restore-logs/{id}/rollback
if ($method === 'POST' && preg_match('#api/restore-logs/(\d+)/rollback$#', $uri, $m)) {
    api_require_role('admin', 'operator');
    api_check_csrf();
    try {
        makeRestoreEngine($pdo)->rollback((int)$m[1], (int)$user['id']);
        api_response(true);
    } catch (\RuntimeException $e) {
        api_response(false, null, $e->getMessage(), 400);
    }
}

api_response(false, null, 'NOT_FOUND', 404);
