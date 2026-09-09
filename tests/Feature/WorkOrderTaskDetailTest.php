<?php

namespace Tests\Feature;

use App\Models\Department;
use App\Models\User;
use App\Models\WorkOrder;
use App\Models\WorkOrderList;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * งานย่อยคือ WorkOrder ที่มี parent_job_id
 *
 * เทสต์ชุดนี้คุมสองอย่างที่พังง่ายที่สุดของโครงสร้างนี้ คือ งานย่อยต้องมีฟิลด์ครบ
 * เหมือนงานปกติ (สถานะ ความสำคัญ วันที่ ผู้รับผิดชอบ) และต้องไม่โผล่เป็นงานเดี่ยว
 * ซ้ำในบอร์ดหรือแถบสรุป
 */
class WorkOrderTaskDetailTest extends TestCase
{
    use RefreshDatabase;

    public function test_full_task_creation_stores_repeatable_details_and_renders_them_on_board(): void
    {
        $department = Department::create(['department_name' => 'IT']);
        $owner = User::factory()->create(['role' => 'user', 'department_id' => $department->id]);

        $this->actingAs($owner)
            ->postJson(route('mytasks.create'), [
                'project_name' => 'งานอบรม',
                'job_topic' => 'เตรียมของ',
                'subtasks' => ['ซื้ออุปกรณ์', '', 'จัดชุดเอกสาร'],
                'user_id' => $owner->id,
                'job_start_at' => now()->format('Y-m-d'),
                'job_due_at' => now()->addDay()->format('Y-m-d'),
                'job_priority' => 2,
            ])
            ->assertCreated();

        $task = WorkOrder::where('job_topic', 'เตรียมของ')->firstOrFail();

        $this->assertSame(
            ['ซื้ออุปกรณ์', 'จัดชุดเอกสาร'],
            $task->children()->pluck('job_topic')->all()
        );

        $child = $task->children()->firstOrFail();
        $this->assertSame((int) $task->job_priority, (int) $child->job_priority);
        $this->assertSame($owner->id, (int) $child->user_id);
        $this->assertSame((int) $task->work_order_list_id, (int) $child->work_order_list_id);
        $this->assertNotNull($child->job_due_at);

        $this->actingAs($owner)
            ->get(route('mytasks.index', ['view' => 'board']))
            ->assertOk()
            ->assertSee('data-task-details-toggle', false)
            ->assertSee('data-detail-project-target="1"', false)
            ->assertSee('ซื้ออุปกรณ์')
            ->assertSee('จัดชุดเอกสาร');
    }

    public function test_child_tasks_are_not_listed_as_their_own_board_rows(): void
    {
        $owner = User::factory()->create(['role' => 'user']);
        $project = WorkOrderList::create(['user_id' => $owner->id, 'name' => 'Project A']);
        $parent = $this->task($owner, $project, 'Parent task');

        $this->actingAs($owner)
            ->postJson(route('mytasks.details.store', $parent), ['title' => 'Child task'])
            ->assertCreated();

        $child = $parent->children()->firstOrFail();

        $response = $this->actingAs($owner)->get(route('mytasks.index', ['view' => 'board']))->assertOk();
        $html = $response->getContent();

        // แถวของงานย่อยมีได้แถวเดียว คือแถวซ่อนที่โมดัลรายละเอียดงานใช้อ่านค่า
        $this->assertSame(1, substr_count($html, 'data-row data-id="'.$child->job_id.'"'));
        $this->assertStringContainsString('data-child-task="1"', $html);
        // การ์ดของบอร์ดเป็นของงานแม่เท่านั้น งานย่อยต้องไม่มีการ์ดเป็นของตัวเอง
        $this->assertStringNotContainsString('data-board-task data-detail-target="1" data-project-key="Project A" data-task-id="'.$child->job_id.'"', $html);
    }

