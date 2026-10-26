<?php

namespace App\Support;

/**
 * เกณฑ์ SLA ของ Smart Ticket จาก config/tickets.php — ที่เดียวที่แปลงค่าตั้งเป็นนาที
 *
 * ใช้ทั้งรายงาน (TicketReportService) และหน้ารับแจ้งปัญหา (TicketQueryService::overdue)
 * ค่าว่าง ไม่ใช่ตัวเลข หรือไม่มากกว่า 0 = ยังไม่ได้กำหนด → null (ไม่เดาเกณฑ์ให้)
 */
final class TicketSla
{
    /** รับงานภายในกี่นาทีหลังแจ้ง (first_responded_at − created_at) */
    public static function responseMinutes(): ?int
    {
        return self::minutes(config('tickets.sla.response_minutes'), 1);
    }

    /** ปิดงานภายในกี่นาทีหลังแจ้ง (closed_at ครั้งล่าสุด − created_at, เวลาปฏิทิน) — ตั้งเป็นชั่วโมง */
    public static function resolutionMinutes(): ?int
    {
        return self::minutes(config('tickets.sla.resolution_hours'), 60);
    }

    private static function minutes(mixed $value, int $minutesPerUnit): ?int
    {
        return is_numeric($value) && (float) $value > 0 ? (int) round((float) $value * $minutesPerUnit) : null;
    }
}
