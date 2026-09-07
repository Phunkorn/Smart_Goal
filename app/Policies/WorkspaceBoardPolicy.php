<?php

namespace App\Policies;

use App\Models\Department;
use App\Models\User;
use App\Models\WorkspaceBoard;

/**
 * สิทธิ์ของ "กระดานไอเดีย"
 *
 * กติกาที่ตกลงกันไว้
 * ---------------------------------------------------------------
 * - กระดานเป็นของแผนก ไม่ใช่ของบุคคล คนในแผนกเดียวกัน "ทุกคน" วาดและแก้ได้
 *   ไม่ใช่เฉพาะคนสร้าง เพราะจุดประสงค์คือกระดาษระดมสมองร่วมกัน
 * - แผนกอื่นเปิดดูได้อย่างเดียว เพื่อให้เห็นว่าแผนกอื่นคิดอะไรกันอยู่ แต่เข้าไป
 *   วาดทับไม่ได้ ซึ่งเป็นข้อกังวลหลักที่ทำให้เลือกออกแบบให้ผูกกับแผนก
 * - คนสร้าง หัวหน้าแผนกนั้น และ admin เปลี่ยนชื่อ/สลับการมองเห็น/ลบได้
 * - admin เห็นและแก้ได้ทุกแผนก
 * - viewer เป็น read-only ทั้งระบบ จึงเห็นได้เฉพาะกระดานที่เปิดเป็นทั้งองค์กร
 *
 * ทำไม visibility เข้ามาที่ view() จุดเดียว
 * ---------------------------------------------------------------
 * ธง visibility ทำหน้าที่ "ตัดคนนอกแผนกออกจากการอ่าน" เท่านั้น มันไม่เคยเพิ่ม
 * สิทธิ์แก้ไขให้ใคร และไม่เคยถอนสิทธิ์แก้ไขของคนในแผนก การเอาไปเช็คใน update()
 * หรือ delete() ด้วยจะทำให้เกิดสถานะแปลก ๆ เช่นกระดานที่ตั้งเป็นทั้งองค์กรแล้ว
 * แผนกอื่นแก้ได้ ซึ่งตรงข้ามกับกติกา
 *
 * ทำไมไม่มี before()
 * ---------------------------------------------------------------
 * ตามแนวทางของ WorkOrderPolicy และ WorkLogPolicy ในโปรเจกต์นี้ admin bypass
 * เขียนไว้ในแต่ละเมธอดอย่างชัดเจน เพื่อให้อ่านเมธอดเดียวแล้วรู้กติกาครบ และเพื่อ
 * ให้เมธอดที่ตั้งใจไม่ให้ admin ข้าม (ถ้ามีในอนาคต) ไม่ถูก before() ข้ามให้เงียบ ๆ
 */
class WorkspaceBoardPolicy
{
    /**
     * เปิดเมนูกระดานไอเดียได้หรือไม่
     *
     * เปิดให้ทุก role รวม viewer เพราะหน้ารายการถูกกรองด้วย
     * WorkspaceBoardQueryService::visibleQuery() อยู่แล้ว viewer ที่ไม่มีกระดาน
     * สาธารณะให้ดูจะเห็นหน้าว่าง ซึ่งถูกต้องกว่าการเจอ 403 ที่อธิบายอะไรไม่ได้
     */
    public function viewAny(User $user): bool
    {
        return in_array($user->role, ['admin', 'user', 'viewer'], true);
    }

    public function view(User $user, WorkspaceBoard $board): bool
    {
        // กระดานที่เปิดเป็นทั้งองค์กรอ่านได้ทุกคนที่ล็อกอิน รวม viewer
        if ($board->isVisibleToOtherDepartments()) {
            return true;
        }

        // กระดานที่ตั้งเป็นเฉพาะแผนกเป็นเรื่องภายในของแผนกนั้น viewer ไม่ได้สังกัด
        // แผนกใดในเชิงการทำงาน จึงไม่เข้าข่าย
        if ($user->role === 'viewer') {
            return false;
        }

        if ($user->role === 'admin') {
            return true;
        }

        return $this->belongsToSameDepartment($user, $board);
    }

    public function create(User $user): bool
    {
        return $user->role !== 'viewer';
    }

