<?php
use PHPUnit\Framework\TestCase;

class ChecksumServiceTest extends TestCase
{
    private ChecksumService $svc;
    private string $tmpFile;

    protected function setUp(): void
    {
        $this->svc = new ChecksumService();
        $this->tmpFile = tempnam(sys_get_temp_dir(), 'brs_chk_');
        file_put_contents($this->tmpFile, 'test content for checksum');
    }

    protected function tearDown(): void
    {
        @unlink($this->tmpFile);
    }

    public function testGeneratesHex64String(): void
    {
        $checksum = $this->svc->generate($this->tmpFile);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $checksum);
    }

    public function testVerifyPassesForMatchingChecksum(): void
    {
        $checksum = $this->svc->generate($this->tmpFile);
        $this->assertTrue($this->svc->verify($this->tmpFile, $checksum));
    }

    public function testVerifyFailsForMismatch(): void
    {
        $this->assertFalse($this->svc->verify($this->tmpFile, str_repeat('0', 64)));
    }

    public function testThrowsForMissingFile(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->svc->generate('/nonexistent/file.txt');
    }
}
