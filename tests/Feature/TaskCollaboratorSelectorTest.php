<?php

namespace Tests\Feature;

use App\Models\Department;
use App\Models\User;
use App\Models\WorkOrder;
use App\Models\WorkOrderList;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ตัวเลือกผู้ร่วมงานถูกเปลี่ยนจาก <select multiple> มาใช้ component กลางชุดเดียวกับ
 * ตัวเลือกผู้เข้าร่วมประชุม โดยกติกาฝั่ง server ต้องไม่เปลี่ยน
 */
class TaskCollaboratorSelectorTest extends TestCase
{
    use RefreshDatabase;

    /** โครงสร้างที่ทั้งหน้า User และหน้า Admin ต้อง render เหมือนกัน */
    private const SHARED_MARKUP = [
        'data-people-selector',
        'data-people-search',
        'data-people-department',
        'data-people-options',
        'data-people-checkbox',
        'data-people-chips',
        'data-people-count',
        'name="collaborators[]"',
        'data-team-submit',
    ];

    private Department $department;

    protected function setUp(): void
    {
        parent::setUp();
        $this->department = Department::create(['department_name' => 'Operations']);
    }

    public function test_user_and_admin_render_the_same_people_selector(): void
    {
        [$member, $admin] = $this->scenario();
        $this->member('Selectable Mate');

        $pages = [
            [$member, route('mytasks.index')],
            [$admin, route('admin.work-board.member', [$this->department, $member])],
        ];

        foreach ($pages as [$actor, $url]) {
            $response = $this->actingAs($actor)->get($url)->assertOk();

            foreach (self::SHARED_MARKUP as $marker) {
                $response->assertSee($marker, false);
            }

            // ตัวเลือกเดิมต้องไม่เหลืออยู่ที่ไหนอีก
            $this->assertSame(
                0,
                preg_match('/<select[^>]*name="collaborators\[\]"[^>]*multiple/i', $response->getContent()),
                'ต้องไม่เหลือ select multiple ของผู้ร่วมงาน'
            );
        }
    }

    public function test_team_manager_is_one_component_without_the_old_duplicated_panels(): void
    {
        [$member] = $this->scenario();
        $this->member('Selectable Mate');

        $content = $this->actingAs($member)->get(route('mytasks.index'))->assertOk()->getContent();

        // ทีมปัจจุบันกับรายการที่เตรียมเพิ่มต้องมีตัวนับคนละตัวและอ่านออกว่าคนละชุด
        $this->assertStringContainsString('data-team-current', $content);
        $this->assertStringContainsString('ทีมปัจจุบัน 0 คน', $content);
        $this->assertStringContainsString('data-count-template="เลือกเพิ่ม :count คน"', $content);
        $this->assertStringNotContainsString('เลือกแล้ว 0 คน', $content, 'ห้ามใช้ข้อความรวมที่ขัดกับจำนวนทีมปัจจุบัน');

        // UI เก่าต้องถูกลบจริง ไม่ใช่ซ่อนไว้
        foreach (['team-members-panel', 'team-section-heading', 'class="team-invite"', 'name="collaborators[]" multiple'] as $dead) {
            $this->assertStringNotContainsString($dead, $content, $dead.' ต้องไม่เหลืออยู่ในผลลัพธ์');
        }

        // ปุ่มหลักอยู่ใน flow ปกติของ footer และเริ่มต้นกดไม่ได้
        $this->assertMatchesRegularExpression('/<button type="submit"[^>]*data-team-submit[^>]*disabled/', $content);
        $this->assertStringContainsString('เลือกผู้ร่วมงานก่อน', $content);
    }

    public function test_overlay_uses_theme_scope_not_page_layout_class(): void
    {
        [$member] = $this->scenario();

        $this->actingAs($member)
            ->get(route('mytasks.index'))
            ->assertOk()
            // page-layout class พา width/margin ของหน้าไปบีบ backdrop จนคลุมไม่เต็มจอ
            ->assertSee('class="task-workspace-modal notion-modal sg-task-theme"', false)
            ->assertDontSee('task-workspace-modal notion-modal my-tasks-page', false);
    }

    public function test_selector_lists_only_active_users_and_never_the_viewer(): void
    {
        [$member] = $this->scenario();
        $active = $this->member('Active Mate');
        $inactive = $this->member('Inactive Mate', active: false);

        $content = $this->actingAs($member)->get(route('mytasks.index'))->assertOk()->getContent();

        $this->assertMatchesRegularExpression('/value="'.$active->id.'"[^>]*data-people-checkbox/', $content);
        $this->assertDoesNotMatchRegularExpression('/value="'.$inactive->id.'"[^>]*data-people-checkbox/', $content);
        $this->assertDoesNotMatchRegularExpression('/value="'.$member->id.'"[^>]*data-people-checkbox/', $content);
    }

    public function test_adding_many_collaborators_keeps_department_based_status(): void
    {
        [$member, , $task] = $this->scenario();
        $sameDepartment = $this->member('Same Dept');
        $otherDepartment = $this->member('Other Dept', department: Department::create(['department_name' => 'Finance']));

        $this->actingAs($member)
            ->postJson(route('tasks.collaborators.store', $task->job_id), [
                'collaborators' => [$sameDepartment->id, $otherDepartment->id],
            ])
            ->assertOk();

        $collaborators = $task->fresh()->collaborators->keyBy('id');
        $this->assertSame('accepted', $collaborators[$sameDepartment->id]->pivot->status);
        $this->assertSame('pending', $collaborators[$otherDepartment->id]->pivot->status);
    }

