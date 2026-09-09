<?php

namespace App\Support;

use App\Models\SystemNotification;
use Illuminate\Support\Str;

/**
 * ประกอบข้อความ Telegram จากการแจ้งเตือนในระบบ
 *
 * เป็นกฎการแสดงผลล้วน ๆ ไม่มี state และไม่แตะฐานข้อมูล จึงอยู่ใน Support ตามกติกาของโปรเจกต์
 * ใช้ parse_mode = HTML เพราะ MarkdownV2 ของ Telegram บังคับ escape อักขระจำนวนมาก
 * ซึ่งพังง่ายกับหัวข้องานที่ผู้ใช้พิมพ์เอง
 */
class TelegramMessageComposer
{
    /** เพดานความยาวข้อความหนึ่งฉบับของ Telegram Bot API */
    public const MAX_LENGTH = 4096;

    private const CATEGORY_ICONS = [
        'review' => '🔍',
        'comment' => '💬',
        'deadline' => '⏰',
        'task' => '📋',
        'meeting' => '📅',
        'worklog' => '📝',
        'system' => '🔔',
    ];

    public static function forNotification(SystemNotification $notification, ?string $url = null): string
    {
        $icon = self::CATEGORY_ICONS[$notification->category] ?? self::CATEGORY_ICONS['system'];

        // escape อย่างเดียวพอ ห้ามใช้ strip_tags ซ้ำที่นี่ เพราะหัวข้อที่ผู้ใช้พิมพ์เองอย่าง
        // "ประชุมทีม <ด่วน>" จะถูกลบข้อความในวงเล็บมุมทิ้งทั้งก้อน ทั้งที่ไม่ใช่แท็ก
        $lines = [$icon.' <b>'.self::escape(Str::limit((string) $notification->title, 200, '…')).'</b>'];

        if (filled($notification->message)) {
            $lines[] = '';
            $lines[] = self::escape(Str::limit($notification->message, 900, '…'));
        }

        if (filled($url)) {
            $lines[] = '';
            $lines[] = '<a href="'.self::escape($url).'">เปิดในระบบ Smart Goal</a>';
        }

        return self::clamp(implode("\n", $lines));
    }

    /**
     * ข้อความยืนยันหลังผูกบัญชีสำเร็จ ส่งกลับไปในแชทที่เพิ่งผูก
     */
    public static function linked(string $name): string
    {
        return "✅ <b>เชื่อมต่อสำเร็จ</b>\n\n"
            .'บัญชี '.self::escape($name)." ผูกกับแชทนี้แล้ว ระบบจะส่งการแจ้งเตือนของคุณมาที่นี่\n\n"
            .'พิมพ์ /stop เพื่อหยุดรับแจ้งเตือน หรือ /status เพื่อดูสถานะ';
    }

    public static function help(): string
    {
        return "👋 <b>Smart Goal</b>\n\n"
            ."บอทนี้ส่งการแจ้งเตือนงานของคุณเป็นการส่วนตัว\n\n"
            .'วิธีเริ่มใช้งาน: เข้าเว็บ Smart Goal ไปที่หน้า <b>ตั้งค่า</b> แล้วกด "เชื่อมต่อ Telegram" '
            ."ระบบจะให้ลิงก์สำหรับผูกบัญชี\n\n"
            .'คำสั่ง: /status ดูสถานะ · /stop หยุดรับแจ้งเตือน · /start รับแจ้งเตือนอีกครั้ง';
    }

    public static function stopped(): string
    {
        return "🔕 <b>หยุดรับการแจ้งเตือนแล้ว</b>\n\n"
            .'พิมพ์ /start เพื่อกลับมารับอีกครั้ง หรือยกเลิกการเชื่อมต่อได้ที่หน้าตั้งค่าในเว็บ';
    }

    public static function resumed(string $name): string
    {
        return '🔔 <b>กลับมารับการแจ้งเตือนแล้ว</b> — '.self::escape($name);
    }

    public static function status(string $name, bool $enabled): string
    {
        return "ℹ️ <b>สถานะการเชื่อมต่อ</b>\n\n"
            .'บัญชี: '.self::escape($name)."\n"
            .'การแจ้งเตือน: '.($enabled ? 'เปิดอยู่' : 'ปิดอยู่ (พิมพ์ /start เพื่อเปิด)');
    }

    public static function test(string $name): string
    {
        return "🔔 <b>ข้อความทดสอบจาก Smart Goal</b>\n\n"
            .'ถ้าคุณเห็นข้อความนี้ แปลว่าบัญชี '.self::escape($name).' พร้อมรับการแจ้งเตือนแล้ว';
    }

    public static function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    /**
     * ตัดข้อความให้ไม่เกินเพดานของ Telegram โดยนับเป็นตัวอักษร ไม่ใช่ไบต์
     * (ข้อความไทยหนึ่งตัวใช้ 3 ไบต์ การตัดด้วย substr จะทำให้แท็ก HTML ขาดกลางคัน)
     */
    private static function clamp(string $text): string
    {
        return mb_strlen($text) <= self::MAX_LENGTH
            ? $text
            : mb_substr($text, 0, self::MAX_LENGTH - 1).'…';
    }
}
