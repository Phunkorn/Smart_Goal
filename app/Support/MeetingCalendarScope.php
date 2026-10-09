<?php

namespace App\Support;

use App\Models\Department;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;

/**
 * ขอบเขตของ "ปฏิทินประชุมและนัดหมาย" หนึ่งใบ — ใครดู และดูปฏิทินของแผนกไหน
 *
 * ทำไมต้องแยก "ผู้ดู" ออกจาก "แผนกเจ้าของปฏิทิน"
 * ---------------------------------------------------------------
 * เดิมปฏิทินมีขอบเขตเดียวคือ "แผนกของผู้เปิด" (ดู MeetingQueryService::calendarScopedQuery)
 * ซึ่งใช้ได้กับปฏิทินหลักของหน้า "งานของฉัน" แต่ใช้กับ Member Workspace ไม่ได้ —
 * Admin ที่เปิด Workspace ของพนักงานแผนก IT ต้องเห็นปฏิทินของแผนก IT ไม่ใช่ของตัวเอง
 * และห้ามเห็นทุกแผนกรวมกัน
 *
 * ชั้นนี้จึงเป็นแหล่งเดียวที่ตอบสองคำถาม:
 *   1. ปฏิทินใบนี้มีขอบเขตเป็นอะไร (ของผู้ดูเอง หรือของแผนกที่ route ผูกมา)
 *   2. ผู้ดูคนนี้เปิดปฏิทินของแผนกนั้นได้หรือไม่ (allows)
 *
 * "เห็นปฏิทิน" ไม่เท่ากับ "สร้าง/แก้/ลบได้"
 * ---------------------------------------------------------------
 * ที่นี่ตัดสินเฉพาะการมองเห็น สิทธิ์เขียนยังเป็นของ MeetingPolicy และ
 * AppointmentPolicy::update()/delete() ตามเดิม ไม่ถูกแตะ
 */
final class MeetingCalendarScope
{
    private function __construct(
        public readonly User $viewer,
        public readonly ?Department $department,
    ) {}

    /**
     * ปฏิทินของผู้ดูเอง — พฤติกรรมเดิมของปฏิทินหลัก
     *
     * ขอบเขตคือแผนกของผู้ดู บวกประชุม/นัดหมายที่ตนจัดหรือถูกเชิญข้ามแผนก
     */
    public static function forViewer(User $viewer): self
    {
        return new self($viewer, null);
    }

    /**
     * ปฏิทินของแผนกที่ถูกระบุมา — ใช้โดย Member Workspace และ API ที่ส่ง ?department= มา
     *
     * โยน AuthorizationException ทันทีเมื่อผู้ดูไม่มีสิทธิ์เปิดปฏิทินของแผนกนั้น
     * (Laravel แปลงเป็น 403) การสร้าง scope จึงปลอดภัยโดยตัวมันเอง ไม่ต้องหวังว่า
     * ทุก controller จะจำใส่ abort_unless เอง
     *
     * @throws AuthorizationException
     */
    public static function forDepartment(User $viewer, Department $department): self
    {
        if (! self::allows($viewer, $department->id)) {
            throw new AuthorizationException('ไม่มีสิทธิ์เปิดปฏิทินของแผนกนี้');
        }

        return new self($viewer, $department);
    }

    /**
     * เปิดปฏิทินของแผนกนี้ได้หรือไม่
     *
     *   - admin ดูแลทุกแผนก จึงเปิดได้ทุกแผนก (ตรงกับ Member Workspace ที่เข้าได้ทุกแผนกอยู่แล้ว)
     *   - หัวหน้าแผนกเปิดได้เฉพาะแผนกที่ตนดูแล — เงื่อนไขเดียวกับ
     *     WorkBoardController::member() ที่ใช้ตัดสินว่าเข้า Workspace เต็มได้หรือไม่
     *   - บทบาทอื่นเปิดได้เฉพาะแผนกของตัวเอง ซึ่งเท่ากับที่เห็นจากปฏิทินหลักอยู่แล้ว
     *
     * ผู้ใช้ที่ยังไม่ถูกจัดแผนก (department_id เป็น null) ต้องไม่จับคู่กับ null ของแผนก
     * ไม่งั้นจะกลายเป็นเปิดปฏิทินของ "แผนกที่ไม่มีจริง" ได้
     */
    public static function allows(User $viewer, int|string|null $departmentId): bool
    {
        if ($departmentId === null) {
            return false;
        }

        if ($viewer->role === 'admin') {
            return true;
        }

        return $viewer->overseesDepartment($departmentId)
            || ((bool) $viewer->department_id && (int) $viewer->department_id === (int) $departmentId);
    }

    public function isDepartmentScoped(): bool
    {
        return $this->department !== null;
    }

    /**
     * ชื่อแผนกเจ้าของปฏิทิน — หัวเรื่องของหน้าอ่านค่านี้
     *
     * ขอบเขตแบบ "ของผู้ดูเอง" ยังบอกชื่อแผนกของผู้ดูตามเดิม และคืน null เมื่อผู้ดู
     * ยังไม่ถูกจัดแผนก เพื่อให้หัวเรื่องไม่โกหกว่ากำลังเห็นของทั้งแผนก
     */
    public function departmentName(): ?string
    {
        return $this->department
            ? $this->department->department_name
            : $this->viewer->loadMissing('department')->department?->department_name;
    }

    /**
     * พารามิเตอร์ที่ต้องติดไปกับ endpoint ของปฏิทิน (เปลี่ยนเดือน ตัวกรอง และ Export)
     * เพื่อให้การเรียกครั้งถัดไปยังอยู่ในขอบเขตเดิม ไม่ตกกลับไปเป็นปฏิทินของผู้ดู
     *
     * @return array<string, int>
     */
    public function routeQuery(): array
    {
        return $this->department ? ['department' => (int) $this->department->id] : [];
    }
}
