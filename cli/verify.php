<?php
require_once __DIR__ . '/common.php';
$args = cli_parse_args($argv);
$pdo  = Database::pdo();
$eng  = new RestoreEngine($pdo,
    new EncryptionService(Config::get('encryption_key_path')),
    new ChecksumService(), new AuditLogger($pdo),
    new NotificationService(null,'none'),
    Config::get('temp_dir'), Config::get('mysql_path'));
$ids = [];
if (isset($args['backup-log-id'])) {
    $ids[] = (int)$args['backup-log-id'];
} elseif (isset($args['job-id'])) {
    $s = $pdo->prepare("SELECT id FROM backup_logs WHERE job_id=? AND status='success' ORDER BY id DESC LIMIT 1");
    $s->execute([(int)$args['job-id']]); if ($r=$s->fetch()) $ids[]=(int)$r['id'];
} elseif (isset($args['all-recent'])) {
    $d=(int)($args['days']??7);
    $s=$pdo->prepare("SELECT id FROM backup_logs WHERE status='success' AND started_at>=DATE_SUB(NOW(),INTERVAL ? DAY)");
    $s->execute([$d]); $ids=array_column($s->fetchAll(),'id');
} else { cli_exit("Usage: php verify.php --backup-log-id=N | --job-id=N | --all-recent --days=7"); }
$exit=0;
foreach ($ids as $id) {
    echo "Verifying backup_log_id=$id ...\n";
    try {
        $r=$eng->validate($id);
        $ok=$r['checksum_valid']&&$r['extraction_test_passed'];
        echo ($ok?'  ✓ PASSED':'  ✗ FAILED') . "\n";
        $pdo->prepare('UPDATE backup_logs SET verification_status=? WHERE id=?')
            ->execute([$ok?'passed':'failed',$id]);
        if (!$ok) $exit=1;
    } catch (\Exception $e) { echo "  ✗ ERROR: {$e->getMessage()}\n"; $exit=1; }
}
exit($exit);
