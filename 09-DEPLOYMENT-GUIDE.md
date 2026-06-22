# DEPLOYMENT-GUIDE — ขั้นตอนติดตั้งระบบบน XAMPP
## BRS: XAMPP Backup & Restore System

---

## 1. ความต้องการระบบ (Prerequisites)

| รายการ | ขั้นต่ำ |
|--------|---------|
| OS | Windows Server 2016+ หรือ Windows 10/11 |
| XAMPP | เวอร์ชันที่มี PHP 8.1+ และ MariaDB 10.4+ |
| PHP Extensions ที่ต้องเปิด | `zip`, `openssl`, `pdo_mysql`, `mbstring`, `fileinfo`, `curl` |
| Disk Space | ขั้นต่ำ 3 เท่าของขนาดข้อมูลรวมที่จะ backup (เผื่อ temp + retention) |
| สิทธิ์ผู้ติดตั้ง | Administrator (สำหรับตั้งค่า Task Scheduler และ NTFS permission) |

## 2. ขั้นตอนติดตั้ง

### Step 1: เตรียม PHP Extensions
เปิดไฟล์ `C:\xampp\php\php.ini` ตรวจสอบและเอา `;` ออกหน้าบรรทัดเหล่านี้:
```ini
extension=zip
extension=openssl
extension=pdo_mysql
extension=mbstring
extension=fileinfo
extension=curl
```
รีสตาร์ท Apache ผ่าน XAMPP Control Panel

### Step 2: วาง Source Code
```
copy โฟลเดอร์ brs ทั้งหมดไปที่ C:\xampp\htdocs\brs\
```
**สำคัญ:** ตรวจสอบว่า `public/` คือจุดเดียวที่เข้าถึงผ่าน browser ได้ — ตั้งค่า Apache VirtualHost ให้ DocumentRoot ชี้ไปที่ `public/` โดยตรง เพื่อไม่ให้เข้าถึง `config/`, `storage/`, `lib/` ผ่าน URL ได้เลย:

```apache
# C:\xampp\apache\conf\extra\httpd-vhosts.conf
<VirtualHost *:80>
    ServerName brs.local
    DocumentRoot "C:\xampp\htdocs\brs\public"
    <Directory "C:\xampp\htdocs\brs\public">
        AllowOverride All
        Require all granted
    </Directory>
</VirtualHost>
```
เพิ่มใน `C:\Windows\System32\drivers\etc\hosts`:
```
127.0.0.1   brs.local
```

### Step 3: สร้างฐานข้อมูล
```bash
C:\xampp\mysql\bin\mysql.exe -u root -p < C:\xampp\htdocs\brs\sql\schema.sql
```
(ใช้ DDL จาก `04-DATABASE-SCHEMA.md`)

### Step 4: ตั้งค่า Config
คัดลอก `config/app.config.example.php` เป็น `config/app.config.php` แล้วแก้ไข:
```php
return [
    'db_host' => 'localhost',
    'db_name' => 'brs_system',
    'db_user' => 'brs_app',          // แนะนำสร้าง MySQL user เฉพาะ ไม่ใช้ root
    'db_pass' => 'STRONG_PASSWORD_HERE',
    'session_timeout_minutes' => 30,
    'line_notify_default_token' => '', // ตั้งเป็นค่า default ขององค์กร (override ได้ต่อ user)
    'mysqldump_path' => 'C:\\xampp\\mysql\\bin\\mysqldump.exe',
    'mysql_path' => 'C:\\xampp\\mysql\\bin\\mysql.exe',
];
```

สร้าง MySQL user เฉพาะสำหรับระบบ (ไม่ใช้ root):
```sql
CREATE USER 'brs_app'@'localhost' IDENTIFIED BY 'STRONG_PASSWORD_HERE';
GRANT ALL PRIVILEGES ON brs_system.* TO 'brs_app'@'localhost';
FLUSH PRIVILEGES;
```

### Step 5: สร้าง Encryption Key
```bash
C:\xampp\php\php.exe C:\xampp\htdocs\brs\cli\generate-key.php
```
สคริปต์นี้จะสุ่ม master key 256-bit และเขียนลง `config/encryption.key` อัตโนมัติ
**ทำทันที:** สำรอง `encryption.key` ไปเก็บไว้ที่ปลอดภัยนอกระบบ (เช่น password manager องค์กร) — ถ้าไฟล์นี้หาย backup ที่เข้ารหัสไว้จะกู้คืนไม่ได้เลย

