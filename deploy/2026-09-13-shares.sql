-- =============================================================================
-- Smart Goal — SQL อัปเดตฐานข้อมูล: แชร์งาน และการเลิกใช้ความสำคัญระดับ routine
-- (Deploy รอบ 5 — 2026-09-13)
-- =============================================================================
--
-- ไฟล์นี้แทนคำสั่ง `php artisan migrate` สำหรับเซิร์ฟเวอร์ DirectAdmin ที่ไม่มี SSH
-- ครอบคลุม migration 4 ตัว
--   2026_09_11_000001_create_work_order_shares_table
--   2026_09_11_000002_create_work_order_share_requests_table
--   2026_09_11_000003_add_head_decision_to_work_order_share_requests
--   2026_09_12_000001_retire_routine_task_priority
--
-- สิ่งที่ไฟล์นี้ทำ
--   1) สร้างตาราง work_order_shares (ประกาศแชร์งาน)
--   2) สร้างตาราง work_order_share_requests (คำขอเข้าร่วมงานที่ถูกแชร์)
--   3) เพิ่มคอลัมน์การตัดสินชั้นที่สองของหัวหน้าแผนก (head_decided_by / head_decided_at)
--   4) ย้ายงานที่ความสำคัญ = 1 (routine ที่เลิกใช้แล้ว) ไปเป็น 5 (ไม่รีบ ไม่มีกำหนด)
--   5) บันทึกลงตาราง migrations เพื่อให้ Laravel รู้ว่ารันไปแล้ว
--
-- ทุกคำสั่งข้ามของที่มีอยู่แล้วให้เอง จึงรันซ้ำได้อย่างปลอดภัย
-- ไม่มีการลบตาราง คอลัมน์ หรือแถวใดทั้งสิ้น
--
-- -----------------------------------------------------------------------------
-- ก่อนรัน
-- -----------------------------------------------------------------------------
--   1. สำรองฐานข้อมูลก่อนเสมอ (phpMyAdmin > Export > Quick > SQL)
--   2. ต้องรันไฟล์ของรอบก่อนหน้าให้ครบแล้ว โดยเฉพาะ
--      2026_09_10_000001_convert_legacy_midnight_schedules_to_business_time
--      ตรวจด้วย: SELECT migration FROM migrations ORDER BY id DESC LIMIT 5;
--   3. รันใน phpMyAdmin > เลือกฐานข้อมูล > แท็บ SQL > วางทั้งไฟล์ > Go
--   4. ต้องรัน SQL ให้เสร็จ "ก่อน" อัปไฟล์โค้ด มิฉะนั้นเว็บจะขึ้น 500
--      เพราะโค้ดใหม่อ้างถึงตารางที่ยังไม่มี
--
-- -----------------------------------------------------------------------------
-- ข้อควรรู้เรื่องข้อ 4 (ความสำคัญ routine)
-- -----------------------------------------------------------------------------
-- ย้อนกลับไม่ได้ หลังรันแล้วงานที่เคยเป็น routine จะแยกจากงานที่ผู้ใช้ตั้งเป็น
-- "ไม่รีบ ไม่มีกำหนด" เองไม่ได้อีก เพราะทั้งสองกลุ่มมีค่าเท่ากันทุกประการ
-- ถ้าต้องการเก็บหลักฐานว่างานใดเคยเป็น routine ให้ export ก่อนด้วย
--   SELECT job_id, job_topic FROM work_orders WHERE job_priority = 1;
-- =============================================================================

SET @db := DATABASE();


-- =============================================================================
-- 1) ตาราง work_order_shares — ประกาศแชร์งาน
--
-- work_orders ใช้ job_id เป็น primary key คีย์นอกจึงอ้างไปที่ job_id ไม่ใช่ id
--
-- ไม่มี UNIQUE ที่ work_order_id เพราะงานที่เคยปิดประกาศแล้วต้องแชร์ใหม่ได้
-- กติกา "หนึ่งงานมีประกาศที่เปิดอยู่ได้ใบเดียว" บังคับในโค้ดด้วยการล็อกแถวก่อนสร้าง
-- =============================================================================

