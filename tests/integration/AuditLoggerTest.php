<?php
use PHPUnit\Framework\TestCase;

class AuditLoggerTest extends TestCase
{
    private AuditLogger $logger;

    protected function setUp(): void
    {
        $this->logger = new AuditLogger(Database::pdo());
    }

    public function testLogsActionToDatabase(): void
    {
        $this->logger->log('test.action', null, 'backup_job', 1, '127.0.0.1', ['key' => 'val']);
        $stmt = Database::pdo()->prepare(
            "SELECT * FROM audit_logs WHERE action = 'test.action' ORDER BY id DESC LIMIT 1"
        );
        $stmt->execute();
        $row = $stmt->fetch();
        $this->assertNotFalse($row);
        $this->assertEquals('test.action', $row['action']);
        $this->assertEquals('127.0.0.1', $row['ip_address']);
        // Cleanup
        Database::pdo()->prepare("DELETE FROM audit_logs WHERE action = 'test.action'")->execute();
    }
}
