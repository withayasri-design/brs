<?php
declare(strict_types=1);

class BackupEngine
{
    public function __construct(
        private readonly PDO                 $pdo,
        private readonly EncryptionService   $encryption,
        private readonly ChecksumService     $checksum,
        private readonly LockManager         $lockManager,
        private readonly AuditLogger         $auditLogger,
        private readonly NotificationService $notification,
        private readonly string              $tempDir,
        private readonly string              $mysqldumpPath,
    ) {}

    public function run(int $jobId, string $triggeredBy = 'cli', ?int $userId = null): int
    {
        $job = $this->loadJob($jobId);
        if (!$this->lockManager->acquire($jobId)) {
            throw new \RuntimeException("Job $jobId already running.", 3);
        }
        $logId   = $this->createLog($jobId, $triggeredBy, $userId);
        $workDir = $this->tempDir . DIRECTORY_SEPARATOR . "bk_{$jobId}_" . time();
        mkdir($workDir, 0700, true);
        try {
            $adapters  = $this->loadAdapters($jobId);
            $this->checkDiskSpace($job, $workDir);
            $filePaths = [];
            $checksums = [];

            if (in_array($job['backup_type'], ['database_only', 'both'])) {
                $sql = $workDir . '/database.sql';
                $this->dumpDatabase($job, $sql);
                $checksums['database'] = $this->checksum->generate($sql);
                if ($job['encryption_enabled']) {
                    $this->encryption->encryptFile($sql, $sql . '.enc', 'backup_file');
                    unlink($sql); $sql .= '.enc';
                }
                $filePaths['database'] = $sql;
            }
            if (in_array($job['backup_type'], ['files_only', 'both'])) {
                $zip = $workDir . '/files.zip';
                $this->zipFiles($job, $zip);
                $checksums['files'] = $this->checksum->generate($zip);
                if ($job['encryption_enabled']) {
                    $this->encryption->encryptFile($zip, $zip . '.enc', 'backup_file');
                    unlink($zip); $zip .= '.enc';
                }
                $filePaths['files'] = $zip;
            }

            $manifest = $workDir . '/manifest.json';
            file_put_contents($manifest, json_encode([
                'job_id' => $jobId, 'job_name' => $job['job_name'],
                'backup_log_id' => $logId, 'created_at' => date('c'),
                'backup_type' => $job['backup_type'],
                'encrypted' => (bool) $job['encryption_enabled'],
                'checksums' => $checksums,
                'files' => array_map('basename', $filePaths),
            ], JSON_PRETTY_PRINT));
            $filePaths['manifest'] = $manifest;

            $this->verifyBackup($filePaths, (bool) $job['encryption_enabled']);

            $total  = 0;
            $prefix = "{$jobId}/" . date('Y-m-d_His');
            foreach ($adapters as ['adapter' => $ad, 'target_id' => $tid]) {
                foreach ($filePaths as $type => $lp) {
                    $rp   = $prefix . '/' . basename($lp);
                    $ad->upload($lp, $rp);
                    $sz   = filesize($lp) ?: 0;
                    $total += $sz;
                    $this->recordFile($logId, $tid, $type, $rp, $sz);
                }
            }

            $this->finalizeLog($logId, 'success', $total,
                $checksums['files'] ?? null, $checksums['database'] ?? null,
                $manifest, (bool) $job['encryption_enabled']);

            (new RetentionPolicyService($this->pdo))->getExpiredBackupIds(
                $jobId, (int)$job['retention_daily'], (int)$job['retention_weekly'], (int)$job['retention_monthly']
            );
            $this->notification->notifyBackupSuccess($jobId, $job['job_name'], 0, $total);
            $this->auditLogger->log('backup.success', $userId, 'backup_job', $jobId);
            return $logId;
        } catch (\Throwable $e) {
            $this->pdo->prepare('UPDATE backup_logs SET status="failed",finished_at=NOW(),error_message=? WHERE id=?')
                ->execute([$e->getMessage(), $logId]);
            $this->notification->notifyBackupFailure($jobId, $job['job_name'], $e->getMessage());
            throw $e;
        } finally {
            $this->rmdirR($workDir);
            $this->lockManager->release($jobId);
        }
    }

    public function isDue(int $jobId): bool
    {
        $stmt = $this->pdo->prepare('SELECT schedule_cron,is_active FROM backup_jobs WHERE id=?');
        $stmt->execute([$jobId]);
        $job = $stmt->fetch();
        if (!$job || !$job['is_active'] || !$job['schedule_cron']) return false;
        return $this->cronMatches($job['schedule_cron'], new \DateTimeImmutable());
    }

