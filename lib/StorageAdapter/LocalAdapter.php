<?php
declare(strict_types=1);

class LocalAdapter implements StorageAdapterInterface
{
    private string $basePath;

    public function __construct(array $config)
    {
        $this->basePath = rtrim($config['base_path'], '/\\');
    }

    public function upload(string $localPath, string $remotePath): bool
    {
        $dest = $this->fullPath($remotePath);
        $dir  = dirname($dest);
        if (!is_dir($dir)) {
            mkdir($dir, 0750, true);
        }
        return copy($localPath, $dest);
    }

    public function download(string $remotePath, string $localPath): bool
    {
        return copy($this->fullPath($remotePath), $localPath);
    }

    public function delete(string $remotePath): bool
    {
        $path = $this->fullPath($remotePath);
        return file_exists($path) && unlink($path);
    }

    public function exists(string $remotePath): bool
    {
        return file_exists($this->fullPath($remotePath));
    }

    public function listFiles(string $prefix = ''): array
    {
        $dir   = $this->basePath . ($prefix ? DIRECTORY_SEPARATOR . $prefix : '');
        $files = glob($dir . DIRECTORY_SEPARATOR . '*', GLOB_BRACE);
        return $files ?: [];
    }

    public function getFreeSpace(): ?int
    {
        $free = disk_free_space($this->basePath);
        return $free !== false ? (int) $free : null;
    }

    public function testConnection(): array
    {
        if (!is_dir($this->basePath)) {
            return ['status' => 'failed', 'message' => "Directory does not exist: {$this->basePath}"];
        }
        $testFile = $this->basePath . DIRECTORY_SEPARATOR . '.brs_connection_test';
        if (file_put_contents($testFile, 'ok') === false) {
            return ['status' => 'failed', 'message' => 'Cannot write to storage directory'];
        }
        unlink($testFile);
        return ['status' => 'success', 'message' => 'Local storage accessible, read/write OK'];
    }

    private function fullPath(string $remotePath): string
    {
        return $this->basePath . DIRECTORY_SEPARATOR . ltrim($remotePath, '/\\');
    }
}
