# SRS — Software Requirements Specification
## BRS: XAMPP Backup & Restore System

---

## 1. Functional Requirements

### FR-1: Backup Job Management
| ID | Requirement |
|----|-------------|
| FR-1.1 | ผู้ใช้สามารถสร้าง Backup Job ใหม่ได้ โดยระบุ: ชื่อ Job, path ของเว็บแอป, ข้อมูลเชื่อมต่อฐานข้อมูล (host/port/user/password/db name), storage target ปลายทาง, ตารางเวลา (schedule), retention policy, เปิด/ปิดการเข้ารหัส |
| FR-1.2 | ผู้ใช้สามารถแก้ไข/ปิดใช้งาน (disable) /ลบ Backup Job ได้ |
| FR-1.3 | ผู้ใช้สามารถสั่ง "Backup Now" เพื่อรันทันทีนอกเหนือจากตารางเวลาได้ |
| FR-1.4 | ระบบต้องป้องกันไม่ให้ Job เดียวกันรันซ้อนกัน (mutex/lock file ต่อ Job ID) |
| FR-1.5 | ผู้ใช้สามารถเลือก backup เฉพาะไฟล์เว็บแอป, เฉพาะฐานข้อมูล, หรือทั้งคู่ ต่อ 1 Job ได้ |
| FR-1.6 | ระบบต้องสามารถ exclude path/file pattern ที่ไม่ต้องการ backup ได้ (เช่น `/cache/*`, `*.log`, `/tmp/*`) |

### FR-2: Backup Execution
| ID | Requirement |
|----|-------------|
| FR-2.1 | ระบบต้อง compress ไฟล์เว็บแอปเป็น .zip ด้วย PHP ZipArchive |
| FR-2.2 | ระบบต้อง export ฐานข้อมูลด้วย `mysqldump` พร้อม option `--single-transaction` เพื่อความ consistent ขณะระบบยังทำงานอยู่ |
| FR-2.3 | ระบบต้องตรวจสอบพื้นที่ว่างบน disk ปลายทางก่อนเริ่ม backup เสมอ และยกเลิก job หากพื้นที่ไม่พอ พร้อมแจ้งเตือน |
| FR-2.4 | ระบบต้องสร้างไฟล์ checksum (SHA-256) คู่กับทุกไฟล์ backup ที่สร้าง |
| FR-2.5 | ระบบต้องเข้ารหัสไฟล์ backup ด้วย AES-256-CBC หาก Job เปิดใช้ encryption |
| FR-2.6 | หลัง backup เสร็จ ระบบต้องรัน "verification step" อัตโนมัติ: ตรวจ checksum + ทดลอง extract zip (test mode) ก่อนถือว่า job สำเร็จ |
| FR-2.7 | ระบบต้องบันทึก log การทำงานทุกขั้นตอน (start time, end time, file size, checksum, status, error message ถ้ามี) ลงตาราง `backup_logs` |
| FR-2.8 | ระบบต้องส่งไฟล์ backup ไปยัง storage target ที่ config ไว้ (รองรับหลายปลายทางพร้อมกันต่อ 1 Job ได้ เช่น local + cloud)|

### FR-3: Restore
| ID | Requirement |
|----|-------------|
| FR-3.1 | ผู้ใช้สามารถดูประวัติ backup ทั้งหมดของแต่ละ Job พร้อม timestamp, ขนาดไฟล์, สถานะ |
| FR-3.2 | ผู้ใช้สามารถเลือก backup version ใดก็ได้ในประวัติเพื่อ restore (point-in-time restore) |
| FR-3.3 | ระบบต้องมีโหมด "Dry-Run Restore" ที่ตรวจสอบความถูกต้องของไฟล์ backup โดยไม่ทับข้อมูลจริง |
| FR-3.4 | ก่อน restore จริงทับระบบเดิม ระบบต้องสร้าง "Pre-Restore Snapshot" ของสถานะปัจจุบันอัตโนมัติ เผื่อต้อง rollback |
| FR-3.5 | ผู้ใช้สามารถเลือก restore ไปยัง path/database อื่น (ไม่ทับของเดิม) เพื่อทดสอบได้ |
| FR-3.6 | ระบบต้อง decrypt และตรวจ checksum ก่อนเริ่มกระบวนการ restore เสมอ หาก checksum ไม่ตรง ต้องหยุดทันทีและแจ้งเตือน |
| FR-3.7 | ระบบต้องบันทึก log ทุกการ restore ลงตาราง `restore_logs` พร้อมผู้ดำเนินการ |

