<?php
return [
    'db' => [
        'host'     => 'localhost',
        'port'     => 3306,
        'dbname'   => 'brs_system',
        'username' => 'root',
        'password' => '',
        'charset'  => 'utf8mb4',
    ],
    'encryption_key_path' => __DIR__ . '/encryption.key',
    'mysqldump_path'      => 'C:\\xampp\\mysql\\bin\\mysqldump.exe',
    'mysql_path'          => 'C:\\xampp\\mysql\\bin\\mysql.exe',
    'temp_dir'            => __DIR__ . '/../temp',
    'logs_dir'            => __DIR__ . '/../logs',
    'storage_dir'         => __DIR__ . '/../storage',
    'session_timeout'     => 1800,
    'notify_mode'         => 'failure_only', // 'all' | 'failure_only' | 'none'
    'line_notify_token'   => null,
];
