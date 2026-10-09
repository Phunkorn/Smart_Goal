<?php

namespace Tests\Feature;

use App\Models\Department;
use App\Models\SystemNotification;
use App\Models\User;
use App\Models\WorkOrder;
use App\Models\WorkOrderList;
use App\Services\MemberWorkloadQuery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ผู้ร่วมงานข้ามแผนกที่ถูกเพิ่มเข้า "งานย่อย" โดยตรง
 *
 * กติกา: การเข้าร่วมงานย่อยทำให้เห็นงานแม่เพื่อเป็นบริบท ไม่ได้ทำให้กลายเป็น
 * ผู้มีส่วนร่วมของงานแม่ (Direct participation in a subtask grants contextual
 * visibility to its ancestors, not participation in those ancestors)
 *
 * ของเดิมสิทธิ์การมองเห็นงานย่อยอิงจากการเป็นสมาชิกของงานแม่ก่อน ผู้ที่ถูกเพิ่มเข้า
 * งานย่อยตรง ๆ (ซึ่งเป็นผลลัพธ์ปกติของฟีเจอร์ "แชร์งาน" ที่แชร์ได้เฉพาะงานย่อย)
 * จึงเห็นแต่ชื่อโปรเจกต์ ไม่มีแถวงานให้เปิดเลยทั้งหน้า
 */
class SubtaskContextVisibilityTest extends TestCase
{
    use RefreshDatabase;

    private Department $it;

    private Department $marketing;

    /** เจ้าของงานทั้งหมด อยู่แผนก IT */
    private User $owner;

    /** ผู้ร่วมงานข้ามแผนก อยู่แผนก Marketing */
    private User $outsider;

    private WorkOrderList $project;

    private WorkOrder $parent;

    private WorkOrder $subtask;

    private WorkOrder $sibling;

    protected function setUp(): void
    {
        parent::setUp();

        $this->it = Department::create(['department_name' => 'IT']);
        $this->marketing = Department::create(['department_name' => 'Marketing']);
        $this->owner = $this->member($this->it);
        $this->outsider = $this->member($this->marketing);

        $this->project = WorkOrderList::create([
            'user_id' => $this->owner->id,
            'name' => 'อบรมและพัฒนา',
        ]);
        $this->parent = $this->task('สอนคนให้อบรม', ['work_order_list_id' => $this->project->id]);
        $this->subtask = $this->task('ทำให้ผู้อื่นเข้าใจ', [
            'work_order_list_id' => $this->project->id,
            'parent_job_id' => $this->parent->job_id,
        ]);
        $this->sibling = $this->task('งานย่อยภายในของ IT', [
            'work_order_list_id' => $this->project->id,
            'parent_job_id' => $this->parent->job_id,
        ]);
    }

    public function test_subtask_only_participant_sees_the_project_parent_and_own_subtask(): void
    {
        $this->joinSubtask();

        $this->actingAs($this->outsider)->get(route('mytasks.index', ['view' => 'board']))
            ->assertOk()
            ->assertSee($this->project->name)
            ->assertSee($this->parent->job_topic)
            ->assertSee($this->subtask->job_topic)
            // งานย่อยใบอื่นของงานแม่เดียวกันต้องไม่ติดมาด้วย
            ->assertDontSee($this->sibling->job_topic)
            // แถวจริงต้องอยู่ในหน้า ไม่ใช่แค่ชื่อที่โผล่ในสรุปประจำวัน
            ->assertSee('data-task-id="'.$this->parent->job_id.'"', false)
            ->assertSee('data-detail-id="'.$this->subtask->job_id.'"', false)
            ->assertSee('data-row data-id="'.$this->subtask->job_id.'"', false)
            // งานแม่ต้องถูกป้ายว่าเป็นบริบท ไม่ใช่งานของผู้ใช้
            ->assertSee('data-context-only="1"', false);
    }

