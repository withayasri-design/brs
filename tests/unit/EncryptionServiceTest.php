<?php
use PHPUnit\Framework\TestCase;

class EncryptionServiceTest extends TestCase
{
    private EncryptionService $svc;
    private string $keyFile;
    private string $tmpDir;

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir() . '/brs_test_' . uniqid();
        mkdir($this->tmpDir, 0700, true);
        $this->keyFile = $this->tmpDir . '/test.key';
        file_put_contents($this->keyFile, base64_encode(random_bytes(32)));
        $this->svc = new EncryptionService($this->keyFile);
    }

    protected function tearDown(): void
    {
        array_map('unlink', glob($this->tmpDir . '/*'));
        rmdir($this->tmpDir);
    }

    public function testEncryptDecryptFile(): void
    {
        $plain = $this->tmpDir . '/plain.txt';
        $enc   = $this->tmpDir . '/plain.enc';
        $dec   = $this->tmpDir . '/plain.dec';

        $original = 'Hello BRS encryption test — unicode สวัสดี';
        file_put_contents($plain, $original);

        $this->svc->encryptFile($plain, $enc, 'backup_file');
        $this->assertFileExists($enc);
        $this->assertNotEquals($original, file_get_contents($enc));

        $this->svc->decryptFile($enc, $dec, 'backup_file');
        $this->assertEquals($original, file_get_contents($dec));
    }

    public function testEncryptDecryptString(): void
    {
        $plain = 'super_secret_password_123';
        $cipher = $this->svc->encryptString($plain, 'credential');
        $this->assertNotEquals($plain, $cipher);
        $this->assertEquals($plain, $this->svc->decryptString($cipher, 'credential'));
    }

    public function testDifferentPurposesProduceDifferentCiphertext(): void
    {
        $plain = 'same_plaintext';
        $c1 = $this->svc->encryptString($plain, 'credential');
        $c2 = $this->svc->encryptString($plain, 'backup_file');
        $this->assertNotEquals($c1, $c2);
    }
}
