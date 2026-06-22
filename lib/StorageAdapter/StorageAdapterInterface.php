<?php
declare(strict_types=1);

interface StorageAdapterInterface
{
    public function upload(string $localPath, string $remotePath): bool;
    public function download(string $remotePath, string $localPath): bool;
    public function delete(string $remotePath): bool;
    public function exists(string $remotePath): bool;
    public function listFiles(string $prefix = ''): array;
    public function getFreeSpace(): ?int;  // bytes, null if provider can't report
    public function testConnection(): array;  // ['status' => 'success'|'failed', 'message' => '...']
}
