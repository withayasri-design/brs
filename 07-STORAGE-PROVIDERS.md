# STORAGE-PROVIDERS — ปลายทางจัดเก็บ Backup
## BRS: XAMPP Backup & Restore System

ระบบรองรับ storage หลายแบบผ่าน **Adapter Pattern** — ทุก provider implement interface เดียวกัน ทำให้ Job หนึ่งสามารถส่ง backup ไปหลายปลายทางพร้อมกันได้ (ตามหลัก 3-2-1)

```php
interface StorageAdapterInterface {
    public function upload(string $localPath, string $remotePath): bool;
    public function download(string $remotePath, string $localPath): bool;
    public function delete(string $remotePath): bool;
    public function exists(string $remotePath): bool;
    public function listFiles(string $prefix): array;
    public function getFreeSpace(): ?int;   // bytes, null ถ้า provider ไม่รองรับการเช็ค
    public function testConnection(): array; // ['status' => 'success'|'failed', 'message' => '...']
}
```

---

## 1. Local Disk Adapter

**ใช้เมื่อ:** ต้องการความเร็วสูงสุดสำหรับ restore ด่วน, เป็น default/primary storage เสมอ

**Config (`config_json`):**
```json
{
  "base_path": "C:\\xampp\\htdocs\\brs\\storage"
}
```

**ข้อควรระวัง:**
- ต้องไม่อยู่ภายใต้ web-accessible document root โดยตรง (ป้องกันคนเข้าถึงผ่าน URL ได้)
- ตั้ง NTFS permission จำกัดเฉพาะ service account
- **ข้อจำกัด:** Local disk เพียงอย่างเดียวไม่ตรงหลัก 3-2-1 (ไม่มี off-site) — แนะนำให้ใช้ร่วมกับ NAS หรือ Cloud เสมอใน production

---

## 2. NAS / Network Share Adapter

**ใช้เมื่อ:** มี NAS หรือเครื่อง file server แยกต่างหากในองค์กร (สื่อที่ 2 ตามหลัก 3-2-1)

**Config:**
```json
{
  "unc_path": "\\\\NAS-SERVER\\backups\\brs",
  "mapped_drive": "Z:",
  "username": "DOMAIN\\backup_svc",
  "password_encrypted": "..."
}
```

**วิธีเชื่อมต่อ:** ใช้ `net use` mapping drive ก่อนเขียนไฟล์ หรือเขียนตรงผ่าน UNC path ด้วย service account ที่มีสิทธิ์
**Test Connection:** เขียนไฟล์ทดสอบขนาดเล็ก + อ่านกลับ + ลบทิ้ง เพื่อยืนยันสิทธิ์ read/write ครบ

---

## 3. AWS S3 (หรือ S3-Compatible) Adapter

**ใช้เมื่อ:** ต้องการ off-site storage ระดับ enterprise, รองรับ versioning/lifecycle policy ในตัว

**Config:**
```json
{
  "bucket": "bsi-backup-offsite",
  "region": "ap-southeast-1",
  "access_key_encrypted": "...",
  "secret_key_encrypted": "...",
  "path_prefix": "brs/",
  "storage_class": "STANDARD_IA"
}
```

**Implementation:** ใช้ AWS SDK for PHP (ผ่าน Composer เฉพาะ adapter นี้ — ไม่กระทบ core ที่เป็น native PHP)
**แนะนำเพิ่มเติม:**
- เปิด S3 Bucket Versioning เพื่อป้องกันการลบ/เขียนทับโดยไม่ตั้งใจ (เป็น safety net อีกชั้น)
- ตั้ง Lifecycle Policy แยกจาก retention policy ของ BRS เอง (ความซ้ำซ้อนที่ตั้งใจ — defense in depth)
- ใช้ IAM user ที่มีสิทธิ์เฉพาะ bucket นี้เท่านั้น (least privilege) ไม่ใช้ root credential

---

## 4. Google Drive Adapter

**ใช้เมื่อ:** องค์กรใช้ Google Workspace อยู่แล้ว ต้องการ off-site storage แบบไม่เพิ่มค่าใช้จ่ายเซิร์ฟเวอร์

**Config:**
```json
{
  "service_account_json_encrypted": "...",
  "shared_drive_folder_id": "1AbCxxxx",
  "use_shared_drive": true
}
```

**Implementation:** ใช้ Google API Client Library for PHP, auth ผ่าน Service Account (ไม่ใช้ OAuth user flow เพื่อให้ automation ทำงานได้โดยไม่ต้อง re-login)
**ข้อจำกัด:** ต้องตรวจสอบ quota ของ Google Drive และจำกัดขนาดไฟล์เดี่ยวตาม API limit (แนะนำ split ไฟล์ใหญ่กว่า 5GB)

---

## 5. SFTP Adapter

**ใช้เมื่อ:** มีเซิร์ฟเวอร์ Linux/SFTP แยกต่างหากสำหรับเก็บ backup โดยเฉพาะ

**Config:**
```json
{
  "host": "backup.internal.bsi.local",
  "port": 22,
  "username": "brs_backup",
  "auth_method": "key",
  "private_key_path_encrypted": "...",
  "remote_base_path": "/backups/brs"
}
```

**แนะนำ:** ใช้ SSH Key-based authentication แทน password เสมอ, จำกัดสิทธิ์ user บนเซิร์ฟเวอร์ปลายทางให้ chroot อยู่ใน backup directory เท่านั้น (ป้องกันแม้ credential รั่วก็เข้าถึงส่วนอื่นไม่ได้)

---

## 6. การเลือก Storage Strategy ตามขนาดองค์กร

| ขนาดระบบ | แนะนำ |
|----------|--------|
| ระบบเล็ก/ทดสอบ | Local Disk เพียงอย่างเดียว (ยอมรับความเสี่ยงได้) |
| ระบบ production ทั่วไป | Local + NAS (สื่อ 2 ตัวในองค์กร) |
| ระบบ critical/สำคัญสูง | Local + NAS + Cloud (ครบหลัก 3-2-1) |
| งบจำกัด ไม่มี NAS | Local + Google Drive (ฟรีหรือต้นทุนต่ำ) |

---

## 7. Storage Target Health Monitoring

- ระบบตรวจสอบ connectivity ของทุก storage target ที่ active อยู่เป็นระยะ (ผ่าน `healthcheck.php` ที่รันใน schedule แยก เช่น ทุก 6 ชั่วโมง)
- หาก target ใด unreachable ติดต่อกัน → แจ้งเตือน admin ทันที เพื่อแก้ไขก่อนถึงรอบ backup จริง ไม่ใช่ไปพบตอน backup ล้มเหลว