CREATE TABLE IF NOT EXISTS `work_order_shares` (
    `id`             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `work_order_id`  BIGINT UNSIGNED NOT NULL,
    `shared_by`      BIGINT UNSIGNED NOT NULL,
    `department_id`  BIGINT UNSIGNED NULL DEFAULT NULL,
    `scope`          VARCHAR(20) NOT NULL,
    `status`         VARCHAR(20) NOT NULL DEFAULT 'open',
    `note`           TEXT NULL DEFAULT NULL,
    `closed_at`      TIMESTAMP NULL DEFAULT NULL,
    `created_at`     TIMESTAMP NULL DEFAULT NULL,
    `updated_at`     TIMESTAMP NULL DEFAULT NULL,
    PRIMARY KEY (`id`),
    KEY `work_order_shares_audience_idx` (`status`, `scope`, `department_id`),
    KEY `work_order_shares_work_order_id_status_index` (`work_order_id`, `status`),
    KEY `work_order_shares_shared_by_foreign` (`shared_by`),
    KEY `work_order_shares_department_id_foreign` (`department_id`),
    CONSTRAINT `work_order_shares_work_order_id_foreign`
        FOREIGN KEY (`work_order_id`) REFERENCES `work_orders` (`job_id`) ON DELETE CASCADE,
    CONSTRAINT `work_order_shares_shared_by_foreign`
        FOREIGN KEY (`shared_by`) REFERENCES `users` (`id`) ON DELETE CASCADE,
    CONSTRAINT `work_order_shares_department_id_foreign`
        FOREIGN KEY (`department_id`) REFERENCES `departments` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- =============================================================================
-- 2) ตาราง work_order_share_requests — คำขอเข้าร่วมงานที่ถูกแชร์
--
-- collaborator_status เก็บผลที่ได้ตอนผู้แชร์อนุมัติ ('accepted' หรือ 'pending')
-- เพราะการอนุมัติของผู้แชร์ไม่ได้แปลว่าจบเสมอ คำขอข้ามแผนกยังต้องผ่านหัวหน้าแผนก
-- อีกชั้น ถ้าไม่เก็บค่านี้ ผู้ขอจะเห็นว่า "อนุมัติแล้ว" แต่เปิดงานไม่เจอ
--
-- head_decided_by / head_decided_at (migration ตัวที่ 3) ถูกใส่มาในคำสั่ง CREATE นี้
-- ด้วยเลย สำหรับฐานที่ยังไม่มีตาราง ส่วนฐานที่มีตารางแล้วจะได้จากข้อ 3 ด้านล่าง
-- =============================================================================

