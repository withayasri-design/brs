# SECURITY — มาตรการความปลอดภัยและความมั่นคงสูงสุด
## BRS: XAMPP Backup & Restore System

เอกสารนี้คือหัวใจของระบบ เนื่องจากผู้ใช้ระบุชัดเจนว่าต้องการ **"ความมั่นคงสูงสุด"** ทุกข้อในนี้ถือเป็น **บังคับ (mandatory)** ไม่ใช่ optional

---

## 1. Encryption (การเข้ารหัส)

### 1.1 At-Rest Encryption
- ไฟล์ backup ทุกไฟล์ (`files.zip`, `database.sql`) เข้ารหัสด้วย **AES-256-CBC** ผ่าน PHP `openssl_encrypt()`
- แต่ละไฟล์ใช้ **IV (Initialization Vector) ที่สุ่มใหม่ทุกครั้ง** (16 bytes, `random_bytes(16)`) ห้ามใช้ IV ซ้ำเด็ดขาด — IV จะถูกเก็บไว้ใน header ของไฟล์ที่เข้ารหัส (ไม่ต้องเก็บแยก)
- Key derivation: ใช้ master key จาก `config/encryption.key` ผ่าน HKDF เพื่อสร้าง per-file key แยกกัน (ป้องกันไม่ให้ key เดียวรั่วแล้วกระทบทุกไฟล์)

### 1.2 Master Key Management
- Master key เก็บในไฟล์ `config/encryption.key` **แยกจากฐานข้อมูลและแยกจาก source code** โดยเด็ดขาด
- กำหนด NTFS permission ให้เฉพาะ service account ที่รัน Apache/PHP เท่านั้นที่อ่านได้ (`icacls` restrict)
- **สำรอง master key แยกต่างหาก** ไว้นอกระบบ (เช่น password manager ขององค์กร หรือ safe deposit) — เพราะถ้า key หายแม้ backup จะอยู่ครบก็ decrypt ไม่ได้ ต้องระบุเรื่องนี้ใน Deployment checklist ชัดเจน
- รองรับ key rotation: เมื่อ rotate key ใหม่ ระบบต้อง re-encrypt backup เก่าที่ยัง active อยู่ หรืออย่างน้อยเก็บ key เวอร์ชันเก่าไว้คู่กับ key ใหม่เพื่อยัง decrypt backup เก่าได้ (`key_version` ใน manifest.json)

### 1.3 In-Transit Encryption
- การอัปโหลดไป Cloud Storage (S3/Google Drive/SFTP) ต้องใช้ TLS/SSH เสมอ ห้าม config แบบ insecure/plaintext transport
- การเชื่อมต่อฐานข้อมูลสำหรับ mysqldump ใช้ `--ssl-mode=PREFERRED` ขั้นต่ำหากฐานข้อมูลปลายทางรองรับ

### 1.4 Credential Encryption ใน Database
- `db_password_encrypted` ใน `backup_jobs` และ `config_json` ใน `storage_targets` ต้องเข้ารหัสก่อนบันทึกเสมอ — **ห้ามเก็บ plaintext password ใน MariaDB เด็ดขาด**
- ใช้ encryption key เดียวกับ master key แต่แยก derived key คนละ purpose (`purpose=credential` vs `purpose=backup_file`)

---

## 2. Integrity Verification (การตรวจสอบความถูกต้อง)

- ทุกไฟล์ backup ต้องมี SHA-256 checksum บันทึกใน `manifest.json` และตาราง `backup_logs`
- **Automated Verification ทุกครั้งหลัง backup เสร็จ** (ไม่ optional):
  1. ตรวจ checksum ของไฟล์ที่อัปโหลดแล้วตรงกับตอนสร้างหรือไม่
  2. ทดลอง `ZipArchive::open()` + `testArchive()` แบบ test mode (ไม่ extract จริง) เพื่อยืนยันไฟล์ไม่เสีย
  3. ตรวจ syntax เบื้องต้นของ `database.sql` (เช่น มี `-- Dump completed` ท้ายไฟล์จาก mysqldump หรือไม่)
