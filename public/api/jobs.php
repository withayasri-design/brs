<?php
require_once __DIR__ . '/common.php';
$user   = api_require_auth();
$method = $_SERVER['REQUEST_METHOD'];
$uri    = trim(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH), '/');
$pdo    = Database::pdo();

// GET /api/jobs/export
if ($method === 'GET' && str_ends_with($uri, 'jobs/export')) {
    api_require_role('admin');
    $jobs = $pdo->query(
        'SELECT bj.*, GROUP_CONCAT(st.target_name ORDER BY jst.priority SEPARATOR ",") AS storage_target_names
         FROM backup_jobs bj
         LEFT JOIN job_storage_targets jst ON jst.job_id=bj.id
         LEFT JOIN storage_targets st ON st.id=jst.storage_target_id
         GROUP BY bj.id ORDER BY bj.id'
    )->fetchAll();
    $export = ['exported_at' => date('c'), 'brs_version' => '1.0', 'jobs' => array_map(function ($j) {
        return [
            'job_name'            => $j['job_name'],
            'description'         => $j['description'],
            'app_path'            => $j['app_path'],
            'include_patterns'    => json_decode($j['include_patterns'] ?? '["*"]', true),
            'exclude_patterns'    => json_decode($j['exclude_patterns'] ?? '[]', true),
            'db_host'             => $j['db_host'],
            'db_port'             => (int)$j['db_port'],
            'db_name'             => $j['db_name'],
            'db_username'         => $j['db_username'],
            'backup_type'         => $j['backup_type'],
            'encryption_enabled'  => (bool)$j['encryption_enabled'],
            'schedule_cron'       => $j['schedule_cron'],
            'retention_daily'     => (int)$j['retention_daily'],
            'retention_weekly'    => (int)$j['retention_weekly'],
            'retention_monthly'   => (int)$j['retention_monthly'],
            'is_active'           => (bool)$j['is_active'],
            'storage_target_names'=> $j['storage_target_names'] ? explode(',', $j['storage_target_names']) : [],
            // db_password intentionally omitted — must be re-entered on import
        ];
    }, $jobs)];
    api_response(true, $export);
}

// POST /api/jobs/import
if ($method === 'POST' && str_ends_with($uri, 'jobs/import')) {
    api_require_role('admin');
    api_check_csrf();
    $b       = api_json_body();
    $jobs    = $b['jobs'] ?? [];
    $execute = !empty($b['execute']);
    if (empty($jobs) || !is_array($jobs)) api_response(false, null, 'jobs array required', 400);

    // Pre-flight: resolve storage target names to IDs
    $allTargets = $pdo->query('SELECT id, target_name FROM storage_targets')->fetchAll();
    $targetMap  = array_column($allTargets, 'id', 'target_name');

    $preview = [];
    foreach ($jobs as $j) {
        $resolvedIds = [];
        $missing     = [];
        foreach ($j['storage_target_names'] ?? [] as $name) {
            isset($targetMap[$name]) ? ($resolvedIds[] = $targetMap[$name]) : ($missing[] = $name);
        }
        $preview[] = [
            'job_name'             => $j['job_name'],
            'resolved_target_ids'  => $resolvedIds,
            'missing_targets'      => $missing,
            'will_create'          => $execute,
        ];
    }

    if (!$execute) {
        api_response(true, ['preview' => $preview, 'execute' => false]);
    }

    $enc     = new EncryptionService(Config::get('encryption_key_path'));
    $created = [];
    foreach ($jobs as $idx => $j) {
        $resolvedIds = $preview[$idx]['resolved_target_ids'];
        $pdo->prepare(
            'INSERT INTO backup_jobs (job_name,description,app_path,include_patterns,exclude_patterns,
             db_host,db_port,db_name,db_username,backup_type,encryption_enabled,schedule_cron,
             retention_daily,retention_weekly,retention_monthly,is_active,created_by)
             VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)'
        )->execute([
            $j['job_name'], $j['description'] ?? null, $j['app_path'] ?? null,
            json_encode($j['include_patterns'] ?? ['*']),
            json_encode($j['exclude_patterns'] ?? []),
            $j['db_host'] ?? null, $j['db_port'] ?? 3306,
            $j['db_name'] ?? null, $j['db_username'] ?? null,
            $j['backup_type'] ?? 'both', $j['encryption_enabled'] ? 1 : 0,
            $j['schedule_cron'] ?? null,
            $j['retention_daily'] ?? 7, $j['retention_weekly'] ?? 4, $j['retention_monthly'] ?? 6,
            $j['is_active'] ?? 1, (int)$user['id'],
        ]);
        $newId = (int)$pdo->lastInsertId();
        foreach ($resolvedIds as $pri => $tid) {
            $pdo->prepare('INSERT INTO job_storage_targets (job_id,storage_target_id,priority) VALUES(?,?,?)')
                ->execute([$newId, $tid, $pri + 1]);
        }
        (new AuditLogger($pdo))->log('job.import', (int)$user['id'], 'backup_job', $newId, api_ip());
        $created[] = ['id' => $newId, 'job_name' => $j['job_name']];
    }
    api_response(true, ['created' => $created, 'execute' => true], null, 201);
}

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
    $items = array_map(function($row) { unset($row['db_password_encrypted']); return $row; }, $stmt->fetchAll());
    api_response(true, ['items' => $items, 'total' => (int)$total->fetchColumn(), 'page' => $page, 'limit' => $limit]);
}

// GET /api/jobs/{id}
if ($method === 'GET' && $id) {
    $stmt = $pdo->prepare('SELECT * FROM backup_jobs WHERE id=?');
    $stmt->execute([$id]);
    $job = $stmt->fetch();
    if (!$job) api_response(false, null, 'NOT_FOUND', 404);
    unset($job['db_password_encrypted']);  // Never return encrypted password
    $jst = $pdo->prepare('SELECT storage_target_id FROM job_storage_targets WHERE job_id=? ORDER BY priority');
    $jst->execute([$id]);
    $job['storage_target_ids'] = array_column($jst->fetchAll(), 'storage_target_id');
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
    if (isset($b['storage_target_ids'])) {
        $pdo->prepare('DELETE FROM job_storage_targets WHERE job_id=?')->execute([$id]);
        foreach ($b['storage_target_ids'] as $pri => $stId) {
            $pdo->prepare('INSERT INTO job_storage_targets (job_id,storage_target_id,priority) VALUES(?,?,?)')
                ->execute([$id, $stId, $pri + 1]);
        }
    }
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
