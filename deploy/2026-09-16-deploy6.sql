-- =============================================================================
-- Smart Goal — SQL อัปเดตฐานข้อมูล (Deploy รอบ 6 — 2026-09-16)
-- =============================================================================
--
-- ไฟล์นี้แทนคำสั่ง `php artisan migrate` สำหรับเซิร์ฟเวอร์ DirectAdmin ที่ไม่มี SSH
-- ครอบคลุม migration 4 ตัว (ต่อจาก Deploy รอบ 5 ที่จบที่ 2026_09_12_000001)
--   2026_09_14_000001_add_shift_schedule_to_work_log_templates
--   2026_09_15_000001_add_completion_issue_and_shared_copy_to_work_logs
--   2026_09_16_000001_add_parent_job_id_to_work_order_list_task_requests
--   2026_09_16_000001_add_routine_cutoff_and_absence_to_work_logs
--
-- สิ่งที่ไฟล์นี้ทำ
--   1) ตารางเวรงานประจำ: คอลัมน์ work_log_templates.schedule_type + ตาราง work_log_template_shifts
--   2) ปิดงานแบบพบปัญหา + สำเนาผู้ร่วมงานนอกสถานที่: work_logs.has_issue / issue_details / shared_from_work_log_id
--   3) คำขอเพิ่มงานย่อยในโปรเจกต์: work_order_list_task_requests.parent_job_id
--   4) ปิดรอบงานประจำ 17:00 และ "ไม่มา": work_logs.unfinished_reason / absent_marked_by / absent_marked_at /
--      cutoff_closed_at / explained_at และ work_log_templates.accountable_from
--   5) บันทึกลงตาราง migrations เพื่อให้ Laravel รู้ว่ารันไปแล้ว
--
-- ทุกคำสั่งข้ามของที่มีอยู่แล้วให้เอง จึงรันซ้ำได้อย่างปลอดภัย
-- ไม่มีการลบตาราง คอลัมน์ หรือแถวใดทั้งสิ้น
--
-- -----------------------------------------------------------------------------
-- ก่อนรัน
-- -----------------------------------------------------------------------------
--   1. สำรองฐานข้อมูลก่อนเสมอ (phpMyAdmin > Export > Quick > SQL)
--   2. ตรวจว่ารันไฟล์ของรอบ 5 (2026-09-13-shares.sql) ครบแล้ว
--      SELECT migration FROM migrations ORDER BY id DESC LIMIT 5;
--      แถวบนสุดต้องเป็น 2026_09_12_000001_retire_routine_task_priority (หรือใหม่กว่า)
--   3. รันใน phpMyAdmin > เลือกฐานข้อมูล > แท็บ SQL > วางทั้งไฟล์ > Go
--   4. ต้องรัน SQL ให้เสร็จ "ก่อน" อัปไฟล์โค้ด มิฉะนั้นเว็บจะขึ้น 500
--      เพราะโค้ดใหม่อ้างถึงคอลัมน์ที่ยังไม่มี
--
-- -----------------------------------------------------------------------------
-- ข้อควรรู้เรื่องข้อ 4 (accountable_from)
-- -----------------------------------------------------------------------------
-- แม่แบบงานประจำที่มีอยู่แล้วทุกตัวถูกตั้ง accountable_from = เวลาที่รันไฟล์นี้ (UTC)
-- ผลคือไม่มีใครถูกถามเหตุผลย้อนหลังของวันก่อน deploy รอบนี้ ตั้งค่าเฉพาะตอนที่เพิ่มคอลัมน์
-- ครั้งแรกเท่านั้น รันไฟล์ซ้ำจะไม่ทับค่าเดิม
-- =============================================================================

SET @db := DATABASE();


-- =============================================================================
-- 1) ตารางเวรงานประจำ (2026_09_14_000001)
--
-- schedule_type = 'weekly' คือแบบเดิม งานประจำที่มีอยู่ทั้งหมดยังทำงานเหมือนเดิม
-- =============================================================================

