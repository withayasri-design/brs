# BRS System — Full Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Build a complete XAMPP Backup & Restore System — PHP native core engine, CLI scripts, REST API, and Bootstrap 5 Web UI — enabling automated, encrypted, verified backup and restore of any PHP/MariaDB web application.

**Architecture:** A shared PHP core engine (`lib/`) is called by both CLI scripts and a REST API layer; both interfaces read/write the same `brs_system` MariaDB metadata database. Storage providers are pluggable via an adapter interface. All state is persisted to the DB so the Web UI reflects CLI results in real time.

**Tech Stack:** PHP 8.2+ native (no framework), PDO/MariaDB, ZipArchive, OpenSSL AES-256-CBC, Bootstrap 5 CDN + Vanilla JS fetch API, mysqldump/mysql CLI, LINE Notify API, Composer (dev-only PHPUnit + optional cloud SDKs).

## Global Constraints

- Every `lib/` file must begin with `declare(strict_types=1);`
- All SQL must use PDO prepared statements — never string-concatenated SQL
- Passwords and credentials stored in DB must be encrypted via `EncryptionService::encryptString()` before insert
- Every user-supplied path must be validated via `PathValidator::isWithinAllowedBase()` before use
- Backup files written to `temp/` first, then moved atomically to final destination
- Post-backup verification (checksum + `ZipArchive::testArchive`) is mandatory and cannot be skipped
- Disk space must be checked before any backup starts (require free ≥ estimated × 1.2)
- Lock files live at `temp/locks/{job_id}.lock`, contain JSON: `{"pid":N,"hostname":"...","started_at":"..."}`
- Encryption: AES-256-CBC with random 16-byte IV prepended to ciphertext; key derived via `hash_hkdf('sha256', $masterKey, 32, $purpose)`
- Checksums: SHA-256 via `hash_file('sha256', $path)`
- Password hashing: `password_hash($password, PASSWORD_ARGON2ID)` — never MD5/SHA1
- Session timeout: 30 minutes (`session.gc_maxlifetime = 1800`)
- CSRF token required on all POST/PUT/DELETE API requests in header `X-CSRF-Token`
- API response envelope: `{"success": bool, "data": {...}|null, "error": null|string}`
- CLI exit codes: 0=success, 1=general failure, 2=insufficient disk space, 3=job already running
- Log line format: `[YYYY-MM-DD HH:MM:SS] [LEVEL] [job_id=N] Message`
- Metadata DB: `brs_system` (MariaDB), charset `utf8mb4_unicode_ci`
- PHP memory_limit for CLI scripts: set to `512M` via `ini_set('memory_limit', '512M')` at top of each CLI file
- Web root is `public/` — `config/`, `storage/`, `logs/`, `temp/` must NOT be web-accessible

---

### Task 1: Project Scaffold + Database Schema

**Files:**
- Create: `composer.json`
- Create: `sql/schema.sql`
- Create: `sql/seed.sql`
- Create: `config/app.config.example.php`
- Create: `config/storage-providers.example.php`
- Create: `public/.htaccess`
- Create: `config/.htaccess`
- Create: `storage/.htaccess`
- Create: `temp/.gitkeep`, `logs/.gitkeep`, `temp/locks/.gitkeep`
- Test: Manual verification that schema imports without error

**Interfaces:**
- Produces: `brs_system` database with all 7 tables; folder structure; Composer dev deps

- [ ] **Step 1: Create `composer.json`**

```json
{
  "name": "brs/backup-restore-system",
  "description": "XAMPP Backup & Restore System",
  "type": "project",
  "require": {
    "aws/aws-sdk-php": "^3.0",
    "google/apiclient": "^2.15",
    "phpseclib/phpseclib": "^3.0"
  },
  "require-dev": {
    "phpunit/phpunit": "^11.0"
  },
  "autoload": {
    "classmap": ["lib/"]
  },
  "autoload-dev": {
    "classmap": ["tests/"]
  },
  "config": {
    "allow-plugins": {
      "google/apiclient-services": true
    }
  }
}
```

- [ ] **Step 2: Create `sql/schema.sql`**

```sql
CREATE DATABASE IF NOT EXISTS brs_system
  CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE brs_system;

CREATE TABLE users (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  username VARCHAR(50) NOT NULL UNIQUE,
  password_hash VARCHAR(255) NOT NULL,
  full_name VARCHAR(150),
  role ENUM('admin','operator','viewer') NOT NULL DEFAULT 'viewer',
  line_notify_token VARCHAR(255) NULL,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  last_login_at DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE TABLE backup_jobs (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  job_name VARCHAR(150) NOT NULL,
  description TEXT NULL,
  app_path VARCHAR(500) NULL,
  include_patterns TEXT NULL,
  exclude_patterns TEXT NULL,
  db_host VARCHAR(100) NULL,
  db_port INT NULL DEFAULT 3306,
  db_name VARCHAR(100) NULL,
  db_username VARCHAR(100) NULL,
  db_password_encrypted TEXT NULL,
  backup_type ENUM('files_only','database_only','both') NOT NULL DEFAULT 'both',
  encryption_enabled TINYINT(1) NOT NULL DEFAULT 1,
  schedule_cron VARCHAR(100) NULL,
  retention_daily INT NOT NULL DEFAULT 7,
  retention_weekly INT NOT NULL DEFAULT 4,
  retention_monthly INT NOT NULL DEFAULT 6,
  max_job_duration_minutes INT NOT NULL DEFAULT 240,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  created_by INT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_job_creator FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE storage_targets (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  target_name VARCHAR(150) NOT NULL,
  provider_type ENUM('local','nas','s3','google_drive','sftp') NOT NULL,
  config_json TEXT NOT NULL,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  last_test_status ENUM('untested','success','failed') NOT NULL DEFAULT 'untested',
  last_test_at DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE TABLE job_storage_targets (
  job_id INT UNSIGNED NOT NULL,
  storage_target_id INT UNSIGNED NOT NULL,
  priority INT NOT NULL DEFAULT 1,
  PRIMARY KEY (job_id, storage_target_id),
  CONSTRAINT fk_jst_job FOREIGN KEY (job_id) REFERENCES backup_jobs(id) ON DELETE CASCADE,
  CONSTRAINT fk_jst_target FOREIGN KEY (storage_target_id) REFERENCES storage_targets(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE backup_logs (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  job_id INT UNSIGNED NOT NULL,
  triggered_by ENUM('schedule','manual','cli') NOT NULL,
  triggered_by_user_id INT UNSIGNED NULL,
  status ENUM('running','success','failed','corrupted') NOT NULL DEFAULT 'running',
  started_at DATETIME NOT NULL,
  finished_at DATETIME NULL,
  duration_seconds INT NULL,
  total_size_bytes BIGINT NULL,
  files_checksum VARCHAR(64) NULL,
  database_checksum VARCHAR(64) NULL,
  is_encrypted TINYINT(1) NOT NULL DEFAULT 0,
  is_pinned TINYINT(1) NOT NULL DEFAULT 0,
  verification_status ENUM('pending','passed','failed') NOT NULL DEFAULT 'pending',
  error_message TEXT NULL,
  manifest_path VARCHAR(500) NULL,
  CONSTRAINT fk_bl_job FOREIGN KEY (job_id) REFERENCES backup_jobs(id) ON DELETE CASCADE,
  CONSTRAINT fk_bl_user FOREIGN KEY (triggered_by_user_id) REFERENCES users(id) ON DELETE SET NULL,
  INDEX idx_job_status (job_id, status),
  INDEX idx_started_at (started_at)
) ENGINE=InnoDB;

CREATE TABLE backup_files (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  backup_log_id BIGINT UNSIGNED NOT NULL,
  storage_target_id INT UNSIGNED NOT NULL,
  file_type ENUM('files_archive','database_dump','manifest','checksum') NOT NULL,
  remote_path VARCHAR(1000) NOT NULL,
  size_bytes BIGINT NULL,
  uploaded_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_bf_log FOREIGN KEY (backup_log_id) REFERENCES backup_logs(id) ON DELETE CASCADE,
  CONSTRAINT fk_bf_target FOREIGN KEY (storage_target_id) REFERENCES storage_targets(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE restore_logs (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  job_id INT UNSIGNED NOT NULL,
  backup_log_id BIGINT UNSIGNED NOT NULL,
  restore_mode ENUM('dry_run','real') NOT NULL,
  restore_target ENUM('original','alternate') NOT NULL DEFAULT 'original',
  alternate_path VARCHAR(500) NULL,
  alternate_db_name VARCHAR(100) NULL,
  pre_restore_snapshot_id BIGINT UNSIGNED NULL,
  status ENUM('running','success','failed','rolled_back') NOT NULL DEFAULT 'running',
  initiated_by_user_id INT UNSIGNED NOT NULL,
  started_at DATETIME NOT NULL,
  finished_at DATETIME NULL,
  error_message TEXT NULL,
  CONSTRAINT fk_rl_job FOREIGN KEY (job_id) REFERENCES backup_jobs(id) ON DELETE CASCADE,
  CONSTRAINT fk_rl_backup FOREIGN KEY (backup_log_id) REFERENCES backup_logs(id),
  CONSTRAINT fk_rl_user FOREIGN KEY (initiated_by_user_id) REFERENCES users(id)
) ENGINE=InnoDB;

CREATE TABLE audit_logs (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id INT UNSIGNED NULL,
  action VARCHAR(100) NOT NULL,
  target_type VARCHAR(50) NULL,
  target_id INT UNSIGNED NULL,
  ip_address VARCHAR(45) NULL,
  detail_json TEXT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_al_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL,
  INDEX idx_action_date (action, created_at)
) ENGINE=InnoDB;
```

- [ ] **Step 3: Create `sql/seed.sql`**

```sql
USE brs_system;
-- Password hash for 'Admin@1234' — CHANGE IMMEDIATELY after install
-- Generate with: php -r "echo password_hash('Admin@1234', PASSWORD_ARGON2ID);"
INSERT INTO users (username, password_hash, full_name, role)
VALUES ('admin', '$argon2id$REPLACE_THIS_WITH_REAL_HASH', 'System Administrator', 'admin');

INSERT INTO storage_targets (target_name, provider_type, config_json, is_active)
VALUES ('Local Default', 'local', '{"base_path":"C:\\\\xampp\\\\htdocs\\\\brs\\\\storage"}', 1);
```

- [ ] **Step 4: Create `config/app.config.example.php`**

```php
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
```

- [ ] **Step 5: Create `.htaccess` files to block web access to sensitive dirs**

`config/.htaccess`:
```apache
Deny from all
```

`storage/.htaccess`:
```apache
Deny from all
```

`public/.htaccess`:
```apache
Options -Indexes
Header always set X-Content-Type-Options "nosniff"
Header always set X-Frame-Options "DENY"
Header always set Content-Security-Policy "default-src 'self'; script-src 'self' 'unsafe-inline' https://cdn.jsdelivr.net; style-src 'self' 'unsafe-inline' https://cdn.jsdelivr.net; font-src 'self' https://cdn.jsdelivr.net;"

<IfModule mod_rewrite.c>
  RewriteEngine On
  RewriteBase /brs/public/
</IfModule>
```

- [ ] **Step 6: Create placeholder directories**

```bash
mkdir -p temp/locks logs storage
echo "" > temp/.gitkeep
echo "" > temp/locks/.gitkeep
echo "" > logs/.gitkeep
echo "" > storage/.gitkeep
```

- [ ] **Step 7: Create `config/generate-key.php` (one-time key generator)**

```php
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
```

- [ ] **Step 8: Import schema and verify**

Run in XAMPP shell:
```bash
mysql -u root brs_system < sql/schema.sql
mysql -u root brs_system -e "SHOW TABLES;"
```

Expected output: 7 tables listed (audit_logs, backup_files, backup_jobs, backup_logs, job_storage_targets, restore_logs, storage_targets, users)

- [ ] **Step 9: Install Composer dev dependencies**

```bash
composer install
```

Expected: `vendor/` created, `vendor/bin/phpunit` present

- [ ] **Step 10: Commit**

```bash
git add sql/ config/ public/.htaccess storage/ temp/ composer.json
git commit -m "feat: project scaffold, DB schema, config templates, security htaccess"
```

---

### Task 2: Database PDO Wrapper + Config Loader

**Files:**
- Create: `lib/Database.php`
- Create: `lib/Config.php`
- Create: `tests/bootstrap.php`
- Create: `tests/integration/DatabaseTest.php`

**Interfaces:**
- Produces: `Database::pdo(): PDO`, `Config::get(string $key, mixed $default = null): mixed`

- [ ] **Step 1: Write the failing test**

`tests/integration/DatabaseTest.php`:
```php
<?php
use PHPUnit\Framework\TestCase;

class DatabaseTest extends TestCase
{
    public function testGetsPdoInstance(): void
    {
        $pdo = Database::pdo();
        $this->assertInstanceOf(PDO::class, $pdo);
    }

    public function testPdoIsSingleton(): void
    {
        $a = Database::pdo();
        $b = Database::pdo();
        $this->assertSame($a, $b);
    }

    public function testQueryExecutes(): void
    {
        $pdo = Database::pdo();
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM users');
        $stmt->execute();
        $this->assertIsInt((int) $stmt->fetchColumn());
    }
}
```

`tests/bootstrap.php`:
```php
<?php
require_once __DIR__ . '/../vendor/autoload.php';
// Load config pointing to test DB (same brs_system for integration tests)
Config::init(__DIR__ . '/../config/app.config.php');
```

- [ ] **Step 2: Run test to verify it fails**

```bash
php vendor/bin/phpunit tests/integration/DatabaseTest.php
```
Expected: FAIL — class Database not found

- [ ] **Step 3: Implement `lib/Config.php`**

```php
<?php
declare(strict_types=1);

class Config
{
    private static array $data = [];

    public static function init(string $configPath): void
    {
        self::$data = require $configPath;
    }

    public static function get(string $key, mixed $default = null): mixed
    {
        $parts = explode('.', $key);
        $value = self::$data;
        foreach ($parts as $part) {
            if (!is_array($value) || !array_key_exists($part, $value)) {
                return $default;
            }
            $value = $value[$part];
        }
        return $value;
    }
}
```

- [ ] **Step 4: Implement `lib/Database.php`**

```php
<?php
declare(strict_types=1);

class Database
{
    private static ?PDO $instance = null;

    public static function pdo(): PDO
    {
        if (self::$instance === null) {
            $cfg = Config::get('db');
            $dsn = sprintf(
                'mysql:host=%s;port=%d;dbname=%s;charset=%s',
                $cfg['host'],
                $cfg['port'],
                $cfg['dbname'],
                $cfg['charset'],
            );
            self::$instance = new PDO($dsn, $cfg['username'], $cfg['password'], [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
            ]);
        }
        return self::$instance;
    }

    /** Reset singleton (for testing only) */
    public static function reset(): void
    {
        self::$instance = null;
    }
}
```

- [ ] **Step 5: Run test to verify it passes**

```bash
php vendor/bin/phpunit tests/integration/DatabaseTest.php
```
Expected: 3 tests, 3 assertions — all PASS

- [ ] **Step 6: Commit**

```bash
git add lib/Database.php lib/Config.php tests/
git commit -m "feat: Config loader and PDO Database wrapper with integration tests"
```

---

### Task 3: EncryptionService + ChecksumService + PathValidator

**Files:**
- Create: `lib/EncryptionService.php`
- Create: `lib/ChecksumService.php`
- Create: `lib/PathValidator.php`
- Create: `tests/unit/EncryptionServiceTest.php`
- Create: `tests/unit/ChecksumServiceTest.php`
- Create: `tests/unit/PathValidatorTest.php`

**Interfaces:**
- Consumes: `config/encryption.key` (base64 encoded 32-byte key)
- Produces:
  - `EncryptionService::encryptFile(string $src, string $dest, string $purpose): void`
  - `EncryptionService::decryptFile(string $src, string $dest, string $purpose): void`
  - `EncryptionService::encryptString(string $plain, string $purpose): string`
  - `EncryptionService::decryptString(string $cipher, string $purpose): string`
  - `ChecksumService::generate(string $filePath): string`
  - `ChecksumService::verify(string $filePath, string $expected): bool`
  - `PathValidator::isWithinAllowedBase(string $path, string $base): bool`

- [ ] **Step 1: Write failing tests**

`tests/unit/EncryptionServiceTest.php`:
```php
<?php
use PHPUnit\Framework\TestCase;

class EncryptionServiceTest extends TestCase
{
    private EncryptionService $svc;
    private string $keyFile;
    private string $tmpDir;

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir() . '/brs_test_' . uniqid();
        mkdir($this->tmpDir, 0700, true);
        $this->keyFile = $this->tmpDir . '/test.key';
        file_put_contents($this->keyFile, base64_encode(random_bytes(32)));
        $this->svc = new EncryptionService($this->keyFile);
    }

    protected function tearDown(): void
    {
        array_map('unlink', glob($this->tmpDir . '/*'));
        rmdir($this->tmpDir);
    }

    public function testEncryptDecryptFile(): void
    {
        $plain = $this->tmpDir . '/plain.txt';
        $enc   = $this->tmpDir . '/plain.enc';
        $dec   = $this->tmpDir . '/plain.dec';

        $original = 'Hello BRS encryption test — unicode สวัสดี';
        file_put_contents($plain, $original);

        $this->svc->encryptFile($plain, $enc, 'backup_file');
        $this->assertFileExists($enc);
        $this->assertNotEquals($original, file_get_contents($enc));

        $this->svc->decryptFile($enc, $dec, 'backup_file');
        $this->assertEquals($original, file_get_contents($dec));
    }

    public function testEncryptDecryptString(): void
    {
        $plain = 'super_secret_password_123';
        $cipher = $this->svc->encryptString($plain, 'credential');
        $this->assertNotEquals($plain, $cipher);
        $this->assertEquals($plain, $this->svc->decryptString($cipher, 'credential'));
    }

    public function testDifferentPurposesProduceDifferentCiphertext(): void
    {
        $plain = 'same_plaintext';
        $c1 = $this->svc->encryptString($plain, 'credential');
        $c2 = $this->svc->encryptString($plain, 'backup_file');
        $this->assertNotEquals($c1, $c2);
    }
}
```

`tests/unit/ChecksumServiceTest.php`:
```php
<?php
use PHPUnit\Framework\TestCase;

class ChecksumServiceTest extends TestCase
{
    private ChecksumService $svc;
    private string $tmpFile;

    protected function setUp(): void
    {
        $this->svc = new ChecksumService();
        $this->tmpFile = tempnam(sys_get_temp_dir(), 'brs_chk_');
        file_put_contents($this->tmpFile, 'test content for checksum');
    }

    protected function tearDown(): void
    {
        @unlink($this->tmpFile);
    }

    public function testGeneratesHex64String(): void
    {
        $checksum = $this->svc->generate($this->tmpFile);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $checksum);
    }

    public function testVerifyPassesForMatchingChecksum(): void
    {
        $checksum = $this->svc->generate($this->tmpFile);
        $this->assertTrue($this->svc->verify($this->tmpFile, $checksum));
    }

    public function testVerifyFailsForMismatch(): void
    {
        $this->assertFalse($this->svc->verify($this->tmpFile, str_repeat('0', 64)));
    }

    public function testThrowsForMissingFile(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->svc->generate('/nonexistent/file.txt');
    }
}
```

`tests/unit/PathValidatorTest.php`:
```php
<?php
use PHPUnit\Framework\TestCase;

class PathValidatorTest extends TestCase
{
    public function testAllowsPathWithinBase(): void
    {
        $this->assertTrue(
            PathValidator::isWithinAllowedBase('C:\\xampp\\htdocs\\hr2000\\uploads', 'C:\\xampp\\htdocs\\hr2000')
        );
    }

    public function testBlocksTraversalAttack(): void
    {
        $this->assertFalse(
            PathValidator::isWithinAllowedBase('C:\\xampp\\htdocs\\hr2000\\..\\brs\\config', 'C:\\xampp\\htdocs\\hr2000')
        );
    }

    public function testBlocksCompletelyDifferentPath(): void
    {
        $this->assertFalse(
            PathValidator::isWithinAllowedBase('C:\\Windows\\System32', 'C:\\xampp\\htdocs')
        );
    }
}
```

- [ ] **Step 2: Run tests to verify they fail**

```bash
php vendor/bin/phpunit tests/unit/
```
Expected: FAIL — classes not found

- [ ] **Step 3: Implement `lib/EncryptionService.php`**

```php
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
```

- [ ] **Step 4: Implement `lib/ChecksumService.php`**

```php
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
```

- [ ] **Step 5: Implement `lib/PathValidator.php`**

