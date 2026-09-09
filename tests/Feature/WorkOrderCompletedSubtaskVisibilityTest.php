<?php

namespace Tests\Feature;

use App\Models\Department;
use App\Models\User;
use App\Models\WorkOrder;
use App\Models\WorkOrderList;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * งานย่อยที่ปิดแล้วต้องไม่หายไปจากหน้าจอ
 *
 * งานย่อยเป็น WorkOrder จริง แต่มันไม่ใช่ "งาน" ของบอร์ด มันเป็นแถวใต้งานแม่
 * มันจึงต้องไม่ถูกย้ายเข้ากลุ่ม "งานที่เสร็จแล้ว" ของโปรเจกต์เมื่อปิด และต้องยังอยู่
 * ใต้งานแม่ทั้งตอนงานแม่ยังเปิด และตอนงานแม่ปิดแล้วย้ายเข้ากลุ่มงานที่เสร็จแล้ว
 */
class WorkOrderCompletedSubtaskVisibilityTest extends TestCase
{
    use RefreshDatabase;

    private function scenario(int $parentStatus, int $childStatus): array
    {
        $department = Department::create(['department_name' => 'IT']);
        $owner = User::factory()->create(['role' => 'user', 'department_id' => $department->id]);
        $list = WorkOrderList::create([
            'user_id' => $owner->id,
            'name' => 'โปรเจกต์ทดสอบ',
            'priority' => 2,
            'is_visible' => true,
        ]);

        $parent = WorkOrder::create([
            'user_id' => $owner->id,
            'created_by' => $owner->id,
            'assigned_by' => $owner->id,
            'department_id' => $department->id,
            'work_order_list_id' => $list->id,
            'job_topic' => 'งานแม่ทดสอบ',
            'job_priority' => 2,
            'job_status' => $parentStatus,
            'job_start_at' => now(),
            'job_due_at' => now()->addDay(),
            'job_completed_at' => $parentStatus === 4 ? now() : null,
        ]);

        $child = WorkOrder::create([
            'user_id' => $owner->id,
            'created_by' => $owner->id,
            'assigned_by' => $owner->id,
            'department_id' => $department->id,
            'work_order_list_id' => $list->id,
            'parent_job_id' => $parent->job_id,
            'parent_sort_order' => 0,
            'job_topic' => 'งานย่อยที่ปิดแล้ว',
            'job_priority' => 2,
            'job_status' => $childStatus,
            'job_start_at' => now(),
            'job_due_at' => now()->addDay(),
            'job_completed_at' => $childStatus === 4 ? now() : null,
        ]);

        return [$owner, $parent, $child];
    }

    public function test_a_closed_subtask_stays_under_an_open_parent_on_the_board(): void
    {
        [$owner] = $this->scenario(2, 4);

        $this->actingAs($owner)
            ->get(route('mytasks.index', ['view' => 'board']))
            ->assertOk()
            ->assertSee('งานย่อยที่ปิดแล้ว');
    }

    public function test_a_closed_parent_still_shows_its_closed_subtasks(): void
    {
        [$owner] = $this->scenario(4, 4);

        $this->actingAs($owner)
            ->get(route('mytasks.index', ['view' => 'board']))
            ->assertOk()
            ->assertSee('งานแม่ทดสอบ')
            ->assertSee('งานย่อยที่ปิดแล้ว');
    }

    public function test_a_closed_subtask_is_never_counted_as_a_completed_task_of_the_project(): void
    {
        [$owner, $parent] = $this->scenario(2, 4);

        $response = $this->actingAs($owner)->get(route('mytasks.index', ['view' => 'board']))->assertOk();

        /*
         * กลุ่ม "งานที่เสร็จแล้ว" นับเป็นจำนวนงาน ไม่ใช่จำนวนแถว
         * งานแม่ยังเปิดอยู่ กลุ่มนี้จึงต้องไม่ถูกสร้างขึ้นมาเลยสำหรับโปรเจกต์นี้
         */
        $response->assertDontSee('data-completed-group', false);
        $this->assertSame(2, (int) $parent->fresh()->job_status);
    }
}
