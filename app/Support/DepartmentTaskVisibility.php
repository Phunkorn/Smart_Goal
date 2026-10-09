<?php

namespace App\Support;

use App\Models\User;

/**
 * สิทธิ์ "ดูงานของแผนก" ที่ admin มอบให้พนักงานเป็นรายคน — แหล่งความจริงเดียว
 *
 * ตอบคำถามเดียวว่า "ผู้ใช้คนนี้ได้สิทธิ์ดูงานของแผนกรหัสใดบ้าง" ซึ่งถูกถามจากสี่ที่
 * ที่ต้องตอบตรงกันเสมอ — WorkOrder::scopeVisibleInProjectsFor() (คิวรีรายการ),
 * WorkOrderPolicy::view()/viewComments()/viewAsContext() (สิทธิ์รายใบ),
 * CrossDepartmentWork::isOutsider() (การตัดงานย่อย) และหน้าจัดการพนักงานของ admin
 *
 * เป็นสิทธิ์ "อ่าน" ล้วน ๆ
 * ---------------------------------------------------------------
 * ที่นี่ไม่แตะ App\Support\TaskParticipation และไม่ถูกเรียกจาก work()/comment()/
 * manageTeam()/manageSubtasks()/share()/review()/deleteOwn() เลย ผู้รับสิทธิ์จึง
 * ไม่กลายเป็นผู้มีส่วนร่วมของงานใบใด ไม่ขึ้นในรายงาน ภาระงาน หรือรายชื่อผู้รับแจ้งเตือน
 * (Can See Task != Participates In Task)
 *
 * ทำไม own_department ไม่เก็บแผนกลงตาราง
 * ---------------------------------------------------------------
 * โหมดนี้สื่อว่า "แผนกของตัวเอง" ไม่ใช่ "แผนก IT" แผนกจึงถูกอ่านจาก
 * users.department_id ตอนถาม สิทธิ์ย้ายตามคนเมื่อ admin ย้ายแผนกโดยไม่ต้องมีโค้ดเพิ่ม
 */
final class DepartmentTaskVisibility
{
    public const SCOPE_INVOLVED = 'involved';

    public const SCOPE_OWN_DEPARTMENT = 'own_department';

    public const SCOPE_CUSTOM = 'custom';

    /** @var array<int, string> */
    public const SCOPES = [self::SCOPE_INVOLVED, self::SCOPE_OWN_DEPARTMENT, self::SCOPE_CUSTOM];

    /**
     * ถ้อยคำของแต่ละโหมด — โมดัลพนักงานและป้ายบนการ์ดอ่านจากที่นี่ที่เดียว
     *
     * @return array<string, array{label: string, hint: string}>
     */
    public static function options(): array
    {
        return [
            self::SCOPE_INVOLVED => [
                'label' => 'เฉพาะงานที่เกี่ยวข้อง',
                'hint' => 'ค่าเริ่มต้น เห็นงานที่ตัวเองรับผิดชอบ เป็นผู้ร่วมงาน ถูกแชร์ หรือมีสิทธิ์ตามระบบเดิม',
            ],
            self::SCOPE_OWN_DEPARTMENT => [
                'label' => 'เห็นทุกคนในแผนกของตนเอง',
                'hint' => 'เห็นงานทั้งหมดของแผนกที่ตัวเองสังกัด ย้ายแผนกแล้วสิทธิ์ย้ายตามอัตโนมัติ',
            ],
            self::SCOPE_CUSTOM => [
                'label' => 'กำหนดเอง',
                'hint' => 'เลือกแผนกที่ต้องการให้มองเห็นงานได้ เลือกได้หลายแผนก',
            ],
        ];
    }