    public function test_child_task_row_ships_the_same_board_controls_as_its_parent(): void
    {
        $owner = User::factory()->create(['role' => 'user']);
        $project = WorkOrderList::create(['user_id' => $owner->id, 'name' => 'Project A']);
        $parent = $this->task($owner, $project, 'Parent task');

        $created = $this->actingAs($owner)
            ->postJson(route('mytasks.details.store', $parent), ['title' => 'Child task'])
            ->assertCreated();
        $child = WorkOrder::findOrFail($created->json('detail.id'));

        $html = $this->actingAs($owner)->get(route('mytasks.index', ['view' => 'board']))->assertOk()->getContent();
        $anchor = strpos($html, 'data-detail-id="'.$child->job_id.'"');
        $row = substr($html, (int) strrpos(substr($html, 0, $anchor), '<li '));
        $row = substr($row, 0, (int) strpos($row, '</li>'));

        // งานย่อยต้องแก้สถานะ ความสำคัญ วันที่ แนบไฟล์ และคอมเมนต์ได้จากแถวเหมือนงานแม่
        $this->assertStringContainsString('data-board-status-value', $row);
        $this->assertStringContainsString('data-board-priority-value', $row);
        $this->assertStringContainsString('data-board-field="start"', $row);
        $this->assertStringContainsString('data-board-field="due"', $row);
        $this->assertStringContainsString('data-board-open-attachments="'.$child->job_id.'"', $row);
        $this->assertStringContainsString('data-task-tab="updates"', $row);
        // และต้องถูกทำเครื่องหมายไว้ให้โค้ดที่ไล่รายการงานข้ามไป
        $this->assertStringContainsString('data-board-subtask="1"', $row);
    }

    public function test_owner_can_create_edit_move_reorder_and_delete_task_details(): void
    {
        $owner = User::factory()->create(['role' => 'user']);
        $firstProject = WorkOrderList::create(['user_id' => $owner->id, 'name' => 'Project A']);
        $secondProject = WorkOrderList::create(['user_id' => $owner->id, 'name' => 'Project B']);
        $source = $this->task($owner, $firstProject, 'Source task');
        $target = $this->task($owner, $secondProject, 'Target task');

        $existingResponse = $this->actingAs($owner)
            ->postJson(route('mytasks.details.store', $target), ['title' => 'Existing target detail'])
            ->assertCreated();
        $existing = WorkOrder::findOrFail($existingResponse->json('detail.id'));

        $createdResponse = $this->actingAs($owner)
            ->postJson(route('mytasks.details.store', $source), ['title' => 'New detail'])
            ->assertCreated()
            ->assertJsonPath('detail.work_order_id', $source->job_id)
            ->assertJsonPath('detail.status', 2)
            ->assertJsonPath('detail.priority', (int) $source->job_priority);

        $this->actingAs($owner)
            ->postJson(route('mytasks.details.store', $source), ['title' => '   '])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('title');

        $detail = WorkOrder::findOrFail($createdResponse->json('detail.id'));

        $this->actingAs($owner)
            ->patchJson(route('mytasks.details.update', $detail), ['title' => 'Renamed detail'])
            ->assertOk()
            ->assertJsonPath('detail.title', 'Renamed detail');

        $this->actingAs($owner)
            ->patchJson(route('mytasks.details.move', $detail), [
                'target_work_order_id' => $target->job_id,
                'position' => 0,
            ])
            ->assertOk()
            ->assertJsonPath('target.project_id', $secondProject->id);

        $this->assertSame($target->job_id, (int) $detail->fresh()->parent_job_id);
        $this->assertSame(
            [$detail->job_id, $existing->job_id],
            $target->children()->pluck('job_id')->all()
        );

        $this->actingAs($owner)
            ->deleteJson(route('mytasks.details.destroy', $detail))
            ->assertOk();

        // งานย่อยเป็นงานจริง จึงเป็น soft delete และกู้คืนจากถังขยะได้
        $this->assertSoftDeleted('work_orders', ['job_id' => $detail->job_id]);
        $this->assertSame(0, (int) $existing->fresh()->parent_sort_order);
    }

