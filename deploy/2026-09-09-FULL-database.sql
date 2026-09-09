-- =============================================================================
-- Smart Goal — SQL รวมทุกอย่างที่ต้องเพิ่มในฐานข้อมูล (ไฟล์เดียวจบ)
-- สร้างเมื่อ 2026-09-09 สำหรับ Deploy รอบ 3
-- =============================================================================
--
-- ไฟล์นี้รวมสามไฟล์เดิมไว้ด้วยกันตามลำดับที่ถูกต้อง จึงวางทีเดียวจบ
--   deploy/2026-09-08-upgrade.sql            (Deploy รอบ 2 — 10 migration)
--   deploy/2026-09-09-subtasks-and-routines.sql (Deploy รอบ 3 — 5 migration)
--   deploy/2026-09-09-telegram.sql           (Deploy รอบ 3 — 3 migration)
--
-- รวม 18 migration ครอบคลุมทุกตารางและทุกคอลัมน์ที่โค้ดรุ่นปัจจุบันต้องใช้
--
-- -----------------------------------------------------------------------------
-- รันซ้ำได้ และรันได้ไม่ว่าฐานข้อมูลจะอยู่รุ่นไหน
-- -----------------------------------------------------------------------------
-- ทุกคำสั่งตรวจของที่มีอยู่แล้วให้เองก่อนเสมอ
--   สร้างตาราง   ใช้ CREATE TABLE IF NOT EXISTS
--   เพิ่มคอลัมน์  ตรวจ information_schema ก่อนแล้วค่อย ALTER
--   เพิ่มข้อมูล   ใช้ INSERT IGNORE หรือ WHERE NOT EXISTS
--
-- ไม่มีการ DROP หรือ DELETE ใด ๆ ทั้งไฟล์ ข้อมูลเดิมไม่หายแม้แต่แถวเดียว
--
-- -----------------------------------------------------------------------------
-- ขั้นตอน
-- -----------------------------------------------------------------------------
--   1. สำรองฐานข้อมูลก่อน — phpMyAdmin > Export > Quick > SQL
--
--   2. ทดสอบสิทธิ์ก่อนหนึ่งบรรทัด (ไฟล์นี้ต้องอ่าน information_schema ได้)
--      วางบรรทัดนี้ในแท็บ SQL แล้ว Go:
--
--        SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE();
--
--      ได้ตัวเลขออกมา = ผ่าน รันไฟล์นี้ได้เลย
--      ถ้าได้ #1044 Access denied = โฮสต์ปิดสิทธิ์นั้น ให้แจ้งผู้พัฒนาเพื่อขอไฟล์อีกแบบ
--
--   3. phpMyAdmin > คลิกชื่อฐานข้อมูลทางซ้าย > แท็บ SQL > วางไฟล์นี้ทั้งไฟล์ > Go
--      (ใช้แท็บ SQL ไม่ใช่แท็บ Import เพราะ Import อาจแบ่งรันหลายรอบ
--       แล้วตัวแปร @ ที่ส่งค่าข้ามคำสั่งจะหายไปกลางทาง)
--
--   4. เลื่อนดูผลลัพธ์ท้ายหน้า เทียบกับหัวข้อ "ตรวจผลหลังรัน" ให้ครบทุกข้อ
--
--   5. ลบไฟล์ .php ทั้งหมดใน public_html/bootstrap/cache/
--      และใน Smart-Private/storage/framework/views/
--
-- ถ้ารันแล้วหยุดกลางคันด้วย error ให้แก้ตามข้อความแล้ววางไฟล์นี้รันใหม่ทั้งไฟล์ได้เลย
-- ส่วนที่สำเร็จไปแล้วจะถูกข้ามเอง
-- =============================================================================


-- #############################################################################
-- # ตอนที่ 1 จาก 3 — บันทึกงานประจำวัน กระดานไอเดีย และถังขยะไฟล์แนบ
-- # (เดิมคือ deploy/2026-09-08-upgrade.sql)
-- #############################################################################

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 1;


-- =============================================================================
-- ส่วนที่ 1 — ตารางใหม่
--
-- ลำดับสำคัญ ตารางที่ถูกอ้างถึงด้วย FOREIGN KEY ต้องถูกสร้างก่อนตารางที่อ้างถึงมัน
-- =============================================================================