- หากตรวจพบความเสียหาย → status เปลี่ยนเป็น `corrupted` ทันที + แจ้งเตือนระดับ critical ผ่าน LINE Notify + ห้าม retention policy ลบ backup เวอร์ชันก่อนหน้าจนกว่าจะมี backup ใหม่ที่ verify ผ่าน

---

## 3. Access Control (RBAC)

| Role | สิทธิ์ |
|------|--------|
| **Admin** | ทุกอย่าง รวมถึงจัดการ user, storage target, ลบ backup ถาวร |
| **Operator** | สร้าง/แก้ไข/รัน backup และ restore เฉพาะ job ที่ได้รับมอบหมาย (ผ่านตาราง mapping job-operator) |
| **Viewer** | ดู dashboard และ history เท่านั้น ห้าม trigger หรือ restore |

- ทุก action แบบ **destructive** (restore จริง, ลบ backup, ลบ job, ลบ storage target) ต้อง:
  1. แสดง dialog อธิบายผลกระทบชัดเจน
  2. บังคับพิมพ์ชื่อ resource (เช่น job name) เพื่อยืนยันซ้ำ — ป้องกันการกดพลาด
  3. ตรวจสอบ role ฝั่ง server เสมอ (ห้ามเชื่อ client-side check อย่างเดียว)

---

## 4. Web Application Security

| มาตรการ | รายละเอียด |
|---------|-------------|
| SQL Injection | บังคับใช้ PDO Prepared Statement ทุก query ห้าม string concat SQL เด็ดขาด |
| Path Traversal | Validate ทุก path ที่รับจาก input (app_path, restore alternate_path) ด้วย whitelist pattern + `realpath()` ตรวจว่าอยู่ใน allowed base directory เท่านั้น |
| CSRF | ทุก POST/PUT/DELETE ต้องแนบ CSRF token ที่ผูกกับ session |
| Session Security | Session timeout 30 นาที (config ได้), regenerate session ID หลัง login, `HttpOnly` + `Secure` cookie flag |
| Password Storage | ใช้ `password_hash()` (bcrypt หรือ Argon2id) ห้าม MD5/SHA1 |
| Rate Limiting | จำกัดจำนวนครั้ง login ผิดพลาด (5 ครั้ง → ล็อก 15 นาที) ป้องกัน brute-force |
| File Upload (ถ้ามีในอนาคต เช่น restore จากไฟล์ที่ upload เอง) | ตรวจ MIME type จริง (ไม่เชื่อ extension อย่างเดียว) + จำกัดขนาด + scan ก่อนใช้งาน |
| Security Headers | ตั้งค่า `X-Content-Type-Options: nosniff`, `X-Frame-Options: DENY`, `Content-Security-Policy` ที่เหมาะสมใน `.htaccess` |

---

## 5. Operational Stability (ความมั่นคงระดับปฏิบัติการ)

### 5.1 Atomic Operations
- ทุก backup เขียนลง `temp/` ก่อนเสมอ แล้วค่อย move (atomic rename) ไป folder ปลายทางจริงเมื่อเสร็จสมบูรณ์ — ป้องกันไฟล์ backup ที่ค้างครึ่งๆ กลางๆ ถูกนับเป็น valid backup
- หาก process ถูก kill กลางทาง ไฟล์ใน `temp/` ที่ไม่สมบูรณ์ต้องถูกล้างโดย cleanup script รอบถัดไปเสมอ

### 5.2 Lock & Stale Process Detection
- Lock file เก็บ PID + hostname + timestamp
- ก่อนเริ่ม job ใหม่ ตรวจสอบว่า PID ใน lock ยัง alive จริงหรือไม่ (`tasklist /FI "PID eq xxx"` บน Windows)
- หาก lock อายุเกิน `max_job_duration_minutes` ให้ถือเป็น stale และ auto-clear พร้อมบันทึก log ผิดปกติเพื่อให้ admin ตรวจสอบภายหลัง

