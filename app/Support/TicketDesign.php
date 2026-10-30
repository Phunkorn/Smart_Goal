<?php

namespace App\Support;

use App\Models\Ticket;
use App\Models\TicketActivity;
use Carbon\CarbonInterface;

/**
 * ป้ายและโทนสีของสถานะ/การกระทำของ Smart Ticket — ที่เดียว
 *
 * tone ตรงกับคลาส .badge-soft.{tone} ที่มีอยู่แล้วใน layout/shared-ui.css
 * จึงไม่ต้องประกาศสีใหม่ และเลือกโทนให้สอดคล้องกับ WorkBoardDesign
 * (กำลังทำ = blue, พัก/รอ = amber, เสร็จ = green)
 */
final class TicketDesign
{
    /** @var array<string, array{label: string, tone: string, icon: string}> */
    private const STATUSES = [
        // ไอคอนบอกความหมายของสถานะ: รอคนรับ (นาฬิกา) · กำลังซ่อม (เครื่องมือ) · รอติดตาม (นาฬิกาทราย) · เสร็จ (เครื่องหมายถูก)
        Ticket::STATUS_NEW => ['label' => 'รอรับงาน', 'tone' => 'accent', 'icon' => 'bi-clock'],
        Ticket::STATUS_ACTIVE => ['label' => 'กำลังดำเนินการ', 'tone' => 'blue', 'icon' => 'bi-tools'],
        Ticket::STATUS_FOLLOW_UP => ['label' => 'งานที่ต้องติดตาม', 'tone' => 'amber', 'icon' => 'bi-hourglass-split'],
        Ticket::STATUS_CLOSED => ['label' => 'ปิดงาน', 'tone' => 'green', 'icon' => 'bi-check2-circle'],
    ];

    /** @var array<string, string> */
    private const ACTIONS = [
        Ticket::ACTION_START => 'รับงาน',
        Ticket::ACTION_FOLLOW_UP => 'งานที่ต้องติดตาม',
        Ticket::ACTION_RESUME => 'กลับมาดำเนินการ',
        Ticket::ACTION_CLOSE => 'ปิดงาน',
        Ticket::ACTION_CANCEL => 'ยกเลิกงาน',
        Ticket::ACTION_REOPEN => 'เปิดงานใหม่',
    ];

    /** ช่องทางที่แจ้ง (tickets.source) — ใช้ทั้งคิวงานและรายงาน */
    private const SOURCES = [
        Ticket::SOURCE_INTERNAL => ['label' => 'Smart Goals', 'icon' => 'bi-building'],
        Ticket::SOURCE_PUBLIC => ['label' => 'Smart Ticket', 'icon' => 'bi-globe2'],
    ];

    /** @return array<string, array{label: string, tone: string, icon: string}> */
    public static function statuses(): array
    {
        return self::STATUSES;
    }

    /** @return array<string, array{label: string, icon: string}> */
    public static function sources(): array
    {
        return self::SOURCES;
    }

    /** @return array{label: string, icon: string} */
    public static function source(?string $source): array
    {
        return self::SOURCES[$source] ?? ['label' => 'ไม่ทราบช่องทาง', 'icon' => 'bi-question-circle'];
    }

    /** @return array{label: string, tone: string, icon: string} */
    public static function status(?string $status): array
    {
        return self::STATUSES[$status] ?? ['label' => 'ไม่ทราบสถานะ', 'tone' => 'gray', 'icon' => 'bi-question-circle'];
    }

    /** สถานะที่แสดงของ Ticket ปัจจุบัน: closed ที่เกิดจาก cancel ต้องบอกให้ต่างจากปิดงาน */
    public static function ticketStatus(Ticket $ticket): array
    {
        if ($ticket->status === Ticket::STATUS_CLOSED && ($ticket->closingActivity?->data['action'] ?? null) === Ticket::ACTION_CANCEL) {
            return ['label' => 'ยกเลิก', 'tone' => 'red', 'icon' => 'bi-x-circle'];
        }

        return self::status($ticket->status);
    }

