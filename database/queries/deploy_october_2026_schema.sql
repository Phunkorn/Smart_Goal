/*
 | Smart Goal manual database deployment: 2026-10-06 through 2026-10-08
 |
 | Target: the production database checked with audit_october_2026_schema.sql.
 | Preconditions:
 |   1. Back up the production database.
 |   2. Select the Smart Goal database before running this script.
 |   3. The audit must report all 25 items as missing, as shown before this file
 |      was generated. Do not run this script a second time.
 |
 | The migration records are inserted last. If phpMyAdmin reports any error,
 | stop and run the audit again before attempting anything else.
 |
 | computer_check_sessions is created with its unique key already scoped to the
 | department (department_id, check_date, shift), which is what migration
 | 2026_10_09_000001 enforces. One inspection sheet per department per day per
 | shift - not one for the whole system. If this script was already run with the
 | older global unique key (check_date, shift), do not edit the table by hand:
 | run php artisan migrate, which applies that migration as an ALTER.
 */

SET NAMES utf8mb4;

/* -------------------------------------------------------------------------- */
/* Computer check                                                             */
/* -------------------------------------------------------------------------- */

CREATE TABLE `computer_check_workstations` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `code` varchar(10) COLLATE utf8mb4_unicode_ci NOT NULL,
  `zone` varchar(2) COLLATE utf8mb4_unicode_ci NOT NULL,
  `position_x` smallint unsigned NOT NULL,
  `position_y` smallint unsigned NOT NULL,
  `width` smallint unsigned NOT NULL,
  `height` smallint unsigned NOT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT '1',
  `display_order` smallint unsigned NOT NULL DEFAULT '0',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `computer_check_workstations_code_unique` (`code`),
  KEY `computer_check_workstations_listing_index` (`is_active`, `display_order`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `computer_check_workstations`
  (`code`, `zone`, `position_x`, `position_y`, `width`, `height`, `is_active`, `display_order`, `created_at`, `updated_at`)
VALUES
  ('A-01', 'A', 514, 135, 166, 76, 1, 1, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP),
  ('A-02', 'A', 794, 135, 178, 76, 1, 2, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP),
  ('A-03', 'A', 794, 261, 178, 76, 1, 3, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP),
  ('A-04', 'A', 794, 389, 178, 76, 1, 4, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP),
  ('A-05', 'A', 794, 517, 178, 76, 1, 5, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP),
  ('A-06', 'A', 514, 651, 166, 76, 1, 6, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP),
  ('A-07', 'A', 794, 644, 178, 76, 1, 7, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP),
  ('A-08', 'A', 514, 774, 166, 76, 1, 8, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP),
  ('A-09', 'A', 794, 774, 178, 76, 1, 9, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP),
  ('A-10', 'A', 514, 903, 166, 76, 1, 10, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP),
  ('A-11', 'A', 794, 903, 178, 76, 1, 11, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP),
  ('B-01', 'B', 24, 135, 164, 76, 1, 12, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP),
  ('B-02', 'B', 312, 135, 164, 76, 1, 13, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP),
  ('B-03', 'B', 24, 261, 164, 76, 1, 14, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP),
  ('B-04', 'B', 24, 389, 164, 76, 1, 15, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP),
  ('B-05', 'B', 24, 517, 164, 76, 1, 16, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP),
  ('B-06', 'B', 24, 644, 164, 76, 1, 17, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP),
  ('B-07', 'B', 24, 774, 164, 76, 1, 18, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP),
  ('B-08', 'B', 312, 774, 164, 76, 1, 19, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP),
  ('B-09', 'B', 312, 903, 164, 76, 1, 20, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP);

CREATE TABLE `computer_check_sessions` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `work_log_id` bigint unsigned DEFAULT NULL,
  `department_id` bigint unsigned DEFAULT NULL,
  `check_date` date NOT NULL,
  `shift` varchar(2) COLLATE utf8mb4_unicode_ci NOT NULL,
  `checker_1_id` bigint unsigned NOT NULL,
  `checker_2_id` bigint unsigned DEFAULT NULL,
  `started_at` timestamp NOT NULL,
  `completed_at` timestamp NULL DEFAULT NULL,
  `status` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'in_progress',
  `created_by` bigint unsigned NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `cc_sessions_dept_day_shift_unique` (`department_id`, `check_date`, `shift`),
  KEY `computer_check_sessions_work_log_id_foreign` (`work_log_id`),
  KEY `computer_check_sessions_checker_1_id_foreign` (`checker_1_id`),
  KEY `computer_check_sessions_checker_2_id_foreign` (`checker_2_id`),
  KEY `computer_check_sessions_created_by_foreign` (`created_by`),
  KEY `computer_check_sessions_day_status_index` (`check_date`, `status`),
  KEY `computer_check_sessions_department_index` (`department_id`),
  CONSTRAINT `computer_check_sessions_checker_1_id_foreign` FOREIGN KEY (`checker_1_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `computer_check_sessions_checker_2_id_foreign` FOREIGN KEY (`checker_2_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `computer_check_sessions_created_by_foreign` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `computer_check_sessions_department_id_foreign` FOREIGN KEY (`department_id`) REFERENCES `departments` (`id`) ON DELETE SET NULL,
  CONSTRAINT `computer_check_sessions_work_log_id_foreign` FOREIGN KEY (`work_log_id`) REFERENCES `work_logs` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `computer_check_results` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `computer_check_session_id` bigint unsigned NOT NULL,
  `computer_check_workstation_id` bigint unsigned NOT NULL,
  `keyboard_mouse_status` varchar(10) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'pending',
  `speaker_status` varchar(10) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'pending',
  `microphone_status` varchar(10) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'pending',
  `internet_status` varchar(10) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'pending',
  `computer_status` varchar(10) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'pending',
  `note` text COLLATE utf8mb4_unicode_ci,
  `checked_by` bigint unsigned DEFAULT NULL,
  `checked_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `computer_check_results_session_station_unique` (`computer_check_session_id`, `computer_check_workstation_id`),
  KEY `computer_check_results_computer_check_workstation_id_foreign` (`computer_check_workstation_id`),
  KEY `computer_check_results_checked_by_foreign` (`checked_by`),
  CONSTRAINT `computer_check_results_checked_by_foreign` FOREIGN KEY (`checked_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `computer_check_results_computer_check_session_id_foreign` FOREIGN KEY (`computer_check_session_id`) REFERENCES `computer_check_sessions` (`id`) ON DELETE CASCADE,
  CONSTRAINT `computer_check_results_computer_check_workstation_id_foreign` FOREIGN KEY (`computer_check_workstation_id`) REFERENCES `computer_check_workstations` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

/* -------------------------------------------------------------------------- */
/* Meeting attendee responses                                                  */
/* -------------------------------------------------------------------------- */

ALTER TABLE `meeting_attendees`
  ADD COLUMN `status` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'pending' AFTER `user_id`,
  ADD COLUMN `responded_at` timestamp NULL DEFAULT NULL AFTER `status`,
  ADD COLUMN `decline_reason` text COLLATE utf8mb4_unicode_ci AFTER `responded_at`,
  ADD KEY `meeting_attendees_meeting_id_status_index` (`meeting_id`, `status`);

CREATE TABLE `meeting_attendee_response_events` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `meeting_id` bigint unsigned NOT NULL,
  `user_id` bigint unsigned NOT NULL,
  `actor_user_id` bigint unsigned DEFAULT NULL,
  `event` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL,
  `decline_reason` text COLLATE utf8mb4_unicode_ci,
  `occurred_at` timestamp NOT NULL,
  PRIMARY KEY (`id`),
  KEY `meeting_attendee_response_events_user_id_foreign` (`user_id`),
  KEY `meeting_attendee_response_events_actor_user_id_foreign` (`actor_user_id`),
  KEY `meeting_attendee_events_meeting_user_occurred_index` (`meeting_id`, `user_id`, `occurred_at`),
  CONSTRAINT `meeting_attendee_response_events_actor_user_id_foreign` FOREIGN KEY (`actor_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `meeting_attendee_response_events_meeting_id_foreign` FOREIGN KEY (`meeting_id`) REFERENCES `meetings` (`id`) ON DELETE CASCADE,
  CONSTRAINT `meeting_attendee_response_events_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

/* -------------------------------------------------------------------------- */
/* Appointments and calendar                                                   */
/* -------------------------------------------------------------------------- */

ALTER TABLE `meetings`
  ADD COLUMN `map_url` varchar(2048) COLLATE utf8mb4_unicode_ci DEFAULT NULL AFTER `location`;

CREATE TABLE `appointments` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `created_by` bigint unsigned NOT NULL,
  `department_id` bigint unsigned DEFAULT NULL,
  `visibility` varchar(16) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'personal',
  `title` varchar(160) COLLATE utf8mb4_unicode_ci NOT NULL,
  `appointment_date` date NOT NULL,
  `starts_at_time` time NOT NULL,
  `ends_at_time` time DEFAULT NULL,
  `location` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `map_url` varchar(2048) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `description` text COLLATE utf8mb4_unicode_ci,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `appointments_created_by_appointment_date_index` (`created_by`, `appointment_date`),
  KEY `appointments_appointment_date_index` (`appointment_date`),
  KEY `appointments_department_id_foreign` (`department_id`),
  KEY `appt_scope_date_idx` (`visibility`, `department_id`, `appointment_date`),
  CONSTRAINT `appointments_created_by_foreign` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `appointments_department_id_foreign` FOREIGN KEY (`department_id`) REFERENCES `departments` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `appointment_attendees` (
  `appointment_id` bigint unsigned NOT NULL,
  `user_id` bigint unsigned NOT NULL,
  `status` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'pending',
  `responded_at` timestamp NULL DEFAULT NULL,
  `decline_reason` text COLLATE utf8mb4_unicode_ci,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  UNIQUE KEY `appointment_attendees_appointment_user_unique` (`appointment_id`, `user_id`),
  KEY `appointment_attendees_user_id_foreign` (`user_id`),
  KEY `appointment_attendees_appointment_status_index` (`appointment_id`, `status`),
  CONSTRAINT `appointment_attendees_appointment_id_foreign` FOREIGN KEY (`appointment_id`) REFERENCES `appointments` (`id`) ON DELETE CASCADE,
  CONSTRAINT `appointment_attendees_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `appointment_attendee_response_events` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `appointment_id` bigint unsigned NOT NULL,
  `user_id` bigint unsigned NOT NULL,
  `actor_user_id` bigint unsigned DEFAULT NULL,
  `event` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL,
  `decline_reason` text COLLATE utf8mb4_unicode_ci,
  `occurred_at` timestamp NOT NULL,
  PRIMARY KEY (`id`),
  KEY `appointment_attendee_response_events_user_id_foreign` (`user_id`),
  KEY `appointment_attendee_response_events_actor_user_id_foreign` (`actor_user_id`),
  KEY `appointment_attendee_events_appt_user_occurred_index` (`appointment_id`, `user_id`, `occurred_at`),
  CONSTRAINT `appointment_attendee_response_events_actor_user_id_foreign` FOREIGN KEY (`actor_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `appointment_attendee_response_events_appointment_id_foreign` FOREIGN KEY (`appointment_id`) REFERENCES `appointments` (`id`) ON DELETE CASCADE,
  CONSTRAINT `appointment_attendee_response_events_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

/* -------------------------------------------------------------------------- */
/* Department feature access                                                   */
/* -------------------------------------------------------------------------- */

ALTER TABLE `departments`
  ADD COLUMN `computer_check_enabled` tinyint(1) NOT NULL DEFAULT '0' AFTER `department_name`;

/* Keep the Laravel backfill behavior if sessions were inserted during deploy. */
UPDATE `departments` AS `department`
INNER JOIN (
  SELECT DISTINCT COALESCE(`session`.`department_id`, `checker`.`department_id`) AS `department_id`
  FROM `computer_check_sessions` AS `session`
  LEFT JOIN `users` AS `checker` ON `checker`.`id` = `session`.`checker_1_id`
) AS `used_department`
  ON `used_department`.`department_id` = `department`.`id`
SET `department`.`computer_check_enabled` = 1
WHERE `used_department`.`department_id` IS NOT NULL;

/* -------------------------------------------------------------------------- */
/* Mark the equivalent Laravel migrations as complete. This must remain last. */
/* -------------------------------------------------------------------------- */

SET @smart_goal_migration_batch := (
  SELECT COALESCE(MAX(`batch`), 0) + 1
  FROM `migrations`
);

INSERT INTO `migrations` (`migration`, `batch`)
VALUES
  ('2026_10_06_000001_create_computer_check_workstations_table', @smart_goal_migration_batch),
  ('2026_10_06_000002_create_computer_check_sessions_table', @smart_goal_migration_batch),
  ('2026_10_06_000003_create_computer_check_results_table', @smart_goal_migration_batch),
  ('2026_10_07_000001_add_attendee_response_to_meeting_attendees_table', @smart_goal_migration_batch),
  ('2026_10_07_000002_create_meeting_attendee_response_events_table', @smart_goal_migration_batch),
  ('2026_10_08_000001_create_appointments_table', @smart_goal_migration_batch),
  ('2026_10_08_000002_add_map_url_to_meetings_and_appointments_tables', @smart_goal_migration_batch),
  ('2026_10_08_000003_create_appointment_attendees_table', @smart_goal_migration_batch),
  ('2026_10_08_000004_create_appointment_attendee_response_events_table', @smart_goal_migration_batch),
  ('2026_10_08_000005_add_department_scope_to_appointments_table', @smart_goal_migration_batch),
  ('2026_10_08_000006_add_computer_check_access_to_departments_table', @smart_goal_migration_batch),
  ('2026_10_09_000001_scope_computer_check_sessions_unique_to_department', @smart_goal_migration_batch);

SELECT
  DATABASE() AS `database_name`,
  @smart_goal_migration_batch AS `migration_batch`,
  'DEPLOY COMPLETED - run audit_october_2026_schema.sql again' AS `result`;
