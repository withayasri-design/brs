<?php
declare(strict_types=1);

class NasAdapter implements StorageAdapterInterface
{
    private string $basePath;
    private ?string $username;
    private ?string $password;

    public function __construct(array $config, ?EncryptionService $encryption = null)
    {
        $this->basePath = rtrim($config['unc_path'] ?? $config['mapped_drive'] ?? '', '/\\');
        $this->username = $config['username'] ?? null;
        $this->password = null;

        if (isset($config['password_encrypted']) && $encryption) {
            $this->password = $encryption->decryptString($config['password_encrypted'], 'credential');
        }
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
        $dir = $this->basePath . ($prefix ? DIRECTORY_SEPARATOR . $prefix : '');
        return glob($dir . DIRECTORY_SEPARATOR . '*') ?: [];
    }

    public function getFreeSpace(): ?int
    {
        $free = @disk_free_space($this->basePath);
        return $free !== false ? (int) $free : null;
    }

    public function testConnection(): array
    {
        $testFile = $this->basePath . DIRECTORY_SEPARATOR . '.brs_test';
        if (@file_put_contents($testFile, 'ok') === false) {
            return ['status' => 'failed', 'message' => "Cannot write to NAS path: {$this->basePath}"];
        }
        @unlink($testFile);
        return ['status' => 'success', 'message' => 'NAS share accessible, read/write OK'];
    }

    private function fullPath(string $remotePath): string
    {
        return $this->basePath . DIRECTORY_SEPARATOR . ltrim($remotePath, '/\\');
    }
}
