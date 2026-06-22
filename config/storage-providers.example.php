<?php
/**
 * Storage Provider Configuration Examples
 *
 * Copy relevant sections into your storage_targets table config_json column,
 * or use the admin UI to configure storage targets.
 *
 * Each provider type has its own required fields documented below.
 */

return [

    /**
     * LOCAL — store backups on the same server filesystem
     */
    'local' => [
        'base_path' => 'C:\\xampp\\htdocs\\brs\\storage',
    ],

    /**
     * NAS — network-attached storage via UNC path or mounted drive
     */
    'nas' => [
        'base_path'  => '\\\\NAS_HOST\\backups\\brs',
        'username'   => '',   // Windows share credentials (optional)
        'password'   => '',
    ],

    /**
     * S3 — Amazon S3 or S3-compatible (MinIO, Wasabi, etc.)
     */
    's3' => [
        'bucket'     => 'my-brs-backups',
        'region'     => 'ap-southeast-1',
        'prefix'     => 'brs/',
        'access_key' => 'AKIAIOSFODNN7EXAMPLE',
        'secret_key' => 'wJalrXUtnFEMI/K7MDENG/bPxRfiCYEXAMPLEKEY',
        'endpoint'   => null,   // Set for S3-compatible endpoints, e.g. 'https://s3.wasabisys.com'
    ],

    /**
     * GOOGLE_DRIVE — Google Drive via service account
     */
    'google_drive' => [
        'service_account_json' => 'C:\\xampp\\htdocs\\brs\\config\\google-service-account.json',
        'folder_id'            => '1BxiMVs0XRA5nFMdKvBdBZjgmUUqptlbs',  // Google Drive folder ID
    ],

    /**
     * SFTP — remote server via SSH/SFTP
     */
    'sftp' => [
        'host'        => 'sftp.example.com',
        'port'        => 22,
        'username'    => 'backupuser',
        'password'    => null,           // Use either password or private_key_path
        'private_key_path' => null,      // e.g. 'C:\\Users\\Administrator\\.ssh\\id_rsa'
        'remote_path' => '/backups/brs',
    ],

];
