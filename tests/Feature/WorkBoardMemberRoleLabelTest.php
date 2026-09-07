<?php

namespace Tests\Feature;

use App\Models\Department;
use App\Models\User;
use App\Services\DepartmentWorkBoardQuery;
use App\Support\RoleLabel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * การ์ดสมาชิกบนบอร์ดแผนกต้องเรียกบทบาทให้ถูก
 *
 * "หัวหน้าแผนก" ไม่ใช่ค่าใน users.role แต่เป็นธง users.is_department_head แยกต่างหาก
 * การ์ดนี้เคยเขียนคำว่า "พนักงาน" ไว้ตายตัวใน Blade หัวหน้าแผนกจึงถูกแสดงเป็นพนักงาน
 * ทุกครั้ง ทั้งที่แถบบนของหน้าเดียวกันแสดงว่าเป็นหัวหน้าแผนกอย่างถูกต้อง
 *
 * มีกับดักสองชั้น: นอกจาก Blade แล้ว DepartmentWorkBoardQuery ยัง select คอลัมน์
 * เฉพาะที่ต้องใช้ ถ้าไม่ได้ดึง is_department_head มาด้วย RoleLabel จะอ่านเป็น null
 * แล้วตอบว่า "พนักงาน" เหมือนเดิมโดยไม่มีอะไรพัง
 */
class WorkBoardMemberRoleLabelTest extends TestCase
{
    use RefreshDatabase;

    private Department $department;

    protected function setUp(): void
    {
        parent::setUp();
        $this->department = Department::create(['department_name' => 'IT']);
    }

    public function test_the_department_board_names_the_head_as_a_head_not_a_member(): void
    {
        $head = $this->member('พันกร ศรีทอน', true);
        $plain = $this->member('Anutida');

        $response = $this->actingAs($head)
            ->get(route('work-board.department', $this->department))
            ->assertOk();

        $body = $response->getContent();

        $this->assertStringContainsString(
            RoleLabel::DEPARTMENT_HEAD.' <span aria-hidden="true">·</span> IT',
            $body
        );
        $this->assertStringContainsString(
            RoleLabel::MEMBER.' <span aria-hidden="true">·</span> IT',
            $body
        );
        $this->assertStringContainsString($head->name, $body);
        $this->assertStringContainsString($plain->name, $body);
    }

    /**
     * ธงต้องเดินทางมาถึง Blade จริง ไม่ใช่ถูกตัดทิ้งที่ชั้น select ของ query
     */
    public function test_the_directory_query_carries_the_department_head_flag(): void
    {
        $head = $this->member('หัวหน้า', true);

        $members = app(DepartmentWorkBoardQuery::class)
            ->directory($this->department, request(), false)['members'];

        $loaded = $members->firstWhere('id', $head->id);

        $this->assertNotNull($loaded);
        $this->assertTrue($loaded->isDepartmentHead());
        $this->assertSame(RoleLabel::DEPARTMENT_HEAD, RoleLabel::for($loaded));
    }

    private function member(string $name, bool $isHead = false): User
    {
        return User::factory()->create([
            'name' => $name,
            'role' => 'user',
            'department_id' => $this->department->id,
            'is_department_head' => $isHead,
            'is_active' => true,
            'must_change_password' => false,
        ]);
    }
}
