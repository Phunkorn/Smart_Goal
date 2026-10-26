-- =====================================================================
-- Smart Ticket — migration 2026_10_23_000001 .. 000008 + 2026_10_24_000001 (MySQL 8)
-- สถานะ: ฉบับร่าง (DRAFT) · สร้างจาก `php artisan migrate --pretend` · ยังไม่เคยรันบน production
-- ห้ามรันจนกว่าจะได้รับอนุมัติ — ดู docs/plans/smart-ticket-release.md
--
-- ก่อนรัน
--   1. สำรองฐานข้อมูล (phpMyAdmin › Export › Quick › SQL)
--   2. รันคำสั่งตรวจนี้ก่อน ต้องได้ 0 — ถ้าไม่ใช่ 0 ห้ามรันไฟล์นี้ (เคยติดตั้งไปแล้วบางส่วน):
--        SELECT COUNT(*) FROM migrations WHERE migration LIKE '2026_10_23_%' OR migration = '2026_10_24_000001_add_public_contact_fields_to_tickets_table';
--   3. ต้องมีตาราง users และ departments อยู่แล้ว (มีอยู่ในระบบเดิม)
--
-- ไฟล์นี้ไม่ใช่แบบรันซ้ำได้ (ต่างจาก 2026-09-09-FULL-database.sql) — รันครั้งเดียวทั้งไฟล์
-- คำสั่ง DDL ของ MySQL commit ทีละคำสั่ง ถ้าหยุดกลางทาง: อย่ารันใหม่ทั้งไฟล์
-- ให้ดูคำสั่งที่ล้ม แล้วรันต่อจากคำสั่งนั้น หรือกู้จากไฟล์สำรอง
--
-- ผลต่อข้อมูลเดิม: สร้างตารางใหม่ 6 ตาราง, เพิ่มคอลัมน์ departments.ticket_responsible
-- (ค่าเริ่มต้น 0), ไม่ลบหรือแก้แถวเดิมของระบบใด ๆ
-- =====================================================================


