/*
 | Smart Goal schema audit for migrations added on 2026-10-10.
 |
 | Read-only: this query only reads migrations and information_schema.
 | Run it while connected to the Smart Goal database. An empty result means that
 | every expected migration record, table, column, and application-defined index
 | is present in the currently selected database.
 |
 | This audit covers the second deploy batch only. The first batch
 | (2026-10-06 through 2026-10-09) has its own audit in
 | audit_october_2026_schema.sql, and both must come back empty before the
 | database is considered up to date.
 |
 | 9 items are expected when nothing has been deployed yet:
 | 2 migrations, 1 table, 4 columns, 2 indexes.
 */

WITH
expected_migrations AS (
    SELECT '2026_10_10_000001_add_department_task_visibility_grant' AS migration
    UNION ALL SELECT '2026_10_10_000002_add_job_due_time_unset_to_work_orders_table'
),
expected_tables AS (
    SELECT 'user_task_visibility_departments' AS table_name, '2026_10_10_000001_add_department_task_visibility_grant' AS source_migration
),
expected_columns AS (
    SELECT 'users' AS table_name, 'task_visibility_scope' AS column_name, '2026_10_10_000001_add_department_task_visibility_grant' AS source_migration

    UNION ALL SELECT 'user_task_visibility_departments', 'user_id', '2026_10_10_000001_add_department_task_visibility_grant'
    UNION ALL SELECT 'user_task_visibility_departments', 'department_id', '2026_10_10_000001_add_department_task_visibility_grant'

    UNION ALL SELECT 'work_orders', 'job_due_time_unset', '2026_10_10_000002_add_job_due_time_unset_to_work_orders_table'
),
/*
 | Only indexes the migration names itself are listed.
 |
 | The foreign key on user_id gets no index of its own: MySQL reuses the
 | composite unique key, whose leftmost column it is. Auditing an index that
 | MySQL never creates would report a permanent false positive.
 */
expected_indexes AS (
    SELECT 'user_task_visibility_departments' AS table_name, 'user_task_visibility_departments_user_department_unique' AS index_name, '2026_10_10_000001_add_department_task_visibility_grant' AS source_migration
    UNION ALL SELECT 'user_task_visibility_departments', 'user_task_visibility_departments_department_index', '2026_10_10_000001_add_department_task_visibility_grant'
),
missing_items AS (
    SELECT
        'migration' AS object_type,
        'migrations' AS table_name,
        expected.migration AS object_name,
        expected.migration AS source_migration,
        'migration record is missing' AS problem
    FROM expected_migrations AS expected
    WHERE NOT EXISTS (
        SELECT 1
        FROM `migrations` AS actual
        WHERE actual.migration = expected.migration
    )

    UNION ALL

    SELECT
        'table',
        expected.table_name,
        expected.table_name,
        expected.source_migration,
        'table is missing'
    FROM expected_tables AS expected
    WHERE NOT EXISTS (
        SELECT 1
        FROM information_schema.tables AS actual
        WHERE actual.table_schema = DATABASE()
          AND actual.table_name = expected.table_name
          AND actual.table_type = 'BASE TABLE'
    )

    UNION ALL

    SELECT
        'column',
        expected.table_name,
        expected.column_name,
        expected.source_migration,
        'column is missing'
    FROM expected_columns AS expected
    WHERE EXISTS (
        SELECT 1
        FROM information_schema.tables AS parent_table
        WHERE parent_table.table_schema = DATABASE()
          AND parent_table.table_name = expected.table_name
          AND parent_table.table_type = 'BASE TABLE'
    )
      AND NOT EXISTS (
        SELECT 1
        FROM information_schema.columns AS actual
        WHERE actual.table_schema = DATABASE()
          AND actual.table_name = expected.table_name
          AND actual.column_name = expected.column_name
    )

    UNION ALL

    SELECT
        'index',
        expected.table_name,
        expected.index_name,
        expected.source_migration,
        'index is missing'
    FROM expected_indexes AS expected
    WHERE EXISTS (
        SELECT 1
        FROM information_schema.tables AS parent_table
        WHERE parent_table.table_schema = DATABASE()
          AND parent_table.table_name = expected.table_name
          AND parent_table.table_type = 'BASE TABLE'
    )
      AND NOT EXISTS (
        SELECT 1
        FROM information_schema.statistics AS actual
        WHERE actual.table_schema = DATABASE()
          AND actual.table_name = expected.table_name
          AND actual.index_name = expected.index_name
    )
)
SELECT
    DATABASE() AS database_name,
    object_type,
    table_name,
    object_name,
    source_migration,
    problem
FROM missing_items
ORDER BY
    FIELD(object_type, 'migration', 'table', 'column', 'index'),
    table_name,
    object_name;
