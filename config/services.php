<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    /*
    | Telegram — แจ้งเตือนรายบุคคลผ่านแชทส่วนตัวของบอท
    |
    | ไม่มีการสร้างกลุ่ม ผู้ใช้แต่ละคนผูกบัญชีเองจากหน้าตั้งค่าแล้วระบบเก็บ chat_id ไว้
    | เครื่อง production ไม่มี queue worker และไม่มี cron การส่งจึงเกิดหลังคืน response
    | (ดู App\Services\Telegram\TelegramOutbox) ค่าเหล่านี้ต้องอ่านผ่าน config เท่านั้น
    | เพราะโฮสต์ใช้ config:cache จริง
    */
    /*
     * LINE Login ของฟอร์มแจ้งปัญหาสาธารณะ /support (App\Services\LineLogin) — ช่องทาง LINE Login ใน LINE Developers Console
     * Callback URL ของช่องทาง: {APP_URL}/support/line/callback · ว่างทั้งคู่ = ฟอร์มไม่บังคับ LINE
     */
    'line_login' => [
        'channel_id' => env('LINE_LOGIN_CHANNEL_ID'),
        'channel_secret' => env('LINE_LOGIN_CHANNEL_SECRET'),
    ],

    'telegram' => [
        'enabled' => (bool) env('TELEGRAM_NOTIFICATIONS_ENABLED', false),
        'bot_token' => env('TELEGRAM_BOT_TOKEN'),
        'bot_username' => env('TELEGRAM_BOT_USERNAME'),
        'webhook_secret' => env('TELEGRAM_WEBHOOK_SECRET'),
        'timeout' => (int) env('TELEGRAM_TIMEOUT', 8),
        'drain_limit' => (int) env('TELEGRAM_DRAIN_LIMIT', 40),
        // เพดานเวลาต่อรอบ drain (วินาที) — รอบตอนปิดท้าย request ต้องไม่ยึด worker ของเว็บนาน
        'drain_seconds' => (int) env('TELEGRAM_DRAIN_SECONDS', 20),
        // ลองซ้ำห่างขึ้นเรื่อย ๆ (TelegramMessage::RETRY_DELAYS_MINUTES) 5 ครั้งครอบคลุม Telegram ล่มราว 50 นาที
        'max_attempts' => (int) env('TELEGRAM_MAX_ATTEMPTS', 5),
    ],

];
