<?php

namespace Tests\Feature;

use App\Models\Department;
use App\Models\User;
use App\Models\WorkspaceBoard;
use App\Services\WorkspaceBoardQueryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Tests\TestCase;

/**
 * กติกาว่าใครเห็นกระดานใบไหนถูกเขียนไว้สองที่โดยตั้งใจ
 *
 * - WorkspaceBoardPolicy::view() ตัดสินตอนเปิดกระดานทีละใบ
 * - WorkspaceBoardQueryService::visibleQuery() กรองตอนดึงรายการหลายใบ
 *
 * ทั้งสองต้องให้คำตอบตรงกันเป๊ะ ถ้าวันหนึ่งมีคนแก้กติกาที่เดียว กระดานที่ตั้งเป็น
 * "เฉพาะแผนก" จะโผล่ในหน้ารายการของแผนกอื่นทั้งที่เปิด URL ตรง ๆ แล้วได้ 403
 * (หรือกลับกัน คือหายไปจากรายการของคนที่ควรเห็น) เทสต์นี้มีไว้จับกรณีนั้น
 * โดยไม่ผูกกับกติกาชุดใดชุดหนึ่ง แต่เทียบสองเส้นทางเข้าหากันตรง ๆ
 */
class WorkspaceBoardVisibilityParityTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_list_query_returns_exactly_the_boards_the_policy_allows(): void
    {
        $it = Department::create(['department_name' => 'IT']);
        $hr = Department::create(['department_name' => 'HR']);
        $ops = Department::create(['department_name' => 'Operations']);

        $itStaff = $this->user('user', $it);
        $itHead = $this->user('user', $it, true);
        $hrStaff = $this->user('user', $hr);
        $admin = $this->user('admin', $it);
        $viewer = $this->user('viewer', $it);

        // กระดาน 6 ใบครอบคลุมทุกส่วนผสมของ (แผนกเจ้าของ x การมองเห็น)
        $boards = collect([
            $this->board($it, $itStaff, 'organization'),
            $this->board($it, $itStaff, 'department'),
            $this->board($hr, $hrStaff, 'organization'),
            $this->board($hr, $hrStaff, 'department'),
            $this->board($ops, null, 'organization'),
            $this->board($ops, null, 'department'),
        ]);

        $query = app(WorkspaceBoardQueryService::class);

        foreach ([
            'พนักงาน IT' => $itStaff,
            'หัวหน้าแผนก IT' => $itHead,
            'พนักงาน HR' => $hrStaff,
            'ผู้ดูแลระบบ' => $admin,
            'ผู้เข้าชม' => $viewer,
        ] as $label => $actor) {
            $allowedByPolicy = $boards
                ->filter(fn (WorkspaceBoard $board): bool => Gate::forUser($actor)->allows('view', $board))
                ->pluck('id')
                ->sort()
                ->values()
                ->all();

            $returnedByQuery = $query->visibleQuery($actor)
                ->pluck('id')
                ->sort()
                ->values()
                ->all();

            $this->assertSame(
                $allowedByPolicy,
                $returnedByQuery,
                $label.': รายการที่ query คืนต้องตรงกับที่ policy อนุญาตพอดี'
            );
        }
    }

    /**
     * ยืนยันว่าเทสต์ข้างบนไม่ได้ผ่านเพราะทั้งสองฝั่งคืนค่าว่างเหมือนกัน
     */
    public function test_the_parity_check_is_exercised_with_boards_that_are_actually_hidden(): void
    {
        $it = Department::create(['department_name' => 'IT']);
        $hr = Department::create(['department_name' => 'HR']);

        $itStaff = $this->user('user', $it);
        $hrStaff = $this->user('user', $hr);

        $shared = $this->board($it, $itStaff, 'organization');
        $private = $this->board($it, $itStaff, 'department');

        $visibleToOutsider = app(WorkspaceBoardQueryService::class)
            ->visibleQuery($hrStaff)
            ->pluck('id')
            ->all();

        $this->assertContains($shared->id, $visibleToOutsider);
        $this->assertNotContains(
            $private->id,
            $visibleToOutsider,
            'กระดานที่ตั้งเป็น "เฉพาะแผนก" ต้องไม่โผล่ในรายการของแผนกอื่น'
        );
    }

    /**
     * กระดานที่ถูกลบไปแล้วต้องไม่ถูกนับในสรุปรายแผนก
     *
     * departmentSummaries() ใช้ query แบบ group by ถ้าเผลอเรียก getQuery()
     * ก่อน scope ของ SoftDeletes จะหลุด แล้วตัวเลขบนการ์ดจะไม่ตรงกับจำนวน
     * กระดานที่เปิดเข้าไปแล้วเจอจริง
     */
    public function test_department_summaries_ignore_deleted_boards(): void
    {
        $it = Department::create(['department_name' => 'IT']);
        $staff = $this->user('user', $it);

        $this->board($it, $staff, 'organization');
        $this->board($it, $staff, 'organization')->delete();

        $summary = app(WorkspaceBoardQueryService::class)
            ->departmentSummaries($staff)
            ->firstWhere(fn (array $row): bool => $row['department']->id === $it->id);

        $this->assertSame(1, $summary['board_count']);
    }

    private function board(Department $department, ?User $creator, string $visibility): WorkspaceBoard
    {
        return WorkspaceBoard::query()->create([
            'department_id' => $department->id,
            'created_by' => $creator?->id,
            'title' => 'กระดาน '.$department->department_name.' ('.$visibility.')',
            'visibility' => $visibility,
        ]);
    }

    private function user(string $role, Department $department, bool $isHead = false): User
    {
        return User::factory()->create([
            'role' => $role,
            'department_id' => $department->id,
            'is_department_head' => $isHead,
            'is_active' => true,
        ]);
    }
}
