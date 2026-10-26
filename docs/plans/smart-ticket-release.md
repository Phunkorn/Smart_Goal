# Smart Ticket — Pre-Release Verification & Production Runbook

> สถานะ: **READY WITH CONDITIONS** · เอกสารเตรียมการ ยังไม่มีการ Deploy, Migrate หรือตั้ง Cron บน production
> จัดทำ 2026-10-22 · ผู้อ่าน: เจ้าของระบบ และผู้ดูแลเซิร์ฟเวอร์ production (DirectAdmin)
> ประกอบกับ: `docs/plans/smart-ticket.md` (แผนและผลแต่ละ Phase), `deploy/README.md` (ขั้นตอนอัปเดิม), `deploy/2026-10-23-smart-ticket.sql` (SQL ฉบับร่าง)

สัญลักษณ์: ✅ ยืนยันแล้วจากหลักฐาน · ⚠️ พบความเสี่ยง · ❓ ยังไม่ได้ตรวจ ต้องให้ผู้ดูแล production ตรวจ

---

## 1. Migration Verification (ฐาน dev)

| รายการ | ผล | หลักฐาน |
|---|---|---|
| สถานะ migration | ✅ `2026_10_23_000001–000007` = batch 39, `000008` = batch 40 (แถว id 90 แถวเดียวใน batch) | `migrate:status`, ตาราง `migrations` (อ่านอย่างเดียว) |
| Schema ตรงกับไฟล์ migration | ✅ `ticket_participants.user_ref_id`, `ticket_activities.user_ref_id`, `tickets.closed_by_ref_id` เป็น `bigint unsigned NULL` มี index ชื่อ `*_user_ref_id_index` / `tickets_closed_by_ref_id_index` และ **ไม่มี foreign key** ตรงตามไฟล์ | `Schema::getColumns/getIndexes/getForeignKeys` |
| คำสั่งที่รันจริง | ✅ binary log (`binlog.000114`) มีคำสั่ง `alter table … add user_ref_id bigint unsigned null after user_id`, `add index …`, `add closed_by_ref_id …` รูปแบบตรงกับที่ Laravel schema builder สร้างจากไฟล์ 000008 | `SHOW BINLOG EVENTS` |
| เวลาที่รัน | ✅ **2026-10-22 10:55:03 UTC** (17:55:03 เวลาเครื่องฐานข้อมูล = กรุงเทพ) — `CREATE_TIME` ของสามตารางถูกรีเซ็ตพร้อมกันจากการ ALTER | `information_schema.TABLES` |
| ข้อมูลที่ถูก backfill | ✅ ฐาน dev ไม่มีแถว Ticket (0 แถวทั้งสามตาราง) จึงไม่มีอะไรถูกคัดลอก · `tickets:identity-check` ไม่พบปัญหา | คิวรีนับแถว (อ่านอย่างเดียว) |
| ใคร/กระบวนการใดรัน | **ไม่ทราบสาเหตุ** — ดูหลักฐานด้านล่าง | — |

หลักฐานที่ตรวจเพื่อหาผู้รัน (ไม่สามารถระบุได้ จึงไม่สรุป):

- Claude Code session นี้: เรียก `artisan migrate` เฉพาะแบบ `--pretend` และ `migrate:status` · ไม่มีการทำงานใดระหว่าง 10:25:12–10:56:18 UTC (รอข้อความผู้ใช้) ซึ่งครอบเวลา 10:55:03 · สถานะเป็น Pending ตอน 10:19 UTC และ Ran ตอน 11:54 UTC
- Claude Code session อื่นของโปรเจกต์นี้บนเครื่องนี้: ไม่มีคำสั่ง `artisan migrate` ในวันที่ 2026-10-22
- `storage/logs/laravel.log`: Laravel ไม่บันทึกการ migrate ตามปกติ · ไม่มีรายการในช่วงเวลานั้น
- MySQL general log ปิด · binary log ไม่บันทึกชื่อผู้ใช้หรือเครื่องที่สั่ง
- `composer setup` (มี `migrate --force`) ไม่ถูกรัน — `vendor/` และ `node_modules/` ไม่เปลี่ยนตั้งแต่ ส.ค.
- ไม่มี schedule หรือสคริปต์อัตโนมัติใน repo ที่รัน migrate

**ไม่ได้ Rollback และไม่ได้ Migrate ซ้ำ**

---

## 2. Production Environment Findings

