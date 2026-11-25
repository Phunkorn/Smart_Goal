-- =====================================================================
-- Smart Goal — รวม SQL อัปฐานข้อมูลรอบ 22–25 พฤศจิกายน 2026 (ไฟล์เดียวจบ)
-- MySQL 8 / MariaDB 10.5+ · สำหรับเซิร์ฟเวอร์ที่ไม่มี SSH/Artisan
--
-- ครอบคลุม 9 migrations ต่อจาก 2026_10_25_000001_add_open_requester_guard_to_tickets_table
--   2026_11_22_000001_create_social_posts_table
--   2026_11_22_000002_create_social_post_media_table
--   2026_11_22_000003_create_social_comments_table
--   2026_11_22_000004_create_social_post_likes_table
--   2026_11_23_000001_add_visibility_and_target_department_to_social_posts_table
--   2026_11_23_000002_create_social_feed_reads_table
--   2026_11_24_000001_add_profile_appearance_to_users_table
--   2026_11_24_000002_create_profile_frames_table      (+ ข้อมูลกรอบโปรไฟล์ 24 แบบ)
--   2026_11_25_000001_add_workspace_collaboration_history
--
-- รันซ้ำได้ และรันได้ไม่ว่าฐานจะอยู่รุ่นไหนในช่วงนี้ — ส่วนที่มีอยู่แล้วจะถูกข้ามเอง
-- จึงใช้แทน 2026-11-24-profile-frame-gallery.sql และ 2026-11-25-workspace-collaboration.sql ได้
-- ไม่มีการลบตาราง คอลัมน์ หรือแถวใด ๆ มีแต่การเพิ่ม
--
-- ก่อนรัน
--   1. สำรองฐานข้อมูล (phpMyAdmin › Export › Quick › SQL) และไฟล์ใน Smart-Private
--   2. ตรวจว่าฐานผ่าน Smart Ticket แล้ว — คำสั่งนี้ต้องได้ 1 แถว:
--        SELECT migration FROM migrations
--        WHERE migration = '2026_10_25_000001_add_open_requester_guard_to_tickets_table';
--   3. ตรวจสิทธิ์อ่าน information_schema — ต้องได้ตัวเลข ไม่ใช่ #1044:
--        SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE();
--   4. ใช้ไฟล์นี้ หรือ php artisan migrate อย่างใดอย่างหนึ่ง ไม่ใช่ทั้งสองแบบแยกกัน
--
-- อัปฐานข้อมูลก่อน แล้วค่อยอัปไฟล์โค้ด ถ้าสลับลำดับเว็บจะขึ้น 500
-- =====================================================================

SET NAMES utf8mb4;

