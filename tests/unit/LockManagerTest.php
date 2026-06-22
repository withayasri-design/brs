<?php
use PHPUnit\Framework\TestCase;

class LockManagerTest extends TestCase
{
    private string $lockDir;
    private LockManager $lm;

    protected function setUp(): void
    {
        $this->lockDir = sys_get_temp_dir() . '/brs_locks_' . uniqid();
        mkdir($this->lockDir, 0700, true);
        $this->lm = new LockManager($this->lockDir);
    }

    protected function tearDown(): void
    {
        array_map('unlink', glob($this->lockDir . '/*.lock'));
        @rmdir($this->lockDir);
    }

    public function testAcquireCreatesLockFile(): void
    {
        $result = $this->lm->acquire(99);
        $this->assertTrue($result);
        $this->assertFileExists($this->lockDir . '/99.lock');
    }

    public function testIsLockedReturnsTrueWhenLocked(): void
    {
        $this->lm->acquire(99);
        $this->assertTrue($this->lm->isLocked(99));
    }

    public function testReleaseRemovesLock(): void
    {
        $this->lm->acquire(99);
        $this->lm->release(99);
        $this->assertFalse($this->lm->isLocked(99));
    }

    public function testCannotAcquireAlreadyLockedJob(): void
    {
        $this->lm->acquire(99);
        $result = $this->lm->acquire(99);
        $this->assertFalse($result);
    }
}
