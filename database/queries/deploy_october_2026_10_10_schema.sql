/*
 | Smart Goal manual database deployment: 2026-10-09 through 2026-10-10
 |
 | This is the SECOND batch. It continues deploy_october_2026_schema.sql, which
 | ended at migration 2026_10_09_000001. Do not run this file instead of that
 | one - run it after.
 |
 | Target: the production database checked with audit_october_2026_10_10_schema.sql.
 | Preconditions:
 |   1. Back up the production database.
 |   2. Select the Smart Goal database before running this script.
 |   3. deploy_october_2026_schema.sql has already been applied, and its audit
 |      (audit_october_2026_schema.sql) returns an empty result.
 |   4. audit_october_2026_10_10_schema.sql must report all 9 items as missing
 |      before this script runs. Do not run this script a second time.
 |
 | The migration records are inserted last. If phpMyAdmin reports any error,
 | stop and run the audit again before attempting anything else.
 |
 | Running this twice is self-limiting, not destructive: the first ALTER TABLE
 | fails with "Duplicate column name" and nothing after it executes. Never fix
 | such an error by deleting the column - run the audit and see what is actually
 | present first.
 |
 | Nothing here backfills existing rows, by design. Both new columns carry the
 | default that means "no change in behaviour" - every employee stays on
 | "involved" task visibility, and every existing work order keeps its due time.
 | Granting the new visibility to anyone is an explicit admin action in the UI,
 | and a backfill here would hand out cross-department read access that nobody
 | asked for.
 |
 | 2026_10_09_000001 is NOT repeated here. The previous deploy file already
 | created computer_check_sessions with the department-scoped unique key, and
 | it already inserted that migration record.
 */

SET NAMES utf8mb4;

/* -------------------------------------------------------------------------- */
/* Department task visibility: 2026_10_10_000001                              */
/*                                                                            */
/* The read-only "may view this department's work" grant an admin hands to an  */
/* individual employee. This is not a new role and not a permission framework: */
/* the real check stays in WorkOrderPolicy::view() and                         */
/* WorkOrder::scopeVisibleInProjectsFor(). These objects only record what the  */
/* admin configured.                                                          */
/* -------------------------------------------------------------------------- */

ALTER TABLE `users`
  ADD COLUMN `task_visibility_scope` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'involved' AFTER `is_department_head`;

/*
 | Accepted values, enforced in PHP by App\Support\DepartmentTaskVisibility:
 |   'involved'       - only work the employee is involved in (the default)
 |   'own_department' - all work of whatever department the employee is in NOW.
 |                      Deliberately stores no department row, so the grant
 |                      moves with the person when an admin transfers them.
 |   'custom'         - the departments listed in the pivot table below.
 |
 | It is a varchar and not an ENUM on purpose: adding a value to an ENUM is an
 | ALTER that rewrites the table, and the allowed set belongs in one place in
 | the application, not in two that can drift.
 */

/*
 | user_task_visibility_departments has no `id` and no PRIMARY KEY.
 |
 | That is exactly what the Laravel migration produces (foreignId pairs plus a
 | composite unique key, no $table->id()), and this script has to match it or
 | the audit and every later migration will disagree with production. Do not
 | "improve" it by adding a primary key here.
 */
CREATE TABLE `user_task_visibility_departments` (
  `user_id` bigint unsigned NOT NULL,
  `department_id` bigint unsigned NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  UNIQUE KEY `user_task_visibility_departments_user_department_unique` (`user_id`,`department_id`),
  /* Answers the reverse question "who can see this department's work" in the admin screen. */
  KEY `user_task_visibility_departments_department_index` (`department_id`),
  CONSTRAINT `user_task_visibility_departments_department_id_foreign` FOREIGN KEY (`department_id`) REFERENCES `departments` (`id`) ON DELETE CASCADE,
  CONSTRAINT `user_task_visibility_departments_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

/* -------------------------------------------------------------------------- */
/* Unset due time: 2026_10_10_000002                                          */
/*                                                                            */
/* Records the user's intent "this task has a due date but no agreed time".    */
/* work_orders.job_due_at stays NOT NULL and keeps holding the instant that    */
/* decides lateness (23:59 Asia/Bangkok), because TodayWorkspace::             */
/* synchronizeLate compares instants directly. A sentinel time alone could not */
/* tell "no time given" apart from "the user really chose 23:59".              */
/* -------------------------------------------------------------------------- */

ALTER TABLE `work_orders`
  ADD COLUMN `job_due_time_unset` tinyint(1) NOT NULL DEFAULT '0' AFTER `job_due_at`;

/* -------------------------------------------------------------------------- */
/* Mark the equivalent Laravel migrations as complete. This must remain last. */
/* -------------------------------------------------------------------------- */

SET @smart_goal_migration_batch := (
  SELECT COALESCE(MAX(`batch`), 0) + 1
  FROM `migrations`
);

INSERT INTO `migrations` (`migration`, `batch`)
VALUES
  ('2026_10_10_000001_add_department_task_visibility_grant', @smart_goal_migration_batch),
  ('2026_10_10_000002_add_job_due_time_unset_to_work_orders_table', @smart_goal_migration_batch);

SELECT
  DATABASE() AS `database_name`,
  @smart_goal_migration_batch AS `migration_batch`,
  'DEPLOY COMPLETED - run audit_october_2026_10_10_schema.sql again' AS `result`;
