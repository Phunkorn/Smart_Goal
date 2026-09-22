<?php

namespace Tests\Feature;

use App\Models\Department;
use App\Models\User;
use App\Models\WorkOrder;
use App\Models\WorkOrderList;
use App\Support\TodayWorkspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ป้ายเตือน "มีงานย่อยเลยกำหนด" บนหัวข้องานในมุมมองบอร์ด
 *
 * ผู้ใช้ที่มีงานย่อยจำนวนมากต้องกางทุกงานออกมาดูถึงจะรู้ว่ามีใบไหนล่าช้า ป้ายนี้ยก
 * ข้อมูลนั้นขึ้นมาไว้หลังชื่องาน ซึ่งเป็นสิ่งที่ผู้ใช้อ่านก่อนเสมอ
 *
 * สัญญาที่สำคัญที่สุดของชุดนี้คือ "เลขบนป้าย = จำนวนแถวงานย่อยที่เป็นสีแดง" ทั้งสองค่า
 * ต้องมาจาก TodayWorkspace::isOverdue() ที่เดียว
 */
class MyTasksBoardLateSubtaskBadgeTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_board_shows_a_late_badge_with_the_number_of_overdue_subtasks(): void
    {
        [$user, $project] = $this->workspace();
        $parent = $this->task($user, $project, 'งานแม่ที่มีงานย่อยล่าช้า');

        $this->subtask($user, $project, $parent, 'งานย่อยเลยกำหนด 1', ['job_due_at' => now()->subDays(3)]);
        $this->subtask($user, $project, $parent, 'งานย่อยเลยกำหนด 2', ['job_due_at' => now()->subDay()]);
        $this->subtask($user, $project, $parent, 'งานย่อยที่ยังไม่ถึงกำหนด', ['job_due_at' => now()->addWeek()]);

        $html = $this->board($user);

        $this->assertStringContainsString('data-task-details-late', $html);
        $this->assertStringContainsString('bi-exclamation-circle', $html);
        $this->assertSame(2, $this->badgeCount($html), 'ป้ายต้องบอกจำนวนงานย่อยที่เลยกำหนด');
    }

    /**
     * สัญญาหลัก — เลขบนป้ายต้องเท่ากับจำนวนแถวงานย่อยที่ถูกทำเป็นสีแดง
     *
     * เทสต์นี้คือตัวเดียวที่จับได้ถ้ามีคนเปลี่ยนป้ายไปใช้ WorkBoardDesign::statusKey()
     * (ซึ่งปัดไปสิ้นวันและให้พักงานชนะ) หรือ job_status === 6 แทน isOverdue()
     */
    public function test_the_badge_count_equals_the_number_of_red_subtask_rows(): void
    {
        [$user, $project] = $this->workspace();
        $parent = $this->task($user, $project, 'งานแม่');

        $this->subtask($user, $project, $parent, 'เลยกำหนดวันนี้ตอนเช้า', ['job_due_at' => now()->subHours(3)]);
        $this->subtask($user, $project, $parent, 'เลยกำหนดเมื่อวาน', ['job_due_at' => now()->subDay()]);
        $this->subtask($user, $project, $parent, 'พักงานแล้วเลยกำหนด', [
            'job_status' => 5,
            'job_due_at' => now()->subDays(2),
        ]);
        $this->subtask($user, $project, $parent, 'ยังไม่ถึงกำหนด', ['job_due_at' => now()->addDays(5)]);

        $html = $this->board($user);

        $redRows = preg_match_all('/class="board-task-detail[^"]*\bis-late\b/', $html);

        $this->assertSame(3, $redRows, 'ต้องมีแถวงานย่อยสีแดงสามแถว');
        $this->assertSame($redRows, $this->badgeCount($html), 'เลขบนป้ายต้องเท่ากับจำนวนแถวสีแดงเสมอ');
    }

    public function test_a_completed_subtask_is_never_counted_even_when_it_missed_its_deadline(): void
    {
        [$user, $project] = $this->workspace();
        $parent = $this->task($user, $project, 'งานแม่');

        $this->subtask($user, $project, $parent, 'ปิดไปแล้วแม้จะส่งช้า', [
            'job_status' => 4,
            'job_due_at' => now()->subWeek(),
            'job_completed_at' => now()->subDay(),
        ]);

        $html = $this->board($user);

        $this->assertStringNotContainsString('data-task-details-late', $html, 'งานที่ปิดแล้วต้องไม่ถูกนับว่าล่าช้า');
    }

    public function test_a_task_without_overdue_subtasks_renders_no_badge_at_all(): void
    {
        [$user, $project] = $this->workspace();
        $parent = $this->task($user, $project, 'งานแม่ที่ยังตามแผน');
        $this->subtask($user, $project, $parent, 'งานย่อยตามแผน', ['job_due_at' => now()->addWeek()]);

        $html = $this->board($user);

        // ต้องไม่ render element เปล่าทิ้งไว้ ไม่งั้น grid track จะถูกกินโดยของที่มองไม่เห็น
        $this->assertStringNotContainsString('data-task-details-late', $html);
    }

    /**
     * ผู้ร่วมงานข้ามแผนกเห็นเฉพาะงานย่อยที่ตัวเองมีส่วนร่วม ป้ายจึงต้องนับเท่าที่เขาเห็น
     *
     * พิสูจน์ว่าการนับเกาะไปกับ CrossDepartmentWork::restrictChildren() โดยอัตโนมัติ
     * ไม่ได้ไปนับจากฐานข้อมูลตรง ๆ ซึ่งจะรั่วจำนวนงานของแผนกอื่นออกมา
     */
    public function test_a_cross_department_viewer_only_counts_the_subtasks_they_can_see(): void
    {
        $owner = $this->user(Department::create(['department_name' => 'IT']));
        $outsider = $this->user(Department::create(['department_name' => 'HR']));
        $project = $this->list($owner);

        // ต้องเชิญเข้างานแม่ด้วย ไม่งั้นแถวงานแม่ไม่ถูกวาดบนบอร์ดของคนนอกเลย
        // (MyTaskController คัดงานย่อยออกจากรายการแถวหลัก งานย่อยแสดงใต้งานแม่เท่านั้น)
        $parent = $this->task($owner, $project, 'งานแม่ของแผนกไอที');
        $this->invite($parent, $outsider, $owner);

        $shared = $this->subtask($owner, $project, $parent, 'งานย่อยที่เชิญคนนอกมาช่วย', [
            'job_due_at' => now()->subDays(2),
        ]);
        $this->subtask($owner, $project, $parent, 'งานย่อยภายในที่คนนอกไม่เห็น', [
            'job_due_at' => now()->subDays(4),
        ]);

        $this->invite($shared, $outsider, $owner);

        $this->assertSame(1, $this->badgeCount($this->board($outsider)), 'คนนอกต้องเห็นเลขเท่างานย่อยที่ตัวเองเห็นเท่านั้น');
        $this->assertSame(2, $this->badgeCount($this->board($owner)), 'เจ้าของงานยังเห็นครบทั้งสองใบ');
    }

    public function test_is_overdue_matches_the_board_definition(): void
    {
        [$user, $project] = $this->workspace();

        // work_orders.job_due_at เป็น NOT NULL จึงสร้างงานที่ไม่มีกำหนดส่งลงฐานข้อมูลไม่ได้
        // ทดสอบสาขานี้กับ model ที่ยังไม่บันทึก เพื่อคุมพฤติกรรมของ helper ไว้เผื่อ schema เปลี่ยน
        $noDueDate = new WorkOrder(['job_status' => 2]);
        $future = $this->task($user, $project, 'ยังไม่ถึงกำหนด', ['job_due_at' => now()->addDay()]);
        $closed = $this->task($user, $project, 'ปิดแล้วแม้เลยกำหนด', [
            'job_status' => 4,
            'job_due_at' => now()->subDay(),
        ]);

        $this->assertFalse(TodayWorkspace::isOverdue($noDueDate));
        $this->assertFalse(TodayWorkspace::isOverdue($future));
        $this->assertFalse(TodayWorkspace::isOverdue($closed), 'งานที่ปิดแล้วไม่ใช่งานล่าช้า');

        // ทุกสถานะที่ยังไม่ปิดและเลยกำหนดแล้ว นับเป็นล่าช้าหมด
        foreach ([2, 3, 5, 6] as $status) {
            $overdue = $this->task($user, $project, 'เลยกำหนดที่สถานะ '.$status, [
                'job_status' => $status,
                'job_due_at' => now()->subDay(),
            ]);

            $this->assertTrue(TodayWorkspace::isOverdue($overdue), 'สถานะ '.$status.' ที่เลยกำหนดต้องนับเป็นล่าช้า');
        }
    }

    private function invite(WorkOrder $task, User $person, User $addedBy): void
    {
        $task->collaborators()->attach($person->id, [
            'added_by' => $addedBy->id,
            'status' => 'accepted',
            'responded_at' => now(),
        ]);
    }

    /** จำนวนบนป้าย อ่านจาก <b> ที่อยู่ในป้าย */
    private function badgeCount(string $html): int
    {
        return preg_match('/data-task-details-late[^>]*>.*?<b aria-hidden="true">(\d+)<\/b>/s', $html, $matches)
            ? (int) $matches[1]
            : 0;
    }

    private function board(User $user): string
    {
        return $this->actingAs($user)
            ->get(route('mytasks.index', ['view' => 'board']))
            ->assertOk()
            ->getContent();
    }

    /** @return array{0: User, 1: WorkOrderList} */
    private function workspace(): array
    {
        $user = $this->user(Department::create(['department_name' => 'IT']));

        return [$user, $this->list($user)];
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
            'name' => 'โปรเจกต์ทดสอบ',
            'is_visible' => true,
            'sort_order' => 1,
        ]);
    }

    private function task(User $owner, WorkOrderList $list, string $topic, array $overrides = []): WorkOrder
    {
        return WorkOrder::create(array_merge([
            'user_id' => $owner->id,
            'created_by' => $owner->id,
            'assigned_by' => $owner->id,
            'leader_user_id' => $owner->id,
            'work_order_list_id' => $list->id,
            'job_topic' => $topic,
            'job_priority' => 2,
            'job_status' => 2,
            'approval_status' => 'approved',
            'job_start_at' => now()->subWeek(),
            'job_due_at' => now()->addWeek(),
        ], $overrides));
    }

    private function subtask(User $owner, WorkOrderList $list, WorkOrder $parent, string $topic, array $overrides = []): WorkOrder
    {
        return $this->task($owner, $list, $topic, array_merge([
            'parent_job_id' => $parent->job_id,
        ], $overrides));
    }
}
