<?php
require_once __DIR__ . '/common.php';
$user   = api_require_auth();
$method = $_SERVER['REQUEST_METHOD'];
$uri    = trim(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH), '/');
$pdo    = Database::pdo();

// GET /api/dashboard/summary
if ($method === 'GET' && str_ends_with($uri, 'dashboard/summary')) {
    $totalJobs  = (int)$pdo->query('SELECT COUNT(*) FROM backup_jobs')->fetchColumn();
    $activeJobs = (int)$pdo->query('SELECT COUNT(*) FROM backup_jobs WHERE is_active=1')->fetchColumn();
    $failed24h  = (int)$pdo->query("SELECT COUNT(*) FROM backup_logs WHERE status='failed' AND started_at>=DATE_SUB(NOW(),INTERVAL 24 HOUR)")->fetchColumn();
    $totalSize  = (int)$pdo->query('SELECT COALESCE(SUM(total_size_bytes),0) FROM backup_logs WHERE status="success"')->fetchColumn();
    $upcoming   = $pdo->query(
        'SELECT id AS job_id,job_name,schedule_cron FROM backup_jobs WHERE is_active=1 AND schedule_cron IS NOT NULL LIMIT 5'
    )->fetchAll();
    $storage    = $pdo->query('SELECT id,target_name FROM storage_targets WHERE is_active=1')->fetchAll();
    $storageUsage = [];
    foreach ($storage as $st) {
        try {
            $ad   = StorageAdapterFactory::create($st);
            $free = $ad->getFreeSpace();
            $storageUsage[] = ['target_name' => $st['target_name'], 'free_bytes' => $free];
        } catch (\Exception) {}
    }
    api_response(true, [
        'total_jobs' => $totalJobs, 'active_jobs' => $activeJobs,
        'jobs_failed_last_24h' => $failed24h, 'total_backup_size_bytes' => $totalSize,
        'storage_usage' => $storageUsage, 'upcoming_scheduled_jobs' => $upcoming,
    ]);
}

// GET /api/audit-logs
if ($method === 'GET' && str_ends_with($uri, 'audit-logs')) {
    api_require_role('admin');
    $page   = max(1,(int)($_GET['page']??1));
    $limit  = min(100,max(1,(int)($_GET['limit']??50)));
    $where  = '1=1';
    $params = [];
    if (!empty($_GET['user_id']))  { $where .= ' AND al.user_id=?';       $params[] = (int)$_GET['user_id']; }
    if (!empty($_GET['user']))     { $where .= ' AND u.username LIKE ?';  $params[] = '%'.$_GET['user'].'%'; }
    if (!empty($_GET['action']))   { $where .= ' AND al.action LIKE ?';   $params[] = '%'.$_GET['action'].'%'; }
    if (!empty($_GET['from']))     { $where .= ' AND al.created_at>=?';   $params[] = $_GET['from']; }
    if (!empty($_GET['to']))       { $where .= ' AND al.created_at<=?';   $params[] = $_GET['to'].' 23:59:59'; }
    $total = $pdo->prepare("SELECT COUNT(*) FROM audit_logs al LEFT JOIN users u ON u.id=al.user_id WHERE $where");
    $total->execute($params);
    $stmt  = $pdo->prepare(
        "SELECT al.*,u.username FROM audit_logs al LEFT JOIN users u ON u.id=al.user_id WHERE $where ORDER BY al.id DESC LIMIT ? OFFSET ?"
    );
    $stmt->execute(array_merge($params, [$limit, ($page-1)*$limit]));
    api_response(true, ['items' => $stmt->fetchAll(), 'total' => (int)$total->fetchColumn(), 'page' => $page]);
}

// GET /api/healthcheck
if ($method === 'GET' && str_ends_with($uri, 'api/healthcheck')) {
    $ok = true;
    $result = [
        'mysqldump_available' => false,
        'php_extensions' => [],
        'storage_targets' => [],
    ];
    exec('"' . Config::get('mysqldump_path') . '" --version 2>&1', $out, $rc);
    $result['mysqldump_available'] = ($rc === 0);
    if ($rc !== 0) $ok = false;
    foreach (['zip','openssl','pdo_mysql'] as $ext) {
        $result['php_extensions'][$ext] = extension_loaded($ext);
        if (!extension_loaded($ext)) $ok = false;
    }
    $targets = $pdo->query('SELECT * FROM storage_targets WHERE is_active=1')->fetchAll();
    foreach ($targets as $t) {
        try {
            $r = StorageAdapterFactory::create($t)->testConnection();
            $result['storage_targets'][] = ['id'=>$t['id'],'name'=>$t['target_name'],'status'=>$r['status']];
            if ($r['status']!=='success') $ok=false;
        } catch (\Exception $e) {
            $result['storage_targets'][] = ['id'=>$t['id'],'name'=>$t['target_name'],'status'=>'failed'];
            $ok = false;
        }
    }
    api_response($ok, $result, $ok?null:'Some checks failed');
}

api_response(false, null, 'NOT_FOUND', 404);
