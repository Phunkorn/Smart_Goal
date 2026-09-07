<?php

namespace App\Support;

use Carbon\CarbonInterface;

/**
 * ตัววันทำงานของแม่แบบงานประจำ เก็บเป็น bitmask
 * bit0 = จันทร์, bit1 = อังคาร, ... bit6 = อาทิตย์
 *
 * คลาสนี้ตั้งใจให้เป็น pure ทั้งหมด (ไม่แตะฐานข้อมูล ไม่แตะ now()) เพื่อให้
 * ตรรกะ "วันนี้ต้องสร้างงานประจำตัวไหนบ้าง" ทดสอบได้โดยไม่ต้องมี DB
 *
 * สำคัญ: matches() ต้องได้รับวันที่ที่แปลงเป็นเวลากรุงเทพมาแล้วเสมอ
 * ห้ามส่ง now() แบบ UTC เข้ามาตรง ๆ เพราะเช้าวันจันทร์ 06:00 ที่กรุงเทพ
 * ยังเป็นวันอาทิตย์ 23:00 ตามเวลา UTC ซึ่งจะทำให้ระบบสร้างงานประจำผิดวัน
 */
final class WorkLogWeekdays
{
    /**
     * เรียงตามลำดับ bit ไม่ใช่ตามค่า dayOfWeek ของ Carbon
     * (Carbon นับอาทิตย์ = 0 แต่สัปดาห์ทำงานของไทยเริ่มวันจันทร์)
     */
    public const WEEKDAYS = [
        0 => ['short' => 'จ', 'label' => 'จันทร์'],
        1 => ['short' => 'อ', 'label' => 'อังคาร'],
        2 => ['short' => 'พ', 'label' => 'พุธ'],
        3 => ['short' => 'พฤ', 'label' => 'พฤหัสบดี'],
        4 => ['short' => 'ศ', 'label' => 'ศุกร์'],
        5 => ['short' => 'ส', 'label' => 'เสาร์'],
        6 => ['short' => 'อา', 'label' => 'อาทิตย์'],
    ];

    /** จันทร์ถึงศุกร์ — ค่าเริ่มต้นของแม่แบบใหม่ */
    public const WORKWEEK = 31;

    public const EVERYDAY = 127;

    /**
     * รวมรายการ bit index เป็น mask ตัวเดียว
     *
     * @param  array<int, int|string>  $days  bit index (0 = จันทร์)
     */
    public static function mask(array $days): int
    {
        $mask = 0;

        foreach ($days as $day) {
            $index = (int) $day;

            if (! array_key_exists($index, self::WEEKDAYS)) {
                continue;
            }

            $mask |= (1 << $index);
        }

        return $mask;
    }

    /**
     * แตก mask กลับเป็นรายการ bit index
     *
     * @return array<int, int>
     */
    public static function days(int $mask): array
    {
        $days = [];

        foreach (array_keys(self::WEEKDAYS) as $index) {
            if (self::includes($mask, $index)) {
                $days[] = $index;
            }
        }

        return $days;
    }

    public static function includes(int $mask, int $dayIndex): bool
    {
        if (! array_key_exists($dayIndex, self::WEEKDAYS)) {
            return false;
        }

        return ($mask & (1 << $dayIndex)) !== 0;
    }

    /**
     * วันที่ที่ให้มาตรงกับ mask หรือไม่
     *
     * ผู้เรียกต้องแปลงเป็นเวลากรุงเทพก่อนเสมอ (ดูหมายเหตุบนหัวคลาส)
     */
    public static function matches(int $mask, CarbonInterface $businessDate): bool
    {
        return self::includes($mask, self::dayIndex($businessDate));
    }

    /**
     * แปลง Carbon dayOfWeek (อาทิตย์ = 0) เป็น bit index ของเรา (จันทร์ = 0)
     */
    public static function dayIndex(CarbonInterface $businessDate): int
    {
        return ($businessDate->dayOfWeek + 6) % 7;
    }

    /**
     * ป้ายอ่านง่ายของ mask เช่น "จ–ศ", "ทุกวัน", "จ, พ, ศ"
     */
    public static function label(int $mask): string
    {
        $days = self::days($mask);

        if ($days === []) {
            return 'ไม่มีวันที่กำหนด';
        }

        if ($mask === self::EVERYDAY) {
            return 'ทุกวัน';
        }

        if ($mask === self::WORKWEEK) {
            return 'จ–ศ';
        }

        return implode(', ', array_map(
            fn (int $index): string => self::WEEKDAYS[$index]['short'],
            $days
        ));
    }
}
