<?php

namespace App\Support;

use App\Models\MeetingAttendee;
use App\Models\MeetingAttendeeResponseEvent;
use Carbon\CarbonInterface;

/**
 * ป้ายกำกับและโทนสีของสถานะตอบรับเข้าร่วมประชุม — แหล่งเดียวของคำที่ผู้ใช้เห็น
 *
 * อยู่ที่นี่ด้วยเหตุผลเดียวกับ WorkBoardDesign: สถานะตัวเดียวกันถูกแสดงทั้งในแถบปุ่มของ
 * ผู้เข้าร่วมและใน modal สถานะผู้เข้าร่วมของผู้สร้าง ถ้าปล่อยให้ Blade แต่ละไฟล์แปลเอง
 * คำกับสีจะเริ่มไม่ตรงกันทันทีที่เพิ่มสถานะใหม่
 */
final class MeetingAttendeeResponseDesign
{
    private const STATUS_LABELS = [
        MeetingAttendee::STATUS_PENDING => 'รอตอบรับ',
        MeetingAttendee::STATUS_ACCEPTED => 'เข้าร่วม',
        MeetingAttendee::STATUS_DECLINED => 'ไม่ว่าง',
    ];

    private const STATUS_ICONS = [
        MeetingAttendee::STATUS_PENDING => 'bi-hourglass-split',
        MeetingAttendee::STATUS_ACCEPTED => 'bi-check2-circle',
        MeetingAttendee::STATUS_DECLINED => 'bi-x-circle',
    ];

    private const EVENT_LABELS = [
        MeetingAttendeeResponseEvent::EVENT_INVITED => 'ถูกเชิญเข้าร่วม',
        MeetingAttendeeResponseEvent::EVENT_REINVITED => 'ถูกเชิญอีกครั้ง',
        MeetingAttendeeResponseEvent::EVENT_ACCEPTED => 'ตอบรับเข้าร่วม',
        MeetingAttendeeResponseEvent::EVENT_DECLINED => 'แจ้งไม่สะดวกเข้าร่วม',
    ];

    public static function statusLabel(?string $status): string
    {
        return self::STATUS_LABELS[$status] ?? self::STATUS_LABELS[MeetingAttendee::STATUS_PENDING];
    }

    public static function statusIcon(?string $status): string
    {
        return self::STATUS_ICONS[$status] ?? self::STATUS_ICONS[MeetingAttendee::STATUS_PENDING];
    }

    /**
     * ส่วนท้ายของคลาส CSS ที่ใช้ระบายสีป้าย (meeting-rsvp__badge--<tone>)
     * ค่าเท่ากับชื่อสถานะโดยเจตนา แต่เรียกผ่านเมธอดนี้เพื่อให้สถานะใหม่มีที่แก้ที่เดียว
     */
    public static function statusTone(?string $status): string
    {
        return array_key_exists($status, self::STATUS_LABELS) ? $status : MeetingAttendee::STATUS_PENDING;
    }

    public static function eventLabel(string $event): string
    {
        return self::EVENT_LABELS[$event] ?? $event;
    }

    /**
     * เวลาที่ตอบกลับในรูปแบบที่ผู้ใช้อ่าน — รูปแบบวันที่มาจาก ThaiDate ที่เดียวกับ
     * ทุกวันที่ในหน้าประชุม/นัดหมาย/ปฏิทินประชุม ไม่จัดรูปแบบเองซ้ำที่นี่
     *
     * ต้องรับเวลาที่แปลงเป็นเวลาไทยมาแล้ว การแปลงโซนเวลาเป็นหน้าที่ของชั้น query
     * (MeetingQueryService) ไม่ใช่ของชั้นแสดงผล
     */
    public static function respondedAtLabel(CarbonInterface $respondedAt): string
    {
        return ThaiDate::dayAtTime($respondedAt);
    }
}
