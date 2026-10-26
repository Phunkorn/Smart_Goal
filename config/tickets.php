<?php

/*
|--------------------------------------------------------------------------
| Smart Ticket
|--------------------------------------------------------------------------
*/

return [

    /*
     * ที่จำว่า "ติดตั้งตารางของ Smart Ticket แล้ว" (App\Services\TicketSchemaStatus)
     *
     * ค่าเริ่มต้นเป็น file เพื่อไม่ให้การตรวจนี้เพิ่มคิวรีฐานข้อมูลในทุก request
     * (cache หลักของระบบเป็น database) จำเฉพาะผลบวก และถูกล้างเองหลัง migrate/rollback
     * เทสต์ตั้งเป็น array (phpunit.xml) เพื่อไม่ให้ค่าค้างข้ามเทสต์บนดิสก์
     */
    'schema_cache_store' => env('TICKET_SCHEMA_CACHE_STORE', 'file'),

    /*
     * เกณฑ์ SLA ของรายงาน Smart Ticket (App\Services\TicketReportService)
     *
     * เว้นว่าง = ยังไม่ได้กำหนด → รายงานไม่แสดงเปอร์เซ็นต์ SLA เลย (ไม่เดาเกณฑ์ให้)
     * response_minutes   — รับงานภายในกี่นาทีหลังแจ้ง (first_responded_at − created_at)
     * resolution_hours   — ปิดงานภายในกี่ชั่วโมงหลังแจ้ง (closed_at ครั้งล่าสุด − created_at, เวลาปฏิทิน)
     */
    /*
     * ฟอร์มแจ้งปัญหาสาธารณะ /support (App\Services\LineLogin)
     *
     * require_line = true (ค่าเริ่มต้น ใช้กับ production): ต้องยืนยันตัวด้วย LINE ทุกครั้ง
     *   ถ้ายังไม่ได้ตั้ง LINE_LOGIN_CHANNEL_ID / SECRET หน้า /support แจ้งว่าปิดให้บริการชั่วคราว และไม่รับ Ticket
     *   — ไม่มีการถอยไปรับ Ticket แบบไม่ผูก LINE
     * require_line = false: สำหรับเครื่องพัฒนา/เทสต์ที่ยังไม่มีช่องทาง LINE เท่านั้น (phpunit.xml ตั้งไว้)
     *   ถ้าตั้งค่า LINE ไว้แล้ว ยังบังคับ LINE เหมือนเดิม
     */
    'public' => [
        'require_line' => (bool) env('TICKETS_PUBLIC_REQUIRE_LINE', true),
    ],

    'sla' => [
        'response_minutes' => env('TICKET_SLA_RESPONSE_MINUTES'),
        'resolution_hours' => env('TICKET_SLA_RESOLUTION_HOURS'),
    ],

    /*
     * เสียงแจ้ง Ticket ใหม่ (components/ticket-alert.js)
     * sound: ไฟล์ใต้ public/ (deploy ไปพร้อมโฟลเดอร์ public) — โหลดไม่ได้จะใช้เสียงสังเคราะห์เดิมแทน
     * loop_seconds: เล่นวนต่อ Ticket ใหม่หนึ่งใบแล้วหยุดเอง (Ticket ใหม่ระหว่างนั้นเริ่มนับใหม่)
     */
    'alert' => [
        'sound' => 'sounds/alert -1.mp3',
        'loop_seconds' => 20,
    ],

];
