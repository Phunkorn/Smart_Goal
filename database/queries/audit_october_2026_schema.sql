/*
 | Smart Goal schema audit for migrations added on 2026-10-06 through 2026-10-08.
 |
 | Read-only: this query only reads migrations and information_schema.
 | Run it while connected to the Smart Goal database. An empty result means that
 | every expected migration record, table, column, and application-defined index
 | is present in the currently selected database.
 */

WITH
expected_migrations AS (
    SELECT '2026_10_06_000001_create_computer_check_workstations_table' AS migration
    UNION ALL SELECT '2026_10_06_000002_create_computer_check_sessions_table'
    UNION ALL SELECT '2026_10_06_000003_create_computer_check_results_table'
    UNION ALL SELECT '2026_10_07_000001_add_attendee_response_to_meeting_attendees_table'
    UNION ALL SELECT '2026_10_07_000002_create_meeting_attendee_response_events_table'
    UNION ALL SELECT '2026_10_08_000001_create_appointments_table'
    UNION ALL SELECT '2026_10_08_000002_add_map_url_to_meetings_and_appointments_tables'
    UNION ALL SELECT '2026_10_08_000003_create_appointment_attendees_table'
    UNION ALL SELECT '2026_10_08_000004_create_appointment_attendee_response_events_table'
    UNION ALL SELECT '2026_10_08_000005_add_department_scope_to_appointments_table'
    UNION ALL SELECT '2026_10_08_000006_add_computer_check_access_to_departments_table'
    UNION ALL SELECT '2026_10_09_000001_scope_computer_check_sessions_unique_to_department'
),
expected_tables AS (
    SELECT 'computer_check_workstations' AS table_name, '2026_10_06_000001_create_computer_check_workstations_table' AS source_migration
    UNION ALL SELECT 'computer_check_sessions', '2026_10_06_000002_create_computer_check_sessions_table'
    UNION ALL SELECT 'computer_check_results', '2026_10_06_000003_create_computer_check_results_table'
    UNION ALL SELECT 'meeting_attendee_response_events', '2026_10_07_000002_create_meeting_attendee_response_events_table'
    UNION ALL SELECT 'appointments', '2026_10_08_000001_create_appointments_table'
    UNION ALL SELECT 'appointment_attendees', '2026_10_08_000003_create_appointment_attendees_table'
    UNION ALL SELECT 'appointment_attendee_response_events', '2026_10_08_000004_create_appointment_attendee_response_events_table'
),
expected_columns AS (
    SELECT 'computer_check_workstations' AS table_name, 'id' AS column_name, '2026_10_06_000001_create_computer_check_workstations_table' AS source_migration
    UNION ALL SELECT 'computer_check_workstations', 'code', '2026_10_06_000001_create_computer_check_workstations_table'
    UNION ALL SELECT 'computer_check_workstations', 'zone', '2026_10_06_000001_create_computer_check_workstations_table'
    UNION ALL SELECT 'computer_check_workstations', 'position_x', '2026_10_06_000001_create_computer_check_workstations_table'
    UNION ALL SELECT 'computer_check_workstations', 'position_y', '2026_10_06_000001_create_computer_check_workstations_table'
    UNION ALL SELECT 'computer_check_workstations', 'width', '2026_10_06_000001_create_computer_check_workstations_table'
    UNION ALL SELECT 'computer_check_workstations', 'height', '2026_10_06_000001_create_computer_check_workstations_table'
    UNION ALL SELECT 'computer_check_workstations', 'is_active', '2026_10_06_000001_create_computer_check_workstations_table'
    UNION ALL SELECT 'computer_check_workstations', 'display_order', '2026_10_06_000001_create_computer_check_workstations_table'
    UNION ALL SELECT 'computer_check_workstations', 'created_at', '2026_10_06_000001_create_computer_check_workstations_table'
    UNION ALL SELECT 'computer_check_workstations', 'updated_at', '2026_10_06_000001_create_computer_check_workstations_table'

    UNION ALL SELECT 'computer_check_sessions', 'id', '2026_10_06_000002_create_computer_check_sessions_table'
    UNION ALL SELECT 'computer_check_sessions', 'work_log_id', '2026_10_06_000002_create_computer_check_sessions_table'
    UNION ALL SELECT 'computer_check_sessions', 'department_id', '2026_10_06_000002_create_computer_check_sessions_table'
    UNION ALL SELECT 'computer_check_sessions', 'check_date', '2026_10_06_000002_create_computer_check_sessions_table'
    UNION ALL SELECT 'computer_check_sessions', 'shift', '2026_10_06_000002_create_computer_check_sessions_table'
    UNION ALL SELECT 'computer_check_sessions', 'checker_1_id', '2026_10_06_000002_create_computer_check_sessions_table'
    UNION ALL SELECT 'computer_check_sessions', 'checker_2_id', '2026_10_06_000002_create_computer_check_sessions_table'
    UNION ALL SELECT 'computer_check_sessions', 'started_at', '2026_10_06_000002_create_computer_check_sessions_table'
    UNION ALL SELECT 'computer_check_sessions', 'completed_at', '2026_10_06_000002_create_computer_check_sessions_table'
    UNION ALL SELECT 'computer_check_sessions', 'status', '2026_10_06_000002_create_computer_check_sessions_table'
    UNION ALL SELECT 'computer_check_sessions', 'created_by', '2026_10_06_000002_create_computer_check_sessions_table'
    UNION ALL SELECT 'computer_check_sessions', 'created_at', '2026_10_06_000002_create_computer_check_sessions_table'
    UNION ALL SELECT 'computer_check_sessions', 'updated_at', '2026_10_06_000002_create_computer_check_sessions_table'

    UNION ALL SELECT 'computer_check_results', 'id', '2026_10_06_000003_create_computer_check_results_table'
    UNION ALL SELECT 'computer_check_results', 'computer_check_session_id', '2026_10_06_000003_create_computer_check_results_table'
    UNION ALL SELECT 'computer_check_results', 'computer_check_workstation_id', '2026_10_06_000003_create_computer_check_results_table'
    UNION ALL SELECT 'computer_check_results', 'keyboard_mouse_status', '2026_10_06_000003_create_computer_check_results_table'
    UNION ALL SELECT 'computer_check_results', 'speaker_status', '2026_10_06_000003_create_computer_check_results_table'
    UNION ALL SELECT 'computer_check_results', 'microphone_status', '2026_10_06_000003_create_computer_check_results_table'
    UNION ALL SELECT 'computer_check_results', 'internet_status', '2026_10_06_000003_create_computer_check_results_table'
    UNION ALL SELECT 'computer_check_results', 'computer_status', '2026_10_06_000003_create_computer_check_results_table'
    UNION ALL SELECT 'computer_check_results', 'note', '2026_10_06_000003_create_computer_check_results_table'
    UNION ALL SELECT 'computer_check_results', 'checked_by', '2026_10_06_000003_create_computer_check_results_table'
    UNION ALL SELECT 'computer_check_results', 'checked_at', '2026_10_06_000003_create_computer_check_results_table'
    UNION ALL SELECT 'computer_check_results', 'created_at', '2026_10_06_000003_create_computer_check_results_table'
    UNION ALL SELECT 'computer_check_results', 'updated_at', '2026_10_06_000003_create_computer_check_results_table'

    UNION ALL SELECT 'meeting_attendees', 'status', '2026_10_07_000001_add_attendee_response_to_meeting_attendees_table'
    UNION ALL SELECT 'meeting_attendees', 'responded_at', '2026_10_07_000001_add_attendee_response_to_meeting_attendees_table'
    UNION ALL SELECT 'meeting_attendees', 'decline_reason', '2026_10_07_000001_add_attendee_response_to_meeting_attendees_table'

    UNION ALL SELECT 'meeting_attendee_response_events', 'id', '2026_10_07_000002_create_meeting_attendee_response_events_table'
    UNION ALL SELECT 'meeting_attendee_response_events', 'meeting_id', '2026_10_07_000002_create_meeting_attendee_response_events_table'
    UNION ALL SELECT 'meeting_attendee_response_events', 'user_id', '2026_10_07_000002_create_meeting_attendee_response_events_table'
    UNION ALL SELECT 'meeting_attendee_response_events', 'actor_user_id', '2026_10_07_000002_create_meeting_attendee_response_events_table'
    UNION ALL SELECT 'meeting_attendee_response_events', 'event', '2026_10_07_000002_create_meeting_attendee_response_events_table'
    UNION ALL SELECT 'meeting_attendee_response_events', 'decline_reason', '2026_10_07_000002_create_meeting_attendee_response_events_table'
    UNION ALL SELECT 'meeting_attendee_response_events', 'occurred_at', '2026_10_07_000002_create_meeting_attendee_response_events_table'

    UNION ALL SELECT 'appointments', 'id', '2026_10_08_000001_create_appointments_table'
    UNION ALL SELECT 'appointments', 'created_by', '2026_10_08_000001_create_appointments_table'
    UNION ALL SELECT 'appointments', 'title', '2026_10_08_000001_create_appointments_table'
    UNION ALL SELECT 'appointments', 'appointment_date', '2026_10_08_000001_create_appointments_table'
    UNION ALL SELECT 'appointments', 'starts_at_time', '2026_10_08_000001_create_appointments_table'
    UNION ALL SELECT 'appointments', 'ends_at_time', '2026_10_08_000001_create_appointments_table'
    UNION ALL SELECT 'appointments', 'location', '2026_10_08_000001_create_appointments_table'
    UNION ALL SELECT 'appointments', 'description', '2026_10_08_000001_create_appointments_table'
    UNION ALL SELECT 'appointments', 'created_at', '2026_10_08_000001_create_appointments_table'
    UNION ALL SELECT 'appointments', 'updated_at', '2026_10_08_000001_create_appointments_table'
    UNION ALL SELECT 'appointments', 'deleted_at', '2026_10_08_000001_create_appointments_table'
    UNION ALL SELECT 'meetings', 'map_url', '2026_10_08_000002_add_map_url_to_meetings_and_appointments_tables'
    UNION ALL SELECT 'appointments', 'map_url', '2026_10_08_000002_add_map_url_to_meetings_and_appointments_tables'
    UNION ALL SELECT 'appointments', 'department_id', '2026_10_08_000005_add_department_scope_to_appointments_table'
    UNION ALL SELECT 'appointments', 'visibility', '2026_10_08_000005_add_department_scope_to_appointments_table'

    UNION ALL SELECT 'appointment_attendees', 'appointment_id', '2026_10_08_000003_create_appointment_attendees_table'
    UNION ALL SELECT 'appointment_attendees', 'user_id', '2026_10_08_000003_create_appointment_attendees_table'
    UNION ALL SELECT 'appointment_attendees', 'status', '2026_10_08_000003_create_appointment_attendees_table'
    UNION ALL SELECT 'appointment_attendees', 'responded_at', '2026_10_08_000003_create_appointment_attendees_table'
    UNION ALL SELECT 'appointment_attendees', 'decline_reason', '2026_10_08_000003_create_appointment_attendees_table'
    UNION ALL SELECT 'appointment_attendees', 'created_at', '2026_10_08_000003_create_appointment_attendees_table'
    UNION ALL SELECT 'appointment_attendees', 'updated_at', '2026_10_08_000003_create_appointment_attendees_table'

    UNION ALL SELECT 'appointment_attendee_response_events', 'id', '2026_10_08_000004_create_appointment_attendee_response_events_table'
    UNION ALL SELECT 'appointment_attendee_response_events', 'appointment_id', '2026_10_08_000004_create_appointment_attendee_response_events_table'
    UNION ALL SELECT 'appointment_attendee_response_events', 'user_id', '2026_10_08_000004_create_appointment_attendee_response_events_table'
    UNION ALL SELECT 'appointment_attendee_response_events', 'actor_user_id', '2026_10_08_000004_create_appointment_attendee_response_events_table'
    UNION ALL SELECT 'appointment_attendee_response_events', 'event', '2026_10_08_000004_create_appointment_attendee_response_events_table'
    UNION ALL SELECT 'appointment_attendee_response_events', 'decline_reason', '2026_10_08_000004_create_appointment_attendee_response_events_table'
    UNION ALL SELECT 'appointment_attendee_response_events', 'occurred_at', '2026_10_08_000004_create_appointment_attendee_response_events_table'

    UNION ALL SELECT 'departments', 'computer_check_enabled', '2026_10_08_000006_add_computer_check_access_to_departments_table'
),
expected_indexes AS (
    SELECT 'computer_check_workstations' AS table_name, 'computer_check_workstations_code_unique' AS index_name, '2026_10_06_000001_create_computer_check_workstations_table' AS source_migration
    UNION ALL SELECT 'computer_check_workstations', 'computer_check_workstations_listing_index', '2026_10_06_000001_create_computer_check_workstations_table'
    UNION ALL SELECT 'computer_check_sessions', 'cc_sessions_dept_day_shift_unique', '2026_10_09_000001_scope_computer_check_sessions_unique_to_department'
    UNION ALL SELECT 'computer_check_sessions', 'computer_check_sessions_day_status_index', '2026_10_06_000002_create_computer_check_sessions_table'
    UNION ALL SELECT 'computer_check_sessions', 'computer_check_sessions_department_index', '2026_10_06_000002_create_computer_check_sessions_table'
    UNION ALL SELECT 'computer_check_results', 'computer_check_results_session_station_unique', '2026_10_06_000003_create_computer_check_results_table'
    UNION ALL SELECT 'meeting_attendees', 'meeting_attendees_meeting_id_status_index', '2026_10_07_000001_add_attendee_response_to_meeting_attendees_table'
    UNION ALL SELECT 'meeting_attendee_response_events', 'meeting_attendee_events_meeting_user_occurred_index', '2026_10_07_000002_create_meeting_attendee_response_events_table'
    UNION ALL SELECT 'appointments', 'appointments_appointment_date_index', '2026_10_08_000001_create_appointments_table'
    UNION ALL SELECT 'appointments', 'appointments_created_by_appointment_date_index', '2026_10_08_000001_create_appointments_table'
    UNION ALL SELECT 'appointment_attendees', 'appointment_attendees_appointment_user_unique', '2026_10_08_000003_create_appointment_attendees_table'
    UNION ALL SELECT 'appointment_attendees', 'appointment_attendees_appointment_status_index', '2026_10_08_000003_create_appointment_attendees_table'
    UNION ALL SELECT 'appointment_attendee_response_events', 'appointment_attendee_events_appt_user_occurred_index', '2026_10_08_000004_create_appointment_attendee_response_events_table'
    UNION ALL SELECT 'appointments', 'appt_scope_date_idx', '2026_10_08_000005_add_department_scope_to_appointments_table'
),
missing_items AS (
    SELECT
        'migration' AS object_type,
        CAST(NULL AS CHAR(64)) AS table_name,
        expected.migration AS object_name,
        expected.migration AS source_migration,
        'migration record is missing' AS problem
    FROM expected_migrations AS expected
    WHERE NOT EXISTS (
        SELECT 1
        FROM migrations AS actual
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
