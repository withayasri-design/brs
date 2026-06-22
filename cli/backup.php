<?php
require_once __DIR__ . '/common.php';
$args = cli_parse_args($argv);
$pdo  = Database::pdo();
$eng  = new BackupEngine($pdo,
    new EncryptionService(Config::get('encryption_key_path')),
    new ChecksumService(),
    new LockManager(Config::get('temp_dir') . '/locks'),
    new AuditLogger($pdo),
    new NotificationService(Config::get('line_notify_token'), Config::get('notify_mode')),
    Config::get('temp_dir'), Config::get('mysqldump_path'));

if (isset($args['all'])) {
    $rows = $pdo->query('SELECT id FROM backup_jobs WHERE is_active=1')->fetchAll();
    $code = 0;
    foreach ($rows as $r) {
        if ($eng->isDue((int)$r['id']) || isset($args['force'])) {
            try { $eng->run((int)$r['id'], 'schedule'); }
            catch (\Throwable $e) { fwrite(STDERR,"Job {$r['id']}: {$e->getMessage()}\n"); $code = max($code, $e->getCode()?:1); }
        }
    }
    exit($code);
}
if (!isset($args['job-id'])) cli_exit("Usage: php backup.php --job-id=N [--force] | --all");
try {
    $id = $eng->run((int)$args['job-id'], 'cli');
    echo "Backup completed. backup_log_id=$id\n";
    exit(0);
} catch (\Throwable $e) {
    cli_exit("Backup failed: " . $e->getMessage(), $e->getCode() ?: 1);
}
