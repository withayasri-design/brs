# CLAUDE.md — AI Development Companion
## BRS: XAMPP Backup & Restore System

เอกสารนี้ใช้เป็น context สำหรับ Claude Code หรือ AI coding assistant อื่นเมื่อพัฒนาโค้ดของระบบนี้ ให้แนบไฟล์นี้ไว้ใน root ของโปรเจกต์เสมอ

---

## 1. Project Context

ระบบ **BRS (Backup & Restore System)** เป็น reusable framework สำหรับ backup/restore เว็บแอป PHP/MariaDB ใดๆ ที่รันบน XAMPP ไม่ผูกกับระบบเฉพาะเจาะจง เน้น **ความมั่นคงสูงสุด (maximum reliability & security)** เป็นหลักการออกแบบอันดับหนึ่ง รองลงมาคือความง่ายในการใช้งาน

อ่านเอกสารตามลำดับนี้ก่อนเริ่มเขียนโค้ดทุกครั้ง: `01-PRD.md` → `02-SRS.md` → `03-ARCHITECTURE.md` → `04-DATABASE-SCHEMA.md` → ไฟล์เฉพาะส่วนที่กำลังพัฒนา

## 2. Tech Stack (ห้ามเบี่ยงเบน)

- PHP 8.2+ **Native เท่านั้น** — ห้ามใช้ Framework (Laravel/Symfony) สำหรับ core engine เพื่อความ portable ข้าม XAMPP environment โดยไม่มี dependency ซับซ้อน
- Composer ใช้ได้เฉพาะ adapter ที่จำเป็นจริงๆ (AWS SDK, Google API Client) — แยก autoload ไม่ให้กระทบ core
- Database: MariaDB ผ่าน PDO เท่านั้น (ห้ามใช้ `mysqli` หรือ raw query string)
- Frontend: Bootstrap 5 + Vanilla JavaScript (`fetch` API) — ห้ามเพิ่ม React/Vue/jQuery โดยไม่จำเป็น
- ไม่มี build step (ไม่มี webpack/vite) — ไฟล์ JS/CSS เขียนตรงและ include ปกติ

## 3. Coding Conventions

```php
// ทุกไฟล์ใน lib/ ต้องเริ่มด้วย strict types
declare(strict_types=1);

// ใช้ PDO Prepared Statement เสมอ ห้าม string concat SQL
$stmt = $pdo->prepare('SELECT * FROM backup_jobs WHERE id = :id');
$stmt->execute(['id' => $jobId]);

// Naming convention
// - Class: PascalCase (BackupEngine, StorageAdapter)
// - Method/function: camelCase (runBackup, validateChecksum)
// - Database table/column: snake_case (backup_jobs, db_password_encrypted)
// - Constant: UPPER_SNAKE_CASE
```

### ข้อบังคับที่ห้ามละเมิดเด็ดขาด (จาก 06-SECURITY.md)

1. **ห้าม** เก็บ password หรือ credential เป็น plaintext ในฐานข้อมูลหรือ log ไฟล์ — ต้องเข้ารหัสผ่าน `EncryptionService` เสมอ
2. **ห้าม** เขียน SQL แบบ string concatenation — ใช้ PDO prepared statement ทุกครั้งไม่มีข้อยกเว้น
3. **ห้าม** รับ path จาก user input โดยไม่ validate ผ่าน `PathValidator::isWithinAllowedBase()` ก่อนใช้งาน (ป้องกัน path traversal)
4. **ห้าม** ลบไฟล์ backup หรือ restore ทับระบบจริงโดยไม่ผ่าน confirmation step ที่ตรงกับ flow ใน `03-ARCHITECTURE.md` หัวข้อ 6
5. **ห้าม** ข้าม verification step หลัง backup เสร็จไม่ว่ากรณีใด (เป็น mandatory step ตาม `06-SECURITY.md` หัวข้อ 2)
6. **ห้าม** เขียนโค้ดที่ assume ว่า disk มีพื้นที่พอ โดยไม่เช็คก่อนเสมอ (NFR-2.3)

