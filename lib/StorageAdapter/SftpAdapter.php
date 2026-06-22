<?php
declare(strict_types=1);

use phpseclib3\Net\SFTP;
use phpseclib3\Crypt\PublicKeyLoader;

class SftpAdapter implements StorageAdapterInterface
{
    private SFTP $sftp;
    private string $remotePath;

    public function __construct(array $config, ?EncryptionService $encryption = null)
    {
        $this->remotePath = rtrim($config['remote_base_path'], '/');
        $this->sftp = new SFTP($config['host'], (int) ($config['port'] ?? 22));

        if ($config['auth_method'] === 'key') {
            $keyPath = $config['private_key_path_encrypted'] ?? $config['private_key_path'] ?? '';
            if ($encryption && isset($config['private_key_path_encrypted'])) {
                $keyPath = $encryption->decryptString($keyPath, 'credential');
            }
            $key = PublicKeyLoader::load(file_get_contents($keyPath));
            if (!$this->sftp->login($config['username'], $key)) {
                throw new \RuntimeException("SFTP key auth failed for {$config['host']}");
            }
        } else {
            $password = $config['password_encrypted'] ?? $config['password'] ?? '';
            if ($encryption && isset($config['password_encrypted'])) {
                $password = $encryption->decryptString($password, 'credential');
            }
            if (!$this->sftp->login($config['username'], $password)) {
                throw new \RuntimeException("SFTP password auth failed for {$config['host']}");
            }
        }
    }

    public function upload(string $localPath, string $remotePath): bool
    {
        $dest = $this->remotePath . '/' . $remotePath;
        $dir  = dirname($dest);
        $this->sftp->mkdir($dir, -1, true);
        return $this->sftp->put($dest, $localPath, SFTP::SOURCE_LOCAL_FILE);
    }

    public function download(string $remotePath, string $localPath): bool
    {
        return $this->sftp->get($this->remotePath . '/' . $remotePath, $localPath);
    }

    public function delete(string $remotePath): bool
    {
        return $this->sftp->delete($this->remotePath . '/' . $remotePath);
    }

    public function exists(string $remotePath): bool
    {
        return $this->sftp->stat($this->remotePath . '/' . $remotePath) !== false;
    }

    public function listFiles(string $prefix = ''): array
    {
        $dir = $this->remotePath . ($prefix ? '/' . $prefix : '');
        return $this->sftp->nlist($dir) ?: [];
    }

    public function getFreeSpace(): ?int
    {
        $stat = $this->sftp->statvfs($this->remotePath);
        if (!$stat) return null;
        return (int) ($stat['f_bsize'] * $stat['f_bavail']);
    }

    public function testConnection(): array
    {
        try {
            $testPath = $this->remotePath . '/.brs_connection_test';
            $this->sftp->put($testPath, 'ok');
            $this->sftp->delete($testPath);
            return ['status' => 'success', 'message' => 'SFTP connection OK, write/delete test passed'];
        } catch (\Exception $e) {
            return ['status' => 'failed', 'message' => $e->getMessage()];
        }
    }
}
