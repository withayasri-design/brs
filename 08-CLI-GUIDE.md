# CLI-GUIDE — CLI Commands & Automation Scheduling
## BRS: XAMPP Backup & Restore System

CLI scripts อยู่ที่ `C:\xampp\htdocs\brs\cli\` รันด้วย `C:\xampp\php\php.exe` เรียกจาก Windows Task Scheduler หรือ cron (กรณีใช้ XAMPP บน Linux)

---

## 1. คำสั่งทั้งหมด

### `backup.php` — รัน Backup Job
```bash
php cli\backup.php --job-id=1
php cli\backup.php --job-id=1 --force          # รันแม้ job ถูก disable ไว้ชั่วคราว
php cli\backup.php --all                       # รันทุก active job ที่ถึงกำหนดตาม schedule
```
**Exit code:** `0` = สำเร็จ, `1` = ล้มเหลวทั่วไป, `2` = ล้มเหลวเพราะ disk space ไม่พอ, `3` = job กำลังรันอยู่แล้ว (lock)

### `restore.php` — Restore จาก Backup
```bash
php cli\restore.php --backup-log-id=245 --mode=dry_run
php cli\restore.php --backup-log-id=245 --mode=real --confirm="HR2000 Production"
php cli\restore.php --backup-log-id=245 --mode=real --target=alternate --alt-path="C:\xampp\htdocs\hr2000_test" --alt-db="hr2000_db_test" --confirm="HR2000 Production"
```
**หมายเหตุ:** โหมด `real` บังคับต้องส่ง `--confirm` ที่ตรงกับชื่อ job เป๊ะ มิฉะนั้นระบบปฏิเสธทันที (ป้องกันรัน script พลาดจาก automation อื่น)

### `verify.php` — ตรวจสอบ integrity ของ backup ที่มีอยู่ (โดยไม่ restore)
```bash
php cli\verify.php --backup-log-id=245
php cli\verify.php --job-id=1 --latest          # ตรวจเฉพาะ backup ล่าสุดของ job
php cli\verify.php --all-recent --days=7        # ตรวจทุก backup ใน 7 วันล่าสุด (รันเป็น weekly health check)
```

### `cleanup.php` — ลบ backup เก่าตาม Retention Policy
```bash
php cli\cleanup.php --job-id=1
php cli\cleanup.php --all                       # รันทุก job
php cli\cleanup.php --job-id=1 --dry-run         # แสดงว่าจะลบอะไรบ้าง โดยไม่ลบจริง
```

### `list.php` — แสดงรายการ backup
```bash
php cli\list.php --job-id=1 --limit=10
php cli\list.php --job-id=1 --format=json        # สำหรับนำไปใช้ใน script อื่นต่อ
```

### `healthcheck.php` — ตรวจสอบความพร้อมของระบบ
```bash
php cli\healthcheck.php
```
ตรวจ: `mysqldump`/`mysql` path ใช้งานได้, PHP extensions ที่จำเป็น (`zip`, `openssl`, `pdo_mysql`), storage target ทุกตัว connect ได้, พื้นที่ disk เหลือเพียงพอ

---

## 2. ตั้งเวลาอัตโนมัติด้วย Windows Task Scheduler

### ขั้นตอนตั้งค่า (แนะนำใช้ตัวกลาง 1 task เรียก `--all` แทนตั้งทีละ job)

1. เปิด **Task Scheduler** → Create Task
2. **General tab:**
   - Name: `BRS - Run Scheduled Backups`
   - Run whether user is logged on or not: ✓ เลือก
   - Run with highest privileges: ✓ เลือก
3. **Triggers tab:** New → Daily, Repeat task every **15 minutes** for a duration of **1 day** (เพื่อให้ครอบคลุมทุก cron schedule ที่ตั้งใน Job แต่ละตัว)
4. **Actions tab:** New →
   - Program/script: `C:\xampp\php\php.exe`
   - Add arguments: `C:\xampp\htdocs\brs\cli\backup.php --all`
   - Start in: `C:\xampp\htdocs\brs`
5. **Settings tab:**
   - ✓ Allow task to be run on demand
   - ✓ If the task fails, restart every 5 minutes, up to 3 times
   - ✓ Stop the task if it runs longer than `4 hours` (สอดคล้องกับ `max_job_duration_minutes`)

> **หลักการ:** Task Scheduler รันทุก 15 นาที แต่ `backup.php --all` จะเช็คเองว่า job ไหน "ถึงกำหนด" ตาม `schedule_cron` ของแต่ละ job แล้วรันเฉพาะที่ถึงเวลาเท่านั้น (logic เปรียบเทียบ cron expression อยู่ใน `BackupEngine::isDue()`)

### Task แยกสำหรับ Cleanup และ Healthcheck

| Task Name | Schedule | Command |
|-----------|----------|---------|
| BRS - Daily Cleanup | ทุกวัน 04:00 | `php cli\cleanup.php --all` |
| BRS - Weekly Verify | ทุกวันอาทิตย์ 05:00 | `php cli\verify.php --all-recent --days=7` |
| BRS - Healthcheck | ทุก 6 ชั่วโมง | `php cli\healthcheck.php` |

---

## 3. ตั้งเวลาด้วย Cron (กรณี XAMPP บน Linux)

```bash
# /etc/cron.d/brs
*/15 * * * *  www-data  php /opt/lampp/htdocs/brs/cli/backup.php --all >> /var/log/brs/cron.log 2>&1
0 4 * * *     www-data  php /opt/lampp/htdocs/brs/cli/cleanup.php --all >> /var/log/brs/cron.log 2>&1
0 5 * * 0     www-data  php /opt/lampp/htdocs/brs/cli/verify.php --all-recent --days=7 >> /var/log/brs/cron.log 2>&1
0 */6 * * *   www-data  php /opt/lampp/htdocs/brs/cli/healthcheck.php >> /var/log/brs/cron.log 2>&1
```

---

## 4. ตัวอย่าง Batch File สำหรับ Manual Trigger (สะดวกสำหรับผู้ใช้ที่ไม่ถนัด command line)

`backup-now.bat`:
```bat
@echo off
echo กำลัง Backup Job ID 1 ...
C:\xampp\php\php.exe C:\xampp\htdocs\brs\cli\backup.php --job-id=1
if %ERRORLEVEL% EQU 0 (
    echo Backup สำเร็จ!
) else (
    echo Backup ล้มเหลว! กรุณาตรวจสอบ log ที่ C:\xampp\htdocs\brs\logs\
)
pause
```
วาง shortcut ของไฟล์นี้บน Desktop ให้ System Owner ที่ไม่ถนัดเทคนิคดับเบิลคลิกได้เองเมื่อต้องการ backup ด่วน

---

## 5. Logging ของ CLI

ทุกคำสั่ง CLI เขียน log ลง:
- `logs/backup-YYYY-MM-DD.log` หรือ `logs/restore-YYYY-MM-DD.log` (human-readable, สำหรับ troubleshoot)
- ฐานข้อมูล `brs_system` ตาราง `backup_logs`/`restore_logs` (สำหรับ Web UI แสดงผล)

รูปแบบ log line:
```
[2026-06-21 02:00:00] [INFO] [job_id=1] Starting backup job "HR2000 Production"
[2026-06-21 02:00:01] [INFO] [job_id=1] Disk space check passed (free: 180GB, required: 2.5GB)
[2026-06-21 02:00:02] [INFO] [job_id=1] Dumping database hr2000_db ...
[2026-06-21 02:02:15] [INFO] [job_id=1] Database dump completed (450MB)
[2026-06-21 02:02:16] [INFO] [job_id=1] Compressing files from C:\xampp\htdocs\hr2000 ...
[2026-06-21 02:03:40] [INFO] [job_id=1] Encryption completed
[2026-06-21 02:03:55] [INFO] [job_id=1] Uploaded to target "Local Default", "AWS S3 Offsite"
[2026-06-21 02:04:10] [INFO] [job_id=1] Verification PASSED (checksum match, extraction test OK)
[2026-06-21 02:04:11] [INFO] [job_id=1] Retention cleanup: removed 2 expired backups
[2026-06-21 02:04:12] [INFO] [job_id=1] Backup completed successfully (duration: 4m12s)
```