    /**
     * ลิงก์กรองคิวงานตามสถานะ ('' = ทุกสถานะ) และช่วงวัน ('' = ทุกวัน) — การ์ดภาพรวมของหน้ารับแจ้งปัญหา
     * และแท็บสถานะของรายการแผนก คงตัวกรองอื่น (ค้นหา สาขา ขอบเขต) ไว้ แต่เริ่มหน้า 1 ใหม่เสมอ
     *
     * สถานะกับวันเป็นชุดเลือกอย่างใดอย่างหนึ่งของแถวการ์ด — ทุกลิงก์จึงตั้งค่าทั้งคู่
     * การ์ดที่ใช้อยู่จึงมีได้ใบเดียวเสมอ ไม่ใช่สองใบพร้อมกัน
     *
     * @param  array<string, string>  $filters  ตัวกรองที่ normalize แล้ว (TicketQueryService::filters)
     * @param  string  $day  TicketQueryService::DAY_TODAY หรือ '' (ทุกวัน)
     */
    public static function queueStatusUrl(array $filters, string $status, string $day = ''): string
    {
        $query = array_filter([...$filters, 'status' => $status, 'day' => $day], fn ($value) => $value !== '');

        return route('tickets.index', $query).'#ticket-queue';
    }

    public static function actionLabel(string $action): string
    {
        return self::ACTIONS[$action] ?? $action;
    }

    /** วันเวลาตามเวลาธุรกิจ (กรุงเทพ) — คอลัมน์เก็บเป็น UTC แปลงตอนแสดงผลเท่านั้น */
    public static function when(?CarbonInterface $at): string
    {
        return $at ? ThaiDate::dayAtTime(TodayWorkspace::businessNow($at)) : '—';
    }

    /**
     * วันที่และเวลาแยกส่วน (เวลากรุงเทพ) สำหรับตาราง — ให้ตัดบรรทัดระหว่างวันที่กับเวลาได้โดยไม่หักกลางวันที่
     *
     * @return array{date: string, time: string}|null
     */
    public static function whenParts(?CarbonInterface $at): ?array
    {
        if (! $at) {
            return null;
        }

        $local = TodayWorkspace::businessNow($at);

        return ['date' => ThaiDate::day($local), 'time' => ThaiDate::time($local)];
    }

    /**
     * ตัวอักษรในวงกลมแทนรูป — ข้ามสระนำหน้า (เ แ โ ใ ไ) เพราะตัวอักษรเดี่ยวอ่านไม่ออก
     */
    public static function initial(?string $name): string
    {
        $letters = preg_replace('/^[\s\x{0E40}-\x{0E44}]+/u', '', trim((string) $name)) ?? '';

        return mb_strtoupper(mb_substr($letters, 0, 1)) ?: '?';
    }

    /**
     * หัวข้อ ไอคอน และป้ายของข้อความประกอบ ของหนึ่งเหตุการณ์ใน timeline
     *
     * @return array{icon: string, title: string, body_label: string|null, tone: string}
     */
    public static function activity(TicketActivity $activity): array
    {
        $data = $activity->data ?? [];

        return match ($activity->type) {
            TicketActivity::TYPE_CREATED => ['icon' => 'bi-flag', 'title' => 'แจ้งปัญหา', 'body_label' => null, 'tone' => 'gray'],
            TicketActivity::TYPE_JOINED => ['icon' => 'bi-person-plus', 'title' => 'เข้าร่วม Ticket', 'body_label' => null, 'tone' => 'blue'],
            TicketActivity::TYPE_LEFT => [
                'icon' => 'bi-person-dash',
                'title' => ($data['coordinator_cleared'] ?? false) ? 'ออกจาก Ticket และพ้นจากผู้ประสานงาน' : 'ออกจาก Ticket',
                'body_label' => null,
                'tone' => 'gray',
            ],
            TicketActivity::TYPE_COORDINATOR_SET => [
                'icon' => 'bi-star',
                'title' => filled($data['coordinator_name'] ?? null)
                    ? 'ตั้ง '.$data['coordinator_name'].' เป็นผู้ประสานงาน'
                    : 'ยกเลิกผู้ประสานงาน',
                'body_label' => null,
                'tone' => 'accent',
            ],
            TicketActivity::TYPE_NOTE => ['icon' => 'bi-chat-left-text', 'title' => 'บันทึกการดำเนินงาน', 'body_label' => null, 'tone' => 'accent'],
            TicketActivity::TYPE_STATUS_CHANGED => self::statusChange((string) ($data['action'] ?? ''), $activity->to_status),
            default => ['icon' => 'bi-dot', 'title' => $activity->type, 'body_label' => null, 'tone' => 'gray'],
        };
    }

