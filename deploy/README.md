# นำขึ้นเซิร์ฟเวอร์ DirectAdmin

## โครงสร้างบนเซิร์ฟเวอร์

```
domains/smart-goals.premium-care.in.th/
├── public_html/      ← โค้ดทั้งหมด (ซับโดเมนชี้มาที่นี่)
└── Smart-Private/    ← .env และ storage — อยู่นอกเว็บรูท
```

`public_html/.htaccess` ปิดไม่ให้เปิด `app/`, `config/`, `vendor/`, `.env` ผ่านเว็บ
แล้ว rewrite ทุก request เข้า `public/index.php`

`bootstrap/private-path.php` เป็นตัวบอก Laravel ว่า `.env` กับ `storage` อยู่ที่ไหน

---

## สร้างชุดอัป

รันจาก **PowerShell** ในโฟลเดอร์โปรเจกต์:

```powershell
npm.cmd run deploy:zip
```

> ต้องเป็น PowerShell ไม่ใช่ Git Bash — tar ของ MSYS อ่าน `D:` เป็นชื่อโฮสต์แล้วล้ม

สคริปต์จะ build Vite ก่อน แล้วสร้าง `smart-goal-update-*.zip` ไว้ที่ `deploy-output/`

### สิ่งที่ไม่อยู่ในชุดอัป — และเหตุผล

| ไม่รวม | เพราะ |
|---|---|
| `vendor/` | ผูกกับ `composer.lock` ถ้าไม่เปลี่ยนก็ไม่ต้องอัป (ประหยัดจาก 64 MB เหลือ ~2 MB) |
| `bootstrap/private-path.php` | เป็นไฟล์ของเซิร์ฟเวอร์เท่านั้น ถ้าอัปทับจะไปเขียนทับ path ที่ตั้งไว้ |
| `.env`, `storage/` | อยู่ใน `Smart-Private` ไม่เคยอยู่ใน `public_html` |
| `database/database.sqlite` | ฐานข้อมูลของเครื่องพัฒนา มีข้อมูลทดสอบอยู่ข้างใน |
| `tests/`, `phpunit.xml`, `.claude`, `CLAUDE.md` | ไม่ได้ใช้ตอนรันจริง |

### `bootstrap/app.php` ถูกแปลงให้อัตโนมัติ

ชุดพัฒนากับเซิร์ฟเวอร์ต่างกันสามบรรทัด (โค้ดชี้ไป `Smart-Private`)
`build-update.mjs` เติมให้เองตอนสร้างชุด จึงมีแหล่งความจริงเดียวคือชุดพัฒนา
และไม่มีทางอัปไฟล์ที่ทำเว็บล่มขึ้นไปโดยไม่ตั้งใจ — สคริปต์ตรวจซ้ำก่อนสร้าง ZIP เสมอ

---

## ขั้นตอนอัป

### 1. สำรองก่อน

- **ฐานข้อมูล** — phpMyAdmin › เลือกฐาน › Export › Quick › SQL
- **โค้ด** — ดาวน์โหลด `public_html` เก็บไว้ หรือ Compress ไว้บนเซิร์ฟเวอร์

### 2. อัปฐานข้อมูล (ทำก่อนอัปไฟล์)

ตรวจก่อนว่ายังไม่เคยรัน:

```sql
SELECT migration FROM migrations ORDER BY id DESC LIMIT 5;
```

ถ้าแถวบนสุดคือ `2026_09_03_000001_restore_missing_work_order_subtasks_table`
ให้เปิด phpMyAdmin › แท็บ SQL › วางไฟล์ `2026-09-08-upgrade.sql` ทั้งไฟล์ › Go

ไฟล์นั้นแทนคำสั่ง `php artisan migrate` สำหรับเซิร์ฟเวอร์ที่ไม่มี SSH
ทุกคำสั่งเป็นการ "เพิ่ม" เท่านั้น ไม่ลบหรือแก้ข้อมูลเดิม และรันซ้ำได้โดยไม่เสียหาย

**ต้องอัปฐานข้อมูลก่อนอัปไฟล์** เพราะโค้ดใหม่อ้างถึงตารางและคอลัมน์ที่ยังไม่มี
ถ้าสลับลำดับ เว็บจะขึ้น 500 ระหว่างที่ยังไม่ได้รัน SQL

### 3. อัปไฟล์

อัป ZIP เข้า `public_html` แล้ว Extract แบบเขียนทับ

### 4. ล้างไฟล์ที่ระบบสร้างไว้

ไม่มี SSH จึงต้องลบด้วย File Manager:

- `public_html/bootstrap/cache/` — ลบไฟล์ `.php` ทุกไฟล์ (เก็บ `.gitignore` ไว้)
- `Smart-Private/storage/framework/views/` — ลบไฟล์ `.php` ทุกไฟล์

Laravel สร้างใหม่ให้เองในการเปิดหน้าครั้งถัดไป
ถ้าไม่ลบ หน้าจออาจยังเป็นเทมเพลตเก่าที่คอมไพล์ไว้

### 5. ตรวจว่าใช้งานได้

| หน้า | ต้องเห็น |
|---|---|
| `/my-tasks` | เปิดได้ ไม่ขึ้น 500 |
| `/daily-logs` | เมนู "บันทึกงานประจำวัน" (ของใหม่) |
| `/workspace` | เมนู "กระดานไอเดีย" (ของใหม่) |
| `/reports/operational` | รายงานภาระงานปฏิบัติการ (ของใหม่) |
| `/admin/audit` | แท็บถังขยะมีปุ่มลบถาวร |

แนบไฟล์ในคอมเมนต์งานหนึ่งครั้ง แล้วลบ — ไฟล์ต้องยังเปิดได้จากถังขยะ

---

## ถ้ามีการเพิ่ม/ลบ Composer package

ชุดอัปไม่มี `vendor/` เมื่อ `composer.lock` เปลี่ยน ต้องอัป `vendor/` แยกต่างหาก
ก่อนอัปโค้ดรุ่นใหม่ มิฉะนั้นจะเกิด "Class not found"

รอบนี้ `composer.json` และ `composer.lock` **ไม่เปลี่ยน** จึงไม่ต้องแตะ `vendor/`
