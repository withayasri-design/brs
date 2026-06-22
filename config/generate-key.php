<?php
$keyPath = __DIR__ . '/encryption.key';
if (file_exists($keyPath)) {
    echo "Key already exists at $keyPath\n";
    exit(1);
}
$key = random_bytes(32);
file_put_contents($keyPath, base64_encode($key));
chmod($keyPath, 0600);
echo "Encryption key generated at $keyPath\n";
echo "BACK THIS UP OUTSIDE THE SERVER IMMEDIATELY.\n";
