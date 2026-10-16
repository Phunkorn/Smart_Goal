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
 * ตัวกรอง "งานทั้งหมด / งานของฉัน / งานของทีม" และผู้รับผิดชอบ (แทนปุ่ม "เฉพาะงานของฉัน" เดิม)
 *
 * ผู้ที่ถูกเชิญร่วมงานหนึ่งใบในโปรเจกต์ จะเห็นงานพี่น้องทุกใบในโปรเจกต์นั้นแบบ read-only
 * ตามสิทธิ์ระดับโปรเจกต์ ซึ่งเป็นพฤติกรรมที่ถูกต้องและต้องไม่ถูกแตะ ปุ่มนี้เป็นเพียง
 * ตัวกรองการแสดงผลฝั่ง client ที่อ่านจาก data-participate ที่ server ใส่มาให้
 *
 * เทสต์ชุดนี้คุมฝั่ง server อย่างเดียว คือ "ใบไหนถูกทำเครื่องหมายว่าเป็นของผู้ใช้"
 * ส่วนพฤติกรรมตอนกดปุ่มจริงอยู่ใน tests/js/mytasks-board-mine-filter.test.js
 */
class MyTasksBoardMineFilterTest extends TestCase
{
    use RefreshDatabase;

    public function test_board_marks_the_viewers_own_tasks_apart_from_project_siblings(): void
    {
        $department = Department::create(['department_name' => 'IT']);
        $assigner = $this->user($department);
        $worker = $this->user($department);
        $project = $this->list($assigner);

        $assignedToWorker = $this->task($worker, $assigner, $project, 'งานที่ถูกมอบหมาย');
        $this->invite($assignedToWorker, $worker, $assigner);

        // เห็นได้เพราะสิทธิ์ระดับโปรเจกต์เท่านั้น ไม่ได้ถูกเชิญเข้าใบนี้
        $ownedByAssigner = $this->task($assigner, $assigner, $project, 'งานของผู้มอบหมายเอง');

        $html = $this->actingAs($worker)->get(route('mytasks.index'))->assertOk()->getContent();

        $this->assertSame('1', $this->participationFlag($html, $assignedToWorker->job_id));
        $this->assertSame('0', $this->participationFlag($html, $ownedByAssigner->job_id));

        // สัญญาเดิม: บอร์ดต้องไม่ถูกแตะ งานพี่น้องยังอยู่ใน HTML ครบ การซ่อนเกิดฝั่ง client เท่านั้น
        $this->assertContains((int) $ownedByAssigner->job_id, $this->boardTaskIds($html), 'บอร์ดต้องไม่ถูกแตะ');
    }

    /**
     * งานของตัวเองที่ปิดแล้วและงานที่ยังรออนุมัติ ยังต้องนับเป็น "งานของฉัน"
     *
     * นี่คือเหตุผลที่ flag นี้ต้องมาจาก ability participate ไม่ใช่ work ซึ่งปฏิเสธงานสถานะ 4
     * และงานที่ approval_status ยังไม่ approved ทุกใบ ถ้าใครสลับไปใช้ work เทสต์นี้ต้องแดง
     */
    public function test_finished_and_pending_work_of_the_viewer_still_counts_as_their_own(): void
    {
        $department = Department::create(['department_name' => 'IT']);
        $assigner = $this->user($department);
        $worker = $this->user($department);
        $project = $this->list($assigner);

        $anchor = $this->task($worker, $assigner, $project, 'งานหลักของฉัน');
        $this->invite($anchor, $worker, $assigner);

        $closed = $this->task($worker, $assigner, $project, 'งานของฉันที่ปิดไปแล้ว', [
            'job_status' => 4,
            'job_completed_at' => now(),
        ]);

        $pending = $this->task($worker, $worker, $project, 'งานของฉันที่ยังรออนุมัติ', [
            'approval_status' => 'pending',
        ]);

        $html = $this->actingAs($worker)->get(route('mytasks.index'))->assertOk()->getContent();

        $this->assertSame('1', $this->participationFlag($html, $closed->job_id), 'งานที่ปิดแล้วของตัวเองต้องยังเป็นงานของฉัน');
        $this->assertSame('1', $this->participationFlag($html, $pending->job_id), 'งานที่ยังรออนุมัติของตัวเองต้องยังเป็นงานของฉัน');
    }

