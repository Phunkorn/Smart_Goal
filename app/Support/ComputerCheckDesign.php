<?php

namespace App\Support;

/**
 * แหล่งเดียวของป้ายชื่อ สี ไอคอน และค่าคงที่ของ "ตรวจเช็กคอมพิวเตอร์ประจำวัน"
 * ทำหน้าที่เดียวกับที่ WorkLogDesign ทำให้กับบันทึกงานประจำวัน
 *
 * Blade อ่านคลาสนี้ตรง ๆ ส่วน JavaScript อ่านผ่าน JSON island ที่ได้จาก
 * forClient() เพื่อไม่ให้ข้อความไทยถูกคัดลอกไปอยู่ในไฟล์ .js อีกชุดหนึ่ง
 *
 * สิ่งที่ "ไม่" อยู่ในคลาสนี้: ตำแหน่งโต๊ะ ตำแหน่งอยู่ในตาราง
 * computer_check_workstations ที่เดียว เพราะห้องจริงย้ายโต๊ะได้โดยไม่ต้อง deploy
 * ที่นี่เก็บเฉพาะขนาดกรอบห้องและสิ่งปลูกสร้างที่ไม่ใช่โต๊ะ (บันได/ส่วนกลาง/ตู้)
 * ซึ่งเป็นรูปร่างของตัวอาคาร ไม่ใช่ข้อมูลที่ผู้ใช้แก้
 */
final class ComputerCheckDesign
{
    /**
     * อุปกรณ์ที่ต้องตรวจต่อหนึ่งเครื่อง — ลำดับนี้คือลำดับที่แสดงทุกที่
     * (แผงตรวจ, ผังห้อง, แบบตรวจ A4) เปลี่ยนที่นี่ที่เดียวแล้วเปลี่ยนทั้งระบบ
     *
     * key = ชื่อคอลัมน์ใน computer_check_results โดยเติม _status ต่อท้าย
     * letter = ตัวอักษรย่อบนแบบตรวจ A4 ตามที่ระบุไว้ใน legend ของแบบฟอร์ม
     */
    public const DEVICES = [
        'keyboard_mouse' => ['label' => 'Keyboard / Mouse', 'letter' => 'K', 'icon' => 'bi-keyboard'],
        'speaker' => ['label' => 'Speaker', 'letter' => 'S', 'icon' => 'bi-volume-up'],
        'microphone' => ['label' => 'Microphone', 'letter' => 'M', 'icon' => 'bi-mic'],
        'internet' => ['label' => 'Internet', 'letter' => 'I', 'icon' => 'bi-wifi'],
        'computer' => ['label' => 'Computer', 'letter' => 'C', 'icon' => 'bi-pc-display'],
    ];

    /**
     * สามสถานะของอุปกรณ์หนึ่งชิ้น
     *
     * 'pending' ต้องเป็นคนละค่ากับ 'issue' เสมอ — ดูเหตุผลเต็มในหัว migration
     * ของ computer_check_results
     *
     * mark คือสัญลักษณ์บนแบบตรวจ A4 ซึ่งต้องอ่านออกตอนพิมพ์ขาวดำด้วย
     * จึงไม่พึ่งสีอย่างเดียวทั้งบนจอและบนกระดาษ
     */
    public const DEVICE_STATUSES = [
        'pending' => ['label' => 'ยังไม่ได้ตรวจ', 'tone' => 'gray', 'icon' => 'bi-dash-circle', 'mark' => '-'],
        'normal' => ['label' => 'ปกติ', 'tone' => 'green', 'icon' => 'bi-check-circle-fill', 'mark' => '✓'],
        'issue' => ['label' => 'มีปัญหา', 'tone' => 'red', 'icon' => 'bi-exclamation-triangle-fill', 'mark' => '!'],
    ];

