<?php

namespace Tests\Feature;

use App\Models\Department;
use App\Models\User;
use App\Models\WorkOrder;
use App\Models\WorkOrderList;
use App\Support\DepartmentTaskVisibility;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * หน้า "งานของฉัน" ของผู้ได้รับสิทธิ์ดูงานของแผนก — งานของตัวเองกับงานของทีมต้องแยกออกได้
 *
 * มุมมอง data-view="table" (แท็บ "บอร์ด" kanban) เคยกรองด้วย participate ของผู้ใช้อย่างเดียว
 * งานของเพื่อนร่วมแผนกที่ผู้ได้สิทธิ์เห็นอยู่แล้วในมุมมองตารางจึงหายไปจาก kanban ทั้งหมด
 * ตอนนี้ kanban วาดงานเหล่านั้นด้วยแบบอ่านอย่างเดียว พร้อม data-assignee ให้ตัวกรองผู้รับผิดชอบ
 * — ไม่มีสิทธิ์เขียนใดเพิ่มขึ้น
 */
class MyTasksDepartmentTeamViewTest extends TestCase
{
    use RefreshDatabase;

    private Department $it;

    private Department $sales;

    protected function setUp(): void
    {
        parent::setUp();

        $this->it = Department::create(['department_name' => 'IT']);
        $this->sales = Department::create(['department_name' => 'Sales']);
    }

    public function test_own_department_kanban_draws_teammate_tasks_as_team_cards(): void
    {
        $grantee = $this->employee($this->it, 'ผู้ได้สิทธิ์', DepartmentTaskVisibility::SCOPE_OWN_DEPARTMENT);
        $teammate = $this->employee($this->it, 'เพื่อนร่วมแผนก');
        $outsider = $this->employee($this->sales, 'คนแผนกอื่น');

        $mine = $this->task($this->it, $grantee, 'งานของผู้ได้สิทธิ์');
        $team = $this->task($this->it, $teammate, 'งานของเพื่อนร่วมแผนก');
        $other = $this->task($this->sales, $outsider, 'งานของแผนกอื่น');

        $html = $this->actingAs($grantee)
            ->get(route('mytasks.index', ['view' => 'table']))
            ->assertOk()
            ->getContent();

        $this->assertSame(['assignee' => (string) $grantee->id], $this->kanbanCard($html, $mine->job_id));
        $this->assertSame(['assignee' => (string) $teammate->id], $this->kanbanCard($html, $team->job_id));
        $this->assertNull($this->kanbanCard($html, $other->job_id), 'งานนอกแผนกที่ได้สิทธิ์ต้องไม่ถูกวาด');
        // ชื่อผู้รับผิดชอบอยู่บนการ์ดเสมอ แยกงานของทีมได้โดยไม่ต้องเปิดรายละเอียด
        $this->assertStringContainsString('เพื่อนร่วมแผนก', $html);
    }

    public function test_team_cards_add_no_write_ability(): void
    {
        $grantee = $this->employee($this->it, 'ผู้ได้สิทธิ์', DepartmentTaskVisibility::SCOPE_OWN_DEPARTMENT);
        $teammate = $this->employee($this->it, 'เพื่อนร่วมแผนก');
        $team = $this->task($this->it, $teammate, 'งานของเพื่อนร่วมแผนก');

        $this->assertFalse($grantee->can('work', $team));
        $this->assertFalse($grantee->can('participate', $team));

        $this->actingAs($grantee)
            ->patchJson(route('tasks.updateStatus', ['id' => $team->job_id]), ['job_status' => 5])
            ->assertForbidden();
        $this->assertSame(2, (int) $team->fresh()->job_status);

        // การ์ดที่ถูกดึงกลับมาทีละใบ (หลังเปิดงานอีกครั้ง) ยังใช้กติกา participate เดิม
        $this->actingAs($grantee)
            ->getJson(route('mytasks.kanban-card', $team))
            ->assertNotFound();
    }

