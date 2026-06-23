<?php
declare(strict_types=1);
/**
 * Generate a random 256-bit AES encryption key and write it to config/encryption.key.
 * Run once during installation. Keep the output file backed up offline.
 *
 * Usage: php cli\generate-key.php [--force]
 */
$rootDir = dirname(__DIR__);
$keyPath = $rootDir . '/config/encryption.key';

$force = in_array('--force', $argv, true);

if (file_exists($keyPath) && !$force) {
    fwrite(STDERR, "ERROR: $keyPath already exists. Use --force to overwrite.\n");
    fwrite(STDERR, "WARNING: Overwriting the key will make existing encrypted backups unrecoverable!\n");
    exit(1);
}

$key = base64_encode(random_bytes(32)); // 256-bit key, base64-encoded
if (file_put_contents($keyPath, $key, LOCK_EX) === false) {
    fwrite(STDERR, "ERROR: Could not write to $keyPath — check directory permissions.\n");
    exit(1);
}

echo "Encryption key written to: $keyPath\n";
echo "Key (store this securely outside the server):\n$key\n";
echo "\nIMPORTANT: Back up this key to a secure location now.\n";
echo "Without it, encrypted backups cannot be restored.\n";
exit(0);