    /**
     * การ์ดหลักของผู้แจ้งบน /tickets — สถานะ Ticket ที่ยังไม่ปิดของตัวเอง (null = ไม่มี → แจ้งปัญหาได้)
     * ภาพประกอบตัดจาก public/images/Ticket -1/-2/- 3.png (ต้นฉบับยังอยู่) — follow_up ใช้ภาพเดียวกับ active
     *
     * @return array{key: string, title: string, description: string, image: string, size: array{int, int}, action: 'report'|'view'}
     */
    public static function reporterState(?Ticket $open): array
    {
        return match ($open?->status) {
            Ticket::STATUS_NEW => [
                'key' => 'waiting', 'title' => 'รอเจ้าหน้าที่รับงาน',
                'description' => 'ส่งคำขอเรียบร้อยแล้ว กำลังรอทีม IT รับเรื่อง',
                'image' => 'images/tickets/reporter-2.png', 'size' => [605, 194], 'action' => 'view',
            ],
            Ticket::STATUS_ACTIVE => [
                'key' => 'active', 'title' => 'เจ้าหน้าที่รับงานแล้ว',
                'description' => 'ทีม IT รับเรื่องของคุณแล้ว และกำลังดำเนินการ',
                'image' => 'images/tickets/reporter-3.png', 'size' => [697, 219], 'action' => 'view',
            ],
            Ticket::STATUS_FOLLOW_UP => [
                'key' => 'follow_up', 'title' => 'อยู่ระหว่างติดตามงาน',
                'description' => 'ทีม IT กำลังติดตามและดำเนินการแก้ไขปัญหาของคุณ',
                'image' => 'images/tickets/reporter-3.png', 'size' => [697, 219], 'action' => 'view',
            ],
            default => [
                'key' => 'report', 'title' => 'แจ้งปัญหา IT',
                'description' => 'พบปัญหาคอมพิวเตอร์ ระบบ หรืออุปกรณ์ แจ้งทีม IT ได้ที่นี่',
                'image' => 'images/tickets/reporter-1.png', 'size' => [707, 194], 'action' => 'report',
            ],
        };
    }

    /**
     * ความคืบหน้าที่เปิดเผยให้ผู้แจ้ง/หัวหน้าแผนกได้ (components/readonly-preview)
     * เฉพาะการแจ้ง และการเปลี่ยนสถานะ (หัวเรื่องเท่านั้น) — ไม่มีบันทึกการดำเนินงาน ผู้เข้าร่วม หรือเหตุผลภายใน
     * ข้อความที่แสดงได้มีอย่างเดียวคือ "ผลการแก้ไข" ตอนปิดงาน
     *
     * @return array{show: bool, body: string|null}
     */
    public static function reporterProgress(TicketActivity $activity): array
    {
        return match ($activity->type) {
            TicketActivity::TYPE_CREATED => ['show' => true, 'body' => null],
            TicketActivity::TYPE_STATUS_CHANGED => [
                'show' => true,
                // ผลการแก้ไขของการปิดงาน และเหตุผลของการยกเลิก เป็นสิ่งที่ผู้แจ้งต้องรู้
                'body' => in_array($activity->data['action'] ?? null, [Ticket::ACTION_CLOSE, Ticket::ACTION_CANCEL], true) && filled($activity->body)
                    ? $activity->body : null,
            ],
            default => ['show' => false, 'body' => null],
        };
    }

    /** @return array{icon: string, title: string, body_label: string|null, tone: string} */
    private static function statusChange(string $action, ?string $toStatus): array
    {
        $tone = self::status($toStatus)['tone'];

        return match ($action) {
            Ticket::ACTION_START => ['icon' => 'bi-play-circle', 'title' => 'รับงาน', 'body_label' => null, 'tone' => $tone],
            Ticket::ACTION_FOLLOW_UP => ['icon' => 'bi-hourglass-split', 'title' => 'ย้ายเป็นงานที่ต้องติดตาม', 'body_label' => 'เหตุผล', 'tone' => $tone],
            Ticket::ACTION_RESUME => ['icon' => 'bi-arrow-repeat', 'title' => 'กลับมาดำเนินการ', 'body_label' => null, 'tone' => $tone],
            Ticket::ACTION_CLOSE => ['icon' => 'bi-check-circle', 'title' => 'ปิดงาน', 'body_label' => 'ผลการแก้ไข', 'tone' => $tone],
            Ticket::ACTION_CANCEL => ['icon' => 'bi-x-circle', 'title' => 'ยกเลิกงาน', 'body_label' => 'เหตุผลที่ยกเลิก', 'tone' => 'red'],
            Ticket::ACTION_REOPEN => ['icon' => 'bi-arrow-counterclockwise', 'title' => 'เปิดงานใหม่', 'body_label' => 'เหตุผลที่เปิดใหม่', 'tone' => $tone],
            default => ['icon' => 'bi-arrow-left-right', 'title' => 'เปลี่ยนสถานะเป็น '.self::status($toStatus)['label'], 'body_label' => null, 'tone' => $tone],
        };
    }
}