ไม่มีสิทธิ์เข้าถึง production (ไม่มี SSH/DirectAdmin ในเครื่องนี้) — ข้อมูลด้านล่างมาจากเอกสารใน repo, ไฟล์ lock และการเปิดหน้าเว็บสาธารณะแบบอ่านอย่างเดียว 1 ครั้ง (`GET /up`)

| รายการ | ผล | แหล่ง |
|---|---|---|
| โดเมน | `smart-goals.premium-care.in.th` | deploy/README.md |
| Production project path | ❓ ตามเอกสาร: `/home/<DA_USER>/domains/smart-goals.premium-care.in.th/public_html` (โค้ดทั้งหมด) และ `…/Smart-Private/` (`.env`, `storage`) — ไม่ทราบชื่อผู้ใช้ DirectAdmin | deploy/README.md |
| Web server | ✅ หน้าเว็บตอบ `Server: nginx`, `/up` = 200 · ❓ โหมดของ DirectAdmin (nginx หน้า Apache หรือ nginx + PHP-FPM ตรง) — เอกสารใช้ `.htaccess` ซึ่งบ่งว่ามี Apache | `curl -I`, deploy/README.md |
| Cloudflare | ⚠️ **production ไม่ได้อยู่หลัง Cloudflare** — DNS ชี้ตรงไป `103.233.193.32` (ไม่อยู่ในช่วง IP ของ Cloudflare) และไม่มี header `cf-ray` แม้คอมเมนต์ใน `bootstrap/app.php` จะเขียนว่า "Cloudflare Tunnel" | `nslookup`, `curl -I` |
| PHP (web) | ❓ ต้องเป็น ≥ 8.2 (`composer.json`) — เครื่องพัฒนาใช้ 8.3.30 | composer.json |
| PHP CLI path | ❓ ไม่ทราบ — เอกสารยกตัวอย่าง `/usr/local/bin/php` ต้องยืนยันจาก DirectAdmin | deploy/README.md |
| Laravel | ✅ `v12.62.0` (จาก `composer.lock` ซึ่งไม่เปลี่ยนตั้งแต่ 2026-08-18 จึงตรงกับ `vendor/` บน production ถ้า deploy ล่าสุดหลังวันนั้น) | composer.lock |
| MySQL/MariaDB | ❓ ไม่ทราบรุ่น production (dev = MySQL 8.4.3, เอกสารเคยทดสอบ SQL กับ 8.0.46) | — |
| Database config | ❓ อยู่ใน `Smart-Private/.env` (ไม่ได้อ่าน) · `CACHE_STORE` ค่าเริ่ม `database` (ล็อกของ scheduler ใช้ตาราง `cache_locks`) | config/cache.php |
| Cron ที่มีอยู่ | ❓ ไม่ทราบ — เอกสารระบุว่า production ไม่เคยตั้ง cron · ตรวจได้จากแถบสถานะในหน้า `/admin/audit` | deploy/README.md, SchedulerHeartbeat |
| File permissions | ❓ ต้องตรวจว่าเจ้าของ `Smart-Private/storage` เป็นผู้ใช้เดียวกับที่ cron รัน | — |

### 2.1 ขั้นตอนให้ผู้ดูแล production ตรวจเอง (ไม่แก้อะไร)

| # | ตรวจ | วิธี (DirectAdmin / phpMyAdmin) | ต้องได้ |
|---|---|---|---|
| E1 | ชื่อผู้ใช้และ path | DirectAdmin › File Manager | path จริงของ `public_html` และ `Smart-Private` |
| E2 | PHP ของเว็บ | DirectAdmin › PHP Version Selector / Select PHP version | ≥ 8.2 |
| E3 | PHP CLI สำหรับ cron | DirectAdmin › Cron Jobs (หน้าแสดงตัวอย่าง path) หรือสอบถามผู้ให้บริการ — ทดสอบด้วย cron ครั้งเดียว `<PHP_CLI> -v > ~/php-cli-version.txt 2>&1` | รุ่นเดียวกับเว็บ (≥ 8.2) |
| E4 | รุ่นฐานข้อมูล | phpMyAdmin › SQL: `SELECT VERSION();` | MySQL ≥ 8.0 หรือ MariaDB ≥ 10.2.4 |
| E5 | ตัวนับ id ผู้ใช้ | `SELECT AUTO_INCREMENT FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users';` และ `SELECT MAX(id) FROM users;` | ตัวนับ > id ที่เคยใช้สูงสุด (ดู §2.2) |
| E6 | migration ที่ production มี | `SELECT migration FROM migrations ORDER BY id DESC LIMIT 30;` | เทียบกับ `database/migrations/` ของชุดที่จะอัป (§4 ขั้น 3) |
| E7 | สิทธิ์ information_schema | `SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE();` | ได้ตัวเลข |
| E8 | ตาราง cache_locks | `SHOW TABLES LIKE 'cache_locks';` | มี 1 แถว (scheduler ใช้ล็อกกันซ้อน) |
| E9 | cron เดิม | หน้า `/admin/audit` แท็บภาพรวม + DirectAdmin › Cron Jobs | ทราบว่ามี/ไม่มี |
| E10 | โหมด web server | DirectAdmin › CustomBuild › ค่า `webserver` (`nginx_apache` / `nginx` / `apache` / `litespeed`) | ใช้ประเมิน §2.3 |
| E11 | เจ้าของไฟล์ | File Manager › `Smart-Private/storage` (owner/permission) | เจ้าของ = ผู้ใช้ DirectAdmin ที่ cron จะรัน |

