<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\Department;
use App\Models\SystemNotification;
use App\Models\User;
use App\Models\WorkOrder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Tests\TestCase;

class AssignmentApprovalFlowTest extends TestCase
{
    use RefreshDatabase;

    public function test_same_department_assignment_notifications_are_consistent_across_entry_points(): void
    {
        $department = Department::create(['department_name' => 'IT']);
        $actor = $this->user($department);
        $assignee = $this->user($department);
        $head = $this->departmentHead($department);
        $admin = $this->admin();

        $this->actingAs($actor)
            ->post(route('tasks.store'), $this->payload($assignee, 'Task endpoint assignment'))
            ->assertRedirect(route('mytasks.index'));

        $taskEndpointJob = WorkOrder::where('job_topic', 'Task endpoint assignment')->firstOrFail();
        $this->assertSame('approved', $taskEndpointJob->approval_status);
        $this->assertNotificationCount($assignee, $taskEndpointJob, 'task_assigned', 1);
        // ฉบับสรุปงานภายในแผนกเป็นของหัวหน้าแผนก ไม่ใช่ของ admin
        // (NotificationService::notifyTaskOverseers)
        $this->assertNotificationCount($head, $taskEndpointJob, 'same_department_assignment', 1);
        $this->assertNotificationCount($admin, $taskEndpointJob, 'same_department_assignment', 0);

        $this->actingAs($actor)
            ->postJson(route('mytasks.create'), $this->payload($assignee, 'My Tasks endpoint assignment'))
            ->assertCreated()
            ->assertJsonPath('requires_admin_review', false);

        $myTasksJob = WorkOrder::where('job_topic', 'My Tasks endpoint assignment')->firstOrFail();
        $this->assertSame('approved', $myTasksJob->approval_status);
        $this->assertNotificationCount($assignee, $myTasksJob, 'task_assigned', 1);
        $this->assertNotificationCount($head, $myTasksJob, 'same_department_assignment', 1);
        $this->assertNotificationCount($admin, $myTasksJob, 'same_department_assignment', 0);
    }

    public function test_self_assignment_does_not_notify_self_but_still_notifies_the_department_head(): void
    {
        $department = Department::create(['department_name' => 'IT']);
        $actor = $this->user($department);
        $head = $this->departmentHead($department);
        $admin = $this->admin();

        $this->actingAs($actor)
            ->post(route('tasks.store'), $this->payload($actor, 'Self assignment'))
            ->assertRedirect(route('mytasks.index'));

        $job = WorkOrder::where('job_topic', 'Self assignment')->firstOrFail();
        $this->assertNotificationCount($actor, $job, 'task_assigned', 0);
        $this->assertNotificationCount($head, $job, 'same_department_assignment', 1);
        // admin เป็นผู้ดูแลระบบ ไม่ใช่ผู้ติดตามภาระงานของแผนก
        $this->assertNotificationCount($admin, $job, 'same_department_assignment', 0);
    }

