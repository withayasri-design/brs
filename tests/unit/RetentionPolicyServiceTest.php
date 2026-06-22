<?php
use PHPUnit\Framework\TestCase;

class RetentionPolicyServiceTest extends TestCase
{
    public function testKeepsDailyBackups(): void
    {
        // 10 backups, retain 7 daily → oldest 3 should be expired
        $backups = $this->makeBackups(10, '-1 day');
        $svc = new RetentionPolicyService(Database::pdo());
        // We'll use a stub/mock approach: test the pure logic method
        $expired = $svc->computeExpired($backups, daily: 7, weekly: 0, monthly: 0);
        $this->assertCount(3, $expired);
    }

    public function testPinnedBackupsNeverExpire(): void
    {
        $backups = $this->makeBackups(10, '-1 day');
        $backups[0]['is_pinned'] = 1;  // Pin the oldest
        $svc = new RetentionPolicyService(Database::pdo());
        $expired = $svc->computeExpired($backups, daily: 7, weekly: 0, monthly: 0);
        $expiredIds = array_column($expired, 'id');
        $this->assertNotContains($backups[0]['id'], $expiredIds);
    }

    private function makeBackups(int $count, string $step): array
    {
        $result = [];
        for ($i = 0; $i < $count; $i++) {
            $result[] = [
                'id'         => $i + 1,
                'started_at' => date('Y-m-d H:i:s', strtotime("$step * $i")),
                'is_pinned'  => 0,
                'status'     => 'success',
            ];
        }
        return $result;
    }
}