### FR-4: Storage Providers
| ID | Requirement |
|----|-------------|
| FR-4.1 | รองรับ Local Disk เป็น default storage |
| FR-4.2 | รองรับ Network Share / NAS ผ่าน UNC path หรือ mapped drive |
| FR-4.3 | รองรับ Cloud Storage: Google Drive API, AWS S3 (S3-compatible), SFTP |
| FR-4.4 | ผู้ใช้ config storage target ผ่าน Web UI ได้ พร้อมปุ่ม "Test Connection" |
| FR-4.5 | รองรับการตั้งค่า "3-2-1 rule" ได้ (เก็บ 3 copies, 2 สื่อต่างกัน, 1 off-site) โดยเลือกได้หลาย target ต่อ 1 job |

### FR-5: Retention Policy
| ID | Requirement |
|----|-------------|
| FR-5.1 | รองรับ retention แบบ GFS: เก็บ daily backup N วันล่าสุด, weekly backup N สัปดาห์, monthly backup N เดือน |
| FR-5.2 | ระบบต้องลบ backup ที่เกิน retention policy โดยอัตโนมัติหลังจาก verification สำเร็จของ backup ใหม่เท่านั้น (ป้องกันไม่มี backup เหลือเลยหาก job ใหม่ล้มเหลว) |
| FR-5.3 | ผู้ใช้สามารถ "pin" (ล็อก) backup version ใดไม่ให้ถูกลบอัตโนมัติได้ (เช่น backup ก่อน major release) |

### FR-6: User & Access Control
| ID | Requirement |
|----|-------------|
| FR-6.1 | ระบบมี 3 role: Admin (จัดการทุกอย่าง), Operator (backup/restore เฉพาะ job ที่ได้รับสิทธิ์), Viewer (ดูอย่างเดียว) |
| FR-6.2 | Action ที่เป็น "destructive" (restore ทับ, ลบ backup, ลบ job) ต้องมีการยืนยันซ้ำ (confirm dialog + พิมพ์ชื่อ job ยืนยัน) |
| FR-6.3 | ระบบต้องบันทึก audit log ทุก action สำคัญ พร้อม user, timestamp, IP address |

### FR-7: Notification
| ID | Requirement |
|----|-------------|
| FR-7.1 | ส่งแจ้งเตือนผ่าน LINE Notify เมื่อ backup สำเร็จ/ล้มเหลว (ตั้งค่าได้ว่าต้องการแจ้งเฉพาะ failure หรือทุกครั้ง) |
| FR-7.2 | ส่งแจ้งเตือนเมื่อ disk พื้นที่เหลือต่ำกว่าเกณฑ์ที่กำหนด |
| FR-7.3 | ส่งแจ้งเตือนเมื่อมีการ restore เกิดขึ้น (เพื่อความปลอดภัย ให้ทุกคนที่เกี่ยวข้องรับรู้) |

### FR-8: CLI Interface
| ID | Requirement |
|----|-------------|
| FR-8.1 | มีคำสั่ง CLI ครบสำหรับ backup, restore, list, verify, cleanup โดยไม่ต้องเปิด Web UI |
| FR-8.2 | CLI ต้อง return exit code มาตรฐาน (0 = success, non-zero = error) เพื่อให้ Task Scheduler ตรวจสอบผลได้ |
| FR-8.3 | CLI ต้องเขียน log แยกจาก Web UI execution log แต่บันทึกลงฐานข้อมูลเดียวกัน |

---

## 2. Non-Functional Requirements

### NFR-1: Security (ความมั่นคงสูงสุด)
| ID | Requirement |
|----|-------------|
| NFR-1.1 | ไฟล์ backup ที่เข้ารหัสต้องใช้ AES-256-CBC ขั้นต่ำ พร้อม unique IV ต่อไฟล์ |
| NFR-1.2 | Encryption key ต้องไม่เก็บในฐานข้อมูลเดียวกับ metadata; เก็บแยกใน config file ที่จำกัดสิทธิ์ระดับ OS หรือใช้ environment variable |
| NFR-1.3 | รหัสผ่านฐานข้อมูลที่เก็บใน Job config ต้องเข้ารหัสก่อนบันทึกลง DB (ห้ามเก็บ plaintext) |
| NFR-1.4 | Web UI ต้องบังคับ login และมี session timeout (default 30 นาที) |
| NFR-1.5 | ต้องป้องกัน SQL Injection ด้วย Prepared Statement ทุก query, ป้องกัน Path Traversal ในการระบุ path backup |
| NFR-1.6 | Folder เก็บ backup ต้องตั้ง permission ให้เข้าถึงได้เฉพาะ service account ที่รัน XAMPP เท่านั้น |
| NFR-1.7 | ทุก action ที่กระทบข้อมูล (restore, delete) ต้องผ่าน CSRF token validation |