    /**
     * สถานะรวมของหนึ่งเครื่อง คำนวณจากอุปกรณ์ทั้งห้า (ดู workstationStatus())
     *
     * 'partial' ไม่ได้อยู่ในโจทย์ตั้งต้น แต่เกิดขึ้นจริงได้เสมอเมื่ออุปกรณ์มีสาม
     * สถานะ — ผู้ตรวจติ๊กไปสามชิ้นแล้วเดินไปเครื่องอื่น ถ้าไม่มีสถานะนี้
     * เครื่องนั้นจะถูกนับเป็น "ยังไม่ได้ตรวจ" ทั้งที่มีข้อมูลบันทึกไว้แล้ว
     * ซึ่งทำให้ตัวเลขสรุปโกหกผู้ใช้
     *
     * 'selected' (สีน้ำเงิน) เป็นสถานะของการโต้ตอบ ไม่ใช่ผลการตรวจ จึงไม่อยู่ที่นี่
     * แต่อยู่ในคลาส CSS ของผังห้องแทน
     *
     * mark คือสัญลักษณ์ที่วาดลงใน SVG ของผังห้อง ใช้อักขระ Unicode ธรรมดาแทน
     * ไอคอนฟอนต์ เพราะ <text> ใน SVG ของแบบตรวจที่พิมพ์ลงกระดาษต้องอ่านออก
     * แม้เครื่องปลายทางจะโหลดฟอนต์ไอคอนไม่สำเร็จ ส่วน icon ใช้กับ HTML ปกติ
     */
    public const WORKSTATION_STATUSES = [
        'pending' => ['label' => 'ยังไม่ตรวจ', 'tone' => 'gray', 'icon' => 'bi-circle', 'mark' => '○'],
        'partial' => ['label' => 'ตรวจบางส่วน', 'tone' => 'amber', 'icon' => 'bi-hourglass-split', 'mark' => '◑'],
        'normal' => ['label' => 'ปกติ', 'tone' => 'green', 'icon' => 'bi-check-circle-fill', 'mark' => '✓'],
        'issue' => ['label' => 'มีปัญหา', 'tone' => 'red', 'icon' => 'bi-exclamation-triangle-fill', 'mark' => '!'],
    ];

    /** รอบการตรวจ ตรงกับช่อง AM/PM บนแบบฟอร์มกระดาษ */
    public const SHIFTS = [
        'AM' => ['label' => 'เช้า', 'full' => 'รอบเช้า (AM)'],
        'PM' => ['label' => 'บ่าย', 'full' => 'รอบบ่าย (PM)'],
    ];

    public const SESSION_STATUSES = [
        'in_progress' => ['label' => 'กำลังตรวจ', 'tone' => 'blue'],
        'completed' => ['label' => 'ตรวจเสร็จแล้ว', 'tone' => 'green'],
    ];

    /** ห้องที่ผังนี้ใช้ — แบบฟอร์มกระดาษเป็นของห้อง Call Center ใบเดียว */
    public const ROOM_NAME = 'Call Center';

    /** รหัสแบบฟอร์มกระดาษต้นฉบับ พิมพ์ไว้มุมบนขวาของแบบตรวจ A4 */
    public const FORM_CODE = 'IT26-0-01';

    public const LOG_TITLE = 'ตรวจเช็กคอมพิวเตอร์ประจำวัน Call Center';

    /** หมวดงานที่บันทึกงานประจำวันของรอบนี้ถูกจัดเข้า (ตาราง work_log_categories) */
    public const LOG_CATEGORY = 'IT Support';

    /**
     * กรอบพิกัดของผังห้อง — เป็นหน่วยของ SVG viewBox ไม่ใช่พิกเซลบนจอ
     * ตำแหน่งใน computer_check_workstations อ้างอิงระบบพิกัดนี้
     */
    public const ROOM_WIDTH = 996;

    public const ROOM_HEIGHT = 1000;

    /**
     * ป้ายโซนด้านบนผัง — ตามกระดาษจริง Zone B อยู่ซ้าย Zone A อยู่ขวา
     * ห้ามสลับให้เรียงตามตัวอักษร
     */
    public const ZONE_BANDS = [
        ['zone' => 'B', 'x' => 24, 'y' => 21, 'width' => 452, 'height' => 78],
        ['zone' => 'A', 'x' => 514, 'y' => 21, 'width' => 458, 'height' => 78],
    ];