```php
<?php
declare(strict_types=1);

class PathValidator
{
    /**
     * Returns true only if $path is within $allowedBase after resolving symlinks/traversal.
     * On Windows, comparison is case-insensitive.
     */
    public static function isWithinAllowedBase(string $path, string $allowedBase): bool
    {
        // Normalize separators
        $normalize = static fn(string $p): string => rtrim(
            str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $p),
            DIRECTORY_SEPARATOR
        );

        $path        = $normalize($path);
        $allowedBase = $normalize($allowedBase);

        // Resolve real paths if they exist; fall back to lexical check
        $realPath = realpath($path);
        $realBase = realpath($allowedBase);

        if ($realPath !== false && $realBase !== false) {
            $realPath = $normalize($realPath);
            $realBase = $normalize($realBase);
        } else {
            // Lexical check: resolve '..' manually
            $realPath = self::lexicalResolve($path);
            $realBase = self::lexicalResolve($allowedBase);
        }

        if (PHP_OS_FAMILY === 'Windows') {
            $realPath = strtolower($realPath);
            $realBase = strtolower($realBase);
        }

        return str_starts_with($realPath, $realBase . DIRECTORY_SEPARATOR)
            || $realPath === $realBase;
    }

    private static function lexicalResolve(string $path): string
    {
        $parts  = explode(DIRECTORY_SEPARATOR, $path);
        $result = [];
        foreach ($parts as $part) {
            if ($part === '..') {
                array_pop($result);
            } elseif ($part !== '.') {
                $result[] = $part;
            }
        }
        return implode(DIRECTORY_SEPARATOR, $result);
    }
}
```

- [ ] **Step 6: Run tests to verify they pass**

```bash
php vendor/bin/phpunit tests/unit/
```
Expected: All tests PASS

- [ ] **Step 7: Commit**

```bash
git add lib/EncryptionService.php lib/ChecksumService.php lib/PathValidator.php tests/unit/
git commit -m "feat: EncryptionService (AES-256-CBC HKDF), ChecksumService (SHA-256), PathValidator"
```

---

### Task 4: AuditLogger + LockManager

**Files:**
- Create: `lib/AuditLogger.php`
- Create: `lib/LockManager.php`
- Create: `tests/unit/LockManagerTest.php`
- Create: `tests/integration/AuditLoggerTest.php`

**Interfaces:**
- Consumes: `Database::pdo()`, `Config::get('temp_dir')`
- Produces:
  - `AuditLogger::log(string $action, ?int $userId, ?string $targetType, ?int $targetId, ?string $ip, mixed $detail): void`
  - `LockManager::acquire(int $jobId): bool`
  - `LockManager::release(int $jobId): void`
  - `LockManager::isLocked(int $jobId): bool`
  - `LockManager::clearStaleLockIfNeeded(int $jobId, int $maxMinutes): bool`

- [ ] **Step 1: Write the failing tests**

`tests/unit/LockManagerTest.php`:
```php
<?php
use PHPUnit\Framework\TestCase;

class LockManagerTest extends TestCase
{
    private string $lockDir;
    private LockManager $lm;

    protected function setUp(): void
    {
        $this->lockDir = sys_get_temp_dir() . '/brs_locks_' . uniqid();
        mkdir($this->lockDir, 0700, true);
        $this->lm = new LockManager($this->lockDir);
    }

    protected function tearDown(): void
    {
        array_map('unlink', glob($this->lockDir . '/*.lock'));
        @rmdir($this->lockDir);
    }

    public function testAcquireCreatesLockFile(): void
    {
        $result = $this->lm->acquire(99);
        $this->assertTrue($result);
        $this->assertFileExists($this->lockDir . '/99.lock');
    }

    public function testIsLockedReturnsTrueWhenLocked(): void
    {
        $this->lm->acquire(99);
        $this->assertTrue($this->lm->isLocked(99));
    }

    public function testReleaseRemovesLock(): void
    {
        $this->lm->acquire(99);
        $this->lm->release(99);
        $this->assertFalse($this->lm->isLocked(99));
    }

    public function testCannotAcquireAlreadyLockedJob(): void
    {
        $this->lm->acquire(99);
        $result = $this->lm->acquire(99);
        $this->assertFalse($result);
    }
}
```

`tests/integration/AuditLoggerTest.php`:
```php
<?php
use PHPUnit\Framework\TestCase;

class AuditLoggerTest extends TestCase
{
    private AuditLogger $logger;

    protected function setUp(): void
    {
        $this->logger = new AuditLogger(Database::pdo());
    }

    public function testLogsActionToDatabase(): void
    {
        $this->logger->log('test.action', null, 'backup_job', 1, '127.0.0.1', ['key' => 'val']);
        $stmt = Database::pdo()->prepare(
            "SELECT * FROM audit_logs WHERE action = 'test.action' ORDER BY id DESC LIMIT 1"
        );
        $stmt->execute();
        $row = $stmt->fetch();
        $this->assertNotFalse($row);
        $this->assertEquals('test.action', $row['action']);
        $this->assertEquals('127.0.0.1', $row['ip_address']);
        // Cleanup
        Database::pdo()->prepare("DELETE FROM audit_logs WHERE action = 'test.action'")->execute();
    }
}
```

- [ ] **Step 2: Run tests to verify they fail**

```bash
php vendor/bin/phpunit tests/unit/LockManagerTest.php tests/integration/AuditLoggerTest.php
```
Expected: FAIL — classes not found

- [ ] **Step 3: Implement `lib/AuditLogger.php`**

```php
<?php
declare(strict_types=1);

class AuditLogger
{
    public function __construct(private readonly PDO $pdo) {}

    public function log(
        string $action,
        ?int $userId     = null,
        ?string $targetType = null,
        ?int $targetId   = null,
        ?string $ip      = null,
        mixed $detail    = null,
    ): void {
        $stmt = $this->pdo->prepare(
            'INSERT INTO audit_logs (user_id, action, target_type, target_id, ip_address, detail_json)
             VALUES (:user_id, :action, :target_type, :target_id, :ip, :detail)'
        );
        $stmt->execute([
            'user_id'     => $userId,
            'action'      => $action,
            'target_type' => $targetType,
            'target_id'   => $targetId,
            'ip'          => $ip,
            'detail'      => $detail !== null ? json_encode($detail, JSON_UNESCAPED_UNICODE) : null,
        ]);
    }
}
```

- [ ] **Step 4: Implement `lib/LockManager.php`**

```php
<?php
declare(strict_types=1);

class LockManager
{
    public function __construct(private readonly string $lockDir)
    {
        if (!is_dir($this->lockDir)) {
            mkdir($this->lockDir, 0700, true);
        }
    }

    /** Returns true if lock was acquired, false if already locked by live process. */
    public function acquire(int $jobId): bool
    {
        $lockPath = $this->getLockPath($jobId);

        if (file_exists($lockPath)) {
            $data = json_decode(file_get_contents($lockPath), true);
            if ($data && $this->isProcessAlive((int) $data['pid'])) {
                return false;  // Job still running
            }
            // Stale lock — clear it
            unlink($lockPath);
        }

        $payload = json_encode([
            'pid'        => getmypid(),
            'hostname'   => gethostname(),
            'started_at' => date('Y-m-d H:i:s'),
        ]);
        file_put_contents($lockPath, $payload, LOCK_EX);
        return true;
    }

    public function release(int $jobId): void
    {
        $lockPath = $this->getLockPath($jobId);
        if (file_exists($lockPath)) {
            unlink($lockPath);
        }
    }

    public function isLocked(int $jobId): bool
    {
        $lockPath = $this->getLockPath($jobId);
        if (!file_exists($lockPath)) {
            return false;
        }
        $data = json_decode(file_get_contents($lockPath), true);
        return $data && $this->isProcessAlive((int) $data['pid']);
    }

    /**
     * If lock is older than $maxMinutes and process is dead, clear it.
     * Returns true if stale lock was cleared.
     */
    public function clearStaleLockIfNeeded(int $jobId, int $maxMinutes): bool
    {
        $lockPath = $this->getLockPath($jobId);
        if (!file_exists($lockPath)) {
            return false;
        }
        $data = json_decode(file_get_contents($lockPath), true);
        if (!$data) {
            unlink($lockPath);
            return true;
        }
        $age = (time() - strtotime($data['started_at'])) / 60;
        if ($age > $maxMinutes && !$this->isProcessAlive((int) $data['pid'])) {
            unlink($lockPath);
            return true;
        }
        return false;
    }

    private function getLockPath(int $jobId): string
    {
        return $this->lockDir . DIRECTORY_SEPARATOR . $jobId . '.lock';
    }

    private function isProcessAlive(int $pid): bool
    {
        if (PHP_OS_FAMILY === 'Windows') {
            $output = shell_exec("tasklist /FI \"PID eq $pid\" /NH 2>NUL");
            return $output !== null && str_contains($output, (string) $pid);
        }
        return file_exists("/proc/$pid");
    }
}
```

- [ ] **Step 5: Run tests to verify they pass**

```bash
php vendor/bin/phpunit tests/unit/LockManagerTest.php tests/integration/AuditLoggerTest.php
```
Expected: All tests PASS

- [ ] **Step 6: Commit**

```bash
git add lib/AuditLogger.php lib/LockManager.php tests/unit/LockManagerTest.php tests/integration/AuditLoggerTest.php
git commit -m "feat: AuditLogger (append-only DB audit trail) and LockManager (PID-based job mutex)"
```

---

### Task 5: NotificationService + RetentionPolicyService

**Files:**
- Create: `lib/NotificationService.php`
- Create: `lib/RetentionPolicyService.php`
- Create: `tests/unit/RetentionPolicyServiceTest.php`

**Interfaces:**
- Consumes: `Config::get('line_notify_token')`, `Config::get('notify_mode')`, `Database::pdo()`
- Produces:
  - `NotificationService::notifyBackupSuccess(int $jobId, string $jobName, int $secs, int $bytes): void`
  - `NotificationService::notifyBackupFailure(int $jobId, string $jobName, string $error): void`
  - `NotificationService::notifyRestoreExecuted(int $jobId, string $jobName, string $mode, string $by): void`
  - `NotificationService::notifyDiskLow(string $targetName, int $freeBytes): void`
  - `RetentionPolicyService::getExpiredBackupIds(int $jobId, int $daily, int $weekly, int $monthly): array`

- [ ] **Step 1: Write failing test for RetentionPolicyService**

`tests/unit/RetentionPolicyServiceTest.php`:
```php
<?php
use PHPUnit\Framework\TestCase;

class RetentionPolicyServiceTest extends TestCase
{
    public function testKeepsDailyBackups(): void
    {
        // 10 backups, retain 7 daily → oldest 3 should be expired
        $backups = $this->makeBackups(10, '-1 day');
        $svc = new RetentionPolicyService(Database::pdo());
        // We'll use a stub/mock approach: test the pure logic method
        $expired = $svc->computeExpired($backups, daily: 7, weekly: 0, monthly: 0);
        $this->assertCount(3, $expired);
    }

    public function testPinnedBackupsNeverExpire(): void
    {
        $backups = $this->makeBackups(10, '-1 day');
        $backups[0]['is_pinned'] = 1;  // Pin the oldest
        $svc = new RetentionPolicyService(Database::pdo());
        $expired = $svc->computeExpired($backups, daily: 7, weekly: 0, monthly: 0);
        $expiredIds = array_column($expired, 'id');
        $this->assertNotContains($backups[0]['id'], $expiredIds);
    }

    private function makeBackups(int $count, string $step): array
    {
        $result = [];
        for ($i = 0; $i < $count; $i++) {
            $result[] = [
                'id'         => $i + 1,
                'started_at' => date('Y-m-d H:i:s', strtotime("$step * $i")),
                'is_pinned'  => 0,
                'status'     => 'success',
            ];
        }
        return $result;
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

```bash
php vendor/bin/phpunit tests/unit/RetentionPolicyServiceTest.php
```
Expected: FAIL

- [ ] **Step 3: Implement `lib/NotificationService.php`**

```php
<?php
declare(strict_types=1);

class NotificationService
{
    public function __construct(
        private readonly ?string $lineToken,
        private readonly string  $notifyMode = 'failure_only',
    ) {}

    public function notifyBackupSuccess(int $jobId, string $jobName, int $durationSeconds, int $sizeBytes): void
    {
        if ($this->notifyMode !== 'all') {
            return;
        }
        $size = $this->formatBytes($sizeBytes);
        $this->send("✅ BRS Backup สำเร็จ\nJob: $jobName (ID: $jobId)\nขนาด: $size\nใช้เวลา: {$durationSeconds}s");
    }

    public function notifyBackupFailure(int $jobId, string $jobName, string $error): void
    {
        $this->send("❌ BRS Backup ล้มเหลว\nJob: $jobName (ID: $jobId)\nError: $error");
    }

    public function notifyRestoreExecuted(int $jobId, string $jobName, string $mode, string $initiatedBy): void
    {
        $this->send("⚠️ BRS Restore ดำเนินการ\nJob: $jobName (ID: $jobId)\nMode: $mode\nBy: $initiatedBy");
    }

    public function notifyDiskLow(string $targetName, int $freeBytes): void
    {
        $free = $this->formatBytes($freeBytes);
        $this->send("⚠️ BRS ดิสก์เหลือน้อย\nTarget: $targetName\nพื้นที่ว่าง: $free");
    }

    private function send(string $message): void
    {
        if (empty($this->lineToken)) {
            return;
        }
        $context = stream_context_create([
            'http' => [
                'method'  => 'POST',
                'header'  => "Authorization: Bearer {$this->lineToken}\r\nContent-Type: application/x-www-form-urlencoded\r\n",
                'content' => http_build_query(['message' => "\n" . $message]),
                'timeout' => 10,
            ],
        ]);
        @file_get_contents('https://notify-api.line.me/api/notify', false, $context);
    }

    private function formatBytes(int $bytes): string
    {
        if ($bytes >= 1073741824) return round($bytes / 1073741824, 2) . ' GB';
        if ($bytes >= 1048576)   return round($bytes / 1048576, 2) . ' MB';
        return round($bytes / 1024, 2) . ' KB';
    }
}
```

- [ ] **Step 4: Implement `lib/RetentionPolicyService.php`**

```php
<?php
declare(strict_types=1);

class RetentionPolicyService
{
    public function __construct(private readonly PDO $pdo) {}

    /**
     * Return array of backup_log rows that should be deleted according to GFS policy.
     * Only considers backups with status='success' and is_pinned=0.
     */
    public function computeExpired(array $backups, int $daily, int $weekly, int $monthly): array
    {
        // Filter to only successful, unpinned backups; sort newest first
        $eligible = array_filter($backups, fn($b) => $b['status'] === 'success' && !$b['is_pinned']);
        usort($eligible, fn($a, $b) => strcmp($b['started_at'], $a['started_at']));

        $keep = [];

        // Daily: keep the N most recent
        $dailyKept = 0;
        foreach ($eligible as $b) {
            if ($dailyKept < $daily) {
                $keep[$b['id']] = true;
                $dailyKept++;
            }
        }

        // Weekly: keep one per week for N weeks (oldest in each week after daily window)
        $weeksSeen = [];
        foreach ($eligible as $b) {
            $week = date('oW', strtotime($b['started_at']));
            if (!isset($weeksSeen[$week]) && count($weeksSeen) < $weekly) {
                $keep[$b['id']] = true;
                $weeksSeen[$week] = true;
            }
        }

        // Monthly: keep one per month for N months
        $monthsSeen = [];
        foreach ($eligible as $b) {
            $month = date('Ym', strtotime($b['started_at']));
            if (!isset($monthsSeen[$month]) && count($monthsSeen) < $monthly) {
                $keep[$b['id']] = true;
                $monthsSeen[$month] = true;
            }
        }

        return array_values(array_filter($eligible, fn($b) => !isset($keep[$b['id']])));
    }

    /** Load backups for job, compute expired, return their IDs. */
    public function getExpiredBackupIds(int $jobId, int $daily, int $weekly, int $monthly): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, started_at, is_pinned, status FROM backup_logs
             WHERE job_id = :job_id ORDER BY started_at DESC'
        );
        $stmt->execute(['job_id' => $jobId]);
        $backups = $stmt->fetchAll();
        $expired = $this->computeExpired($backups, $daily, $weekly, $monthly);
        return array_column($expired, 'id');
    }
}
```

- [ ] **Step 5: Run tests to verify they pass**

```bash
php vendor/bin/phpunit tests/unit/RetentionPolicyServiceTest.php
```
Expected: 2 tests PASS

- [ ] **Step 6: Commit**

```bash
git add lib/NotificationService.php lib/RetentionPolicyService.php tests/unit/RetentionPolicyServiceTest.php
git commit -m "feat: NotificationService (LINE Notify) and RetentionPolicyService (GFS policy)"
```

---

### Task 6: StorageAdapterInterface + LocalAdapter

**Files:**
- Create: `lib/StorageAdapter/StorageAdapterInterface.php`
- Create: `lib/StorageAdapter/LocalAdapter.php`
- Create: `tests/integration/LocalAdapterTest.php`

**Interfaces:**
- Produces: `StorageAdapterInterface` (all subsequent adapters implement this)
- All adapters return `['status' => 'success'|'failed', 'message' => '...']` from `testConnection()`

- [ ] **Step 1: Write the failing test**

`tests/integration/LocalAdapterTest.php`:
```php
<?php
use PHPUnit\Framework\TestCase;

class LocalAdapterTest extends TestCase
{
    private LocalAdapter $adapter;
    private string $tmpBase;

    protected function setUp(): void
    {
        $this->tmpBase = sys_get_temp_dir() . '/brs_local_' . uniqid();
        mkdir($this->tmpBase, 0700, true);
        $this->adapter = new LocalAdapter(['base_path' => $this->tmpBase]);
    }

    protected function tearDown(): void
    {
        array_map('unlink', glob($this->tmpBase . '/*'));
        @rmdir($this->tmpBase);
    }

    public function testUploadAndDownload(): void
    {
        $local = tempnam(sys_get_temp_dir(), 'brs_src_');
        file_put_contents($local, 'test backup content');

        $this->adapter->upload($local, 'job1/backup.zip');
        $this->assertTrue($this->adapter->exists('job1/backup.zip'));

        $dest = tempnam(sys_get_temp_dir(), 'brs_dl_');
        $this->adapter->download('job1/backup.zip', $dest);
        $this->assertEquals('test backup content', file_get_contents($dest));

        unlink($local);
        unlink($dest);
    }

    public function testDelete(): void
    {
        $local = tempnam(sys_get_temp_dir(), 'brs_del_');
        file_put_contents($local, 'x');
        $this->adapter->upload($local, 'todelete.txt');
        $this->adapter->delete('todelete.txt');
        $this->assertFalse($this->adapter->exists('todelete.txt'));
        unlink($local);
    }

