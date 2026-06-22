<?php
declare(strict_types=1);

class ChecksumService
{
    public function generate(string $filePath): string
    {
        if (!file_exists($filePath)) {
            throw new \RuntimeException("File not found for checksum: $filePath");
        }
        $hash = hash_file('sha256', $filePath);
        if ($hash === false) {
            throw new \RuntimeException("Cannot compute checksum for: $filePath");
        }
        return $hash;
    }

    public function verify(string $filePath, string $expectedChecksum): bool
    {
        if (!file_exists($filePath)) {
            return false;
        }
        return hash_equals($expectedChecksum, $this->generate($filePath));
    }

    /** Generate checksums for multiple files. Returns ['filename' => 'sha256hex', ...] */
    public function generateManifest(array $filePaths): array
    {
        $manifest = [];
        foreach ($filePaths as $path) {
            $manifest[basename($path)] = $this->generate($path);
        }
        return $manifest;
    }
}