### 2.2 ความเสี่ยง User ID Reuse (ตัวตนทางประวัติไม่มี FK)

`user_ref_id` / `closed_by_ref_id` เก็บ `users.id` โดยไม่มี foreign key ถ้า id ของบัญชีที่ถูกลบถาวรถูกจ่ายให้บัญชีใหม่ ประวัติของคนเก่าจะถูกนับเป็นของคนใหม่ในรายงาน

| สถานการณ์ | เสี่ยงเมื่อใด | ป้องกัน |
|---|---|---|
| Force delete แล้ว restart ฐาน | MySQL < 8.0 / MariaDB < 10.2.4 ตั้งตัวนับใหม่เป็น `MAX(id)+1` หลัง restart — ถ้าบัญชี id สูงสุดถูกลบถาวร id นั้นถูกใช้ซ้ำ | ใช้รุ่นที่เก็บตัวนับถาวร (E4) |
| Restore ฐานข้อมูล | กู้ **เฉพาะตาราง `users`** หรือกู้คนละช่วงเวลากับตาราง Ticket | กู้ทั้งฐานพร้อมกันเสมอ ห้ามกู้ทีละตาราง |
| Counter reset | `ALTER TABLE users AUTO_INCREMENT = …` ค่าต่ำกว่าที่เคยใช้ / `TRUNCATE users` | ห้ามทำบน production |
| Table rebuild | `OPTIMIZE`/`ALTER … ENGINE=InnoDB` เก็บตัวนับไว้ (ปลอดภัย) แต่การ dump แล้ว import โดยไม่มี `AUTO_INCREMENT=` ทำให้ตัวนับกลับเป็น `MAX(id)+1` | import ให้คงค่า `AUTO_INCREMENT` เดิม |
| Manual import ผู้ใช้ | นำเข้าแถวพร้อมระบุ `id` เอง | นำเข้าโดยไม่ระบุ id |
| สร้างผู้ใช้ใหม่ตามปกติ | ไม่เสี่ยง ตัวนับเพิ่มขึ้นเสมอ | — |

หมายเหตุ: บัญชีถูกลบถาวรได้สองทาง — ปุ่มลบถาวรในถังขยะ (admin) และ `trash:purge-expired` (ทำงานเมื่อมี cron)

ตรวจจับย้อนหลัง (หลังกู้คืน/นำเข้า ทุกครั้ง):
- มี SSH: `php artisan tickets:identity-check` (exit 1 เมื่อพบ)
- ไม่มี SSH (phpMyAdmin):
  ```sql
  SELECT p.user_ref_id, u.name, MIN(p.created_at) AS first_history, u.created_at AS account_created
  FROM ticket_participants p JOIN users u ON u.id = p.user_ref_id
  GROUP BY p.user_ref_id, u.name, u.created_at
  HAVING MIN(p.created_at) < u.created_at;
  ```
  ไม่มีแถว = ปกติ

ทางป้องกันระยะยาว (ต้องอนุมัติ schema แยก): เพิ่ม `users.uuid` ที่ไม่เปลี่ยน แล้วใช้เป็นตัวอ้างอิงแทน id

### 2.3 ⚠️ IP ของผู้ใช้กับ `trustProxies('*')`