    public function test_pending_cross_department_assignment_is_hidden_and_immutable_for_assignee(): void
    {
        $it = Department::create(['department_name' => 'IT']);
        $marketing = Department::create(['department_name' => 'Marketing']);
        $actor = $this->user($it);
        $assignee = $this->user($marketing);
        $admin = $this->admin();
        $candidate = $this->user($marketing);

        $this->actingAs($actor)
            ->post(route('tasks.store'), $this->payload($assignee, 'Private pending assignment'))
            ->assertRedirect(route('mytasks.index'));

        $job = WorkOrder::where('job_topic', 'Private pending assignment')->firstOrFail();
        $this->assertSame('pending', $job->approval_status);
        $this->assertNotificationCount($admin, $job, 'cross_department_pending', 1);
        $this->assertSame(0, SystemNotification::where('user_id', $assignee->id)->where('work_order_id', $job->job_id)->count());

        foreach (['table', 'board', 'calendar'] as $view) {
            $this->actingAs($assignee)->get(route('mytasks.index', ['view' => $view]))
                ->assertOk()
                ->assertDontSee('Private pending assignment');
        }

        $this->actingAs($assignee)->get(route('work-board.index'))
            ->assertOk()
            ->assertDontSee('Private pending assignment');
        $this->actingAs($assignee)->get(route('tasks.show', $job))->assertForbidden();

        $this->assertFalse(Gate::forUser($assignee)->allows('update', $job));
        $this->assertFalse(Gate::forUser($assignee)->allows('comment', $job));
        $this->assertFalse(Gate::forUser($assignee)->allows('manageTeam', $job));
        $this->assertFalse(Gate::forUser($assignee)->allows('deleteOwn', $job));

        $this->actingAs($assignee)->patchJson(route('tasks.details.update', $job), [
            'job_topic' => 'Leaked edit',
        ])->assertForbidden();
        $this->actingAs($assignee)->patchJson(route('tasks.schedule.update', $job), [
            'job_start_at' => now()->format('Y-m-d'),
            'job_due_at' => now()->addDay()->format('Y-m-d'),
        ])->assertForbidden();
        $this->actingAs($assignee)->patchJson(route('tasks.updateStatus', $job), ['job_status' => 2])->assertForbidden();
        $this->actingAs($assignee)->postJson(route('tasks.attachments.store', $job), [])->assertForbidden();
        $this->actingAs($assignee)->postJson(route('tasks.collaborators.store', $job), [
            'collaborators' => [$candidate->id],
        ])->assertForbidden();
        $this->actingAs($assignee)->postJson(route('tasks.comments.store', $job), ['message' => 'Bypass'])->assertForbidden();
        $this->actingAs($assignee)->postJson(route('tasks.deleteRequest.store', $job), ['reason' => 'Bypass'])->assertForbidden();

        $this->actingAs($assignee)->postJson(route('mytasks.updatePriority', $job), ['job_priority' => 3])->assertNotFound();
        $this->actingAs($assignee)->postJson(route('mytasks.updateDueDate', $job), ['job_due_at' => now()->addDays(2)->format('Y-m-d')])->assertNotFound();
        $this->actingAs($assignee)->deleteJson(route('mytasks.destroy', $job))->assertForbidden();

        $this->assertSame(0, WorkOrder::query()->contributedBy($assignee->id)->whereKey($job->job_id)->count());
        $this->assertSame('Private pending assignment', $job->fresh()->job_topic);
    }

    public function test_admin_queue_and_approval_are_authorized_idempotent_and_notify_once(): void
    {
        [$actor, $assignee, $admin, $job] = $this->crossDepartmentAssignment('Approve once');

        $this->actingAs($admin)->get(route('admin.approvals.index'))
            ->assertOk()
            ->assertSee('งานข้ามแผนก')
            ->assertSee('Approve once')
            ->assertSee($actor->department->department_name)
            ->assertSee($assignee->department->department_name)
            ->assertSee(route('admin.tasks.approval', $job), false)
            ->assertSee('data-assignment-approval-form', false);

        $this->actingAs($actor)
            ->patchJson(route('admin.tasks.approval', $job), ['approval_status' => 'approved'])
            ->assertForbidden();

        $this->actingAs($admin)
            ->patchJson(route('admin.tasks.approval', $job), ['approval_status' => 'approved'])
            ->assertOk();

        $job->refresh();
        $this->assertSame('approved', $job->approval_status);
        $this->assertSame($admin->id, $job->approved_by);
        $this->assertNotNull($job->approved_at);
        $this->assertNotificationCount($assignee, $job, 'task_assigned', 1);
        $this->assertNotificationCount($actor, $job, 'assignment_approved', 1);
        $this->assertSame(1, ActivityLog::where('subject_id', $job->job_id)->where('action', 'approval_updated')->count());

        $this->actingAs($assignee)->get(route('mytasks.index', ['view' => 'table']))
            ->assertOk()
            ->assertSee('Approve once');

        $notification = SystemNotification::where('user_id', $assignee->id)
            ->where('work_order_id', $job->job_id)
            ->where('type', 'task_assigned')
            ->firstOrFail();
        $this->actingAs($assignee)->get(route('notifications.open', $notification))
            ->assertRedirect(route('mytasks.index', ['open_task' => $job->job_id]));

        $this->actingAs($admin)
            ->patchJson(route('admin.tasks.approval', $job), ['approval_status' => 'approved'])
            ->assertConflict();
        $this->actingAs($admin)
            ->patchJson(route('admin.tasks.approval', $job), ['approval_status' => 'rejected'])
            ->assertConflict();
        $this->assertNotificationCount($assignee, $job, 'task_assigned', 1);
        $this->assertNotificationCount($actor, $job, 'assignment_approved', 1);
        $this->assertSame(1, ActivityLog::where('subject_id', $job->job_id)->where('action', 'approval_updated')->count());
    }

