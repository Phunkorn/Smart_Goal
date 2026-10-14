/*
 | Smart Goal manual database deployment: 2026-10-12
 |
 | This is the THIRD batch. It continues deploy_october_2026_10_10_schema.sql,
 | which ended at migration 2026_10_10_000002. Do not run this file instead of
 | the earlier ones - run it after.
 |
 | Target: the production database checked with audit_october_2026_10_12_schema.sql.
 | Preconditions:
 |   1. Back up the production database.
 |   2. Select the Smart Goal database before running this script.
 |   3. Both earlier deploy files have already been applied, and both of their
 |      audits (audit_october_2026_schema.sql and
 |      audit_october_2026_10_10_schema.sql) return an empty result.
 |   4. audit_october_2026_10_12_schema.sql must report both items before this
 |      script runs.
 |
 | The migration record is inserted last. If phpMyAdmin reports any error,
 | stop and run the audit again before attempting anything else.
 |
 | Unlike the earlier batches, running the ALTER below twice does not fail:
 | MODIFY to the definition a column already has is a no-op. The INSERT at the
 | end is what must not be repeated - a second run would add a duplicate
 | migration record. Run the audit first; if it is empty, do not run this file.
 |
 | Nothing here changes existing rows. Every existing work order keeps the due
 | date it has. A task only loses its due date when a user chooses
 | "ไม่มีกำหนดส่ง" in the UI.
 */

SET NAMES utf8mb4;

/* -------------------------------------------------------------------------- */
/* No due date: 2026_10_12_000001                                             */
/*                                                                            */
/* A task may now have no due date at all. That is stored as NULL in           */
/* work_orders.job_due_at, not as a flag beside a leftover date: a flag would  */
/* have to be known by every reader of the column (lateness, reports,          */
/* calendar, sorting), and any reader that forgot it would show a due date     */
/* the user removed. NULL is excluded from `job_due_at < NOW()` by MySQL       */
/* itself, so TodayWorkspace::synchronizeLate never marks such a task late.    */
/*                                                                            */
/* This is a different thing from job_due_time_unset (2026_10_10_000002),      */
/* which still has a due DATE and still becomes late at the end of that day.   */
/*                                                                            */
/* The column type must stay `datetime` exactly as the original migration      */
/* created it. Do not add a DEFAULT here: Laravel's ->nullable()->change()     */
/* produces precisely this statement.                                         */
/* -------------------------------------------------------------------------- */

ALTER TABLE `work_orders`
  MODIFY `job_due_at` datetime NULL;

/* -------------------------------------------------------------------------- */
/* Mark the equivalent Laravel migration as complete. This must remain last.  */
/* -------------------------------------------------------------------------- */

SET @smart_goal_migration_batch := (
  SELECT COALESCE(MAX(`batch`), 0) + 1
  FROM `migrations`
);

INSERT INTO `migrations` (`migration`, `batch`)
VALUES
  ('2026_10_12_000001_allow_work_orders_without_due_date', @smart_goal_migration_batch);

SELECT
  DATABASE() AS `database_name`,
  @smart_goal_migration_batch AS `migration_batch`,
  'DEPLOY COMPLETED - run audit_october_2026_10_12_schema.sql again' AS `result`;