    /**
     * สร้างกระดานในแผนกที่ระบุได้หรือไม่
     *
     * แยกจาก create() เพราะตอนสร้างยังไม่มีกระดานให้ตรวจ ต้องตัดสินจากแผนก
     * ปลายทางโดยตรง มิฉะนั้นพนักงานแผนกหนึ่งจะยิง POST พร้อม department_id
     * ของแผนกอื่นเข้ามาสร้างกระดานในบ้านคนอื่นได้
     *
     * วิธีเรียกที่ถูกต้อง ต้องระบุคลาสไว้ข้างหน้าเสมอ เพราะ Gate เลือก policy จาก
     * argument ตัวแรก ถ้าส่ง Department เปล่า ๆ มันจะไปหา DepartmentPolicy
     * แล้วตอบ false เงียบ ๆ
     *
     *     Gate::authorize('createInDepartment', [WorkspaceBoard::class, $department]);
     */
    public function createInDepartment(User $user, Department $department): bool
    {
        if ($user->role === 'viewer') {
            return false;
        }

        if ($user->role === 'admin') {
            return true;
        }

        return $user->department_id !== null
            && (int) $user->department_id === (int) $department->id;
    }

    /**
     * วาดและแก้เนื้อหาบนกระดาน - เป็น ability เดียวกับที่ใช้ปิดกั้นการบันทึก
     * อัตโนมัติและการอัปโหลดรูป
     *
     * ไม่เช็ค visibility โดยตั้งใจ (ดูเหตุผลบนหัวคลาส)
     */
    public function update(User $user, WorkspaceBoard $board): bool
    {
        if ($user->role === 'viewer') {
            return false;
        }

        if ($user->role === 'admin') {
            return true;
        }

        return $this->belongsToSameDepartment($user, $board);
    }

    /**
     * เปลี่ยนชื่อกระดานและสลับการมองเห็น
     *
     * แคบกว่า update() เพราะเป็นการตัดสินใจว่า "ใครนอกแผนกจะเห็นงานชิ้นนี้บ้าง"
     * ซึ่งเป็นเรื่องของเจ้าของงานกับหัวหน้าแผนก ไม่ใช่ของทุกคนที่เข้ามาช่วยวาด
     */
    public function manageSettings(User $user, WorkspaceBoard $board): bool
    {
        if ($user->role === 'viewer') {
            return false;
        }

        if ($user->role === 'admin') {
            return true;
        }

        return $this->isCreator($user, $board)
            || $user->overseesDepartment($board->department_id);
    }

    /**
     * ลบกระดาน (soft delete)
     *
     * กติกาเดียวกับ manageSettings() แต่แยกเมธอดไว้ เพื่อให้ audit log และการ
     * แก้กติกาในอนาคตแยกจากกันได้ โดยไม่ต้องไปแตะการเปลี่ยนชื่อ
     */
    public function delete(User $user, WorkspaceBoard $board): bool
    {
        if ($user->role === 'viewer') {
            return false;
        }

        if ($user->role === 'admin') {
            return true;
        }

        return $this->isCreator($user, $board)
            || $user->overseesDepartment($board->department_id);
    }

    public function restore(User $user, WorkspaceBoard $board): bool
    {
        return $user->role === 'admin';
    }

    public function forceDelete(User $user, WorkspaceBoard $board): bool
    {
        return $user->role === 'admin';
    }

    /**
     * เป็นคนสร้างกระดานใบนี้หรือไม่
     *
     * created_by เป็น nullable (กระดานอยู่ต่อได้แม้บัญชีคนสร้างถูกลบ) การเทียบ
     * แบบ (int) null === (int) $user->id จึงไม่มีทางเป็นจริง กระดานกำพร้าจึงตก
     * ไปอยู่กับหัวหน้าแผนกและ admin โดยอัตโนมัติ ซึ่งเป็นพฤติกรรมที่ต้องการ
     */
    private function isCreator(User $user, WorkspaceBoard $board): bool
    {
        return $board->created_by !== null
            && (int) $board->created_by === (int) $user->id;
    }

    /**
     * อยู่แผนกเดียวกับกระดานหรือไม่
     *
     * ผู้ใช้ที่ยังไม่ถูกกำหนดแผนก (department_id เป็น NULL) ต้องไม่จับคู่กับ
     * กระดานใด ๆ การเทียบ null === null จะทำให้เขาแก้กระดานได้ทุกใบที่แผนกหาย
     */
    private function belongsToSameDepartment(User $user, WorkspaceBoard $board): bool
    {
        return $user->department_id !== null
            && (int) $user->department_id === (int) $board->department_id;
    }
}
