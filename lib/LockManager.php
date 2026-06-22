<?php
declare(strict_types=1);

class LockManager
{
    public function __construct(private readonly string $lockDir)
    {
        if (!is_dir($this->lockDir)) {
            mkdir($this->lockDir, 0700, true);
        }
    }

    /** Returns true if lock was acquired, false if already locked by live process. */
    public function acquire(int $jobId): bool
    {
        $lockPath = $this->getLockPath($jobId);

        if (file_exists($lockPath)) {
            $data = json_decode(file_get_contents($lockPath), true);
            if ($data && $this->isProcessAlive((int) $data['pid'])) {
                return false;  // Job still running
            }
            // Stale lock — clear it
            unlink($lockPath);
        }

        // Use fopen 'x' mode for atomic lock creation (fails if file already exists)
        $handle = @fopen($lockPath, 'x');
        if ($handle === false) {
            return false;  // Race lost — another process created the lock first
        }

        $payload = json_encode([
            'pid'        => getmypid(),
            'hostname'   => gethostname(),
            'started_at' => date('Y-m-d H:i:s'),
        ]);
        fwrite($handle, $payload);
        fclose($handle);
        return true;
    }

    public function release(int $jobId): void
    {
        $lockPath = $this->getLockPath($jobId);
        if (file_exists($lockPath)) {
            unlink($lockPath);
        }
    }

    public function isLocked(int $jobId): bool
    {
        $lockPath = $this->getLockPath($jobId);
        if (!file_exists($lockPath)) {
            return false;
        }
        $data = json_decode(file_get_contents($lockPath), true);
        return $data && $this->isProcessAlive((int) $data['pid']);
    }

    /**
     * If lock is older than $maxMinutes and process is dead, clear it.
     * Returns true if stale lock was cleared.
     */
    public function clearStaleLockIfNeeded(int $jobId, int $maxMinutes): bool
    {
        $lockPath = $this->getLockPath($jobId);
        if (!file_exists($lockPath)) {
            return false;
        }
        $data = json_decode(file_get_contents($lockPath), true);
        if (!$data) {
            unlink($lockPath);
            return true;
        }
        $startedAt = strtotime($data['started_at']);
        if ($startedAt === false) {
            // Corrupt lock file — clear it
            unlink($lockPath);
            return true;
        }
        $age = (time() - $startedAt) / 60;
        if ($age > $maxMinutes && !$this->isProcessAlive((int) $data['pid'])) {
            unlink($lockPath);
            return true;
        }
        return false;
    }

    private function getLockPath(int $jobId): string
    {
        return $this->lockDir . DIRECTORY_SEPARATOR . $jobId . '.lock';
    }

    private function isProcessAlive(int $pid): bool
    {
        if (PHP_OS_FAMILY === 'Windows') {
            $output = shell_exec("tasklist /FI \"PID eq $pid\" /NH 2>NUL");
            return $output !== null && str_contains($output, (string) $pid);
        }
        return file_exists("/proc/$pid");
    }
}
