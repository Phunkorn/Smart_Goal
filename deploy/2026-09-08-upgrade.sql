-- =============================================================================
-- Smart Goal — SQL อัปเดตฐานข้อมูลรุ่น 2026-09-08
-- =============================================================================
--
-- ไฟล์นี้แทนคำสั่ง `php artisan migrate` สำหรับเซิร์ฟเวอร์ที่ไม่มี SSH
-- ครอบคลุม migration 10 ตัวที่ยังไม่ได้รันบนโปรดักชัน (ตัวถัดจาก
-- 2026_09_03_000001_restore_missing_work_order_subtasks_table เป็นต้นไป)
--
-- สิ่งที่ไฟล์นี้ทำ
--   1) สร้างตารางใหม่ 9 ตาราง (บันทึกงานประจำวัน + กระดานไอเดีย + รูปในคอมเมนต์)
--   2) เพิ่มคอลัมน์ deleted_at ให้ตารางไฟล์แนบเดิม 2 ตาราง
--   3) ใส่หมวดงานตั้งต้น 7 รายการ
--   4) บันทึกลงตาราง migrations เพื่อให้ Laravel รู้ว่ารันไปแล้ว
--
-- คำสั่งทุกบรรทัดเป็นการ "เพิ่ม" เท่านั้น ไม่มีการลบหรือแก้ข้อมูลเดิมแม้แต่แถวเดียว
--
-- -----------------------------------------------------------------------------
-- ก่อนรัน
-- -----------------------------------------------------------------------------
--   1. สำรองฐานข้อมูลก่อนเสมอ (phpMyAdmin > Export > Quick > SQL)
--   2. ตรวจว่ายังไม่เคยรันไฟล์นี้ ด้วยคำสั่ง:
--
--        SELECT migration FROM migrations ORDER BY id DESC LIMIT 5;
--
--      ถ้าแถวบนสุดคือ 2026_09_03_000001_restore_missing_work_order_subtasks_table
--      แปลว่ายังไม่ได้รัน ให้รันไฟล์นี้ได้เลย
--      ถ้าเห็นชื่อที่ขึ้นต้นด้วย 2026_09_05 หรือใหม่กว่าอยู่แล้ว แปลว่ารันไปแล้วบางส่วน
--      ห้ามรันซ้ำทั้งไฟล์ ให้ติดต่อผู้พัฒนาก่อน
--
--   3. รันใน phpMyAdmin > เลือกฐานข้อมูล > แท็บ SQL > วางทั้งไฟล์ > Go
--
-- ถ้ารันแล้วหยุดกลางคัน ให้แก้ตามข้อความ error แล้วรันใหม่ทั้งไฟล์ได้
-- ส่วนสร้างตาราง ส่วนหมวดงาน และส่วนบันทึก migrations ข้ามของที่มีอยู่แล้วให้เอง
-- มีเพียงคำสั่ง ALTER TABLE สองบรรทัดในส่วนที่ 2 ที่จะฟ้อง
-- "#1060 Duplicate column name" ถ้าเคยรันไปแล้ว ซึ่งแปลว่าสำเร็จไปก่อนหน้านี้
-- ไม่ใช่ความเสียหาย ให้ลบสองบรรทัดนั้นออกแล้วรันส่วนที่เหลือต่อ
--
-- ไฟล์นี้ไม่ใช้ information_schema เลย เพราะผู้ใช้ฐานข้อมูลของ shared hosting
-- มักไม่มีสิทธิ์อ่านตารางระบบนั้น (MySQL ตอบ #1044 Access denied)
-- =============================================================================

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
-- หมายเหตุ: สองคำสั่งนี้เป็นจุดเดียวในไฟล์ที่รันซ้ำไม่ได้
-- ถ้าเคยรันไปแล้วจะขึ้น "#1060 - Duplicate column name 'deleted_at'"
-- ซึ่งแปลว่าคอลัมน์มีอยู่แล้ว ไม่ใช่ความเสียหาย ข้ามไปรันส่วนที่ 3 ต่อได้เลย
-- =============================================================================

ALTER TABLE `job_images`
    ADD COLUMN `deleted_at` timestamp NULL DEFAULT NULL;

ALTER TABLE `work_order_list_attachments`
    ADD COLUMN `deleted_at` timestamp NULL DEFAULT NULL;


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


-- =============================================================================
-- ตรวจผลหลังรัน
--
-- ใช้ SHOW TABLES แทนการอ่าน information_schema เพราะผู้ใช้ฐานข้อมูลของ
-- shared hosting มักไม่มีสิทธิ์อ่านตารางระบบนั้น (MySQL ตอบ #1044 Access denied)
-- =============================================================================

-- ควรได้ 10 แถว เลข batch เดียวกันทั้งหมด
SELECT `migration`, `batch` FROM `migrations` WHERE `batch` = @batch ORDER BY `id`;

-- ควรได้ 5 ตาราง
SHOW TABLES LIKE 'work\_log%';

-- ควรได้ 3 ตาราง
SHOW TABLES LIKE 'workspace\_board%';

-- ควรได้ 1 ตาราง
SHOW TABLES LIKE 'work\_order\_update\_attachments';

-- ควรได้ 7 แถว
SELECT `id`, `name`, `sort_order` FROM `work_log_categories` ORDER BY `sort_order`;