-- ---------------------------------------------------------------------
-- 1) Social feed — 2026_11_22_000001 … 000004
-- ---------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS `social_posts` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `user_id` bigint unsigned NOT NULL,
  `body` text COLLATE utf8mb4_unicode_ci,
  `edited_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `social_posts_user_id_foreign` (`user_id`),
  KEY `social_posts_deleted_id_index` (`deleted_at`,`id`),
  CONSTRAINT `social_posts_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `social_post_media` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `social_post_id` bigint unsigned NOT NULL,
  `disk` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'local',
  `file_path` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `original_name` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `mime_type` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  `file_size` int unsigned NOT NULL,
  `width` smallint unsigned DEFAULT NULL,
  `height` smallint unsigned DEFAULT NULL,
  `sort_order` tinyint unsigned NOT NULL DEFAULT '0',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `social_post_media_post_order_index` (`social_post_id`,`sort_order`),
  CONSTRAINT `social_post_media_social_post_id_foreign` FOREIGN KEY (`social_post_id`) REFERENCES `social_posts` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `social_comments` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `social_post_id` bigint unsigned NOT NULL,
  `user_id` bigint unsigned NOT NULL,
  `parent_id` bigint unsigned DEFAULT NULL,
  `body` text COLLATE utf8mb4_unicode_ci NOT NULL,
  `edited_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `social_comments_user_id_foreign` (`user_id`),
  KEY `social_comments_parent_id_foreign` (`parent_id`),
  KEY `social_comments_post_parent_id_index` (`social_post_id`,`parent_id`,`id`),
  CONSTRAINT `social_comments_parent_id_foreign` FOREIGN KEY (`parent_id`) REFERENCES `social_comments` (`id`) ON DELETE CASCADE,
  CONSTRAINT `social_comments_social_post_id_foreign` FOREIGN KEY (`social_post_id`) REFERENCES `social_posts` (`id`) ON DELETE CASCADE,
  CONSTRAINT `social_comments_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `social_post_likes` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `social_post_id` bigint unsigned NOT NULL,
  `user_id` bigint unsigned NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `social_post_likes_post_user_unique` (`social_post_id`,`user_id`),
  KEY `social_post_likes_user_id_foreign` (`user_id`),
  CONSTRAINT `social_post_likes_social_post_id_foreign` FOREIGN KEY (`social_post_id`) REFERENCES `social_posts` (`id`) ON DELETE CASCADE,
  CONSTRAINT `social_post_likes_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 2) Social visibility + feed reads — 2026_11_23_000001, 000002
--    ALTER แบบมีเงื่อนไข: ข้ามเองถ้ามีคอลัมน์/ดัชนี/FK อยู่แล้ว
-- ---------------------------------------------------------------------

SET @ddl = IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='social_posts' AND COLUMN_NAME='visibility')=0,
 'ALTER TABLE social_posts ADD visibility varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT ''everyone'' AFTER body','SELECT 1');
PREPARE sg_stmt FROM @ddl; EXECUTE sg_stmt; DEALLOCATE PREPARE sg_stmt;

SET @ddl = IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='social_posts' AND COLUMN_NAME='target_department_id')=0,
 'ALTER TABLE social_posts ADD target_department_id bigint unsigned NULL DEFAULT NULL AFTER visibility','SELECT 1');
PREPARE sg_stmt FROM @ddl; EXECUTE sg_stmt; DEALLOCATE PREPARE sg_stmt;

SET @ddl = IF((SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='social_posts' AND CONSTRAINT_NAME='social_posts_target_department_id_foreign')=0,
 'ALTER TABLE social_posts ADD CONSTRAINT social_posts_target_department_id_foreign FOREIGN KEY (target_department_id) REFERENCES departments (id) ON DELETE RESTRICT','SELECT 1');
PREPARE sg_stmt FROM @ddl; EXECUTE sg_stmt; DEALLOCATE PREPARE sg_stmt;

SET @ddl = IF((SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='social_posts' AND INDEX_NAME='social_posts_visibility_department_index')=0,
 'CREATE INDEX social_posts_visibility_department_index ON social_posts (visibility, target_department_id)','SELECT 1');
PREPARE sg_stmt FROM @ddl; EXECUTE sg_stmt; DEALLOCATE PREPARE sg_stmt;

CREATE TABLE IF NOT EXISTS `social_feed_reads` (
  `user_id` bigint unsigned NOT NULL,
  `seen_at` timestamp NOT NULL,
  PRIMARY KEY (`user_id`),
  CONSTRAINT `social_feed_reads_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 3) Profile appearance + frame gallery — 2026_11_24_000001, 000002
-- ---------------------------------------------------------------------

SET @ddl = IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='users' AND COLUMN_NAME='profile_cover')=0,
 'ALTER TABLE users ADD profile_cover varchar(255) COLLATE utf8mb4_unicode_ci NULL DEFAULT NULL','SELECT 1');
PREPARE sg_stmt FROM @ddl; EXECUTE sg_stmt; DEALLOCATE PREPARE sg_stmt;

SET @ddl = IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='users' AND COLUMN_NAME='avatar_frame')=0,
 'ALTER TABLE users ADD avatar_frame varchar(24) COLLATE utf8mb4_unicode_ci NULL DEFAULT NULL','SELECT 1');
PREPARE sg_stmt FROM @ddl; EXECUTE sg_stmt; DEALLOCATE PREPARE sg_stmt;