-- ตาราง: work_log_categories
CREATE TABLE IF NOT EXISTS `work_log_categories` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(60) COLLATE utf8mb4_unicode_ci NOT NULL,
  `tone` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'gray',
  `icon` varchar(40) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `sort_order` smallint unsigned NOT NULL DEFAULT '0',
  `is_active` tinyint(1) NOT NULL DEFAULT '1',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `work_log_categories_name_unique` (`name`),
  KEY `work_log_categories_is_active_sort_order_index` (`is_active`,`sort_order`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ตาราง: work_log_templates
CREATE TABLE IF NOT EXISTS `work_log_templates` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `user_id` bigint unsigned NOT NULL,
  `work_log_category_id` bigint unsigned DEFAULT NULL,
  `kind` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'routine',
  `title` varchar(200) COLLATE utf8mb4_unicode_ci NOT NULL,
  `details` text COLLATE utf8mb4_unicode_ci,
  `weekday_mask` tinyint unsigned NOT NULL DEFAULT '31',
  `default_start_time` time DEFAULT NULL,
  `default_duration_minutes` smallint unsigned DEFAULT NULL,
  `starts_on` date DEFAULT NULL,
  `ends_on` date DEFAULT NULL,
  `last_materialized_on` date DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT '1',
  `sort_order` smallint unsigned NOT NULL DEFAULT '0',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `work_log_templates_work_log_category_id_foreign` (`work_log_category_id`),
  KEY `work_log_templates_user_id_is_active_index` (`user_id`,`is_active`),
  CONSTRAINT `work_log_templates_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `work_log_templates_work_log_category_id_foreign` FOREIGN KEY (`work_log_category_id`) REFERENCES `work_log_categories` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ตาราง: work_logs
CREATE TABLE IF NOT EXISTS `work_logs` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `user_id` bigint unsigned NOT NULL,
  `department_id` bigint unsigned DEFAULT NULL,
  `work_log_category_id` bigint unsigned DEFAULT NULL,
  `work_log_template_id` bigint unsigned DEFAULT NULL,
  `work_order_list_id` bigint unsigned DEFAULT NULL,
  `job_id` bigint unsigned DEFAULT NULL,
  `kind` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'routine',
  `status` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'open',
  `source` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'manual',
  `title` varchar(200) COLLATE utf8mb4_unicode_ci NOT NULL,
  `details` text COLLATE utf8mb4_unicode_ci,
  `location` varchar(120) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `requester_name` varchar(120) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `work_date` date NOT NULL,
  `started_at` timestamp NULL DEFAULT NULL,
  `ended_at` timestamp NULL DEFAULT NULL,
  `duration_minutes` smallint unsigned DEFAULT NULL,
  `open_timer_owner_id` bigint unsigned DEFAULT NULL,
  `auto_closed_at` timestamp NULL DEFAULT NULL,
  `created_by` bigint unsigned NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `work_logs_template_day_unique` (`work_log_template_id`,`user_id`,`work_date`),
  UNIQUE KEY `work_logs_open_timer_unique` (`open_timer_owner_id`),
  KEY `work_logs_work_log_category_id_foreign` (`work_log_category_id`),
  KEY `work_logs_work_order_list_id_foreign` (`work_order_list_id`),
  KEY `work_logs_created_by_foreign` (`created_by`),
  KEY `work_logs_job_id_foreign` (`job_id`),
  KEY `work_logs_user_day_index` (`user_id`,`work_date`),
  KEY `work_logs_department_day_index` (`department_id`,`work_date`),
  KEY `work_logs_day_kind_index` (`work_date`,`kind`),
  CONSTRAINT `work_logs_created_by_foreign` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `work_logs_department_id_foreign` FOREIGN KEY (`department_id`) REFERENCES `departments` (`id`) ON DELETE SET NULL,
  CONSTRAINT `work_logs_job_id_foreign` FOREIGN KEY (`job_id`) REFERENCES `work_orders` (`job_id`) ON DELETE SET NULL,
  CONSTRAINT `work_logs_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `work_logs_work_log_category_id_foreign` FOREIGN KEY (`work_log_category_id`) REFERENCES `work_log_categories` (`id`) ON DELETE SET NULL,
  CONSTRAINT `work_logs_work_log_template_id_foreign` FOREIGN KEY (`work_log_template_id`) REFERENCES `work_log_templates` (`id`) ON DELETE SET NULL,
  CONSTRAINT `work_logs_work_order_list_id_foreign` FOREIGN KEY (`work_order_list_id`) REFERENCES `work_order_lists` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ตาราง: work_log_attachments
CREATE TABLE IF NOT EXISTS `work_log_attachments` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `work_log_id` bigint unsigned NOT NULL,
  `file_path` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `original_name` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `file_type` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `uploaded_by` bigint unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `work_log_attachments_work_log_id_foreign` (`work_log_id`),
  KEY `work_log_attachments_uploaded_by_foreign` (`uploaded_by`),
  CONSTRAINT `work_log_attachments_uploaded_by_foreign` FOREIGN KEY (`uploaded_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `work_log_attachments_work_log_id_foreign` FOREIGN KEY (`work_log_id`) REFERENCES `work_logs` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ตาราง: work_log_participants
CREATE TABLE IF NOT EXISTS `work_log_participants` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `work_log_id` bigint unsigned NOT NULL,
  `user_id` bigint unsigned NOT NULL,
  `added_by` bigint unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `work_log_participants_unique` (`work_log_id`,`user_id`),
  KEY `work_log_participants_added_by_foreign` (`added_by`),
  KEY `work_log_participants_user_id_index` (`user_id`),
  CONSTRAINT `work_log_participants_added_by_foreign` FOREIGN KEY (`added_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `work_log_participants_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `work_log_participants_work_log_id_foreign` FOREIGN KEY (`work_log_id`) REFERENCES `work_logs` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ตาราง: workspace_boards
CREATE TABLE IF NOT EXISTS `workspace_boards` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `department_id` bigint unsigned NOT NULL,
  `created_by` bigint unsigned DEFAULT NULL,
  `title` varchar(160) COLLATE utf8mb4_unicode_ci NOT NULL,
  `visibility` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'organization',
  `element_count` int unsigned NOT NULL DEFAULT '0',
  `last_edited_by` bigint unsigned DEFAULT NULL,
  `last_edited_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `workspace_boards_last_edited_by_foreign` (`last_edited_by`),
  KEY `workspace_boards_department_visibility_index` (`department_id`,`visibility`),
  KEY `workspace_boards_department_recent_index` (`department_id`,`last_edited_at`),
  KEY `workspace_boards_creator_index` (`created_by`),
  CONSTRAINT `workspace_boards_created_by_foreign` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `workspace_boards_department_id_foreign` FOREIGN KEY (`department_id`) REFERENCES `departments` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `workspace_boards_last_edited_by_foreign` FOREIGN KEY (`last_edited_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ตาราง: workspace_board_documents
CREATE TABLE IF NOT EXISTS `workspace_board_documents` (
  `workspace_board_id` bigint unsigned NOT NULL,
  `document` json NOT NULL,
  `content_version` bigint unsigned NOT NULL DEFAULT '1',
  `byte_size` int unsigned NOT NULL DEFAULT '0',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`workspace_board_id`),
  CONSTRAINT `workspace_board_documents_workspace_board_id_foreign` FOREIGN KEY (`workspace_board_id`) REFERENCES `workspace_boards` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ตาราง: workspace_board_attachments
CREATE TABLE IF NOT EXISTS `workspace_board_attachments` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `workspace_board_id` bigint unsigned NOT NULL,
  `file_path` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `original_name` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `file_type` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `image_width` int unsigned DEFAULT NULL,
  `image_height` int unsigned DEFAULT NULL,
  `byte_size` bigint unsigned NOT NULL DEFAULT '0',
  `uploaded_by` bigint unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `workspace_board_attachments_uploaded_by_foreign` (`uploaded_by`),
  KEY `workspace_board_attachments_board_index` (`workspace_board_id`),
  CONSTRAINT `workspace_board_attachments_uploaded_by_foreign` FOREIGN KEY (`uploaded_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `workspace_board_attachments_workspace_board_id_foreign` FOREIGN KEY (`workspace_board_id`) REFERENCES `workspace_boards` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ตาราง: work_order_update_attachments
CREATE TABLE IF NOT EXISTS `work_order_update_attachments` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `work_order_update_id` bigint unsigned NOT NULL,
  `file_path` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `original_name` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `file_type` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `byte_size` bigint unsigned NOT NULL DEFAULT '0',
  `uploaded_by` bigint unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `work_order_update_attachments_uploaded_by_foreign` (`uploaded_by`),
  KEY `work_order_update_attachments_update_index` (`work_order_update_id`),
  CONSTRAINT `work_order_update_attachments_uploaded_by_foreign` FOREIGN KEY (`uploaded_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `work_order_update_attachments_work_order_update_id_foreign` FOREIGN KEY (`work_order_update_id`) REFERENCES `work_order_updates` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =============================================================================
-- ส่วนที่ 2 - เพิ่มคอลัมน์ deleted_at ให้ตารางไฟล์แนบเดิม
--
-- ไฟล์แนบที่ถูกลบต้องกู้คืนได้ภายใน 30 วันเหมือนข้อมูลชนิดอื่น เดิมไฟล์ถูกลบออก
-- จากดิสก์ทันทีที่กดลบ ถังขยะจึงไม่เคยกู้ไฟล์กลับมาได้เลย
--
-- สองตารางใหม่ (work_log_attachments, workspace_board_attachments) มีคอลัมน์นี้
-- อยู่ในคำสั่ง CREATE TABLE ข้างบนแล้ว จึงเหลือเฉพาะสองตารางเดิมที่ต้อง ALTER
--
-- แถวเดิมทั้งหมดได้ค่า NULL ซึ่งแปลว่า "ยังไม่ถูกลบ" ตรงกับความจริงพอดี
--
-- ในไฟล์รวมนี้สองคำสั่งถูกเปลี่ยนเป็นแบบ "ตรวจก่อนแล้วค่อยเพิ่ม" จึงรันซ้ำได้แล้ว
-- (ต้นฉบับของ Deploy รอบ 2 เป็น ALTER ตรง ๆ ซึ่งจะฟ้อง #1060 ถ้าเคยรันไปแล้ว)
-- =============================================================================

SET @db := DATABASE();

SET @has_ji_deleted := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'job_images' AND COLUMN_NAME = 'deleted_at'
);
SET @sql := IF(@has_ji_deleted = 0,
    'ALTER TABLE `job_images` ADD COLUMN `deleted_at` timestamp NULL DEFAULT NULL',
    'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @has_wola_deleted := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'work_order_list_attachments' AND COLUMN_NAME = 'deleted_at'
);
SET @sql := IF(@has_wola_deleted = 0,
    'ALTER TABLE `work_order_list_attachments` ADD COLUMN `deleted_at` timestamp NULL DEFAULT NULL',
    'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;


-- ส่วนที่ 3 — หมวดงานตั้งต้นของบันทึกงานประจำวัน
--
-- ชุดเดียวกับที่ migration ใส่ให้ตอนสร้างตาราง ผู้ดูแลระบบเพิ่มหรือปิดใช้งานได้
-- ภายหลังจากหน้า "หมวดงานประจำวัน" โดยไม่ต้องแก้ฐานข้อมูลอีก
--
-- INSERT IGNORE อาศัย UNIQUE KEY ของคอลัมน์ name จึงไม่เกิดหมวดซ้ำถ้ารันซ้ำ
-- =============================================================================

INSERT IGNORE INTO `work_log_categories` (`name`, `tone`, `icon`, `sort_order`, `is_active`, `created_at`, `updated_at`) VALUES
('IT Support',      'blue',   'bi-headset',             1,  1, NOW(), NOW()),
('ดูแลระบบ',        'purple', 'bi-hdd-network',         2,  1, NOW(), NOW()),
('ซ่อมบำรุง',        'amber',  'bi-tools',               3,  1, NOW(), NOW()),
('ติดตั้งอุปกรณ์',    'teal',   'bi-pc-display',          4,  1, NOW(), NOW()),
('ประชุม/อบรม',      'cyan',   'bi-people',              5,  1, NOW(), NOW()),
('งานเอกสาร',        'gray',   'bi-file-earmark-text',   6,  1, NOW(), NOW()),
('อื่น ๆ',           'gray',   'bi-three-dots',         99,  1, NOW(), NOW());


-- =============================================================================
-- ส่วนที่ 4 — บันทึกว่า migration เหล่านี้รันไปแล้ว
--
-- ถ้าไม่ใส่ส่วนนี้ Laravel จะยังคิดว่ายังไม่ได้รัน แล้วพยายามสร้างตารางซ้ำ
-- ในครั้งถัดไปที่มีใครสั่ง migrate ซึ่งจะล้มด้วย "table already exists"
--
-- batch ใช้เลขถัดจากชุดล่าสุดที่มีอยู่ ทั้ง 10 ตัวนับเป็นการ deploy ครั้งเดียวกัน
-- =============================================================================

SET @batch := (SELECT IFNULL(MAX(`batch`), 0) + 1 FROM `migrations`);

INSERT INTO `migrations` (`migration`, `batch`)
SELECT * FROM (
    SELECT '2026_09_05_000001_create_work_log_categories_table'        AS m, @batch AS b
    UNION ALL SELECT '2026_09_05_000002_create_work_log_templates_table',        @batch
    UNION ALL SELECT '2026_09_05_000003_create_work_logs_table',                 @batch
    UNION ALL SELECT '2026_09_05_000004_create_work_log_attachments_table',      @batch
    UNION ALL SELECT '2026_09_06_000001_create_work_log_participants_table',     @batch
    UNION ALL SELECT '2026_09_07_000001_create_workspace_boards_table',          @batch
    UNION ALL SELECT '2026_09_07_000002_create_workspace_board_documents_table', @batch
    UNION ALL SELECT '2026_09_07_000003_create_workspace_board_attachments_table', @batch
    UNION ALL SELECT '2026_09_08_000001_soft_delete_attachments',                @batch
    UNION ALL SELECT '2026_09_08_000002_create_work_order_update_attachments_table', @batch
) AS rows_to_add
WHERE NOT EXISTS (
    SELECT 1 FROM `migrations` WHERE `migration` = rows_to_add.m
);

-- #############################################################################
-- # ตอนที่ 2 จาก 3 — งานย่อย งานประจำ และการจัดเก็บโปรเจกต์
-- # (เดิมคือ deploy/2026-09-09-subtasks-and-routines.sql)
-- #############################################################################

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

-- #############################################################################
-- # ตอนที่ 3 จาก 3 — การแจ้งเตือนผ่าน Telegram
-- # (เดิมคือ deploy/2026-09-09-telegram.sql)
-- #############################################################################

SET @db := DATABASE();

SET @has_chat_id := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'users' AND COLUMN_NAME = 'telegram_chat_id'
);
SET @sql := IF(@has_chat_id = 0,
    'ALTER TABLE `users` ADD COLUMN `telegram_chat_id` BIGINT UNSIGNED NULL DEFAULT NULL AFTER `profile_image`',
    'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @has_username := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'users' AND COLUMN_NAME = 'telegram_username'
);
SET @sql := IF(@has_username = 0,
    'ALTER TABLE `users` ADD COLUMN `telegram_username` VARCHAR(64) NULL DEFAULT NULL AFTER `telegram_chat_id`',
    'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @has_linked_at := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'users' AND COLUMN_NAME = 'telegram_linked_at'
);
SET @sql := IF(@has_linked_at = 0,
    'ALTER TABLE `users` ADD COLUMN `telegram_linked_at` TIMESTAMP NULL DEFAULT NULL AFTER `telegram_username`',
    'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @has_enabled := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'users' AND COLUMN_NAME = 'telegram_notifications_enabled'
);
SET @sql := IF(@has_enabled = 0,
    'ALTER TABLE `users` ADD COLUMN `telegram_notifications_enabled` TINYINT(1) NOT NULL DEFAULT 1 AFTER `telegram_linked_at`',
    'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- แชทส่วนตัวหนึ่งห้องต้องผูกได้กับผู้ใช้เดียวเท่านั้น
-- มิฉะนั้นการแจ้งเตือนของคนละคนจะไปโผล่รวมกันในห้องเดียว
SET @has_unique := (
    SELECT COUNT(*) FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'users' AND INDEX_NAME = 'users_telegram_chat_id_unique'
);
SET @sql := IF(@has_unique = 0,
    'ALTER TABLE `users` ADD UNIQUE KEY `users_telegram_chat_id_unique` (`telegram_chat_id`)',
    'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;


-- =============================================================================
-- 2) ตารางใหม่
-- =============================================================================

-- รหัสผูกบัญชีแบบใช้ครั้งเดียว ผู้ใช้ขอจากหน้าตั้งค่าแล้วส่งให้บอทด้วย /start <token>
CREATE TABLE IF NOT EXISTS `telegram_link_tokens` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `user_id` BIGINT UNSIGNED NOT NULL,
    `token` VARCHAR(48) NOT NULL,
    `expires_at` TIMESTAMP NOT NULL,
    `consumed_at` TIMESTAMP NULL DEFAULT NULL,
    `created_at` TIMESTAMP NULL DEFAULT NULL,
    `updated_at` TIMESTAMP NULL DEFAULT NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `telegram_link_tokens_token_unique` (`token`),
    KEY `telegram_link_tokens_user_id_foreign` (`user_id`),
    CONSTRAINT `telegram_link_tokens_user_id_foreign`
        FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- คิวข้อความขาออก เครื่องนี้ไม่มี queue worker การส่งจริงจึงเกิดตอนปิดท้าย request
-- แถวที่ส่งไม่สำเร็จค้างเป็น pending แล้วถูก request ถัดไปหยิบไปลองใหม่
CREATE TABLE IF NOT EXISTS `telegram_messages` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `user_id` BIGINT UNSIGNED NOT NULL,
    `system_notification_id` BIGINT UNSIGNED NULL DEFAULT NULL,
    `chat_id` BIGINT UNSIGNED NOT NULL,
    `text` TEXT NOT NULL,
    `status` VARCHAR(20) NOT NULL DEFAULT 'pending',
    `attempts` TINYINT UNSIGNED NOT NULL DEFAULT 0,
    `last_error` TEXT NULL DEFAULT NULL,
    `sent_at` TIMESTAMP NULL DEFAULT NULL,
    `created_at` TIMESTAMP NULL DEFAULT NULL,
    `updated_at` TIMESTAMP NULL DEFAULT NULL,
    PRIMARY KEY (`id`),
    KEY `telegram_messages_status_id_index` (`status`, `id`),
    KEY `telegram_messages_user_id_foreign` (`user_id`),
    KEY `telegram_messages_system_notification_id_foreign` (`system_notification_id`),
    CONSTRAINT `telegram_messages_user_id_foreign`
        FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
    CONSTRAINT `telegram_messages_system_notification_id_foreign`
        FOREIGN KEY (`system_notification_id`) REFERENCES `system_notifications` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- =============================================================================
-- 3) บันทึกลงตาราง migrations
--
-- ถ้าไม่ใส่ส่วนนี้ Laravel จะยังคิดว่ายังไม่ได้รัน แล้วพยายามสร้างซ้ำในครั้งถัดไป
-- =============================================================================

SET @batch := (SELECT IFNULL(MAX(`batch`), 0) + 1 FROM `migrations`);

INSERT INTO `migrations` (`migration`, `batch`)
SELECT * FROM (
    SELECT '2026_09_08_000004_add_telegram_columns_to_users_table' AS m, @batch AS b
    UNION ALL SELECT '2026_09_08_000005_create_telegram_link_tokens_table', @batch
    UNION ALL SELECT '2026_09_08_000006_create_telegram_messages_table',    @batch
) AS rows_to_add
WHERE NOT EXISTS (
    SELECT 1 FROM `migrations` WHERE `migration` = rows_to_add.m
);

-- #############################################################################
-- # ตรวจผลหลังรัน — เลื่อนดูผลลัพธ์ทีละอันให้ครบ
-- #############################################################################

-- 1) migration ต้องครบ 18 ตัว (นับเฉพาะของสามรอบนี้)
SELECT COUNT(*) AS `ต้องได้_18`
FROM `migrations`
WHERE `migration` IN (
    '2026_09_05_000001_create_work_log_categories_table',
    '2026_09_05_000002_create_work_log_templates_table',
    '2026_09_05_000003_create_work_logs_table',
    '2026_09_05_000004_create_work_log_attachments_table',
    '2026_09_06_000001_create_work_log_participants_table',
    '2026_09_07_000001_create_workspace_boards_table',
    '2026_09_07_000002_create_workspace_board_documents_table',
    '2026_09_07_000003_create_workspace_board_attachments_table',
    '2026_09_08_000001_soft_delete_attachments',
    '2026_09_08_000002_create_work_order_update_attachments_table',
    '2026_09_07_000001_create_work_log_template_participants_table',
    '2026_09_08_000001_add_parent_job_id_to_work_orders_table',
    '2026_09_08_000002_convert_work_order_subtasks_to_child_work_orders',
    '2026_09_08_000003_add_routine_execution_fields_to_work_logs',
    '2026_09_08_000007_add_archived_at_to_work_order_lists',
    '2026_09_08_000004_add_telegram_columns_to_users_table',
    '2026_09_08_000005_create_telegram_link_tokens_table',
    '2026_09_08_000006_create_telegram_messages_table'
);

-- 2) ตารางใหม่ทั้งหมดต้องครบ 11 ตาราง
SELECT COUNT(*) AS `ต้องได้_11`
FROM information_schema.TABLES
WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME IN (
    'work_log_categories', 'work_log_templates', 'work_logs',
    'work_log_attachments', 'work_log_participants', 'work_log_template_participants',
    'workspace_boards', 'workspace_board_documents', 'workspace_board_attachments',
    'work_order_update_attachments', 'telegram_link_tokens'
);

-- (telegram_messages นับแยกเพราะเป็นตารางที่ 12)
SHOW TABLES LIKE 'telegram\_%';

-- 3) คอลัมน์ใหม่ของงานย่อย — ต้องได้ 2 แถว
SHOW COLUMNS FROM `work_orders` LIKE 'parent\_%';

-- 4) คอลัมน์ใหม่ของงานประจำ — ต้องได้ 6 แถว
SHOW COLUMNS FROM `work_logs` WHERE `Field` IN (
    'planned_start_at', 'planned_end_at', 'late_start_reason',
    'late_completion_reason', 'skip_reason', 'skipped_at'
);

-- 5) แม่แบบงานประจำผูกกับโปรเจกต์/งานได้ — ต้องได้ 2 แถว
SHOW COLUMNS FROM `work_log_templates` WHERE `Field` IN ('work_order_list_id', 'job_id');

-- 6) จัดเก็บโปรเจกต์ — ต้องได้ 1 แถว
SHOW COLUMNS FROM `work_order_lists` LIKE 'archived_at';

-- 7) Telegram ในตาราง users — ต้องได้ 4 แถว
SHOW COLUMNS FROM `users` LIKE 'telegram\_%';

-- 8) ถังขยะไฟล์แนบ — ต้องได้อย่างละ 1 แถว
SHOW COLUMNS FROM `job_images` LIKE 'deleted_at';
SHOW COLUMNS FROM `work_order_list_attachments` LIKE 'deleted_at';

-- 9) หมวดงานตั้งต้น — ต้องได้ 7 แถว
SELECT `id`, `name`, `sort_order` FROM `work_log_categories` ORDER BY `sort_order`;

-- 10) งานย่อยเดิมถูกย้ายขึ้นมาครบ — สองเลขต้องเท่ากัน
--     (ถ้าได้ 0 ทั้งคู่ก็ปกติ แปลว่าไม่เคยมีงานย่อยแบบเก่าอยู่เลย)
SELECT
    (SELECT COUNT(*) FROM `work_orders` WHERE `parent_job_id` IS NOT NULL) AS `งานย่อยใหม่`,
    (SELECT COUNT(DISTINCT `work_order_id`, `title`) FROM `work_order_subtasks`) AS `งานย่อยเดิม`;
