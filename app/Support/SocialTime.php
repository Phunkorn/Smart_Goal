<?php

namespace App\Support;

use Carbon\CarbonInterface;

/**
 * เวลาของโพสต์และความคิดเห็นในชุมชน
 *
 * เก็บเป็น UTC ในฐานข้อมูล แปลงเป็นเวลาทำการ (TodayWorkspace::businessNow) ตอนแสดงผลเท่านั้น
 * "เมื่อวาน" ตัดสินจากวันตามเวลาไทย ไม่ใช่ 24 ชั่วโมงย้อนหลัง
 *
 *   ไม่ถึง 1 นาที           เมื่อสักครู่
 *   ไม่ถึง 1 ชั่วโมง         5 นาทีที่แล้ว
 *   วันเดียวกัน             3 ชั่วโมงที่แล้ว
 *   วันก่อนหน้า             เมื่อวาน เวลา 14:32 น.
 *   ปีเดียวกัน              7 ต.ค. เวลา 14:32 น.
 *   ข้ามปี                 7 ต.ค. 2568 เวลา 14:32 น.
 *
 * วันเวลาเต็ม (tooltip) ใช้ ThaiDate ชุดเดียวกับหน้าประชุม/ปฏิทิน
 */
final class SocialTime
{
    public static function relative(CarbonInterface $moment, ?CarbonInterface $now = null): string
    {
        $now = TodayWorkspace::businessNow($now);
        $local = TodayWorkspace::businessNow($moment);
        $seconds = max(0, $now->getTimestamp() - $local->getTimestamp());

        if ($seconds < 60) {
            return 'เมื่อสักครู่';
        }

        if ($seconds < 3600) {
            return intdiv($seconds, 60).' นาทีที่แล้ว';
        }

        if ($local->isSameDay($now)) {
            return intdiv($seconds, 3600).' ชั่วโมงที่แล้ว';
        }

        if ($local->isSameDay($now->copy()->subDay())) {
            return 'เมื่อวาน เวลา '.ThaiDate::time($local);
        }

        if ($local->year === $now->year) {
            return $local->locale('th')->translatedFormat('j M').' เวลา '.ThaiDate::time($local);
        }

        return ThaiDate::dayAtTime($local);
    }

    /** วันพุธที่ 7 ตุลาคม 2569 เวลา 14:32 น. */
    public static function full(CarbonInterface $moment): string
    {
        $local = TodayWorkspace::businessNow($moment);

        return ThaiDate::weekdayLongDay($local).' เวลา '.ThaiDate::time($local);
    }

    /** ค่า datetime ของแท็ก <time> — ISO 8601 พร้อมโซนเวลา */
    public static function iso(CarbonInterface $moment): string
    {
        return TodayWorkspace::businessNow($moment)->toIso8601String();
    }
}
