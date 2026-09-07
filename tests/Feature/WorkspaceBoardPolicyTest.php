<?php

namespace Tests\Feature;

use App\Models\Department;
use App\Models\User;
use App\Models\WorkspaceBoard;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Tests\TestCase;

/**
 * เมทริกซ์สิทธิ์ของกระดานไอเดีย
 *
 * กติกาที่ตกลงกันไว้กับผู้ใช้
 * - คนในแผนกเดียวกัน "ทุกคน" วาดและแก้ได้ ไม่ใช่เฉพาะคนสร้าง
 * - แผนกอื่นดูได้อย่างเดียว และดูไม่ได้เลยถ้ากระดานตั้งเป็น "เฉพาะแผนก"
 * - คนสร้าง หัวหน้าแผนกนั้น และ admin เท่านั้นที่เปลี่ยนชื่อ/สลับการมองเห็น/ลบได้
 * - viewer เป็น read-only เห็นเฉพาะกระดานที่เปิดเป็นทั้งองค์กร
 */
class WorkspaceBoardPolicyTest extends TestCase
{
    use RefreshDatabase;

    private Department $it;

    private Department $hr;

    protected function setUp(): void
    {
        parent::setUp();

        $this->it = Department::create(['department_name' => 'IT']);
        $this->hr = Department::create(['department_name' => 'HR']);
    }

    public function test_everyone_can_read_a_board_that_is_open_to_the_whole_organization(): void
    {
        $board = $this->board($this->it, $this->staff($this->it), 'organization');

        foreach ([
            'พนักงานแผนกเดียวกัน' => $this->staff($this->it),
            'พนักงานแผนกอื่น' => $this->staff($this->hr),
            'หัวหน้าแผนกอื่น' => $this->departmentHead($this->hr),
            'ผู้ดูแลระบบ' => $this->admin(),
            'ผู้เข้าชม' => $this->viewer(),
        ] as $label => $actor) {
            $this->assertTrue(
                Gate::forUser($actor)->allows('view', $board),
                $label.' ต้องเปิดดูกระดานที่ตั้งเป็นทั้งองค์กรได้'
            );
        }
    }

    public function test_a_department_private_board_is_hidden_from_outsiders_and_viewers(): void
    {
        $board = $this->board($this->it, $this->staff($this->it), 'department');

        $this->assertTrue(Gate::forUser($this->staff($this->it))->allows('view', $board));
        $this->assertTrue(Gate::forUser($this->departmentHead($this->it))->allows('view', $board));
        $this->assertTrue(Gate::forUser($this->admin())->allows('view', $board));

        $this->assertFalse(Gate::forUser($this->staff($this->hr))->allows('view', $board));
        $this->assertFalse(Gate::forUser($this->departmentHead($this->hr))->allows('view', $board));
        $this->assertFalse(Gate::forUser($this->viewer())->allows('view', $board));
    }

    /**
     * หัวใจของฟีเจอร์: กระดานเป็นของแผนก ไม่ใช่ของคนสร้าง
     */
    public function test_every_member_of_the_owning_department_can_edit_the_canvas(): void
    {
        $creator = $this->staff($this->it);
        $colleague = $this->staff($this->it);
        $board = $this->board($this->it, $creator, 'organization');

        $this->assertTrue(Gate::forUser($creator)->allows('update', $board));
        $this->assertTrue(
            Gate::forUser($colleague)->allows('update', $board),
            'เพื่อนร่วมแผนกที่ไม่ได้สร้างกระดานต้องวาดได้ด้วย'
        );
        $this->assertTrue(Gate::forUser($this->departmentHead($this->it))->allows('update', $board));
        $this->assertTrue(Gate::forUser($this->admin())->allows('update', $board));
    }

    public function test_outsiders_and_viewers_can_never_edit_the_canvas(): void
    {
        $board = $this->board($this->it, $this->staff($this->it), 'organization');

        $this->assertFalse(Gate::forUser($this->staff($this->hr))->allows('update', $board));
        $this->assertFalse(
            Gate::forUser($this->departmentHead($this->hr))->allows('update', $board),
            'หัวหน้าแผนกอื่นไม่มีอำนาจเหนือกระดานของแผนกนี้'
        );
        $this->assertFalse($this->viewerCanEdit($board));
    }

    /**
     * การเปิดกระดานเป็น "ทั้งองค์กร" ต้องไม่ทำให้แผนกอื่นแก้ได้
     *
     * ธง visibility มีผลกับการอ่านเท่านั้น ถ้าวันหนึ่งมีคนเผลอเอาไปเช็คใน update()
     * ด้วย เทสต์นี้จะจับได้
     */
    public function test_visibility_never_grants_edit_rights_to_other_departments(): void
    {
        $outsider = $this->staff($this->hr);

        foreach (['organization', 'department'] as $visibility) {
            $board = $this->board($this->it, $this->staff($this->it), $visibility);

            $this->assertFalse(
                Gate::forUser($outsider)->allows('update', $board),
                'คนนอกแผนกต้องแก้ไม่ได้ไม่ว่ากระดานจะตั้งการมองเห็นแบบใด ('.$visibility.')'
            );
        }
    }