CREATE TABLE IF NOT EXISTS `work_order_share_requests` (
    `id`                    BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `work_order_share_id`   BIGINT UNSIGNED NOT NULL,
    `requester_id`          BIGINT UNSIGNED NOT NULL,
    `status`                VARCHAR(20) NOT NULL DEFAULT 'pending',
    `decided_by`            BIGINT UNSIGNED NULL DEFAULT NULL,
    `decided_at`            TIMESTAMP NULL DEFAULT NULL,
    `head_decided_by`       BIGINT UNSIGNED NULL DEFAULT NULL,
    `head_decided_at`       TIMESTAMP NULL DEFAULT NULL,
    `decision_reason`       TEXT NULL DEFAULT NULL,
    `collaborator_status`   VARCHAR(20) NULL DEFAULT NULL,
    `created_at`            TIMESTAMP NULL DEFAULT NULL,
    `updated_at`            TIMESTAMP NULL DEFAULT NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `work_order_share_requests_unique` (`work_order_share_id`, `requester_id`),
    KEY `work_order_share_requests_requester_id_status_index` (`requester_id`, `status`),
    KEY `work_order_share_requests_decided_by_foreign` (`decided_by`),
    KEY `work_order_share_requests_head_decided_by_foreign` (`head_decided_by`),
    CONSTRAINT `wosr_share_id_foreign`
        FOREIGN KEY (`work_order_share_id`) REFERENCES `work_order_shares` (`id`) ON DELETE CASCADE,
    CONSTRAINT `wosr_requester_id_foreign`
        FOREIGN KEY (`requester_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
    CONSTRAINT `wosr_decided_by_foreign`
        FOREIGN KEY (`decided_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
    CONSTRAINT `wosr_head_decided_by_foreign`
        FOREIGN KEY (`head_decided_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- =============================================================================
-- 3) คอลัมน์การตัดสินชั้นที่สองของหัวหน้าแผนก
--
-- สำหรับฐานที่สร้างตารางในข้อ 2 ไปแล้วรอบก่อน (จึงยังไม่มีสองคอลัมน์นี้)
-- MySQL 8 ไม่รองรับ ADD COLUMN IF NOT EXISTS จึงต้องตรวจ information_schema ก่อน
--
-- ไม่ทับ decided_by / decided_at ซึ่งเป็นของผู้แชร์ ไม่งั้นจะไม่เหลือหลักฐานว่าใคร
-- เป็นคนรับผู้ขอเข้ามาตั้งแต่แรก ซึ่งเป็นคำถามแรกเวลาย้อนตรวจ
-- =============================================================================

SET @has_head_by := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = @db
      AND TABLE_NAME = 'work_order_share_requests'
      AND COLUMN_NAME = 'head_decided_by'
);
SET @sql := IF(@has_head_by = 0,
    'ALTER TABLE `work_order_share_requests` ADD COLUMN `head_decided_by` BIGINT UNSIGNED NULL DEFAULT NULL AFTER `decided_at`',
    'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @has_head_at := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = @db
      AND TABLE_NAME = 'work_order_share_requests'
      AND COLUMN_NAME = 'head_decided_at'
);
SET @sql := IF(@has_head_at = 0,
    'ALTER TABLE `work_order_share_requests` ADD COLUMN `head_decided_at` TIMESTAMP NULL DEFAULT NULL AFTER `head_decided_by`',
    'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @has_head_index := (
    SELECT COUNT(*) FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = @db
      AND TABLE_NAME = 'work_order_share_requests'
      AND INDEX_NAME = 'work_order_share_requests_head_decided_by_foreign'
);
SET @sql := IF(@has_head_index = 0,
    'ALTER TABLE `work_order_share_requests` ADD INDEX `work_order_share_requests_head_decided_by_foreign` (`head_decided_by`)',
    'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @has_head_fk := (
    SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
    WHERE TABLE_SCHEMA = @db
      AND TABLE_NAME = 'work_order_share_requests'
      AND CONSTRAINT_NAME = 'wosr_head_decided_by_foreign'
);
SET @sql := IF(@has_head_fk = 0,
    'ALTER TABLE `work_order_share_requests` ADD CONSTRAINT `wosr_head_decided_by_foreign` FOREIGN KEY (`head_decided_by`) REFERENCES `users` (`id`) ON DELETE SET NULL',
    'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;


-- =============================================================================
-- 4) เลิกใช้ความสำคัญระดับ 1 (routine)
--
-- UI ไม่มีตัวเลือกระดับนี้แล้วและ validation ไม่รับค่า 1 ถ้าไม่ย้ายข้อมูลเก่า
-- งานที่เคยตั้งเป็น routine จะแสดงเป็น "สำคัญไม่ด่วน" บนหน้าจอ ทั้งที่ฐานข้อมูล
-- ยังเก็บเลข 1 อยู่ — และแก้ผ่านหน้าจอไม่ได้เลยเพราะปุ่มของระดับนั้นหายไปแล้ว
--
-- ย้ายไประดับ 5 ("ไม่รีบ ไม่มีกำหนด") ซึ่งใกล้เคียงความหมายเดิมที่สุด
--
-- ความสำคัญของ "โปรเจกต์" (work_order_lists.priority) ใช้สเกลคนละชุด (1-3)
-- และยังใช้ระดับ 1 อยู่ จึงไม่ถูกแตะ
-- =============================================================================

UPDATE `work_orders` SET `job_priority` = 5 WHERE `job_priority` = 1;

SET @has_task_requests := (
    SELECT COUNT(*) FROM information_schema.TABLES
    WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'work_order_list_task_requests'
);
SET @sql := IF(@has_task_requests = 1,
    'UPDATE `work_order_list_task_requests` SET `job_priority` = 5 WHERE `job_priority` = 1',
    'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;


-- =============================================================================
-- 5) บันทึกลงตาราง migrations
--
-- ถ้าไม่ใส่ส่วนนี้ Laravel จะยังคิดว่ายังไม่ได้รัน แล้วพยายามสร้างซ้ำในครั้งถัดไป
-- =============================================================================

SET @batch := (SELECT IFNULL(MAX(`batch`), 0) + 1 FROM `migrations`);

INSERT INTO `migrations` (`migration`, `batch`)
SELECT * FROM (
    SELECT '2026_09_11_000001_create_work_order_shares_table' AS m, @batch AS b
    UNION ALL SELECT '2026_09_11_000002_create_work_order_share_requests_table',        @batch
    UNION ALL SELECT '2026_09_11_000003_add_head_decision_to_work_order_share_requests', @batch
    UNION ALL SELECT '2026_09_12_000001_retire_routine_task_priority',                   @batch
) AS rows_to_add
WHERE NOT EXISTS (
    SELECT 1 FROM `migrations` WHERE `migration` = rows_to_add.m
);


-- =============================================================================
-- ตรวจผลหลังรัน — คัดลอกไปรันแยกได้
-- =============================================================================

-- ควรได้สองตาราง
-- SHOW TABLES LIKE 'work_order_share%';

-- ควรได้ 12 คอลัมน์ รวม head_decided_by และ head_decided_at
-- SHOW COLUMNS FROM `work_order_share_requests`;

-- ต้องได้ 0 ทั้งสองค่า (ไม่เหลืองานความสำคัญระดับ 1)
-- SELECT
--     (SELECT COUNT(*) FROM `work_orders` WHERE `job_priority` = 1) AS `work_orders_routine_left`,
--     (SELECT COUNT(*) FROM `work_order_list_task_requests` WHERE `job_priority` = 1) AS `task_requests_routine_left`;

-- ควรเห็น migration ทั้งสี่ตัวของรอบนี้
-- SELECT `migration`, `batch` FROM `migrations` ORDER BY `id` DESC LIMIT 6;
