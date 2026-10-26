-- =====================================================================
-- Smart Ticket — migration 2026_10_25_000001 (MySQL 8)
-- ผู้แจ้งภายใน (บัญชี Smart Goals) มี Ticket ที่ยังไม่ปิดได้ใบเดียว: คอลัมน์คำนวณ (VIRTUAL) + unique index
-- สถานะ: ฉบับร่าง (DRAFT) · สร้างจาก `php artisan migrate --pretend` · ยังไม่เคยรันบน production
-- ห้ามรันจนกว่าจะได้รับอนุมัติ
--
-- ก่อนรัน
--   1. สำรองฐานข้อมูล (phpMyAdmin › Export › Quick › SQL)
--   2. ต้องติดตั้ง Smart Ticket แล้ว (deploy/2026-10-23-smart-ticket.sql) — ตรวจ:
--        SELECT migration FROM migrations WHERE migration LIKE '2026_10_2%' ORDER BY migration;
--      ต้องมี 2026_10_24_000001 และยังไม่มี 2026_10_25_000001
--   3. ตรวจเวอร์ชัน: SELECT VERSION();  (MySQL 8.x — ถ้าเป็น MariaDB ต้อง 10.2 ขึ้นไป และแจ้งทีมพัฒนาก่อน)
--   4. ตรวจข้อมูลซ้ำ ต้องได้ 0 แถว — ถ้าไม่ใช่ ให้เจ้าหน้าที่ปิด Ticket ที่ซ้ำก่อน ห้ามรันไฟล์นี้:
--        SELECT requester_id, COUNT(*) AS open_count, GROUP_CONCAT(ticket_number) AS tickets
--        FROM tickets WHERE source = 'internal' AND status <> 'closed' AND requester_id IS NOT NULL
--        GROUP BY requester_id HAVING COUNT(*) > 1;
--
-- ผลต่อข้อมูลเดิม: ไม่มีการเขียนข้อมูล (คอลัมน์ VIRTUAL คำนวณจาก source/status/requester_id)
-- Ticket สาธารณะ / ปิดแล้ว / ผู้แจ้งถูกลบถาวร ได้ค่า NULL ไม่ชนกัน
--
-- ถอยกลับ (ไม่มีข้อมูลหาย):
--   ALTER TABLE `tickets` DROP INDEX `tickets_open_requester_unique`;
--   ALTER TABLE `tickets` DROP COLUMN `open_requester_id`;
--   DELETE FROM migrations WHERE migration = '2026_10_25_000001_add_open_requester_guard_to_tickets_table';
-- =====================================================================

alter table `tickets` add `open_requester_id` bigint unsigned as (case when source = 'internal' and status <> 'closed' then requester_id end) after `requester_id`;
alter table `tickets` add unique `tickets_open_requester_unique`(`open_requester_id`);

SET @batch = (SELECT COALESCE(MAX(batch), 0) + 1 FROM migrations);
INSERT INTO migrations (migration, batch) VALUES ('2026_10_25_000001_add_open_requester_guard_to_tickets_table', @batch);
