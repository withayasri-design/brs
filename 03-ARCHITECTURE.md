# ARCHITECTURE — System Architecture & Design
## BRS: XAMPP Backup & Restore System

---

## 1. High-Level Architecture

```
┌─────────────────────────────────────────────────────────────────┐
│                         BRS SYSTEM                                │
│                                                                     │
│  ┌──────────────┐        ┌──────────────────────────────────┐    │
│  │   Web UI     │───────▶│        PHP Core Engine            │    │
│  │ (Bootstrap5  │  HTTP  │  (lib/BackupEngine.php)           │    │
│  │  + Vanilla   │  fetch │  (lib/RestoreEngine.php)          │    │
│  │     JS)      │        │  (lib/StorageAdapter.php)         │    │
│  └──────────────┘        │  (lib/EncryptionService.php)      │    │
│                            │  (lib/NotificationService.php)    │    │
│  ┌──────────────┐        └──────────────┬─────────────────────┘    │
│  │  CLI Scripts │───────────────────────┘                          │
│  │ backup.php   │                                                  │
│  │ restore.php  │                                                  │
│  │ cleanup.php  │                                                  │
│  │ verify.php   │                                                  │
│  └──────┬───────┘                                                  │
│         │ triggered by                                             │
│         ▼                                                          │
│  Windows Task Scheduler / cron                                     │
│                                                                     │
└──────────────┬─────────────────────────┬──────────────────────────┘
               │                         │
     ┌─────────▼─────────┐    ┌──────────▼──────────────┐
     │  brs_system DB     │    │   Target Web App(s)      │
     │  (MariaDB)          │    │   - Files (htdocs/*)     │
     │  metadata, logs,    │    │   - MySQL/MariaDB DB     │
     │  job config         │    │     (mysqldump source)   │
     └─────────────────────┘    └───────────────────────────┘
               │
     ┌─────────▼──────────────────────────────────────┐
     │              Storage Adapters                    │
     │  ┌──────────┐ ┌──────────┐ ┌──────────────────┐ │
     │  │  Local   │ │ NAS/UNC  │ │  Cloud (S3/Drive/ │ │
     │  │  Disk    │ │  Share   │ │  SFTP)            │ │
     │  └──────────┘ └──────────┘ └──────────────────┘ │
     └────────────────────────────────────────────────┘
```

## 2. Design Principles

1. **Engine แยกจาก Interface** — Backup/Restore Engine เป็น PHP class กลางที่ทั้ง Web UI และ CLI เรียกใช้ร่วมกัน เพื่อไม่ให้ logic ซ้ำซ้อนหรือพฤติกรรมต่างกันระหว่างสองช่องทาง
2. **Storage Adapter Pattern** — ทุก storage provider (Local/NAS/S3/Drive/SFTP) implement interface เดียวกัน (`StorageAdapterInterface`) ทำให้เพิ่ม provider ใหม่ในอนาคตไม่กระทบ core engine
3. **Fail-Safe by Default** — ทุก operation ที่เสี่ยงต่อข้อมูล (restore, delete, cleanup) ต้องผ่าน validation step ก่อนเสมอ และ default เป็นโหมดปลอดภัยที่สุด (เช่น dry-run เป็นค่าเริ่มต้น)
4. **Stateless CLI, Stateful DB** — CLI script ไม่เก็บ state ใดๆ ในตัวเอง ทุกอย่างอ่าน/เขียนผ่าน `brs_system` database เพื่อให้ Web UI เห็นผลลัพธ์แบบ real-time เสมอ

## 3. Folder Structure

