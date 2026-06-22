<?php
declare(strict_types=1);

class EncryptionService
{
    private string $masterKey;

    public function __construct(string $keyPath)
    {
        if (!file_exists($keyPath)) {
            throw new \RuntimeException("Encryption key not found: $keyPath. Run config/generate-key.php first.");
        }
        $this->masterKey = base64_decode(trim(file_get_contents($keyPath)));
        if (strlen($this->masterKey) !== 32) {
            throw new \RuntimeException("Invalid encryption key length — must be 32 bytes.");
        }
    }

    /**
     * Encrypt a file using AES-256-CBC.
     * Output format: [16-byte random IV][ciphertext with PKCS7 padding]
     */
    public function encryptFile(string $sourcePath, string $destPath, string $purpose = 'backup_file'): void
    {
        $key = $this->deriveKey($purpose);
        $iv  = random_bytes(16);

        $plaintext  = file_get_contents($sourcePath);
        if ($plaintext === false) {
            throw new \RuntimeException("Cannot read source file: $sourcePath");
        }

        $ciphertext = openssl_encrypt($plaintext, 'AES-256-CBC', $key, OPENSSL_RAW_DATA, $iv);
        if ($ciphertext === false) {
            throw new \RuntimeException("Encryption failed: " . openssl_error_string());
        }

        if (file_put_contents($destPath, $iv . $ciphertext) === false) {
            throw new \RuntimeException("Cannot write encrypted file: $destPath");
        }
    }

    /**
     * Decrypt a file encrypted by encryptFile().
     */
    public function decryptFile(string $sourcePath, string $destPath, string $purpose = 'backup_file'): void
    {
        $key  = $this->deriveKey($purpose);
        $blob = file_get_contents($sourcePath);
        if ($blob === false || strlen($blob) < 16) {
            throw new \RuntimeException("Cannot read or invalid encrypted file: $sourcePath");
        }

        $iv         = substr($blob, 0, 16);
        $ciphertext = substr($blob, 16);

        $plaintext = openssl_decrypt($ciphertext, 'AES-256-CBC', $key, OPENSSL_RAW_DATA, $iv);
        if ($plaintext === false) {
            throw new \RuntimeException("Decryption failed — wrong key or corrupted file.");
        }

        if (file_put_contents($destPath, $plaintext) === false) {
            throw new \RuntimeException("Cannot write decrypted file: $destPath");
        }
    }

    /** Encrypt a short string (for credential storage). Returns base64-encoded blob. */
    public function encryptString(string $plaintext, string $purpose = 'credential'): string
    {
        $key        = $this->deriveKey($purpose);
        $iv         = random_bytes(16);
        $ciphertext = openssl_encrypt($plaintext, 'AES-256-CBC', $key, OPENSSL_RAW_DATA, $iv);
        if ($ciphertext === false) {
            throw new \RuntimeException("String encryption failed.");
        }
        return base64_encode($iv . $ciphertext);
    }

    /** Decrypt a string encrypted by encryptString(). */
    public function decryptString(string $cipherBlob, string $purpose = 'credential'): string
    {
        $key  = $this->deriveKey($purpose);
        $data = base64_decode($cipherBlob, true);
        if ($data === false || strlen($data) < 16) {
            throw new \RuntimeException("Invalid encrypted string.");
        }
        $iv         = substr($data, 0, 16);
        $ciphertext = substr($data, 16);
        $plaintext  = openssl_decrypt($ciphertext, 'AES-256-CBC', $key, OPENSSL_RAW_DATA, $iv);
        if ($plaintext === false) {
            throw new \RuntimeException("String decryption failed.");
        }
        return $plaintext;
    }

    /** HKDF-derived per-purpose key from master key. */
    private function deriveKey(string $purpose): string
    {
        return hash_hkdf('sha256', $this->masterKey, 32, $purpose);
    }
}
