-- MySQL / MariaDB additive upgrade. Back up database + private files first.
-- Use this OR artisan migrate, never both independently. No existing canvas/file is changed.
SET NAMES utf8mb4;
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

-- Conditional ALTERs allow retrying an interrupted manual upgrade.
SET @ddl = IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='workspace_board_attachments' AND COLUMN_NAME='claimed_at')=0,
 'ALTER TABLE workspace_board_attachments ADD claimed_at TIMESTAMP NULL DEFAULT NULL','SELECT 1');
PREPARE workspace_stmt FROM @ddl; EXECUTE workspace_stmt; DEALLOCATE PREPARE workspace_stmt;
SET @ddl = IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='workspace_board_attachments' AND COLUMN_NAME='released_at')=0,
 'ALTER TABLE workspace_board_attachments ADD released_at TIMESTAMP NULL DEFAULT NULL','SELECT 1');
PREPARE workspace_stmt FROM @ddl; EXECUTE workspace_stmt; DEALLOCATE PREPARE workspace_stmt;
SET @ddl = IF((SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='workspace_board_attachments' AND INDEX_NAME='workspace_board_attachments_released_at_index')=0,
 'CREATE INDEX workspace_board_attachments_released_at_index ON workspace_board_attachments (released_at)','SELECT 1');
PREPARE workspace_stmt FROM @ddl; EXECUTE workspace_stmt; DEALLOCATE PREPARE workspace_stmt;

INSERT INTO migrations (migration,batch)
SELECT '2026_11_25_000001_add_workspace_collaboration_history',COALESCE((SELECT MAX(batch) FROM migrations),0)+1
WHERE NOT EXISTS (SELECT 1 FROM migrations WHERE migration='2026_11_25_000001_add_workspace_collaboration_history');
