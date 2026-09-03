<?php

namespace Tests\Feature;

use App\Models\Department;
use App\Models\User;
use App\Models\WorkOrder;
use App\Models\WorkOrderList;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Task Workspace แบบเต็มจอที่ Admin และ User ใช้ร่วมกัน
 *
 * โครงสร้างคือ โปรเจกต์ > รายการงาน จึงไม่มีช่อง "รายละเอียดงาน" อีกต่อไป
 * คอลัมน์ job_details ยังอยู่ในฐานข้อมูลเพื่อ backward compatibility แต่ต้องไม่ถูกแตะจากหน้านี้
 */
class TaskWorkspaceDesignTest extends TestCase
{
    use RefreshDatabase;

    private Department $department;

    protected function setUp(): void
    {
        parent::setUp();
        $this->department = Department::create(['department_name' => 'Workspace']);
    }

    /** โครงสร้างที่ทั้งสอง role ต้องได้เหมือนกัน ใช้พิสูจน์ว่าไม่ได้คัดลอกหน้าแยกสองชุด */
    private const SHARED_MARKUP = [
        'data-task-modal',
        'class="task-workspace"',
        'task-workspace__breadcrumb',
        'data-rename-task',
        'task-workspace__summary',
        'data-modal-status-menu',
        'data-modal-priority-menu',
        'name="job_start_at"',
        'name="job_due_at"',
        'data-workspace-assignee',
        'data-manage-team',
        'data-task-attachments',
        'data-task-inline-file-input',
        'data-timeline-tab="updates"',
        'data-timeline-tab="activity"',
        'data-task-update-note',
        'data-submit-task-update',
        'data-close-task',
        'data-save-task',
    ];

    public function test_user_opens_the_new_workspace_design(): void
    {
        [$member, , $task] = $this->scenario();

        $response = $this->actingAs($member)->get(route('mytasks.index'))->assertOk();

        foreach (self::SHARED_MARKUP as $marker) {
            $response->assertSee($marker, false);
        }

        $response->assertSee('ข้อมูลและความคืบหน้าของงาน')
            ->assertSee('href="'.route('mytasks.index').'"', false)
            ->assertSee('data-schedule-template="'.route('tasks.schedule.update', ['id' => '__ID__']).'"', false)
            ->assertSee('class="board-start board-start-editable"', false)
            ->assertSee('data-board-field="start"', false)
            ->assertSee('การเปลี่ยนแปลงจะถูกบันทึกเมื่อกดปุ่มบันทึก')
            ->assertSee('บันทึกการแก้ไข')
            ->assertSee('ยกเลิก')
            ->assertSee('ยังไม่มีไฟล์แนบ')
            ->assertSee('data-open-task-modal', false)
            ->assertSee('data-id="'.$task->job_id.'"', false);
    }

    public function test_admin_opens_the_same_workspace_design(): void
    {
        [$member, $admin] = $this->scenario();

        $response = $this->actingAs($admin)
            ->get(route('admin.work-board.member', [$this->department, $member]))
            ->assertOk();

        foreach (self::SHARED_MARKUP as $marker) {
            $response->assertSee($marker, false);
        }

        $response->assertSee('ข้อมูลและความคืบหน้าของงาน')
            // breadcrumb ของผู้ดูแลชี้กลับไปที่ Workspace ของสมาชิกคนนั้น
            ->assertSee('href="'.route('admin.work-board.member', [$this->department, $member]).'"', false);
    }

    public function test_workspace_uses_repeatable_task_details_without_restoring_the_legacy_description_field(): void
    {
        [$member, $admin] = $this->scenario();

        foreach ([
            [$member, route('mytasks.index')],
            [$admin, route('admin.work-board.member', [$this->department, $member])],
        ] as [$actor, $url]) {
            $content = $this->actingAs($actor)->get($url)
                ->assertOk()
                ->assertDontSee('name="job_details"', false)
                ->assertSee('data-task-details', false)
                ->assertSee('รายละเอียด')
                ->assertDontSee('รายละเอียดโปรเจกต์')
                ->getContent();

            // textarea เดียวที่เหลือได้คือช่องเขียนอัปเดตตามแบบที่อนุมัติ
            $this->assertSame(
                substr_count($content, 'data-task-update-note'),
                substr_count($content, '<textarea'),
                'ต้องไม่มี textarea อื่นนอกจากช่องเขียนอัปเดต'
            );
        }
    }

