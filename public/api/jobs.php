<?php
require_once __DIR__ . '/common.php';
$user   = api_require_auth();
$method = $_SERVER['REQUEST_METHOD'];
$uri    = trim(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH), '/');
$pdo    = Database::pdo();

// Extract ID from URI: /api/jobs/{id} or /api/jobs/{id}/run
preg_match('#api/jobs(?:/(\d+)(?:/(run))?)?$#', $uri, $m);
$id     = isset($m[1]) ? (int)$m[1] : null;
$action = $m[2] ?? null;

// GET /api/jobs
if ($method === 'GET' && !$id) {
    $page  = max(1, (int)($_GET['page'] ?? 1));
    $limit = min(100, max(1, (int)($_GET['limit'] ?? 20)));
    $offset = ($page - 1) * $limit;
    $search = '%' . ($_GET['search'] ?? '') . '%';
    $total = $pdo->prepare('SELECT COUNT(*) FROM backup_jobs WHERE job_name LIKE ?');
    $total->execute([$search]);
    $stmt = $pdo->prepare(
        'SELECT bj.*,
          (SELECT status FROM backup_logs WHERE job_id=bj.id ORDER BY id DESC LIMIT 1) AS last_backup_status,
          (SELECT started_at FROM backup_logs WHERE job_id=bj.id ORDER BY id DESC LIMIT 1) AS last_backup_at
         FROM backup_jobs bj WHERE bj.job_name LIKE ? ORDER BY bj.id LIMIT ? OFFSET ?'
    );
    $stmt->execute([$search, $limit, $offset]);
    api_response(true, ['items' => $stmt->fetchAll(), 'total' => (int)$total->fetchColumn(), 'page' => $page, 'limit' => $limit]);
}

// GET /api/jobs/{id}
if ($method === 'GET' && $id) {
    $stmt = $pdo->prepare('SELECT * FROM backup_jobs WHERE id=?');
    $stmt->execute([$id]);
    $job = $stmt->fetch();
    if (!$job) api_response(false, null, 'NOT_FOUND', 404);
    unset($job['db_password_encrypted']);  // Never return encrypted password
    api_response(true, $job);
}

// POST /api/jobs (create)
if ($method === 'POST' && !$id) {
    api_require_role('admin', 'operator');
    api_check_csrf();
    $b   = api_json_body();
    $enc = new EncryptionService(Config::get('encryption_key_path'));
    $pwEnc = isset($b['db_password']) ? $enc->encryptString($b['db_password'], 'credential') : null;
    $stmt = $pdo->prepare(
        'INSERT INTO backup_jobs (job_name,description,app_path,include_patterns,exclude_patterns,db_host,db_port,db_name,db_username,db_password_encrypted,backup_type,encryption_enabled,schedule_cron,retention_daily,retention_weekly,retention_monthly,created_by)
         VALUES(:jn,:desc,:ap,:inc,:exc,:dbh,:dbp,:dbn,:dbu,:dbpw,:bt,:ee,:sc,:rd,:rw,:rm,:cb)'
    );
    $stmt->execute([
        'jn'=>$b['job_name'],'desc'=>$b['description']??null,'ap'=>$b['app_path']??null,
        'inc'=>isset($b['include_patterns'])?json_encode($b['include_patterns']):null,
        'exc'=>isset($b['exclude_patterns'])?json_encode($b['exclude_patterns']):null,
        'dbh'=>$b['db_host']??null,'dbp'=>$b['db_port']??3306,
        'dbn'=>$b['db_name']??null,'dbu'=>$b['db_username']??null,'dbpw'=>$pwEnc,
        'bt'=>$b['backup_type']??'both','ee'=>$b['encryption_enabled']??1,
        'sc'=>$b['schedule_cron']??null,'rd'=>$b['retention_daily']??7,
        'rw'=>$b['retention_weekly']??4,'rm'=>$b['retention_monthly']??6,'cb'=>$user['id'],
    ]);
    $newId = (int)$pdo->lastInsertId();
    if (isset($b['storage_target_ids'])) {
        foreach ($b['storage_target_ids'] as $pri => $stId) {
            $pdo->prepare('INSERT INTO job_storage_targets (job_id,storage_target_id,priority) VALUES(?,?,?)')
                ->execute([$newId, $stId, $pri + 1]);
        }
    }
    (new AuditLogger($pdo))->log('job.create', (int)$user['id'], 'backup_job', $newId, api_ip());
    api_response(true, ['id' => $newId], null, 201);
}

