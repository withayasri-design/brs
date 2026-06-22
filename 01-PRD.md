# PRD — Product Requirements Document
## BRS: XAMPP Backup & Restore System

---

## 1. Background & Problem Statement

ปัจจุบันการ backup เว็บแอปพลิเคชันและฐานข้อมูลที่รันบน XAMPP มักทำแบบ manual (copy โฟลเดอร์ + export ฐานข้อมูลผ่าน phpMyAdmin) ซึ่งมีปัญหา:

- **ไม่สม่ำเสมอ** — ขึ้นอยู่กับว่าผู้ดูแลระบบจำได้หรือไม่
- **ไม่มีการตรวจสอบ** — ไม่รู้ว่าไฟล์ backup ที่ได้ใช้งานได้จริงหรือไม่จนกว่าจะลอง restore ตอนเกิดเหตุฉุกเฉิน
- **ไม่ปลอดภัย** — ไฟล์ backup เก็บแบบไม่เข้ารหัส อาจมีข้อมูลอ่อนไหวรั่วไหลได้
- **กู้คืนยาก** — ขั้นตอน restore ไม่มีเอกสาร ทำให้ใช้เวลานานเมื่อเกิดเหตุจริง (downtime สูง)
- **ไม่มี retention policy** — ไฟล์ backup สะสมจนเต็ม disk หรือถูกลบทิ้งโดยไม่ตั้งใจ

## 2. Goals

| เป้าหมาย | ตัวชี้วัดความสำเร็จ |
|----------|---------------------|
| Backup อัตโนมัติ ไม่ต้องพึ่งคน | Job รันตามตารางเวลาสำเร็จ ≥ 99% |
| Restore ได้รวดเร็วเมื่อเกิดเหตุฉุกเฉิน | RTO (Recovery Time Objective) ≤ 30 นาที สำหรับระบบขนาดกลาง |
| ข้อมูลสูญหายน้อยที่สุด | RPO (Recovery Point Objective) ≤ 24 ชั่วโมง (หรือถี่กว่าตาม config) |
| ใช้งานง่าย ไม่ต้องเป็นโปรแกรมเมอร์ | ผู้ใช้ทั่วไปกด backup/restore ผ่าน Web UI ได้ภายใน 3 คลิก |
| มั่นใจได้ว่า backup ใช้งานได้จริง | ทุก backup ผ่าน automated integrity check 100% |
| รองรับหลายระบบพร้อมกัน | 1 BRS instance จัดการได้หลายเว็บแอป/หลายฐานข้อมูล |

## 3. Non-Goals (สิ่งที่ระบบนี้ไม่ทำ)

- ไม่ใช่ระบบ replication/real-time mirroring (ไม่ใช่ HA cluster)
- ไม่ครอบคลุม backup ของ Windows Server OS หรือ IIS configuration
- ไม่รองรับฐานข้อมูลอื่นนอกจาก MySQL/MariaDB ใน v1.0 (PostgreSQL/MSSQL เป็น roadmap ภายหลัง)
- ไม่ใช่ version control system (ไม่ใช่ Git) แม้จะเก็บ snapshot ของไฟล์ก็ตาม

## 4. Target Users / Personas

| Persona | ความต้องการ |
|---------|--------------|
| **IT Admin (ผู้ดูแลระบบ)** | ตั้งค่า job, ดู log, จัดการ storage target, monitor สถานะรวม |
| **System Owner (เจ้าของระบบแต่ละ BU)** | กด backup ด่วนก่อนแก้ไขระบบใหญ่, restore เองได้เมื่อจำเป็น |
| **Auditor / ผู้ตรวจสอบ** | ดู audit trail ว่าใครทำอะไรกับ backup/restore เมื่อไหร่ |

## 5. Scope (ขอบเขต v1.0)