    public function test_detail_cannot_be_changed_or_moved_to_a_task_outside_the_users_access(): void
    {
        $owner = User::factory()->create(['role' => 'user']);
        $outsider = User::factory()->create(['role' => 'user']);
        $ownerProject = WorkOrderList::create(['user_id' => $owner->id, 'name' => 'Owner project']);
        $outsiderProject = WorkOrderList::create(['user_id' => $outsider->id, 'name' => 'Outsider project']);
        $source = $this->task($owner, $ownerProject, 'Owner task');
        $target = $this->task($outsider, $outsiderProject, 'Private task');

        $created = $this->actingAs($owner)
            ->postJson(route('mytasks.details.store', $source), ['title' => 'Protected detail'])
            ->assertCreated();
        $detail = WorkOrder::findOrFail($created->json('detail.id'));

        $this->actingAs($outsider)
            ->patchJson(route('mytasks.details.update', $detail), ['title' => 'No access'])
            ->assertForbidden();

        $this->actingAs($owner)
            ->patchJson(route('mytasks.details.move', $detail), [
                'target_work_order_id' => $target->job_id,
            ])
            ->assertForbidden();

        $this->assertSame($source->job_id, (int) $detail->fresh()->parent_job_id);
        $this->assertSame('Protected detail', $detail->fresh()->job_topic);
    }

    public function test_detail_cannot_be_moved_under_itself(): void
    {
        $owner = User::factory()->create(['role' => 'user']);
        $project = WorkOrderList::create(['user_id' => $owner->id, 'name' => 'Project A']);
        $parent = $this->task($owner, $project, 'Parent task');

        $created = $this->actingAs($owner)
            ->postJson(route('mytasks.details.store', $parent), ['title' => 'Child task'])
            ->assertCreated();
        $detail = WorkOrder::findOrFail($created->json('detail.id'));

        $this->actingAs($owner)
            ->patchJson(route('mytasks.details.move', $detail), [
                'target_work_order_id' => $detail->job_id,
            ])
            ->assertStatus(422);

        $this->assertSame($parent->job_id, (int) $detail->fresh()->parent_job_id);
    }

    public function test_deleting_a_parent_task_takes_its_child_tasks_with_it_and_restores_them_together(): void
    {
        $owner = User::factory()->create(['role' => 'user']);
        $project = WorkOrderList::create(['user_id' => $owner->id, 'name' => 'Project A']);
        $parent = $this->task($owner, $project, 'Parent task');

        $created = $this->actingAs($owner)
            ->postJson(route('mytasks.details.store', $parent), ['title' => 'Child task'])
            ->assertCreated();
        $child = WorkOrder::findOrFail($created->json('detail.id'));

        $parent->delete();

        $this->assertSoftDeleted('work_orders', ['job_id' => $child->job_id]);

        $parent->restore();

        $this->assertNull(WorkOrder::withTrashed()->findOrFail($child->job_id)->deleted_at);
    }

    /**
     * ปิดงานย่อยแล้วมันต้องยังอยู่ใต้งานแม่ ไม่ถูกเก็บเข้ากลุ่ม "งานที่เสร็จแล้ว" ของโปรเจกต์
     *
     * กลุ่มงานที่เสร็จแล้วเก็บเป็น "งาน" ทั้งใบ งานย่อยที่ปิดแล้วจึงตามงานแม่เข้าไปทีหลัง
     * พร้อมแถวของมันเอง ไม่ใช่หลุดออกไปตอนถูกปิดจนผู้ใช้หามันไม่เจอ
     */
    public function test_a_closed_child_task_stays_under_its_open_parent_on_the_board(): void
    {
        $owner = User::factory()->create(['role' => 'user']);
        $project = WorkOrderList::create(['user_id' => $owner->id, 'name' => 'Project A']);
        $parent = $this->task($owner, $project, 'Parent task');

        $created = $this->actingAs($owner)
            ->postJson(route('mytasks.details.store', $parent), ['title' => 'Child task'])
            ->assertCreated();
        $child = WorkOrder::findOrFail($created->json('detail.id'));

        $this->actingAs($owner)
            ->patchJson(route('tasks.updateStatus', $child->job_id), ['job_status' => 4])
            ->assertOk();

        $this->assertSame(4, (int) $child->fresh()->job_status);
        $this->assertSame(2, (int) $parent->fresh()->job_status);

        $html = $this->actingAs($owner)->get(route('mytasks.index', ['view' => 'board']))->assertOk()->getContent();

        // แถวงานย่อยยังอยู่ และงานแม่ที่ยังไม่ปิดต้องไม่ถูกยกเข้ากลุ่มงานที่เสร็จแล้ว
        $this->assertStringContainsString('data-detail-id="'.$child->job_id.'"', $html);
        $this->assertStringContainsString('Child task', $html);
        $this->assertStringNotContainsString('data-completed-group', $html);
    }