`bootstrap/app.php` เชื่อ `X-Forwarded-For` จากทุกแหล่ง (คอมเมนต์ระบุว่าเพื่อ Cloudflare Tunnel) แต่ production ไม่ได้อยู่หลัง Cloudflare (§2)

- ถ้า E10 = `nginx_apache` (nginx ส่งต่อให้ Apache และต่อท้าย IP จริงใน `X-Forwarded-For`) Laravel ใช้ IP ขวาสุดซึ่งเป็น IP จริง → **ปลอมไม่ได้**
- ถ้า E10 = `nginx` แบบ PHP-FPM ตรง หรือ `apache` ตรง → ผู้ใช้ส่ง `X-Forwarded-For` เองได้ → **ปลอม IP ได้** ซึ่งกระทบ:
  - การล็อกเข้าสู่ระบบ: `AuthController` ล็อกด้วย `username|ip` → เปลี่ยน IP ปลอมเพื่อเลี่ยงการล็อก 5 ครั้งได้ (**ปัญหาเดิมของ Smart Goals ไม่ใช่ของ Smart Ticket**)
  - rate limit ของ `/support` และ `submitter_ip` ที่บันทึก
- ถ้าเข้าข่ายข้อ 2: เสนอให้จำกัด trusted proxies เป็น IP ของ proxy จริง (เช่น `127.0.0.1`) — เป็นการแก้โค้ดกลาง ต้องอนุมัติแยก
- ไม่ได้ทดสอบโดยส่งคำขอเข้าสู่ระบบไปยัง production

---

## 3. DirectAdmin Cron Setup Guide (ยังไม่ตั้ง — รออนุมัติ)

### 3.1 สิ่งที่ตรวจในโค้ดแล้ว ✅

| หัวข้อ | สถานะ |
|---|---|
| Scheduler | `telegram:drain` ทุกนาที, `withoutOverlapping(10)` — ล็อกหมดอายุใน 10 นาที (แก้จากค่าเริ่ม 24 ชม. ในรอบนี้ มีเทสต์) |
| Retry backoff | ลองใหม่หลัง 1 / 5 / 15 / 30 นาที สูงสุด 5 ครั้ง (`TELEGRAM_MAX_ATTEMPTS`) |
| Timeout | HTTP timeout 8 วินาที (`TELEGRAM_TIMEOUT`) · รอบละไม่เกิน 20 วินาที (`TELEGRAM_DRAIN_SECONDS`) · ไปไม่ถึง Telegram = หยุดรอบและคืนข้อความเข้าคิวโดยไม่นับครั้ง |
| Failed logging | `Log::warning` ทุกข้อความที่ส่งไม่สำเร็จ (id, รหัส error, ครั้งที่) + `last_error` ในตาราง · ไม่มีเนื้อความใน log |
| Duplicate protection | จองแถวด้วย `lockForUpdate` + สถานะ `sending` ก่อนส่ง · แถวที่ค้าง `sending` > 5 นาทีถูกดึงกลับ · dedupe ต่อการแจ้งเตือน · (ข้อจำกัดของ Telegram: ถ้า timeout หลัง Telegram รับไปแล้ว อาจได้ซ้ำหนึ่งครั้ง) |
| ตรวจคิว | `php artisan telegram:status` (exit 1 เมื่อค้าง > 10 นาที) และแถบเตือนในหน้า `/admin/audit` |

### 3.2 ⚠️ ก่อนเลือกคำสั่ง: `schedule:run` เปิดงานทุกตัว ไม่ใช่แค่ Telegram

ถ้า production ไม่เคยมี cron งานเหล่านี้จะเริ่มทำงานครั้งแรก และบางตัว **ลบข้อมูลถาวร**:

| งาน | เวลา (กรุงเทพ) | ผล |
|---|---|---|
| `trash:purge-expired` | **09:30** ทุกวัน (ตั้ง `02:30` ไม่ระบุ timezone = UTC) | **ลบถาวร** ของในถังขยะที่ครบ 30 วัน รวมบัญชีผู้ใช้ |
| `audit:prune-activity` | 03:00 | ลบบันทึกกิจกรรมที่พ้นอายุ |
| `notifications:prune` | 02:45 | ลบการแจ้งเตือนที่อ่านแล้วเกิน 90 วัน |
| `notifications:generate-deadlines` | 00:05 | สร้างแจ้งเตือนงานถึงกำหนด/เลยกำหนด |
| `worklogs:materialize-routines` / `close-stale-timers` | 00:10 / 00:20 | สร้างรายการงานประจำ / ปิดตัวจับเวลาค้าง |
| `worklogs:close-routine-day` | 17:00 | ปิดรอบงานประจำ |
| `worklogs:notify-routines` | ทุก 5 นาที | แจ้งเตือนงานประจำ |
| `telegram:drain` | ทุกนาที | ส่ง Telegram ที่ค้าง |
| heartbeat | ทุก 5 นาที | บอกหน้า Audit ว่า cron ทำงาน |

