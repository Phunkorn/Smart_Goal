<?php

namespace App\Support;

use Carbon\CarbonInterface;

/**
 * วันที่และเวลาในรูปแบบที่ผู้ใช้อ่าน — ปีพุทธศักราชเสมอ
 *
 * ทำไมต้องมีที่นี่
 * ---------------------------------------------------------------
 * ระบบแสดงปี พ.ศ. อยู่แล้วในรายงาน ประกาศ ปฏิทิน และตัวเลือกวันที่ แต่แต่ละที่
 * เขียน `$date->year + 543` ของตัวเอง (ReportMonth, AnnouncementDesign,
 * OperationalWorkloadReportService, RoutineAccountabilityService,
 * date-picker.js ฯลฯ) ขณะที่หน้าการประชุมใช้ isoFormat('YYYY') ซึ่งให้ ค.ศ.
 * ผลคือหน้าเดียวกันเคยขึ้น 2569 ปนกับ 2026
 *
 * คลาสนี้คือรูปแบบวันที่ของหน้าประชุม/นัดหมาย/ปฏิทินประชุม ที่เดียว
 * ห้ามเขียน + 543 หรือ isoFormat('YYYY') กระจายใน Blade ของสามหน้านั้นอีก
 *
 * ทุกเมธอดรับเวลาที่แปลงเป็นเวลาไทยมาแล้ว การแปลงโซนเวลาเป็นหน้าที่ของชั้น query
 * ไม่ใช่ชั้นแสดงผล (CLAUDE.md: Convert timezone at query or display boundaries only)
 *
 * คลาสนี้ไม่แตะค่าที่เก็บในฐานข้อมูล เป็นการจัดรูปแบบเพื่อแสดงผลเท่านั้น
 */
final class ThaiDate
{
    private const BUDDHIST_ERA_OFFSET = 543;

    public static function buddhistYear(int $gregorianYear): int
    {
        return $gregorianYear + self::BUDDHIST_ERA_OFFSET;
    }

    /** 7 ต.ค. 2569 */
    public static function day(CarbonInterface $date): string
    {
        return $date->locale('th')->translatedFormat('j M').' '.self::buddhistYear($date->year);
    }

    /** 7 ตุลาคม 2569 */
    public static function longDay(CarbonInterface $date): string
    {
        return $date->locale('th')->translatedFormat('j F').' '.self::buddhistYear($date->year);
    }

    /** วันพุธที่ 7 ตุลาคม 2569 */
    public static function weekdayLongDay(CarbonInterface $date): string
    {
        return 'วัน'.$date->locale('th')->translatedFormat('l').'ที่ '.self::longDay($date);
    }

    /** ตุลาคม 2569 — หัวเรื่องของปฏิทินรายเดือน */
    public static function month(CarbonInterface $date): string
    {
        return $date->locale('th')->translatedFormat('F').' '.self::buddhistYear($date->year);
    }

    /** 14:32 น. */
    public static function time(CarbonInterface $date): string
    {
        return $date->format('H:i').' น.';
    }

    /** 7 ต.ค. 2569 เวลา 14:32 น. */
    public static function dayAtTime(CarbonInterface $dateTime): string
    {
        return self::day($dateTime).' เวลา '.self::time($dateTime);
    }

    /**
     * ช่วงเวลาในวันเดียว — 14:32–15:30 น. หรือ 14:32 น. เมื่อไม่มีเวลาสิ้นสุด
     *
     * รับเป็นข้อความ H:i ตรง ๆ เพราะ appointments เก็บเวลาแยกจากวันที่
     * (คอลัมน์ time) และเวลาสิ้นสุดเป็นค่าที่ไม่บังคับ
     */
    public static function timeRangeLabel(string $startTime, ?string $endTime = null): string
    {
        $start = substr($startTime, 0, 5);

        if (! $endTime) {
            return $start.' น.';
        }

        return $start.'–'.substr($endTime, 0, 5).' น.';
    }
}
