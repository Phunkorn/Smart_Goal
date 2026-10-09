<?php

namespace App\Support;

use App\Models\AppointmentAttendee;
use App\Models\AppointmentAttendeeResponseEvent;
use Carbon\CarbonInterface;

/**
 * ป้ายกำกับและโทนสีของสถานะตอบรับเข้าร่วมนัดหมาย — แหล่งเดียวของคำที่ผู้ใช้เห็น
 *
 * คู่ขนานกับ MeetingAttendeeResponseDesign โดยตั้งใจ ป้ายสามค่าเหมือนกันเพราะเป็น
 * แนวคิดเดียวกัน แต่คีย์มาจากค่าคงที่ของ AppointmentAttendee ซึ่งเป็นคนละ domain
 * ถ้าภายหลังต้องการยุบรวม ให้ยกแผนที่ป้ายออกเป็น Support กลางแล้วให้ทั้งสองคลาสชี้มา
 */
final class AppointmentAttendeeResponseDesign
{
    private const STATUS_LABELS = [
        AppointmentAttendee::STATUS_PENDING => 'รอตอบรับ',
        AppointmentAttendee::STATUS_ACCEPTED => 'เข้าร่วม',
        AppointmentAttendee::STATUS_DECLINED => 'ไม่ว่าง',
    ];

    private const STATUS_ICONS = [
        AppointmentAttendee::STATUS_PENDING => 'bi-hourglass-split',
        AppointmentAttendee::STATUS_ACCEPTED => 'bi-check2-circle',
        AppointmentAttendee::STATUS_DECLINED => 'bi-x-circle',
    ];

    private const EVENT_LABELS = [
        AppointmentAttendeeResponseEvent::EVENT_INVITED => 'ถูกเชิญเข้าร่วม',
        AppointmentAttendeeResponseEvent::EVENT_REINVITED => 'ถูกเชิญอีกครั้ง',
        AppointmentAttendeeResponseEvent::EVENT_ACCEPTED => 'ตอบรับเข้าร่วม',
        AppointmentAttendeeResponseEvent::EVENT_DECLINED => 'แจ้งไม่สะดวกเข้าร่วม',
    ];

    public static function statusLabel(?string $status): string
    {
        return self::STATUS_LABELS[$status] ?? self::STATUS_LABELS[AppointmentAttendee::STATUS_PENDING];
    }

    public static function statusIcon(?string $status): string
    {
        return self::STATUS_ICONS[$status] ?? self::STATUS_ICONS[AppointmentAttendee::STATUS_PENDING];
    }

    /**
     * ส่วนท้ายของคลาส CSS ที่ใช้ระบายสีป้าย (meeting-rsvp__badge--<tone>)
     * ใช้ชุดสีเดียวกับการประชุมเพราะเป็นสถานะแนวคิดเดียวกันในสายตาผู้ใช้
     */
    public static function statusTone(?string $status): string
    {
        return array_key_exists($status, self::STATUS_LABELS) ? $status : AppointmentAttendee::STATUS_PENDING;
    }

    public static function eventLabel(string $event): string
    {
        return self::EVENT_LABELS[$event] ?? $event;
    }

    /** รับเวลาที่แปลงเป็นเวลาไทยมาแล้ว การแปลงโซนเวลาเป็นหน้าที่ของชั้น query */
    public static function respondedAtLabel(CarbonInterface $respondedAt): string
    {
        return ThaiDate::dayAtTime($respondedAt);
    }
}