ก่อนเปิด `schedule:run` ให้ดูจำนวนที่จะถูกลบในหน้า `/admin/audit` (ปุ่ม "ล้างของหมดอายุ (N)" / "ล้างบันทึกเก่า (N)") และ export สำรองไว้

### 3.3 คำสั่ง (แทน `<DA_USER>` และ `<PHP_CLI>` ด้วยค่าจริงจาก E1/E3 — ยังไม่ได้ยืนยัน)

> หมายเหตุ: ตัวอย่างใน `deploy/README.md` ใช้ `…/laravel` ซึ่งไม่ตรงกับโครงสร้างจริง (`public_html`)

**ขั้น A — เฉพาะ Telegram (แนะนำให้เริ่มที่นี่):** DirectAdmin › Advanced Features › Cron Jobs › Minute/Hour/Day/Month/Weekday = `*` ทั้งหมด

```
cd /home/<DA_USER>/domains/smart-goals.premium-care.in.th/public_html && <PHP_CLI> artisan telegram:drain >> /home/<DA_USER>/cron-telegram.log 2>&1
```

ไม่เรียก scheduler จึงไม่เปิดงานลบข้อมูลใด ๆ · รอบหนึ่งไม่เกิน ~20 วินาที จึงไม่ซ้อนกัน และถึงซ้อนก็ไม่ส่งซ้ำ (จองแถวก่อนส่ง)

**ขั้น B — Scheduler เต็ม (หลังอนุมัติงานใน §3.2):** ลบ cron ขั้น A แล้วตั้ง

```
cd /home/<DA_USER>/domains/smart-goals.premium-care.in.th/public_html && <PHP_CLI> artisan schedule:run >> /dev/null 2>&1
```

### 3.4 ตรวจว่าทำงานจริง

- ขั้น A: ไฟล์ `~/cron-telegram.log` มีบรรทัด `Delivered N queued Telegram message(s).` ทุกนาที · หน้า `/admin/audit` ไม่มีแถบเตือน Telegram · เมื่อมั่นใจแล้วเปลี่ยน `>> …log` เป็น `>> /dev/null` เพื่อไม่ให้ log โต
- ขั้น B: หน้า `/admin/audit` แถบบนสุดเป็นสีเขียว "งานอัตโนมัติทำงานปกติ" ภายใน 5 นาที

### 3.5 ปิดเมื่อเกิดปัญหา

1. DirectAdmin › Cron Jobs › เลือกรายการ › Delete (มีผลรอบถัดไปทันที)
2. ถ้าต้องหยุดส่ง Telegram ทั้งหมด: `Smart-Private/.env` ตั้ง `TELEGRAM_NOTIFICATIONS_ENABLED=false` แล้วลบ `public_html/bootstrap/cache/config.php` (ถ้ามี)
3. ข้อความที่ค้างยังอยู่ในตาราง `telegram_messages` ไม่หาย เปิดกลับเมื่อพร้อม

### 3.6 สิทธิ์ไฟล์

cron ของ DirectAdmin รันเป็นผู้ใช้ของบัญชี — `Smart-Private/storage/logs` และ `storage/framework/cache` ต้องเขียนได้โดยผู้ใช้นั้น และไฟล์ที่ cron สร้าง (เช่น `laravel.log`) ต้องไม่ทำให้เว็บเขียนไม่ได้ ตรวจ E11 หลังรัน cron ครั้งแรก

---

## 4. Deployment Checklist (รออนุมัติ — ห้ามทำก่อน)

ลำดับนี้ทำให้ Smart Goals เดิมไม่หยุด: การเปลี่ยนฐานข้อมูลของ Smart Ticket เป็นการ **เพิ่มเท่านั้น** (ตารางใหม่ + คอลัมน์ใหม่ที่มีค่าเริ่มต้น) โค้ดเดิมไม่สนใจของเหล่านี้ และโค้ดใหม่ตอบ "ยังไม่ติดตั้ง" (503) เฉพาะหน้า Ticket หากฐานยังไม่ครบ หน้าอื่นใช้งานได้ปกติ (ทดสอบแล้ว)

