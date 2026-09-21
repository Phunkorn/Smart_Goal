<?php

namespace Tests\Feature;

use App\Models\Department;
use App\Models\User;
use App\Models\WorkOrder;
use App\Models\WorkOrderList;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ปุ่ม "เฉพาะงานของฉัน" บนมุมมองบอร์ด
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

    public function test_the_toggle_renders_once_and_starts_switched_off_on_the_board(): void
    {
        $department = Department::create(['department_name' => 'IT']);
        $worker = $this->user($department);

        $html = $this->actingAs($worker)
            ->get(route('mytasks.index', ['view' => 'board']))
            ->assertOk()
            ->getContent();

        $this->assertSame(1, substr_count($html, 'data-board-mine-toggle'), 'ปุ่มต้อง render เพียงครั้งเดียว');
        $this->assertStringContainsString('aria-pressed="false"', $html);

        // ค่าเริ่มต้นต้องเป็นปิด บอร์ดตอนโหลดหน้าจึงแสดงทั้งโปรเจกต์เหมือนเดิม
        preg_match('/<div class="mytasks-mine-filter"[^>]*>/', $html, $wrapper);
        $this->assertNotEmpty($wrapper, 'ไม่พบตัวครอบของปุ่ม');
        $this->assertStringNotContainsString('hidden', $wrapper[0], 'มุมมองบอร์ดต้องเห็นปุ่ม');
    }

    public function test_the_toggle_is_hidden_outside_the_board_view(): void
    {
        $department = Department::create(['department_name' => 'IT']);
        $worker = $this->user($department);

        $html = $this->actingAs($worker)
            ->get(route('mytasks.index', ['view' => 'table']))
            ->assertOk()
            ->getContent();

        preg_match('/<div class="mytasks-mine-filter"[^>]*>/', $html, $wrapper);
        $this->assertNotEmpty($wrapper);
        // มุมมองตารางกรองด้วย ability participate ที่ฝั่ง server อยู่แล้ว ปุ่มที่นั่นจึงไม่มีผล
        $this->assertStringContainsString('hidden', $wrapper[0]);
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