    public function test_rejection_keeps_task_private_and_duplicate_decisions_are_safe(): void
    {
        [$actor, $assignee, $admin, $job] = $this->crossDepartmentAssignment('Reject once');

        $this->actingAs($admin)
            ->from(route('board.index'))
            ->patch(route('admin.tasks.approval', $job), ['approval_status' => 'rejected'])
            ->assertRedirect(route('board.index'))
            ->assertSessionHas('success');

        $this->assertSame('rejected', $job->fresh()->approval_status);
        $this->assertNotificationCount($actor, $job, 'assignment_rejected', 1);
        $this->assertSame(1, ActivityLog::where('subject_id', $job->job_id)->where('action', 'approval_updated')->count());
        $this->assertNotificationCount($assignee, $job, 'task_assigned', 0);
        $this->actingAs($assignee)->get(route('mytasks.index'))->assertOk()->assertDontSee('Reject once');
        $this->actingAs($assignee)->get(route('tasks.show', $job))->assertForbidden();

        $this->actingAs($admin)
            ->from(route('board.index'))
            ->patch(route('admin.tasks.approval', $job), ['approval_status' => 'rejected'])
            ->assertRedirect(route('board.index'))
            ->assertSessionHasErrors('status');
        $this->assertNotificationCount($actor, $job, 'assignment_rejected', 1);
        $this->assertSame(1, ActivityLog::where('subject_id', $job->job_id)->where('action', 'approval_updated')->count());
    }

    public function test_admin_cross_department_assignment_is_immediate_and_notifies_recipient(): void
    {
        $it = Department::create(['department_name' => 'IT']);
        $marketing = Department::create(['department_name' => 'Marketing']);
        $admin = $this->admin($it);
        $assignee = $this->user($marketing);

        $this->actingAs($admin)
            ->post(route('tasks.store'), $this->payload($assignee, 'Admin immediate assignment'))
            ->assertRedirect(route('board.index'));

        $job = WorkOrder::where('job_topic', 'Admin immediate assignment')->firstOrFail();
        $this->assertSame('approved', $job->approval_status);
        $this->assertSame($admin->id, $job->approved_by);
        $this->assertNotificationCount($assignee, $job, 'admin_created_task', 1);
        $this->assertSame(0, SystemNotification::where('work_order_id', $job->job_id)->where('type', 'cross_department_pending')->count());
        $this->actingAs($assignee)->get(route('mytasks.index'))->assertOk()->assertSee('Admin immediate assignment');
    }

