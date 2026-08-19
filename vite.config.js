import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';
import tailwindcss from '@tailwindcss/vite';

export default defineConfig({
    plugins: [
        laravel({
            input: [
                'resources/css/app.css',
                'resources/css/components/layout.css',
                'resources/css/pages/admin-activity-logs.css',
                'resources/css/pages/admin-trash.css',
                'resources/css/pages/auth-login.css',
                'resources/css/pages/auth-setup-password.css',
                'resources/css/pages/auth-welcome.css',
                'resources/css/pages/board.css',
                'resources/css/pages/work-board.css',
                'resources/css/pages/employees.css',
                'resources/css/pages/employee-show.css',
                'resources/css/pages/errors.css',
                'resources/css/pages/mytasks.css',
                'resources/css/pages/notifications.css',
                'resources/css/pages/report-employee.css',
                'resources/css/pages/report-my.css',
                'resources/css/pages/reports.css',
                'resources/css/pages/settings.css',
                'resources/css/pages/task-detail.css',
                'resources/css/pages/tasks.css',
                'resources/js/app.js',
                'resources/js/pages/mytasks/index.js',
            ],
            refresh: true,
        }),
        tailwindcss(),
    ],
    server: {
        watch: {
            ignored: ['**/storage/framework/views/**'],
        },
    },
});
