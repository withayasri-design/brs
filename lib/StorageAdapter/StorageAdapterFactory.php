<?php
declare(strict_types=1);

class StorageAdapterFactory
{
    public static function create(array $target, ?EncryptionService $encryption = null): StorageAdapterInterface
    {
        $raw = $target['config_json'];
        // Encrypted blobs are base64 strings; plain configs start with '{' or '['
        if ($encryption !== null && $raw !== null && !str_starts_with(ltrim($raw), '{') && !str_starts_with(ltrim($raw), '[')) {
            $raw = $encryption->decryptString($raw, 'credential');
        }
        $config = json_decode($raw, true) ?? [];
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
