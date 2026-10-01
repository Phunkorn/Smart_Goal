<?php

namespace App\Support;

use App\Models\Announcement;
use Carbon\CarbonInterface;

/**
 * ป้าย สี และกติกาสถานะของประกาศ — แหล่งเดียวทั้งหน้าจัดการและสรุปประจำวัน
 *
 * สถานะไม่ได้เก็บในฐานข้อมูล แต่คำนวณจากช่วงวันที่เทียบกับ "วันนี้ตามเวลาไทย"
 * ทุกที่ที่ต้องรู้ว่าประกาศอยู่สถานะไหนต้องเรียก statusFor() หรือใช้เงื่อนไขชุดเดียวกัน
 * ใน App\Services\AnnouncementQueryService ห้ามเขียนเงื่อนไขเทียบวันชุดใหม่
 *
 * tone ตรงกับ class ของ .badge-soft ใน resources/css/components/layout/shared-ui.css
 */
final class AnnouncementDesign
{
    public const TITLE_MAX = 160;

    public const BODY_MAX = 5000;

    public const AUDIENCES = [
        Announcement::AUDIENCE_DEPARTMENT => ['label' => 'เฉพาะแผนก', 'tone' => 'red', 'icon' => 'bi-building'],
        Announcement::AUDIENCE_ALL => ['label' => 'ทุกแผนก', 'tone' => 'blue', 'icon' => 'bi-globe2'],
    ];

    public const STATUSES = [
        'active' => ['label' => 'กำลังแสดง', 'tone' => 'green'],
        'pending' => ['label' => 'รอแสดง', 'tone' => 'blue'],
        'expired' => ['label' => 'หมดอายุ', 'tone' => 'gray'],
    ];

    /** วันนี้ตามปฏิทินไทยในรูป Y-m-d — ใช้เทียบกับคอลัมน์ date ของประกาศ */
    public static function today(): string
    {
        return TodayWorkspace::businessNow()->format('Y-m-d');
    }

    public static function statusFor(Announcement $announcement, ?string $today = null): string
    {
        $today ??= self::today();
        $startsOn = $announcement->starts_on?->format('Y-m-d');
        $endsOn = $announcement->ends_on?->format('Y-m-d');

        if ($startsOn !== null && $startsOn > $today) {
            return 'pending';
        }

        if ($endsOn !== null && $endsOn < $today) {
            return 'expired';
        }

        return 'active';
    }

    /**
     * @return array{label:string,tone:string}
     */
    public static function status(string $key): array
    {
        return self::STATUSES[$key] ?? self::STATUSES['active'];
    }

    /**
     * @return array{label:string,tone:string,icon:string}
     */
    public static function audience(?string $key): array
    {
        return self::AUDIENCES[$key] ?? self::AUDIENCES[Announcement::AUDIENCE_DEPARTMENT];
    }

    /** 26/09/2569 — ค่าในคอลัมน์ date เป็นวันไทยอยู่แล้ว จึงไม่แปลง timezone ซ้ำ */
    public static function shortDate(?CarbonInterface $date): string
    {
        if (! $date) {
            return '';
        }

        return $date->format('d/m/').($date->year + 543);
    }

    /** 26 ก.ย. 2569 08:45 น. — สำหรับเวลาที่เก็บเป็น UTC เช่น created_at */
    public static function postedAt(?CarbonInterface $moment): string
    {
        if (! $moment) {
            return '';
        }

        $local = TodayWorkspace::businessNow($moment)->locale('th');

        return $local->translatedFormat('j M').' '.($local->year + 543).' '.$local->format('H:i').' น.';
    }

    /** วันศุกร์ที่ 25 กันยายน 2569 — หัวข้อของสรุปประจำวัน */
    public static function longDate(CarbonInterface $businessDay): string
    {
        $local = $businessDay->copy()->locale('th');

        return 'วัน'.$local->translatedFormat('l').'ที่ '.$local->translatedFormat('j F').' '.($local->year + 543);
    }
}