    /**
     * รหัสแผนกที่ผู้ใช้คนนี้ได้สิทธิ์ดูงาน
     *
     * admin กับ viewer ไม่ใช้สิทธิ์ชุดนี้เลย — admin เห็นทุกงานอยู่แล้ว (คิวรีคืนก่อน
     * ถึงบรรทัดนี้) และ viewer เป็นสิทธิ์อ่านทั้งระบบตามนิยามของมัน การยอมรับ grant
     * ของสองบทบาทนี้คือการเปิดทางเข้าที่ไม่มีใครตั้งใจให้มี
     * บัญชีที่ปิดใช้งานไม่มีสิทธิ์ใด ๆ รูปแบบเดียวกับ User::isDepartmentHead()
     *
     * @return array<int, int>
     */
    public static function departmentIdsFor(?User $user): array
    {
        if (! $user || $user->role !== 'user' || ! $user->is_active) {
            return [];
        }

        return match ($user->task_visibility_scope) {
            self::SCOPE_OWN_DEPARTMENT => $user->department_id === null
                ? []
                : [(int) $user->department_id],
            self::SCOPE_CUSTOM => self::customDepartmentIds($user),
            default => [],
        };
    }

    /** ผู้ใช้คนนี้ได้สิทธิ์ดูงานของแผนกรหัสนี้หรือไม่ */
    public static function covers(?User $user, int|string|null $departmentId): bool
    {
        return $departmentId !== null
            && in_array((int) $departmentId, self::departmentIdsFor($user), true);
    }

    /**
     * ข้อความบรรทัดเดียวบนการ์ดพนักงาน — null เฉพาะบัญชีระบบที่ไม่มีสิทธิ์ชุดนี้
     *
     * โหมดเริ่มต้นก็มีข้อความของตัวเอง ("เฉพาะงานที่เกี่ยวข้อง") เพราะการ์ดต้องตอบได้ว่า
     * คนนี้ถูกตั้งค่าไว้อย่างไร การเงียบเมื่อเป็นค่าเริ่มต้นทำให้แยกไม่ออกว่า "ไม่ได้ตั้ง"
     * กับ "หน้านี้ไม่แสดงเรื่องนี้" ต่างกันตรงไหน
     */
    public static function summary(?User $user): ?string
    {
        if (! $user || $user->role !== 'user') {
            return null;
        }

        if ($user->task_visibility_scope === self::SCOPE_OWN_DEPARTMENT) {
            return 'ทุกคนใน '.($user->department?->department_name ?? 'แผนกของตนเอง');
        }

        if ($user->task_visibility_scope !== self::SCOPE_CUSTOM) {
            return self::options()[self::SCOPE_INVOLVED]['label'];
        }

        $user->loadMissing('visibleDepartments');
        $names = $user->visibleDepartments->pluck('department_name')->filter()->values();

        // "กำหนดเอง" ที่ไม่เหลือแผนกเลย (เช่นแผนกถูกลบไป) ต้องบอกตรง ๆ
        // ไม่ใช่ตกกลับไปเป็นข้อความของค่าเริ่มต้นแล้วดูเหมือนไม่เคยถูกมอบสิทธิ์
        return $names->isEmpty()
            ? 'กำหนดเอง (ยังไม่ได้เลือกแผนก)'
            : $names->join(', ');
    }

    /** ผู้ใช้คนนี้ถูกมอบสิทธิ์ดูงานของแผนกเพิ่มไว้หรือไม่ — ใช้เน้นป้ายบนการ์ด */
    public static function isGranted(?User $user): bool
    {
        return self::departmentIdsFor($user) !== [];
    }

    /**
     * อ่าน relation ครั้งเดียวต่อ instance — view() ถูกถามทีละงานหลายสิบใบต่อหน้า
     *
     * loadMissing() ทำให้มีคิวรีได้มากสุดหนึ่งครั้งต่อ User หนึ่ง instance ต่อ request
     * และไม่มี static cache ที่จะค้างข้าม test
     *
     * @return array<int, int>
     */
    private static function customDepartmentIds(User $user): array
    {
        $user->loadMissing('visibleDepartments');

        return $user->visibleDepartments
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values()
            ->all();
    }
}
