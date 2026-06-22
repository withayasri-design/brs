<?php
declare(strict_types=1);

use Aws\S3\S3Client;
use Aws\Exception\AwsException;

class S3Adapter implements StorageAdapterInterface
{
    private S3Client $client;
    private string $bucket;
    private string $prefix;
    private string $storageClass;

    public function __construct(array $config, ?EncryptionService $encryption = null)
    {
        $accessKey = $config['access_key_encrypted'] ?? $config['access_key'] ?? '';
        $secretKey = $config['secret_key_encrypted'] ?? $config['secret_key'] ?? '';

        if ($encryption && isset($config['access_key_encrypted'])) {
            $accessKey = $encryption->decryptString($config['access_key_encrypted'], 'credential');
            $secretKey = $encryption->decryptString($config['secret_key_encrypted'], 'credential');
        }

        $this->bucket       = $config['bucket'];
        $this->prefix       = rtrim($config['path_prefix'] ?? '', '/') . '/';
        $this->storageClass = $config['storage_class'] ?? 'STANDARD';

        $this->client = new S3Client([
            'version'     => 'latest',
            'region'      => $config['region'],
            'credentials' => ['key' => $accessKey, 'secret' => $secretKey],
        ]);
    }

    public function upload(string $localPath, string $remotePath): bool
    {
        try {
            $this->client->putObject([
                'Bucket'       => $this->bucket,
                'Key'          => $this->prefix . $remotePath,
                'SourceFile'   => $localPath,
                'StorageClass' => $this->storageClass,
            ]);
            return true;
        } catch (AwsException $e) {
            throw new \RuntimeException("S3 upload failed: " . $e->getMessage());
        }
    }

    public function download(string $remotePath, string $localPath): bool
    {
        try {
            $this->client->getObject([
                'Bucket' => $this->bucket,
                'Key'    => $this->prefix . $remotePath,
                'SaveAs' => $localPath,
            ]);
            return true;
        } catch (AwsException $e) {
            throw new \RuntimeException("S3 download failed: " . $e->getMessage());
        }
    }

    public function delete(string $remotePath): bool
    {
        try {
            $this->client->deleteObject([
                'Bucket' => $this->bucket,
                'Key'    => $this->prefix . $remotePath,
            ]);
            return true;
        } catch (AwsException $e) {
            return false;
        }
    }

    public function exists(string $remotePath): bool
    {
        return $this->client->doesObjectExist($this->bucket, $this->prefix . $remotePath);
    }

    public function listFiles(string $prefix = ''): array
    {
        $result = $this->client->listObjectsV2([
            'Bucket' => $this->bucket,
            'Prefix' => $this->prefix . $prefix,
        ]);
        return array_column($result['Contents'] ?? [], 'Key');
    }

    public function getFreeSpace(): ?int
    {
        return null;  // S3 has no concept of "free space"
    }

    public function testConnection(): array
    {
        try {
            $testKey = $this->prefix . '.brs_connection_test';
            $this->client->putObject(['Bucket' => $this->bucket, 'Key' => $testKey, 'Body' => 'ok']);
            $this->client->deleteObject(['Bucket' => $this->bucket, 'Key' => $testKey]);
            return ['status' => 'success', 'message' => "S3 bucket '{$this->bucket}' accessible, write/delete OK"];
        } catch (AwsException $e) {
            return ['status' => 'failed', 'message' => $e->getMessage()];
        }
    }
}