| # | ขั้นตอน | รายละเอียด | ตรวจผ่านเมื่อ |
|---|---|---|---|
| 1 | Pre-deployment verification | ทำ E1–E11 (§2.1) · เลือกช่วงคนใช้น้อย · แจ้งผู้ใช้ | ทุกข้อมีคำตอบ ไม่มีข้อขัดข้อง |
| 2 | Backup | phpMyAdmin › Export ทั้งฐาน (Quick › SQL) · บีบอัด `public_html` และ `Smart-Private/storage` · ดาวน์โหลดเก็บนอกเซิร์ฟเวอร์ | เปิดไฟล์สำรองได้ ขนาดสมเหตุสมผล |
| 3 | ตรวจ pending migrations | E6 เทียบกับรายชื่อใน `database/migrations/` ของชุดที่จะอัป — **ต้องรวม migration อื่นที่ไม่ใช่ Ticket** ที่ production ยังไม่มี (ถ้ามี ต้องเตรียม SQL แยก) | รายการที่ขาดตรงกับที่วางแผน |
| 4 | ตรวจ SQL ทั้ง 9 migration (รวม `2026_10_24_000001` รหัสรีโมท/LINE) | อ่าน `deploy/2026-10-23-smart-ticket.sql` · ยืนยันว่าคำสั่งตรวจที่หัวไฟล์ได้ 0 · ไฟล์สร้างด้วย grammar MySQL ของ Laravel แต่ **ยังไม่เคยรันกับ MySQL production** | ผู้ตรวจลงชื่อรับรอง |
| 5 | เตรียมโค้ดและ assets | PowerShell: `npm.cmd run deploy:zip` (build Vite + ZIP) · `vendor/` ไม่ต้องอัปถ้า `composer.lock` ตรงกับ production | ได้ไฟล์ `deploy-output/smart-goal-update-*.zip` |
| 6 | Database migration | phpMyAdmin › SQL › วางไฟล์จากขั้น 4 › Go (ตาม `deploy/README.md`: ฐานข้อมูลก่อนไฟล์) | `migrations` มี `2026_10_23_000001–000008` · หน้าเดิมยังเปิดได้ |
| 7 | Deploy code & assets | อัป ZIP เข้า `public_html` › Extract เขียนทับ (ไม่แตะ `Smart-Private`, `bootstrap/private-path.php`) | ไฟล์ครบ ไม่มี error ตอนแตก |
| 8 | Cache rebuild | ลบ `public_html/bootstrap/cache/*.php` และ `Smart-Private/storage/framework/views/*.php` (เก็บ `.gitignore`) — สถานะติดตั้งของ Ticket ไม่จำผลลบ จึงเห็นผลทันที | `/my-tasks`, `/board`, `/reports/projects` เปิดได้ · เมนู "แจ้งปัญหา IT" ขึ้นสำหรับพนักงาน |
| 9 | Admin Ticket settings | `/admin/tickets`: เลือกแผนกที่รับผิดชอบ, เพิ่มสาขา, ตรวจรายชื่อ agent และการรับแจ้งเตือน | agent ที่ถูกต้องเห็นเมนูและป้ายจำนวน |
| 10 | Telegram cron | ทำ §3 ขั้น A (ต้องได้อนุมัติแยก) | §3.4 ผ่าน |
| 11 | Permission verification | ตาราง §5 แถว "สิทธิ์ / IDOR" ด้วยบัญชีจริงแต่ละบทบาท | ทุกแถว PASS |
| 12 | E2E smoke test | ตาราง §5 ทั้งหมด | ไม่มี FAIL ในแถวที่จำเป็นต่อการเปิดใช้ |
| R | Recovery | §7 | — |

---

## 5. Smoke / Final Acceptance Checklist

คอลัมน์ "ก่อน release" คือผลจากการทดสอบอัตโนมัติและ headless Chrome บนเครื่องพัฒนา · คอลัมน์ "Production" ให้ผู้ทดสอบกรอกหลัง deploy พร้อมหลักฐาน (ภาพหน้าจอ/เลข Ticket/เวลา)

