# BRS — XAMPP Backup & Restore System
## เอกสารชุดสมบูรณ์สำหรับพัฒนาระบบ (Documentation Suite)

**ชื่อระบบ:** BRS (Backup & Restore System)
**เวอร์ชันเอกสาร:** 1.0
**วันที่:** 21 มิถุนายน 2569 (2026)
**สถานะ:** Draft for Development

---

## 1. ภาพรวมระบบ

BRS คือระบบ **Backup & Restore แบบ Reusable Framework** ที่ออกแบบมาให้ใช้ได้กับ **ทุกเว็บแอปพลิเคชัน PHP/MariaDB ที่รันบน XAMPP** ไม่ผูกติดกับระบบใดระบบหนึ่งโดยเฉพาะ สามารถนำไปติดตั้งคู่กับเว็บแอปใดก็ได้ในองค์กร เพียง config path และฐานข้อมูลที่ต้องการ backup

**หลักการออกแบบ 3 ข้อ:**

1. **สมบูรณ์ (Complete)** — ครอบคลุมทั้งไฟล์เว็บแอป (source code, uploads, config) และฐานข้อมูล MariaDB/MySQL ในการ backup ชุดเดียว พร้อม metadata ที่ใช้ restore กลับมาให้ตรงสภาพเดิม 100%
2. **ง่ายและสะดวก (Simple & Convenient)** — มี Web UI สำหรับคลิกใช้งานได้ทันที และ CLI script สำหรับตั้งเวลาอัตโนมัติผ่าน Windows Task Scheduler / cron โดยไม่ต้องเขียนคำสั่งซับซ้อน
3. **มั่นคงสูงสุด (Maximum Reliability)** — เข้ารหัสไฟล์ backup ด้วย AES-256, ตรวจสอบความถูกต้องด้วย SHA-256 checksum ทุกครั้ง, ป้องกัน backup ซ้อนกัน (lock mechanism), บันทึก audit log ทุก action, และมี dry-run validation ก่อน restore จริง

---

## 2. โครงสร้างเอกสารทั้งหมด

| # | ไฟล์ | เนื้อหา |
|---|------|---------|
| 00 | README.md | ภาพรวมเอกสารชุดนี้ (ไฟล์นี้) |
| 01 | PRD.md | Product Requirements — เป้าหมาย, ผู้ใช้งาน, ขอบเขต |
| 02 | SRS.md | Software Requirements — functional/non-functional requirements |
| 03 | ARCHITECTURE.md | สถาปัตยกรรมระบบ, tech stack, โครงสร้างโฟลเดอร์ |
| 04 | DATABASE-SCHEMA.md | MariaDB schema สำหรับเก็บ metadata ของระบบ + DDL |
| 05 | API-SPEC.md | REST API endpoints สำหรับ Web UI |
| 06 | SECURITY.md | มาตรการความปลอดภัยและความมั่นคง (encryption, checksum, RBAC, audit) |
| 07 | STORAGE-PROVIDERS.md | ปลายทางจัดเก็บ backup: Local / NAS / Cloud (Google Drive, S3, FTP) |
| 08 | CLI-GUIDE.md | คำสั่ง CLI ทั้งหมด + การตั้งเวลาอัตโนมัติ (Task Scheduler/Cron) |
| 09 | DEPLOYMENT-GUIDE.md | ขั้นตอนติดตั้งบน XAMPP ตั้งแต่ศูนย์ |
| 10 | DISASTER-RECOVERY.md | แผนกู้คืนระบบ, RTO/RPO, ขั้นตอน restore ฉุกเฉิน |
| 11 | CLAUDE.md | คู่มือสำหรับ AI-assisted development (Claude Code) |

---

## 3. Tech Stack สรุป

| Layer | เทคโนโลยี |
|-------|-----------|
| Backend / CLI | PHP 8.2+ Native (ไม่ใช้ Framework เพื่อลด dependency) |
| Web UI | Bootstrap 5 + Vanilla JavaScript (fetch API) |
| Metadata DB | MariaDB (แยก database เฉพาะของระบบ BRS เอง ชื่อ `brs_system`) |
| Target DB ที่ backup | MySQL/MariaDB ของเว็บแอปใดก็ได้ (ผ่าน `mysqldump`/`mysql` CLI) |
| File Compression | ZipArchive (PHP native extension) |
| Encryption | OpenSSL AES-256-CBC (PHP `openssl_encrypt`) |
| Checksum | SHA-256 (PHP `hash_file`) |
| Scheduling | Windows Task Scheduler (เรียก PHP CLI) หรือ Linux cron |
| Notification | LINE Notify API (แจ้งเตือนสำเร็จ/ล้มเหลว) |
| Cloud Storage (optional) | Google Drive API / AWS S3 SDK / SFTP |

---

## 4. Quick Start (ลำดับการอ่านเอกสาร)

1. อ่าน **PRD.md** เพื่อเข้าใจเป้าหมายและขอบเขตก่อน
2. อ่าน **ARCHITECTURE.md** เพื่อเข้าใจโครงสร้างระบบ
3. ใช้ **DATABASE-SCHEMA.md** สร้างฐานข้อมูล metadata
4. พัฒนาตาม **API-SPEC.md** และ **CLI-GUIDE.md**
5. ใช้ **SECURITY.md** เป็น checklist ตรวจสอบก่อนขึ้น production
6. ใช้ **DEPLOYMENT-GUIDE.md** ติดตั้งจริง
7. ทดสอบตาม **DISASTER-RECOVERY.md** ว่า restore ได้จริง (สำคัญที่สุด — backup ที่ไม่เคยทดสอบ restore ถือว่าใช้ไม่ได้)

---

## 5. หลักการสำคัญที่ยึดตลอดทั้งระบบ

> **"Backup ที่ไม่เคย Restore สำเร็จ ไม่ใช่ Backup"**

ระบบนี้จึงบังคับให้มี **Automated Restore Verification** — หลัง backup เสร็จทุกครั้ง ระบบจะทดลอง extract และตรวจ checksum โดยอัตโนมัติ (ไม่ใช่ restore ทับจริง แต่ตรวจว่าไฟล์สมบูรณ์และสามารถ restore ได้) เพื่อให้มั่นใจว่าทุก backup ที่เก็บไว้ใช้งานได้จริงเมื่อต้องการ