### NFR-2: Reliability & Stability
| ID | Requirement |
|----|-------------|
| NFR-2.1 | Backup job ต้องมี retry mechanism อัตโนมัติสูงสุด 3 ครั้งหากล้มเหลวจากสาเหตุชั่วคราว (เช่น network timeout ไปยัง cloud storage) พร้อม exponential backoff |
| NFR-2.2 | ระบบต้องทำงานแบบ atomic — หาก backup ล้มเหลวกลางทาง ต้องลบไฟล์ที่ไม่สมบูรณ์ทิ้งทันที ไม่ปล่อยให้ไฟล์ขยะค้าง |
| NFR-2.3 | ระบบต้องมี health-check script ตรวจสอบว่า dependency ที่จำเป็น (mysqldump, PHP extensions, storage connectivity) พร้อมใช้งานก่อนรัน job ทุกครั้ง |
| NFR-2.4 | กรณีไฟฟ้าดับ/เครื่องรีสตาร์ทระหว่าง backup ระบบต้องตรวจพบ lock file ค้างและ auto-clear เมื่อเริ่มใหม่ พร้อมตรวจสอบว่า process เดิมยังทำงานอยู่จริงหรือไม่ |
| NFR-2.5 | Uptime เป้าหมายของ Web UI: 99.5% (ยกเว้นช่วง maintenance) |

### NFR-3: Performance
| ID | Requirement |
|----|-------------|
| NFR-3.1 | Backup ฐานข้อมูลขนาด ≤ 1GB ต้องเสร็จภายใน 5 นาที |
| NFR-3.2 | Web UI Dashboard ต้องโหลดภายใน 2 วินาที แม้มี backup history มากกว่า 1,000 รายการ (ใช้ pagination) |
| NFR-3.3 | การ compress ไฟล์ขนาดใหญ่ต้องไม่ block การทำงานของ Web UI อื่น (รันแบบ background process) |

### NFR-4: Usability
| ID | Requirement |
|----|-------------|
| NFR-4.1 | ผู้ใช้ทั่วไป (ไม่ใช่โปรแกรมเมอร์) ต้องสามารถสร้าง Backup Job แรกได้สำเร็จภายใน 5 นาทีโดยไม่ต้องอ่าน manual |
| NFR-4.2 | ทุกหน้าจอที่เป็น destructive action ต้องมีคำอธิบายผลกระทบชัดเจนก่อนยืนยัน |
| NFR-4.3 | รองรับภาษาไทยในทุกข้อความ error/notification |

### NFR-5: Maintainability & Portability
| ID | Requirement |
|----|-------------|
| NFR-5.1 | Codebase ใช้ PHP Native ไม่ผูก framework ภายนอก เพื่อให้ deploy ได้ในทุก XAMPP environment โดยไม่ต้องพึ่ง Composer dependency จำนวนมาก |
| NFR-5.2 | Config การเชื่อมต่อ database/storage ต้องแยกจาก source code (ไฟล์ `.env` หรือ `config/*.php` ที่ไม่ commit เข้า version control) |
| NFR-5.3 | ระบบต้องรองรับการย้ายไปเครื่องใหม่ได้ง่าย โดย export/import การตั้งค่า Job ทั้งหมดเป็นไฟล์ JSON ได้ |

### NFR-6: Compatibility
| ID | Requirement |
|----|-------------|
| NFR-6.1 | รองรับ XAMPP บน Windows Server 2016/2019/2022 และ Windows 10/11 |
| NFR-6.2 | รองรับ PHP 8.1, 8.2, 8.3 |
| NFR-6.3 | รองรับ MySQL 5.7+ และ MariaDB 10.4+ |
| NFR-6.4 | Web UI รองรับ Chrome, Edge, Firefox เวอร์ชันล่าสุด |

---

## 3. Use Case Diagram (สรุปข้อความ)

```
Actor: Admin
  └─ Manage Storage Targets
  └─ Manage Users & Roles
  └─ Create/Edit/Delete Backup Job
  └─ View Audit Log

Actor: Operator
  └─ Create/Edit Backup Job (เฉพาะที่ได้รับสิทธิ์)
  └─ Trigger Backup Now
  └─ Restore (Dry-run / Real)
  └─ View Backup History

Actor: Viewer
  └─ View Dashboard
  └─ View Backup History (read-only)

Actor: System (Scheduler)
  └─ Trigger Scheduled Backup (ผ่าน CLI)
  └─ Auto Cleanup ตาม Retention Policy
  └─ Auto Verification หลัง backup
```