| หัวข้อ | สิ่งที่ทดสอบ | ก่อน release | หลักฐาน | Production |
|---|---|---|---|---|
| Public ticket | เปิด `/support` ไม่ login · กรอก 3 ช่อง · แนบรูป · ได้เลข Ticket · ส่งซ้ำไม่เกิดใบใหม่ | PASS | `SmartTicketEndToEndTest` Scenario B, `TicketPublicSubmissionTest` | NOT TESTED |
| Internal ticket | ชื่อ/แผนกดึงจากบัญชี (ปลอมใน form ไม่ได้) · เลข Ticket | PASS | Scenario A | NOT TESTED |
| Agent รับงาน/เข้าร่วมหลายคน | รับงาน · เข้าร่วมซ้ำ/ออก/กลับเข้า ไม่ซ้ำแถว · ผู้ประสานงาน | PASS | Scenario C, `TicketParticipantTest` | NOT TESTED |
| Follow-up / Close / Reopen | เหตุผลบังคับ · กดพร้อมกันได้ 409 · reopen นับครั้ง | PASS | Scenario A, C | NOT TESTED |
| Notification Center | หมวด Ticket · ลิงก์ตรวจสิทธิ์ตอนเปิด | PASS | Scenario E, `TicketNotificationTest` | NOT TESTED |
| Telegram จริง | agent ที่ผูก Telegram ได้ข้อความ Ticket ใหม่ ไม่มี IP/ช่องทางติดต่อ · ปิดเครือข่ายแล้วส่งตามภายหลัง | **NOT TESTED** (ทดสอบกับ Telegram ปลอมเท่านั้น) | `TelegramNotificationDeliveryTest` | NOT TESTED |
| เสียงแจ้งเตือน | ดังครั้งเดียวเมื่อเปิดสองแท็บ · ไม่คลิกหน้า = ไม่ดัง ไม่ error · ปุ่มปิดเสียง | PASS (headless Chrome จริง) | CDP harness: 2 แท็บ = 1 เสียง | NOT TESTED |
| Admin settings | แผนก/agent/สาขา · เพิ่ม agent ซ้ำพร้อมกันไม่ 500 | PASS | `TicketSettingsPageTest` | NOT TESTED |
| Reports & KPI | ทีม = Ticket ละ 1 · รายบุคคลไม่รวมชื่อซ้ำ · บัญชีลบถาวรรวมเป็นคนเดียว · SLA ไม่แสดงเมื่อไม่ตั้ง | PASS | `TicketReportTest`, Scenario D | NOT TESTED |
| สิทธิ์ / IDOR | viewer, พนักงาน, หัวหน้าแผนก A↔B, agent ถูกปิดสิทธิ์, admin · เปิด URL ตรง · รูปแนบผ่าน policy | PASS | `TicketPolicyTest`, `TicketVisibilityTest`, `TicketActionRoutesTest`, Scenario B/C | NOT TESTED |
| Mobile responsive | 320–1440 px ไม่ล้น ไม่ซ้อน ปุ่ม ≥ 40 px | PASS (headless Chrome desktop emulation) | 186 รอบวัด | **NOT TESTED บนอุปกรณ์จริง** |
| หน้าเดิมของ Smart Goals | `/my-tasks`, `/board`, รายงานเดิม เปิดได้ระหว่าง/หลัง deploy | PASS | `TicketNotInstalledTest`, `TicketReportTest` | NOT TESTED |

---

## 6. Privacy Retention (ข้อเสนอ รออนุมัติ — ยังไม่ลบข้อมูลใด)

| ข้อมูล | ที่เก็บ | ปัจจุบัน | ข้อเสนอ |
|---|---|---|---|
| IP ผู้แจ้งภายนอก | `tickets.submitter_ip` | เก็บถาวร | ล้างเป็น NULL เมื่อ Ticket อายุ 90 วัน (ใช้กันสแปมช่วงสั้นเท่านั้น) |
| ช่องทางติดต่อผู้แจ้งภายนอก | `tickets.contact` | เก็บถาวร | เก็บจนปิดงาน + 1 ปี แล้วล้าง |
| ข้อความ Telegram | `telegram_messages.text` (มีสรุปปัญหา สาขา เวลา) | เก็บถาวร | ลบแถวที่ส่งสำเร็จเมื่อครบ 30 วัน · แถวล้มเหลวเมื่อครบ 90 วัน |
| ชื่อผู้แจ้ง/ผู้เข้าร่วม (snapshot) และตัวตนทางประวัติ | `tickets`, `ticket_participants`, `ticket_activities` | เก็บตลอดอายุ Ticket | คงไว้ — จำเป็นต่อประวัติและ KPI |
| รูปแนบ | `Smart-Private/storage/app/ticket-attachments` | เก็บถาวร · ลบ GPS/EXIF แล้วตอนอัป | กำหนดตามนโยบายเอกสารขององค์กร |