    public function test_settings_and_delete_are_limited_to_the_creator_head_and_admin(): void
    {
        $creator = $this->staff($this->it);
        $colleague = $this->staff($this->it);
        $board = $this->board($this->it, $creator, 'organization');

        foreach (['manageSettings', 'delete'] as $ability) {
            $this->assertTrue(Gate::forUser($creator)->allows($ability, $board));
            $this->assertTrue(Gate::forUser($this->departmentHead($this->it))->allows($ability, $board));
            $this->assertTrue(Gate::forUser($this->admin())->allows($ability, $board));

            $this->assertFalse(
                Gate::forUser($colleague)->allows($ability, $board),
                'เพื่อนร่วมแผนกวาดได้ แต่เปลี่ยนชื่อหรือลบกระดานของคนอื่นไม่ได้ ('.$ability.')'
            );
            $this->assertFalse(Gate::forUser($this->departmentHead($this->hr))->allows($ability, $board));
            $this->assertFalse(Gate::forUser($this->viewer())->allows($ability, $board));
        }
    }

    /**
     * กระดานที่บัญชีคนสร้างถูกลบไปแล้วต้องไม่กลายเป็นของทุกคน
     */
    public function test_an_orphaned_board_falls_back_to_the_department_head_and_admin(): void
    {
        $board = $this->board($this->it, $this->staff($this->it), 'organization');
        $board->forceFill(['created_by' => null])->save();

        $this->assertTrue(Gate::forUser($this->departmentHead($this->it))->allows('manageSettings', $board));
        $this->assertTrue(Gate::forUser($this->admin())->allows('manageSettings', $board));
        $this->assertFalse(Gate::forUser($this->staff($this->it))->allows('manageSettings', $board));
    }

    /**
     * ผู้ใช้ที่ยังไม่ถูกกำหนดแผนกต้องไม่จับคู่กับกระดานใด ๆ
     *
     * ถ้าเปรียบเทียบด้วย (int) null === (int) null เขาจะแก้กระดานได้ทุกใบที่
     * department_id หายไป
     */
    public function test_a_user_without_a_department_cannot_edit_any_board(): void
    {
        $board = $this->board($this->it, $this->staff($this->it), 'organization');

        $drifter = User::factory()->create([
            'role' => 'user',
            'department_id' => null,
            'is_active' => true,
        ]);

        $this->assertFalse(Gate::forUser($drifter)->allows('update', $board));
    }

    public function test_viewer_cannot_create_boards_anywhere(): void
    {
        $viewer = $this->viewer();

        $this->assertFalse(Gate::forUser($viewer)->allows('create', WorkspaceBoard::class));
        $this->assertFalse(
            Gate::forUser($viewer)->allows('createInDepartment', [WorkspaceBoard::class, $this->it])
        );
    }

    public function test_staff_can_only_create_boards_inside_their_own_department(): void
    {
        $staff = $this->staff($this->it);

        $this->assertTrue(
            Gate::forUser($staff)->allows('createInDepartment', [WorkspaceBoard::class, $this->it])
        );
        $this->assertFalse(
            Gate::forUser($staff)->allows('createInDepartment', [WorkspaceBoard::class, $this->hr]),
            'พนักงานต้องสร้างกระดานในแผนกอื่นไม่ได้ แม้จะยิง department_id เข้ามาเอง'
        );

        $this->assertTrue(
            Gate::forUser($this->admin())->allows('createInDepartment', [WorkspaceBoard::class, $this->hr])
        );
    }

    private function viewerCanEdit(WorkspaceBoard $board): bool
    {
        return Gate::forUser($this->viewer())->allows('update', $board);
    }

    private function board(Department $department, User $creator, string $visibility): WorkspaceBoard
    {
        return WorkspaceBoard::query()->create([
            'department_id' => $department->id,
            'created_by' => $creator->id,
            'title' => 'กระดานทดสอบ',
            'visibility' => $visibility,
        ]);
    }

    private function staff(Department $department): User
    {
        return User::factory()->create([
            'role' => 'user',
            'department_id' => $department->id,
            'is_active' => true,
        ]);
    }

    private function departmentHead(Department $department): User
    {
        return User::factory()->create([
            'role' => 'user',
            'department_id' => $department->id,
            'is_department_head' => true,
            'is_active' => true,
        ]);
    }

    private function admin(): User
    {
        return User::factory()->create([
            'role' => 'admin',
            'department_id' => $this->it->id,
            'is_active' => true,
        ]);
    }

    private function viewer(): User
    {
        return User::factory()->create([
            'role' => 'viewer',
            'department_id' => $this->it->id,
            'is_active' => true,
        ]);
    }
}