```
C:\xampp\htdocs\brs\
│
├── config\
│   ├── app.config.php          # ค่า config หลักของระบบ
│   ├── encryption.key          # Master encryption key (chmod 600 / NTFS restricted)
│   └── storage-providers.php   # Credential ของแต่ละ storage (encrypted)
│
├── public\                      # Web root (เปิดผ่าน browser)
│   ├── index.php               # Dashboard
│   ├── login.php
│   ├── jobs.php                # Job management
│   ├── job-edit.php
│   ├── history.php             # Backup history
│   ├── restore.php             # Restore wizard
│   ├── storage-targets.php
│   ├── users.php
│   ├── audit-log.php
│   ├── api\                    # REST API endpoints (ดู API-SPEC.md)
│   │   ├── jobs.php
│   │   ├── backup.php
│   │   ├── restore.php
│   │   └── storage.php
│   └── assets\
│       ├── css\
│       └── js\
│
├── lib\                          # Core Engine (shared by Web UI + CLI)
│   ├── BackupEngine.php
│   ├── RestoreEngine.php
│   ├── StorageAdapter\
│   │   ├── StorageAdapterInterface.php
│   │   ├── LocalAdapter.php
│   │   ├── NasAdapter.php
│   │   ├── S3Adapter.php
│   │   ├── GoogleDriveAdapter.php
│   │   └── SftpAdapter.php
│   ├── EncryptionService.php
│   ├── ChecksumService.php
│   ├── NotificationService.php
│   ├── RetentionPolicyService.php
│   ├── LockManager.php
│   ├── AuditLogger.php
│   └── Database.php             # PDO wrapper สำหรับ brs_system DB
│
├── cli\                          # CLI scripts สำหรับ Task Scheduler/cron
│   ├── backup.php
│   ├── restore.php
│   ├── verify.php
│   ├── cleanup.php
│   ├── list.php
│   └── healthcheck.php
│
├── storage\                      # พื้นที่ local backup (default target)
│   └── {job_id}\
│       └── {timestamp}\
│           ├── files.zip(.enc)
│           ├── database.sql(.enc)
│           ├── manifest.json
│           └── checksums.sha256
│
├── logs\
│   ├── backup-YYYY-MM-DD.log
│   ├── restore-YYYY-MM-DD.log
│   └── error-YYYY-MM-DD.log
│
├── temp\                         # Working directory ชั่วคราวระหว่าง backup/restore
│
└── vendor\                       # (ถ้าใช้ Composer สำหรับ AWS SDK/Google API เท่านั้น)
```

## 4. Core Component Responsibilities

| Component | หน้าที่ |
|-----------|---------|
| `BackupEngine.php` | Orchestrate ขั้นตอน backup ทั้งหมด: lock → validate disk space → dump DB → zip files → checksum → encrypt → upload to storage → verify → unlock → log |
| `RestoreEngine.php` | Orchestrate ขั้นตอน restore: validate checksum → decrypt → dry-run extract → (ถ้า real restore) snapshot ปัจจุบัน → extract จริง → import DB → verify → log |
| `StorageAdapter\*` | จัดการ upload/download/list/delete ไฟล์กับปลายทางแต่ละแบบ ผ่าน interface เดียวกัน |
| `EncryptionService.php` | เข้ารหัส/ถอดรหัสไฟล์ด้วย AES-256-CBC, จัดการ key derivation |
| `ChecksumService.php` | สร้างและตรวจสอบ SHA-256 checksum |
| `LockManager.php` | สร้าง/ตรวจสอบ/ลบ lock file ป้องกัน job ซ้อน พร้อมตรวจสอบ stale lock (process ตายไปแล้วแต่ lock ค้าง) |
| `RetentionPolicyService.php` | คำนวณว่า backup version ใดควรถูกลบตาม GFS policy |
| `NotificationService.php` | ส่ง LINE Notify เมื่อมี event สำคัญ |
| `AuditLogger.php` | บันทึกทุก action สำคัญลงตาราง `audit_logs` |
| `Database.php` | PDO wrapper, prepared statement บังคับเสมอ |

## 5. Backup Execution Flow (Sequence)