SET @has_col := (SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'work_log_templates' AND COLUMN_NAME = 'schedule_type');
SET @sql := IF(@has_col = 0,
    'ALTER TABLE `work_log_templates` ADD COLUMN `schedule_type` VARCHAR(20) NOT NULL DEFAULT ''weekly'' AFTER `kind`',
    'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

CREATE TABLE IF NOT EXISTS `work_log_template_shifts` (
    `id`                    BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `work_log_template_id`  BIGINT UNSIGNED NOT NULL,
    `user_id`               BIGINT UNSIGNED NOT NULL,
    `work_date`             DATE NOT NULL,
    `created_by`            BIGINT UNSIGNED NULL DEFAULT NULL,
    `created_at`            TIMESTAMP NULL DEFAULT NULL,
    `updated_at`            TIMESTAMP NULL DEFAULT NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `work_log_template_shifts_unique` (`work_log_template_id`, `work_date`, `user_id`),
    KEY `work_log_template_shifts_created_by_foreign` (`created_by`),
    KEY `work_log_template_shifts_user_day_index` (`user_id`, `work_date`),
    CONSTRAINT `work_log_template_shifts_created_by_foreign`
        FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
    CONSTRAINT `work_log_template_shifts_user_id_foreign`
        FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
    CONSTRAINT `work_log_template_shifts_work_log_template_id_foreign`
        FOREIGN KEY (`work_log_template_id`) REFERENCES `work_log_templates` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- =============================================================================
-- 2) ปิดงานแบบพบปัญหา และสำเนาผู้ร่วมงานนอกสถานที่ (2026_09_15_000001)
-- =============================================================================

SET @has_col := (SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'work_logs' AND COLUMN_NAME = 'has_issue');
SET @sql := IF(@has_col = 0,
    'ALTER TABLE `work_logs` ADD COLUMN `has_issue` TINYINT(1) NOT NULL DEFAULT 0 AFTER `late_completion_reason`',
    'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @has_col := (SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'work_logs' AND COLUMN_NAME = 'issue_details');
SET @sql := IF(@has_col = 0,
    'ALTER TABLE `work_logs` ADD COLUMN `issue_details` TEXT NULL DEFAULT NULL AFTER `has_issue`',
    'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @has_col := (SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'work_logs' AND COLUMN_NAME = 'shared_from_work_log_id');
SET @sql := IF(@has_col = 0,
    'ALTER TABLE `work_logs` ADD COLUMN `shared_from_work_log_id` BIGINT UNSIGNED NULL DEFAULT NULL AFTER `work_log_template_id`',
    'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @has_idx := (SELECT COUNT(*) FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'work_logs' AND INDEX_NAME = 'work_logs_shared_from_work_log_id_foreign');
SET @sql := IF(@has_idx = 0,
    'ALTER TABLE `work_logs` ADD INDEX `work_logs_shared_from_work_log_id_foreign` (`shared_from_work_log_id`)',
    'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @has_fk := (SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
    WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'work_logs' AND CONSTRAINT_NAME = 'work_logs_shared_from_work_log_id_foreign');
SET @sql := IF(@has_fk = 0,
    'ALTER TABLE `work_logs` ADD CONSTRAINT `work_logs_shared_from_work_log_id_foreign` FOREIGN KEY (`shared_from_work_log_id`) REFERENCES `work_logs` (`id`) ON DELETE SET NULL',
    'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;


-- =============================================================================
-- 3) คำขอเพิ่มงานย่อยในโปรเจกต์ (2026_09_16_000001_add_parent_job_id...)
--
-- work_orders ใช้ job_id เป็น primary key คีย์นอกจึงอ้างไปที่ job_id
-- =============================================================================

SET @has_col := (SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'work_order_list_task_requests' AND COLUMN_NAME = 'parent_job_id');
SET @sql := IF(@has_col = 0,
    'ALTER TABLE `work_order_list_task_requests` ADD COLUMN `parent_job_id` BIGINT UNSIGNED NULL DEFAULT NULL AFTER `work_order_id`',
    'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @has_idx := (SELECT COUNT(*) FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'work_order_list_task_requests' AND INDEX_NAME = 'work_order_list_task_requests_parent_job_id_foreign');
SET @sql := IF(@has_idx = 0,
    'ALTER TABLE `work_order_list_task_requests` ADD INDEX `work_order_list_task_requests_parent_job_id_foreign` (`parent_job_id`)',
    'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @has_fk := (SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
    WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'work_order_list_task_requests' AND CONSTRAINT_NAME = 'work_order_list_task_requests_parent_job_id_foreign');
SET @sql := IF(@has_fk = 0,
    'ALTER TABLE `work_order_list_task_requests` ADD CONSTRAINT `work_order_list_task_requests_parent_job_id_foreign` FOREIGN KEY (`parent_job_id`) REFERENCES `work_orders` (`job_id`) ON DELETE CASCADE',
    'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;


-- =============================================================================
-- 4) ปิดรอบงานประจำ 17:00 และการระบุว่าผู้ร่วมงานไม่มา (2026_09_16_000001_add_routine_cutoff...)
--
-- สถานะใหม่ของ work_logs.status (not_started / unfinished / absent) ไม่ต้องแก้ชนิดคอลัมน์
-- เพราะเป็น VARCHAR(20) อยู่แล้ว
-- =============================================================================

SET @has_col := (SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'work_logs' AND COLUMN_NAME = 'unfinished_reason');
SET @sql := IF(@has_col = 0,
    'ALTER TABLE `work_logs` ADD COLUMN `unfinished_reason` VARCHAR(500) NULL DEFAULT NULL AFTER `skip_reason`',
    'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @has_col := (SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'work_logs' AND COLUMN_NAME = 'absent_marked_by');
SET @sql := IF(@has_col = 0,
    'ALTER TABLE `work_logs` ADD COLUMN `absent_marked_by` BIGINT UNSIGNED NULL DEFAULT NULL AFTER `unfinished_reason`',
    'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @has_idx := (SELECT COUNT(*) FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'work_logs' AND INDEX_NAME = 'work_logs_absent_marked_by_foreign');
SET @sql := IF(@has_idx = 0,
    'ALTER TABLE `work_logs` ADD INDEX `work_logs_absent_marked_by_foreign` (`absent_marked_by`)',
    'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @has_fk := (SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
    WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'work_logs' AND CONSTRAINT_NAME = 'work_logs_absent_marked_by_foreign');
SET @sql := IF(@has_fk = 0,
    'ALTER TABLE `work_logs` ADD CONSTRAINT `work_logs_absent_marked_by_foreign` FOREIGN KEY (`absent_marked_by`) REFERENCES `users` (`id`) ON DELETE SET NULL',
    'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @has_col := (SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'work_logs' AND COLUMN_NAME = 'absent_marked_at');
SET @sql := IF(@has_col = 0,
    'ALTER TABLE `work_logs` ADD COLUMN `absent_marked_at` TIMESTAMP NULL DEFAULT NULL AFTER `absent_marked_by`',
    'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @has_col := (SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'work_logs' AND COLUMN_NAME = 'cutoff_closed_at');
SET @sql := IF(@has_col = 0,
    'ALTER TABLE `work_logs` ADD COLUMN `cutoff_closed_at` TIMESTAMP NULL DEFAULT NULL AFTER `absent_marked_at`',
    'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @has_col := (SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'work_logs' AND COLUMN_NAME = 'explained_at');
SET @sql := IF(@has_col = 0,
    'ALTER TABLE `work_logs` ADD COLUMN `explained_at` TIMESTAMP NULL DEFAULT NULL AFTER `cutoff_closed_at`',
    'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- accountable_from: ตั้งค่าให้แม่แบบเดิมเฉพาะรอบที่เพิ่มคอลัมน์ รันซ้ำจะไม่ทับค่าที่มีอยู่
SET @had_accountable := (SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'work_log_templates' AND COLUMN_NAME = 'accountable_from');
SET @sql := IF(@had_accountable = 0,
    'ALTER TABLE `work_log_templates` ADD COLUMN `accountable_from` TIMESTAMP NULL DEFAULT NULL AFTER `is_active`',
    'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF(@had_accountable = 0,
    'UPDATE `work_log_templates` SET `accountable_from` = UTC_TIMESTAMP()',
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
    SELECT '2026_09_14_000001_add_shift_schedule_to_work_log_templates' AS m, @batch AS b
    UNION ALL SELECT '2026_09_15_000001_add_completion_issue_and_shared_copy_to_work_logs', @batch
    UNION ALL SELECT '2026_09_16_000001_add_parent_job_id_to_work_order_list_task_requests', @batch
    UNION ALL SELECT '2026_09_16_000001_add_routine_cutoff_and_absence_to_work_logs', @batch
) AS rows_to_add
WHERE NOT EXISTS (
    SELECT 1 FROM `migrations` WHERE `migration` = rows_to_add.m
);


-- =============================================================================
-- ตรวจผลหลังรัน — คัดลอกไปรันแยกได้
-- =============================================================================

-- ควรได้ 10 แถว (คอลัมน์ใหม่ทั้งหมดของรอบนี้)
-- SELECT TABLE_NAME, COLUMN_NAME FROM information_schema.COLUMNS
-- WHERE TABLE_SCHEMA = DATABASE() AND COLUMN_NAME IN (
--   'schedule_type','has_issue','issue_details','shared_from_work_log_id','parent_job_id',
--   'unfinished_reason','absent_marked_by','absent_marked_at','cutoff_closed_at','explained_at','accountable_from');

-- ควรได้ตาราง work_log_template_shifts
-- SHOW TABLES LIKE 'work_log_template_shifts';

-- ควรเห็น migration ทั้งสี่ตัวของรอบนี้
-- SELECT `migration`, `batch` FROM `migrations` ORDER BY `id` DESC LIMIT 6;