    public function test_inactive_user_cannot_be_added_even_through_the_api(): void
    {
        [$member, , $task] = $this->scenario();
        $inactive = $this->member('Disabled Mate', active: false);

        $this->actingAs($member)
            ->postJson(route('tasks.collaborators.store', $task->job_id), ['collaborators' => [$inactive->id]])
            ->assertOk();

        $this->assertFalse($task->fresh()->collaborators->contains('id', $inactive->id));
    }

    public function test_owner_leader_and_existing_collaborators_cannot_be_added_again(): void
    {
        [$member, , $task] = $this->scenario();
        $existing = $this->member('Already In Team');
        $task->collaborators()->attach($existing->id, ['added_by' => $member->id, 'status' => 'pending']);

        $this->actingAs($member)
            ->postJson(route('tasks.collaborators.store', $task->job_id), [
                'collaborators' => [$member->id, $existing->id],
            ])
            ->assertOk();

        $fresh = $task->fresh();
        $this->assertSame(1, $fresh->collaborators->count());
        $this->assertSame('pending', $fresh->collaborators->firstWhere('id', $existing->id)->pivot->status);
    }

    public function test_owner_creator_and_leader_cannot_be_removed(): void
    {
        [$member, , $task] = $this->scenario();

        $this->actingAs($member)
            ->deleteJson(route('tasks.collaborators.destroy', [$task->job_id, $member->id]))
            ->assertStatus(422);
    }

    public function test_users_without_manage_team_cannot_add_or_remove(): void
    {
        [$member, , $task] = $this->scenario();
        $stranger = $this->member('Stranger');
        $mate = $this->member('Mate');
        $task->collaborators()->attach($mate->id, ['added_by' => $member->id, 'status' => 'accepted']);

        foreach ([$stranger, $this->viewer()] as $actor) {
            $this->actingAs($actor)
                ->postJson(route('tasks.collaborators.store', $task->job_id), ['collaborators' => [$stranger->id]])
                ->assertForbidden();

            $this->actingAs($actor)
                ->deleteJson(route('tasks.collaborators.destroy', [$task->job_id, $mate->id]))
                ->assertForbidden();
        }

        $this->assertTrue($task->fresh()->collaborators->contains('id', $mate->id));
    }

    public function test_completed_task_is_locked_for_members_but_admin_can_still_manage(): void
    {
        [$member, $admin, $task] = $this->scenario();
        $mate = $this->member('Mate');
        $task->forceFill(['job_status' => 4])->save();

        // เจ้าของงานที่ปิดแล้วจัดการทีมไม่ได้ตามกติกาเดิม
        $this->actingAs($member)
            ->postJson(route('tasks.collaborators.store', $task->job_id), ['collaborators' => [$mate->id]])
            ->assertForbidden();

        // Admin จัดการได้ ตรงกับที่ controller และธง locked ใน Blade สื่อไว้
        $this->actingAs($admin)
            ->postJson(route('tasks.collaborators.store', $task->job_id), ['collaborators' => [$mate->id]])
            ->assertOk();

        $this->assertTrue($task->fresh()->collaborators->contains('id', $mate->id));

        $this->actingAs($admin)
            ->deleteJson(route('tasks.collaborators.destroy', [$task->job_id, $mate->id]))
            ->assertOk();
        $this->assertFalse($task->fresh()->collaborators->contains('id', $mate->id));
    }

    public function test_viewer_never_manages_a_team_even_when_the_task_is_open(): void
    {
        [, , $task] = $this->scenario();

        $this->actingAs($this->viewer())
            ->postJson(route('tasks.collaborators.store', $task->job_id), ['collaborators' => [$this->member('X')->id]])
            ->assertForbidden();
    }

    /**
     * @return array{0: User, 1: User, 2: WorkOrder}
     */
    private function scenario(): array
    {
        $member = $this->member('Owner');
        $admin = $this->member('Admin', role: 'admin');
        $project = WorkOrderList::create([
            'user_id' => $member->id,
            'name' => 'โปรเจกต์ทดสอบ',
            'priority' => 2,
            'is_visible' => true,
            'sort_order' => 1,
        ]);

        $task = WorkOrder::create([
            'user_id' => $member->id,
            'created_by' => $member->id,
            'leader_user_id' => $member->id,
            'department_id' => $this->department->id,
            'work_order_list_id' => $project->id,
            'job_topic' => 'งานทดสอบผู้ร่วมงาน',
            'job_priority' => 2,
            'job_status' => 2,
            'approval_status' => 'approved',
            'approved_by' => $member->id,
            'approved_at' => now(),
            'job_start_at' => now(),
            'job_due_at' => now()->addDay(),
        ]);

        return [$member, $admin, $task];
    }

    private function member(string $name, string $role = 'user', bool $active = true, ?Department $department = null): User
    {
        return User::factory()->create([
            'name' => $name,
            'role' => $role,
            'department_id' => ($department ?? $this->department)->id,
            'is_active' => $active,
            'must_change_password' => false,
        ]);
    }

    private function viewer(): User
    {
        return $this->member('Viewer '.uniqid(), role: 'viewer');
    }
}