    public function test_department_head_accepts_cross_department_assignment_immediately(): void
    {
        $it = Department::create(['department_name' => 'IT']);
        $marketing = Department::create(['department_name' => 'Marketing']);
        $actor = $this->user($marketing);
        $head = $this->user($it);
        $head->update(['is_department_head' => true]);

        $this->actingAs($actor)
            ->postJson(route('mytasks.create'), $this->payload($head, 'Head joins immediately'))
            ->assertCreated()
            ->assertJsonPath('requires_admin_review', false);

        $job = WorkOrder::where('job_topic', 'Head joins immediately')->firstOrFail();
        $this->assertSame('approved', $job->approval_status);
        $this->assertSame($actor->id, $job->approved_by);
        $this->assertSame($head->id, $job->leader_user_id);
        $this->assertNotificationCount($head, $job, 'task_assigned', 1);
        $this->assertSame(0, SystemNotification::where('work_order_id', $job->job_id)
            ->where('type', 'cross_department_pending')->count());
    }

    /**
     * สองช่องทางสร้างงานต้องใช้กติกาผู้เข้าร่วมชุดเดียวกัน
     *
     * ผู้เข้าร่วมทุกคนเข้าร่วมทันทีไม่ว่าแผนกไหน และ admin ไม่เกี่ยวข้องกับเส้นทางนี้เลย
     */
    public function test_task_creation_entry_points_share_the_same_participant_rules(): void
    {
        $it = Department::create(['department_name' => 'IT']);
        $marketing = Department::create(['department_name' => 'Marketing']);
        $actor = $this->user($it);
        $sameDepartment = $this->user($it);
        $crossDepartment = $this->user($marketing);
        $marketingHead = $this->user($marketing);
        $marketingHead->update(['is_department_head' => true]);
        $admin = $this->admin();

        $this->actingAs($actor)
            ->post(route('tasks.store'), $this->payload($actor, 'Task route collaborators') + [
                'collaborators' => [$sameDepartment->id, $crossDepartment->id],
            ])
            ->assertRedirect();

        $taskRouteJob = WorkOrder::where('job_topic', 'Task route collaborators')->firstOrFail();
        $this->assertSame('accepted', $taskRouteJob->collaborators()->findOrFail($sameDepartment->id)->pivot->status);
        $this->assertSame('accepted', $taskRouteJob->collaborators()->findOrFail($crossDepartment->id)->pivot->status);
        $this->assertNotificationCount($sameDepartment, $taskRouteJob, 'collaborator_added', 1);
        $this->assertNotificationCount($crossDepartment, $taskRouteJob, 'collaborator_added', 1);
        $this->assertNotificationCount($admin, $taskRouteJob, 'collaborator_approval_request', 0);
        $this->assertNotificationCount($marketingHead, $taskRouteJob, 'cross_department_participation', 1);

        $this->actingAs($actor)
            ->postJson(route('mytasks.create'), $this->payload($actor, 'My Tasks route collaborators') + [
                'project_name' => 'Shared collaborator rules',
                'collaborators' => [$sameDepartment->id, $crossDepartment->id],
            ])
            ->assertCreated();

        $myTasksJob = WorkOrder::where('job_topic', 'My Tasks route collaborators')->firstOrFail();
        $this->assertSame('accepted', $myTasksJob->collaborators()->findOrFail($sameDepartment->id)->pivot->status);
        $this->assertSame('accepted', $myTasksJob->collaborators()->findOrFail($crossDepartment->id)->pivot->status);
        $this->assertNotificationCount($sameDepartment, $myTasksJob, 'collaborator_added', 1);
        $this->assertNotificationCount($crossDepartment, $myTasksJob, 'collaborator_added', 1);
        $this->assertNotificationCount($admin, $myTasksJob, 'collaborator_approval_request', 0);
        $this->assertNotificationCount($marketingHead, $myTasksJob, 'cross_department_participation', 1);
    }

