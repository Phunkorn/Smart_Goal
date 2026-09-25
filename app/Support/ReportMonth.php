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

    /**
     * ตัวเลือกเดือนย้อนหลังแบบข้ามปี — ใช้กับหน้าสรุปรายเดือนของบันทึกงานประจำวัน
     * ที่ต้องดูย้อนไปได้ไกลกว่าปีปัจจุบัน เรียงจากเดือนนี้ไปเก่า ไม่มีเดือนในอนาคต
     * ถ้าเดือนที่กำลังดู ($include) อยู่นอกช่วง จะใส่เพิ่มให้ ดร็อปดาวน์จึงแสดงค่าจริงเสมอ
     *
     * @return array<string, string> 'Y-m' => 'กันยายน 2569'
     */
    public static function recentOptions(int $count = 24, ?string $include = null): array
    {
        $month = self::current();
        $options = [];

        for ($i = 0; $i < $count; $i++, $month = $month->subMonthNoOverflow()) {
            $options[$month->format('Y-m')] = self::label($month);
        }

        if ($include !== null && ! array_key_exists($include, $options) && preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $include)) {
            $options[$include] = self::label(CarbonImmutable::createFromFormat('!Y-m', $include, ReportMetrics::BUSINESS_TIMEZONE));
            krsort($options);
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