    /**
     * สิ่งที่ไม่ใช่โต๊ะคอมพิวเตอร์ แต่ต้องอยู่ในผังเพื่อให้ผู้ตรวจรู้ว่ายืนอยู่ตรงไหน
     *
     * kind: 'block' = พื้นที่ทึบตรงกลางห้อง, 'stairs' = ช่องบันได,
     *       'fixture' = ตู้/โต๊ะเปล่าที่ไม่มีคอมพิวเตอร์ (มุมซ้ายล่างของกระดาษ)
     */
    public const FIXTURES = [
        ['kind' => 'block', 'label' => 'พื้นที่ส่วนกลาง', 'x' => 312, 'y' => 331, 'width' => 174, 'height' => 380],
        ['kind' => 'stairs', 'label' => 'บันได', 'x' => 486, 'y' => 333, 'width' => 194, 'height' => 308],
        ['kind' => 'fixture', 'label' => 'ตู้เก็บของ', 'x' => 24, 'y' => 928, 'width' => 164, 'height' => 50],
    ];

    /** ย้อนหลังได้ไกลสุดกี่วัน — ใช้ค่าเดียวกับบันทึกงานประจำวันเพื่อไม่ให้สองหน้าตอบต่างกัน */
    public const MAX_BACKFILL_DAYS = WorkLogDesign::MAX_BACKFILL_DAYS;

    public const MAX_NOTE_LENGTH = 1000;

    public static function deviceKeys(): array
    {
        return array_keys(self::DEVICES);
    }

    /** ชื่อคอลัมน์ของอุปกรณ์แต่ละชิ้นในตาราง computer_check_results */
    public static function statusColumns(): array
    {
        return array_map(static fn (string $key): string => $key.'_status', self::deviceKeys());
    }

    public static function deviceStatusKeys(): array
    {
        return array_keys(self::DEVICE_STATUSES);
    }

    public static function shiftKeys(): array
    {
        return array_keys(self::SHIFTS);
    }

    public static function device(?string $key): array
    {
        return self::DEVICES[$key] ?? ['label' => 'ไม่ระบุอุปกรณ์', 'letter' => '?', 'icon' => 'bi-question-circle'];
    }

    public static function deviceStatus(?string $key): array
    {
        return self::DEVICE_STATUSES[$key] ?? self::DEVICE_STATUSES['pending'];
    }

    public static function workstationStatus(?string $key): array
    {
        return self::WORKSTATION_STATUSES[$key] ?? self::WORKSTATION_STATUSES['pending'];
    }

    public static function shiftLabel(?string $key): string
    {
        return self::SHIFTS[$key]['label'] ?? 'ไม่ระบุรอบ';
    }

    public static function shiftFullLabel(?string $key): string
    {
        return self::SHIFTS[$key]['full'] ?? 'ไม่ระบุรอบ';
    }

    /**
     * สถานะรวมของเครื่องหนึ่งเครื่องจากสถานะอุปกรณ์ทั้งห้า
     *
     * แหล่งความจริงเดียวของกติกานี้ — ทั้งผังบนเว็บ ตัวเลขสรุป และแบบตรวจ A4
     * ต้องเรียกเมธอดนี้ ห้ามเขียนเงื่อนไขซ้ำที่อื่น ไม่งั้นตัวเลข "ปกติ/มีปัญหา"
     * ของสามหน้าจะเพี้ยนออกจากกันทันทีที่แก้ข้างเดียว
     *
     * @param  array<string, string|null>  $statuses  คีย์เป็นชื่ออุปกรณ์ (ไม่ใช่ชื่อคอลัมน์)
     */
    public static function rollUp(array $statuses): string
    {
        $values = array_map(
            static fn (string $key): string => (string) ($statuses[$key] ?? 'pending'),
            self::deviceKeys()
        );

        if (in_array('issue', $values, true)) {
            return 'issue';
        }

        $checked = count(array_filter($values, static fn (string $value): bool => $value !== 'pending'));

        if ($checked === 0) {
            return 'pending';
        }

        return $checked === count($values) ? 'normal' : 'partial';
    }

    /**
     * ข้อมูลชุดเดียวกันในรูปแบบที่ JavaScript ใช้ได้ ส่งผ่าน JSON island
     * ไม่ใช่ให้ฝั่ง client เขียนป้ายชื่อของตัวเองซ้ำ
     */
    public static function forClient(): array
    {
        return [
            'devices' => self::DEVICES,
            'deviceStatuses' => self::DEVICE_STATUSES,
            'workstationStatuses' => self::WORKSTATION_STATUSES,
            'shifts' => self::SHIFTS,
            'maxNoteLength' => self::MAX_NOTE_LENGTH,
            'roomWidth' => self::ROOM_WIDTH,
            'roomHeight' => self::ROOM_HEIGHT,
        ];
    }
}