    /**
     * ไม่มีสิทธิ์ดูงานของแผนก — kanban ยังเป็นกระดานงานของตัวเองเหมือนเดิม
     * งานพี่น้องในโปรเจกต์ที่เห็นได้ในมุมมองตารางต้องไม่ถูกดึงเข้ามา
     */
    public function test_kanban_without_a_grant_still_shows_only_the_users_own_work(): void
    {
        $assigner = $this->employee($this->it, 'ผู้มอบหมาย');
        $worker = $this->employee($this->it, 'ผู้ทำงาน');
        $project = WorkOrderList::create(['user_id' => $assigner->id, 'name' => 'โปรเจกต์ร่วม', 'is_visible' => true, 'sort_order' => 1]);

        $mine = $this->task($this->it, $worker, 'งานของฉัน', ['created_by' => $assigner->id, 'work_order_list_id' => $project->id]);
        $mine->collaborators()->attach($worker->id, ['added_by' => $assigner->id, 'status' => 'accepted']);
        $sibling = $this->task($this->it, $assigner, 'งานพี่น้องในโปรเจกต์', ['work_order_list_id' => $project->id]);

        $html = $this->actingAs($worker)
            ->get(route('mytasks.index', ['view' => 'table']))
            ->assertOk()
            ->getContent();

        $this->assertNotNull($this->kanbanCard($html, $mine->job_id));
        $this->assertNull($this->kanbanCard($html, $sibling->job_id));
        // แต่มุมมองตารางยังเห็นงานพี่น้องตามสิทธิ์ระดับโปรเจกต์เดิม
        $this->assertStringContainsString('data-task-id="'.$sibling->job_id.'"', $html);
    }

    public function test_custom_grant_draws_the_granted_department_and_nothing_else(): void
    {
        $custom = $this->employee($this->sales, 'สิทธิ์กำหนดเอง', DepartmentTaskVisibility::SCOPE_CUSTOM, [$this->it]);
        $itOwner = $this->employee($this->it, 'เจ้าของงาน IT');
        $salesOwner = $this->employee($this->sales, 'เจ้าของงาน Sales');
        $itTask = $this->task($this->it, $itOwner, 'งานแผนก IT');
        $salesTask = $this->task($this->sales, $salesOwner, 'งานแผนก Sales');

        $html = $this->actingAs($custom)->get(route('mytasks.index', ['view' => 'table']))->assertOk()->getContent();

        $this->assertSame((string) $itOwner->id, $this->kanbanCard($html, $itTask->job_id)['assignee'] ?? null);
        $this->assertNull($this->kanbanCard($html, $salesTask->job_id), 'แผนกของตัวเองไม่ได้อยู่ในสิทธิ์ custom');
    }

    /** @return array{assignee: string}|null */
    private function kanbanCard(string $html, int $taskId): ?array
    {
        $pattern = '/<article\s+class="mytasks-kanban__card[^"]*"\s+data-kanban-card\s+data-id="'.$taskId.'".*?data-assignee="(\d+)"/s';

        return preg_match($pattern, $html, $matches)
            ? ['assignee' => $matches[1]]
            : null;
    }

    private function employee(Department $department, string $name, string $scope = DepartmentTaskVisibility::SCOPE_INVOLVED, array $departments = []): User
    {
        $user = User::factory()->create([
            'name' => $name,
            'role' => 'user',
            'department_id' => $department->id,
            'is_active' => true,
            'must_change_password' => false,
            'task_visibility_scope' => $scope,
        ]);
        $user->visibleDepartments()->sync(collect($departments)->pluck('id')->all());

        return $user->fresh();
    }

    /** @param  array<string, mixed>  $overrides */
    private function task(Department $department, User $assignee, string $topic, array $overrides = []): WorkOrder
    {
        return WorkOrder::create(array_merge([
            'user_id' => $assignee->id,
            'created_by' => $assignee->id,
            'leader_user_id' => $assignee->id,
            'department_id' => $department->id,
            'job_topic' => $topic,
            'job_priority' => 2,
            'job_status' => 2,
            'approval_status' => 'approved',
            'job_start_at' => now()->subDay(),
            'job_due_at' => now()->addDays(3),
        ], $overrides));
    }
}
