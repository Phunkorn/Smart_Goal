-- MySQL / MariaDB. Generated from both profile migrations and the frame seeder.
-- Select the application database in phpMyAdmin and back it up before importing.
-- Includes profile_cover + avatar_frame required by the department member directory.
-- Existing user values and frame records are preserved; an interrupted import can be retried.
SET NAMES utf8mb4;

SET @ddl = IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='users' AND COLUMN_NAME='profile_cover')=0,
 'ALTER TABLE `users` ADD COLUMN `profile_cover` VARCHAR(255) NULL DEFAULT NULL','SELECT 1');
PREPARE profile_stmt FROM @ddl; EXECUTE profile_stmt; DEALLOCATE PREPARE profile_stmt;

SET @ddl = IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='users' AND COLUMN_NAME='avatar_frame')=0,
 'ALTER TABLE `users` ADD COLUMN `avatar_frame` VARCHAR(24) NULL DEFAULT NULL','SELECT 1');
PREPARE profile_stmt FROM @ddl; EXECUTE profile_stmt; DEALLOCATE PREPARE profile_stmt;

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

SET @profile_batch = (SELECT COALESCE(MAX(batch), 0) + 1 FROM migrations);

INSERT INTO migrations (migration, batch)
SELECT '2026_11_24_000001_add_profile_appearance_to_users_table', @profile_batch
WHERE NOT EXISTS (SELECT 1 FROM migrations WHERE migration = '2026_11_24_000001_add_profile_appearance_to_users_table');

INSERT INTO migrations (migration, batch)
SELECT '2026_11_24_000002_create_profile_frames_table', @profile_batch
WHERE NOT EXISTS (SELECT 1 FROM migrations WHERE migration = '2026_11_24_000002_create_profile_frames_table');

-- Verification: expect both columns, 24 standard frames, and both migration names.
-- Avoid a top-level information_schema SELECT in phpMyAdmin's import results.
SELECT COUNT(*) AS profile_frames_rows FROM profile_frames;
SELECT migration, batch FROM migrations
WHERE migration IN ('2026_11_24_000001_add_profile_appearance_to_users_table', '2026_11_24_000002_create_profile_frames_table');
SHOW COLUMNS FROM `users` WHERE `Field` IN ('profile_cover', 'avatar_frame');
