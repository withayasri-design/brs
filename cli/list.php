<?php
require_once __DIR__ . '/common.php';
$args = cli_parse_args($argv);
if (!isset($args['job-id'])) cli_exit("Usage: php list.php --job-id=N [--limit=20] [--format=table|json]");
$limit = (int)($args['limit'] ?? 20);
$fmt   = $args['format'] ?? 'table';
$stmt  = Database::pdo()->prepare(
    'SELECT id,started_at,status,verification_status,total_size_bytes,triggered_by
     FROM backup_logs WHERE job_id=? ORDER BY started_at DESC LIMIT ?'
);
$stmt->bindValue(1, (int)$args['job-id'], PDO::PARAM_INT);
$stmt->bindValue(2, $limit, PDO::PARAM_INT);
$stmt->execute();
$rows = $stmt->fetchAll();
if ($fmt === 'json') { echo json_encode($rows, JSON_PRETTY_PRINT) . "\n"; exit(0); }
printf("%-6s %-20s %-10s %-12s %-8s\n", 'ID','Started At','Status','Verified','Size');
echo str_repeat('-', 62) . "\n";
foreach ($rows as $r) {
    $sz = $r['total_size_bytes'] ? round($r['total_size_bytes']/1048576,1).'MB' : '-';
    printf("%-6d %-20s %-10s %-12s %-8s\n", $r['id'], $r['started_at'], $r['status'], $r['verification_status'], $sz);
}
exit(0);
