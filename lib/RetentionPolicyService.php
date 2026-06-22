<?php
declare(strict_types=1);

class RetentionPolicyService
{
    public function __construct(private readonly PDO $pdo) {}

    /**
     * Return array of backup_log rows that should be deleted according to GFS policy.
     * Only considers backups with status='success' and is_pinned=0.
     */
    public function computeExpired(array $backups, int $daily, int $weekly, int $monthly): array
    {
        // Filter to only successful, unpinned backups; sort newest first
        $eligible = array_filter($backups, fn($b) => $b['status'] === 'success' && !$b['is_pinned']);
        usort($eligible, fn($a, $b) => strcmp($b['started_at'], $a['started_at']));

        $keep = [];

        // Daily: keep the N most recent
        $dailyKept = 0;
        foreach ($eligible as $b) {
            if ($dailyKept < $daily) {
                $keep[$b['id']] = true;
                $dailyKept++;
            }
        }

        // Weekly: keep one per week for N weeks (oldest in each week after daily window)
        $weeksSeen = [];
        foreach ($eligible as $b) {
            $week = date('oW', strtotime($b['started_at']));
            if (!isset($weeksSeen[$week]) && count($weeksSeen) < $weekly) {
                $keep[$b['id']] = true;
                $weeksSeen[$week] = true;
            }
        }

        // Monthly: keep one per month for N months
        $monthsSeen = [];
        foreach ($eligible as $b) {
            $month = date('Ym', strtotime($b['started_at']));
            if (!isset($monthsSeen[$month]) && count($monthsSeen) < $monthly) {
                $keep[$b['id']] = true;
                $monthsSeen[$month] = true;
            }
        }

        return array_values(array_filter($eligible, fn($b) => !isset($keep[$b['id']])));
    }

    /** Load backups for job, compute expired, return their IDs. */
    public function getExpiredBackupIds(int $jobId, int $daily, int $weekly, int $monthly): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, started_at, is_pinned, status FROM backup_logs
             WHERE job_id = :job_id ORDER BY started_at DESC'
        );
        $stmt->execute(['job_id' => $jobId]);
        $backups = $stmt->fetchAll();
        $expired = $this->computeExpired($backups, $daily, $weekly, $monthly);
        return array_column($expired, 'id');
    }
}
