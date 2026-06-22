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
