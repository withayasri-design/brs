<?php
require_once __DIR__ . '/common.php';
$args = cli_parse_args($argv);
if (!isset($args['backup-log-id'], $args['mode'])) {
    cli_exit("Usage: php restore.php --backup-log-id=N --mode=dry_run|real [--target=original|alternate] [--alt-path=P] [--alt-db=D] [--confirm=\"Name\"]");
}
$blId    = (int)$args['backup-log-id'];
$mode    = $args['mode'];
$target  = $args['target'] ?? 'original';
$confirm = $args['confirm'] ?? null;
if ($mode === 'real' && $target === 'original' && !$confirm) cli_exit("Real restore requires --confirm=\"Job Name\"");
$pdo = Database::pdo();
if ($confirm) {
    $stmt = $pdo->prepare('SELECT bj.job_name FROM backup_logs bl JOIN backup_jobs bj ON bj.id=bl.job_id WHERE bl.id=?');
    $stmt->execute([$blId]);
    $r = $stmt->fetch();
    if (!$r || $r['job_name'] !== $confirm) cli_exit("Confirmation does not match job name. Aborted.");
}
$eng = new RestoreEngine($pdo,
    new EncryptionService(Config::get('encryption_key_path')),
    new ChecksumService(),
    new AuditLogger($pdo),
    new NotificationService(Config::get('line_notify_token'), Config::get('notify_mode')),
    Config::get('temp_dir'), Config::get('mysql_path'));
try {
    $id = $eng->execute($blId, $mode, $target, $args['alt-path'] ?? null, $args['alt-db'] ?? null, 1);
    echo "Restore $mode completed. restore_log_id=$id\n";
    exit(0);
} catch (\Throwable $e) {
    cli_exit("Restore failed: " . $e->getMessage(), 1);
}