ถ้าอนุมัติ: ทำเป็นคำสั่ง artisan ใน scheduler พร้อม dry-run และ AuditTrail — แยกงาน

---

## 7. Rollback & Recovery Plan

**หลักการ: ย้อนโค้ดก่อนเสมอ กู้ฐานข้อมูลเป็นทางสุดท้าย และห้ามลบตาราง Ticket ที่มีข้อมูล** (`down()` ของ migration ปฏิเสธเองเมื่อมีข้อมูล)

| สถานการณ์ | ทำอะไร | ผลต่อข้อมูล |
|---|---|---|
| หน้าเดิมของ Smart Goals ผิดปกติหลังอัปโค้ด | แตก `public_html` จากไฟล์สำรอง (ขั้น 2) ทับ แล้วล้าง cache (ขั้น 8) | ไม่กระทบฐาน · ตาราง Ticket ยังอยู่ โค้ดเดิมไม่ใช้ |
| Smart Ticket ผิดปกติแต่หน้าอื่นปกติ | ย้อนโค้ดเหมือนข้อบน หรือรอแก้ — ไม่ต้องแตะฐาน | Ticket ที่บันทึกแล้วยังอยู่ ใช้ต่อได้เมื่อขึ้นโค้ดใหม่ |
| SQL ขั้น 6 ล้มกลางทาง | อย่ารันทั้งไฟล์ซ้ำ · ดูคำสั่งที่ล้ม แก้สาเหตุ แล้วรันต่อจากคำสั่งนั้น · ถ้าไม่แน่ใจ กู้ฐานจากขั้น 2 (ยังไม่มีข้อมูล Ticket ใหม่) | เฉพาะส่วนที่เพิ่มใหม่ |
| Telegram ส่งผิดพลาด/ซ้ำ | ปิด cron (§3.5) | ข้อความในคิวไม่หาย |
| ข้อมูลเสียหายจริง (หลังเปิดใช้แล้ว) | กู้ฐานจากขั้น 2 **เฉพาะเมื่อจำเป็น** — export ตาราง `ticket_*` และ `telegram_messages` ก่อน เพราะการกู้จะทำให้ Ticket ที่เกิดหลัง deploy หาย · กู้ทั้งฐานพร้อมกันเท่านั้น (§2.2) · แล้วรัน identity check | ข้อมูลหลังเวลาสำรองหาย |

---

## 8. Release Conditions

| # | เงื่อนไข | สถานะ |
|---|---|---|
| C1 | ผลตรวจ E1–E11 ครบ (path, PHP CLI, รุ่นฐาน, โหมด web server, cron เดิม, สิทธิ์ไฟล์) | ❓ รอผู้ดูแล production |
| C2 | รุ่นฐานข้อมูลเก็บตัวนับ AUTO_INCREMENT ถาวร และขั้นตอนกู้คืน/นำเข้าตาม §2.2 | ❓ |
| C3 | ประเมิน `trustProxies('*')` ตาม E10 (§2.3) — ถ้าปลอม IP ได้ ต้องตัดสินใจแก้ก่อนเปิด `/support` สาธารณะ | ❓ |
| C4 | ตรวจทานและอนุมัติ SQL `deploy/2026-10-23-smart-ticket.sql` + migration อื่นที่ production ยังขาด (ขั้น 3) | รออนุมัติ |
| C5 | อนุมัติ cron ขั้น A (และขั้น B แยก พร้อมรับทราบงานลบข้อมูลใน §3.2) | รออนุมัติ |
| C6 | ทดสอบ Telegram จริงหนึ่งรอบบน production | NOT TESTED |
| C7 | ทดสอบบนมือถือจริง (iOS Safari, Android Chrome) | NOT TESTED |
| C8 | ตัดสินใจนโยบาย retention (§6) — ไม่บังคับก่อนเปิดใช้ | รออนุมัติ |

## 9. รออนุมัติแยก

1. การตั้ง Cron บน production (ขั้น A และขั้น B แยกกัน)
2. การรัน Database Migration บน production
3. การ Deploy Smart Goals เวอร์ชันใหม่