### Step 6: ตั้งค่าสิทธิ์ Folder (NTFS Permission)
```powershell
icacls "C:\xampp\htdocs\brs\config" /inheritance:r /grant:r "NETWORK SERVICE:(OI)(CI)F" /grant:r "Administrators:(OI)(CI)F"
icacls "C:\xampp\htdocs\brs\storage" /inheritance:r /grant:r "NETWORK SERVICE:(OI)(CI)F" /grant:r "Administrators:(OI)(CI)F"
icacls "C:\xampp\htdocs\brs\logs" /inheritance:r /grant:r "NETWORK SERVICE:(OI)(CI)F" /grant:r "Administrators:(OI)(CI)F"
```
(ปรับชื่อ account ตาม service account จริงที่ใช้รัน Apache)

### Step 7: สร้าง Admin User แรก
```bash
C:\xampp\php\php.exe C:\xampp\htdocs\brs\cli\create-admin.php --username=admin --password=ChangeMe123!
```
**เปลี่ยนรหัสผ่านทันที** หลัง login ครั้งแรกผ่าน Web UI

### Step 8: ทดสอบ Healthcheck
```bash
C:\xampp\php\php.exe C:\xampp\htdocs\brs\cli\healthcheck.php
```
ต้องเห็นผลลัพธ์ `OK` ทุกรายการก่อนใช้งานจริง

### Step 9: ตั้งค่า Storage Target แรก
เข้า Web UI → Storage Targets → Add New → เลือก Local Disk เป็นค่าเริ่มต้น → กด "Test Connection" ให้ผ่านก่อน

### Step 10: สร้าง Backup Job แรกและทดสอบ
1. สร้าง Job ใหม่ระบุ path เว็บแอปและฐานข้อมูลที่ต้องการ
2. กด "Backup Now" เพื่อทดสอบทันที (อย่ารอ schedule)
3. ตรวจสอบสถานะใน History ว่า `verification_status = passed`
4. ทดสอบ **Dry-Run Restore** อย่างน้อย 1 ครั้งเพื่อยืนยันว่า backup ใช้งานได้จริง (ดู DISASTER-RECOVERY.md)

### Step 11: ตั้งเวลาอัตโนมัติ
ทำตาม `08-CLI-GUIDE.md` หัวข้อ Windows Task Scheduler

---

## 3. Post-Deployment Checklist

- [ ] PHP extensions ครบทุกตัว (`healthcheck.php` ผ่าน)
- [ ] DocumentRoot ชี้ที่ `public/` เท่านั้น (ทดสอบเข้า URL `/config/app.config.php` ต้องได้ 403/404)
- [ ] เปลี่ยนรหัสผ่าน default admin แล้ว
- [ ] สำรอง `encryption.key` ไว้นอกระบบแล้ว
- [ ] NTFS permission ของ `config/`, `storage/`, `logs/` ถูกจำกัดแล้ว
- [ ] สร้าง MySQL user เฉพาะระบบ (ไม่ใช้ root) แล้ว
- [ ] ทดสอบ backup จริง 1 job และผ่าน verification แล้ว
- [ ] ทดสอบ dry-run restore แล้ว
- [ ] ตั้ง Task Scheduler ครบทั้ง 4 task (backup/cleanup/verify/healthcheck)
- [ ] ตั้งค่า LINE Notify และทดสอบรับการแจ้งเตือนแล้ว
- [ ] เพิ่ม storage target สำรอง (NAS/Cloud) อย่างน้อย 1 ปลายทางสำหรับระบบสำคัญ

---

## 4. Upgrade / Migration ไปเครื่องใหม่

1. Export การตั้งค่า Job ทั้งหมดเป็น JSON ผ่าน Web UI (Settings → Export Configuration)
2. Backup ฐานข้อมูล `brs_system` เอง (ใช้ BRS backup ตัวมันเองตามที่แนะนำใน SECURITY.md ข้อ 5.6)
3. ติดตั้งระบบใหม่ตามขั้นตอนข้างต้นบนเครื่องใหม่
4. คัดลอก `encryption.key` เดิมมาด้วย (จำเป็นเพื่อ decrypt backup เก่าที่ยังเก็บอยู่)
5. Import การตั้งค่า Job จาก JSON ที่ export ไว้
6. ทดสอบ verify backup ประวัติเก่าว่ายัง decrypt/extract ได้ปกติ