### In Scope
- Backup ไฟล์เว็บแอป (ทั้งโฟลเดอร์ หรือเลือกเฉพาะ path ที่กำหนด) แบบ Full
- Backup ฐานข้อมูล MySQL/MariaDB ผ่าน `mysqldump` แบบ Full
- รองรับหลาย "Backup Job" โดยแต่ละ Job ผูกกับ 1 เว็บแอป + 1 ฐานข้อมูล (หรือมากกว่า)
- Storage ปลายทางเลือกได้: Local Disk, Network Share/NAS, Cloud (Google Drive, AWS S3, SFTP)
- เข้ารหัสไฟล์ backup (AES-256) แบบ optional ต่อ Job
- ตรวจสอบ integrity อัตโนมัติหลัง backup ทุกครั้ง (checksum + test extraction)
- Retention policy แบบ GFS (Grandfather-Father-Son: daily/weekly/monthly)
- Restore แบบเลือก point-in-time ได้ (เลือกจาก backup history)
- Restore แบบ dry-run (validate ก่อน โดยไม่ทับข้อมูลจริง) และ restore จริง
- Web UI: Dashboard, Job management, Backup history, Restore wizard, Storage config, User management (RBAC), Audit log viewer
- CLI script สำหรับรันผ่าน Task Scheduler/cron
- แจ้งเตือนผ่าน LINE Notify เมื่อ backup สำเร็จ/ล้มเหลว
- Role-based access control: Admin / Operator / Viewer

### Out of Scope (v1.0)
- Incremental/differential backup (เป็น roadmap v2.0)
- Multi-server centralized management (จัดการหลายเครื่อง XAMPP จากศูนย์เดียว)
- Database อื่นนอกจาก MySQL/MariaDB
- Mobile app

## 6. Key User Stories

1. ในฐานะ IT Admin ฉันต้องการตั้งเวลาให้ระบบ backup เว็บแอป A ทุกวันตี 2 โดยอัตโนมัติ เพื่อไม่ต้องทำเอง
2. ในฐานะ System Owner ฉันต้องการกดปุ่ม "Backup Now" ก่อนทำการ deploy โค้ดใหม่ เพื่อป้องกันความเสี่ยง
3. ในฐานะ IT Admin เมื่อเซิร์ฟเวอร์ล่ม ฉันต้องการเลือก backup ล่าสุดและกด restore ภายใน 5 นาที โดยไม่ต้องจำคำสั่ง
4. ในฐานะ Auditor ฉันต้องการดูว่าใคร restore ระบบอะไรเมื่อไหร่ ย้อนหลังได้
5. ในฐานะ IT Admin ฉันต้องการให้ระบบลบ backup เก่าที่เกิน retention policy โดยอัตโนมัติ เพื่อไม่ให้ disk เต็ม
6. ในฐานะ IT Admin ฉันต้องการได้รับแจ้งเตือนทาง LINE ทันทีถ้า backup ล้มเหลว เพื่อแก้ไขได้ทัน

## 7. Success Metrics (KPI หลังใช้งานจริง 3 เดือน)

- Backup job success rate ≥ 99%
- จำนวนครั้งที่ restore สำเร็จ / จำนวนครั้งที่พยายาม restore = 100%
- เวลาเฉลี่ยในการ restore ระบบขนาดกลาง (< 5GB) ≤ 30 นาที
- Zero incident ของ backup file เสียหายโดยไม่ทราบล่วงหน้า (ตรวจพบจาก integrity check ก่อนเสมอ)

## 8. Risks & Mitigations

| ความเสี่ยง | แนวทางลด |
|------------|-----------|
| Disk เต็มระหว่าง backup | ตรวจสอบพื้นที่ว่างก่อนเริ่ม job เสมอ, แจ้งเตือนเมื่อพื้นที่เหลือ < 20% |
| Backup ทับซ้อนกันทำให้ resource หมด | ใช้ lock file ป้องกัน job เดียวกันรันซ้อน |
| ไฟล์ backup ถูกเข้าถึงโดยไม่ได้รับอนุญาต | เข้ารหัส AES-256 + จำกัดสิทธิ์ folder ระดับ OS + RBAC ใน Web UI |
| Backup เสียหายแต่ไม่รู้จนสาย | Automated integrity check ทุกครั้งหลัง backup เสร็จ |
| ลืม test restore จนเกิดเหตุจริงแล้ว restore ไม่ได้ | บังคับมี "Scheduled Restore Drill" รายเดือนใน roadmap, มี dry-run validation ทุกครั้ง |