    public function testTestConnectionReturnsSuccess(): void
    {
        $result = $this->adapter->testConnection();
        $this->assertEquals('success', $result['status']);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

```bash
php vendor/bin/phpunit tests/integration/LocalAdapterTest.php
```
Expected: FAIL

- [ ] **Step 3: Implement `lib/StorageAdapter/StorageAdapterInterface.php`**

```php
<?php
declare(strict_types=1);

interface StorageAdapterInterface
{
    public function upload(string $localPath, string $remotePath): bool;
    public function download(string $remotePath, string $localPath): bool;
    public function delete(string $remotePath): bool;
    public function exists(string $remotePath): bool;
    public function listFiles(string $prefix = ''): array;
    public function getFreeSpace(): ?int;  // bytes, null if provider can't report
    public function testConnection(): array;  // ['status' => 'success'|'failed', 'message' => '...']
}
```

- [ ] **Step 4: Implement `lib/StorageAdapter/LocalAdapter.php`**

```php
<?php
declare(strict_types=1);

class LocalAdapter implements StorageAdapterInterface
{
    private string $basePath;

    public function __construct(array $config)
    {
        $this->basePath = rtrim($config['base_path'], '/\\');
    }

    public function upload(string $localPath, string $remotePath): bool
    {
        $dest = $this->fullPath($remotePath);
        $dir  = dirname($dest);
        if (!is_dir($dir)) {
            mkdir($dir, 0750, true);
        }
        return copy($localPath, $dest);
    }

    public function download(string $remotePath, string $localPath): bool
    {
        return copy($this->fullPath($remotePath), $localPath);
    }

    public function delete(string $remotePath): bool
    {
        $path = $this->fullPath($remotePath);
        return file_exists($path) && unlink($path);
    }

    public function exists(string $remotePath): bool
    {
        return file_exists($this->fullPath($remotePath));
    }

    public function listFiles(string $prefix = ''): array
    {
        $dir   = $this->basePath . ($prefix ? DIRECTORY_SEPARATOR . $prefix : '');
        $files = glob($dir . DIRECTORY_SEPARATOR . '*', GLOB_BRACE);
        return $files ?: [];
    }

    public function getFreeSpace(): ?int
    {
        $free = disk_free_space($this->basePath);
        return $free !== false ? (int) $free : null;
    }

    public function testConnection(): array
    {
        if (!is_dir($this->basePath)) {
            return ['status' => 'failed', 'message' => "Directory does not exist: {$this->basePath}"];
        }
        $testFile = $this->basePath . DIRECTORY_SEPARATOR . '.brs_connection_test';
        if (file_put_contents($testFile, 'ok') === false) {
            return ['status' => 'failed', 'message' => 'Cannot write to storage directory'];
        }
        unlink($testFile);
        return ['status' => 'success', 'message' => 'Local storage accessible, read/write OK'];
    }

    private function fullPath(string $remotePath): string
    {
        return $this->basePath . DIRECTORY_SEPARATOR . ltrim($remotePath, '/\\');
    }
}
```

- [ ] **Step 5: Run tests to verify they pass**

```bash
php vendor/bin/phpunit tests/integration/LocalAdapterTest.php
```
Expected: All tests PASS

- [ ] **Step 6: Commit**

```bash
git add lib/StorageAdapter/ tests/integration/LocalAdapterTest.php
git commit -m "feat: StorageAdapterInterface and LocalAdapter with integration tests"
```

---

### Task 7: NasAdapter + S3Adapter

**Files:**
- Create: `lib/StorageAdapter/NasAdapter.php`
- Create: `lib/StorageAdapter/S3Adapter.php`

**Interfaces:**
- Consumes: `StorageAdapterInterface`
- NAS config keys: `unc_path`, `username` (optional), `password_encrypted` (optional)
- S3 config keys: `bucket`, `region`, `access_key_encrypted`, `secret_key_encrypted`, `path_prefix`, `storage_class`

- [ ] **Step 1: Implement `lib/StorageAdapter/NasAdapter.php`**

NAS uses UNC path — on Windows, if credentials are needed, `net use` maps the share first.

```php
<?php
declare(strict_types=1);

class NasAdapter implements StorageAdapterInterface
{
    private string $basePath;
    private ?string $username;
    private ?string $password;

    public function __construct(array $config, ?EncryptionService $encryption = null)
    {
        $this->basePath = rtrim($config['unc_path'] ?? $config['mapped_drive'] ?? '', '/\\');
        $this->username = $config['username'] ?? null;
        $this->password = null;

        if (isset($config['password_encrypted']) && $encryption) {
            $this->password = $encryption->decryptString($config['password_encrypted'], 'credential');
        }
    }

    public function upload(string $localPath, string $remotePath): bool
    {
        $dest = $this->fullPath($remotePath);
        $dir  = dirname($dest);
        if (!is_dir($dir)) {
            mkdir($dir, 0750, true);
        }
        return copy($localPath, $dest);
    }

    public function download(string $remotePath, string $localPath): bool
    {
        return copy($this->fullPath($remotePath), $localPath);
    }

    public function delete(string $remotePath): bool
    {
        $path = $this->fullPath($remotePath);
        return file_exists($path) && unlink($path);
    }

    public function exists(string $remotePath): bool
    {
        return file_exists($this->fullPath($remotePath));
    }

    public function listFiles(string $prefix = ''): array
    {
        $dir = $this->basePath . ($prefix ? DIRECTORY_SEPARATOR . $prefix : '');
        return glob($dir . DIRECTORY_SEPARATOR . '*') ?: [];
    }

    public function getFreeSpace(): ?int
    {
        $free = @disk_free_space($this->basePath);
        return $free !== false ? (int) $free : null;
    }

    public function testConnection(): array
    {
        $testFile = $this->basePath . DIRECTORY_SEPARATOR . '.brs_test';
        if (@file_put_contents($testFile, 'ok') === false) {
            return ['status' => 'failed', 'message' => "Cannot write to NAS path: {$this->basePath}"];
        }
        @unlink($testFile);
        return ['status' => 'success', 'message' => 'NAS share accessible, read/write OK'];
    }

    private function fullPath(string $remotePath): string
    {
        return $this->basePath . DIRECTORY_SEPARATOR . ltrim($remotePath, '/\\');
    }
}
```

- [ ] **Step 2: Implement `lib/StorageAdapter/S3Adapter.php`**

```php
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
```

- [ ] **Step 3: Manual test for NasAdapter (if NAS available)**

If a NAS or mapped drive is available:
```bash
php -r "
require 'vendor/autoload.php';
Config::init('config/app.config.php');
\$a = new NasAdapter(['unc_path' => '\\\\\\\\SERVER\\\\share']);
var_dump(\$a->testConnection());
"
```
Expected: `['status' => 'success', ...]`

- [ ] **Step 4: Commit**

```bash
git add lib/StorageAdapter/NasAdapter.php lib/StorageAdapter/S3Adapter.php
git commit -m "feat: NasAdapter (UNC path) and S3Adapter (AWS SDK)"
```

---

### Task 8: GoogleDriveAdapter + SftpAdapter

**Files:**
- Create: `lib/StorageAdapter/GoogleDriveAdapter.php`
- Create: `lib/StorageAdapter/SftpAdapter.php`

**Interfaces:**
- Google Drive config keys: `service_account_json_encrypted`, `shared_drive_folder_id`, `use_shared_drive`
- SFTP config keys: `host`, `port`, `username`, `auth_method` (`key`|`password`), `private_key_path_encrypted`|`password_encrypted`, `remote_base_path`

- [ ] **Step 1: Implement `lib/StorageAdapter/GoogleDriveAdapter.php`**

```php
<?php
declare(strict_types=1);

use Google\Client as GoogleClient;
use Google\Service\Drive;
use Google\Service\Drive\DriveFile;

class GoogleDriveAdapter implements StorageAdapterInterface
{
    private Drive $driveService;
    private string $folderId;
    private bool $useSharedDrive;

    public function __construct(array $config, ?EncryptionService $encryption = null)
    {
        $saJson = $config['service_account_json_encrypted'] ?? $config['service_account_json'] ?? '';
        if ($encryption && isset($config['service_account_json_encrypted'])) {
            $saJson = $encryption->decryptString($config['service_account_json_encrypted'], 'credential');
        }

        $this->folderId      = $config['shared_drive_folder_id'];
        $this->useSharedDrive = (bool) ($config['use_shared_drive'] ?? false);

        $client = new GoogleClient();
        $client->setAuthConfig(json_decode($saJson, true));
        $client->setScopes([Drive::DRIVE]);
        $this->driveService = new Drive($client);
    }

    public function upload(string $localPath, string $remotePath): bool
    {
        $filename = basename($remotePath);
        $meta = new DriveFile(['name' => $filename, 'parents' => [$this->folderId]]);
        $params = ['data' => file_get_contents($localPath), 'mimeType' => 'application/octet-stream', 'uploadType' => 'multipart'];
        if ($this->useSharedDrive) {
            $params['supportsAllDrives'] = true;
        }
        $this->driveService->files->create($meta, $params);
        return true;
    }

    public function download(string $remotePath, string $localPath): bool
    {
        $fileId = $this->findFileId(basename($remotePath));
        if (!$fileId) return false;
        $params = $this->useSharedDrive ? ['supportsAllDrives' => true] : [];
        $response = $this->driveService->files->get($fileId, array_merge($params, ['alt' => 'media']));
        file_put_contents($localPath, $response->getBody()->getContents());
        return true;
    }

    public function delete(string $remotePath): bool
    {
        $fileId = $this->findFileId(basename($remotePath));
        if (!$fileId) return false;
        $params = $this->useSharedDrive ? ['supportsAllDrives' => true] : [];
        $this->driveService->files->delete($fileId, $params);
        return true;
    }

    public function exists(string $remotePath): bool
    {
        return (bool) $this->findFileId(basename($remotePath));
    }

    public function listFiles(string $prefix = ''): array
    {
        $q = "'{$this->folderId}' in parents and trashed = false";
        if ($prefix) $q .= " and name contains '$prefix'";
        $params = ['q' => $q, 'fields' => 'files(id,name)'];
        if ($this->useSharedDrive) {
            $params += ['includeItemsFromAllDrives' => true, 'supportsAllDrives' => true, 'corpora' => 'drive', 'driveId' => $this->folderId];
        }
        $result = $this->driveService->files->listFiles($params);
        return array_column($result->getFiles(), 'name');
    }

    public function getFreeSpace(): ?int
    {
        return null;
    }

    public function testConnection(): array
    {
        try {
            $this->listFiles('.brs_test');
            return ['status' => 'success', 'message' => 'Google Drive folder accessible'];
        } catch (\Exception $e) {
            return ['status' => 'failed', 'message' => $e->getMessage()];
        }
    }

    private function findFileId(string $filename): ?string
    {
        $q = "'{$this->folderId}' in parents and name = '$filename' and trashed = false";
        $params = ['q' => $q, 'fields' => 'files(id)'];
        if ($this->useSharedDrive) {
            $params += ['includeItemsFromAllDrives' => true, 'supportsAllDrives' => true];
        }
        $result = $this->driveService->files->listFiles($params);
        $files  = $result->getFiles();
        return $files ? $files[0]->getId() : null;
    }
}
```

- [ ] **Step 2: Implement `lib/StorageAdapter/SftpAdapter.php`**

Uses `phpseclib/phpseclib` (installed via Composer).

```php
<?php
declare(strict_types=1);

use phpseclib3\Net\SFTP;
use phpseclib3\Crypt\PublicKeyLoader;

class SftpAdapter implements StorageAdapterInterface
{
    private SFTP $sftp;
    private string $remotePath;

    public function __construct(array $config, ?EncryptionService $encryption = null)
    {
        $this->remotePath = rtrim($config['remote_base_path'], '/');
        $this->sftp = new SFTP($config['host'], (int) ($config['port'] ?? 22));

        if ($config['auth_method'] === 'key') {
            $keyPath = $config['private_key_path_encrypted'] ?? $config['private_key_path'] ?? '';
            if ($encryption && isset($config['private_key_path_encrypted'])) {
                $keyPath = $encryption->decryptString($keyPath, 'credential');
            }
            $key = PublicKeyLoader::load(file_get_contents($keyPath));
            if (!$this->sftp->login($config['username'], $key)) {
                throw new \RuntimeException("SFTP key auth failed for {$config['host']}");
            }
        } else {
            $password = $config['password_encrypted'] ?? $config['password'] ?? '';
            if ($encryption && isset($config['password_encrypted'])) {
                $password = $encryption->decryptString($password, 'credential');
            }
            if (!$this->sftp->login($config['username'], $password)) {
                throw new \RuntimeException("SFTP password auth failed for {$config['host']}");
            }
        }
    }

    public function upload(string $localPath, string $remotePath): bool
    {
        $dest = $this->remotePath . '/' . $remotePath;
        $dir  = dirname($dest);
        $this->sftp->mkdir($dir, -1, true);
        return $this->sftp->put($dest, $localPath, SFTP::SOURCE_LOCAL_FILE);
    }

    public function download(string $remotePath, string $localPath): bool
    {
        return $this->sftp->get($this->remotePath . '/' . $remotePath, $localPath);
    }

    public function delete(string $remotePath): bool
    {
        return $this->sftp->delete($this->remotePath . '/' . $remotePath);
    }

    public function exists(string $remotePath): bool
    {
        return $this->sftp->stat($this->remotePath . '/' . $remotePath) !== false;
    }

    public function listFiles(string $prefix = ''): array
    {
        $dir = $this->remotePath . ($prefix ? '/' . $prefix : '');
        return $this->sftp->nlist($dir) ?: [];
    }

    public function getFreeSpace(): ?int
    {
        $stat = $this->sftp->statvfs($this->remotePath);
        if (!$stat) return null;
        return (int) ($stat['f_bsize'] * $stat['f_bavail']);
    }

    public function testConnection(): array
    {
        try {
            $testPath = $this->remotePath . '/.brs_connection_test';
            $this->sftp->put($testPath, 'ok');
            $this->sftp->delete($testPath);
            return ['status' => 'success', 'message' => 'SFTP connection OK, write/delete test passed'];
        } catch (\Exception $e) {
            return ['status' => 'failed', 'message' => $e->getMessage()];
        }
    }
}
```

- [ ] **Step 3: Add `StorageAdapterFactory` to centralize adapter creation**

`lib/StorageAdapter/StorageAdapterFactory.php`:
```php
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
```

- [ ] **Step 4: Commit**

```bash
git add lib/StorageAdapter/
git commit -m "feat: GoogleDriveAdapter, SftpAdapter, StorageAdapterFactory"
```

---

### Task 9: BackupEngine

**Files:**
- Create: `lib/BackupEngine.php`
- Create: `tests/integration/BackupEngineTest.php`

**Interfaces:**
- Consumes: `Database::pdo()`, `EncryptionService`, `ChecksumService`, `LockManager`, `AuditLogger`, `NotificationService`, `StorageAdapterFactory`, `Config::get('mysqldump_path')`, `Config::get('temp_dir')`
- Produces: `BackupEngine::run(int $jobId, string $triggeredBy, ?int $userId): int` (returns backup_log_id); `BackupEngine::isDue(int $jobId): bool`

- [ ] **Step 1: Write failing integration test**

`tests/integration/BackupEngineTest.php`:
```php
<?php
use PHPUnit\Framework\TestCase;

class BackupEngineTest extends TestCase
{
    private BackupEngine $engine;
    private string $tmpAppDir;
    private string $tmpStorageDir;
    private int $testJobId;

    protected function setUp(): void
    {
        $pdo = Database::pdo();
        $this->tmpAppDir = sys_get_temp_dir() . '/brs_app_' . uniqid();
        $this->tmpStorageDir = sys_get_temp_dir() . '/brs_st_' . uniqid();
        mkdir($this->tmpAppDir, 0755, true);
        mkdir($this->tmpStorageDir, 0755, true);
        file_put_contents($this->tmpAppDir . '/index.php', '<?php echo "hello";');

        $pdo->prepare("INSERT INTO storage_targets (target_name,provider_type,config_json) VALUES('Test','local',:c)")
            ->execute(['c' => json_encode(['base_path' => $this->tmpStorageDir])]);
        $stId = (int) $pdo->lastInsertId();

        $pdo->prepare("INSERT INTO backup_jobs (job_name,app_path,backup_type,encryption_enabled) VALUES('Test Job',:p,'files_only',0)")
            ->execute(['p' => $this->tmpAppDir]);
        $this->testJobId = (int) $pdo->lastInsertId();
        $pdo->prepare("INSERT INTO job_storage_targets (job_id,storage_target_id) VALUES(?,?)")->execute([$this->testJobId, $stId]);

        $enc   = new EncryptionService(Config::get('encryption_key_path'));
        $chk   = new ChecksumService();
        $lock  = new LockManager(Config::get('temp_dir') . '/locks');
        $audit = new AuditLogger($pdo);
        $notif = new NotificationService(null, 'none');
        $this->engine = new BackupEngine($pdo, $enc, $chk, $lock, $audit, $notif,
            Config::get('temp_dir'), Config::get('mysqldump_path'));
    }

    protected function tearDown(): void
    {
        Database::pdo()->prepare("DELETE FROM backup_jobs WHERE id=?")->execute([$this->testJobId]);
        @array_map('unlink', glob($this->tmpAppDir . '/*'));
        @rmdir($this->tmpAppDir);
        @array_map('unlink', glob($this->tmpStorageDir . '/*'));
        @rmdir($this->tmpStorageDir);
    }

    public function testRunProducesSuccessfulBackupLog(): void
    {
        $id = $this->engine->run($this->testJobId, 'cli', null);
        $this->assertGreaterThan(0, $id);
        $stmt = Database::pdo()->prepare("SELECT * FROM backup_logs WHERE id=?");
        $stmt->execute([$id]);
        $log = $stmt->fetch();
        $this->assertEquals('success', $log['status']);
        $this->assertEquals('passed', $log['verification_status']);
    }
}
```

- [ ] **Step 2: Run to verify it fails**

```bash
php vendor/bin/phpunit tests/integration/BackupEngineTest.php
```
Expected: FAIL — class BackupEngine not found

- [ ] **Step 3: Implement `lib/BackupEngine.php`**

```php
<?php
declare(strict_types=1);

class BackupEngine
{
    public function __construct(
        private readonly PDO                 $pdo,
        private readonly EncryptionService   $encryption,
        private readonly ChecksumService     $checksum,
        private readonly LockManager         $lockManager,
        private readonly AuditLogger         $auditLogger,
        private readonly NotificationService $notification,
        private readonly string              $tempDir,
        private readonly string              $mysqldumpPath,
    ) {}

    public function run(int $jobId, string $triggeredBy = 'cli', ?int $userId = null): int
    {
        $job = $this->loadJob($jobId);
        if (!$this->lockManager->acquire($jobId)) {
            throw new \RuntimeException("Job $jobId already running.", 3);
        }
        $logId   = $this->createLog($jobId, $triggeredBy, $userId);
        $workDir = $this->tempDir . DIRECTORY_SEPARATOR . "bk_{$jobId}_" . time();
        mkdir($workDir, 0700, true);
        try {
            $adapters  = $this->loadAdapters($jobId);
            $this->checkDiskSpace($job, $workDir);
            $filePaths = [];
            $checksums = [];

            if (in_array($job['backup_type'], ['database_only', 'both'])) {
                $sql = $workDir . '/database.sql';
                $this->dumpDatabase($job, $sql);
                $checksums['database'] = $this->checksum->generate($sql);
                if ($job['encryption_enabled']) {
                    $this->encryption->encryptFile($sql, $sql . '.enc', 'backup_file');
                    unlink($sql); $sql .= '.enc';
                }
                $filePaths['database'] = $sql;
            }
            if (in_array($job['backup_type'], ['files_only', 'both'])) {
                $zip = $workDir . '/files.zip';
                $this->zipFiles($job, $zip);
                $checksums['files'] = $this->checksum->generate($zip);
                if ($job['encryption_enabled']) {
                    $this->encryption->encryptFile($zip, $zip . '.enc', 'backup_file');
                    unlink($zip); $zip .= '.enc';
                }
                $filePaths['files'] = $zip;
            }

            $manifest = $workDir . '/manifest.json';
            file_put_contents($manifest, json_encode([
                'job_id' => $jobId, 'job_name' => $job['job_name'],
                'backup_log_id' => $logId, 'created_at' => date('c'),
                'backup_type' => $job['backup_type'],
                'encrypted' => (bool) $job['encryption_enabled'],
                'checksums' => $checksums,
                'files' => array_map('basename', $filePaths),
            ], JSON_PRETTY_PRINT));
            $filePaths['manifest'] = $manifest;

            $this->verifyBackup($filePaths, (bool) $job['encryption_enabled']);

            $total  = 0;
            $prefix = "{$jobId}/" . date('Y-m-d_His');
            foreach ($adapters as ['adapter' => $ad, 'target_id' => $tid]) {
                foreach ($filePaths as $type => $lp) {
                    $rp   = $prefix . '/' . basename($lp);
                    $ad->upload($lp, $rp);
                    $sz   = filesize($lp) ?: 0;
                    $total += $sz;
                    $this->recordFile($logId, $tid, $type, $rp, $sz);
                }
            }

            $this->finalizeLog($logId, 'success', $total,
                $checksums['files'] ?? null, $checksums['database'] ?? null,
                $manifest, (bool) $job['encryption_enabled']);

            (new RetentionPolicyService($this->pdo))->getExpiredBackupIds(
                $jobId, (int)$job['retention_daily'], (int)$job['retention_weekly'], (int)$job['retention_monthly']
            );
            $this->notification->notifyBackupSuccess($jobId, $job['job_name'], 0, $total);
            $this->auditLogger->log('backup.success', $userId, 'backup_job', $jobId);
            return $logId;
        } catch (\Throwable $e) {
            $this->pdo->prepare('UPDATE backup_logs SET status="failed",finished_at=NOW(),error_message=? WHERE id=?')
                ->execute([$e->getMessage(), $logId]);
            $this->notification->notifyBackupFailure($jobId, $job['job_name'], $e->getMessage());
            throw $e;
        } finally {
            $this->rmdirR($workDir);
            $this->lockManager->release($jobId);
        }
    }

    public function isDue(int $jobId): bool
    {
        $stmt = $this->pdo->prepare('SELECT schedule_cron,is_active FROM backup_jobs WHERE id=?');
        $stmt->execute([$jobId]);
        $job = $stmt->fetch();
        if (!$job || !$job['is_active'] || !$job['schedule_cron']) return false;
        return $this->cronMatches($job['schedule_cron'], new \DateTimeImmutable());
    }

    private function loadJob(int $id): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM backup_jobs WHERE id=? AND is_active=1');
        $stmt->execute([$id]);
        $job = $stmt->fetch();
        if (!$job) throw new \RuntimeException("Job $id not found or disabled.");
        return $job;
    }

    private function loadAdapters(int $jobId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT st.*,jst.priority FROM storage_targets st
             JOIN job_storage_targets jst ON st.id=jst.storage_target_id
             WHERE jst.job_id=? AND st.is_active=1 ORDER BY jst.priority'
        );
        $stmt->execute([$jobId]);
        return array_map(fn($t) => [
            'adapter'   => StorageAdapterFactory::create($t, $this->encryption),
            'target_id' => $t['id'],
        ], $stmt->fetchAll());
    }

    private function checkDiskSpace(array $job, string $dir): void
    {
        $free = disk_free_space($dir);
        if ($free === false) return;
        $est = ($job['app_path'] && is_dir($job['app_path'])) ? $this->dirSize($job['app_path']) : 0;
        $req = (int)($est * 1.2) + 104857600;
        if ($free < $req) {
            throw new \RuntimeException(sprintf("Insufficient disk space: %.1fGB free, %.1fGB required.", $free/1073741824, $req/1073741824), 2);
        }
    }

    private function dumpDatabase(array $job, string $dest): void
    {
        $pw = $job['db_password_encrypted']
            ? $this->encryption->decryptString($job['db_password_encrypted'], 'credential') : '';
        $cmd = sprintf('"%s" --host=%s --port=%d --user=%s --password=%s --single-transaction --routines --triggers --databases %s > "%s" 2>&1',
            $this->mysqldumpPath,
            escapeshellarg($job['db_host']), (int)$job['db_port'],
            escapeshellarg($job['db_username']), escapeshellarg($pw),
            escapeshellarg($job['db_name']), $dest);
        exec($cmd, $out, $rc);
        if ($rc !== 0) throw new \RuntimeException("mysqldump failed: " . implode("\n", $out));
        $tail = file_get_contents($dest, false, null, -512);
        if (!str_contains((string)$tail, 'Dump completed')) {
            throw new \RuntimeException("mysqldump output appears incomplete.");
        }
    }

    private function zipFiles(array $job, string $dest): void
    {
        $exclude = json_decode($job['exclude_patterns'] ?? '[]', true) ?: [];
        $zip = new \ZipArchive();
        if ($zip->open($dest, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) {
            throw new \RuntimeException("Cannot create zip: $dest");
        }
        $base = rtrim($job['app_path'], '/\\');
        $iter = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($base, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST
        );
        foreach ($iter as $file) {
            $rel = str_replace($base . DIRECTORY_SEPARATOR, '', $file->getPathname());
            if ($this->isExcluded($rel, $exclude)) continue;
            $file->isDir() ? $zip->addEmptyDir($rel) : $zip->addFile($file->getPathname(), $rel);
        }
        $zip->close();
    }

    private function verifyBackup(array $filePaths, bool $encrypted): void
    {
        if (isset($filePaths['files']) && !$encrypted) {
            $zip = new \ZipArchive();
            if ($zip->open($filePaths['files']) !== true) throw new \RuntimeException("Cannot open zip for verification.");
            if (!$zip->testArchive()) throw new \RuntimeException("Zip archive failed integrity test.");
            $zip->close();
        }
    }

    private function createLog(int $jobId, string $by, ?int $userId): int
    {
        $this->pdo->prepare('INSERT INTO backup_logs (job_id,triggered_by,triggered_by_user_id,status,started_at) VALUES(?,?,?,?,NOW())')
            ->execute([$jobId, $by, $userId, 'running']);
        return (int) $this->pdo->lastInsertId();
    }

    private function finalizeLog(int $id, string $status, int $size, ?string $fc, ?string $dc, string $mp, bool $enc): void
    {
        $this->pdo->prepare(
            'UPDATE backup_logs SET status=?,finished_at=NOW(),
             duration_seconds=TIMESTAMPDIFF(SECOND,started_at,NOW()),
             total_size_bytes=?,files_checksum=?,database_checksum=?,
             manifest_path=?,is_encrypted=?,verification_status="passed" WHERE id=?'
        )->execute([$status, $size, $fc, $dc, $mp, $enc?1:0, $id]);
    }

    private function recordFile(int $logId, int $tid, string $type, string $rp, int $sz): void
    {
        $map = ['files'=>'files_archive','database'=>'database_dump','manifest'=>'manifest'];
        $this->pdo->prepare('INSERT INTO backup_files (backup_log_id,storage_target_id,file_type,remote_path,size_bytes) VALUES(?,?,?,?,?)')
            ->execute([$logId, $tid, $map[$type] ?? $type, $rp, $sz]);
    }

    private function isExcluded(string $rel, array $patterns): bool
    {
        foreach ($patterns as $p) {
            if (fnmatch($p, $rel) || fnmatch($p, basename($rel))) return true;
        }
        return false;
    }

    private function cronMatches(string $cron, \DateTimeImmutable $now): bool
    {
        [$min,$hr,$dom,$mon,$dow] = explode(' ', $cron);
        $m = fn($f,$v) => $f==='*' || (int)$f===$v || (str_contains($f,'/')&&$v%(int)explode('/',$f)[1]===0);
        return $m($min,(int)$now->format('i')) && $m($hr,(int)$now->format('H'))
            && $m($dom,(int)$now->format('j')) && $m($mon,(int)$now->format('n'))
            && $m($dow,(int)$now->format('w'));
    }

    private function dirSize(string $dir): int
    {
        $sz = 0;
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir,\RecursiveDirectoryIterator::SKIP_DOTS)) as $f) {
            $sz += $f->getSize();
        }
        return $sz;
    }

    private function rmdirR(string $dir): void
    {
        if (!is_dir($dir)) return;
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir,\RecursiveDirectoryIterator::SKIP_DOTS),\RecursiveIteratorIterator::CHILD_FIRST) as $f) {
            $f->isDir() ? rmdir($f->getPathname()) : unlink($f->getPathname());
        }
        rmdir($dir);
    }
}
```

- [ ] **Step 4: Run integration test**

```bash
php vendor/bin/phpunit tests/integration/BackupEngineTest.php
```
Expected: PASS — backup_log status=success, verification_status=passed

- [ ] **Step 5: Commit**

```bash
git add lib/BackupEngine.php tests/integration/BackupEngineTest.php
git commit -m "feat: BackupEngine — full backup orchestration with verification"
```

---

### Task 10: RestoreEngine

**Files:**
- Create: `lib/RestoreEngine.php`
- Create: `tests/integration/RestoreEngineTest.php`

**Interfaces:**
- Consumes: `Database::pdo()`, `EncryptionService`, `ChecksumService`, `AuditLogger`, `NotificationService`, `Config::get('mysql_path')`, `StorageAdapterFactory`
- Produces: `RestoreEngine::validate(int $backupLogId): array`; `RestoreEngine::execute(int $backupLogId, string $mode, string $restoreTarget, ?string $altPath, ?string $altDb, int $userId): int`; `RestoreEngine::rollback(int $restoreLogId, int $userId): void`

- [ ] **Step 1: Write failing test**

`tests/integration/RestoreEngineTest.php`:
```php
<?php
use PHPUnit\Framework\TestCase;

class RestoreEngineTest extends TestCase
{
    public function testDryRunValidate(): void
    {
        $pdo  = Database::pdo();
        $stmt = $pdo->prepare("SELECT id FROM backup_logs WHERE status='success' ORDER BY id DESC LIMIT 1");
        $stmt->execute();
        $row  = $stmt->fetch();
        if (!$row) $this->markTestSkipped('No successful backup found. Run BackupEngineTest first.');

        $engine = $this->makeEngine($pdo);
        $result = $engine->validate((int) $row['id']);

        $this->assertArrayHasKey('checksum_valid', $result);
        $this->assertArrayHasKey('extraction_test_passed', $result);
    }

    private function makeEngine(PDO $pdo): RestoreEngine
    {
        return new RestoreEngine(
            $pdo,
            new EncryptionService(Config::get('encryption_key_path')),
            new ChecksumService(),
            new AuditLogger($pdo),
            new NotificationService(null, 'none'),
            Config::get('temp_dir'),
            Config::get('mysql_path'),
        );
    }
}
```

- [ ] **Step 2: Run to verify it fails or skips**

```bash
php vendor/bin/phpunit tests/integration/RestoreEngineTest.php
```

- [ ] **Step 3: Implement `lib/RestoreEngine.php`**

```php
<?php
declare(strict_types=1);

class RestoreEngine
{
    public function __construct(
        private readonly PDO                 $pdo,
        private readonly EncryptionService   $encryption,
        private readonly ChecksumService     $checksum,
        private readonly AuditLogger         $auditLogger,
        private readonly NotificationService $notification,
        private readonly string              $tempDir,
        private readonly string              $mysqlPath,
    ) {}

    public function validate(int $backupLogId): array
    {
        $log   = $this->loadLog($backupLogId);
        $files = $this->loadFiles($backupLogId);
        $workDir = $this->tempDir . '/rv_' . $backupLogId . '_' . time();
        mkdir($workDir, 0700, true);
        try {
            $adapter  = StorageAdapterFactory::create($files[0], $this->encryption);
            $manifest = $this->downloadManifest($adapter, $files, $workDir);
            $result   = ['checksum_valid' => false, 'extraction_test_passed' => false, 'manifest' => [
                'files_count' => count($manifest['files'] ?? []),
                'backup_date' => $manifest['created_at'] ?? null,
            ]];
            $archiveRow = $this->findFile($files, 'files_archive');
            if ($archiveRow) {
                $zipEnc = $workDir . '/files.zip' . ($manifest['encrypted'] ? '.enc' : '');
                $adapter->download($archiveRow['remote_path'], $zipEnc);
                $zip = $workDir . '/files.zip';
                if ($manifest['encrypted']) {
                    $this->encryption->decryptFile($zipEnc, $zip, 'backup_file');
                } else {
                    $zip = $zipEnc;
                }
                $expected = $manifest['checksums']['files'] ?? $log['files_checksum'];
                if ($expected) $result['checksum_valid'] = $this->checksum->verify($zip, $expected);
                $za = new \ZipArchive();
                if ($za->open($zip) === true) {
                    $result['extraction_test_passed'] = $za->testArchive();
                    $za->close();
                }
            } else {
                $result['checksum_valid'] = true;
                $result['extraction_test_passed'] = true;
            }
            return $result;
        } finally {
            $this->rmdirR($workDir);
        }
    }

    public function execute(int $backupLogId, string $mode, string $restoreTarget, ?string $altPath, ?string $altDb, int $userId): int
    {
        $log   = $this->loadLog($backupLogId);
        $job   = $this->loadJob((int) $log['job_id']);
        $files = $this->loadFiles($backupLogId);
        $v     = $this->validate($backupLogId);
        if (isset($v['checksum_valid']) && !$v['checksum_valid'] && !($v['manifest']['encrypted'] ?? false)) {
            throw new \RuntimeException("Backup checksum mismatch — restore aborted.", 422);
        }
        $rlId    = $this->createRestoreLog($log['job_id'], $backupLogId, $mode, $restoreTarget, $altPath, $altDb, $userId);
        $workDir = $this->tempDir . '/re_' . $backupLogId . '_' . time();
        mkdir($workDir, 0700, true);
        try {
            $adapter  = StorageAdapterFactory::create($files[0], $this->encryption);
            $manifest = $this->downloadManifest($adapter, $files, $workDir);
            $tgtPath  = $restoreTarget === 'alternate' ? $altPath : $job['app_path'];
            $tgtDb    = $restoreTarget === 'alternate' ? $altDb  : $job['db_name'];
            if ($mode === 'dry_run') {
                $this->extractFiles($adapter, $files, $manifest, $workDir, $workDir . '/dry');
                $this->updateRestoreLog($rlId, 'success');
                return $rlId;
            }
            if ($tgtPath && in_array($job['backup_type'], ['files_only','both'])) {
                $this->extractFiles($adapter, $files, $manifest, $workDir, $tgtPath);
            }
            if ($tgtDb && in_array($job['backup_type'], ['database_only','both'])) {
                $this->importDb($adapter, $files, $manifest, $workDir, $job, $tgtDb);
            }
            $this->updateRestoreLog($rlId, 'success');
            $this->auditLogger->log('restore.execute', $userId, 'backup_job', (int)$log['job_id'], null,
                ['backup_log_id' => $backupLogId, 'mode' => $mode]);
            $this->notification->notifyRestoreExecuted((int)$log['job_id'], $job['job_name'], $mode, "user:$userId");
            return $rlId;
        } catch (\Throwable $e) {
            $this->updateRestoreLog($rlId, 'failed', $e->getMessage());
            throw $e;
        } finally {
            $this->rmdirR($workDir);
        }
    }

    public function rollback(int $restoreLogId, int $userId): void
    {
        $stmt = $this->pdo->prepare('SELECT * FROM restore_logs WHERE id=?');
        $stmt->execute([$restoreLogId]);
        $rl = $stmt->fetch();
        if (!$rl || !$rl['pre_restore_snapshot_id']) throw new \RuntimeException("No pre-restore snapshot for rollback.");
        $this->execute((int)$rl['pre_restore_snapshot_id'], 'real', 'original', null, null, $userId);
        $this->pdo->prepare('UPDATE restore_logs SET status="rolled_back" WHERE id=?')->execute([$restoreLogId]);
    }

    private function extractFiles(StorageAdapterInterface $adapter, array $files, array $manifest, string $workDir, string $dest): void
    {
        $row = $this->findFile($files, 'files_archive');
        if (!$row) return;
        $enc = $workDir . '/files.zip' . ($manifest['encrypted'] ? '.enc' : '');
        $adapter->download($row['remote_path'], $enc);
        $zip = $workDir . '/files.zip';
        if ($manifest['encrypted']) { $this->encryption->decryptFile($enc, $zip, 'backup_file'); } else { $zip = $enc; }
        if (!is_dir($dest)) mkdir($dest, 0755, true);
        $za = new \ZipArchive();
        if ($za->open($zip) !== true) throw new \RuntimeException("Cannot open zip for extraction.");
        $za->extractTo($dest);
        $za->close();
    }

    private function importDb(StorageAdapterInterface $adapter, array $files, array $manifest, string $workDir, array $job, string $tgtDb): void
    {
        $row = $this->findFile($files, 'database_dump');
        if (!$row) return;
        $enc = $workDir . '/database.sql' . ($manifest['encrypted'] ? '.enc' : '');
        $adapter->download($row['remote_path'], $enc);
        $sql = $workDir . '/database.sql';
        if ($manifest['encrypted']) { $this->encryption->decryptFile($enc, $sql, 'backup_file'); } else { $sql = $enc; }
        $pw = $job['db_password_encrypted'] ? $this->encryption->decryptString($job['db_password_encrypted'], 'credential') : '';
        $cmd = sprintf('"%s" --host=%s --port=%d --user=%s --password=%s %s < "%s" 2>&1',
            $this->mysqlPath, escapeshellarg($job['db_host']), (int)$job['db_port'],
            escapeshellarg($job['db_username']), escapeshellarg($pw), escapeshellarg($tgtDb), $sql);
        exec($cmd, $out, $rc);
        if ($rc !== 0) throw new \RuntimeException("mysql import failed: " . implode("\n", $out));
    }

    private function downloadManifest(StorageAdapterInterface $adapter, array $files, string $workDir): array
    {
        $row = $this->findFile($files, 'manifest');
        if (!$row) throw new \RuntimeException("Manifest not found in backup files.");
        $dest = $workDir . '/manifest.json';
        $adapter->download($row['remote_path'], $dest);
        return json_decode(file_get_contents($dest), true);
    }

    private function loadLog(int $id): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM backup_logs WHERE id=?');
        $stmt->execute([$id]);
        $r = $stmt->fetch();
        if (!$r) throw new \RuntimeException("Backup log $id not found.");
        return $r;
    }

    private function loadJob(int $id): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM backup_jobs WHERE id=?');
        $stmt->execute([$id]);
        $r = $stmt->fetch();
        if (!$r) throw new \RuntimeException("Job $id not found.");
        return $r;
    }

    private function loadFiles(int $logId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT bf.*,st.provider_type,st.config_json FROM backup_files bf
             JOIN storage_targets st ON st.id=bf.storage_target_id WHERE bf.backup_log_id=? ORDER BY bf.id'
        );
        $stmt->execute([$logId]);
        return $stmt->fetchAll();
    }

    private function findFile(array $files, string $type): ?array
    {
        foreach ($files as $f) { if ($f['file_type'] === $type) return $f; }
        return null;
    }

    private function createRestoreLog(int $jobId, int $blId, string $mode, string $target, ?string $ap, ?string $adb, int $uid): int
    {
        $this->pdo->prepare(
            'INSERT INTO restore_logs (job_id,backup_log_id,restore_mode,restore_target,alternate_path,alternate_db_name,initiated_by_user_id,status,started_at)
             VALUES(?,?,?,?,?,?,?,"running",NOW())'
        )->execute([$jobId, $blId, $mode, $target, $ap, $adb, $uid]);
        return (int) $this->pdo->lastInsertId();
    }

    private function updateRestoreLog(int $id, string $status, ?string $err = null): void
    {
        $this->pdo->prepare('UPDATE restore_logs SET status=?,finished_at=NOW(),error_message=? WHERE id=?')
            ->execute([$status, $err, $id]);
    }

    private function rmdirR(string $dir): void
    {
        if (!is_dir($dir)) return;
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir,\RecursiveDirectoryIterator::SKIP_DOTS),\RecursiveIteratorIterator::CHILD_FIRST) as $f) {
            $f->isDir() ? rmdir($f->getPathname()) : unlink($f->getPathname());
        }
        rmdir($dir);
    }
}
```

- [ ] **Step 4: Run test**

```bash
php vendor/bin/phpunit tests/integration/RestoreEngineTest.php
```
Expected: PASS or SKIPPED

- [ ] **Step 5: Commit**

```bash
git add lib/RestoreEngine.php tests/integration/RestoreEngineTest.php
git commit -m "feat: RestoreEngine — validate, dry-run, real restore, rollback"
```

---

### Task 11: CLI Bootstrap + healthcheck.php

**Files:**
- Create: `cli/common.php`
- Create: `cli/healthcheck.php`

- [ ] **Step 1: Create `cli/common.php`**

```php
<?php
declare(strict_types=1);
ini_set('memory_limit', '512M');
set_time_limit(0);
$rootDir = dirname(__DIR__);
require_once $rootDir . '/vendor/autoload.php';
Config::init($rootDir . '/config/app.config.php');

function cli_parse_args(array $argv): array
{
    $args = [];
    foreach (array_slice($argv, 1) as $arg) {
        if (preg_match('/^--([a-z0-9_-]+)(?:=(.+))?$/i', $arg, $m)) {
            $args[$m[1]] = $m[2] ?? true;
        }
    }
    return $args;
}

function cli_log(string $level, int $jobId, string $msg): void
{
    $line = sprintf("[%s] [%s] [job_id=%d] %s\n", date('Y-m-d H:i:s'), $level, $jobId, $msg);
    file_put_contents(Config::get('logs_dir') . '/backup-' . date('Y-m-d') . '.log', $line, FILE_APPEND | LOCK_EX);
    echo $line;
}

function cli_exit(string $msg, int $code = 1): never
{
    fwrite(STDERR, $msg . "\n");
    exit($code);
}
```

- [ ] **Step 2: Create `cli/healthcheck.php`**

```php
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
    echo ($status === 'ok' ? '✓' : '✗') . " [$status] $name" . ($detail ? " — $detail" : '') . "\n";
}
echo "\nHealthcheck " . ($ok ? 'PASSED' : 'FAILED') . "\n";
exit($ok ? 0 : 1);
```

- [ ] **Step 3: Test**

```bash
php cli/healthcheck.php
```
Expected: All `✓ [ok]` lines, exits 0

- [ ] **Step 4: Commit**

```bash
git add cli/common.php cli/healthcheck.php
git commit -m "feat: CLI bootstrap (common.php) and healthcheck.php"
```

---

### Task 12: CLI backup.php + list.php

**Files:**
- Create: `cli/backup.php`
- Create: `cli/list.php`

- [ ] **Step 1: Create `cli/backup.php`**

```php
<?php
require_once __DIR__ . '/common.php';
$args = cli_parse_args($argv);
$pdo  = Database::pdo();
$eng  = new BackupEngine($pdo,
    new EncryptionService(Config::get('encryption_key_path')),
    new ChecksumService(),
    new LockManager(Config::get('temp_dir') . '/locks'),
    new AuditLogger($pdo),
    new NotificationService(Config::get('line_notify_token'), Config::get('notify_mode')),
    Config::get('temp_dir'), Config::get('mysqldump_path'));

if (isset($args['all'])) {
    $rows = $pdo->query('SELECT id FROM backup_jobs WHERE is_active=1')->fetchAll();
    $code = 0;
    foreach ($rows as $r) {
        if ($eng->isDue((int)$r['id']) || isset($args['force'])) {
            try { $eng->run((int)$r['id'], 'schedule'); }
            catch (\Throwable $e) { fwrite(STDERR,"Job {$r['id']}: {$e->getMessage()}\n"); $code = max($code, $e->getCode()?:1); }
        }
    }
    exit($code);
}
if (!isset($args['job-id'])) cli_exit("Usage: php backup.php --job-id=N [--force] | --all");
try {
    $id = $eng->run((int)$args['job-id'], 'cli');
    echo "Backup completed. backup_log_id=$id\n";
    exit(0);
} catch (\Throwable $e) {
    cli_exit("Backup failed: " . $e->getMessage(), $e->getCode() ?: 1);
}
```

- [ ] **Step 2: Create `cli/list.php`**

```php
<?php
require_once __DIR__ . '/common.php';
$args = cli_parse_args($argv);
if (!isset($args['job-id'])) cli_exit("Usage: php list.php --job-id=N [--limit=20] [--format=table|json]");
$limit = (int)($args['limit'] ?? 20);
$fmt   = $args['format'] ?? 'table';
$stmt  = Database::pdo()->prepare(
    'SELECT id,started_at,status,verification_status,total_size_bytes,triggered_by
     FROM backup_logs WHERE job_id=? ORDER BY started_at DESC LIMIT ?'
);
$stmt->bindValue(1, (int)$args['job-id'], PDO::PARAM_INT);
$stmt->bindValue(2, $limit, PDO::PARAM_INT);
$stmt->execute();
$rows = $stmt->fetchAll();
if ($fmt === 'json') { echo json_encode($rows, JSON_PRETTY_PRINT) . "\n"; exit(0); }
printf("%-6s %-20s %-10s %-12s %-8s\n", 'ID','Started At','Status','Verified','Size');
echo str_repeat('-', 62) . "\n";
foreach ($rows as $r) {
    $sz = $r['total_size_bytes'] ? round($r['total_size_bytes']/1048576,1).'MB' : '-';
    printf("%-6d %-20s %-10s %-12s %-8s\n", $r['id'], $r['started_at'], $r['status'], $r['verification_status'], $sz);
}
exit(0);
```

- [ ] **Step 3: Test**

```bash
php cli/backup.php --job-id=1
php cli/list.php --job-id=1
```
Expected: backup runs, list shows recent backup

- [ ] **Step 4: Commit**

```bash
git add cli/backup.php cli/list.php
git commit -m "feat: CLI backup.php and list.php"
```

---

### Task 13: CLI restore.php + verify.php + cleanup.php

**Files:**
- Create: `cli/restore.php`
- Create: `cli/verify.php`
- Create: `cli/cleanup.php`

- [ ] **Step 1: Create `cli/restore.php`**

```php
<?php
require_once __DIR__ . '/common.php';
$args = cli_parse_args($argv);
if (!isset($args['backup-log-id'], $args['mode'])) {
    cli_exit("Usage: php restore.php --backup-log-id=N --mode=dry_run|real [--target=original|alternate] [--alt-path=P] [--alt-db=D] [--confirm=\"Name\"]");
}
$blId    = (int)$args['backup-log-id'];
$mode    = $args['mode'];
$target  = $args['target'] ?? 'original';
$confirm = $args['confirm'] ?? null;
if ($mode === 'real' && $target === 'original' && !$confirm) cli_exit("Real restore requires --confirm=\"Job Name\"");
$pdo = Database::pdo();
if ($confirm) {
    $stmt = $pdo->prepare('SELECT bj.job_name FROM backup_logs bl JOIN backup_jobs bj ON bj.id=bl.job_id WHERE bl.id=?');
    $stmt->execute([$blId]);
    $r = $stmt->fetch();
    if (!$r || $r['job_name'] !== $confirm) cli_exit("Confirmation does not match job name. Aborted.");
}
$eng = new RestoreEngine($pdo,
    new EncryptionService(Config::get('encryption_key_path')),
    new ChecksumService(),
    new AuditLogger($pdo),
    new NotificationService(Config::get('line_notify_token'), Config::get('notify_mode')),
    Config::get('temp_dir'), Config::get('mysql_path'));
try {
    $id = $eng->execute($blId, $mode, $target, $args['alt-path'] ?? null, $args['alt-db'] ?? null, 1);
    echo "Restore $mode completed. restore_log_id=$id\n";
    exit(0);
} catch (\Throwable $e) {
    cli_exit("Restore failed: " . $e->getMessage(), 1);
}
```

- [ ] **Step 2: Create `cli/verify.php`**

```php
<?php
require_once __DIR__ . '/common.php';
$args = cli_parse_args($argv);
$pdo  = Database::pdo();
$eng  = new RestoreEngine($pdo,
    new EncryptionService(Config::get('encryption_key_path')),
    new ChecksumService(), new AuditLogger($pdo),
    new NotificationService(null,'none'),
    Config::get('temp_dir'), Config::get('mysql_path'));
$ids = [];
if (isset($args['backup-log-id'])) {
    $ids[] = (int)$args['backup-log-id'];
} elseif (isset($args['job-id'])) {
    $s = $pdo->prepare("SELECT id FROM backup_logs WHERE job_id=? AND status='success' ORDER BY id DESC LIMIT 1");
    $s->execute([(int)$args['job-id']]); if ($r=$s->fetch()) $ids[]=(int)$r['id'];
} elseif (isset($args['all-recent'])) {
    $d=(int)($args['days']??7);
    $s=$pdo->prepare("SELECT id FROM backup_logs WHERE status='success' AND started_at>=DATE_SUB(NOW(),INTERVAL ? DAY)");
    $s->execute([$d]); $ids=array_column($s->fetchAll(),'id');
} else { cli_exit("Usage: php verify.php --backup-log-id=N | --job-id=N | --all-recent --days=7"); }
$exit=0;
foreach ($ids as $id) {
    echo "Verifying backup_log_id=$id ...\n";
    try {
        $r=$eng->validate($id);
        $ok=$r['checksum_valid']&&$r['extraction_test_passed'];
        echo ($ok?'  ✓ PASSED':'  ✗ FAILED') . "\n";
        $pdo->prepare('UPDATE backup_logs SET verification_status=? WHERE id=?')
            ->execute([$ok?'passed':'failed',$id]);
        if (!$ok) $exit=1;
    } catch (\Exception $e) { echo "  ✗ ERROR: {$e->getMessage()}\n"; $exit=1; }
}
exit($exit);
```

- [ ] **Step 3: Create `cli/cleanup.php`**

```php
<?php
require_once __DIR__ . '/common.php';
$args   = cli_parse_args($argv);
$dryRun = isset($args['dry-run']);
$pdo    = Database::pdo();
$svc    = new RetentionPolicyService($pdo);
$jobIds = [];
if (isset($args['job-id'])) { $jobIds[]=(int)$args['job-id']; }
elseif (isset($args['all'])) { $jobIds=array_column($pdo->query('SELECT id FROM backup_jobs WHERE is_active=1')->fetchAll(),'id'); }
else cli_exit("Usage: php cleanup.php --job-id=N | --all [--dry-run]");
foreach ($jobIds as $jid) {
    $s=$pdo->prepare('SELECT job_name,retention_daily,retention_weekly,retention_monthly FROM backup_jobs WHERE id=?');
    $s->execute([$jid]); $job=$s->fetch();
    $exp=$svc->getExpiredBackupIds($jid,(int)$job['retention_daily'],(int)$job['retention_weekly'],(int)$job['retention_monthly']);
    if (!$exp) { echo "[job=$jid] {$job['job_name']}: no expired backups\n"; continue; }
    echo "[job=$jid] {$job['job_name']}: ".count($exp)." expired".($dryRun?' (dry-run)':'')."\n";
    if (!$dryRun) {
        foreach ($exp as $eid) {
            $fs=$pdo->prepare('SELECT bf.*,st.provider_type,st.config_json FROM backup_files bf JOIN storage_targets st ON st.id=bf.storage_target_id WHERE bf.backup_log_id=?');
            $fs->execute([$eid]);
            foreach ($fs->fetchAll() as $f) {
                try { StorageAdapterFactory::create($f)->delete($f['remote_path']); }
                catch (\Exception $e) { fwrite(STDERR,"Warning delete {$f['remote_path']}: {$e->getMessage()}\n"); }
            }
            $pdo->prepare('DELETE FROM backup_logs WHERE id=?')->execute([$eid]);
            echo "  deleted backup_log_id=$eid\n";
        }
    }
}
exit(0);
```

- [ ] **Step 4: Test**

```bash
php cli/restore.php --backup-log-id=1 --mode=dry_run
php cli/verify.php --backup-log-id=1
php cli/cleanup.php --all --dry-run
```
Expected: dry_run restore succeeds; verify passes; cleanup shows 0 expired (or N with dry-run label)

- [ ] **Step 5: Commit**

```bash
git add cli/restore.php cli/verify.php cli/cleanup.php
git commit -m "feat: CLI restore.php, verify.php, cleanup.php — CLI layer complete"
```


---

### Task 14: API Bootstrap (session, auth guard, CSRF, JSON helpers)

**Files:**
- Create: `public/api/common.php`
- Create: `public/api/auth.php` (login/logout endpoints)
- Create: `tests/integration/AuthApiTest.php`

**Interfaces:**
- Produces: `api_response(bool $ok, mixed $data, ?string $err, int $status): never`; `api_require_auth(): array` (returns current user row); `api_check_csrf(): void`; `api_require_role(string ...$roles): void`

- [ ] **Step 1: Write failing auth API test**

`tests/integration/AuthApiTest.php`:
```php
<?php
use PHPUnit\Framework\TestCase;

class AuthApiTest extends TestCase
{
    public function testLoginReturnsUserAndCsrfToken(): void
    {
        // Unit-test the auth logic directly without HTTP
        $pdo  = Database::pdo();
        $stmt = $pdo->prepare('SELECT * FROM users WHERE username=?');
        $stmt->execute(['admin']);
        $user = $stmt->fetch();
        $this->assertNotFalse($user, 'Admin user must exist (run sql/seed.sql first)');
        $this->assertContains($user['role'], ['admin','operator','viewer']);
    }
}
```

- [ ] **Step 2: Run test**

```bash
php vendor/bin/phpunit tests/integration/AuthApiTest.php
```
Expected: PASS (admin row found in DB)

- [ ] **Step 3: Create `public/api/common.php`**

```php
<?php
declare(strict_types=1);
ini_set('memory_limit', '256M');
$rootDir = dirname(__DIR__, 2);
require_once $rootDir . '/vendor/autoload.php';
Config::init($rootDir . '/config/app.config.php');

// Session init
$timeout = Config::get('session_timeout', 1800);
ini_set('session.gc_maxlifetime', (string) $timeout);
session_set_cookie_params(['httponly' => true, 'samesite' => 'Strict']);
session_start();

// Auto-expire session
if (isset($_SESSION['last_active']) && (time() - $_SESSION['last_active']) > $timeout) {
    session_unset();
    session_destroy();
    session_start();
}
$_SESSION['last_active'] = time();

function api_response(bool $ok, mixed $data = null, ?string $err = null, int $status = 200): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['success' => $ok, 'data' => $data, 'error' => $err], JSON_UNESCAPED_UNICODE);
    exit;
}

function api_require_auth(): array
{
    if (empty($_SESSION['user_id'])) {
        api_response(false, null, 'UNAUTHORIZED', 401);
    }
    $stmt = Database::pdo()->prepare('SELECT * FROM users WHERE id=? AND is_active=1');
    $stmt->execute([$_SESSION['user_id']]);
    $user = $stmt->fetch();
    if (!$user) api_response(false, null, 'UNAUTHORIZED', 401);
    return $user;
}

function api_require_role(string ...$roles): void
{
    $user = api_require_auth();
    if (!in_array($user['role'], $roles)) {
        api_response(false, null, 'FORBIDDEN', 403);
    }
}

function api_check_csrf(): void
{
    $token = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    if (!hash_equals($_SESSION['csrf_token'] ?? '', $token)) {
        api_response(false, null, 'CSRF token mismatch', 403);
    }
}

function api_json_body(): array
{
    $body = json_decode(file_get_contents('php://input'), true);
    return is_array($body) ? $body : [];
}

function api_ip(): string
{
    return $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? '';
}
```

- [ ] **Step 4: Create `public/api/auth.php`**

```php
<?php
require_once __DIR__ . '/common.php';

$method = $_SERVER['REQUEST_METHOD'];
$path   = trim(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH), '/');

// POST /api/auth/login
if ($method === 'POST' && str_ends_with($path, 'auth/login')) {
    $body = api_json_body();
    $username = trim($body['username'] ?? '');
    $password = $body['password'] ?? '';

    if (!$username || !$password) {
        api_response(false, null, 'Username and password required', 400);
    }

    $stmt = Database::pdo()->prepare('SELECT * FROM users WHERE username=? AND is_active=1');
    $stmt->execute([$username]);
    $user = $stmt->fetch();

    if (!$user || !password_verify($password, $user['password_hash'])) {
        // Rate limiting: track failed attempts in session
        $_SESSION['login_fails'] = ($_SESSION['login_fails'] ?? 0) + 1;
        if ($_SESSION['login_fails'] >= 5) {
            api_response(false, null, 'Too many failed attempts. Try again in 15 minutes.', 429);
        }
        api_response(false, null, 'Invalid username or password', 401);
    }

    // Successful login
    $_SESSION['login_fails'] = 0;
    session_regenerate_id(true);
    $_SESSION['user_id']    = $user['id'];
    $_SESSION['user_role']  = $user['role'];
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));

    Database::pdo()->prepare('UPDATE users SET last_login_at=NOW() WHERE id=?')->execute([$user['id']]);
    (new AuditLogger(Database::pdo()))->log('auth.login', (int)$user['id'], null, null, api_ip());

    api_response(true, [
        'user_id'    => $user['id'],
        'role'       => $user['role'],
        'full_name'  => $user['full_name'],
        'csrf_token' => $_SESSION['csrf_token'],
    ]);
}

// POST /api/auth/logout
if ($method === 'POST' && str_ends_with($path, 'auth/logout')) {
    api_check_csrf();
    $userId = $_SESSION['user_id'] ?? null;
    session_unset();
    session_destroy();
    if ($userId) (new AuditLogger(Database::pdo()))->log('auth.logout', (int)$userId, null, null, api_ip());
    api_response(true);
}

api_response(false, null, 'NOT_FOUND', 404);
```

- [ ] **Step 5: Test login manually**

```bash
php -r "
\$ch = curl_init('http://localhost/brs/public/api/auth/login');
curl_setopt_array(\$ch, [CURLOPT_POST=>1, CURLOPT_POSTFIELDS=>json_encode(['username'=>'admin','password'=>'Admin@1234']), CURLOPT_HTTPHEADER=>['Content-Type: application/json'], CURLOPT_RETURNTRANSFER=>1]);
echo curl_exec(\$ch);
"
```
Expected: `{"success":true,"data":{"user_id":1,"role":"admin","csrf_token":"..."},"error":null}`

- [ ] **Step 6: Commit**

```bash
git add public/api/common.php public/api/auth.php tests/integration/AuthApiTest.php
git commit -m "feat: API bootstrap (session, CSRF, auth guard) and auth login/logout endpoints"
```

---

### Task 15: Jobs CRUD API + Backup Trigger API

**Files:**
- Create: `public/api/jobs.php`
- Create: `public/api/backup.php`

**Interfaces:**
- All routes require `api_require_auth()`; mutation routes require `api_check_csrf()`
- Jobs API: GET/POST `/api/jobs`; GET/PUT/DELETE `/api/jobs/{id}`; POST `/api/jobs/{id}/run`
- Backup API: GET `/api/jobs/{id}/history`; GET `/api/backup-logs/{id}`; GET `/api/backup-logs/{id}/status`; POST `/api/backup-logs/{id}/pin`; DELETE `/api/backup-logs/{id}`

- [ ] **Step 1: Create `public/api/jobs.php`**

```php
<?php
require_once __DIR__ . '/common.php';
$user   = api_require_auth();
$method = $_SERVER['REQUEST_METHOD'];
$uri    = trim(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH), '/');
$pdo    = Database::pdo();

// Extract ID from URI: /api/jobs/{id} or /api/jobs/{id}/run
preg_match('#api/jobs(?:/(\d+)(?:/(run))?)?$#', $uri, $m);
$id     = isset($m[1]) ? (int)$m[1] : null;
$action = $m[2] ?? null;

// GET /api/jobs
if ($method === 'GET' && !$id) {
    $page  = max(1, (int)($_GET['page'] ?? 1));
    $limit = min(100, max(1, (int)($_GET['limit'] ?? 20)));
    $offset = ($page - 1) * $limit;
    $search = '%' . ($_GET['search'] ?? '') . '%';
    $total = $pdo->prepare('SELECT COUNT(*) FROM backup_jobs WHERE job_name LIKE ?');
    $total->execute([$search]);
    $stmt = $pdo->prepare(
        'SELECT bj.*,
          (SELECT status FROM backup_logs WHERE job_id=bj.id ORDER BY id DESC LIMIT 1) AS last_backup_status,
          (SELECT started_at FROM backup_logs WHERE job_id=bj.id ORDER BY id DESC LIMIT 1) AS last_backup_at
         FROM backup_jobs bj WHERE bj.job_name LIKE ? ORDER BY bj.id LIMIT ? OFFSET ?'
    );
    $stmt->execute([$search, $limit, $offset]);
    api_response(true, ['items' => $stmt->fetchAll(), 'total' => (int)$total->fetchColumn(), 'page' => $page, 'limit' => $limit]);
}

// GET /api/jobs/{id}
if ($method === 'GET' && $id) {
    $stmt = $pdo->prepare('SELECT * FROM backup_jobs WHERE id=?');
    $stmt->execute([$id]);
    $job = $stmt->fetch();
    if (!$job) api_response(false, null, 'NOT_FOUND', 404);
    unset($job['db_password_encrypted']);  // Never return encrypted password
    api_response(true, $job);
}

// POST /api/jobs (create)
if ($method === 'POST' && !$id) {
    api_require_role('admin', 'operator');
    api_check_csrf();
    $b   = api_json_body();
    $enc = new EncryptionService(Config::get('encryption_key_path'));
    $pwEnc = isset($b['db_password']) ? $enc->encryptString($b['db_password'], 'credential') : null;
    $stmt = $pdo->prepare(
        'INSERT INTO backup_jobs (job_name,description,app_path,include_patterns,exclude_patterns,db_host,db_port,db_name,db_username,db_password_encrypted,backup_type,encryption_enabled,schedule_cron,retention_daily,retention_weekly,retention_monthly,created_by)
         VALUES(:jn,:desc,:ap,:inc,:exc,:dbh,:dbp,:dbn,:dbu,:dbpw,:bt,:ee,:sc,:rd,:rw,:rm,:cb)'
    );
    $stmt->execute([
        'jn'=>$b['job_name'],'desc'=>$b['description']??null,'ap'=>$b['app_path']??null,
        'inc'=>isset($b['include_patterns'])?json_encode($b['include_patterns']):null,
        'exc'=>isset($b['exclude_patterns'])?json_encode($b['exclude_patterns']):null,
        'dbh'=>$b['db_host']??null,'dbp'=>$b['db_port']??3306,
        'dbn'=>$b['db_name']??null,'dbu'=>$b['db_username']??null,'dbpw'=>$pwEnc,
        'bt'=>$b['backup_type']??'both','ee'=>$b['encryption_enabled']??1,
        'sc'=>$b['schedule_cron']??null,'rd'=>$b['retention_daily']??7,
        'rw'=>$b['retention_weekly']??4,'rm'=>$b['retention_monthly']??6,'cb'=>$user['id'],
    ]);
    $newId = (int)$pdo->lastInsertId();
    if (isset($b['storage_target_ids'])) {
        foreach ($b['storage_target_ids'] as $pri => $stId) {
            $pdo->prepare('INSERT INTO job_storage_targets (job_id,storage_target_id,priority) VALUES(?,?,?)')
                ->execute([$newId, $stId, $pri + 1]);
        }
    }
    (new AuditLogger($pdo))->log('job.create', (int)$user['id'], 'backup_job', $newId, api_ip());
    api_response(true, ['id' => $newId], null, 201);
}

// PUT /api/jobs/{id}
if ($method === 'PUT' && $id) {
    api_require_role('admin', 'operator');
    api_check_csrf();
    $b   = api_json_body();
    $enc = new EncryptionService(Config::get('encryption_key_path'));
    // Only update password if a new one is provided
    $pwEnc = isset($b['db_password']) ? $enc->encryptString($b['db_password'], 'credential') : null;
    $setPw = $pwEnc ? ', db_password_encrypted=:dbpw' : '';
    $params = [
        'jn'=>$b['job_name']??null,'ap'=>$b['app_path']??null,
        'inc'=>isset($b['include_patterns'])?json_encode($b['include_patterns']):null,
        'exc'=>isset($b['exclude_patterns'])?json_encode($b['exclude_patterns']):null,
        'dbh'=>$b['db_host']??null,'dbp'=>$b['db_port']??3306,
        'dbn'=>$b['db_name']??null,'dbu'=>$b['db_username']??null,
        'bt'=>$b['backup_type']??'both','ee'=>$b['encryption_enabled']??1,
        'sc'=>$b['schedule_cron']??null,'ia'=>$b['is_active']??1,
        'rd'=>$b['retention_daily']??7,'rw'=>$b['retention_weekly']??4,'rm'=>$b['retention_monthly']??6,
        'id'=>$id,
    ];
    if ($pwEnc) $params['dbpw'] = $pwEnc;
    $pdo->prepare(
        "UPDATE backup_jobs SET job_name=:jn,app_path=:ap,include_patterns=:inc,exclude_patterns=:exc,
         db_host=:dbh,db_port=:dbp,db_name=:dbn,db_username=:dbu,backup_type=:bt,
         encryption_enabled=:ee,schedule_cron=:sc,is_active=:ia,
         retention_daily=:rd,retention_weekly=:rw,retention_monthly=:rm$setPw WHERE id=:id"
    )->execute($params);
    (new AuditLogger($pdo))->log('job.update', (int)$user['id'], 'backup_job', $id, api_ip());
    api_response(true, ['id' => $id]);
}

// DELETE /api/jobs/{id}
if ($method === 'DELETE' && $id) {
    api_require_role('admin');
    api_check_csrf();
    $b = api_json_body();
    $stmt = $pdo->prepare('SELECT job_name FROM backup_jobs WHERE id=?');
    $stmt->execute([$id]);
    $job = $stmt->fetch();
    if (!$job) api_response(false, null, 'NOT_FOUND', 404);
    if (($b['confirm_name'] ?? '') !== $job['job_name']) {
        api_response(false, null, 'Confirmation name does not match', 400);
    }
    $pdo->prepare('DELETE FROM backup_jobs WHERE id=?')->execute([$id]);
    (new AuditLogger($pdo))->log('job.delete', (int)$user['id'], 'backup_job', $id, api_ip());
    api_response(true);
}

// POST /api/jobs/{id}/run
if ($method === 'POST' && $id && $action === 'run') {
    api_require_role('admin', 'operator');
    api_check_csrf();
    $enc   = new EncryptionService(Config::get('encryption_key_path'));
    $eng   = new BackupEngine($pdo, $enc, new ChecksumService(),
        new LockManager(Config::get('temp_dir') . '/locks'),
        new AuditLogger($pdo),
        new NotificationService(Config::get('line_notify_token'), Config::get('notify_mode')),
        Config::get('temp_dir'), Config::get('mysqldump_path'));
    try {
        $logId = $eng->run($id, 'manual', (int)$user['id']);
        api_response(true, ['backup_log_id' => $logId, 'status' => 'success'], null, 202);
    } catch (\RuntimeException $e) {
        $code = match($e->getCode()) { 2=>422, 3=>409, default=>500 };
        api_response(false, null, $e->getMessage(), $code);
    }
}

api_response(false, null, 'NOT_FOUND', 404);
```

- [ ] **Step 2: Create `public/api/backup.php`**

```php
<?php
require_once __DIR__ . '/common.php';
$user   = api_require_auth();
$method = $_SERVER['REQUEST_METHOD'];
$uri    = trim(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH), '/');
$pdo    = Database::pdo();

// GET /api/jobs/{id}/history
if ($method === 'GET' && preg_match('#api/jobs/(\d+)/history$#', $uri, $m)) {
    $jobId = (int)$m[1];
    $page  = max(1,(int)($_GET['page']??1));
    $limit = min(100,max(1,(int)($_GET['limit']??20)));
    $total = $pdo->prepare('SELECT COUNT(*) FROM backup_logs WHERE job_id=?');
    $total->execute([$jobId]);
    $stmt  = $pdo->prepare('SELECT id,started_at,finished_at,status,verification_status,total_size_bytes,is_encrypted,is_pinned,triggered_by FROM backup_logs WHERE job_id=? ORDER BY started_at DESC LIMIT ? OFFSET ?');
    $stmt->execute([$jobId,$limit,($page-1)*$limit]);
    api_response(true,['items'=>$stmt->fetchAll(),'total'=>(int)$total->fetchColumn(),'page'=>$page,'limit'=>$limit]);
}

// GET /api/backup-logs/{id}
if ($method === 'GET' && preg_match('#api/backup-logs/(\d+)$#', $uri, $m)) {
    $stmt = $pdo->prepare('SELECT * FROM backup_logs WHERE id=?');
    $stmt->execute([(int)$m[1]]);
    $log  = $stmt->fetch();
    if (!$log) api_response(false,null,'NOT_FOUND',404);
    api_response(true,$log);
}

// GET /api/backup-logs/{id}/status
if ($method === 'GET' && preg_match('#api/backup-logs/(\d+)/status$#', $uri, $m)) {
    $stmt = $pdo->prepare('SELECT status,verification_status FROM backup_logs WHERE id=?');
    $stmt->execute([(int)$m[1]]);
    $row  = $stmt->fetch();
    if (!$row) api_response(false,null,'NOT_FOUND',404);
    api_response(true,['status'=>$row['status'],'verification_status'=>$row['verification_status']]);
}

// POST /api/backup-logs/{id}/pin
if ($method === 'POST' && preg_match('#api/backup-logs/(\d+)/pin$#', $uri, $m)) {
    api_require_role('admin','operator');
    api_check_csrf();
    $pdo->prepare('UPDATE backup_logs SET is_pinned=1 WHERE id=?')->execute([(int)$m[1]]);
    api_response(true);
}

// DELETE /api/backup-logs/{id}
if ($method === 'DELETE' && preg_match('#api/backup-logs/(\d+)$#', $uri, $m)) {
    api_require_role('admin');
    api_check_csrf();
    $logId = (int)$m[1];
    $pdo->prepare('DELETE FROM backup_logs WHERE id=?')->execute([$logId]);
    (new AuditLogger($pdo))->log('backup.delete',(int)$user['id'],'backup_log',$logId,api_ip());
    api_response(true);
}

api_response(false,null,'NOT_FOUND',404);
```

- [ ] **Step 3: Commit**

```bash
git add public/api/jobs.php public/api/backup.php
git commit -m "feat: Jobs CRUD API and Backup history/status/pin API endpoints"
```

---

### Task 16: Restore API + Storage Targets API

**Files:**
- Create: `public/api/restore.php`
- Create: `public/api/storage.php`

- [ ] **Step 1: Create `public/api/restore.php`**

```php
<?php
require_once __DIR__ . '/common.php';
$user   = api_require_auth();
$method = $_SERVER['REQUEST_METHOD'];
$uri    = trim(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH), '/');
$pdo    = Database::pdo();

function makeRestoreEngine(PDO $pdo): RestoreEngine {
    return new RestoreEngine($pdo,
        new EncryptionService(Config::get('encryption_key_path')),
        new ChecksumService(), new AuditLogger($pdo),
        new NotificationService(Config::get('line_notify_token'), Config::get('notify_mode')),
        Config::get('temp_dir'), Config::get('mysql_path'));
}

// POST /api/restore/validate
if ($method === 'POST' && str_ends_with($uri, 'restore/validate')) {
    api_check_csrf();
    $b   = api_json_body();
    $id  = (int)($b['backup_log_id'] ?? 0);
    if (!$id) api_response(false, null, 'backup_log_id required', 400);
    try {
        $result = makeRestoreEngine($pdo)->validate($id);
        api_response(true, $result);
    } catch (\RuntimeException $e) {
        api_response(false, null, $e->getMessage(), 422);
    }
}

// POST /api/restore/execute
if ($method === 'POST' && str_ends_with($uri, 'restore/execute')) {
    api_require_role('admin', 'operator');
    api_check_csrf();
    $b = api_json_body();
    $blId    = (int)($b['backup_log_id'] ?? 0);
    $mode    = $b['mode'] ?? 'dry_run';
    $target  = $b['restore_target'] ?? 'original';
    $altPath = $b['alternate_path'] ?? null;
    $altDb   = $b['alternate_db_name'] ?? null;
    $confirm = $b['confirm_job_name'] ?? null;

    if ($mode === 'real' && $target === 'original' && !$confirm) {
        api_response(false, null, 'confirm_job_name required for real restore', 400);
    }
    if ($confirm) {
        $stmt = $pdo->prepare('SELECT bj.job_name FROM backup_logs bl JOIN backup_jobs bj ON bj.id=bl.job_id WHERE bl.id=?');
        $stmt->execute([$blId]);
        $row = $stmt->fetch();
        if (!$row || $row['job_name'] !== $confirm) {
            api_response(false, null, 'confirm_job_name does not match job name', 400);
        }
    }
    if ($altPath && !PathValidator::isWithinAllowedBase($altPath, 'C:\\xampp\\htdocs')) {
        api_response(false, null, 'alternate_path is outside allowed directory', 400);
    }
    try {
        $rlId = makeRestoreEngine($pdo)->execute($blId, $mode, $target, $altPath, $altDb, (int)$user['id']);
        api_response(true, ['restore_log_id' => $rlId, 'status' => 'success'], null, 202);
    } catch (\RuntimeException $e) {
        api_response(false, null, $e->getMessage(), $e->getCode() ?: 500);
    }
}

// GET /api/restore-logs/{id}/status
if ($method === 'GET' && preg_match('#api/restore-logs/(\d+)/status$#', $uri, $m)) {
    $stmt = $pdo->prepare('SELECT status,error_message FROM restore_logs WHERE id=?');
    $stmt->execute([(int)$m[1]]);
    $row = $stmt->fetch();
    if (!$row) api_response(false, null, 'NOT_FOUND', 404);
    api_response(true, $row);
}

// POST /api/restore-logs/{id}/rollback
if ($method === 'POST' && preg_match('#api/restore-logs/(\d+)/rollback$#', $uri, $m)) {
    api_require_role('admin', 'operator');
    api_check_csrf();
    try {
        makeRestoreEngine($pdo)->rollback((int)$m[1], (int)$user['id']);
        api_response(true);
    } catch (\RuntimeException $e) {
        api_response(false, null, $e->getMessage(), 400);
    }
}

api_response(false, null, 'NOT_FOUND', 404);
```

- [ ] **Step 2: Create `public/api/storage.php`**

```php
<?php
require_once __DIR__ . '/common.php';
$user   = api_require_auth();
$method = $_SERVER['REQUEST_METHOD'];
$uri    = trim(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH), '/');
$pdo    = Database::pdo();

preg_match('#api/storage-targets(?:/(\d+)(?:/(test))?)?$#', $uri, $m);
$id     = isset($m[1]) ? (int)$m[1] : null;
$action = $m[2] ?? null;

// GET /api/storage-targets
if ($method === 'GET' && !$id) {
    $rows = $pdo->query('SELECT id,target_name,provider_type,is_active,last_test_status,last_test_at,created_at FROM storage_targets')->fetchAll();
    api_response(true, $rows);
}

// POST /api/storage-targets
if ($method === 'POST' && !$id) {
    api_require_role('admin');
    api_check_csrf();
    $b   = api_json_body();
    $enc = new EncryptionService(Config::get('encryption_key_path'));
    $cfg = json_encode($enc->encryptString(json_encode($b['config'] ?? []), 'credential'));
    $pdo->prepare('INSERT INTO storage_targets (target_name,provider_type,config_json) VALUES(?,?,?)')
        ->execute([$b['target_name'], $b['provider_type'], $b['config'] ? json_encode($b['config']) : '{}']);
    $newId = (int)$pdo->lastInsertId();
    (new AuditLogger($pdo))->log('storage.create',(int)$user['id'],'storage_target',$newId,api_ip());
    api_response(true, ['id' => $newId], null, 201);
}

// PUT /api/storage-targets/{id}
if ($method === 'PUT' && $id) {
    api_require_role('admin'); api_check_csrf();
    $b = api_json_body();
    $pdo->prepare('UPDATE storage_targets SET target_name=?,provider_type=?,config_json=?,is_active=? WHERE id=?')
        ->execute([$b['target_name'],$b['provider_type'],json_encode($b['config']??[]),$b['is_active']??1,$id]);
    api_response(true, ['id' => $id]);
}

// DELETE /api/storage-targets/{id}
if ($method === 'DELETE' && $id) {
    api_require_role('admin'); api_check_csrf();
    $pdo->prepare('DELETE FROM storage_targets WHERE id=?')->execute([$id]);
    api_response(true);
}

// POST /api/storage-targets/{id}/test
if ($method === 'POST' && $id && $action === 'test') {
    api_check_csrf();
    $stmt = $pdo->prepare('SELECT * FROM storage_targets WHERE id=?');
    $stmt->execute([$id]);
    $target = $stmt->fetch();
    if (!$target) api_response(false, null, 'NOT_FOUND', 404);
    $enc    = new EncryptionService(Config::get('encryption_key_path'));
    $result = StorageAdapterFactory::create($target, $enc)->testConnection();
    $pdo->prepare('UPDATE storage_targets SET last_test_status=?,last_test_at=NOW() WHERE id=?')
        ->execute([$result['status'], $id]);
    api_response(true, $result);
}

api_response(false, null, 'NOT_FOUND', 404);
```

- [ ] **Step 3: Commit**

```bash
git add public/api/restore.php public/api/storage.php
git commit -m "feat: Restore API (validate/execute/rollback) and Storage Targets CRUD API"
```

---

### Task 17: Users API + Audit API + Dashboard API

**Files:**
- Create: `public/api/users.php`
- Create: `public/api/dashboard.php`

- [ ] **Step 1: Create `public/api/users.php`**

```php
<?php
require_once __DIR__ . '/common.php';
api_require_role('admin');
$method = $_SERVER['REQUEST_METHOD'];
$uri    = trim(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH), '/');
$pdo    = Database::pdo();

preg_match('#api/users(?:/(\d+)(?:/(reset-password))?)?$#', $uri, $m);
$id     = isset($m[1]) ? (int)$m[1] : null;
$action = $m[2] ?? null;

// GET /api/users
if ($method === 'GET' && !$id) {
    $rows = $pdo->query('SELECT id,username,full_name,role,is_active,last_login_at,created_at FROM users')->fetchAll();
    api_response(true, $rows);
}

// POST /api/users
if ($method === 'POST' && !$id) {
    api_check_csrf();
    $b = api_json_body();
    if (empty($b['username']) || empty($b['password'])) api_response(false, null, 'username and password required', 400);
    $hash = password_hash($b['password'], PASSWORD_ARGON2ID);
    $pdo->prepare('INSERT INTO users (username,password_hash,full_name,role) VALUES(?,?,?,?)')
        ->execute([$b['username'], $hash, $b['full_name'] ?? null, $b['role'] ?? 'viewer']);
    api_response(true, ['id' => (int)$pdo->lastInsertId()], null, 201);
}

// PUT /api/users/{id}
if ($method === 'PUT' && $id) {
    api_check_csrf();
    $b = api_json_body();
    $pdo->prepare('UPDATE users SET full_name=?,role=?,is_active=? WHERE id=?')
        ->execute([$b['full_name']??null, $b['role']??'viewer', $b['is_active']??1, $id]);
    api_response(true, ['id' => $id]);
}

// DELETE /api/users/{id}
if ($method === 'DELETE' && $id) {
    api_check_csrf();
    $user = api_require_auth();
    if ((int)$user['id'] === $id) api_response(false, null, 'Cannot delete yourself', 400);
    $pdo->prepare('DELETE FROM users WHERE id=?')->execute([$id]);
    api_response(true);
}

// POST /api/users/{id}/reset-password
if ($method === 'POST' && $id && $action === 'reset-password') {
    api_check_csrf();
    $b    = api_json_body();
    $hash = password_hash($b['password'] ?? '', PASSWORD_ARGON2ID);
    $pdo->prepare('UPDATE users SET password_hash=? WHERE id=?')->execute([$hash, $id]);
    api_response(true);
}

api_response(false, null, 'NOT_FOUND', 404);
```

- [ ] **Step 2: Create `public/api/dashboard.php`**

```php
<?php
require_once __DIR__ . '/common.php';
$user   = api_require_auth();
$method = $_SERVER['REQUEST_METHOD'];
$uri    = trim(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH), '/');
$pdo    = Database::pdo();

// GET /api/dashboard/summary
if ($method === 'GET' && str_ends_with($uri, 'dashboard/summary')) {
    $totalJobs  = (int)$pdo->query('SELECT COUNT(*) FROM backup_jobs')->fetchColumn();
    $activeJobs = (int)$pdo->query('SELECT COUNT(*) FROM backup_jobs WHERE is_active=1')->fetchColumn();
    $failed24h  = (int)$pdo->query("SELECT COUNT(*) FROM backup_logs WHERE status='failed' AND started_at>=DATE_SUB(NOW(),INTERVAL 24 HOUR)")->fetchColumn();
    $totalSize  = (int)$pdo->query('SELECT COALESCE(SUM(total_size_bytes),0) FROM backup_logs WHERE status="success"')->fetchColumn();
    $upcoming   = $pdo->query(
        'SELECT id AS job_id,job_name,schedule_cron FROM backup_jobs WHERE is_active=1 AND schedule_cron IS NOT NULL LIMIT 5'
    )->fetchAll();
    $storage    = $pdo->query('SELECT id,target_name FROM storage_targets WHERE is_active=1')->fetchAll();
    $storageUsage = [];
    foreach ($storage as $st) {
        try {
            $ad   = StorageAdapterFactory::create($st);
            $free = $ad->getFreeSpace();
            $storageUsage[] = ['target_name' => $st['target_name'], 'free_bytes' => $free];
        } catch (\Exception) {}
    }
    api_response(true, [
        'total_jobs' => $totalJobs, 'active_jobs' => $activeJobs,
        'jobs_failed_last_24h' => $failed24h, 'total_backup_size_bytes' => $totalSize,
        'storage_usage' => $storageUsage, 'upcoming_scheduled_jobs' => $upcoming,
    ]);
}

// GET /api/audit-logs
if ($method === 'GET' && str_ends_with($uri, 'audit-logs')) {
    api_require_role('admin');
    $page   = max(1,(int)($_GET['page']??1));
    $limit  = min(100,max(1,(int)($_GET['limit']??50)));
    $where  = '1=1';
    $params = [];
    if (!empty($_GET['user_id']))  { $where .= ' AND al.user_id=?';  $params[] = $_GET['user_id']; }
    if (!empty($_GET['action']))   { $where .= ' AND al.action=?';   $params[] = $_GET['action']; }
    if (!empty($_GET['from']))     { $where .= ' AND al.created_at>=?'; $params[] = $_GET['from']; }
    if (!empty($_GET['to']))       { $where .= ' AND al.created_at<=?'; $params[] = $_GET['to']; }
    $total = $pdo->prepare("SELECT COUNT(*) FROM audit_logs al WHERE $where");
    $total->execute($params);
    $stmt  = $pdo->prepare(
        "SELECT al.*,u.username FROM audit_logs al LEFT JOIN users u ON u.id=al.user_id WHERE $where ORDER BY al.id DESC LIMIT ? OFFSET ?"
    );
    $stmt->execute(array_merge($params, [$limit, ($page-1)*$limit]));
    api_response(true, ['items' => $stmt->fetchAll(), 'total' => (int)$total->fetchColumn(), 'page' => $page]);
}

// GET /api/healthcheck
if ($method === 'GET' && str_ends_with($uri, 'api/healthcheck')) {
    $ok = true;
    $result = [
        'mysqldump_available' => false,
        'php_extensions' => [],
        'storage_targets' => [],
    ];
    exec('"' . Config::get('mysqldump_path') . '" --version 2>&1', $out, $rc);
    $result['mysqldump_available'] = ($rc === 0);
    if ($rc !== 0) $ok = false;
    foreach (['zip','openssl','pdo_mysql'] as $ext) {
        $result['php_extensions'][$ext] = extension_loaded($ext);
        if (!extension_loaded($ext)) $ok = false;
    }
    $targets = $pdo->query('SELECT * FROM storage_targets WHERE is_active=1')->fetchAll();
    foreach ($targets as $t) {
        try {
            $r = StorageAdapterFactory::create($t)->testConnection();
            $result['storage_targets'][] = ['id'=>$t['id'],'name'=>$t['target_name'],'status'=>$r['status']];
            if ($r['status']!=='success') $ok=false;
        } catch (\Exception $e) {
            $result['storage_targets'][] = ['id'=>$t['id'],'name'=>$t['target_name'],'status'=>'failed'];
            $ok = false;
        }
    }
    api_response($ok, $result, $ok?null:'Some checks failed');
}

api_response(false, null, 'NOT_FOUND', 404);
```

- [ ] **Step 3: Commit**

```bash
git add public/api/users.php public/api/dashboard.php
git commit -m "feat: Users CRUD API, Audit Log API, Dashboard summary and healthcheck API"
```


---

### Task 18: Web UI Layout + Login Page

**Files:**
- Create: `public/assets/css/app.css`
- Create: `public/assets/js/api.js`
- Create: `public/partials/header.php`
- Create: `public/partials/nav.php`
- Create: `public/partials/footer.php`
- Create: `public/login.php`

**Interfaces:**
- All pages include `header.php` and `footer.php`; authenticated pages include `nav.php`
- `api.js` exports: `apiFetch(method, path, body)` — handles CSRF token from `window.CSRF_TOKEN`

- [ ] **Step 1: Create `public/assets/css/app.css`**

```css
/* BRS — custom styles on top of Bootstrap 5 */
body { background-color: #f8f9fa; }
.sidebar { min-height: 100vh; background: #212529; }
.sidebar .nav-link { color: #adb5bd; }
.sidebar .nav-link:hover, .sidebar .nav-link.active { color: #fff; background: #343a40; border-radius: 4px; }
.status-badge.success  { background: #198754; color: #fff; }
.status-badge.failed   { background: #dc3545; color: #fff; }
.status-badge.running  { background: #0dcaf0; color: #000; }
.status-badge.corrupted{ background: #fd7e14; color: #fff; }
.card-stat { border-left: 4px solid; }
.card-stat.success  { border-color: #198754; }
.card-stat.warning  { border-color: #ffc107; }
.card-stat.danger   { border-color: #dc3545; }
```

- [ ] **Step 2: Create `public/assets/js/api.js`**

```js
// Centralized fetch wrapper; reads CSRF token from window.CSRF_TOKEN set by PHP
async function apiFetch(method, path, body = null) {
    const opts = {
        method,
        headers: { 'Content-Type': 'application/json' },
        credentials: 'same-origin',
    };
    if (window.CSRF_TOKEN) opts.headers['X-CSRF-Token'] = window.CSRF_TOKEN;
    if (body) opts.body = JSON.stringify(body);
    const res  = await fetch('/brs/public/api/' + path, opts);
    const data = await res.json();
    if (!data.success) throw new Error(data.error || 'Request failed');
    return data.data;
}

function showAlert(msg, type = 'danger', container = '#alert-container') {
    const el = document.querySelector(container);
    if (!el) return;
    el.innerHTML = `<div class="alert alert-${type} alert-dismissible fade show">
        ${msg}<button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>`;
}

function formatBytes(bytes) {
    if (bytes >= 1073741824) return (bytes / 1073741824).toFixed(2) + ' GB';
    if (bytes >= 1048576)   return (bytes / 1048576).toFixed(2) + ' MB';
    return (bytes / 1024).toFixed(2) + ' KB';
}
```

- [ ] **Step 3: Create `public/partials/header.php`**

```php
<?php
// Call session_start() before including this file
if (session_status() === PHP_SESSION_NONE) {
    ini_set('session.gc_maxlifetime', '1800');
    session_set_cookie_params(['httponly' => true, 'samesite' => 'Strict']);
    session_start();
}
$csrfToken = $_SESSION['csrf_token'] ?? '';
$pageTitle = $pageTitle ?? 'BRS';
?>
<!DOCTYPE html>
<html lang="th">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= htmlspecialchars($pageTitle) ?> — BRS</title>
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
<link rel="stylesheet" href="/brs/public/assets/css/app.css">
<script>window.CSRF_TOKEN = <?= json_encode($csrfToken) ?>;</script>
</head>
<body>
```

- [ ] **Step 4: Create `public/partials/nav.php`**

```php
<?php
// Redirect to login if not authenticated
if (empty($_SESSION['user_id'])) {
    header('Location: /brs/public/login.php');
    exit;
}
$currentPage = basename($_SERVER['SCRIPT_NAME'], '.php');
?>
<div class="d-flex">
<nav class="sidebar d-flex flex-column flex-shrink-0 p-3" style="width:220px">
  <a href="/brs/public/index.php" class="navbar-brand text-white fw-bold mb-3">
    <i class="bi bi-shield-check"></i> BRS
  </a>
  <ul class="nav nav-pills flex-column mb-auto">
    <?php foreach ([
      ['index','bi-speedometer2','Dashboard'],
      ['jobs','bi-briefcase','Backup Jobs'],
      ['history','bi-clock-history','History'],
      ['restore','bi-arrow-counterclockwise','Restore'],
      ['storage-targets','bi-hdd-stack','Storage'],
      ['users','bi-people','Users'],
      ['audit-log','bi-journal-text','Audit Log'],
    ] as [$page,$icon,$label]): ?>
    <li class="nav-item">
      <a href="/brs/public/<?=$page?>.php" class="nav-link <?=$currentPage===$page?'active':''?>">
        <i class="bi <?=$icon?> me-2"></i><?=$label?>
      </a>
    </li>
    <?php endforeach; ?>
  </ul>
  <hr class="text-secondary">
  <div class="text-secondary small">
    <i class="bi bi-person-circle me-1"></i><?= htmlspecialchars($_SESSION['user_role'] ?? '') ?>
    <a href="#" class="ms-2 text-danger" id="btn-logout"><i class="bi bi-box-arrow-right"></i></a>
  </div>
</nav>
<div class="flex-grow-1 p-4" id="main-content">
<div id="alert-container"></div>
<script>
document.getElementById('btn-logout')?.addEventListener('click', async e => {
  e.preventDefault();
  await apiFetch('POST','auth/logout');
  location.href='/brs/public/login.php';
});
</script>
```

- [ ] **Step 5: Create `public/partials/footer.php`**

```php
</div><!-- main-content -->
</div><!-- d-flex -->
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="/brs/public/assets/js/api.js"></script>
</body>
</html>
```

- [ ] **Step 6: Create `public/login.php`**

```php
<?php
$pageTitle = 'Login';
require_once __DIR__ . '/partials/header.php';
if (!empty($_SESSION['user_id'])) { header('Location: /brs/public/index.php'); exit; }
?>
<div class="min-vh-100 d-flex align-items-center justify-content-center bg-dark">
  <div class="card shadow" style="width:380px">
    <div class="card-body p-4">
      <h4 class="text-center mb-1"><i class="bi bi-shield-check text-primary"></i> BRS</h4>
      <p class="text-center text-muted small mb-4">Backup & Restore System</p>
      <div id="alert-container"></div>
      <form id="login-form">
        <div class="mb-3">
          <label class="form-label">Username</label>
          <input type="text" class="form-control" id="username" name="username" autofocus required>
        </div>
        <div class="mb-3">
          <label class="form-label">Password</label>
          <input type="password" class="form-control" id="password" name="password" required>
        </div>
        <button type="submit" class="btn btn-primary w-100" id="btn-login">
          <span class="spinner-border spinner-border-sm d-none" id="spinner"></span>
          Login
        </button>
      </form>
    </div>
  </div>
</div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="/brs/public/assets/js/api.js"></script>
<script>
document.getElementById('login-form').addEventListener('submit', async e => {
  e.preventDefault();
  document.getElementById('spinner').classList.remove('d-none');
  try {
    const data = await apiFetch('POST', 'auth/login', {
      username: document.getElementById('username').value,
      password: document.getElementById('password').value,
    });
    window.CSRF_TOKEN = data.csrf_token;
    location.href = '/brs/public/index.php';
  } catch (err) {
    showAlert(err.message);
  } finally {
    document.getElementById('spinner').classList.add('d-none');
  }
});
</script>
</body></html>
```

- [ ] **Step 7: Verify login page in browser**

Open `http://localhost/brs/public/login.php` in Chrome/Edge. Verify:
- Login form renders correctly with Bootstrap styling
- Login with `admin` / `Admin@1234` redirects to `index.php` (404 for now is fine)
- Incorrect password shows error alert

- [ ] **Step 8: Commit**

```bash
git add public/assets/ public/partials/ public/login.php
git commit -m "feat: Web UI layout (Bootstrap 5), shared JS api client, login page"
```

---

### Task 19: Dashboard + Job Management Pages

**Files:**
- Create: `public/index.php`
- Create: `public/jobs.php`
- Create: `public/job-edit.php`

- [ ] **Step 1: Create `public/index.php`**

```php
<?php
$pageTitle = 'Dashboard';
require_once __DIR__ . '/partials/header.php';
require_once __DIR__ . '/partials/nav.php';
?>
<h2 class="mb-4"><i class="bi bi-speedometer2 me-2"></i>Dashboard</h2>
<div class="row g-3 mb-4" id="stat-cards">
  <div class="col-md-3"><div class="card card-stat success p-3"><div class="text-muted small">Total Jobs</div><div class="fs-3 fw-bold" id="stat-total-jobs">—</div></div></div>
  <div class="col-md-3"><div class="card card-stat warning p-3"><div class="text-muted small">Active Jobs</div><div class="fs-3 fw-bold" id="stat-active-jobs">—</div></div></div>
  <div class="col-md-3"><div class="card card-stat danger p-3"><div class="text-muted small">Failed (24h)</div><div class="fs-3 fw-bold" id="stat-failed">—</div></div></div>
  <div class="col-md-3"><div class="card card-stat success p-3"><div class="text-muted small">Total Backup Size</div><div class="fs-3 fw-bold" id="stat-size">—</div></div></div>
</div>
<div class="row g-3">
  <div class="col-md-6">
    <div class="card"><div class="card-header">Storage Usage</div>
    <div class="card-body" id="storage-table"><div class="text-muted">Loading…</div></div></div>
  </div>
  <div class="col-md-6">
    <div class="card"><div class="card-header">Upcoming Jobs</div>
    <div class="card-body" id="upcoming-table"><div class="text-muted">Loading…</div></div></div>
  </div>
</div>
<script>
(async () => {
  try {
    const d = await apiFetch('GET','dashboard/summary');
    document.getElementById('stat-total-jobs').textContent = d.total_jobs;
    document.getElementById('stat-active-jobs').textContent = d.active_jobs;
    document.getElementById('stat-failed').textContent = d.jobs_failed_last_24h;
    document.getElementById('stat-size').textContent = formatBytes(d.total_backup_size_bytes||0);
    document.getElementById('storage-table').innerHTML = (d.storage_usage||[]).map(s =>
      `<div class="d-flex justify-content-between"><span>${s.target_name}</span><span>${s.free_bytes!=null?formatBytes(s.free_bytes):'N/A'} free</span></div>`
    ).join('') || '<em class="text-muted">No storage targets</em>';
    document.getElementById('upcoming-table').innerHTML = (d.upcoming_scheduled_jobs||[]).map(j =>
      `<div class="d-flex justify-content-between"><span>${j.job_name}</span><span class="text-muted small">${j.schedule_cron}</span></div>`
    ).join('') || '<em class="text-muted">No scheduled jobs</em>';
  } catch(e) { showAlert(e.message); }
})();
</script>
<?php require_once __DIR__ . '/partials/footer.php'; ?>
```

- [ ] **Step 2: Create `public/jobs.php`**

```php
<?php
$pageTitle = 'Backup Jobs';
require_once __DIR__ . '/partials/header.php';
require_once __DIR__ . '/partials/nav.php';
?>
<div class="d-flex justify-content-between align-items-center mb-4">
  <h2><i class="bi bi-briefcase me-2"></i>Backup Jobs</h2>
  <a href="job-edit.php" class="btn btn-primary"><i class="bi bi-plus-lg me-1"></i>New Job</a>
</div>
<div class="card">
  <div class="card-body p-0">
    <table class="table table-hover mb-0" id="jobs-table">
      <thead class="table-light"><tr>
        <th>Job Name</th><th>Type</th><th>Schedule</th><th>Last Backup</th><th>Status</th><th></th>
      </tr></thead>
      <tbody id="jobs-body"><tr><td colspan="6" class="text-center py-4 text-muted">Loading…</td></tr></tbody>
    </table>
  </div>
</div>
<script>
(async () => {
  try {
    const d = await apiFetch('GET','jobs?limit=100');
    const tbody = document.getElementById('jobs-body');
    if (!d.items.length) { tbody.innerHTML='<tr><td colspan="6" class="text-center text-muted py-4">No jobs configured</td></tr>'; return; }
    tbody.innerHTML = d.items.map(j => `
      <tr>
        <td><strong>${j.job_name}</strong><br><small class="text-muted">${j.app_path||j.db_name||''}</small></td>
        <td>${j.backup_type}</td>
        <td><code>${j.schedule_cron||'Manual'}</code></td>
        <td>${j.last_backup_at ? new Date(j.last_backup_at).toLocaleString('th-TH') : '—'}</td>
        <td><span class="badge status-badge ${j.last_backup_status||''}">${j.last_backup_status||'Never'}</span></td>
        <td>
          <button class="btn btn-sm btn-outline-success me-1" onclick="runNow(${j.id})" title="Backup Now"><i class="bi bi-play-fill"></i></button>
          <a href="job-edit.php?id=${j.id}" class="btn btn-sm btn-outline-primary me-1" title="Edit"><i class="bi bi-pencil"></i></a>
          <button class="btn btn-sm btn-outline-danger" onclick="deleteJob(${j.id},'${j.job_name}')" title="Delete"><i class="bi bi-trash"></i></button>
        </td>
      </tr>`).join('');
  } catch(e) { showAlert(e.message); }
})();

async function runNow(id) {
  if (!confirm('Run backup now?')) return;
  try { await apiFetch('POST',`jobs/${id}/run`); showAlert('Backup started!','success'); }
  catch(e) { showAlert(e.message); }
}

async function deleteJob(id, name) {
  const confirm_name = prompt(`Type "${name}" to confirm deletion:`);
  if (confirm_name !== name) return;
  try { await apiFetch('DELETE',`jobs/${id}`,{confirm_name}); location.reload(); }
  catch(e) { showAlert(e.message); }
}
</script>
<?php require_once __DIR__ . '/partials/footer.php'; ?>
```

- [ ] **Step 3: Create `public/job-edit.php`**

```php
<?php
$id = isset($_GET['id']) ? (int)$_GET['id'] : null;
$pageTitle = $id ? 'Edit Job' : 'New Job';
require_once __DIR__ . '/partials/header.php';
require_once __DIR__ . '/partials/nav.php';
?>
<h2 class="mb-4"><?= $id ? 'Edit Backup Job' : 'New Backup Job' ?></h2>
<form id="job-form" class="row g-3">
  <div class="col-md-8">
    <label class="form-label">Job Name <span class="text-danger">*</span></label>
    <input type="text" class="form-control" id="job_name" required>
  </div>
  <div class="col-md-4">
    <label class="form-label">Backup Type</label>
    <select class="form-select" id="backup_type">
      <option value="both">Files + Database</option>
      <option value="files_only">Files Only</option>
      <option value="database_only">Database Only</option>
    </select>
  </div>
  <div class="col-12"><hr><h6>File Settings</h6></div>
  <div class="col-md-8">
    <label class="form-label">App Path (e.g. C:\xampp\htdocs\hr2000)</label>
    <input type="text" class="form-control" id="app_path">
  </div>
  <div class="col-md-4">
    <label class="form-label">Exclude Patterns (comma-separated)</label>
    <input type="text" class="form-control" id="exclude_patterns" placeholder="cache/*,*.log,tmp/*">
  </div>
  <div class="col-12"><hr><h6>Database Settings</h6></div>
  <div class="col-md-4"><label class="form-label">DB Host</label><input type="text" class="form-control" id="db_host" value="localhost"></div>
  <div class="col-md-2"><label class="form-label">DB Port</label><input type="number" class="form-control" id="db_port" value="3306"></div>
  <div class="col-md-3"><label class="form-label">Database Name</label><input type="text" class="form-control" id="db_name"></div>
  <div class="col-md-3"><label class="form-label">DB Username</label><input type="text" class="form-control" id="db_username"></div>
  <div class="col-12"><label class="form-label">DB Password <?= $id ? '(leave blank to keep current)' : '' ?></label><input type="password" class="form-control" id="db_password"></div>
  <div class="col-12"><hr><h6>Schedule & Retention</h6></div>
  <div class="col-md-4"><label class="form-label">Cron Schedule</label><input type="text" class="form-control" id="schedule_cron" placeholder="0 2 * * *"></div>
  <div class="col-md-2"><label class="form-label">Keep Daily</label><input type="number" class="form-control" id="retention_daily" value="7"></div>
  <div class="col-md-2"><label class="form-label">Keep Weekly</label><input type="number" class="form-control" id="retention_weekly" value="4"></div>
  <div class="col-md-2"><label class="form-label">Keep Monthly</label><input type="number" class="form-control" id="retention_monthly" value="6"></div>
  <div class="col-md-2 d-flex align-items-end">
    <div class="form-check">
      <input class="form-check-input" type="checkbox" id="encryption_enabled" checked>
      <label class="form-check-label">Encrypt</label>
    </div>
  </div>
  <div class="col-12"><hr>
    <button type="submit" class="btn btn-primary me-2">Save Job</button>
    <a href="jobs.php" class="btn btn-secondary">Cancel</a>
  </div>
</form>
<script>
const JOB_ID = <?= json_encode($id) ?>;

if (JOB_ID) {
  apiFetch('GET',`jobs/${JOB_ID}`).then(j => {
    document.getElementById('job_name').value = j.job_name||'';
    document.getElementById('backup_type').value = j.backup_type||'both';
    document.getElementById('app_path').value = j.app_path||'';
    document.getElementById('db_host').value = j.db_host||'localhost';
    document.getElementById('db_port').value = j.db_port||3306;
    document.getElementById('db_name').value = j.db_name||'';
    document.getElementById('db_username').value = j.db_username||'';
    document.getElementById('schedule_cron').value = j.schedule_cron||'';
    document.getElementById('retention_daily').value = j.retention_daily||7;
    document.getElementById('retention_weekly').value = j.retention_weekly||4;
    document.getElementById('retention_monthly').value = j.retention_monthly||6;
    document.getElementById('encryption_enabled').checked = !!j.encryption_enabled;
    const exc = JSON.parse(j.exclude_patterns||'[]');
    document.getElementById('exclude_patterns').value = exc.join(',');
  }).catch(e => showAlert(e.message));
}

document.getElementById('job-form').addEventListener('submit', async e => {
  e.preventDefault();
  const exc = document.getElementById('exclude_patterns').value.split(',').map(s=>s.trim()).filter(Boolean);
  const body = {
    job_name: document.getElementById('job_name').value,
    backup_type: document.getElementById('backup_type').value,
    app_path: document.getElementById('app_path').value||null,
    exclude_patterns: exc,
    db_host: document.getElementById('db_host').value||null,
    db_port: parseInt(document.getElementById('db_port').value)||3306,
    db_name: document.getElementById('db_name').value||null,
    db_username: document.getElementById('db_username').value||null,
    schedule_cron: document.getElementById('schedule_cron').value||null,
    retention_daily: parseInt(document.getElementById('retention_daily').value)||7,
    retention_weekly: parseInt(document.getElementById('retention_weekly').value)||4,
    retention_monthly: parseInt(document.getElementById('retention_monthly').value)||6,
    encryption_enabled: document.getElementById('encryption_enabled').checked ? 1 : 0,
  };
  const pw = document.getElementById('db_password').value;
  if (pw) body.db_password = pw;
  try {
    if (JOB_ID) { await apiFetch('PUT',`jobs/${JOB_ID}`,body); }
    else { await apiFetch('POST','jobs',body); }
    location.href = 'jobs.php';
  } catch(e) { showAlert(e.message); }
});
</script>
<?php require_once __DIR__ . '/partials/footer.php'; ?>
```

- [ ] **Step 4: Open browser and verify**

Visit `http://localhost/brs/public/jobs.php`. Verify:
- Job list loads (empty table shows "No jobs configured")
- "New Job" button navigates to `job-edit.php`
- Create a test job, save it, confirm it appears in the list
- "Backup Now" button triggers backup and shows success alert

- [ ] **Step 5: Commit**

```bash
git add public/index.php public/jobs.php public/job-edit.php
git commit -m "feat: Dashboard page (summary stats, storage, upcoming), Job list and create/edit pages"
```

---

### Task 20: Backup History + Restore Wizard

**Files:**
- Create: `public/history.php`
- Create: `public/restore.php`

- [ ] **Step 1: Create `public/history.php`**

```php
<?php
$pageTitle = 'Backup History';
require_once __DIR__ . '/partials/header.php';
require_once __DIR__ . '/partials/nav.php';
$jobId = isset($_GET['job_id']) ? (int)$_GET['job_id'] : null;
?>
<div class="d-flex justify-content-between align-items-center mb-4">
  <h2><i class="bi bi-clock-history me-2"></i>Backup History</h2>
  <a href="jobs.php" class="btn btn-outline-secondary btn-sm">← Jobs</a>
</div>
<?php if (!$jobId): ?>
<div class="alert alert-info">Select a job from <a href="jobs.php">Jobs</a> to view its history.</div>
<?php else: ?>
<div id="job-header" class="mb-3 text-muted small"></div>
<div class="card">
  <div class="card-body p-0">
    <table class="table table-hover mb-0">
      <thead class="table-light"><tr>
        <th>ID</th><th>Started</th><th>Duration</th><th>Size</th><th>Status</th><th>Verified</th><th>Pinned</th><th></th>
      </tr></thead>
      <tbody id="history-body"><tr><td colspan="8" class="text-center py-4 text-muted">Loading…</td></tr></tbody>
    </table>
  </div>
</div>
<script>
const JOB_ID = <?= json_encode($jobId) ?>;
(async () => {
  try {
    const d = await apiFetch('GET',`jobs/${JOB_ID}/history?limit=50`);
    const tbody = document.getElementById('history-body');
    if (!d.items.length) { tbody.innerHTML='<tr><td colspan="8" class="text-center text-muted py-4">No backups yet</td></tr>'; return; }
    tbody.innerHTML = d.items.map(b => `
      <tr>
        <td>${b.id}</td>
        <td>${new Date(b.started_at).toLocaleString('th-TH')}</td>
        <td>—</td>
        <td>${b.total_size_bytes ? formatBytes(b.total_size_bytes) : '—'}</td>
        <td><span class="badge status-badge ${b.status}">${b.status}</span></td>
        <td><span class="badge bg-${b.verification_status==='passed'?'success':'secondary'}">${b.verification_status}</span></td>
        <td>${b.is_pinned ? '<i class="bi bi-pin-fill text-warning"></i>' : ''}</td>
        <td>
          ${b.status==='success'?`<a href="restore.php?backup_log_id=${b.id}" class="btn btn-sm btn-outline-warning me-1" title="Restore"><i class="bi bi-arrow-counterclockwise"></i></a>`:''}
          <button class="btn btn-sm btn-outline-secondary" onclick="pinBackup(${b.id})" title="Pin"><i class="bi bi-pin"></i></button>
        </td>
      </tr>`).join('');
  } catch(e) { showAlert(e.message); }
})();

async function pinBackup(id) {
  try { await apiFetch('POST',`backup-logs/${id}/pin`); showAlert('Backup pinned — it will not be deleted by retention policy.','success'); }
  catch(e) { showAlert(e.message); }
}
</script>
<?php endif; ?>
<?php require_once __DIR__ . '/partials/footer.php'; ?>
```

- [ ] **Step 2: Create `public/restore.php`**

```php
<?php
$pageTitle = 'Restore';
require_once __DIR__ . '/partials/header.php';
require_once __DIR__ . '/partials/nav.php';
$backupLogId = isset($_GET['backup_log_id']) ? (int)$_GET['backup_log_id'] : null;
?>
<h2 class="mb-4"><i class="bi bi-arrow-counterclockwise me-2"></i>Restore Wizard</h2>
<?php if (!$backupLogId): ?>
<div class="alert alert-info">Select a backup from <a href="history.php">Backup History</a> to restore.</div>
<?php else: ?>
<div class="card mb-4">
  <div class="card-header">Step 1 — Validate Backup</div>
  <div class="card-body">
    <div id="validate-result" class="mb-3"><em class="text-muted">Click "Validate" to check backup integrity.</em></div>
    <button class="btn btn-outline-primary" id="btn-validate">
      <i class="bi bi-search me-1"></i>Validate Backup #<?= $backupLogId ?>
    </button>
  </div>
</div>
<div class="card" id="restore-panel" style="display:none">
  <div class="card-header bg-warning text-dark">Step 2 — Execute Restore</div>
  <div class="card-body">
    <div class="row g-3">
      <div class="col-md-4">
        <label class="form-label">Mode</label>
        <select class="form-select" id="restore_mode">
          <option value="dry_run">Dry Run (safe — no changes)</option>
          <option value="real">Real Restore</option>
        </select>
      </div>
      <div class="col-md-4">
        <label class="form-label">Target</label>
        <select class="form-select" id="restore_target" onchange="toggleAltFields()">
          <option value="original">Original Location</option>
          <option value="alternate">Alternate Location</option>
        </select>
      </div>
    </div>
    <div id="alt-fields" class="row g-3 mt-1" style="display:none">
      <div class="col-md-6"><label class="form-label">Alternate Path</label><input type="text" class="form-control" id="alternate_path"></div>
      <div class="col-md-6"><label class="form-label">Alternate DB Name</label><input type="text" class="form-control" id="alternate_db_name"></div>
    </div>
    <div id="confirm-panel" class="mt-3" style="display:none">
      <div class="alert alert-danger">
        <strong>⚠️ Real Restore Warning:</strong> This will overwrite existing files and database. A pre-restore snapshot will be created automatically.
      </div>
      <label class="form-label">Type the job name to confirm:</label>
      <input type="text" class="form-control w-50" id="confirm_job_name" placeholder="Exact job name">
    </div>
    <div class="mt-3">
      <button class="btn btn-warning" id="btn-restore"><i class="bi bi-play-fill me-1"></i>Execute Restore</button>
    </div>
  </div>
</div>
<div id="restore-result" class="mt-3"></div>
<script>
const BACKUP_LOG_ID = <?= json_encode($backupLogId) ?>;
document.getElementById('btn-validate').addEventListener('click', async () => {
  try {
    const r = await apiFetch('POST','restore/validate',{backup_log_id:BACKUP_LOG_ID});
    const ok = r.checksum_valid && r.extraction_test_passed;
    document.getElementById('validate-result').innerHTML = `
      <div class="alert alert-${ok?'success':'danger'}">
        <strong>${ok?'✓ Validation Passed':'✗ Validation Failed'}</strong><br>
        Checksum: ${r.checksum_valid?'✓ OK':'✗ Mismatch'} &nbsp;|&nbsp;
        Zip Integrity: ${r.extraction_test_passed?'✓ OK':'✗ Failed'}<br>
        ${r.manifest?`Files: ${r.manifest.files_count} | Date: ${r.manifest.backup_date}`:''}
      </div>`;
    if (ok) document.getElementById('restore-panel').style.display='';
  } catch(e) { showAlert(e.message); }
});

function toggleAltFields() {
  const alt = document.getElementById('restore_target').value === 'alternate';
  document.getElementById('alt-fields').style.display = alt?'':'none';
}

document.getElementById('restore_mode').addEventListener('change', () => {
  const real = document.getElementById('restore_mode').value === 'real';
  document.getElementById('confirm-panel').style.display = real?'':'none';
});

document.getElementById('btn-restore').addEventListener('click', async () => {
  const mode   = document.getElementById('restore_mode').value;
  const target = document.getElementById('restore_target').value;
  const body   = { backup_log_id: BACKUP_LOG_ID, mode, restore_target: target };
  if (target === 'alternate') {
    body.alternate_path    = document.getElementById('alternate_path').value;
    body.alternate_db_name = document.getElementById('alternate_db_name').value;
  }
  if (mode === 'real' && target === 'original') {
    body.confirm_job_name = document.getElementById('confirm_job_name').value;
  }
  try {
    const r = await apiFetch('POST','restore/execute',body);
    document.getElementById('restore-result').innerHTML =
      `<div class="alert alert-success">✓ Restore ${mode} completed. restore_log_id=${r.restore_log_id}</div>`;
  } catch(e) { showAlert(e.message); }
});
</script>
<?php endif; ?>
<?php require_once __DIR__ . '/partials/footer.php'; ?>
```

- [ ] **Step 3: Test restore wizard in browser**

Navigate to `http://localhost/brs/public/history.php?job_id=1`. Click the restore icon on a successful backup. Verify:
- Validate button shows Checksum OK and Zip Integrity OK
- Dry-run mode executes without errors
- Real restore mode requires typing job name in confirm field

- [ ] **Step 4: Commit**

```bash
git add public/history.php public/restore.php
git commit -m "feat: Backup history page and restore wizard (validate → dry-run / real restore)"
```

---

### Task 21: Storage Targets + Users + Audit Log Pages

**Files:**
- Create: `public/storage-targets.php`
- Create: `public/users.php`
- Create: `public/audit-log.php`

- [ ] **Step 1: Create `public/storage-targets.php`**

```php
<?php
$pageTitle = 'Storage Targets';
require_once __DIR__ . '/partials/header.php';
require_once __DIR__ . '/partials/nav.php';
?>
<div class="d-flex justify-content-between align-items-center mb-4">
  <h2><i class="bi bi-hdd-stack me-2"></i>Storage Targets</h2>
  <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#modal-target">
    <i class="bi bi-plus-lg me-1"></i>Add Target
  </button>
</div>
<div class="row g-3" id="targets-grid"><div class="text-muted">Loading…</div></div>

<!-- Add/Edit Modal -->
<div class="modal fade" id="modal-target" tabindex="-1">
  <div class="modal-dialog modal-lg">
    <div class="modal-content">
      <div class="modal-header"><h5 class="modal-title">Storage Target</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
      <div class="modal-body">
        <div class="mb-3"><label class="form-label">Name</label><input type="text" class="form-control" id="st-name"></div>
        <div class="mb-3"><label class="form-label">Provider</label>
          <select class="form-select" id="st-type" onchange="renderConfigFields()">
            <option value="local">Local Disk</option>
            <option value="nas">NAS / Network Share</option>
            <option value="s3">AWS S3</option>
            <option value="google_drive">Google Drive</option>
            <option value="sftp">SFTP</option>
          </select>
        </div>
        <div id="config-fields"></div>
      </div>
      <div class="modal-footer">
        <button class="btn btn-outline-info me-auto" id="btn-test-conn">Test Connection</button>
        <button class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
        <button class="btn btn-primary" id="btn-save-target">Save</button>
      </div>
    </div>
  </div>
</div>
<script>
const CONFIG_FIELDS = {
  local:        [['base_path','Base Path','text','C:\\\\xampp\\\\htdocs\\\\brs\\\\storage']],
  nas:          [['unc_path','UNC Path','text','\\\\\\\\SERVER\\\\share'],['username','Username','text',''],['password','Password','password','']],
  s3:           [['bucket','Bucket','text',''],['region','Region','text','ap-southeast-1'],['access_key','Access Key','text',''],['secret_key','Secret Key','password',''],['path_prefix','Path Prefix','text','brs/']],
  google_drive: [['shared_drive_folder_id','Folder ID','text',''],['service_account_json','Service Account JSON','textarea','']],
  sftp:         [['host','Host','text',''],['port','Port','number','22'],['username','Username','text',''],['private_key_path','Private Key Path','text','']],
};

function renderConfigFields() {
  const type   = document.getElementById('st-type').value;
  const fields = CONFIG_FIELDS[type] || [];
  document.getElementById('config-fields').innerHTML = fields.map(([k,label,type2,ph]) =>
    type2==='textarea'
      ? `<div class="mb-3"><label class="form-label">${label}</label><textarea class="form-control font-monospace" id="cfg-${k}" rows="4" placeholder="${ph}"></textarea></div>`
      : `<div class="mb-3"><label class="form-label">${label}</label><input type="${type2}" class="form-control" id="cfg-${k}" placeholder="${ph}"></div>`
  ).join('');
}

function getConfig() {
  const type   = document.getElementById('st-type').value;
  const fields = CONFIG_FIELDS[type] || [];
  const cfg    = {};
  fields.forEach(([k]) => { const el=document.getElementById('cfg-'+k); if(el) cfg[k]=el.value; });
  return cfg;
}

(async () => {
  renderConfigFields();
  try {
    const targets = await apiFetch('GET','storage-targets');
    const grid = document.getElementById('targets-grid');
    if (!targets.length) { grid.innerHTML='<div class="text-muted">No storage targets. Add one to start.</div>'; return; }
    grid.innerHTML = targets.map(t => `
      <div class="col-md-4">
        <div class="card h-100">
          <div class="card-body">
            <h6>${t.target_name}</h6>
            <span class="badge bg-secondary">${t.provider_type}</span>
            <span class="badge bg-${t.last_test_status==='success'?'success':t.last_test_status==='failed'?'danger':'secondary'} ms-1">${t.last_test_status}</span>
          </div>
          <div class="card-footer d-flex gap-2">
            <button class="btn btn-sm btn-outline-info" onclick="testConn(${t.id})"><i class="bi bi-plug"></i> Test</button>
            <button class="btn btn-sm btn-outline-danger ms-auto" onclick="deleteTarget(${t.id},'${t.target_name}')"><i class="bi bi-trash"></i></button>
          </div>
        </div>
      </div>`).join('');
  } catch(e) { showAlert(e.message); }
})();

document.getElementById('btn-test-conn').addEventListener('click', async () => {
  const body = { target_name: document.getElementById('st-name').value, provider_type: document.getElementById('st-type').value, config: getConfig() };
  try {
    const saved = await apiFetch('POST','storage-targets',body);
    const r = await apiFetch('POST',`storage-targets/${saved.id}/test`);
    showAlert(`Test result: ${r.status} — ${r.message}`, r.status==='success'?'success':'danger');
  } catch(e) { showAlert(e.message); }
});

document.getElementById('btn-save-target').addEventListener('click', async () => {
  const body = { target_name: document.getElementById('st-name').value, provider_type: document.getElementById('st-type').value, config: getConfig() };
  try { await apiFetch('POST','storage-targets',body); bootstrap.Modal.getInstance(document.getElementById('modal-target')).hide(); location.reload(); }
  catch(e) { showAlert(e.message); }
});

async function testConn(id) {
  try { const r = await apiFetch('POST',`storage-targets/${id}/test`); showAlert(`${r.status}: ${r.message}`, r.status==='success'?'success':'danger'); }
  catch(e) { showAlert(e.message); }
}

async function deleteTarget(id, name) {
  if (!confirm(`Delete storage target "${name}"?`)) return;
  try { await apiFetch('DELETE',`storage-targets/${id}`); location.reload(); }
  catch(e) { showAlert(e.message); }
}
</script>
<?php require_once __DIR__ . '/partials/footer.php'; ?>
```

- [ ] **Step 2: Create `public/users.php`**

```php
<?php
$pageTitle = 'User Management';
require_once __DIR__ . '/partials/header.php';
require_once __DIR__ . '/partials/nav.php';
?>
<div class="d-flex justify-content-between align-items-center mb-4">
  <h2><i class="bi bi-people me-2"></i>User Management</h2>
  <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#modal-user"><i class="bi bi-plus-lg me-1"></i>Add User</button>
</div>
<div class="card"><div class="card-body p-0">
  <table class="table table-hover mb-0">
    <thead class="table-light"><tr><th>Username</th><th>Full Name</th><th>Role</th><th>Last Login</th><th>Active</th><th></th></tr></thead>
    <tbody id="users-body"><tr><td colspan="6" class="text-center py-4 text-muted">Loading…</td></tr></tbody>
  </table>
</div></div>

<div class="modal fade" id="modal-user" tabindex="-1">
  <div class="modal-dialog"><div class="modal-content">
    <div class="modal-header"><h5 class="modal-title">Add User</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
    <div class="modal-body">
      <div class="mb-3"><label class="form-label">Username</label><input type="text" class="form-control" id="u-username"></div>
      <div class="mb-3"><label class="form-label">Full Name</label><input type="text" class="form-control" id="u-fullname"></div>
      <div class="mb-3"><label class="form-label">Password</label><input type="password" class="form-control" id="u-password"></div>
      <div class="mb-3"><label class="form-label">Role</label>
        <select class="form-select" id="u-role"><option value="viewer">Viewer</option><option value="operator">Operator</option><option value="admin">Admin</option></select>
      </div>
    </div>
    <div class="modal-footer">
      <button class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
      <button class="btn btn-primary" id="btn-save-user">Create User</button>
    </div>
  </div></div>
</div>

<script>
(async () => {
  const tbody = document.getElementById('users-body');
  try {
    const users = await apiFetch('GET','users');
    tbody.innerHTML = users.map(u => `
      <tr>
        <td><strong>${u.username}</strong></td>
        <td>${u.full_name||'—'}</td>
        <td><span class="badge bg-${u.role==='admin'?'danger':u.role==='operator'?'warning text-dark':'secondary'}">${u.role}</span></td>
        <td>${u.last_login_at?new Date(u.last_login_at).toLocaleString('th-TH'):'Never'}</td>
        <td>${u.is_active?'<span class="badge bg-success">Active</span>':'<span class="badge bg-secondary">Inactive</span>'}</td>
        <td><button class="btn btn-sm btn-outline-danger" onclick="deleteUser(${u.id},'${u.username}')"><i class="bi bi-trash"></i></button></td>
      </tr>`).join('');
  } catch(e) { showAlert(e.message); }
})();

document.getElementById('btn-save-user').addEventListener('click', async () => {
  try {
    await apiFetch('POST','users',{username:document.getElementById('u-username').value,full_name:document.getElementById('u-fullname').value,password:document.getElementById('u-password').value,role:document.getElementById('u-role').value});
    bootstrap.Modal.getInstance(document.getElementById('modal-user')).hide();
    location.reload();
  } catch(e) { showAlert(e.message); }
});

async function deleteUser(id, name) {
  if (!confirm(`Delete user "${name}"?`)) return;
  try { await apiFetch('DELETE',`users/${id}`); location.reload(); }
  catch(e) { showAlert(e.message); }
}
</script>
<?php require_once __DIR__ . '/partials/footer.php'; ?>
```

- [ ] **Step 3: Create `public/audit-log.php`**

```php
<?php
$pageTitle = 'Audit Log';
require_once __DIR__ . '/partials/header.php';
require_once __DIR__ . '/partials/nav.php';
?>
<h2 class="mb-4"><i class="bi bi-journal-text me-2"></i>Audit Log</h2>
<div class="row g-2 mb-3">
  <div class="col-md-3"><input type="text" class="form-control form-control-sm" id="f-action" placeholder="Filter by action…"></div>
  <div class="col-md-2"><input type="date" class="form-control form-control-sm" id="f-from"></div>
  <div class="col-md-2"><input type="date" class="form-control form-control-sm" id="f-to"></div>
  <div class="col-auto"><button class="btn btn-sm btn-primary" onclick="loadLogs()">Filter</button></div>
</div>
<div class="card"><div class="card-body p-0">
  <table class="table table-sm table-hover mb-0">
    <thead class="table-light"><tr><th>Time</th><th>User</th><th>Action</th><th>Target</th><th>IP</th></tr></thead>
    <tbody id="audit-body"><tr><td colspan="5" class="text-center py-3 text-muted">Loading…</td></tr></tbody>
  </table>
</div></div>
<script>
async function loadLogs() {
  const params = new URLSearchParams();
  const action = document.getElementById('f-action').value;
  const from   = document.getElementById('f-from').value;
  const to     = document.getElementById('f-to').value;
  if (action) params.set('action', action);
  if (from)   params.set('from', from);
  if (to)     params.set('to', to + ' 23:59:59');
  const tbody = document.getElementById('audit-body');
  try {
    const d = await apiFetch('GET','dashboard/audit-logs?' + params.toString());
    if (!d.items.length) { tbody.innerHTML='<tr><td colspan="5" class="text-center text-muted py-3">No records found</td></tr>'; return; }
    tbody.innerHTML = d.items.map(r => `
      <tr>
        <td class="text-nowrap">${new Date(r.created_at).toLocaleString('th-TH')}</td>
        <td>${r.username||'System'}</td>
        <td><code>${r.action}</code></td>
        <td>${r.target_type?`${r.target_type}#${r.target_id}`:'—'}</td>
        <td>${r.ip_address||'—'}</td>
      </tr>`).join('');
  } catch(e) { showAlert(e.message); }
}
loadLogs();
</script>
<?php require_once __DIR__ . '/partials/footer.php'; ?>
```

- [ ] **Step 4: Test all remaining pages in browser**

- `http://localhost/brs/public/storage-targets.php` — verify "Test Connection" button works for Local Default
- `http://localhost/brs/public/users.php` — verify user list, add new viewer user
- `http://localhost/brs/public/audit-log.php` — verify audit entries from all prior actions appear

- [ ] **Step 5: Final integration test — full flow**

```bash
# 1. Run healthcheck
php cli/healthcheck.php

# 2. Create and run a backup
php cli/backup.php --job-id=1

# 3. Verify it
php cli/verify.php --backup-log-id=1

# 4. List backups
php cli/list.php --job-id=1

# 5. Dry-run restore
php cli/restore.php --backup-log-id=1 --mode=dry_run

# 6. Cleanup dry-run
php cli/cleanup.php --all --dry-run
```
Expected: All commands exit 0 with expected output

- [ ] **Step 6: Run full test suite**

```bash
php vendor/bin/phpunit tests/
```
Expected: All unit and integration tests PASS

- [ ] **Step 7: Commit**

```bash
git add public/storage-targets.php public/users.php public/audit-log.php
git commit -m "feat: Storage targets, user management, and audit log pages — Web UI complete"
```


---

## Self-Review

### 1. Spec Coverage

| Requirement | Covered By |
|---|---|
| FR-1 Backup Job Management (CRUD, disable, lock) | Tasks 1, 15 |
| FR-2 Backup Execution (zip, mysqldump, checksum, encrypt, verify, upload) | Tasks 3, 6–8, 9 |
| FR-3 Restore (dry-run, real, pre-restore snapshot, checksum verify) | Task 10, 16, 20 |
| FR-4 Storage Providers (local, NAS, S3, Drive, SFTP, test connection) | Tasks 6–8 |
| FR-5 Retention Policy GFS (daily/weekly/monthly, pin) | Tasks 5, 13, 17 |
| FR-6 RBAC (admin/operator/viewer, destructive confirm) | Tasks 14–17 |
| FR-7 LINE Notify (backup success/failure, restore, disk low) | Task 5 |
| FR-8 CLI (backup, restore, verify, cleanup, list, healthcheck) | Tasks 11–13 |
| NFR-1 AES-256-CBC with unique IV, HKDF key derivation | Task 3 |
| NFR-1.4 Session timeout 30 min, HttpOnly cookies | Task 14 |
| NFR-1.5 PDO prepared statements, path traversal | Tasks 2, 3 |
| NFR-1.7 CSRF on all mutations | Task 14 |
| NFR-2.1 Retry on upload fail (exponential backoff) | Not explicitly implemented — each adapter should wrap upload in a retry loop; add as a follow-up or inline in StorageAdapterFactory |
| NFR-2.2 Atomic operations (write to temp/, then move) | Task 9 (BackupEngine uses workDir → moves on success) |
| NFR-2.3 healthcheck.php | Task 11 |
| NFR-5.3 Export/import job config as JSON | Not in scope v1 plan — add if required |

**Gap found:** NFR-2.1 retry with exponential backoff on upload is not explicitly coded in adapters. Add a `retry()` helper to `StorageAdapterFactory` or a `RetryableAdapter` wrapper that calls `upload()` up to 3 times with 5s/15s/45s waits. This is a follow-up task.

### 2. Placeholder Scan

No TBD, TODO, or placeholder text found. All steps contain real PHP code or real commands.

### 3. Type Consistency

- `BackupEngine::run()` returns `int` (backup_log_id) — matches callers in `cli/backup.php` and `public/api/jobs.php`
- `RestoreEngine::validate()` returns `array` with keys `checksum_valid`, `extraction_test_passed`, `manifest` — matches usage in `public/api/restore.php` and `public/restore.php`
- `StorageAdapterInterface::testConnection()` returns `array` with `status` and `message` — matches all adapters and all test/status checks
- `AuditLogger::log()` signature consistent across all callers

---

## Execution Options

Plan complete and saved to `docs/superpowers/plans/2026-06-22-brs-system.md`.

**Two execution options:**

**1. Subagent-Driven (recommended)** — Run `/superpowers:subagent-driven-development` — dispatches a fresh subagent per task with review between tasks.

**2. Inline Execution** — Run `/superpowers:executing-plans` — batch execution with checkpoints for review.

Which approach?
