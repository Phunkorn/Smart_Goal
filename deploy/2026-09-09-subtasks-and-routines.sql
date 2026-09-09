-- =============================================================================
-- Smart Goal — SQL อัปเดตฐานข้อมูล: งานย่อย งานประจำ และการจัดเก็บโปรเจกต์
-- (Deploy รอบ 3 — 2026-09-09)
-- =============================================================================
--
-- ไฟล์นี้แทนคำสั่ง `php artisan migrate` สำหรับเซิร์ฟเวอร์ DirectAdmin ที่ไม่มี SSH
-- ครอบคลุม migration 5 ตัว (อีก 3 ตัวของ Telegram อยู่ใน 2026-09-09-telegram.sql)
--   2026_09_07_000001_create_work_log_template_participants_table
--   2026_09_08_000001_add_parent_job_id_to_work_orders_table
--   2026_09_08_000002_convert_work_order_subtasks_to_child_work_orders
--   2026_09_08_000003_add_routine_execution_fields_to_work_logs
--   2026_09_08_000007_add_archived_at_to_work_order_lists
--
-- สิ่งที่ไฟล์นี้ทำ
--   1) สร้างตาราง work_log_template_participants (ผู้ร่วมงานของแม่แบบงานประจำ)
--   2) เพิ่ม work_orders.parent_job_id / parent_sort_order — งานย่อยกลายเป็นงานจริง
--   3) ย้ายรายละเอียดงานเดิมใน work_order_subtasks ขึ้นมาเป็นงานย่อยจริง
--      (แถวเดิมไม่ถูกลบ ยังกู้คืนและตรวจย้อนหลังได้)
--   4) เพิ่มคอลัมน์ของงานประจำใน work_log_templates และ work_logs แล้วย้ายค่าเดิม
--   5) เพิ่ม work_order_lists.archived_at (ปุ่ม "จัดเก็บโปรเจกต์")
--   6) บันทึกลงตาราง migrations เพื่อให้ Laravel รู้ว่ารันไปแล้ว
--
-- ทุกคำสั่งข้ามของที่มีอยู่แล้วให้เอง จึงรันซ้ำได้อย่างปลอดภัย
-- ไม่มีการลบตาราง คอลัมน์ หรือแถวใดทั้งสิ้น
--
-- -----------------------------------------------------------------------------
-- ก่อนรัน
-- -----------------------------------------------------------------------------
--   1. สำรองฐานข้อมูลก่อนเสมอ (phpMyAdmin > Export > Quick > SQL)
--   2. ต้องรัน deploy/2026-09-08-upgrade.sql (Deploy รอบ 2) ให้เสร็จก่อนไฟล์นี้
--      ตรวจด้วย: SELECT migration FROM migrations ORDER BY id DESC LIMIT 5;
--      ต้องเห็น 2026_09_08_000002_create_work_order_update_attachments_table แล้ว
--   3. รันใน phpMyAdmin > เลือกฐานข้อมูล > แท็บ SQL > วางทั้งไฟล์ > Go
--   4. รันไฟล์นี้ก่อน แล้วค่อยรัน 2026-09-09-telegram.sql (สลับลำดับได้ ไม่เกี่ยวกัน)
--   5. ต้องรัน SQL ให้เสร็จ "ก่อน" อัปไฟล์โค้ด มิฉะนั้นเว็บจะขึ้น 500
--      เพราะโค้ดใหม่อ้างถึงคอลัมน์ที่ยังไม่มี
-- =============================================================================

SET @db := DATABASE();


-- =============================================================================
-- 1) ตาราง work_log_template_participants
--
-- แม่แบบงานประจำหนึ่งอันส่งรายการให้หลายคนได้ แต่ละคนยังได้แถวใน work_logs
-- ของตัวเอง เพราะการยืนยันว่าทำแล้วเป็นเรื่องของแต่ละคน ไม่ใช่กดแทนกัน
-- =============================================================================