## 4. การพัฒนาแต่ละ Component — ใช้เอกสารใดอ้างอิง

| กำลังพัฒนา | อ่านเอกสารนี้ก่อน |
|---|---|
| `lib/BackupEngine.php` | `03-ARCHITECTURE.md` หัวข้อ 5, `02-SRS.md` FR-2 |
| `lib/RestoreEngine.php` | `03-ARCHITECTURE.md` หัวข้อ 6, `02-SRS.md` FR-3 |
| `lib/StorageAdapter/*` | `07-STORAGE-PROVIDERS.md` |
| `lib/EncryptionService.php` | `06-SECURITY.md` หัวข้อ 1 |
| `lib/RetentionPolicyService.php` | `02-SRS.md` FR-5 |
| `public/api/*.php` | `05-API-SPEC.md` |
| `cli/*.php` | `08-CLI-GUIDE.md` |
| Database migration | `04-DATABASE-SCHEMA.md` (ใช้ DDL ตรงตามนี้ห้ามแก้โครงสร้างโดยไม่อัปเดตเอกสาร) |

## 5. Definition of Done สำหรับแต่ละ Feature

ฟีเจอร์จะถือว่าเสร็จสมบูรณ์ก็ต่อเมื่อ:

- [ ] Logic ตรงตาม Functional Requirement ที่อ้างอิงใน `02-SRS.md`
- [ ] ผ่าน Security checklist ที่เกี่ยวข้องใน `06-SECURITY.md`
- [ ] มี error handling ครบทุก failure case ที่ระบุใน flow (`03-ARCHITECTURE.md`)
- [ ] เขียน log ที่จำเป็นลงทั้งไฟล์ log และฐานข้อมูลตาม schema
- [ ] ทดสอบ manual ตาม test case ที่เกี่ยวข้อง (ดู test plan แยกถ้ามี หรือสร้าง test case ใหม่ตามรูปแบบเดียวกัน)
- [ ] ไม่มี hardcoded credential/path ใดๆ ในโค้ด (ดึงจาก config เท่านั้น)

## 6. คำสั่งที่ใช้บ่อยระหว่างพัฒนา

```bash
# รัน healthcheck เพื่อตรวจ environment ก่อนเริ่มพัฒนา
php cli\healthcheck.php

# ทดสอบ backup job ที่กำลังพัฒนา
php cli\backup.php --job-id=1

# ทดสอบ dry-run restore (ปลอดภัย ไม่กระทบข้อมูลจริง)
php cli\restore.php --backup-log-id=<id> --mode=dry_run

# Re-create schema ระหว่างพัฒนา (ระวัง — ลบข้อมูลทั้งหมด ใช้เฉพาะ dev environment)
mysql -u root -p brs_system < sql\schema.sql
```

## 7. สิ่งที่ AI Assistant ควรระวังเป็นพิเศษเมื่อช่วยเขียนโค้ดระบบนี้

- เมื่อแก้ไข `BackupEngine.php` หรือ `RestoreEngine.php` ต้องตรวจสอบว่ายังคง flow ครบทุก step ตาม sequence diagram ใน `03-ARCHITECTURE.md` ไม่ข้าม step ใดแม้จะดูเหมือนไม่จำเป็นในกรณีทดสอบ
- เมื่อเพิ่ม Storage Adapter ใหม่ ต้อง implement `StorageAdapterInterface` ครบทุก method รวมถึง `testConnection()` ที่ Web UI เรียกใช้
- การแก้ไข Database Schema ต้องอัปเดต `04-DATABASE-SCHEMA.md` ให้ตรงกันเสมอ (เอกสารคือ source of truth)
- ทุกครั้งที่เพิ่ม API endpoint ใหม่ ต้องอัปเดต `05-API-SPEC.md` ให้ตรงกัน
