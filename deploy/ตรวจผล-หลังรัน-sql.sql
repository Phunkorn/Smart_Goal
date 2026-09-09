SELECT `รายการ`, `ได้`, `ต้องได้`, IF(`ได้` = `ต้องได้`, 'OK', '<<< ไม่ครบ') AS `ผล` FROM (
    SELECT '1. migration ทั้ง 3 รอบ' AS `รายการ`, (
        SELECT COUNT(*) FROM `migrations` WHERE `migration` IN (
            '2026_09_05_000001_create_work_log_categories_table',
            '2026_09_05_000002_create_work_log_templates_table',
            '2026_09_05_000003_create_work_logs_table',
            '2026_09_05_000004_create_work_log_attachments_table',
            '2026_09_06_000001_create_work_log_participants_table',
            '2026_09_07_000001_create_workspace_boards_table',
            '2026_09_07_000002_create_workspace_board_documents_table',
            '2026_09_07_000003_create_workspace_board_attachments_table',
            '2026_09_08_000001_soft_delete_attachments',
            '2026_09_08_000002_create_work_order_update_attachments_table',
            '2026_09_07_000001_create_work_log_template_participants_table',
            '2026_09_08_000001_add_parent_job_id_to_work_orders_table',
            '2026_09_08_000002_convert_work_order_subtasks_to_child_work_orders',
            '2026_09_08_000003_add_routine_execution_fields_to_work_logs',
            '2026_09_08_000007_add_archived_at_to_work_order_lists',
            '2026_09_08_000004_add_telegram_columns_to_users_table',
            '2026_09_08_000005_create_telegram_link_tokens_table',
            '2026_09_08_000006_create_telegram_messages_table')
    ) AS `ได้`, 18 AS `ต้องได้`
    UNION ALL SELECT '2. ตารางใหม่', (
        SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME IN (
            'work_log_categories','work_log_templates','work_logs','work_log_attachments',
            'work_log_participants','work_log_template_participants','workspace_boards',
            'workspace_board_documents','workspace_board_attachments',
            'work_order_update_attachments','telegram_link_tokens','telegram_messages')
    ), 12
    UNION ALL SELECT '3. work_orders งานย่อย', (
        SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE()
        AND TABLE_NAME = 'work_orders' AND COLUMN_NAME IN ('parent_job_id','parent_sort_order')
    ), 2
    UNION ALL SELECT '4. work_logs งานประจำ', (
        SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE()
        AND TABLE_NAME = 'work_logs' AND COLUMN_NAME IN ('planned_start_at','planned_end_at',
            'late_start_reason','late_completion_reason','skip_reason','skipped_at')
    ), 6
    UNION ALL SELECT '5. work_log_templates ผูกงาน', (
        SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE()
        AND TABLE_NAME = 'work_log_templates' AND COLUMN_NAME IN ('work_order_list_id','job_id')
    ), 2
    UNION ALL SELECT '6. จัดเก็บโปรเจกต์', (
        SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE()
        AND TABLE_NAME = 'work_order_lists' AND COLUMN_NAME = 'archived_at'
    ), 1
    UNION ALL SELECT '7. Telegram ใน users', (
        SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE()
        AND TABLE_NAME = 'users' AND COLUMN_NAME LIKE 'telegram\_%'
    ), 4
    UNION ALL SELECT '8. ถังขยะไฟล์แนบ', (
        SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE()
        AND TABLE_NAME IN ('job_images','work_order_list_attachments') AND COLUMN_NAME = 'deleted_at'
    ), 2
    UNION ALL SELECT '9. หมวดงานตั้งต้น', (SELECT COUNT(*) FROM `work_log_categories`), 7
    UNION ALL SELECT '10. งานย่อยเดิมถูกย้ายครบ',
        (SELECT COUNT(*) FROM `work_orders` WHERE `parent_job_id` IS NOT NULL),
        (SELECT COUNT(DISTINCT `work_order_id`, `title`) FROM `work_order_subtasks`)
) AS checks;