    /** ปิดงานแม่ได้เมื่องานย่อยเสร็จครบ แล้วกลุ่ม "งานที่เสร็จแล้ว" ต้องยังเห็นงานย่อยของมัน */
    public function test_closing_the_parent_moves_it_into_the_completed_group_with_its_child_tasks(): void
    {
        $owner = User::factory()->create(['role' => 'user']);
        $project = WorkOrderList::create(['user_id' => $owner->id, 'name' => 'Project A']);
        $parent = $this->task($owner, $project, 'Parent task');

        $created = $this->actingAs($owner)
            ->postJson(route('mytasks.details.store', $parent), ['title' => 'Child task'])
            ->assertCreated();
        $child = WorkOrder::findOrFail($created->json('detail.id'));

        // งานย่อยยังค้างอยู่ ปิดงานแม่ไม่ได้
        $this->actingAs($owner)
            ->patchJson(route('tasks.updateStatus', $parent->job_id), ['job_status' => 4])
            ->assertStatus(422);

        $this->actingAs($owner)
            ->patchJson(route('tasks.updateStatus', $child->job_id), ['job_status' => 4])
            ->assertOk();
        $this->actingAs($owner)
            ->patchJson(route('tasks.updateStatus', $parent->job_id), ['job_status' => 4])
            ->assertOk();

        $html = $this->actingAs($owner)->get(route('mytasks.index', ['view' => 'board']))->assertOk()->getContent();

        $this->assertStringContainsString('data-completed-group', $html);
        $group = substr($html, (int) strpos($html, 'data-completed-group'));
        $this->assertStringContainsString('data-detail-id="'.$child->job_id.'"', $group);
    }

    /** ผู้ร่วมงานถูกเชิญเข้างานย่อยได้เหมือนงานปกติ เพราะงานย่อยเป็น WorkOrder จริง */
    public function test_collaborators_can_be_invited_to_a_child_task(): void
    {
        $department = Department::create(['department_name' => 'IT']);
        $owner = User::factory()->create(['role' => 'user', 'department_id' => $department->id]);
        $mate = User::factory()->create(['role' => 'user', 'is_active' => true, 'department_id' => $department->id]);
        $project = WorkOrderList::create(['user_id' => $owner->id, 'name' => 'Project A']);
        $parent = $this->task($owner, $project, 'Parent task');

        $created = $this->actingAs($owner)
            ->postJson(route('mytasks.details.store', $parent), ['title' => 'Child task'])
            ->assertCreated();
        $child = WorkOrder::findOrFail($created->json('detail.id'));

        $this->actingAs($owner)
            ->postJson(route('tasks.collaborators.store', $child->job_id), ['collaborators' => [$mate->id]])
            ->assertOk();

        $this->assertTrue($child->fresh()->collaborators->contains('id', $mate->id));

        // งานย่อยที่ปิดแล้วถูกล็อกการจัดการทีมเหมือนงานปกติ
        $this->actingAs($owner)
            ->patchJson(route('tasks.updateStatus', $child->job_id), ['job_status' => 4])
            ->assertOk();
        $this->actingAs($owner)
            ->postJson(route('tasks.collaborators.store', $child->job_id), ['collaborators' => [$mate->id]])
            ->assertForbidden();
    }

    private function task(User $owner, WorkOrderList $project, string $topic): WorkOrder
    {
        return WorkOrder::create([
            'user_id' => $owner->id,
            'created_by' => $owner->id,
            'assigned_by' => $owner->id,
            'leader_user_id' => $owner->id,
            'work_order_list_id' => $project->id,
            'job_topic' => $topic,
            'job_priority' => 2,
            'job_status' => 2,
            'approval_status' => 'approved',
            'approved_by' => $owner->id,
            'approved_at' => now(),
            'job_start_at' => now(),
            'job_due_at' => now()->addDay(),
        ]);
    }
}
