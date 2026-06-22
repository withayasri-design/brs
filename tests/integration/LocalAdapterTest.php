<?php
use PHPUnit\Framework\TestCase;

class LocalAdapterTest extends TestCase
{
    private LocalAdapter $adapter;
    private string $tmpBase;

    protected function setUp(): void
    {
        $this->tmpBase = sys_get_temp_dir() . '/brs_local_' . uniqid();
        mkdir($this->tmpBase, 0700, true);
        $this->adapter = new LocalAdapter(['base_path' => $this->tmpBase]);
    }

    protected function tearDown(): void
    {
        $this->rmdirRecursive($this->tmpBase);
    }

    private function rmdirRecursive(string $dir): void
    {
        if (!is_dir($dir)) return;
        foreach (new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        ) as $f) {
            $f->isDir() ? rmdir($f->getPathname()) : unlink($f->getPathname());
        }
        rmdir($dir);
    }

    public function testUploadAndDownload(): void
    {
        $local = tempnam(sys_get_temp_dir(), 'brs_src_');
        file_put_contents($local, 'test backup content');

        $this->adapter->upload($local, 'job1/backup.zip');
        $this->assertTrue($this->adapter->exists('job1/backup.zip'));

        $dest = tempnam(sys_get_temp_dir(), 'brs_dl_');
        $this->adapter->download('job1/backup.zip', $dest);
        $this->assertEquals('test backup content', file_get_contents($dest));

        unlink($local);
        unlink($dest);
    }

    public function testDelete(): void
    {
        $local = tempnam(sys_get_temp_dir(), 'brs_del_');
        file_put_contents($local, 'x');
        $this->adapter->upload($local, 'todelete.txt');
        $this->adapter->delete('todelete.txt');
        $this->assertFalse($this->adapter->exists('todelete.txt'));
        unlink($local);
    }

    public function testTestConnectionReturnsSuccess(): void
    {
        $result = $this->adapter->testConnection();
        $this->assertEquals('success', $result['status']);
    }
}