    public function test_several_shared_subtasks_under_one_parent_render_once_without_siblings(): void
    {
        $second = $this->task('ทำสื่อการสอน', [
            'work_order_list_id' => $this->project->id,
            'parent_job_id' => $this->parent->job_id,
        ]);
        $this->joinSubtask();
        $second->collaborators()->attach($this->outsider->id, ['status' => 'accepted']);

        $response = $this->actingAs($this->outsider)->get(route('mytasks.index', ['view' => 'board']))
            ->assertOk()
            ->assertSee($this->subtask->job_topic)
            ->assertSee($second->job_topic)
            ->assertDontSee($this->sibling->job_topic);

        $this->assertSame(
            1,
            substr_count($response->getContent(), 'data-context-only="1"'),
            'งานแม่ต้องถูกวาดเป็นแถวเดียว ไม่ซ้ำตามจำนวนงานย่อยที่ร่วม'
        );
    }

    public function test_context_only_parent_is_read_only_everywhere(): void
    {
        $this->joinSubtask();
        $outsider = $this->outsider->fresh();

        // อ่านได้
        $this->assertTrue($outsider->can('view', $this->parent));
        $this->assertTrue($outsider->can('viewComments', $this->parent));
        $this->assertTrue($outsider->can('viewAsContext', $this->parent));

        // แต่ไม่ได้เป็นผู้มีส่วนร่วม และทำอะไรกับงานแม่ไม่ได้เลย
        foreach ([
            'participate', 'work', 'update', 'comment', 'manageTeam', 'manageSubtasks',
            'share', 'submitForReview', 'review', 'reopen', 'delete', 'deleteOwn',
        ] as $ability) {
            $this->assertFalse(
                $outsider->can($ability, $this->parent),
                "context-only parent ต้องไม่ให้สิทธิ์ {$ability}"
            );
        }

        // ส่วนงานย่อยของตัวเองยังทำงานได้ตามปกติ และไม่ใช่ context-only
        $this->assertTrue($outsider->can('view', $this->subtask));
        $this->assertTrue($outsider->can('work', $this->subtask));
        $this->assertTrue($outsider->can('participate', $this->subtask));
        $this->assertFalse($outsider->can('viewAsContext', $this->subtask));

        // งานย่อยใบอื่นยังเข้าไม่ได้
        $this->assertFalse($outsider->can('view', $this->sibling));
    }

    public function test_context_only_parent_rejects_every_mutation_endpoint(): void
    {
        $this->joinSubtask();

        $this->actingAs($this->outsider)
            ->patchJson(route('tasks.updateStatus', $this->parent), ['job_status' => 5])
            ->assertForbidden();

        $this->actingAs($this->outsider)
            ->postJson(route('tasks.comments.store', $this->parent), ['message' => 'ขอแก้งานแม่'])
            ->assertForbidden();

        $this->actingAs($this->outsider)
            ->postJson(route('tasks.collaborators.store', $this->parent), [
                'collaborators' => [$this->member($this->marketing)->id],
            ])
            ->assertForbidden();

        $this->actingAs($this->outsider)
            ->postJson(route('mytasks.details.store', $this->parent), ['title' => 'งานย่อยที่ไม่ควรเพิ่มได้'])
            ->assertForbidden();

        $this->assertSame(2, (int) $this->parent->fresh()->job_status);
        // ห้าม auto-add ผู้ใช้เข้างานแม่ไม่ว่ากรณีใด
        $this->assertDatabaseMissing('work_order_collaborators', [
            'work_order_id' => $this->parent->job_id,
            'user_id' => $this->outsider->id,
        ]);
    }

    public function test_direct_url_to_the_subtask_lands_on_a_page_that_can_open_it(): void
    {
        $this->joinSubtask();

        $this->actingAs($this->outsider)->get(route('tasks.show', $this->subtask))
            ->assertRedirect(route('mytasks.index', ['open_task' => $this->subtask->job_id]));

        $this->actingAs($this->outsider)
            ->get(route('mytasks.index', ['open_task' => $this->subtask->job_id, 'view' => 'board']))
            ->assertOk()
            ->assertSee('data-row data-id="'.$this->subtask->job_id.'"', false);
    }

