<?php

namespace App\Support;

use Carbon\CarbonImmutable;

/**
 * ตัวเลือก "เดือน" ของรายงานรายเดือน — ใช้ร่วมกันระหว่างรายงานปฏิบัติงานและรายงานโปรเจกต์
 *
 * ตัวเลือกคือทุกเดือนของปีปัจจุบันจนถึงเดือนนี้ (ตามเวลา Asia/Bangkok) เรียงจากใหม่ไปเก่า
 * ค่าที่ไม่อยู่ในรายการ (รูปแบบผิด เดือนในอนาคต หรือปีอื่น) ตกกลับเป็นเดือนปัจจุบันเสมอ
 */
final class ReportMonth
{
    public static function current(): CarbonImmutable
    {
        return CarbonImmutable::now(ReportMetrics::BUSINESS_TIMEZONE)->startOfMonth();
    }

    /**
     * @return array<string, string> 'Y-m' => 'กันยายน 2569'
     */
    public static function options(): array
    {
        $current = self::current();
        $options = [];

        for ($month = $current; $month->year === $current->year; $month = $month->subMonthNoOverflow()) {
            $options[$month->format('Y-m')] = self::label($month);
        }

        return $options;
    }

    public static function resolve(?string $requested): CarbonImmutable
    {
        return array_key_exists((string) $requested, self::options())
            ? CarbonImmutable::createFromFormat('!Y-m', (string) $requested, ReportMetrics::BUSINESS_TIMEZONE)->startOfMonth()
            : self::current();
    }

    /** "กันยายน 2569" — ปีพุทธศักราชตามการแสดงผลของระบบ */
    public static function label(CarbonImmutable $month): string
    {
        return $month->locale('th')->translatedFormat('F').' '.($month->year + 543);
    }
}