    public function test_renaming_a_task_saves_the_title_without_touching_task_details(): void
    {
        [$member, , $task] = $this->scenario();
        $task->forceFill(['job_details' => 'ข้อมูลเดิมจากระบบเก่า'])->save();

        $this->actingAs($member)
            ->patchJson(route('tasks.details.update', $task->job_id), ['job_topic' => 'ชื่อใหม่หลังแก้'])
            ->assertOk();

        $task->refresh();
        $this->assertSame('ชื่อใหม่หลังแก้', $task->job_topic);
        $this->assertSame('ข้อมูลเดิมจากระบบเก่า', $task->job_details, 'ข้อมูลเดิมต้องไม่ถูกล้างเมื่อ Workspace ไม่ส่ง job_details');
    }

    public function test_status_and_priority_changes_follow_permissions(): void
    {
        [$member, , $task] = $this->scenario();
        $stranger = $this->member();

        $this->actingAs($member)
            ->postJson(route('mytasks.updatePriority', $task->job_id), ['job_priority' => 3])
            ->assertOk();
        $this->assertSame(3, (int) $task->fresh()->job_priority);

        // 2 -> 5 คือเส้นทางที่ TaskStatusTransitionService อนุญาตสำหรับงานของตัวเอง
        $this->actingAs($member)
            ->patchJson(route('tasks.updateStatus', $task->job_id), ['job_status' => 5])
            ->assertOk();
        $this->assertSame(5, (int) $task->fresh()->job_status);

        $stranger2 = $this->member();
        $this->actingAs($stranger2)
            ->patchJson(route('tasks.updateStatus', $task->job_id), ['job_status' => 2])
            ->assertForbidden();
        $this->assertSame(5, (int) $task->fresh()->job_status);

        // ผู้ที่ไม่เกี่ยวข้องมองไม่เห็นงานนี้ในคิวรีตั้งต้น จึงถูกปฏิเสธด้วย 404 ก่อนถึง Policy
        $this->actingAs($stranger)
            ->postJson(route('mytasks.updatePriority', $task->job_id), ['job_priority' => 1])
            ->assertNotFound();
        $this->assertSame(3, (int) $task->fresh()->job_priority);
    }

