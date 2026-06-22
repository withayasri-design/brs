<?php
use PHPUnit\Framework\TestCase;

class RestoreEngineTest extends TestCase
{
    public function testDryRunValidate(): void
    {
        $pdo  = Database::pdo();
        $stmt = $pdo->prepare("SELECT id FROM backup_logs WHERE status='success' ORDER BY id DESC LIMIT 1");
        $stmt->execute();
        $row  = $stmt->fetch();
        if (!$row) $this->markTestSkipped('No successful backup found. Run BackupEngineTest first.');

        $engine = $this->makeEngine($pdo);
        $result = $engine->validate((int) $row['id']);

        $this->assertArrayHasKey('checksum_valid', $result);
        $this->assertArrayHasKey('extraction_test_passed', $result);
    }

    private function makeEngine(PDO $pdo): RestoreEngine
    {
        return new RestoreEngine(
            $pdo,
            new EncryptionService(Config::get('encryption_key_path')),
            new ChecksumService(),
            new AuditLogger($pdo),
            new NotificationService(null, 'none'),
            Config::get('temp_dir'),
            Config::get('mysql_path'),
        );
    }
}
