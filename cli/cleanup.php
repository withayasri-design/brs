<?php
require_once __DIR__ . '/common.php';
$args   = cli_parse_args($argv);
$dryRun = isset($args['dry-run']);
$pdo    = Database::pdo();
$svc    = new RetentionPolicyService($pdo);
$jobIds = [];
if (isset($args['job-id'])) { $jobIds[]=(int)$args['job-id']; }
elseif (isset($args['all'])) { $jobIds=array_column($pdo->query('SELECT id FROM backup_jobs WHERE is_active=1')->fetchAll(),'id'); }
else cli_exit("Usage: php cleanup.php --job-id=N | --all [--dry-run]");
foreach ($jobIds as $jid) {
    $s=$pdo->prepare('SELECT job_name,retention_daily,retention_weekly,retention_monthly FROM backup_jobs WHERE id=?');
    $s->execute([$jid]); $job=$s->fetch();
    $exp=$svc->getExpiredBackupIds($jid,(int)$job['retention_daily'],(int)$job['retention_weekly'],(int)$job['retention_monthly']);
    if (!$exp) { echo "[job=$jid] {$job['job_name']}: no expired backups\n"; continue; }
    echo "[job=$jid] {$job['job_name']}: ".count($exp)." expired".($dryRun?' (dry-run)':'')."\n";
    if (!$dryRun) {
        foreach ($exp as $eid) {
            $fs=$pdo->prepare('SELECT bf.*,st.provider_type,st.config_json FROM backup_files bf JOIN storage_targets st ON st.id=bf.storage_target_id WHERE bf.backup_log_id=?');
            $fs->execute([$eid]);
            foreach ($fs->fetchAll() as $f) {
                try { StorageAdapterFactory::create($f)->delete($f['remote_path']); }
                catch (\Exception $e) { fwrite(STDERR,"Warning delete {$f['remote_path']}: {$e->getMessage()}\n"); }
            }
            $pdo->prepare('DELETE FROM backup_logs WHERE id=?')->execute([$eid]);
            echo "  deleted backup_log_id=$eid\n";
        }
    }
}
exit(0);