CREATE TABLE IF NOT EXISTS `profile_frames` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `slug` varchar(24) COLLATE utf8mb4_unicode_ci NOT NULL,
  `name` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `animated` tinyint(1) NOT NULL DEFAULT '0',
  `asset_path` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `preview_path` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `sort_order` smallint unsigned NOT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT '1',
  PRIMARY KEY (`id`),
  UNIQUE KEY `profile_frames_slug_unique` (`slug`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ตรงกับ database/seeders/ProfileFrameSeeder.php — INSERT IGNORE ไม่ทับแถวที่มีอยู่แล้ว
INSERT IGNORE INTO profile_frames (slug, name, animated, asset_path, preview_path, sort_order, is_active) VALUES
  ('sapphire', 'แซฟไฟร์', 0, NULL, NULL, 10, 1),
  ('gold', 'ทอง', 0, NULL, NULL, 20, 1),
  ('emerald', 'มรกต', 0, NULL, NULL, 30, 1),
  ('rose', 'โรส', 0, NULL, NULL, 40, 1),
  ('violet', 'ไวโอเล็ต', 0, NULL, NULL, 50, 1),
  ('aurora', 'ออโรรา', 1, NULL, NULL, 60, 1),
  ('pulse', 'พัลส์', 1, NULL, NULL, 70, 1),
  ('rainbow-cloud', 'เมฆสายรุ้ง', 0, 'static/rainbow-cloud.webp', 'static/rainbow-cloud.webp', 80, 1),
  ('galaxy-voyager', 'นักเดินทางกาแล็กซี', 0, 'static/galaxy-voyager.webp', 'static/galaxy-voyager.webp', 90, 1),
  ('forest-guardian', 'ผู้พิทักษ์พงไพร', 0, 'static/forest-guardian.webp', 'static/forest-guardian.webp', 100, 1),
  ('sweetheart', 'หัวใจแสนหวาน', 0, 'static/sweetheart.webp', 'static/sweetheart.webp', 110, 1),
  ('royal-crown', 'มงกุฎราชวงศ์', 0, 'static/royal-crown.webp', 'static/royal-crown.webp', 120, 1),
  ('star-medal', 'เหรียญดาวเกียรติยศ', 0, 'static/star-medal.webp', 'static/star-medal.webp', 130, 1),
  ('ocean-tide', 'คลื่นมหาสมุทร', 0, 'static/ocean-tide.webp', 'static/ocean-tide.webp', 140, 1),
  ('tech-armor', 'เกราะจักรกล', 0, 'static/tech-armor.webp', 'static/tech-armor.webp', 150, 1),
  ('flame-dragon', 'มังกรเพลิง', 0, 'static/flame-dragon.webp', 'static/flame-dragon.webp', 160, 1),
  ('comic-pop', 'คอมิกป๊อป', 0, 'static/comic-pop.webp', 'static/comic-pop.webp', 170, 1),
  ('star-orbit', 'วงโคจรแห่งดวงดาว', 1, 'animated/star-orbit-motion.webp', 'animated/star-orbit.webp', 180, 1),
  ('lightning-armor', 'เกราะสายฟ้า', 1, 'animated/lightning-armor-motion.webp', 'animated/lightning-armor.webp', 190, 1),
  ('golden-flare', 'ประกายทอง', 1, 'animated/golden-flare-motion.webp', 'animated/golden-flare.webp', 200, 1),
  ('cyber-neon', 'ไซเบอร์นีออน', 1, 'animated/cyber-neon-motion.webp', 'animated/cyber-neon.webp', 210, 1),
  ('space-cat', 'แมวอวกาศ', 1, 'animated/space-cat-motion.webp', 'animated/space-cat.webp', 220, 1),
  ('crystal-energy', 'พลังคริสตัล', 1, 'animated/crystal-energy-motion.webp', 'animated/crystal-energy.webp', 230, 1),
  ('enchanted-forest', 'ป่ามนตราเรืองแสง', 1, 'animated/enchanted-forest-motion.gif', 'animated/enchanted-forest.webp', 240, 1);

-- ---------------------------------------------------------------------
-- 4) Idea Board collaboration history — 2026_11_25_000001
--    ไม่แก้ canvas หรือไฟล์เดิม
-- ---------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS `workspace_board_changes` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `workspace_board_id` bigint unsigned NOT NULL,
  `revision` bigint unsigned NOT NULL,
  `request_id` char(36) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `actor_id` bigint unsigned DEFAULT NULL,
  `request_hash` varchar(64) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `delta` json NOT NULL,
  `byte_size` int unsigned NOT NULL,
  `created_at` timestamp NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `workspace_changes_revision_unique` (`workspace_board_id`,`revision`),
  UNIQUE KEY `workspace_changes_request_unique` (`workspace_board_id`,`request_id`),
  CONSTRAINT `workspace_board_changes_workspace_board_id_foreign` FOREIGN KEY (`workspace_board_id`) REFERENCES `workspace_boards` (`id`) ON DELETE CASCADE,
  CONSTRAINT `workspace_board_changes_actor_id_foreign` FOREIGN KEY (`actor_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET @ddl = IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='workspace_board_attachments' AND COLUMN_NAME='claimed_at')=0,
 'ALTER TABLE workspace_board_attachments ADD claimed_at TIMESTAMP NULL DEFAULT NULL','SELECT 1');
PREPARE sg_stmt FROM @ddl; EXECUTE sg_stmt; DEALLOCATE PREPARE sg_stmt;

SET @ddl = IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='workspace_board_attachments' AND COLUMN_NAME='released_at')=0,
 'ALTER TABLE workspace_board_attachments ADD released_at TIMESTAMP NULL DEFAULT NULL','SELECT 1');
PREPARE sg_stmt FROM @ddl; EXECUTE sg_stmt; DEALLOCATE PREPARE sg_stmt;

SET @ddl = IF((SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='workspace_board_attachments' AND INDEX_NAME='workspace_board_attachments_released_at_index')=0,
 'CREATE INDEX workspace_board_attachments_released_at_index ON workspace_board_attachments (released_at)','SELECT 1');
PREPARE sg_stmt FROM @ddl; EXECUTE sg_stmt; DEALLOCATE PREPARE sg_stmt;

-- ---------------------------------------------------------------------
-- 5) บันทึกลง migrations — เพิ่มเฉพาะชื่อที่ยังไม่มี ใช้ batch ใหม่ batch เดียว
-- ---------------------------------------------------------------------

SET @sg_batch = (SELECT COALESCE(MAX(batch), 0) + 1 FROM migrations);

INSERT INTO migrations (migration, batch)
SELECT m.migration, @sg_batch
FROM (
  SELECT '2026_11_22_000001_create_social_posts_table' AS migration, 1 AS seq
  UNION ALL SELECT '2026_11_22_000002_create_social_post_media_table', 2
  UNION ALL SELECT '2026_11_22_000003_create_social_comments_table', 3
  UNION ALL SELECT '2026_11_22_000004_create_social_post_likes_table', 4
  UNION ALL SELECT '2026_11_23_000001_add_visibility_and_target_department_to_social_posts_table', 5
  UNION ALL SELECT '2026_11_23_000002_create_social_feed_reads_table', 6
  UNION ALL SELECT '2026_11_24_000001_add_profile_appearance_to_users_table', 7
  UNION ALL SELECT '2026_11_24_000002_create_profile_frames_table', 8
  UNION ALL SELECT '2026_11_25_000001_add_workspace_collaboration_history', 9
) AS m
WHERE NOT EXISTS (SELECT 1 FROM migrations x WHERE x.migration = m.migration)
ORDER BY m.seq;

-- ---------------------------------------------------------------------
-- 6) ตรวจผล — ควรได้ 9 แถวในผลแรก และ 24 ในผลที่สอง
-- ---------------------------------------------------------------------

SELECT migration, batch FROM migrations
WHERE migration >= '2026_11_22_000001' ORDER BY migration;

SELECT COUNT(*) AS profile_frames_rows FROM profile_frames;
