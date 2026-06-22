# DATABASE-SCHEMA — Metadata Database Design
## BRS: XAMPP Backup & Restore System

Database name: **`brs_system`** (แยกต่างหากจากฐานข้อมูลของเว็บแอปที่ถูก backup โดยเด็ดขาด)

---

## 1. Entity Relationship Overview

```
users ──┬──< backup_jobs >──┬──< backup_logs >──< backup_files
        │                    │
        │                    └──< storage_targets (many-to-many via job_storage_targets)
        │
        ├──< restore_logs
        └──< audit_logs
```

## 2. Table: `users`

| Column | Type | Note |
|--------|------|------|
| id | INT UNSIGNED PK AUTO_INCREMENT | |
| username | VARCHAR(50) UNIQUE NOT NULL | |
| password_hash | VARCHAR(255) NOT NULL | ใช้ `password_hash()` (bcrypt/argon2) |
| full_name | VARCHAR(150) | |
| role | ENUM('admin','operator','viewer') NOT NULL DEFAULT 'viewer' | |
| line_notify_token | VARCHAR(255) NULL | token ส่วนตัวสำหรับแจ้งเตือน (encrypted) |
| is_active | TINYINT(1) NOT NULL DEFAULT 1 | |
| last_login_at | DATETIME NULL | |
| created_at | DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP | |
| updated_at | DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP | |

## 3. Table: `backup_jobs`

| Column | Type | Note |
|--------|------|------|
| id | INT UNSIGNED PK AUTO_INCREMENT | |
| job_name | VARCHAR(150) NOT NULL | |
| description | TEXT NULL | |
| app_path | VARCHAR(500) NULL | path ของเว็บแอป เช่น `C:\xampp\htdocs\hr2000` (NULL ได้ถ้า backup เฉพาะ DB) |
| include_patterns | TEXT NULL | JSON array เช่น `["*"]` |
| exclude_patterns | TEXT NULL | JSON array เช่น `["cache/*","*.log","tmp/*"]` |
| db_host | VARCHAR(100) NULL | |
| db_port | INT NULL DEFAULT 3306 | |
| db_name | VARCHAR(100) NULL | |
| db_username | VARCHAR(100) NULL | |
| db_password_encrypted | TEXT NULL | เข้ารหัสด้วย EncryptionService ก่อนบันทึกเสมอ |
| backup_type | ENUM('files_only','database_only','both') NOT NULL DEFAULT 'both' | |
| encryption_enabled | TINYINT(1) NOT NULL DEFAULT 1 | |
| schedule_cron | VARCHAR(100) NULL | รูปแบบ cron expression เช่น `0 2 * * *` |
| retention_daily | INT NOT NULL DEFAULT 7 | เก็บ daily backup กี่ชุดล่าสุด |
| retention_weekly | INT NOT NULL DEFAULT 4 | |
| retention_monthly | INT NOT NULL DEFAULT 6 | |
| max_job_duration_minutes | INT NOT NULL DEFAULT 240 | ใช้คำนวณ stale lock |
| is_active | TINYINT(1) NOT NULL DEFAULT 1 | |
| created_by | INT UNSIGNED NULL | FK → users.id |
| created_at | DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP | |
| updated_at | DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP | |

## 4. Table: `storage_targets`

| Column | Type | Note |
|--------|------|------|
| id | INT UNSIGNED PK AUTO_INCREMENT | |
| target_name | VARCHAR(150) NOT NULL | |
| provider_type | ENUM('local','nas','s3','google_drive','sftp') NOT NULL | |
| config_json | TEXT NOT NULL | credential/path เข้ารหัสทั้งก้อนก่อนบันทึก (ดู STORAGE-PROVIDERS.md) |
| is_active | TINYINT(1) NOT NULL DEFAULT 1 | |
| last_test_status | ENUM('untested','success','failed') NOT NULL DEFAULT 'untested' | |
| last_test_at | DATETIME NULL | |
| created_at | DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP | |

## 5. Table: `job_storage_targets` (Many-to-Many)

| Column | Type | Note |
|--------|------|------|
| job_id | INT UNSIGNED NOT NULL | FK → backup_jobs.id |
| storage_target_id | INT UNSIGNED NOT NULL | FK → storage_targets.id |
| priority | INT NOT NULL DEFAULT 1 | ลำดับการอัปโหลด (1 = primary) |
| PRIMARY KEY (job_id, storage_target_id) | | |

## 6. Table: `backup_logs`

