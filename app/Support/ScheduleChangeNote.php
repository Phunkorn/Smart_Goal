<?php

namespace App\Support;

use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;

/**
 * ข้อความบันทึกกิจกรรมสำหรับการเปลี่ยนวันที่ของงาน
 *
 * แถบ "กิจกรรม" ในหน้างานแสดงเฉพาะคอลัมน์ description ส่วน before/after ที่
 * AuditTrail เก็บไว้ในคอลัมน์ changes เปิดอ่านได้เฉพาะหน้าบันทึกของผู้ดูแลระบบ
 * ข้อความว่า "เปลี่ยนกำหนดส่งงาน: <ชื่องาน>" เพียงอย่างเดียวจึงบอกแค่ว่ามีการ
 * เปลี่ยน แต่ไม่บอกว่าเปลี่ยนจากวันไหนไปวันไหน หัวหน้างานที่เห็นงานถูกเลื่อนออก
 * เรื่อย ๆ จึงไม่มีหลักฐานในที่เดียวกับงานให้ถามกลับ
 *
 * คลาสนี้จึงประกอบข้อความให้มีทั้งวันเดิม วันใหม่ และทิศทางที่เลื่อน แล้วให้
 * ทั้ง TaskController::updateSchedule() และ MyTaskController::updateDueDate()
 * เรียกใช้ตัวเดียวกัน ไม่ต้องเขียนรูปแบบข้อความซ้ำคนละที่
 *
 * เวลาที่แสดงถูกแปลงเป็น Asia/Bangkok ตรงจุดแสดงผลจุดนี้เท่านั้น ค่าที่บันทึก
 * ลงฐานข้อมูลยังเป็น UTC ตามเดิม
 */
final class ScheduleChangeNote
{
    /**
     * ประกอบข้อความบันทึกกิจกรรมจากรายการวันที่ที่ถูกแก้
     *
     * @param  array<int, array{label: string, from: mixed, to: mixed}>  $fields
     */
    public static function describe(string $headline, array $fields): string
    {
        $parts = [];

        foreach ($fields as $field) {
            $from = self::toBusinessTime($field['from'] ?? null);
            $to = self::toBusinessTime($field['to'] ?? null);

            if (self::unchanged($from, $to)) {
                continue;
            }

            $parts[] = $field['label'].' '.self::stamp($from).' → '.self::stamp($to).self::shift($from, $to);
        }

        // ผู้ใช้กดบันทึกโดยไม่ได้แก้วันที่จริง ข้อความจึงต้องไม่ห้อยเครื่องหมายค้างไว้
        return $parts === []
            ? $headline
            : $headline.' — '.implode(', ', $parts);
    }

    /**
     * ทิศทางและระยะที่เลื่อน นับเป็น "จำนวนวันตามปฏิทินไทย" ไม่ใช่ผลต่างเป็นชั่วโมง
     *
     * การเลื่อนงานเป็นการตัดสินใจระดับวัน ผลต่าง 23 ชั่วโมงที่ข้ามเที่ยงคืนต้อง
     * อ่านว่าเลื่อนออก 1 วัน ไม่ใช่ 0 วัน ส่วนการแก้เฉพาะเวลาในวันเดิมไม่ต้องมี
     * วงเล็บต่อท้าย เพราะวันที่ที่แสดงอยู่ข้างหน้าบอกครบแล้ว
     */
    private static function shift(?CarbonInterface $from, ?CarbonInterface $to): string
    {
        if (! $from || ! $to) {
            return '';
        }

        // Carbon 3 คืนค่าเป็น float ต้องปัดเป็นจำนวนเต็มก่อนเทียบ ไม่งั้น 0.0 === 0 เป็นเท็จ
        $days = (int) round($from->copy()->startOfDay()->diffInDays($to->copy()->startOfDay(), false));

        if ($days === 0) {
            return '';
        }

        return $days > 0
            ? ' (เลื่อนออก '.$days.' วัน)'
            : ' (เลื่อนเข้า '.abs($days).' วัน)';
    }

    private static function unchanged(?CarbonInterface $from, ?CarbonInterface $to): bool
    {
        if ($from === null && $to === null) {
            return true;
        }

        return $from !== null && $to !== null && $from->equalTo($to);
    }

    private static function stamp(?CarbonInterface $value): string
    {
        return $value?->locale('th')->translatedFormat('j M Y H:i') ?? 'ไม่ระบุ';
    }

    private static function toBusinessTime(mixed $value): ?CarbonInterface
    {
        if ($value === null || $value === '') {
            return null;
        }

        $parsed = $value instanceof CarbonInterface ? $value->copy() : Carbon::parse($value);

        return $parsed->setTimezone(TodayWorkspace::BUSINESS_TIMEZONE);
    }
}
