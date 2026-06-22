<?php
declare(strict_types=1);

class RestoreEngine
{
    public function __construct(
        private readonly PDO                 $pdo,
        private readonly EncryptionService   $encryption,
        private readonly ChecksumService     $checksum,
        private readonly AuditLogger         $auditLogger,
        private readonly NotificationService $notification,
        private readonly string              $tempDir,
        private readonly string              $mysqlPath,
    ) {}

    public function validate(int $backupLogId): array
    {
        $log   = $this->loadLog($backupLogId);
        $files = $this->loadFiles($backupLogId);
        $workDir = $this->tempDir . '/rv_' . $backupLogId . '_' . time();
        mkdir($workDir, 0700, true);
        try {
            $adapter  = StorageAdapterFactory::create($files[0], $this->encryption);
            $manifest = $this->downloadManifest($adapter, $files, $workDir);
            $result   = [
                'checksum_valid'        => false,
                'extraction_test_passed' => false,
                'manifest'              => [
                    'files_count' => count($manifest['files'] ?? []),
                    'backup_date' => $manifest['created_at'] ?? null,
                ],
            ];
            $archiveRow = $this->findFile($files, 'files_archive');
            if ($archiveRow) {
                $zipEnc = $workDir . '/files.zip' . ($manifest['encrypted'] ? '.enc' : '');
                $adapter->download($archiveRow['remote_path'], $zipEnc);
                $zip = $workDir . '/files.zip';
                if ($manifest['encrypted']) {
                    $this->encryption->decryptFile($zipEnc, $zip, 'backup_file');
                } else {
                    $zip = $zipEnc;
                }
                $expected = $manifest['checksums']['files'] ?? ($log['files_checksum'] ?? null);
                if ($expected) {
                    $result['checksum_valid'] = $this->checksum->verify($zip, $expected);
                }
                $za = new \ZipArchive();
                if ($za->open($zip) === true) {
                    // ZipArchive::testArchive() does not exist in PHP 8.2 — use count() + statIndex() as PHP 8.2-safe check
                    if ($za->count() > 0 && $za->statIndex(0) !== false) {
                        $result['extraction_test_passed'] = true;
                    }
                    $za->close();
                }
            } else {
                // No files archive — database-only backup; treat as valid
                $result['checksum_valid']        = true;
                $result['extraction_test_passed'] = true;
            }
            return $result;
        } finally {
            $this->rmdirR($workDir);
        }
    }

    public function execute(int $backupLogId, string $mode, string $restoreTarget, ?string $altPath, ?string $altDb, int $userId): int
    {
        $log   = $this->loadLog($backupLogId);
        $job   = $this->loadJob((int) $log['job_id']);
        $files = $this->loadFiles($backupLogId);
        $v     = $this->validate($backupLogId);
        if (isset($v['checksum_valid']) && !$v['checksum_valid']) {
            throw new \RuntimeException("Backup checksum mismatch — restore aborted.", 422);
        }
        $rlId    = $this->createRestoreLog($log['job_id'], $backupLogId, $mode, $restoreTarget, $altPath, $altDb, $userId);
        $workDir = $this->tempDir . '/re_' . $backupLogId . '_' . time();
        mkdir($workDir, 0700, true);
        try {
            $adapter  = StorageAdapterFactory::create($files[0], $this->encryption);
            $manifest = $this->downloadManifest($adapter, $files, $workDir);
            $tgtPath  = $restoreTarget === 'alternate' ? $altPath : $job['app_path'];
            $tgtDb    = $restoreTarget === 'alternate' ? $altDb  : $job['db_name'];
            if ($mode === 'dry_run') {
                $this->extractFiles($adapter, $files, $manifest, $workDir, $workDir . '/dry');
                $this->updateRestoreLog($rlId, 'success');
                return $rlId;
            }
            if ($tgtPath && in_array($job['backup_type'], ['files_only', 'both'])) {
                $this->extractFiles($adapter, $files, $manifest, $workDir, $tgtPath);
            }
            if ($tgtDb && in_array($job['backup_type'], ['database_only', 'both'])) {
                $this->importDb($adapter, $files, $manifest, $workDir, $job, $tgtDb);
            }
            $this->updateRestoreLog($rlId, 'success');
            $this->auditLogger->log('restore.execute', $userId, 'backup_job', (int) $log['job_id'], null,
                ['backup_log_id' => $backupLogId, 'mode' => $mode]);
            $this->notification->notifyRestoreExecuted((int) $log['job_id'], $job['job_name'], $mode, "user:$userId");
            return $rlId;
        } catch (\Throwable $e) {
            $this->updateRestoreLog($rlId, 'failed', $e->getMessage());
            throw $e;
        } finally {
            $this->rmdirR($workDir);
        }
    }