    public function test_subtask_rows_carry_the_same_participation_flag(): void
    {
        $department = Department::create(['department_name' => 'IT']);
        $assigner = $this->user($department);
        $worker = $this->user($department);
        $project = $this->list($assigner);

        $parent = $this->task($assigner, $assigner, $project, 'งานแม่ของผู้มอบหมาย');

        // ผู้ทำงานถูกเชิญเข้าเฉพาะงานย่อย ซึ่งเป็นเหตุให้เห็นงานแม่ตามมา
        $subtask = $this->task($worker, $assigner, $project, 'งานย่อยของฉัน', [
            'parent_job_id' => $parent->job_id,
        ]);
        $this->invite($subtask, $worker, $assigner);

        $html = $this->actingAs($worker)->get(route('mytasks.index'))->assertOk()->getContent();

        $this->assertSame('1', $this->participationFlag($html, $subtask->job_id));
        $this->assertSame('0', $this->participationFlag($html, $parent->job_id));
    }

    public function test_the_owner_filter_renders_once_and_starts_on_all_tasks_on_the_board(): void
    {
        $department = Department::create(['department_name' => 'IT']);
        $worker = $this->user($department);

        $html = $this->actingAs($worker)
            ->get(route('mytasks.index', ['view' => 'board']))
            ->assertOk()
            ->getContent();

        $this->assertSame(1, substr_count($html, 'data-board-assignee-filter'), 'ตัวกรองต้อง render เพียงครั้งเดียว');
        $this->assertStringNotContainsString('data-board-mine-toggle', $html, 'ปุ่มสลับเดิมต้องถูกแทนที่ ไม่ใช่วางซ้อน');
        // ปุ่ม "งานทั้งหมด / งานของฉัน / งานของทีม" ซ้ำกับตัวกรองขอบเขตงาน จึงต้องไม่ถูก render
        $this->assertStringNotContainsString('data-board-owner-option', $html);
        $this->assertStringNotContainsString('mytasks-owner-filter__segments', $html);
        $this->assertStringContainsString('data-task-scope-menu', $html, 'ตัวกรองขอบเขตงานยังทำหน้าที่เลือกงานของใคร');
        // ค่าเริ่มต้นต้องเป็นผู้รับผิดชอบทุกคน บอร์ดตอนโหลดหน้าจึงแสดงทั้งโปรเจกต์เหมือนเดิม
        $this->assertMatchesRegularExpression('/<option value="" [^>]*>ผู้รับผิดชอบทุกคน<\/option>/', $html);

        $wrapper = $this->filterWrapper($html);
        $this->assertStringNotContainsString('hidden', $wrapper, 'มุมมองบอร์ดต้องเห็นตัวกรอง');
        $this->assertStringContainsString('data-owner-filter-views="board"', $wrapper);
    }

    public function test_the_owner_filter_is_hidden_on_the_kanban_without_a_department_grant(): void
    {
        $department = Department::create(['department_name' => 'IT']);
        $worker = $this->user($department);

        $html = $this->actingAs($worker)
            ->get(route('mytasks.index', ['view' => 'table']))
            ->assertOk()
            ->getContent();

        // บอร์ด kanban ของคนทั่วไปวาดเฉพาะงานของตัวเองที่ฝั่ง server ตัวกรองที่นั่นจึงไม่มีผล
        $wrapper = $this->filterWrapper($html);
        $this->assertStringContainsString('hidden', $wrapper);
        $this->assertStringContainsString('data-owner-filter-views="board"', $wrapper);
    }

    public function test_a_department_grant_turns_the_owner_filter_on_for_the_kanban_too(): void
    {
        $department = Department::create(['department_name' => 'IT']);
        $worker = $this->user($department);
        $worker->forceFill(['task_visibility_scope' => DepartmentTaskVisibility::SCOPE_OWN_DEPARTMENT])->save();

        $html = $this->actingAs($worker->fresh())
            ->get(route('mytasks.index', ['view' => 'table']))
            ->assertOk()
            ->getContent();

        $wrapper = $this->filterWrapper($html);
        $this->assertStringNotContainsString('hidden', $wrapper);
        $this->assertStringContainsString('data-owner-filter-views="board table"', $wrapper);
    }

