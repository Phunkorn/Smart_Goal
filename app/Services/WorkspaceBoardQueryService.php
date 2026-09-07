<?php

namespace App\Services;

use App\Models\Department;
use App\Models\User;
use App\Models\WorkspaceBoard;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * การอ่านข้อมูลกระดานไอเดีย พร้อมกติกาว่าใครเห็นอะไรในระดับ SQL
 *
 * แยกจาก WorkspaceBoardPolicy โดยตั้งใจ policy ตัดสิน "กระดานใบนี้คนนี้เปิดได้ไหม"
 * ส่วนที่นี่ตัดสิน "query ต้องกรองอะไรออกก่อนถึงมือผู้ใช้" ทั้งสองอิงกติกาเดียวกัน
 * แต่ใช้คนละจุดของ request
 *
 * ทั้งสองต้องให้คำตอบตรงกันเสมอ ถ้าแก้ที่หนึ่งต้องแก้อีกที่ด้วย มิฉะนั้นกระดานที่
 * ตั้งเป็น "เฉพาะแผนก" จะรั่วผ่านหน้ารายการทั้งที่เปิด URL ตรง ๆ แล้วได้ 403
 * tests/Feature/WorkspaceBoardVisibilityParityTest.php มีไว้จับกรณีนี้โดยเฉพาะ
 */
class WorkspaceBoardQueryService
{
    /**
     * ขอบเขตกระดานที่ผู้ใช้คนหนึ่งมองเห็นได้
     *
     * เงื่อนไขต้องสะท้อน WorkspaceBoardPolicy::view() แบบหนึ่งต่อหนึ่ง
     * - admin เห็นทุกใบ
     * - viewer เห็นเฉพาะใบที่เปิดเป็นทั้งองค์กร
     * - พนักงานและหัวหน้าเห็นใบที่เปิดเป็นทั้งองค์กร บวกกับทุกใบของแผนกตัวเอง
     */
    public function visibleQuery(User $viewer): Builder
    {
        $query = WorkspaceBoard::query();

        if ($viewer->role === 'admin') {
            return $query;
        }

        if ($viewer->role === 'viewer') {
            return $query->where('visibility', 'organization');
        }

        return $query->where(function (Builder $scoped) use ($viewer): void {
            $scoped->where('visibility', 'organization');

            // ผู้ใช้ที่ยังไม่ถูกกำหนดแผนกต้องไม่จับคู่กับกระดานที่ department_id
            // เป็น NULL ซึ่งเกิดขึ้นไม่ได้ตาม schema แต่กันไว้ให้ตรงกับ policy
            if ($viewer->department_id !== null) {
                $scoped->orWhere('department_id', $viewer->department_id);
            }
        });
    }

    /**
     * กระดานทั้งหมดของแผนกหนึ่งที่ผู้ใช้คนนี้เห็นได้ เรียงตามการแก้ไขล่าสุด
     *
     * กระดานที่ยังไม่เคยถูกแก้ (last_edited_at เป็น NULL) เรียงไปอยู่ท้ายสุด
     * โดยจัดเรียงในหน่วยความจำหลัง query เพราะไวยากรณ์ NULLS LAST ของ SQLite
     * (ฐานข้อมูลทดสอบ) กับ MySQL (production) เขียนไม่เหมือนกัน
     *
     * @return Collection<int, WorkspaceBoard>
     */
    public function boardsForDepartment(User $viewer, Department $department): Collection
    {
        return $this->visibleQuery($viewer)
            ->with(['lastEditor:id,name,profile_image', 'creator:id,name'])
            ->where('department_id', $department->id)
            ->get()
            ->sortBy([
                fn (WorkspaceBoard $board): int => $board->last_edited_at === null ? 1 : 0,
                fn (WorkspaceBoard $board): int => -($board->last_edited_at?->getTimestamp() ?? 0),
                fn (WorkspaceBoard $board): int => -$board->id,
            ])
            ->values();
    }

    /**
     * สรุปจำนวนกระดานที่เห็นได้ของแต่ละแผนก สำหรับการ์ดในหน้ารวม
     *
     * นับด้วย query เดียวแบบ group by แทนการวนนับทีละแผนก เพราะจำนวนแผนก
     * เพิ่มขึ้นได้เรื่อย ๆ และหน้านี้เป็นหน้าแรกที่ทุกคนเปิด
     *
     * @return Collection<int, array{department: Department, board_count: int}>
     */
    public function departmentSummaries(User $viewer): Collection
    {
        // pluck() บน Eloquent builder ยังคง global scope ของ SoftDeletes ไว้
        // ต่างจากการเรียก getQuery() ก่อน ซึ่งจะหลุด scope แล้วนับกระดานที่ลบไปแล้วด้วย
        $counts = $this->visibleQuery($viewer)
            ->select('department_id')
            ->selectRaw('COUNT(*) as board_count')
            ->groupBy('department_id')
            ->pluck('board_count', 'department_id');

        return Department::query()
            ->orderBy('department_name')
            ->get()
            ->map(fn (Department $department): array => [
                'department' => $department,
                'board_count' => (int) ($counts[$department->id] ?? 0),
            ])
            ->values();
    }

    /**
     * กระดานที่ถูกแก้ล่าสุดที่ผู้ใช้คนนี้เห็นได้ ใช้เป็นทางลัดบนหน้ารวม
     *
     * กรองกระดานที่ยังไม่เคยถูกแก้ออก เพราะรายการนี้ตอบคำถามว่า "ตอนนี้ใคร
     * กำลังคิดอะไรกันอยู่" กระดานเปล่าที่เพิ่งสร้างยังไม่มีคำตอบนั้น
     *
     * @return Collection<int, WorkspaceBoard>
     */
    public function recentFor(User $viewer, int $limit = 8): Collection
    {
        return $this->visibleQuery($viewer)
            ->with(['department:id,department_name', 'lastEditor:id,name,profile_image'])
            ->whereNotNull('last_edited_at')
            ->orderByDesc('last_edited_at')
            ->orderByDesc('id')
            ->limit($limit)
            ->get();
    }
}
