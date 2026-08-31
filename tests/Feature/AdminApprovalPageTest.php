<?php

namespace Tests\Feature;

use App\Models\Department;
use App\Models\SystemNotification;
use App\Models\User;
use App\Models\WorkOrder;
use App\Services\AdminApprovalQuery;
use App\Services\NotificationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class AdminApprovalPageTest extends TestCase
{
    use RefreshDatabase;

    public function test_page_is_available_only_to_admins(): void
    {
        $department = Department::create(['department_name' => 'IT']);

        $this->get(route('admin.approvals.index'))->assertRedirect(route('login'));

        foreach (['user', 'viewer'] as $role) {
            $this->actingAs($this->user($role, $department))
                ->get(route('admin.approvals.index'))
                ->assertForbidden();
        }

        $this->actingAs($this->user('admin', $department))
            ->get(route('admin.approvals.index'))
            ->assertOk()
            ->assertSee('คำขออนุมัติ');
    }

    public function test_page_shows_only_actionable_requests_and_counts_collaborator_pivots(): void
    {
        $it = Department::create(['department_name' => 'IT']);
        $marketing = Department::create(['department_name' => 'Marketing']);
        $admin = $this->user('admin', $it);
        $requester = $this->user('user', $it);
        $assignee = $this->user('user', $marketing);
        $candidateOne = $this->user('user', $marketing);
        $candidateTwo = $this->user('user', $marketing);
        $acceptedCandidate = $this->user('user', $marketing);
        $rejectedCandidate = $this->user('user', $marketing);
        $deferredCandidate = $this->user('user', $marketing);

        $pendingAssignment = $this->task($requester, $assignee, $marketing, 'Pending assignment', 'pending');
        $this->task($requester, $assignee, $marketing, 'Approved assignment', 'approved');
        $this->task($requester, $assignee, $marketing, 'Rejected assignment', 'rejected');

        $collaboratorTask = $this->task($requester, $requester, $it, 'Pending collaborators', 'approved');
        $collaboratorTask->collaborators()->attach([
            $candidateOne->id => ['status' => 'pending', 'added_by' => $requester->id],
            $candidateTwo->id => ['status' => 'pending', 'added_by' => $requester->id],
            $acceptedCandidate->id => ['status' => 'accepted', 'added_by' => $requester->id],
            $rejectedCandidate->id => ['status' => 'rejected', 'added_by' => $requester->id],
        ]);

        $deferredTask = $this->task($requester, $assignee, $marketing, 'Deferred collaborator', 'pending');
        $deferredTask->collaborators()->attach($deferredCandidate->id, [
            'status' => 'pending',
            'added_by' => $requester->id,
        ]);

        $response = $this->actingAs($admin)->get(route('admin.approvals.index'));

        $response->assertOk()
            ->assertViewHas('approvalCounts', ['assignments' => 2, 'collaborators' => 2, 'total' => 4])
            ->assertSee($pendingAssignment->job_topic)
            ->assertSee($candidateOne->name)
            ->assertSee($candidateTwo->name)
            ->assertDontSee('Approved assignment')
            ->assertDontSee('Rejected assignment')
            ->assertDontSee($acceptedCandidate->name)
            ->assertDontSee($rejectedCandidate->name)
            ->assertDontSee($deferredCandidate->name)
            ->assertSee(route('admin.tasks.approval', $pendingAssignment), false)
            ->assertSee(route('admin.tasks.collaborators.approval', [$collaboratorTask, $candidateOne]), false);
    }

    public function test_empty_states_and_board_cleanup_are_rendered_without_changing_board_features(): void
    {
        $department = Department::create(['department_name' => 'IT']);
        $admin = $this->user('admin', $department);

        $this->actingAs($admin)->get(route('admin.approvals.index'))
            ->assertOk()
            ->assertViewHas('approvalCounts', ['assignments' => 0, 'collaborators' => 0, 'total' => 0])
            ->assertSee('ไม่มีงานข้ามแผนกที่รอการตัดสินใจ')
            ->assertSee('ไม่มีคำขอผู้ร่วมงานข้ามแผนกที่รอการตัดสินใจ');

        $this->actingAs($admin)->get(route('board.index'))
            ->assertOk()
            ->assertSee('บอร์ดทุกแผนก')
            ->assertSee('สร้างโปรเจกต์')
            ->assertSee('แล้วเพิ่มรายการงาน')
            ->assertSee('สรุปภาพรวมองค์กร')
            ->assertDontSee('assignment-approval-queue')
            ->assertDontSee('collaborator-approval-queue')
            ->assertDontSee('data-approval-form', false);
    }

    public function test_sidebar_badge_uses_lightweight_counts_only_for_admins(): void
    {
        $it = Department::create(['department_name' => 'IT']);
        $marketing = Department::create(['department_name' => 'Marketing']);
        $admin = $this->user('admin', $it);
        $requester = $this->user('user', $it);
        $assignee = $this->user('user', $marketing);
        $candidate = $this->user('user', $marketing);

        $this->task($requester, $assignee, $marketing, 'Badge assignment', 'pending');
        $task = $this->task($requester, $requester, $it, 'Badge collaborator', 'approved');
        $task->collaborators()->attach($candidate->id, ['status' => 'pending', 'added_by' => $requester->id]);

        DB::enableQueryLog();
        $this->assertSame(['assignments' => 1, 'collaborators' => 1, 'total' => 2], app(AdminApprovalQuery::class)->counts());
        $countQueries = DB::getQueryLog();
        DB::disableQueryLog();

        $this->assertCount(2, $countQueries);
        $this->assertFalse(collect($countQueries)->contains(fn (array $query): bool => str_contains($query['query'], 'select *')));
        $this->app->forgetScopedInstances();

        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->actingAs($admin)->get(route('admin.approvals.index'))
            ->assertSee('data-approval-count', false)
            ->assertSee('>2</span>', false);
        $pageQueries = DB::getQueryLog();
        DB::disableQueryLog();

        $this->assertFalse(collect($pageQueries)->contains(fn (array $query): bool =>
            str_contains(strtolower($query['query']), 'count(')
            && (str_contains($query['query'], 'work_orders') || str_contains($query['query'], 'work_order_collaborators'))
        ), 'The approval page should reuse list-derived counts instead of querying counts again.');

        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->actingAs($requester)->get(route('notifications.index'))
            ->assertOk()
            ->assertDontSee('คำขออนุมัติ');
        $nonAdminQueries = DB::getQueryLog();
        DB::disableQueryLog();

        $this->assertFalse(collect($nonAdminQueries)->contains(fn (array $query): bool =>
            str_contains($query['query'], 'work_order_collaborators')
            || str_contains($query['query'], 'approval_status')
        ), 'Non-admin layouts must not query approval counts.');
    }

    public function test_approval_notifications_target_the_new_queue_sections(): void
    {
        $it = Department::create(['department_name' => 'IT']);
        $marketing = Department::create(['department_name' => 'Marketing']);
        $admin = $this->user('admin', $it);
        $requester = $this->user('user', $it);
        $assignee = $this->user('user', $marketing);
        $task = $this->task($requester, $assignee, $marketing, 'Notification target', 'pending');
        $notifications = app(NotificationService::class);

        foreach ([
            'cross_department_pending' => 'assignment',
            'collaborator_approval_request' => 'collaborator',
        ] as $type => $queue) {
            $notification = SystemNotification::create([
                'user_id' => $admin->id,
                'work_order_id' => $task->job_id,
                'type' => $type,
                'title' => 'Approval request',
                'message' => 'Pending',
            ]);

            $this->assertSame(
                route('admin.approvals.index', ['approval_queue' => $queue]),
                $notifications->target($notification, $admin)
            );
        }
    }

    private function user(string $role, Department $department): User
    {
        return User::factory()->create([
            'role' => $role,
            'department_id' => $department->id,
            'must_change_password' => false,
            'is_active' => true,
        ]);
    }

    private function task(
        User $creator,
        User $assignee,
        Department $department,
        string $topic,
        string $approvalStatus
    ): WorkOrder {
        return WorkOrder::create([
            'user_id' => $assignee->id,
            'created_by' => $creator->id,
            'assigned_by' => $creator->id,
            'leader_user_id' => $creator->id,
            'department_id' => $department->id,
            'job_topic' => $topic,
            'job_priority' => 2,
            'job_status' => 1,
            'approval_status' => $approvalStatus,
            'job_start_at' => now(),
            'job_due_at' => now()->addDay(),
        ]);
    }
}