    /**
     * งานที่ตัวมันเองยังรออนุมัติการมอบหมาย ยังไม่มีโปรเจกต์ให้เข้าร่วมจริง
     *
     * สมาชิกที่ใส่ไว้ตอนสร้างจึงค้างเป็น pending — เป็นผลของการอนุมัติ "ตัวงาน"
     * ไม่ใช่การขออนุมัติตัวคน เมื่อการมอบหมายผ่าน ทุกคนเข้าร่วมพร้อมกันทั้งหมด
     */
    public function test_pending_assignment_holds_participants_until_the_assignment_itself_is_approved(): void
    {
        $it = Department::create(['department_name' => 'IT']);
        $marketing = Department::create(['department_name' => 'Marketing']);
        $finance = Department::create(['department_name' => 'Finance']);
        $actor = $this->user($it);
        $assignee = $this->user($marketing);
        $sameAsTask = $this->user($marketing);
        $crossDepartment = $this->user($finance);
        $financeHead = $this->user($finance);
        $financeHead->update(['is_department_head' => true]);
        $admin = $this->admin();

        $this->actingAs($actor)
            ->post(route('tasks.store'), $this->payload($assignee, 'Deferred collaborators') + [
                'collaborators' => [$sameAsTask->id, $crossDepartment->id],
            ])
            ->assertRedirect();

        $job = WorkOrder::where('job_topic', 'Deferred collaborators')->firstOrFail();
        $this->assertSame('pending', $job->approval_status);
        $this->assertSame('pending', $job->collaborators()->findOrFail($sameAsTask->id)->pivot->status);
        $this->assertSame('pending', $job->collaborators()->findOrFail($crossDepartment->id)->pivot->status);
        $this->assertNotificationCount($sameAsTask, $job, 'collaborator_added', 0);
        $this->assertNotificationCount($crossDepartment, $job, 'collaborator_added', 0);
        $this->assertNotificationCount($admin, $job, 'collaborator_approval_request', 0);

        $this->actingAs($admin)
            ->patchJson(route('admin.tasks.approval', $job), ['approval_status' => 'approved'])
            ->assertOk();

        $this->assertSame('accepted', $job->fresh()->collaborators()->findOrFail($sameAsTask->id)->pivot->status);
        $this->assertSame('accepted', $job->fresh()->collaborators()->findOrFail($crossDepartment->id)->pivot->status);
        $this->assertNotificationCount($sameAsTask, $job, 'collaborator_added', 1);
        $this->assertNotificationCount($crossDepartment, $job, 'collaborator_added', 1);
        $this->assertNotificationCount($admin, $job, 'collaborator_approval_request', 0);
        // หัวหน้าแผนกต้นสังกัดของผู้เข้าร่วมข้ามแผนกรับทราบตอนที่เขาเข้าร่วมจริง
        $this->assertNotificationCount($financeHead, $job, 'cross_department_participation', 1);

        // ผู้เข้าร่วมข้ามแผนกต้องถูกบอกว่าเป็นงานข้ามแผนกของแผนกใด แม้มาทางเส้นทางอนุมัติ
        $crossNotice = SystemNotification::where('user_id', $crossDepartment->id)
            ->where('work_order_id', $job->job_id)
            ->where('type', 'collaborator_added')
            ->sole();
        $this->assertSame('คุณถูกเพิ่มเข้าร่วมโปรเจกต์ข้ามแผนก', $crossNotice->title);
        $this->assertStringContainsString('แผนกMarketing', $crossNotice->message);
        $this->actingAs($sameAsTask)->get(route('tasks.show', $job))->assertRedirect();
        $this->actingAs($crossDepartment)->get(route('tasks.show', $job))->assertRedirect();
    }