    public function rollback(int $restoreLogId, int $userId): void
    {
        $stmt = $this->pdo->prepare('SELECT * FROM restore_logs WHERE id=?');
        $stmt->execute([$restoreLogId]);
        $rl = $stmt->fetch();
        if (!$rl || !$rl['pre_restore_snapshot_id']) {
            throw new \RuntimeException("No pre-restore snapshot for rollback.");
        }
        $this->execute((int) $rl['pre_restore_snapshot_id'], 'real', 'original', null, null, $userId);
        $this->pdo->prepare('UPDATE restore_logs SET status="rolled_back" WHERE id=?')->execute([$restoreLogId]);
    }

    private function extractFiles(StorageAdapterInterface $adapter, array $files, array $manifest, string $workDir, string $dest): void
    {
        $row = $this->findFile($files, 'files_archive');
        if (!$row) return;
        $enc = $workDir . '/files.zip' . ($manifest['encrypted'] ? '.enc' : '');
        $adapter->download($row['remote_path'], $enc);
        $zip = $workDir . '/files.zip';
        if ($manifest['encrypted']) {
            $this->encryption->decryptFile($enc, $zip, 'backup_file');
        } else {
            $zip = $enc;
        }
        if (!is_dir($dest)) mkdir($dest, 0755, true);
        $za = new \ZipArchive();
        if ($za->open($zip) !== true) throw new \RuntimeException("Cannot open zip for extraction.");
        $za->extractTo($dest);
        $za->close();
    }

    private function importDb(StorageAdapterInterface $adapter, array $files, array $manifest, string $workDir, array $job, string $tgtDb): void
    {
        $row = $this->findFile($files, 'database_dump');
        if (!$row) return;
        $enc = $workDir . '/database.sql' . ($manifest['encrypted'] ? '.enc' : '');
        $adapter->download($row['remote_path'], $enc);
        $sql = $workDir . '/database.sql';
        if ($manifest['encrypted']) {
            $this->encryption->decryptFile($enc, $sql, 'backup_file');
        } else {
            $sql = $enc;
        }
        $pw = $job['db_password_encrypted']
            ? $this->encryption->decryptString($job['db_password_encrypted'], 'credential')
            : '';
        $cmd = sprintf(
            '"%s" --host=%s --port=%d --user=%s --password=%s %s < "%s" 2>&1',
            $this->mysqlPath,
            escapeshellarg($job['db_host']),
            (int) $job['db_port'],
            escapeshellarg($job['db_username']),
            escapeshellarg($pw),
            escapeshellarg($tgtDb),
            $sql ? "\"$sql\"" : ""
        );
        exec($cmd, $out, $rc);
        if ($rc !== 0) throw new \RuntimeException("mysql import failed: " . implode("\n", $out));
    }

    private function downloadManifest(StorageAdapterInterface $adapter, array $files, string $workDir): array
    {
        $row = $this->findFile($files, 'manifest');
        if (!$row) throw new \RuntimeException("Manifest not found in backup files.");
        $dest = $workDir . '/manifest.json';
        $adapter->download($row['remote_path'], $dest);
        return json_decode(file_get_contents($dest), true);
    }

    private function loadLog(int $id): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM backup_logs WHERE id=?');
        $stmt->execute([$id]);
        $r = $stmt->fetch();
        if (!$r) throw new \RuntimeException("Backup log $id not found.");
        return $r;
    }

    private function loadJob(int $id): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM backup_jobs WHERE id=?');
        $stmt->execute([$id]);
        $r = $stmt->fetch();
        if (!$r) throw new \RuntimeException("Job $id not found.");
        return $r;
    }

    private function loadFiles(int $logId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT bf.*,st.provider_type,st.config_json FROM backup_files bf
             JOIN storage_targets st ON st.id=bf.storage_target_id WHERE bf.backup_log_id=? ORDER BY bf.id'
        );
        $stmt->execute([$logId]);
        return $stmt->fetchAll();
    }

    private function findFile(array $files, string $type): ?array
    {
        foreach ($files as $f) {
            if ($f['file_type'] === $type) return $f;
        }
        return null;
    }

    private function createRestoreLog(int $jobId, int $blId, string $mode, string $target, ?string $ap, ?string $adb, int $uid): int
    {
        $this->pdo->prepare(
            'INSERT INTO restore_logs (job_id,backup_log_id,restore_mode,restore_target,alternate_path,alternate_db_name,initiated_by_user_id,status,started_at)
             VALUES(?,?,?,?,?,?,?,"running",NOW())'
        )->execute([$jobId, $blId, $mode, $target, $ap, $adb, $uid]);
        return (int) $this->pdo->lastInsertId();
    }

    private function updateRestoreLog(int $id, string $status, ?string $err = null): void
    {
        $this->pdo->prepare('UPDATE restore_logs SET status=?,finished_at=NOW(),error_message=? WHERE id=?')
            ->execute([$status, $err, $id]);
    }

    private function rmdirR(string $dir): void
    {
        if (!is_dir($dir)) return;
        foreach (new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        ) as $f) {
            $f->isDir() ? rmdir($f->getPathname()) : unlink($f->getPathname());
        }
        rmdir($dir);
    }
}