-- 2026_10_23_000001_create_ticket_locations_table
create table `ticket_locations` (`id` bigint unsigned not null auto_increment primary key, `name` varchar(150) not null, `is_active` tinyint(1) not null default '1', `sort_order` smallint unsigned not null default '0', `created_at` timestamp null, `updated_at` timestamp null) default character set utf8mb4 collate 'utf8mb4_unicode_ci';
alter table `ticket_locations` add unique `ticket_locations_name_unique`(`name`);
-- 2026_10_23_000002_create_tickets_table
create table `tickets` (`id` bigint unsigned not null auto_increment primary key, `ticket_number` varchar(20) null, `source` varchar(10) not null, `description` text not null, `status` varchar(20) not null default 'new', `requester_id` bigint unsigned null, `requester_name` varchar(150) not null, `requester_department_id` bigint unsigned null, `requester_department_name` varchar(150) null, `contact` varchar(150) null, `location_id` bigint unsigned null, `location_name` varchar(150) not null, `coordinator_id` bigint unsigned null, `follow_up_date` date null, `first_responded_at` timestamp null, `closed_at` timestamp null, `closed_by` bigint unsigned null, `reopen_count` smallint unsigned not null default '0', `submitter_ip` varchar(45) null, `created_at` timestamp null, `updated_at` timestamp null) default character set utf8mb4 collate 'utf8mb4_unicode_ci';
alter table `tickets` add constraint `tickets_requester_id_foreign` foreign key (`requester_id`) references `users` (`id`) on delete set null;
alter table `tickets` add constraint `tickets_requester_department_id_foreign` foreign key (`requester_department_id`) references `departments` (`id`) on delete set null;
alter table `tickets` add constraint `tickets_location_id_foreign` foreign key (`location_id`) references `ticket_locations` (`id`) on delete set null;
alter table `tickets` add constraint `tickets_coordinator_id_foreign` foreign key (`coordinator_id`) references `users` (`id`) on delete set null;
alter table `tickets` add constraint `tickets_closed_by_foreign` foreign key (`closed_by`) references `users` (`id`) on delete set null;
alter table `tickets` add index `tickets_status_created_at_index`(`status`, `created_at`);
alter table `tickets` add unique `tickets_ticket_number_unique`(`ticket_number`);
-- 2026_10_23_000003_create_ticket_participants_table
create table `ticket_participants` (`id` bigint unsigned not null auto_increment primary key, `ticket_id` bigint unsigned not null, `user_id` bigint unsigned null, `user_name` varchar(150) not null, `joined_at` timestamp not null, `left_at` timestamp null, `created_at` timestamp null, `updated_at` timestamp null) default character set utf8mb4 collate 'utf8mb4_unicode_ci';
alter table `ticket_participants` add constraint `ticket_participants_ticket_id_foreign` foreign key (`ticket_id`) references `tickets` (`id`) on delete restrict;
alter table `ticket_participants` add constraint `ticket_participants_user_id_foreign` foreign key (`user_id`) references `users` (`id`) on delete set null;
alter table `ticket_participants` add unique `ticket_participants_ticket_id_user_id_unique`(`ticket_id`, `user_id`);
-- 2026_10_23_000004_create_ticket_activities_table
create table `ticket_activities` (`id` bigint unsigned not null auto_increment primary key, `ticket_id` bigint unsigned not null, `user_id` bigint unsigned null, `user_name` varchar(150) not null, `type` varchar(30) not null, `from_status` varchar(20) null, `to_status` varchar(20) null, `body` text null, `data` json null, `created_at` timestamp not null default CURRENT_TIMESTAMP) default character set utf8mb4 collate 'utf8mb4_unicode_ci';
alter table `ticket_activities` add constraint `ticket_activities_ticket_id_foreign` foreign key (`ticket_id`) references `tickets` (`id`) on delete restrict;
alter table `ticket_activities` add constraint `ticket_activities_user_id_foreign` foreign key (`user_id`) references `users` (`id`) on delete set null;
alter table `ticket_activities` add index `ticket_activities_ticket_id_created_at_index`(`ticket_id`, `created_at`);
-- 2026_10_23_000005_create_ticket_attachments_table
create table `ticket_attachments` (`id` bigint unsigned not null auto_increment primary key, `ticket_id` bigint unsigned not null, `ticket_activity_id` bigint unsigned null, `file_path` varchar(255) not null, `original_name` varchar(255) not null, `file_type` varchar(100) not null, `file_size` int unsigned not null, `uploaded_by` bigint unsigned null, `created_at` timestamp null, `updated_at` timestamp null) default character set utf8mb4 collate 'utf8mb4_unicode_ci';
alter table `ticket_attachments` add constraint `ticket_attachments_ticket_id_foreign` foreign key (`ticket_id`) references `tickets` (`id`) on delete restrict;
alter table `ticket_attachments` add constraint `ticket_attachments_ticket_activity_id_foreign` foreign key (`ticket_activity_id`) references `ticket_activities` (`id`) on delete set null;
alter table `ticket_attachments` add constraint `ticket_attachments_uploaded_by_foreign` foreign key (`uploaded_by`) references `users` (`id`) on delete set null;
-- 2026_10_23_000006_create_ticket_agents_table
create table `ticket_agents` (`id` bigint unsigned not null auto_increment primary key, `user_id` bigint unsigned not null, `is_enabled` tinyint(1) null, `receives_alerts` tinyint(1) not null default '1', `updated_by` bigint unsigned null, `created_at` timestamp null, `updated_at` timestamp null) default character set utf8mb4 collate 'utf8mb4_unicode_ci';
alter table `ticket_agents` add constraint `ticket_agents_user_id_foreign` foreign key (`user_id`) references `users` (`id`) on delete cascade;
alter table `ticket_agents` add constraint `ticket_agents_updated_by_foreign` foreign key (`updated_by`) references `users` (`id`) on delete set null;
alter table `ticket_agents` add unique `ticket_agents_user_id_unique`(`user_id`);
-- 2026_10_23_000007_add_ticket_responsible_to_departments_table
alter table `departments` add `ticket_responsible` tinyint(1) not null default '0' after `department_name`;
-- 2026_10_23_000008_add_identity_refs_to_ticket_history
alter table `ticket_participants` add `user_ref_id` bigint unsigned null after `user_id`;
alter table `ticket_participants` add index `ticket_participants_user_ref_id_index`(`user_ref_id`);
update `ticket_participants` set `user_ref_id` = user_id where `user_id` is not null and `user_ref_id` is null;
alter table `ticket_activities` add `user_ref_id` bigint unsigned null after `user_id`;
alter table `ticket_activities` add index `ticket_activities_user_ref_id_index`(`user_ref_id`);
update `ticket_activities` set `user_ref_id` = user_id where `user_id` is not null and `user_ref_id` is null;
alter table `tickets` add `closed_by_ref_id` bigint unsigned null after `closed_by`;
alter table `tickets` add index `tickets_closed_by_ref_id_index`(`closed_by_ref_id`);
update `tickets` set `closed_by_ref_id` = closed_by where `closed_by` is not null and `closed_by_ref_id` is null;