    public function test_department_head_collaborator_joins_when_pending_assignment_is_approved(): void
    {
        $it = Department::create(['department_name' => 'IT']);
        $marketing = Department::create(['department_name' => 'Marketing']);
        $finance = Department::create(['department_name' => 'Finance']);
        $actor = $this->user($it);
        $assignee = $this->user($marketing);
        $head = $this->user($finance);
        $head->update(['is_department_head' => true]);
        $admin = $this->admin();

        $this->actingAs($actor)
            ->post(route('tasks.store'), $this->payload($assignee, 'Deferred head collaborator') + [
                'collaborators' => [$head->id],
            ]);

        $job = WorkOrder::where('job_topic', 'Deferred head collaborator')->firstOrFail();
        $this->assertSame('pending', $job->collaborators()->findOrFail($head->id)->pivot->status);

        $this->actingAs($admin)
            ->patchJson(route('admin.tasks.approval', $job), ['approval_status' => 'approved'])
            ->assertOk();

        $this->assertSame('accepted', $job->fresh()->collaborators()->findOrFail($head->id)->pivot->status);
        $this->assertNotificationCount($head, $job, 'collaborator_added', 1);
        $this->assertNotificationCount($admin, $job, 'collaborator_approval_request', 0);
        // หัวหน้าแผนกที่เข้าร่วมเองไม่ต้องแจ้งตัวเองว่าลูกทีม (คือตัวเขา) ไปร่วมงานแผนกอื่น
        $this->assertNotificationCount($head, $job, 'cross_department_participation', 0);
    }

    public function test_rejected_assignment_rejects_deferred_collaborators_without_notifications(): void
    {
        $it = Department::create(['department_name' => 'IT']);
        $marketing = Department::create(['department_name' => 'Marketing']);
        $actor = $this->user($it);
        $assignee = $this->user($marketing);
        $candidate = $this->user($marketing);
        $admin = $this->admin();

        $this->actingAs($actor)
            ->post(route('tasks.store'), $this->payload($assignee, 'Rejected deferred collaborator') + [
                'collaborators' => [$candidate->id],
            ]);
        $job = WorkOrder::where('job_topic', 'Rejected deferred collaborator')->firstOrFail();

        $this->actingAs($admin)
            ->patchJson(route('admin.tasks.approval', $job), ['approval_status' => 'rejected'])
            ->assertOk();

        $this->assertSame('rejected', $job->fresh()->collaborators()->findOrFail($candidate->id)->pivot->status);
        $this->assertNotificationCount($candidate, $job, 'collaborator_added', 0);
        $this->assertNotificationCount($admin, $job, 'collaborator_approval_request', 0);
    }

    private function crossDepartmentAssignment(string $topic): array
    {
        $it = Department::create(['department_name' => 'IT']);
        $marketing = Department::create(['department_name' => 'Marketing']);
        $actor = $this->user($it);
        $assignee = $this->user($marketing);
        $admin = $this->admin();

        $this->actingAs($actor)->post(route('tasks.store'), $this->payload($assignee, $topic));

        return [$actor->load('department'), $assignee->load('department'), $admin, WorkOrder::where('job_topic', $topic)->firstOrFail()];
    }

    private function assertNotificationCount(User $recipient, WorkOrder $job, string $type, int $expected): void
    {
        $this->assertSame($expected, SystemNotification::where([
            'user_id' => $recipient->id,
            'work_order_id' => $job->job_id,
            'type' => $type,
        ])->count());
    }

    private function payload(User $assignee, string $topic): array
    {
        return [
            'job_topic' => $topic,
            'user_id' => $assignee->id,
            'job_start_at' => now()->format('Y-m-d'),
            'job_due_at' => now()->addDay()->format('Y-m-d'),
            'job_priority' => 2,
        ];
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

    /** หัวหน้าแผนกถือ role = 'user' ต่างกันที่ธง is_department_head เท่านั้น */
    private function departmentHead(Department $department): User
    {
        return User::factory()->create([
            'role' => 'user',
            'department_id' => $department->id,
            'is_department_head' => true,
            'must_change_password' => false,
            'is_active' => true,
        ]);
    }

    private function admin(?Department $department = null): User
    {
        return User::factory()->create([
            'role' => 'admin',
            'department_id' => $department?->id,
            'must_change_password' => false,
            'is_active' => true,
        ]);
    }
}