    private function loadJob(int $id): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM backup_jobs WHERE id=? AND is_active=1');
        $stmt->execute([$id]);
        $job = $stmt->fetch();
        if (!$job) throw new \RuntimeException("Job $id not found or disabled.");
        return $job;
    }

    private function loadAdapters(int $jobId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT st.*,jst.priority FROM storage_targets st
             JOIN job_storage_targets jst ON st.id=jst.storage_target_id
             WHERE jst.job_id=? AND st.is_active=1 ORDER BY jst.priority'
        );
        $stmt->execute([$jobId]);
        return array_map(fn($t) => [
            'adapter'   => StorageAdapterFactory::create($t, $this->encryption),
            'target_id' => $t['id'],
        ], $stmt->fetchAll());
    }

    private function checkDiskSpace(array $job, string $dir): void
    {
        $free = disk_free_space($dir);
        if ($free === false) return;
        $est = ($job['app_path'] && is_dir($job['app_path'])) ? $this->dirSize($job['app_path']) : 0;
        $req = (int)($est * 1.2) + 104857600;
        if ($free < $req) {
            throw new \RuntimeException(sprintf("Insufficient disk space: %.1fGB free, %.1fGB required.", $free/1073741824, $req/1073741824), 2);
        }
    }

    private function dumpDatabase(array $job, string $dest): void
    {
        $pw = $job['db_password_encrypted']
            ? $this->encryption->decryptString($job['db_password_encrypted'], 'credential') : '';
        $cmd = sprintf('"%s" --host=%s --port=%d --user=%s --password=%s --single-transaction --routines --triggers --databases %s > "%s" 2>&1',
            $this->mysqldumpPath,
            escapeshellarg($job['db_host']), (int)$job['db_port'],
            escapeshellarg($job['db_username']), escapeshellarg($pw),
            escapeshellarg($job['db_name']), $dest);
        exec($cmd, $out, $rc);
        if ($rc !== 0) throw new \RuntimeException("mysqldump failed: " . implode("\n", $out));
        $tail = file_get_contents($dest, false, null, -512);
        if (!str_contains((string)$tail, 'Dump completed')) {
            throw new \RuntimeException("mysqldump output appears incomplete.");
        }
    }

    private function zipFiles(array $job, string $dest): void
    {
        $exclude = json_decode($job['exclude_patterns'] ?? '[]', true) ?: [];
        $zip = new \ZipArchive();
        if ($zip->open($dest, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) {
            throw new \RuntimeException("Cannot create zip: $dest");
        }
        $base = rtrim($job['app_path'], '/\\');
        $iter = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($base, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST
        );
        foreach ($iter as $file) {
            $rel = str_replace($base . DIRECTORY_SEPARATOR, '', $file->getPathname());
            if ($this->isExcluded($rel, $exclude)) continue;
            $file->isDir() ? $zip->addEmptyDir($rel) : $zip->addFile($file->getPathname(), $rel);
        }
        $zip->close();
    }

    private function verifyBackup(array $filePaths, bool $encrypted): void
    {
        if (isset($filePaths['files']) && !$encrypted) {
            $zip = new \ZipArchive();
            if ($zip->open($filePaths['files']) !== true) {
                throw new \RuntimeException("Verification failed: cannot open zip archive.");
            }
            // ZipArchive::testArchive() is PHP 8.3+; use count() + stat() on first entry as PHP 8.2-safe check
            if ($zip->count() === 0) {
                $zip->close();
                throw new \RuntimeException("Verification failed: zip archive contains no entries.");
            }
            // Verify central directory is readable by stat-ing the first entry
            if ($zip->statIndex(0) === false) {
                $zip->close();
                throw new \RuntimeException("Verification failed: zip archive central directory unreadable.");
            }
            $zip->close();
        }
    }

    private function createLog(int $jobId, string $by, ?int $userId): int
    {
        $this->pdo->prepare('INSERT INTO backup_logs (job_id,triggered_by,triggered_by_user_id,status,started_at) VALUES(?,?,?,?,NOW())')
            ->execute([$jobId, $by, $userId, 'running']);
        return (int) $this->pdo->lastInsertId();
    }

    private function finalizeLog(int $id, string $status, int $size, ?string $fc, ?string $dc, string $mp, bool $enc): void
    {
        $this->pdo->prepare(
            'UPDATE backup_logs SET status=?,finished_at=NOW(),
             duration_seconds=TIMESTAMPDIFF(SECOND,started_at,NOW()),
             total_size_bytes=?,files_checksum=?,database_checksum=?,
             manifest_path=?,is_encrypted=?,verification_status="passed" WHERE id=?'
        )->execute([$status, $size, $fc, $dc, $mp, $enc?1:0, $id]);
    }

    private function recordFile(int $logId, int $tid, string $type, string $rp, int $sz): void
    {
        $map = ['files'=>'files_archive','database'=>'database_dump','manifest'=>'manifest'];
        $this->pdo->prepare('INSERT INTO backup_files (backup_log_id,storage_target_id,file_type,remote_path,size_bytes) VALUES(?,?,?,?,?)')
            ->execute([$logId, $tid, $map[$type] ?? $type, $rp, $sz]);
    }

    private function isExcluded(string $rel, array $patterns): bool
    {
        foreach ($patterns as $p) {
            if (fnmatch($p, $rel) || fnmatch($p, basename($rel))) return true;
        }
        return false;
    }

    private function cronMatches(string $cron, \DateTimeImmutable $now): bool
    {
        [$min,$hr,$dom,$mon,$dow] = explode(' ', $cron);
        $m = fn($f,$v) => $f==='*' || (int)$f===$v || (str_contains($f,'/')&&$v%(int)explode('/',$f)[1]===0);
        return $m($min,(int)$now->format('i')) && $m($hr,(int)$now->format('H'))
            && $m($dom,(int)$now->format('j')) && $m($mon,(int)$now->format('n'))
            && $m($dow,(int)$now->format('w'));
    }

    private function dirSize(string $dir): int
    {
        $sz = 0;
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir,\RecursiveDirectoryIterator::SKIP_DOTS)) as $f) {
            $sz += $f->getSize();
        }
        return $sz;
    }

    private function rmdirR(string $dir): void
    {
        if (!is_dir($dir)) return;
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir,\RecursiveDirectoryIterator::SKIP_DOTS),\RecursiveIteratorIterator::CHILD_FIRST) as $f) {
            $f->isDir() ? rmdir($f->getPathname()) : unlink($f->getPathname());
        }
        rmdir($dir);
    }
}
