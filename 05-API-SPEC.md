# API-SPEC — REST API Specification
## BRS: XAMPP Backup & Restore System

Base URL: `http://localhost/brs/public/api`
Authentication: Session cookie (login ผ่าน `/login.php`) + CSRF token ใน header `X-CSRF-Token` สำหรับทุก request ที่เปลี่ยนแปลงข้อมูล (POST/PUT/DELETE)
Response format: JSON เสมอ `{ "success": bool, "data": {...}, "error": null|string }`

---

## 1. Authentication

### POST `/api/auth/login`
```json
// Request
{ "username": "admin", "password": "xxxx" }

// Response 200
{ "success": true, "data": { "user_id": 1, "role": "admin", "csrf_token": "..." } }

// Response 401
{ "success": false, "error": "Invalid username or password" }
```

### POST `/api/auth/logout`
Response 200: `{ "success": true }`

---

## 2. Backup Jobs

### GET `/api/jobs`
Query params: `?page=1&limit=20&search=`
```json
{
  "success": true,
  "data": {
    "items": [
      {
        "id": 1,
        "job_name": "HR2000 Production",
        "app_path": "C:\\xampp\\htdocs\\hr2000",
        "db_name": "hr2000_db",
        "backup_type": "both",
        "schedule_cron": "0 2 * * *",
        "is_active": true,
        "last_backup_status": "success",
        "last_backup_at": "2026-06-20T02:00:15+07:00"
      }
    ],
    "total": 12, "page": 1, "limit": 20
  }
}
```

### POST `/api/jobs`
สร้าง Job ใหม่
```json
// Request
{
  "job_name": "HR2000 Production",
  "app_path": "C:\\xampp\\htdocs\\hr2000",
  "include_patterns": ["*"],
  "exclude_patterns": ["cache/*", "*.log"],
  "db_host": "localhost",
  "db_port": 3306,
  "db_name": "hr2000_db",
  "db_username": "root",
  "db_password": "xxxx",
  "backup_type": "both",
  "encryption_enabled": true,
  "schedule_cron": "0 2 * * *",
  "retention_daily": 7,
  "retention_weekly": 4,
  "retention_monthly": 6,
  "storage_target_ids": [1, 3]
}
// Response 201: { "success": true, "data": { "id": 15 } }
```

### GET `/api/jobs/{id}` — ดู Job เดี่ยว
### PUT `/api/jobs/{id}` — แก้ไข Job (payload เหมือน POST)
### DELETE `/api/jobs/{id}` — ลบ Job (ต้องมี `confirm_name` ตรงกับ job_name ใน body)

```json
// DELETE request body
{ "confirm_name": "HR2000 Production" }
```

### POST `/api/jobs/{id}/run` — สั่ง Backup Now
```json
// Response 202 (accepted, รันแบบ background)
{ "success": true, "data": { "backup_log_id": 245, "status": "running" } }
```

---

## 3. Backup History & Status

### GET `/api/jobs/{id}/history`
Query: `?page=1&limit=20`
```json
{
  "success": true,
  "data": {
    "items": [
      {
        "backup_log_id": 245,
        "started_at": "2026-06-20T02:00:00+07:00",
        "finished_at": "2026-06-20T02:04:32+07:00",
        "status": "success",
        "verification_status": "passed",
        "total_size_bytes": 524288000,
        "is_encrypted": true,
        "is_pinned": false,
        "triggered_by": "schedule"
      }
    ],
    "total": 87
  }
}
```

### GET `/api/backup-logs/{id}` — รายละเอียด backup log เดี่ยว (รวม manifest)
### GET `/api/backup-logs/{id}/status` — สำหรับ polling สถานะระหว่าง job กำลังรัน (ใช้ตอน "Backup Now")
```json
{ "success": true, "data": { "status": "running", "current_step": "uploading_to_storage", "progress_percent": 70 } }
```

### POST `/api/backup-logs/{id}/pin` — ล็อกไม่ให้ retention policy ลบ
### DELETE `/api/backup-logs/{id}` — ลบ backup version นี้ทิ้งด้วยตนเอง (ต้องเป็น admin)

---

## 4. Restore

### POST `/api/restore/validate`
ตรวจสอบ backup ก่อน restore (ใช้ทั้งคู่กับ dry-run และก่อน real restore)
```json
// Request
{ "backup_log_id": 245 }

// Response
{
  "success": true,
  "data": {
    "checksum_valid": true,
    "extraction_test_passed": true,
    "manifest": { "files_count": 1204, "db_tables_count": 38, "backup_date": "2026-06-20T02:00:00+07:00" }
  }
}
```