    public function test_changing_dates_works_for_the_owner(): void
    {
        [$member, , $task] = $this->scenario();

        $this->actingAs($member)
            ->patchJson(route('tasks.schedule.update', $task->job_id), [
                'job_start_at' => '2030-01-10',
                'job_due_at' => '2030-01-11',
            ])
            ->assertOk()
            ->assertJsonPath('job_start_at', '2030-01-10')
            ->assertJsonPath('job_due_at', '2030-01-11');

        $this->assertSame('2030-01-10', $task->fresh()->job_start_at->format('Y-m-d'));
        $this->assertSame('2030-01-11', $task->fresh()->job_due_at->format('Y-m-d'));

        $this->actingAs($member)
            ->patchJson(route('tasks.schedule.update', $task->job_id), [
                'job_start_at' => '2030-01-12',
                'job_due_at' => '2030-01-11',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('job_due_at');

        $this->assertSame('2030-01-10', $task->fresh()->job_start_at->format('Y-m-d'));
    }

    public function test_collaborators_can_be_added_and_removed_from_the_workspace(): void
    {
        [$member, , $task] = $this->scenario();
        $mate = $this->member();

        $this->actingAs($member)
            ->postJson(route('tasks.collaborators.store', $task->job_id), ['collaborators' => [$mate->id]])
            ->assertOk();
        $this->assertTrue($task->fresh()->collaborators->contains('id', $mate->id));

        $this->actingAs($member)
            ->deleteJson(route('tasks.collaborators.destroy', [$task->job_id, $mate->id]))
            ->assertOk();
        $this->assertFalse($task->fresh()->collaborators->contains('id', $mate->id));
    }

    public function test_attachments_can_be_added_opened_and_deleted(): void
    {
        Storage::fake('local');
        [$member, , $task] = $this->scenario();

        $this->actingAs($member)
            ->post(route('tasks.attachments.store', $task->job_id), [
                'completion_attachments' => [UploadedFile::fake()->image('proof.png')],
            ], ['Accept' => 'application/json'])
            ->assertSuccessful();

        $attachment = $task->fresh()->images->firstOrFail();

        $this->actingAs($member)
            ->get(route('media.task-attachments.show', $attachment))
            ->assertOk();

        $this->actingAs($member)
            ->deleteJson(route('tasks.attachments.destroy', [$task->job_id, $attachment->id]))
            ->assertOk();

        $this->assertSame(0, $task->fresh()->images->count());
    }

    public function test_updates_and_activity_tabs_are_both_available_with_working_compose(): void
    {
        [$member, , $task] = $this->scenario();

        $this->actingAs($member)
            ->get(route('mytasks.index'))
            ->assertOk()
            ->assertSee('data-timeline-tab="updates"', false)
            ->assertSee('data-timeline-tab="activity"', false)
            ->assertSee('เขียนอัปเดต...', false);

        $this->actingAs($member)
            ->postJson(route('tasks.comments.store', $task->job_id), ['message' => 'ความคืบหน้าวันนี้'])
            ->assertSuccessful();

        $this->assertSame(1, $task->fresh()->updates()->count());
    }

    public function test_users_without_permission_cannot_edit_through_the_api(): void
    {
        [, , $task] = $this->scenario();
        $viewer = $this->member('viewer');
        $stranger = $this->member();

        foreach ([$viewer, $stranger] as $actor) {
            $this->actingAs($actor)
                ->patchJson(route('tasks.details.update', $task->job_id), ['job_topic' => 'พยายามแก้'])
                ->assertForbidden();

            $this->actingAs($actor)
                ->postJson(route('tasks.comments.store', $task->job_id), ['message' => 'พยายามคอมเมนต์'])
                ->assertForbidden();

            $this->actingAs($actor)
                ->postJson(route('tasks.collaborators.store', $task->job_id), ['collaborators' => [$actor->id]])
                ->assertForbidden();
        }

        $this->assertNotSame('พยายามแก้', $task->fresh()->job_topic);
    }

    public function test_table_board_and_calendar_all_open_the_shared_workspace(): void
    {
        [$member, , $task] = $this->scenario();

        $content = $this->actingAs($member)->get(route('mytasks.index', ['view' => 'table']))->assertOk()->getContent();

        // ตาราง บอร์ด และ Kanban ใช้ trigger เดียวกัน ส่วนปฏิทินเรียกผ่าน trigger ของแถวเดียวกันนี้
        $this->assertStringContainsString('data-open-task-modal', $content);
        $this->assertStringContainsString('data-board-task', $content);
        $this->assertStringContainsString('data-open-kanban-task="'.$task->job_id.'"', $content);
        $this->assertStringContainsString('data-calendar', $content);
        $this->assertSame(1, substr_count($content, 'data-task-modal'), 'Workspace ต้องถูก render ชุดเดียวต่อหนึ่งหน้า');
    }

    /**
     * @return array{0: User, 1: User, 2: WorkOrder}
     */
    private function scenario(): array
    {
        $member = $this->member();
        $admin = $this->member('admin');
        $project = WorkOrderList::create([
            'user_id' => $member->id,
            'name' => 'งานอบรม',
            'priority' => 2,
            'is_visible' => true,
            'sort_order' => 1,
        ]);

        $task = WorkOrder::create([
            'user_id' => $member->id,
            'created_by' => $member->id,
            'leader_user_id' => $member->id,
            'department_id' => $member->department_id,
            'work_order_list_id' => $project->id,
            'job_topic' => 'ทดสอบ 1',
            'job_priority' => 4,
            'job_status' => 2,
            'approval_status' => 'approved',
            'approved_by' => $member->id,
            'approved_at' => now(),
            'job_start_at' => now(),
            'job_due_at' => now()->addDay(),
        ]);

        return [$member, $admin, $task];
    }

    private function member(string $role = 'user'): User
    {
        return User::factory()->create([
            'role' => $role,
            'department_id' => $this->department->id,
            'is_active' => true,
            'must_change_password' => false,
        ]);
    }
}