```
1. CLI/Web UI trigger job_id
2. LockManager::acquire(job_id)
     → ถ้า lock อยู่แล้ว และ process ยัง alive → ABORT "Job already running"
     → ถ้า lock ค้างแต่ process ตายแล้ว → clear stale lock แล้วดำเนินต่อ
3. HealthCheck: ตรวจ mysqldump path, PHP extensions, storage connectivity
4. ตรวจสอบพื้นที่ disk ปลายทาง ≥ ขนาดที่คาดการณ์ + buffer 20%
     → ถ้าไม่พอ → ABORT + Notify
5. BackupEngine::dumpDatabase() → mysqldump --single-transaction --routines --triggers
6. BackupEngine::zipFiles() → compress ตาม include/exclude pattern
7. ChecksumService::generate() → สร้าง SHA-256 ของทุกไฟล์
8. (ถ้าเปิด encryption) EncryptionService::encrypt() → AES-256-CBC
9. สร้าง manifest.json (job info, timestamp, file list, checksum, size, version)
10. StorageAdapter::upload() → ส่งไปยังทุก target ที่ config ไว้
11. Verification Step:
     → download กลับมา (หรือตรวจ local copy) → ตรวจ checksum ตรงกับตอน step 7
     → ทดลอง extract zip แบบ test mode (ZipArchive::TestArchive)
     → ถ้า fail → mark backup เป็น "corrupted" + Notify ด่วน
12. RetentionPolicyService::cleanup() → ลบ backup เก่าที่เกิน policy (เฉพาะหลัง verify สำเร็จ)
13. LockManager::release(job_id)
14. AuditLogger::log() + NotificationService::send()
```

## 6. Restore Execution Flow (Sequence)

```
1. ผู้ใช้เลือก backup version จาก history → เลือกโหมด (Dry-run / Real)
2. RestoreEngine::validate()
     → decrypt (ถ้าเข้ารหัสไว้)
     → ตรวจ checksum กับที่บันทึกใน manifest.json
     → ถ้าไม่ตรง → ABORT ทันที "Backup file corrupted, restore aborted"
3. ถ้าโหมด Dry-run:
     → extract ไป temp\ location, ตรวจว่า extract สำเร็จ, ตรวจ SQL syntax ของ dump
     → แสดงผล "Validation Passed" ให้ผู้ใช้ดู ไม่แตะระบบจริง → จบ
4. ถ้าโหมด Real Restore:
     a. สร้าง Pre-Restore Snapshot ของ path/database ปัจจุบันก่อนเสมอ (auto)
     b. ขอ confirm ซ้ำจากผู้ใช้ (พิมพ์ชื่อ job ยืนยัน)
     c. Extract ไฟล์ทับ path ปลายทาง (หรือ path อื่นถ้าเลือก restore-to-alternate)
     d. Import database: mysql < database.sql (หรือ path อื่นถ้าเลือกไว้)
     e. ตรวจสอบหลัง restore: table count, file count เทียบกับ manifest
     f. AuditLogger::log() + NotificationService::send() แจ้งทุกคนที่เกี่ยวข้อง
5. หาก restore ล้มเหลวระหว่างทาง → เสนอ rollback กลับไปใช้ Pre-Restore Snapshot ทันที
```

## 7. Concurrency & Locking Strategy

- Lock file: `temp/locks/{job_id}.lock` เก็บ PID + timestamp + hostname
- ก่อนเริ่ม job ทุกครั้ง ตรวจสอบว่า PID ใน lock file ยัง running จริงหรือไม่ (ผ่าน `tasklist`/`ps`)
- Lock timeout: หาก lock อายุเกิน `max_job_duration` (config ได้ default 4 ชั่วโมง) ให้ถือว่า stale และ auto-clear พร้อม log แจ้งเตือนผิดปกติ
- Restore operation ใช้ lock แยกจาก backup operation ของ job เดียวกัน แต่ห้าม backup และ restore ของ job เดียวกันรันพร้อมกันเด็ดขาด
