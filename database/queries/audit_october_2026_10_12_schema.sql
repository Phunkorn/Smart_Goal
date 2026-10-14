/*
 | Smart Goal schema audit for the migration added on 2026-10-12.
 |
 | Read-only: this query only reads migrations and information_schema.
 | Run it while connected to the Smart Goal database. An empty result means the
 | migration record is present and work_orders.job_due_at accepts NULL.
 |
 | This audit covers the third deploy batch only. The earlier batches have
 | their own audits (audit_october_2026_schema.sql and
 | audit_october_2026_10_10_schema.sql), and all three must come back empty
 | before the database is considered up to date.
 |
 | 2 items are expected when nothing has been deployed yet:
 | 1 migration, 1 column that is still NOT NULL.
 |
 | This batch adds no table, column, or index - it only relaxes an existing
 | column - so the check is on IS_NULLABLE instead of on the column existing.
 */

WITH
expected_migrations AS (
    SELECT '2026_10_12_000001_allow_work_orders_without_due_date' AS migration
),
expected_nullable_columns AS (
    SELECT 'work_orders' AS table_name, 'job_due_at' AS column_name, '2026_10_12_000001_allow_work_orders_without_due_date' AS source_migration
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
        'column',
        expected.table_name,
        expected.column_name,
        expected.source_migration,
        'column is still NOT NULL'
    FROM expected_nullable_columns AS expected
    WHERE NOT EXISTS (
        SELECT 1
        FROM information_schema.columns AS actual
        WHERE actual.table_schema = DATABASE()
          AND actual.table_name = expected.table_name
          AND actual.column_name = expected.column_name
          AND actual.is_nullable = 'YES'
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
    FIELD(object_type, 'migration', 'column'),
    table_name,
    object_name;
