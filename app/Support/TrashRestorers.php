<?php

namespace App\Support;

use App\Models\Department;
use App\Models\JobImage;
use App\Models\Meeting;
use App\Models\User;
use App\Models\WorkLog;
use App\Models\WorkLogAttachment;
use App\Models\WorkLogCategory;
use App\Models\WorkLogTemplate;
use App\Models\WorkOrder;
use App\Models\WorkOrderList;
use App\Models\WorkOrderListAttachment;
use App\Models\WorkOrderSubtask;
use App\Models\WorkspaceBoard;
use App\Models\WorkspaceBoardAttachment;

/**
 * ทะเบียนกลางว่าอะไรกู้คืนได้ กู้อย่างไร และเรียกว่าอะไร
 *
 * เดิมความรู้ชุดนี้กระจายอยู่เป็น if ที่ไล่เช็คชนิดข้อมูลแบบตายตัวสามที่ใน
 * TrashRetention คือ canRestore(), restoreModel() และ forceDeleteEntity()
 * ผลคือรายการทั้งสามไม่ตรงกัน และเกิดข้อผิดพลาดที่ผู้ใช้เห็นจริง เช่น
 * WorkLogService เขียนบันทึกงานประจำวันลงถังขยะ แต่ canRestore() ไม่มีชนิดนี้
 * ในรายการ ผู้ใช้จึงเห็นรายการในถังขยะที่กดกู้คืนไม่ได้ตลอดกาลโดยไม่มีคำอธิบาย
 *
 * เมื่อเพิ่มชนิดข้อมูลที่ลบได้ใหม่ ให้เพิ่มที่นี่ที่เดียว
 *
 * สองกลยุทธ์:
 *
 *   SOFT    — แถวยังอยู่ในฐานข้อมูลโดยมี deleted_at กู้คืนคือเรียก restore()
 *   PAYLOAD — แถวหายจากฐานข้อมูลจริง กู้คืนคือสร้างใหม่จากสำเนาใน payload_json
 *             ชนิดเหล่านี้ต้องพึ่งพา payload ที่ตอนลบบันทึกไว้ครบถ้วนเท่านั้น
 */
final class TrashRestorers
{
    public const SOFT = 'soft';

    public const PAYLOAD = 'payload';

    /**
     * @var array<class-string, array{strategy: string, label: string, payload_key: ?string}>
     */
    private const MAP = [
        User::class => ['strategy' => self::SOFT, 'label' => 'พนักงาน', 'payload_key' => 'user'],
        WorkOrder::class => ['strategy' => self::SOFT, 'label' => 'งาน', 'payload_key' => 'work_order'],
        WorkLog::class => ['strategy' => self::SOFT, 'label' => 'บันทึกงานประจำวัน', 'payload_key' => null],
        WorkspaceBoard::class => ['strategy' => self::SOFT, 'label' => 'กระดานไอเดีย', 'payload_key' => 'board'],

        JobImage::class => ['strategy' => self::SOFT, 'label' => 'ไฟล์แนบงาน', 'payload_key' => 'attachment'],
        WorkOrderListAttachment::class => ['strategy' => self::SOFT, 'label' => 'ไฟล์แนบโปรเจกต์', 'payload_key' => 'attachment'],
        WorkLogAttachment::class => ['strategy' => self::SOFT, 'label' => 'ไฟล์แนบบันทึกงาน', 'payload_key' => 'attachment'],
        WorkspaceBoardAttachment::class => ['strategy' => self::SOFT, 'label' => 'รูปในกระดานไอเดีย', 'payload_key' => 'attachment'],

        WorkOrderList::class => ['strategy' => self::PAYLOAD, 'label' => 'โปรเจกต์', 'payload_key' => 'list'],
        Department::class => ['strategy' => self::PAYLOAD, 'label' => 'แผนก', 'payload_key' => 'department'],
        Meeting::class => ['strategy' => self::PAYLOAD, 'label' => 'การประชุม', 'payload_key' => 'meeting'],
        WorkOrderSubtask::class => ['strategy' => self::PAYLOAD, 'label' => 'รายการย่อย', 'payload_key' => 'detail'],
        WorkLogCategory::class => ['strategy' => self::PAYLOAD, 'label' => 'หมวดงานประจำวัน', 'payload_key' => 'category'],
        WorkLogTemplate::class => ['strategy' => self::PAYLOAD, 'label' => 'งานประจำ', 'payload_key' => 'template'],
    ];

    /**
     * ชนิดข้อมูลที่ตัวมันเองถือไฟล์บนดิสก์
     *
     * ใช้ตอนลบถาวรเพื่อรู้ว่าต้องเก็บกวาดไฟล์ ไม่ใช่แค่ลบแถว
     *
     * @var array<int, class-string>
     */
    public const FILE_OWNERS = [
        JobImage::class,
        WorkOrderListAttachment::class,
        WorkLogAttachment::class,
        WorkspaceBoardAttachment::class,
    ];

    /**
     * ไฟล์แนบลูกที่ต้องถูกลบผ่าน Eloquent ก่อนลบตัวแม่ถาวร
     *
     * FK แบบ cascadeOnDelete ในฐานข้อมูลลบแถวลูกให้จริง แต่ไม่ยิง Eloquent event
     * ไฟล์บนดิสก์จึงไม่ถูกแตะและค้างอยู่โดยไม่มีอะไรอ้างถึงตลอดไป
     *
     * @var array<class-string, array<int, string>>
     */
    public const FILE_RELATIONS = [
        WorkOrder::class => ['images'],
        WorkOrderList::class => ['attachments'],
        WorkLog::class => ['attachments'],
        WorkspaceBoard::class => ['attachments'],
    ];

    public static function supports(?string $entityType): bool
    {
        return $entityType !== null
            && array_key_exists($entityType, self::MAP)
            && class_exists($entityType);
    }

    public static function strategyFor(string $entityType): ?string
    {
        return self::MAP[$entityType]['strategy'] ?? null;
    }

    /**
     * ชื่อชนิดข้อมูลภาษาไทยที่แสดงต่อผู้ใช้
     *
     * ชนิดที่ไม่รู้จักคืนชื่อคลาสแบบสั้น ดีกว่าคืนค่าว่างจนแถวในตารางดูเหมือนพัง
     */
    public static function labelFor(?string $entityType): string
    {
        if ($entityType === null) {
            return 'ข้อมูล';
        }

        return self::MAP[$entityType]['label'] ?? class_basename($entityType);
    }

    /**
     * คีย์ใน payload_json ที่เก็บสำเนาของแถวนั้น
     */
    public static function payloadKeyFor(string $entityType): ?string
    {
        return self::MAP[$entityType]['payload_key'] ?? null;
    }

    public static function ownsFile(?string $entityType): bool
    {
        return $entityType !== null && in_array($entityType, self::FILE_OWNERS, true);
    }

    /**
     * @return array<int, string>
     */
    public static function fileRelationsFor(string $entityType): array
    {
        return self::FILE_RELATIONS[$entityType] ?? [];
    }

    /**
     * @return array<int, class-string>
     */
    public static function all(): array
    {
        return array_keys(self::MAP);
    }
}