-- 2026_10_24_000001 — ฟอร์มสาธารณะ: รหัสรีโมท และบัญชี LINE ของผู้แจ้ง (หนึ่งบัญชี LINE มี Ticket ที่ยังไม่ปิดได้ใบเดียว)
alter table `tickets` add `remote_access` varchar(100) null after `contact`;
alter table `tickets` add `line_user_id` varchar(64) null after `remote_access`;
alter table `tickets` add `line_display_name` varchar(150) null after `line_user_id`;
alter table `tickets` add index `tickets_line_user_status_index`(`line_user_id`, `status`);
-- หนึ่งบัญชี LINE มี Ticket ที่ยังไม่ปิดได้ใบเดียว: คอลัมน์คำนวณ (VIRTUAL) + unique — Ticket เดิมทั้งหมดได้ค่า NULL ไม่ชนกัน
alter table `tickets` add `open_line_user_id` varchar(64) as (case when status <> 'closed' then line_user_id end) after `line_display_name`;
alter table `tickets` add unique `tickets_open_line_user_unique`(`open_line_user_id`);

-- บันทึกว่า migration ทั้ง 9 รันแล้ว (batch ถัดไป)
SET @batch = (SELECT COALESCE(MAX(batch), 0) + 1 FROM migrations);
INSERT INTO migrations (migration, batch) VALUES ('2026_10_23_000001_create_ticket_locations_table', @batch);
INSERT INTO migrations (migration, batch) VALUES ('2026_10_23_000002_create_tickets_table', @batch);
INSERT INTO migrations (migration, batch) VALUES ('2026_10_23_000003_create_ticket_participants_table', @batch);
INSERT INTO migrations (migration, batch) VALUES ('2026_10_23_000004_create_ticket_activities_table', @batch);
INSERT INTO migrations (migration, batch) VALUES ('2026_10_23_000005_create_ticket_attachments_table', @batch);
INSERT INTO migrations (migration, batch) VALUES ('2026_10_23_000006_create_ticket_agents_table', @batch);
INSERT INTO migrations (migration, batch) VALUES ('2026_10_23_000007_add_ticket_responsible_to_departments_table', @batch);
INSERT INTO migrations (migration, batch) VALUES ('2026_10_23_000008_add_identity_refs_to_ticket_history', @batch);
INSERT INTO migrations (migration, batch) VALUES ('2026_10_24_000001_add_public_contact_fields_to_tickets_table', @batch);
