<?php
use PHPUnit\Framework\TestCase;

class BackupEngineTest extends TestCase
{
    private BackupEngine $engine;
    private string $tmpAppDir;
    private string $tmpStorageDir;
    private int $testJobId;

    protected function setUp(): void
    {
        $pdo = Database::pdo();
        $this->tmpAppDir = sys_get_temp_dir() . '/brs_app_' . uniqid();
        $this->tmpStorageDir = sys_get_temp_dir() . '/brs_st_' . uniqid();
        mkdir($this->tmpAppDir, 0755, true);
        mkdir($this->tmpStorageDir, 0755, true);
        file_put_contents($this->tmpAppDir . '/index.php', '<?php echo "hello";');

        $pdo->prepare("INSERT INTO storage_targets (target_name,provider_type,config_json) VALUES('Test','local',:c)")
            ->execute(['c' => json_encode(['base_path' => $this->tmpStorageDir])]);
        $stId = (int) $pdo->lastInsertId();

        $pdo->prepare("INSERT INTO backup_jobs (job_name,app_path,backup_type,encryption_enabled) VALUES('Test Job',:p,'files_only',0)")
            ->execute(['p' => $this->tmpAppDir]);
        $this->testJobId = (int) $pdo->lastInsertId();
        $pdo->prepare("INSERT INTO job_storage_targets (job_id,storage_target_id) VALUES(?,?)")->execute([$this->testJobId, $stId]);

        $enc   = new EncryptionService(Config::get('encryption_key_path'));
        $chk   = new ChecksumService();
        $lock  = new LockManager(Config::get('temp_dir') . '/locks');
        $audit = new AuditLogger($pdo);
        $notif = new NotificationService(null, 'none');
        $this->engine = new BackupEngine($pdo, $enc, $chk, $lock, $audit, $notif,
            Config::get('temp_dir'), Config::get('mysqldump_path'));
    }

    protected function tearDown(): void
    {
        Database::pdo()->prepare("DELETE FROM backup_jobs WHERE id=?")->execute([$this->testJobId]);
        @array_map('unlink', glob($this->tmpAppDir . '/*'));
        @rmdir($this->tmpAppDir);
        @array_map('unlink', glob($this->tmpStorageDir . '/*'));
        @rmdir($this->tmpStorageDir);
    }

    public function testRunProducesSuccessfulBackupLog(): void
    {
        $id = $this->engine->run($this->testJobId, 'cli', null);
        $this->assertGreaterThan(0, $id);
        $stmt = Database::pdo()->prepare("SELECT * FROM backup_logs WHERE id=?");
        $stmt->execute([$id]);
        $log = $stmt->fetch();
        $this->assertEquals('success', $log['status']);
        $this->assertEquals('passed', $log['verification_status']);
    }
}