CREATE TABLE IF NOT EXISTS `work_log_template_participants` (
    `id`                    BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `work_log_template_id`  BIGINT UNSIGNED NOT NULL,
    `user_id`               BIGINT UNSIGNED NOT NULL,
    `added_by`              BIGINT UNSIGNED NULL DEFAULT NULL,
    `created_at`            TIMESTAMP NULL DEFAULT NULL,
    `updated_at`            TIMESTAMP NULL DEFAULT NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `work_log_template_participants_unique` (`work_log_template_id`, `user_id`),
    KEY `work_log_template_participants_user_id_index` (`user_id`),
    KEY `work_log_template_participants_added_by_foreign` (`added_by`),
    CONSTRAINT `wltp_template_id_foreign`
        FOREIGN KEY (`work_log_template_id`) REFERENCES `work_log_templates` (`id`) ON DELETE CASCADE,
    CONSTRAINT `wltp_user_id_foreign`
        FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
    CONSTRAINT `wltp_added_by_foreign`
        FOREIGN KEY (`added_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- =============================================================================
-- 2) work_orders — คอลัมน์งานแม่/งานย่อย
--
-- MySQL 8 ไม่รองรับ ADD COLUMN IF NOT EXISTS จึงต้องตรวจ information_schema ก่อน
-- =============================================================================

SET @has_parent := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'work_orders' AND COLUMN_NAME = 'parent_job_id'
);
SET @sql := IF(@has_parent = 0,
    'ALTER TABLE `work_orders` ADD COLUMN `parent_job_id` BIGINT UNSIGNED NULL DEFAULT NULL AFTER `work_order_list_id`',
    'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @has_parent_index := (
    SELECT COUNT(*) FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'work_orders' AND INDEX_NAME = 'work_orders_parent_job_id_index'
);
SET @sql := IF(@has_parent_index = 0,
    'ALTER TABLE `work_orders` ADD INDEX `work_orders_parent_job_id_index` (`parent_job_id`)',
    'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @has_sort := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'work_orders' AND COLUMN_NAME = 'parent_sort_order'
);
SET @sql := IF(@has_sort = 0,
    'ALTER TABLE `work_orders` ADD COLUMN `parent_sort_order` INT UNSIGNED NOT NULL DEFAULT 0 AFTER `parent_job_id`',
    'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- งานแม่ถูกลบ งานย่อยต้องกลายเป็นงานลอย ไม่ใช่ถูกลบตามไปเงียบ ๆ จึงเป็น SET NULL
SET @has_parent_fk := (
    SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
    WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'work_orders'
      AND CONSTRAINT_NAME = 'work_orders_parent_job_id_foreign' AND CONSTRAINT_TYPE = 'FOREIGN KEY'
);
SET @sql := IF(@has_parent_fk = 0,
    'ALTER TABLE `work_orders` ADD CONSTRAINT `work_orders_parent_job_id_foreign` FOREIGN KEY (`parent_job_id`) REFERENCES `work_orders` (`job_id`) ON DELETE SET NULL',
    'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;


-- =============================================================================
-- 3) ย้าย work_order_subtasks ขึ้นมาเป็นงานย่อยจริงใน work_orders
--
-- แถวเดิมใน work_order_subtasks ไม่ถูกแตะเลย เพื่อให้ยังตรวจย้อนหลังได้
-- งานย่อยที่มีชื่อซ้ำกับของเดิมจะถูกข้าม การรันซ้ำจึงไม่สร้างงานซ้ำ
--
-- ห่อด้วย PREPARE เพราะต้องข้ามทั้งบล็อกถ้าเซิร์ฟเวอร์ไม่มีตาราง work_order_subtasks
--
-- คำสั่งนี้อ่านจาก work_orders พร้อมกับเขียนลง work_orders ซึ่ง MySQL รองรับสำหรับ
-- INSERT ... SELECT (ต่างจาก UPDATE/DELETE ที่ห้าม) ถ้าโฮสต์ตอบ error 1093 ให้รัน
-- เฉพาะส่วน SELECT ข้างในเพื่อดูรายการก่อน แล้วค่อยเพิ่มทีละงานแม่ด้วย WHERE p.job_id = ?
-- =============================================================================

SET @has_subtasks := (
    SELECT COUNT(*) FROM information_schema.TABLES
    WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'work_order_subtasks'
);

SET @sql := IF(@has_subtasks = 1, '
INSERT INTO `work_orders` (
    `user_id`, `created_by`, `assigned_by`, `leader_user_id`, `department_id`,
    `work_order_list_id`, `parent_job_id`, `parent_sort_order`,
    `job_topic`, `job_priority`, `job_status`,
    `job_start_at`, `job_due_at`, `job_completed_at`,
    `approval_status`, `approved_by`, `approved_at`,
    `created_at`, `updated_at`
)
SELECT
    p.`user_id`,
    IFNULL(s.`created_by`, p.`created_by`),
    p.`assigned_by`,
    p.`leader_user_id`,
    p.`department_id`,
    p.`work_order_list_id`,
    p.`job_id`,
    s.`sort_order`,
    s.`title`,
    p.`job_priority`,
    IF(s.`is_completed` = 1, 4, 2),
    IFNULL(p.`job_start_at`, IFNULL(p.`created_at`, NOW())),
    IFNULL(p.`job_due_at`, IFNULL(p.`created_at`, NOW())),
    IF(s.`is_completed` = 1, IFNULL(s.`updated_at`, NOW()), NULL),
    p.`approval_status`,
    p.`approved_by`,
    p.`approved_at`,
    IFNULL(s.`created_at`, NOW()),
    IFNULL(s.`updated_at`, NOW())
FROM `work_order_subtasks` s
INNER JOIN (
    SELECT `work_order_id`, `title`, MIN(`id`) AS `id`
    FROM `work_order_subtasks`
    GROUP BY `work_order_id`, `title`
) first_of_title ON first_of_title.`id` = s.`id`
INNER JOIN `work_orders` p ON p.`job_id` = s.`work_order_id`
LEFT JOIN (
    SELECT `parent_job_id`, `job_topic` FROM `work_orders` WHERE `parent_job_id` IS NOT NULL
) existing ON existing.`parent_job_id` = p.`job_id` AND existing.`job_topic` = s.`title`
WHERE existing.`parent_job_id` IS NULL
ORDER BY s.`work_order_id`, s.`sort_order`, s.`id`
', 'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;


-- =============================================================================
-- 4) งานประจำ — คอลัมน์ใหม่ของ work_log_templates และ work_logs
-- =============================================================================

-- 4.1 work_log_templates: ผูกแม่แบบกับโปรเจกต์และงานได้
SET @has_tpl_list := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'work_log_templates' AND COLUMN_NAME = 'work_order_list_id'
);
SET @sql := IF(@has_tpl_list = 0,
    'ALTER TABLE `work_log_templates` ADD COLUMN `work_order_list_id` BIGINT UNSIGNED NULL DEFAULT NULL AFTER `work_log_category_id`',
    'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF(@has_tpl_list = 0,
    'ALTER TABLE `work_log_templates` ADD CONSTRAINT `work_log_templates_work_order_list_id_foreign` FOREIGN KEY (`work_order_list_id`) REFERENCES `work_order_lists` (`id`) ON DELETE SET NULL',
    'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @has_tpl_job := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'work_log_templates' AND COLUMN_NAME = 'job_id'
);
SET @sql := IF(@has_tpl_job = 0,
    'ALTER TABLE `work_log_templates` ADD COLUMN `job_id` BIGINT UNSIGNED NULL DEFAULT NULL AFTER `work_order_list_id`',
    'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF(@has_tpl_job = 0,
    'ALTER TABLE `work_log_templates` ADD CONSTRAINT `work_log_templates_job_id_foreign` FOREIGN KEY (`job_id`) REFERENCES `work_orders` (`job_id`) ON DELETE SET NULL',
    'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- 4.2 work_logs: เวลาที่วางแผนไว้ เหตุผลที่เริ่ม/จบช้า และการข้ามงาน
SET @has_planned_start := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'work_logs' AND COLUMN_NAME = 'planned_start_at'
);
SET @sql := IF(@has_planned_start = 0,
    'ALTER TABLE `work_logs` ADD COLUMN `planned_start_at` TIMESTAMP NULL DEFAULT NULL AFTER `work_date`',
    'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @has_planned_end := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'work_logs' AND COLUMN_NAME = 'planned_end_at'
);
SET @sql := IF(@has_planned_end = 0,
    'ALTER TABLE `work_logs` ADD COLUMN `planned_end_at` TIMESTAMP NULL DEFAULT NULL AFTER `planned_start_at`',
    'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @has_late_start := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'work_logs' AND COLUMN_NAME = 'late_start_reason'
);
SET @sql := IF(@has_late_start = 0,
    'ALTER TABLE `work_logs` ADD COLUMN `late_start_reason` VARCHAR(500) NULL DEFAULT NULL AFTER `duration_minutes`',
    'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @has_late_completion := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'work_logs' AND COLUMN_NAME = 'late_completion_reason'
);
SET @sql := IF(@has_late_completion = 0,
    'ALTER TABLE `work_logs` ADD COLUMN `late_completion_reason` VARCHAR(500) NULL DEFAULT NULL AFTER `late_start_reason`',
    'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @has_skip_reason := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'work_logs' AND COLUMN_NAME = 'skip_reason'
);
SET @sql := IF(@has_skip_reason = 0,
    'ALTER TABLE `work_logs` ADD COLUMN `skip_reason` VARCHAR(500) NULL DEFAULT NULL AFTER `late_completion_reason`',
    'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @has_skipped_at := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'work_logs' AND COLUMN_NAME = 'skipped_at'
);
SET @sql := IF(@has_skipped_at = 0,
    'ALTER TABLE `work_logs` ADD COLUMN `skipped_at` TIMESTAMP NULL DEFAULT NULL AFTER `skip_reason`',
    'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- 4.3 "งานแทรก" ถูกยุบรวมเป็นงานประจำ — เปลี่ยนประเภทแทนการลบแถว ประวัติจึงยังอยู่ครบ
UPDATE `work_log_templates` SET `kind` = 'routine' WHERE `kind` = 'interrupt';
UPDATE `work_logs`          SET `kind` = 'routine' WHERE `kind` = 'interrupt';

-- 4.4 ย้าย "เวลาที่วางแผน" ออกจาก started_at มาไว้คอลัมน์ใหม่
--
-- รายการจากแม่แบบรุ่นเดิมเก็บเวลาที่ตั้งใจจะทำไว้ใน started_at ทั้งที่ยังไม่ได้เริ่มจริง
-- แถบเวลาจึงอ่านไม่ออกว่าอะไรคือแผน อะไรคือของจริง
--
-- default_start_time เป็นเวลาไทย ส่วนคอลัมน์ timestamp เก็บเป็น UTC จึงลบ 7 ชั่วโมง
-- ใช้ตัวเลขตรง ๆ แทน CONVERT_TZ เพราะโฮสต์ส่วนใหญ่ไม่ได้โหลดตาราง timezone ของ MySQL
-- ไว้ และเขตเวลาไทยไม่มี DST ค่าคงที่ +7 จึงถูกต้องทุกวันของปี
--
-- เงื่อนไข planned_start_at IS NULL ทำให้รันซ้ำได้ รอบสองจะไม่เขียนทับค่าที่ย้ายไปแล้ว
UPDATE `work_logs` l
INNER JOIN `work_log_templates` t ON t.`id` = l.`work_log_template_id`
SET
    l.`planned_start_at` = IF(
        t.`default_start_time` IS NOT NULL,
        DATE_SUB(TIMESTAMP(l.`work_date`, t.`default_start_time`), INTERVAL 7 HOUR),
        l.`started_at`
    ),
    l.`planned_end_at` = IF(
        t.`default_duration_minutes` IS NOT NULL,
        DATE_ADD(
            IF(
                t.`default_start_time` IS NOT NULL,
                DATE_SUB(TIMESTAMP(l.`work_date`, t.`default_start_time`), INTERVAL 7 HOUR),
                l.`started_at`
            ),
            INTERVAL t.`default_duration_minutes` MINUTE
        ),
        NULL
    )
WHERE l.`planned_start_at` IS NULL;

-- แล้วคืน started_at/ended_at ให้หมายถึง "เวลาที่ผู้ใช้กดจริง" เท่านั้น
-- ทำหลังบล็อกบนเสมอ เพราะบล็อกบนยังต้องอ่านค่าเดิมของ started_at อยู่
UPDATE `work_logs`
SET `started_at` = NULL, `ended_at` = NULL
WHERE `work_log_template_id` IS NOT NULL AND `status` = 'open';


-- =============================================================================
-- 5) work_order_lists.archived_at — ปุ่ม "จัดเก็บโปรเจกต์"
--
-- โปรเจกต์ที่ปิดงานครบแล้วถูกยกออกจากพื้นที่ทำงาน แต่ข้อมูลยังอยู่ครบและกู้กลับได้
-- =============================================================================

SET @has_archived := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'work_order_lists' AND COLUMN_NAME = 'archived_at'
);
SET @sql := IF(@has_archived = 0,
    'ALTER TABLE `work_order_lists` ADD COLUMN `archived_at` TIMESTAMP NULL DEFAULT NULL AFTER `is_visible`',
    'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @has_archived_index := (
    SELECT COUNT(*) FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'work_order_lists' AND INDEX_NAME = 'work_order_lists_archived_at_index'
);
SET @sql := IF(@has_archived_index = 0,
    'ALTER TABLE `work_order_lists` ADD INDEX `work_order_lists_archived_at_index` (`archived_at`)',
    'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;


-- =============================================================================
-- 6) บันทึกลงตาราง migrations
--
-- ถ้าไม่ใส่ส่วนนี้ Laravel จะยังคิดว่ายังไม่ได้รัน แล้วพยายามสร้างซ้ำในครั้งถัดไป
-- =============================================================================

SET @batch := (SELECT IFNULL(MAX(`batch`), 0) + 1 FROM `migrations`);

INSERT INTO `migrations` (`migration`, `batch`)
SELECT * FROM (
    SELECT '2026_09_07_000001_create_work_log_template_participants_table' AS m, @batch AS b
    UNION ALL SELECT '2026_09_08_000001_add_parent_job_id_to_work_orders_table',            @batch
    UNION ALL SELECT '2026_09_08_000002_convert_work_order_subtasks_to_child_work_orders',  @batch
    UNION ALL SELECT '2026_09_08_000003_add_routine_execution_fields_to_work_logs',         @batch
    UNION ALL SELECT '2026_09_08_000007_add_archived_at_to_work_order_lists',               @batch
) AS rows_to_add
WHERE NOT EXISTS (
    SELECT 1 FROM `migrations` WHERE `migration` = rows_to_add.m
);


-- =============================================================================
-- ตรวจผลหลังรัน
-- =============================================================================

-- ควรได้ 5 แถว เลข batch เดียวกันทั้งหมด
SELECT `migration`, `batch` FROM `migrations` WHERE `batch` = @batch ORDER BY `id`;

-- ควรได้ 2 คอลัมน์: parent_job_id, parent_sort_order
SHOW COLUMNS FROM `work_orders` LIKE 'parent\_%';

-- ควรได้ 6 คอลัมน์: planned_start_at, planned_end_at, late_start_reason,
-- late_completion_reason, skip_reason, skipped_at
SHOW COLUMNS FROM `work_logs` WHERE `Field` IN (
    'planned_start_at', 'planned_end_at', 'late_start_reason',
    'late_completion_reason', 'skip_reason', 'skipped_at'
);

-- ควรได้ 1 คอลัมน์
SHOW COLUMNS FROM `work_order_lists` LIKE 'archived_at';

-- ควรได้ตาราง work_log_template_participants
SHOW TABLES LIKE 'work_log_template_participants';

-- จำนวนงานย่อยที่ถูกย้ายขึ้นมา ควรเท่ากับจำนวนแถวใน work_order_subtasks
-- (นับเฉพาะชื่อที่ไม่ซ้ำกันในงานแม่เดียวกัน)
SELECT
    (SELECT COUNT(*) FROM `work_orders` WHERE `parent_job_id` IS NOT NULL) AS `child_work_orders`,
    (SELECT COUNT(DISTINCT `work_order_id`, `title`) FROM `work_order_subtasks`) AS `legacy_subtasks`;
