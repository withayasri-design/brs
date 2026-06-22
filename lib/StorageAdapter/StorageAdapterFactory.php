<?php
declare(strict_types=1);

class StorageAdapterFactory
{
    public static function create(array $target, ?EncryptionService $encryption = null): StorageAdapterInterface
    {
        $config = json_decode($target['config_json'], true);
        return match($target['provider_type']) {
            'local'        => new LocalAdapter($config),
            'nas'          => new NasAdapter($config, $encryption),
            's3'           => new S3Adapter($config, $encryption),
            'google_drive' => new GoogleDriveAdapter($config, $encryption),
            'sftp'         => new SftpAdapter($config, $encryption),
            default        => throw new \InvalidArgumentException("Unknown provider type: {$target['provider_type']}"),
        };
    }
}