    /**
     * รายชื่อผู้รับผิดชอบในตัวกรองมาจากงานที่ถูกวาดอยู่ในหน้าเท่านั้น
     */
    public function test_the_assignee_filter_lists_only_people_whose_tasks_are_on_the_page(): void
    {
        $department = Department::create(['department_name' => 'IT']);
        $assigner = $this->user($department);
        $worker = $this->user($department);
        $stranger = $this->user($department);
        $project = $this->list($assigner);

        $mine = $this->task($worker, $assigner, $project, 'งานของฉัน');
        $this->invite($mine, $worker, $assigner);
        $this->task($assigner, $assigner, $project, 'งานของผู้มอบหมาย');
        $this->task($stranger, $stranger, $this->list($stranger), 'งานที่ฉันมองไม่เห็น');

        $html = $this->actingAs($worker)->get(route('mytasks.index', ['view' => 'board']))->assertOk()->getContent();

        // ตัดเฉพาะ <select> ของตัวกรองนี้ — หน้าเดียวกันมี <option> ของโปรเจกต์ที่ใช้เลขซ้ำกันได้
        $this->assertSame(1, preg_match('/<select data-board-assignee-filter.*?<\/select>/s', $html, $select));
        $this->assertStringContainsString('<option value="'.$assigner->id.'"', $select[0]);
        $this->assertStringContainsString('<option value="'.$worker->id.'"', $select[0]);
        $this->assertStringNotContainsString('<option value="'.$stranger->id.'"', $select[0]);
        // แถวต้องพกผู้รับผิดชอบไว้ให้ตัวกรอง และแสดงชื่อข้างรูป
        $this->assertMatchesRegularExpression('/data-task-id="'.$mine->job_id.'"\s+data-participate="1"\s+data-assignee="'.$worker->id.'"/', $html);
        $this->assertStringContainsString('data-board-owner-name>'.e($assigner->name).'</span>', $html);
    }

    /**
     * Admin Member Workspace ใช้แถวบอร์ดชุดเดียวกัน แต่ต้องไม่มีปุ่มนี้
     *
     * participate() คืน true ทุกใบเมื่อผู้ใช้เป็น admin ปุ่มจึงเป็น no-op ที่ทำให้สับสน
     */
    public function test_the_admin_member_workspace_does_not_render_the_toggle(): void
    {
        $department = Department::create(['department_name' => 'IT']);
        $admin = User::factory()->create([
            'role' => 'admin',
            'department_id' => $department->id,
            'must_change_password' => false,
            'is_active' => true,
        ]);
        $member = $this->user($department);

        $html = $this->actingAs($admin)
            ->get(route('admin.work-board.member', ['department' => $department, 'user' => $member]))
            ->assertOk()
            ->getContent();

        $this->assertStringNotContainsString('data-board-mine-toggle', $html);
        $this->assertStringNotContainsString('data-board-owner-option', $html);
    }

    private function filterWrapper(string $html): string
    {
        preg_match('/<div class="mytasks-owner-filter"[^>]*>/', $html, $wrapper);
        $this->assertNotEmpty($wrapper, 'ไม่พบตัวครอบของตัวกรอง');

        return $wrapper[0];
    }

    /**
     * อ่านด้วย regex แทนการจับคู่สตริงทั้งก้อน เพราะ Blade จัดบรรทัดแอตทริบิวต์ไว้หลายบรรทัด
     * เทสต์จึงไม่พังเมื่อมีคนจัดรูปแบบไฟล์ใหม่
     */
    private function participationFlag(string $html, int $taskId): ?string
    {
        $pattern = '/data-board-task[^>]*?data-task-id="'.$taskId.'"\s+data-participate="(\d)"/';

        return preg_match($pattern, $html, $matches) ? $matches[1] : null;
    }

    /** @return array<int, int> */
    private function boardTaskIds(string $html): array
    {
        preg_match_all('/data-board-task[^>]*?data-task-id="(\d+)"/', $html, $matches);

        return array_map('intval', $matches[1]);
    }

    private function invite(WorkOrder $task, User $person, User $addedBy): void
    {
        $task->collaborators()->attach($person->id, [
            'added_by' => $addedBy->id,
            'status' => 'accepted',
            'responded_at' => now(),
        ]);
    }

    private function user(Department $department): User
    {
        return User::factory()->create([
            'role' => 'user',
            'department_id' => $department->id,
            'must_change_password' => false,
            'is_active' => true,
        ]);
    }

    private function list(User $owner): WorkOrderList
    {
        return WorkOrderList::create([
            'user_id' => $owner->id,
            'name' => 'โปรเจกต์ร่วมแผนก',
            'is_visible' => true,
            'sort_order' => 1,
        ]);
    }

    private function task(User $assignee, User $creator, WorkOrderList $list, string $topic, array $overrides = []): WorkOrder
    {
        return WorkOrder::create(array_merge([
            'user_id' => $assignee->id,
            'created_by' => $creator->id,
            'assigned_by' => $creator->id,
            'leader_user_id' => $creator->id,
            'work_order_list_id' => $list->id,
            'job_topic' => $topic,
            'job_priority' => 2,
            'job_status' => 2,
            'approval_status' => 'approved',
            'job_start_at' => now()->subDay(),
            'job_due_at' => now()->addDay(),
        ], $overrides));
    }
}
