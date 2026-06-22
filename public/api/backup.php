<?php
require_once __DIR__ . '/common.php';
$user   = api_require_auth();
$method = $_SERVER['REQUEST_METHOD'];
$uri    = trim(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH), '/');
$pdo    = Database::pdo();

// GET /api/jobs/{id}/history
if ($method === 'GET' && preg_match('#api/jobs/(\d+)/history$#', $uri, $m)) {
    $jobId = (int)$m[1];
    $page  = max(1,(int)($_GET['page']??1));
    $limit = min(100,max(1,(int)($_GET['limit']??20)));
    $total = $pdo->prepare('SELECT COUNT(*) FROM backup_logs WHERE job_id=?');
    $total->execute([$jobId]);
    $stmt  = $pdo->prepare('SELECT id,started_at,finished_at,status,verification_status,total_size_bytes,is_encrypted,is_pinned,triggered_by FROM backup_logs WHERE job_id=? ORDER BY started_at DESC LIMIT ? OFFSET ?');
    $stmt->execute([$jobId,$limit,($page-1)*$limit]);
    api_response(true,['items'=>$stmt->fetchAll(),'total'=>(int)$total->fetchColumn(),'page'=>$page,'limit'=>$limit]);
}

// GET /api/backup-logs/{id}
if ($method === 'GET' && preg_match('#api/backup-logs/(\d+)$#', $uri, $m)) {
    $stmt = $pdo->prepare('SELECT * FROM backup_logs WHERE id=?');
    $stmt->execute([(int)$m[1]]);
    $log  = $stmt->fetch();
    if (!$log) api_response(false,null,'NOT_FOUND',404);
    api_response(true,$log);
}

// GET /api/backup-logs/{id}/status
if ($method === 'GET' && preg_match('#api/backup-logs/(\d+)/status$#', $uri, $m)) {
    $stmt = $pdo->prepare('SELECT status,verification_status FROM backup_logs WHERE id=?');
    $stmt->execute([(int)$m[1]]);
    $row  = $stmt->fetch();
    if (!$row) api_response(false,null,'NOT_FOUND',404);
    api_response(true,['status'=>$row['status'],'verification_status'=>$row['verification_status']]);
}

// POST /api/backup-logs/{id}/pin
if ($method === 'POST' && preg_match('#api/backup-logs/(\d+)/pin$#', $uri, $m)) {
    api_require_role('admin','operator');
    api_check_csrf();
    $pdo->prepare('UPDATE backup_logs SET is_pinned=1 WHERE id=?')->execute([(int)$m[1]]);
    api_response(true);
}

// DELETE /api/backup-logs/{id}
if ($method === 'DELETE' && preg_match('#api/backup-logs/(\d+)$#', $uri, $m)) {
    api_require_role('admin');
    api_check_csrf();
    $logId = (int)$m[1];
    $pdo->prepare('DELETE FROM backup_logs WHERE id=?')->execute([$logId]);
    (new AuditLogger($pdo))->log('backup.delete',(int)$user['id'],'backup_log',$logId,api_ip());
    api_response(true);
}

api_response(false,null,'NOT_FOUND',404);