### POST `/api/restore/execute`
```json
// Request
{
  "backup_log_id": 245,
  "mode": "real",                      // "dry_run" | "real"
  "restore_target": "alternate",       // "original" | "alternate"
  "alternate_path": "C:\\xampp\\htdocs\\hr2000_test",
  "alternate_db_name": "hr2000_db_test",
  "confirm_job_name": "HR2000 Production"  // บังคับกรอกชื่อ job ยืนยันก่อน restore ของจริงเสมอ
}

// Response 202
{ "success": true, "data": { "restore_log_id": 88, "status": "running" } }
```

### GET `/api/restore-logs/{id}/status` — polling สถานะ restore
### POST `/api/restore-logs/{id}/rollback` — rollback กลับไปใช้ pre-restore snapshot (เมื่อ restore ล้มเหลว)

---

## 5. Storage Targets

### GET `/api/storage-targets`
### POST `/api/storage-targets`
```json
{
  "target_name": "AWS S3 Offsite",
  "provider_type": "s3",
  "config": {
    "bucket": "bsi-backup-offsite",
    "region": "ap-southeast-1",
    "access_key": "xxx",
    "secret_key": "xxx",
    "path_prefix": "brs/"
  }
}
```
### PUT `/api/storage-targets/{id}`
### DELETE `/api/storage-targets/{id}`
### POST `/api/storage-targets/{id}/test` — ทดสอบการเชื่อมต่อ
```json
{ "success": true, "data": { "status": "success", "message": "Connection OK, write/read test passed" } }
```

---

## 6. Users (Admin only)

### GET `/api/users`
### POST `/api/users`
### PUT `/api/users/{id}`
### DELETE `/api/users/{id}`
### POST `/api/users/{id}/reset-password`

---

## 7. Audit Log

### GET `/api/audit-logs`
Query: `?user_id=&action=&from=&to=&page=1&limit=50`
```json
{
  "success": true,
  "data": {
    "items": [
      {
        "id": 901,
        "user": "admin",
        "action": "restore.execute",
        "target_type": "backup_job",
        "target_id": 1,
        "ip_address": "192.168.1.50",
        "detail": { "backup_log_id": 245, "mode": "real" },
        "created_at": "2026-06-21T10:15:00+07:00"
      }
    ],
    "total": 1320
  }
}
```

---

## 8. Dashboard / System Status

### GET `/api/dashboard/summary`
```json
{
  "success": true,
  "data": {
    "total_jobs": 12,
    "active_jobs": 10,
    "jobs_failed_last_24h": 0,
    "total_backup_size_bytes": 84738291200,
    "storage_usage_by_target": [
      { "target_name": "Local Default", "used_bytes": 50000000000, "free_bytes": 200000000000 }
    ],
    "upcoming_scheduled_jobs": [
      { "job_id": 1, "job_name": "HR2000 Production", "next_run_at": "2026-06-22T02:00:00+07:00" }
    ]
  }
}
```

### GET `/api/healthcheck`
ใช้ตรวจสอบความพร้อมของระบบ (mysqldump path, PHP extensions, storage connectivity ของทุก target)
```json
{
  "success": true,
  "data": {
    "mysqldump_available": true,
    "php_extensions": { "zip": true, "openssl": true, "pdo_mysql": true },
    "storage_targets": [
      { "id": 1, "name": "Local Default", "status": "ok" },
      { "id": 3, "name": "AWS S3 Offsite", "status": "ok" }
    ]
  }
}
```

---

## 9. Error Codes มาตรฐาน

| HTTP Status | error code | ความหมาย |
|---|---|---|
| 400 | `VALIDATION_ERROR` | ข้อมูล request ไม่ถูกต้อง |
| 401 | `UNAUTHORIZED` | ยังไม่ login หรือ session หมดอายุ |
| 403 | `FORBIDDEN` | role ไม่มีสิทธิ์ทำ action นี้ |
| 404 | `NOT_FOUND` | ไม่พบ resource |
| 409 | `JOB_ALREADY_RUNNING` | Job นี้กำลังรันอยู่ (lock) |
| 422 | `CHECKSUM_MISMATCH` | ไฟล์ backup checksum ไม่ตรง ห้าม restore |
| 422 | `INSUFFICIENT_DISK_SPACE` | พื้นที่ปลายทางไม่พอ |
| 500 | `INTERNAL_ERROR` | ข้อผิดพลาดทั่วไป |
