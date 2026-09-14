<?php

namespace Tests\Feature;

use App\Models\Department;
use App\Models\User;
use App\Models\WorkOrder;
use App\Models\WorkOrderList;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * มุมมอง "ตาราง" (data-view="table") เป็นกระดานงานของผู้ใช้คนนั้นคนเดียว
 *
 * โปรเจกต์ที่ทำร่วมกันทำให้ผู้ใช้มองเห็นงานของเพื่อนร่วมแผนกและของผู้มอบหมายไปด้วย
 * ซึ่งถูกต้องสำหรับมุมมอง "บอร์ด" ที่มีหน้าที่แสดงภาพรวมของทั้งโปรเจกต์
 * แต่ตารางจัดคอลัมน์ตามสถานะเพื่อให้ลากงานของตัวเองไปต่อ งานที่ลากไม่ได้จึงเป็น
 * แค่สิ่งกีดขวางสายตา
 *
 * เทสต์ชุดนี้คุมทั้งสองด้านพร้อมกัน: ตารางต้องตัดงานของคนอื่นออก และบอร์ดต้องไม่ถูกแตะ
 */
class MyTasksTableViewOwnershipTest extends TestCase
{
    use RefreshDatabase;

    public function test_table_view_shows_only_the_viewers_own_work_while_the_board_shows_the_whole_project(): void
    {
        $department = Department::create(['department_name' => 'IT']);
        $assigner = $this->user($department);
        $worker = $this->user($department);
        $project = $this->list($assigner);

        // ผู้มอบหมายส่งงานให้ผู้ทำงาน และดึงเข้าเป็นผู้ร่วมงาน ซึ่งเป็นเงื่อนไขที่ทำให้
        // งานอื่นในโปรเจกต์เดียวกันมองเห็นได้ตาม scopeVisibleInProjectsFor()
        $assignedToWorker = $this->task($worker, $assigner, $project, 'งานที่ถูกมอบหมาย');
        $assignedToWorker->collaborators()->attach($worker->id, [
            'added_by' => $assigner->id,
            'status' => 'accepted',
            'responded_at' => now(),
        ]);

        $ownedByAssigner = $this->task($assigner, $assigner, $project, 'งานของผู้มอบหมายเอง');

        // งานของตัวเองที่เพิ่งปิดวันนี้ ต้องยังอยู่ในคอลัมน์ "เสร็จแล้ว"
        // นี่คือเหตุผลที่ตัวกรองใช้ ability participate ไม่ใช่ work ซึ่งปฏิเสธงานสถานะ 4 ทุกใบ
        $closedByWorker = $this->task($worker, $assigner, $project, 'งานของฉันที่ปิดไปแล้ว', [
            'job_status' => 4,
            'job_completed_at' => now(),
        ]);

        $html = $this->actingAs($worker)->get(route('mytasks.index'))->assertOk()->getContent();

        $kanban = $this->kanbanCardIds($html);
        $board = $this->boardTaskIds($html);

        // ตาราง: เห็นเฉพาะงานของตัวเอง
        $this->assertContains((int) $assignedToWorker->job_id, $kanban);
        $this->assertContains((int) $closedByWorker->job_id, $kanban, 'งานของตัวเองที่ปิดวันนี้ต้องไม่หายไปจากคอลัมน์เสร็จแล้ว');
        $this->assertNotContains((int) $ownedByAssigner->job_id, $kanban, 'งานของผู้มอบหมายต้องไม่อยู่ในตาราง');

        // บอร์ด: ยังเห็นภาพรวมของโปรเจกต์ครบเหมือนเดิม
        $this->assertContains((int) $ownedByAssigner->job_id, $board, 'บอร์ดต้องไม่ถูกแตะ');
        $this->assertContains((int) $assignedToWorker->job_id, $board);
    }

    public function test_the_assigner_still_sees_their_own_work_in_the_table(): void
    {
        $department = Department::create(['department_name' => 'IT']);
        $assigner = $this->user($department);
        $worker = $this->user($department);
        $project = $this->list($assigner);

        $ownedByAssigner = $this->task($assigner, $assigner, $project, 'งานของผู้มอบหมายเอง');
        $delegated = $this->task($worker, $assigner, $project, 'งานที่ส่งต่อให้ลูกทีม');

        $kanban = $this->kanbanCardIds(
            $this->actingAs($assigner)->get(route('mytasks.index'))->assertOk()->getContent()
        );

        $this->assertContains((int) $ownedByAssigner->job_id, $kanban);
        // ผู้มอบหมายเป็นผู้สร้างและหัวหน้างานของใบที่ส่งต่อ จึงยังนับเป็นงานของเขาเอง
        $this->assertContains((int) $delegated->job_id, $kanban);
    }

    /**
     * อ่านด้วย regex แทนการจับคู่สตริงทั้งก้อน เพราะ Blade จัดบรรทัดแอตทริบิวต์ไว้หลายบรรทัด
     * เทสต์จึงไม่พังเมื่อมีคนจัดรูปแบบไฟล์ใหม่
     *
     * @return array<int, int>
     */
    private function kanbanCardIds(string $html): array
    {
        preg_match_all('/data-kanban-card\s+data-id="(\d+)"/', $html, $matches);

        return array_map('intval', $matches[1]);
    }

    /** @return array<int, int> */
    private function boardTaskIds(string $html): array
    {
        preg_match_all('/data-board-task[^>]*?data-task-id="(\d+)"/', $html, $matches);

        return array_map('intval', $matches[1]);
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