    public function test_notification_deep_link_reaches_the_shared_subtask(): void
    {
        $this->actingAs($this->owner)
            ->postJson(route('tasks.collaborators.store', $this->subtask), [
                'collaborators' => [$this->outsider->id],
            ])
            ->assertOk();

        $notification = SystemNotification::query()
            ->where('user_id', $this->outsider->id)
            ->where('type', 'collaborator_added')
            ->latest('id')
            ->firstOrFail();

        $response = $this->actingAs($this->outsider)
            ->get(route('notifications.open', $notification))
            ->assertRedirect();

        $this->assertStringContainsString(
            'open_task='.$this->subtask->job_id,
            $response->headers->get('Location'),
            'ลิงก์แจ้งเตือนต้องพาไปยังงานย่อยใบที่เพิ่งถูกเพิ่มเข้า'
        );

        $this->actingAs($this->outsider)->get($response->headers->get('Location'))
            ->assertOk()
            ->assertSee('data-row data-id="'.$this->subtask->job_id.'"', false);
    }

    public function test_removing_the_subtask_participation_removes_the_contextual_visibility(): void
    {
        $this->joinSubtask();

        $this->actingAs($this->owner)
            ->deleteJson(route('tasks.collaborators.destroy', [$this->subtask, $this->outsider]))
            ->assertOk();

        $outsider = $this->outsider->fresh();
        $this->assertFalse($outsider->can('view', $this->subtask));
        $this->assertFalse($outsider->can('view', $this->parent));
        $this->assertFalse($outsider->can('viewAsContext', $this->parent));

        $this->actingAs($outsider)->get(route('mytasks.index', ['view' => 'board']))
            ->assertOk()
            ->assertDontSee('data-task-id="'.$this->parent->job_id.'"', false)
            ->assertDontSee('data-detail-id="'.$this->subtask->job_id.'"', false);
    }

    public function test_a_shared_subtask_outside_any_project_is_still_reachable(): void
    {
        $loneParent = $this->task('งานที่ไม่ได้อยู่ในโปรเจกต์');
        $loneSubtask = $this->task('งานย่อยของงานที่ไม่มีโปรเจกต์', [
            'parent_job_id' => $loneParent->job_id,
        ]);
        $loneSubtask->collaborators()->attach($this->outsider->id, ['status' => 'accepted']);

        $this->actingAs($this->outsider)->get(route('mytasks.index', ['view' => 'board']))
            ->assertOk()
            ->assertSee('data-task-id="'.$loneParent->job_id.'"', false)
            ->assertSee('data-detail-id="'.$loneSubtask->job_id.'"', false);
    }

    public function test_member_workspace_keeps_the_context_parent_for_admin_and_home_head(): void
    {
        $this->joinSubtask();
        $admin = $this->admin();
        $head = $this->member($this->marketing, ['is_department_head' => true]);

        foreach ([
            [$admin, route('admin.work-board.member', [$this->marketing, $this->outsider, 'workspace' => 1, 'view' => 'board'])],
            [$head, route('work-board.member', [$this->marketing, $this->outsider, 'workspace' => 1, 'view' => 'board'])],
        ] as [$viewer, $url]) {
            $this->actingAs($viewer)->get($url)
                ->assertOk()
                ->assertSee('data-task-id="'.$this->parent->job_id.'"', false)
                ->assertSee('data-detail-id="'.$this->subtask->job_id.'"', false)
                ->assertDontSee($this->sibling->job_topic);
        }
    }