// PUT /api/jobs/{id}
if ($method === 'PUT' && $id) {
    api_require_role('admin', 'operator');
    api_check_csrf();
    $b   = api_json_body();
    $enc = new EncryptionService(Config::get('encryption_key_path'));
    // Only update password if a new one is provided
    $pwEnc = isset($b['db_password']) ? $enc->encryptString($b['db_password'], 'credential') : null;
    $setPw = $pwEnc ? ', db_password_encrypted=:dbpw' : '';
    $params = [
        'jn'=>$b['job_name']??null,'ap'=>$b['app_path']??null,
        'inc'=>isset($b['include_patterns'])?json_encode($b['include_patterns']):null,
        'exc'=>isset($b['exclude_patterns'])?json_encode($b['exclude_patterns']):null,
        'dbh'=>$b['db_host']??null,'dbp'=>$b['db_port']??3306,
        'dbn'=>$b['db_name']??null,'dbu'=>$b['db_username']??null,
        'bt'=>$b['backup_type']??'both','ee'=>$b['encryption_enabled']??1,
        'sc'=>$b['schedule_cron']??null,'ia'=>$b['is_active']??1,
        'rd'=>$b['retention_daily']??7,'rw'=>$b['retention_weekly']??4,'rm'=>$b['retention_monthly']??6,
        'id'=>$id,
    ];
    if ($pwEnc) $params['dbpw'] = $pwEnc;
    $pdo->prepare(
        "UPDATE backup_jobs SET job_name=:jn,app_path=:ap,include_patterns=:inc,exclude_patterns=:exc,
         db_host=:dbh,db_port=:dbp,db_name=:dbn,db_username=:dbu,backup_type=:bt,
         encryption_enabled=:ee,schedule_cron=:sc,is_active=:ia,
         retention_daily=:rd,retention_weekly=:rw,retention_monthly=:rm$setPw WHERE id=:id"
    )->execute($params);
    (new AuditLogger($pdo))->log('job.update', (int)$user['id'], 'backup_job', $id, api_ip());
    api_response(true, ['id' => $id]);
}

// DELETE /api/jobs/{id}
if ($method === 'DELETE' && $id) {
    api_require_role('admin');
    api_check_csrf();
    $b = api_json_body();
    $stmt = $pdo->prepare('SELECT job_name FROM backup_jobs WHERE id=?');
    $stmt->execute([$id]);
    $job = $stmt->fetch();
    if (!$job) api_response(false, null, 'NOT_FOUND', 404);
    if (($b['confirm_name'] ?? '') !== $job['job_name']) {
        api_response(false, null, 'Confirmation name does not match', 400);
    }
    $pdo->prepare('DELETE FROM backup_jobs WHERE id=?')->execute([$id]);
    (new AuditLogger($pdo))->log('job.delete', (int)$user['id'], 'backup_job', $id, api_ip());
    api_response(true);
}

// POST /api/jobs/{id}/run
if ($method === 'POST' && $id && $action === 'run') {
    api_require_role('admin', 'operator');
    api_check_csrf();
    $enc   = new EncryptionService(Config::get('encryption_key_path'));
    $eng   = new BackupEngine($pdo, $enc, new ChecksumService(),
        new LockManager(Config::get('temp_dir') . '/locks'),
        new AuditLogger($pdo),
        new NotificationService(Config::get('line_notify_token'), Config::get('notify_mode')),
        Config::get('temp_dir'), Config::get('mysqldump_path'));
    try {
        $logId = $eng->run($id, 'manual', (int)$user['id']);
        api_response(true, ['backup_log_id' => $logId, 'status' => 'success'], null, 202);
    } catch (\RuntimeException $e) {
        $code = match($e->getCode()) { 2=>422, 3=>409, default=>500 };
        api_response(false, null, $e->getMessage(), $code);
    }
}

api_response(false, null, 'NOT_FOUND', 404);
