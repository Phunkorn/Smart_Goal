<?php

namespace App\Support;

/**
 * แหล่งเดียวของป้ายชื่อ สี ไอคอน และค่าคงที่ทั้งหมดของ "บันทึกงานประจำวัน"
 * ทำหน้าที่เดียวกับที่ WorkBoardDesign ทำให้กับบอร์ดงานโครงการ
 *
 * Blade อ่านคลาสนี้ตรง ๆ ส่วน JavaScript อ่านผ่าน JSON island ที่ได้จาก
 * forClient() เพื่อไม่ให้ข้อความไทยถูกคัดลอกไปอยู่ในไฟล์ .js อีกชุดหนึ่ง
 * ซึ่งเป็นปัญหาที่เกิดขึ้นแล้วกับป้ายสถานะของบอร์ดงาน
 *
 * หมายเหตุ: คำว่า "routine" ถูกใช้เป็นป้ายระดับความสำคัญของงานโครงการอยู่แล้ว
 * ใน WorkBoardDesign::TASK_PRIORITIES ที่นี่จึงแสดงผลเป็นภาษาไทย "งานประจำ"
 * เสมอ เพื่อไม่ให้ผู้ใช้สับสนระหว่างสองความหมาย
 */
final class WorkLogDesign
{
    /**
     * ประเภทงาน — ชุดปิดตาย ต่างจาก "หมวดงาน" ที่เป็นตาราง lookup แก้ไขได้
     */
    public const KINDS = [
        'routine' => ['label' => 'งานประจำ', 'tone' => 'blue', 'icon' => 'bi-arrow-repeat'],
        'field' => ['label' => 'งานนอกสถานที่', 'tone' => 'teal', 'icon' => 'bi-geo-alt'],
    ];

    /*
     * หมายเหตุเรื่องสี: ฟีเจอร์นี้เลี่ยงสีเขียวทั้งในกราฟและหน้ารายการ ตามการ
     * ตัดสินใจด้านการออกแบบ สถานะ "เสร็จแล้ว" จึงใช้โทน teal ซึ่งอ่านเป็นสีที่
     * ต่างจาก blue ของงานประจำอย่างชัดเจนโดยไม่ต้องพึ่งเขียว
     */
    public const STATUSES = [
        'open' => ['label' => 'รอเริ่ม', 'tone' => 'amber', 'icon' => 'bi-clock'],
        'in_progress' => ['label' => 'กำลังทำ', 'tone' => 'blue', 'icon' => 'bi-play-circle'],
        'overdue' => ['label' => 'เกินเวลา', 'tone' => 'red', 'icon' => 'bi-exclamation-circle'],
        'done' => ['label' => 'เสร็จแล้ว', 'tone' => 'teal', 'icon' => 'bi-check-circle'],
        'skipped' => ['label' => 'ไม่ได้ทำวันนี้', 'tone' => 'gray', 'icon' => 'bi-calendar-x'],
        // สถานะเพื่อการแสดงผลเท่านั้น ไม่เคยถูกเขียนลงคอลัมน์ status
        // ใช้กับงานประจำของวันที่ผ่านไปแล้วซึ่งยังไม่ถูกปิดรายการ เจ้าของทำได้
        // อย่างเดียวคือระบุเหตุผลที่ไม่ได้ทำ ย้อนกลับไปกดเริ่มงานไม่ได้อีกแล้ว
        'missed' => ['label' => 'ต้องระบุเหตุผล', 'tone' => 'red', 'icon' => 'bi-exclamation-octagon'],
        'cancelled' => ['label' => 'ยกเลิก', 'tone' => 'gray', 'icon' => 'bi-slash-circle'],
    ];

    public const SOURCES = ['manual', 'template'];

    /**
     * เพดานเวลาของหนึ่งรายการ ใช้ตอนระบบปิด timer ที่ถูกลืมเปิดค้างข้ามคืน
     * ให้ไม่กลายเป็นตัวเลขที่ทำให้รายงานเพี้ยน
     */
    public const MAX_TIMER_MINUTES = 720;

    public const MAX_DURATION_MINUTES = 1440;

    public const MIN_DURATION_MINUTES = 1;

    /**
     * ไฟล์แนบต่อหนึ่งบันทึก น้อยกว่าของงานโครงการโดยตั้งใจ
     * เพราะบันทึกงานประจำวันเป็นรายการสั้น ๆ ไม่ใช่พื้นที่เก็บเอกสารโครงการ
     */
    public const MAX_ATTACHMENTS = 5;