    public function test_contextual_ancestors_are_never_counted_as_the_members_work(): void
    {
        $this->joinSubtask();

        // นิยาม "ผลงาน" ของรายงานทุกหน้าต้องไม่ขยับ — เห็นงานแม่ไม่เท่ากับทำงานแม่
        $this->assertSame(
            [$this->subtask->job_id],
            WorkOrder::query()->contributedBy($this->outsider->id)->pluck('job_id')->all()
        );
        $this->assertSame(
            [$this->subtask->job_id],
            WorkOrder::query()->joinedByAny([$this->outsider->id])->pluck('job_id')->all()
        );
        $this->assertSame([], WorkOrder::query()->assignedToAny([$this->outsider->id])->pluck('job_id')->all());
        $this->assertSame(
            [$this->subtask->job_id],
            app(MemberWorkloadQuery::class)->forMember($this->outsider)->pluck('job_id')->all()
        );

        /*
         * ตัวเลขภาระงานของ Member Workspace นับเฉพาะงานระดับบนสุดที่เป็นภาระของสมาชิกเอง
         * สมาชิกคนนี้มีแต่งานย่อยหนึ่งใบ ตัวเลขจึงเป็น 0 ทั้งก่อนและหลังการแก้
         * งานแม่ที่โผล่มาเป็นบริบทต้องไม่ถูกบวกเข้าไปให้กลายเป็น 1
         */
        $this->actingAs($this->admin())
            ->get(route('admin.work-board.member', [$this->marketing, $this->outsider, 'workspace' => 1, 'view' => 'board']))
            ->assertOk()
            ->assertViewHas('totals', fn (array $totals) => $totals['tasks'] === 0);
    }

    public function test_the_scope_filter_count_ignores_contextual_ancestors(): void
    {
        $this->joinSubtask();

        // ผู้ใช้มีงานของตัวเองใบเดียวคืองานย่อย งานแม่ที่โผล่มาเป็นบริบทต้องไม่ถูกนับ
        $this->actingAs($this->outsider)
            ->get(route('mytasks.index', ['view' => 'board']))
            ->assertOk()
            ->assertViewHas('taskScopeCount', 0);
    }

    /**
     * กติกาของคนในแผนกเดียวกันต้องไม่เปลี่ยน
     *
     * สิทธิ์ระดับโปรเจกต์ของคนแผนกเดียวกับงานเป็นกติกาที่ตั้งใจไว้ (ดู
     * WorkOrder::scopeVisibleInProjectsFor) เขาจึงยังเห็นงานย่อยทุกใบของโปรเจกต์นั้น
     */
    public function test_same_department_project_visibility_is_unchanged(): void
    {
        $colleague = $this->member($this->it);
        $this->subtask->collaborators()->attach($colleague->id, ['status' => 'accepted']);

        $this->actingAs($colleague)->get(route('mytasks.index', ['view' => 'board']))
            ->assertOk()
            ->assertSee($this->parent->job_topic)
            ->assertSee($this->subtask->job_topic)
            ->assertSee($this->sibling->job_topic);

        $colleague = $colleague->fresh();
        $this->assertTrue($colleague->can('view', $this->parent));
        $this->assertTrue($colleague->can('view', $this->sibling));
        // คนในแผนกเดียวกันเข้าถึงงานแม่ด้วยสิทธิ์ระดับโปรเจกต์ ไม่ใช่สิทธิ์แบบบริบท
        $this->assertFalse($colleague->can('viewAsContext', $this->parent));
    }

    private function joinSubtask(): void
    {
        $this->subtask->collaborators()->attach($this->outsider->id, ['status' => 'accepted']);
    }

    private function admin(): User
    {
        return User::factory()->create([
            'role' => 'admin',
            'department_id' => null,
            'must_change_password' => false,
            'is_active' => true,
        ]);
    }

    private function member(Department $department, array $attributes = []): User
    {
        return User::factory()->create(array_merge([
            'role' => 'user',
            'department_id' => $department->id,
            'must_change_password' => false,
            'is_active' => true,
        ], $attributes));
    }

    private function task(string $topic, array $attributes = []): WorkOrder
    {
        return WorkOrder::create(array_merge([
            'user_id' => $this->owner->id,
            'created_by' => $this->owner->id,
            'leader_user_id' => $this->owner->id,
            'department_id' => $this->it->id,
            'job_topic' => $topic,
            'job_priority' => 2,
            'job_status' => 2,
            'approval_status' => 'approved',
            'job_start_at' => now()->subDay(),
            'job_due_at' => now()->addDays(2),
        ], $attributes));
    }
}