| Column | Type | Note |
|--------|------|------|
| id | BIGINT UNSIGNED PK AUTO_INCREMENT | |
| job_id | INT UNSIGNED NOT NULL | FK → backup_jobs.id |
| triggered_by | ENUM('schedule','manual','cli') NOT NULL | |
| triggered_by_user_id | INT UNSIGNED NULL | FK → users.id (NULL ถ้า schedule) |
| status | ENUM('running','success','failed','corrupted') NOT NULL DEFAULT 'running' | |
| started_at | DATETIME NOT NULL | |
| finished_at | DATETIME NULL | |
| duration_seconds | INT NULL | |
| total_size_bytes | BIGINT NULL | |
| files_checksum | VARCHAR(64) NULL | SHA-256 ของ files.zip |
| database_checksum | VARCHAR(64) NULL | SHA-256 ของ database.sql |
| is_encrypted | TINYINT(1) NOT NULL DEFAULT 0 | |
| is_pinned | TINYINT(1) NOT NULL DEFAULT 0 | ถ้า pinned จะไม่ถูก retention policy ลบ |
| verification_status | ENUM('pending','passed','failed') NOT NULL DEFAULT 'pending' | |
| error_message | TEXT NULL | |
| manifest_path | VARCHAR(500) NULL | path ของ manifest.json |

## 7. Table: `backup_files`

| Column | Type | Note |
|--------|------|------|
| id | BIGINT UNSIGNED PK AUTO_INCREMENT | |
| backup_log_id | BIGINT UNSIGNED NOT NULL | FK → backup_logs.id |
| storage_target_id | INT UNSIGNED NOT NULL | FK → storage_targets.id |
| file_type | ENUM('files_archive','database_dump','manifest','checksum') NOT NULL | |
| remote_path | VARCHAR(1000) NOT NULL | path/URL บน storage target นั้นๆ |
| size_bytes | BIGINT NULL | |
| uploaded_at | DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP | |

## 8. Table: `restore_logs`

| Column | Type | Note |
|--------|------|------|
| id | BIGINT UNSIGNED PK AUTO_INCREMENT | |
| job_id | INT UNSIGNED NOT NULL | FK → backup_jobs.id |
| backup_log_id | BIGINT UNSIGNED NOT NULL | FK → backup_logs.id (backup version ที่เลือก restore) |
| restore_mode | ENUM('dry_run','real') NOT NULL | |
| restore_target | ENUM('original','alternate') NOT NULL DEFAULT 'original' | |
| alternate_path | VARCHAR(500) NULL | |
| alternate_db_name | VARCHAR(100) NULL | |
| pre_restore_snapshot_id | BIGINT UNSIGNED NULL | FK → backup_logs.id (snapshot อัตโนมัติก่อน restore) |
| status | ENUM('running','success','failed','rolled_back') NOT NULL DEFAULT 'running' | |
| initiated_by_user_id | INT UNSIGNED NOT NULL | FK → users.id |
| started_at | DATETIME NOT NULL | |
| finished_at | DATETIME NULL | |
| error_message | TEXT NULL | |

## 9. Table: `audit_logs`

| Column | Type | Note |
|--------|------|------|
| id | BIGINT UNSIGNED PK AUTO_INCREMENT | |
| user_id | INT UNSIGNED NULL | FK → users.id (NULL ถ้าเป็น system action) |
| action | VARCHAR(100) NOT NULL | เช่น `job.create`, `backup.trigger`, `restore.execute`, `storage.delete` |
| target_type | VARCHAR(50) NULL | เช่น `backup_job`, `storage_target` |
| target_id | INT UNSIGNED NULL | |
| ip_address | VARCHAR(45) NULL | |
| detail_json | TEXT NULL | รายละเอียดเพิ่มเติมแบบ JSON |
| created_at | DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP | |

---

## 10. Full DDL Script

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
  line_notify_token VARCHAR(255),
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

## 11. Default Seed Data

```sql
-- Default admin user (เปลี่ยนรหัสผ่านทันทีหลังติดตั้ง)
INSERT INTO users (username, password_hash, full_name, role)
VALUES ('admin', '$2y$10$REPLACE_WITH_PASSWORD_HASH', 'System Administrator', 'admin');

-- Default local storage target
INSERT INTO storage_targets (target_name, provider_type, config_json, is_active)
VALUES ('Local Default', 'local', '{"base_path":"C:\\\\xampp\\\\htdocs\\\\brs\\\\storage"}', 1);
```