    /**
     * ย้อนหลังได้ไกลสุดกี่วัน กันการกรอกข้อมูลย้อนหลังไกลจนตรวจสอบไม่ได้
     */
    public const MAX_BACKFILL_DAYS = 90;

    public const DEFAULT_KIND = 'routine';

    /**
     * เหตุผลสำเร็จรูปของแต่ละปุ่ม — แหล่งเดียวของข้อความไทยชุดนี้
     *
     * ฝั่ง JavaScript อ่านผ่าน forClient() ไม่เขียนรายการซ้ำในไฟล์ .js ด้วย
     * เหตุผลเดียวกับป้ายสถานะ คือรายการสองชุดจะเพี้ยนออกจากกันทันทีที่แก้ข้างเดียว
     *
     * 'missed' คือกรณีที่วันนั้นผ่านไปแล้ว จึงไม่มีตัวเลือกแบบ "ยังทำอยู่"
     * เหลือเฉพาะเหตุผลที่อธิบายว่าทำไมวันนั้นไม่ได้เริ่มงาน
     */
    public const REASONS = [
        'start' => ['ติดงานอื่น', 'ประชุม', 'รอข้อมูลหรืออุปกรณ์', 'ระบบขัดข้อง'],
        'complete' => ['งานมากกว่าที่ประเมิน', 'มีงานอื่นเข้ามาระหว่างทำ', 'รอข้อมูลหรือการตอบกลับ', 'ระบบขัดข้อง'],
        'skip' => ['ลางาน', 'วันหยุด', 'ไม่มีความจำเป็นต้องทำวันนี้', 'มอบหมายให้ผู้อื่น', 'เหตุฉุกเฉิน'],
        'missed' => ['ลืมทำ', 'ลางาน', 'ขาดงาน', 'วันหยุด', 'ติดงานด่วนอื่น', 'มอบหมายให้ผู้อื่น'],
    ];

    public static function kindKeys(): array
    {
        return array_keys(self::KINDS);
    }

    public static function statusKeys(): array
    {
        return array_keys(self::STATUSES);
    }

    public static function kind(?string $key): array
    {
        return self::KINDS[$key] ?? [
            'label' => 'ไม่ระบุประเภท',
            'tone' => 'gray',
            'icon' => 'bi-question-circle',
        ];
    }

    public static function status(?string $key): array
    {
        return self::STATUSES[$key] ?? [
            'label' => 'ไม่ระบุสถานะ',
            'tone' => 'gray',
            'icon' => 'bi-question-circle',
        ];
    }

    /**
     * แปลงจำนวนนาทีเป็นข้อความภาษาไทยที่อ่านง่าย เช่น 165 → "2 ชม. 45 น."
     *
     * คืน "ไม่ระบุเวลา" เมื่อไม่มีค่า เพราะรายการที่บันทึกไว้โดยไม่ระบุเวลา
     * เป็นกรณีที่รองรับโดยตั้งใจ ไม่ใช่ข้อมูลผิดพลาด
     */
    public static function durationLabel(?int $minutes): string
    {
        if ($minutes === null) {
            return 'ไม่ระบุเวลา';
        }

        if ($minutes <= 0) {
            return '0 น.';
        }

        $hours = intdiv($minutes, 60);
        $remaining = $minutes % 60;

        if ($hours === 0) {
            return $remaining.' น.';
        }

        if ($remaining === 0) {
            return $hours.' ชม.';
        }

        return $hours.' ชม. '.$remaining.' น.';
    }

    /**
     * ข้อมูลชุดเดียวกันในรูปแบบที่ JavaScript ใช้ได้ ส่งผ่าน JSON island
     * ไม่ใช่ให้ฝั่ง client เขียนป้ายชื่อของตัวเองซ้ำ
     */
    public static function forClient(): array
    {
        return [
            'kinds' => self::KINDS,
            'statuses' => self::STATUSES,
            'reasons' => self::REASONS,
            'maxAttachments' => self::MAX_ATTACHMENTS,
            'maxBackfillDays' => self::MAX_BACKFILL_DAYS,
            'maxDurationMinutes' => self::MAX_DURATION_MINUTES,
        ];
    }
}
