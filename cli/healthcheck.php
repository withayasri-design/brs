<?php
require_once __DIR__ . '/common.php';
$ok = true;
$checks = [];

// mysqldump
exec('"' . Config::get('mysqldump_path') . '" --version 2>&1', $out, $rc);
$checks[] = ['mysqldump', $rc === 0 ? 'ok' : 'fail', $out[0] ?? ''];
if ($rc !== 0) $ok = false;

// mysql
exec('"' . Config::get('mysql_path') . '" --version 2>&1', $out, $rc);
$checks[] = ['mysql', $rc === 0 ? 'ok' : 'fail', $out[0] ?? ''];
if ($rc !== 0) $ok = false;

// PHP extensions
foreach (['zip','openssl','pdo_mysql'] as $ext) {
    $ok2 = extension_loaded($ext);
    $checks[] = ["ext:$ext", $ok2 ? 'ok' : 'fail', ''];
    if (!$ok2) $ok = false;
}

// DB connection
try {
    Database::pdo()->query('SELECT 1');
    $checks[] = ['db_connection', 'ok', ''];
} catch (\Exception $e) {
    $checks[] = ['db_connection', 'fail', $e->getMessage()];
    $ok = false;
}

// Storage targets
try {
    $stmt = Database::pdo()->query('SELECT * FROM storage_targets WHERE is_active=1');
    foreach ($stmt->fetchAll() as $t) {
        $r = StorageAdapterFactory::create($t)->testConnection();
        $checks[] = ["storage:{$t['target_name']}", $r['status'], $r['message'] ?? ''];
        if ($r['status'] !== 'success') $ok = false;
    }
} catch (\Exception $e) {
    $checks[] = ['storage_targets', 'fail', $e->getMessage()];
    $ok = false;
}

// Encryption key
$kp = Config::get('encryption_key_path');
$checks[] = ['encryption_key', file_exists($kp) ? 'ok' : 'fail', ''];
if (!file_exists($kp)) $ok = false;

foreach ($checks as [$name, $status, $detail]) {
    echo ($status === 'ok' || $status === 'success' ? '✓' : '✗') . " [$status] $name" . ($detail ? " — $detail" : '') . "\n";
}
echo "\nHealthcheck " . ($ok ? 'PASSED' : 'FAILED') . "\n";
exit($ok ? 0 : 1);