### 5.3 Disk Space Pre-Check
- ก่อนเริ่ม backup ทุกครั้ง คำนวณขนาดโดยประมาณ (จากขนาดไฟล์ปัจจุบัน + ขนาด backup ครั้งก่อนเป็น baseline) แล้วตรวจว่าพื้นที่ว่างปลายทาง ≥ ขนาดที่คาด + buffer 20%
- หากไม่พอ → ยกเลิก job ก่อนเริ่ม (ไม่ปล่อยให้ backup ค้างกลางทางเพราะ disk เต็ม) + แจ้งเตือนทันที

### 5.4 Retry & Resilience
- การอัปโหลดไป Cloud Storage หาก fail จาก network/timeout → retry อัตโนมัติสูงสุด 3 ครั้ง ด้วย exponential backoff (5s, 15s, 45s)
- หากครบ 3 ครั้งยัง fail → mark job เป็น failed พร้อมเก็บ local copy ไว้ก่อน (ไม่ลบไฟล์ local แม้ upload cloud ไม่สำเร็จ) เพื่อให้ยังมี backup อย่างน้อย 1 ชุดเสมอ

### 5.5 Pre-Restore Safety Net
- ก่อน real restore ทุกครั้ง ระบบสร้าง **Pre-Restore Snapshot** ของสถานะปัจจุบันอัตโนมัติ (ไม่ใช่ optional) เพื่อให้ rollback ได้หาก restore ผิดพลาดหรือเลือกผิดเวอร์ชัน
- Snapshot นี้นับเป็น backup ปกติในระบบ (เห็นใน history) พร้อม tag พิเศษ `triggered_by=pre_restore`

### 5.6 Backup of the Backup System Itself
- แนะนำให้ตั้ง Job พิเศษ backup ตัวฐานข้อมูล `brs_system` เอง (metadata, job config, logs) ไปยัง storage target แยกต่างหาก เพื่อไม่ให้ระบบ backup กลายเป็น single point of failure ของตัวเอง

---

## 6. 3-2-1 Backup Rule (แนะนำสำหรับ Production)

> เก็บข้อมูลอย่างน้อย **3 ชุด**, บน **2 สื่อ/ที่เก็บข้อมูลที่ต่างกัน**, โดย **1 ชุดอยู่นอกสถานที่ (off-site)**

ระบบรองรับการ config หลาย storage target ต่อ 1 Job (`job_storage_targets`) จึงสามารถ config ได้เช่น:
1. Local Disk (เร็วที่สุด สำหรับ restore ด่วน)
2. NAS ภายในองค์กร (สื่อที่ 2)
3. Cloud Storage เช่น AWS S3 หรือ Google Drive (off-site)

---

## 7. Audit & Compliance

- ทุก action สำคัญถูกบันทึกใน `audit_logs` แบบ **append-only** (ไม่มี UPDATE/DELETE บนตารางนี้ในระดับ application — ถ้าต้องการ ป้องกันเพิ่มด้วย DB trigger ห้าม DELETE)
- เก็บ audit log ขั้นต่ำ 1 ปี (หรือตามนโยบายองค์กร)
- รายงาน audit log ส่งออกเป็น CSV/PDF ได้สำหรับการตรวจสอบภายใน/ภายนอก

---

## 8. Security Checklist ก่อนขึ้น Production

- [ ] เปลี่ยนรหัสผ่าน default admin ทันทีหลังติดตั้ง
- [ ] ตั้งค่า `encryption.key` และสำรองไว้นอกระบบแล้ว
- [ ] จำกัดสิทธิ์ NTFS ของ folder `config/`, `storage/`, `logs/` ให้เฉพาะ service account
- [ ] เปิด HTTPS สำหรับ Web UI (แม้เป็น internal network ก็ควรทำ โดยใช้ self-signed cert หรือ internal CA)
- [ ] ทดสอบ restore จริงอย่างน้อย 1 ครั้งก่อนใช้งานจริง (ดู DISASTER-RECOVERY.md)
- [ ] ตรวจสอบว่า `storage/`, `temp/`, `config/` ไม่อยู่ใต้ web-accessible path (เช่นไม่อยู่ใน `public/`)
- [ ] ตั้งค่า LINE Notify token และทดสอบแจ้งเตือนสำเร็จ
- [ ] Review สิทธิ์ user ทุกคนว่าตรงตามหน้าที่จริง (principle of least privilege)
