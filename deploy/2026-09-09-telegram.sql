-- =============================================================================
-- Smart Goal — SQL อัปเดตฐานข้อมูล: การแจ้งเตือนผ่าน Telegram (2026-09-09)
-- =============================================================================
--
-- ไฟล์นี้แทนคำสั่ง `php artisan migrate` สำหรับเซิร์ฟเวอร์ DirectAdmin ที่ไม่มี SSH
-- ครอบคลุม migration 3 ตัว
--   2026_09_08_000004_add_telegram_columns_to_users_table
--   2026_09_08_000005_create_telegram_link_tokens_table
--   2026_09_08_000006_create_telegram_messages_table
--
-- สิ่งที่ไฟล์นี้ทำ
--   1) เพิ่มคอลัมน์ telegram_* 4 คอลัมน์ในตาราง users
--   2) สร้างตารางใหม่ 2 ตาราง (telegram_link_tokens, telegram_messages)
--   3) บันทึกลงตาราง migrations เพื่อให้ Laravel รู้ว่ารันไปแล้ว
--
-- ทุกคำสั่งเป็นการ "เพิ่ม" เท่านั้น ไม่มีการลบหรือแก้ข้อมูลเดิมแม้แต่แถวเดียว
-- และทุกคำสั่งข้ามของที่มีอยู่แล้วให้เอง จึงรันซ้ำได้อย่างปลอดภัย
--
-- -----------------------------------------------------------------------------
-- ก่อนรัน
-- -----------------------------------------------------------------------------
--   1. สำรองฐานข้อมูลก่อนเสมอ (phpMyAdmin > Export > Quick > SQL)
--   2. ต้องรัน deploy/2026-09-08-upgrade.sql ให้เสร็จก่อนไฟล์นี้
--   3. รันใน phpMyAdmin > เลือกฐานข้อมูล > แท็บ SQL > วางทั้งไฟล์ > Go
--   4. หลังรันเสร็จ ให้ลบไฟล์ใน public_html/bootstrap/cache/*.php
--
-- -----------------------------------------------------------------------------
-- หลังรัน ต้องตั้งค่าใน Smart-Private/.env เพิ่มด้วย มิฉะนั้นระบบจะยังไม่ส่งอะไรเลย
-- -----------------------------------------------------------------------------
--   TELEGRAM_NOTIFICATIONS_ENABLED=true
--   TELEGRAM_BOT_TOKEN=<token จาก @BotFather>
--   TELEGRAM_BOT_USERNAME=<ชื่อบอทไม่ต้องมี @>
--   TELEGRAM_WEBHOOK_SECRET=<ค่าสุ่ม 32 ตัวอักษร>
--
-- แล้วลงทะเบียน webhook หนึ่งครั้งโดยเปิด URL นี้ในเบราว์เซอร์
--   https://api.telegram.org/bot<TOKEN>/setWebhook?url=https://<โดเมน>/telegram/webhook&secret_token=<SECRET>
-- =============================================================================


-- =============================================================================
-- 1) คอลัมน์ Telegram ในตาราง users
--
-- MySQL 8 ไม่รองรับ ALTER TABLE ... ADD COLUMN IF NOT EXISTS จึงต้องตรวจก่อน
-- ด้วย information_schema แล้วค่อยประกอบคำสั่งเท่าที่จำเป็น วิธีนี้ทำให้รันซ้ำได้
-- =============================================================================

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


-- =============================================================================
-- ตรวจผลหลังรัน
-- =============================================================================

-- ควรได้ 3 แถว เลข batch เดียวกันทั้งหมด
SELECT `migration`, `batch` FROM `migrations` WHERE `batch` = @batch ORDER BY `id`;

-- ควรได้ 2 ตาราง
SHOW TABLES LIKE 'telegram\_%';

-- ควรได้ 4 คอลัมน์
SHOW COLUMNS FROM `users` LIKE 'telegram\_%';
