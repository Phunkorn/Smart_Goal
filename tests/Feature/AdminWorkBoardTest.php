<?php

namespace Tests\Feature;

use App\Models\Department;
use App\Models\SystemNotification;
use App\Models\User;
use App\Models\WorkOrder;
use App\Models\WorkOrderList;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class AdminWorkBoardTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_follow_department_member_workspace_without_impersonating_member(): void
    {
        $department = Department::create(['department_name' => 'Operations']);
        $admin = $this->user('admin', $department, 'Admin Owner');
        $member = $this->user('user', $department, 'Member A');
        $otherMember = $this->user('user', $department, 'Member B');
        $collaborator = $this->user('user', $department, 'Collaborator X');
        $project = WorkOrderList::create([
            'user_id' => $admin->id,
            'name' => 'Admin Project',
            'priority' => 3,
            'is_visible' => true,
            'sort_order' => 1,
        ]);
        $project->attachments()->create([
            'file_path' => 'project-attachments/'.$project->id.'/brief.docx',
            'original_name' => 'brief.docx',
            'file_type' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'uploaded_by' => $admin->id,
        ]);
        $memberTask = $this->task($project, $admin, $member, 'Member task');
        $memberTask->subtasks()->create([
            'created_by' => $admin->id,
            'title' => 'Prepare checklist',
            'details' => 'Check every item',
            'sort_order' => 1,
        ]);
        $memberTask->collaborators()->attach($collaborator->id, [
            'added_by' => $admin->id,
            'status' => 'accepted',
            'responded_at' => now(),
        ]);
        $otherTask = $this->task($project, $admin, $otherMember, 'Other member task');

        $this->actingAs($admin)
            ->get(route('admin.work-board.department', $department))
            ->assertOk()
            ->assertSee($department->department_name)
            ->assertSee($member->name)
            ->assertSee(route('admin.work-board.member', [$department, $member]), false);

        $response = $this->actingAs($admin)
            ->get(route('admin.work-board.member', [$department, $member]))
            ->assertOk()
            ->assertSee('Admin Project')
            ->assertSee('Member task')
            ->assertSee('brief.docx')
            ->assertSee('Collaborator X')
            ->assertSee('0/1 งานย่อย')
            ->assertSee('มอบหมายโดย Admin Owner')
            ->assertSee('data-kanban-card', false)
            ->assertSee('data-board-task', false)
            ->assertSee('data-view="table"', false)
            ->assertSee('data-view="board"', false)
            ->assertSee('data-view="calendar"', false)
            ->assertSee('data-calendar', false)
            ->assertSee('data-calendar-month', false)
            ->assertSee('data-calendar-year', false)
            ->assertSee('data-calendar-reset', false)
            ->assertSee('data-calendar-detail', false)
            ->assertDontSee('data-calendar-detail-edit', false)
            ->assertDontSee('data-calendar-detail-timeline', false)
            ->assertSee('data-project-board', false)
            ->assertSee('data-table-kanban', false)
            ->assertSee('data-context="admin-member"', false)
            ->assertSee('data-task-modal', false)
            ->assertSee('class="task-edit-modal notion-modal my-tasks-page" data-task-modal hidden', false)
            ->assertSee('data-team-data', false)
            ->assertSee('data-attachment-data', false)
            ->assertSee('data-timeline-data', false)
            ->assertSee('data-task-management-data', false)
            ->assertDontSee('data-task-subtasks', false)
            ->assertDontSee('data-add-subtask', false)
            ->assertDontSee('data-delete-active-task', false)
            ->assertDontSee('data-modal-progress', false)
            ->assertSee('data-reopen-task', false)
            ->assertSee('data-schedule-template', false)
            ->assertSee('data-progress-template', false)
            ->assertSee('data-quick-template', false)
            ->assertSee('data-row', false)
            ->assertSee('data-total-count="2"', false)
            ->assertSee('name="assignee" readonly', false)
            ->assertDontSee('data-create-modal', false)
            ->assertDontSee('data-create-form', false)
            ->assertDontSee('data-group>', false)
            ->assertSee(route('board.index', ['open_assignment' => 1, 'assign_to' => $member->id]));
        $this->assertSame(1, substr_count($response->getContent(), 'data-task-modal'));

        $response->assertViewHas('activeTasks', fn ($tasks) => $tasks->pluck('job_id')->all() === [$memberTask->job_id])
            ->assertViewHas('todayTasks', fn ($tasks) => $tasks->pluck('job_id')->all() === [$memberTask->job_id]);

        $this->assertSame($admin->id, auth()->id());
        $this->assertSame($project->id, $memberTask->fresh()->work_order_list_id);
        $this->assertSame($project->id, $otherTask->fresh()->work_order_list_id);
        $this->assertDatabaseCount('work_order_lists', 1);
        $this->assertStringContainsString('data-task-id="'.$memberTask->job_id.'"', $response->getContent());
    }

    public function test_admin_workspace_routes_are_authorized_and_validate_department_membership(): void
    {
        $first = Department::create(['department_name' => 'IT']);
        $second = Department::create(['department_name' => 'Finance']);
        $admin = $this->user('admin', $first, 'Admin');
        $user = $this->user('user', $first, 'User');
        $otherDepartmentUser = $this->user('user', $second, 'Other');

        $this->actingAs($user)->get(route('admin.work-board.department', $first))->assertForbidden();
        $this->actingAs($user)->get(route('admin.work-board.member', [$first, $user]))->assertForbidden();
        $this->actingAs($admin)->get(route('admin.work-board.member', [$first, $otherDepartmentUser]))->assertNotFound();
    }

    public function test_admin_can_quick_add_to_the_members_existing_project_without_changing_project_identity(): void
    {
        $department = Department::create(['department_name' => 'Operations']);
        $admin = $this->user('admin', $department, 'Admin');
        $member = $this->user('user', $department, 'Member');
        $other = $this->user('user', $department, 'Other');
        $project = WorkOrderList::create(['user_id' => $admin->id, 'name' => 'Shared Project', 'priority' => 2]);
        $this->task($project, $admin, $member, 'Existing member task');

        $this->actingAs($admin)
            ->postJson(route('admin.work-board.member.tasks.store', [$department, $member, $project]), [
                'job_topic' => 'Quick task',
            ])
            ->assertCreated()
            ->assertJsonPath('list_id', $project->id);

        $job = WorkOrder::where('job_topic', 'Quick task')->firstOrFail();
        $this->assertSame($project->id, $job->work_order_list_id);
        $this->assertSame($member->id, $job->user_id);
        $this->assertSame($department->id, $job->department_id);
        $this->assertSame($admin->id, $job->created_by);
        $this->assertSame($admin->id, $job->assigned_by);
        $this->assertSame('approved', $job->approval_status);
        $this->assertDatabaseCount('work_order_lists', 1);
        $this->assertDatabaseHas('system_notifications', [
            'user_id' => $member->id,
            'work_order_id' => $job->job_id,
            'type' => 'admin_created_task',
        ]);
        $this->assertSame(1, SystemNotification::where('work_order_id', $job->job_id)->where('user_id', $member->id)->count());

        $this->actingAs($admin)
            ->postJson(route('tasks.collaborators.store', $job), ['collaborators' => [$other->id]])
            ->assertOk();
        $this->assertSame('accepted', $job->collaborators()->findOrFail($other->id)->pivot->status);

        $foreignProject = WorkOrderList::create(['user_id' => $admin->id, 'name' => 'Foreign Project', 'priority' => 2]);
        $this->task($foreignProject, $admin, $other, 'Other task');
        $this->actingAs($admin)
            ->postJson(route('admin.work-board.member.tasks.store', [$department, $member, $foreignProject]), ['job_topic' => 'Tampered'])
            ->assertNotFound();
        $this->actingAs($member)
            ->postJson(route('admin.work-board.member.tasks.store', [$department, $member, $project]), ['job_topic' => 'Forbidden'])
            ->assertForbidden();
    }

    public function test_admin_workspace_can_update_schedule_and_override_progress_only_without_subtasks(): void
    {
        $department = Department::create(['department_name' => 'Delivery']);
        $admin = $this->user('admin', $department, 'Admin');
        $member = $this->user('user', $department, 'Member');
        $project = WorkOrderList::create(['user_id' => $admin->id, 'name' => 'Delivery Project', 'priority' => 2]);
        $manualTask = $this->task($project, $admin, $member, 'Manual progress');

        $this->actingAs($admin)
            ->patchJson(route('tasks.schedule.update', $manualTask), [
                'job_start_at' => '2026-09-01',
                'job_due_at' => '2026-09-05',
            ])
            ->assertOk();
        $this->actingAs($admin)
            ->postJson(route('tasks.progress.store', $manualTask), [
                'note' => 'Admin override',
                'progress' => 65,
            ])
            ->assertOk();
        $manualTask->refresh();
        $this->assertSame('2026-09-01', $manualTask->job_start_at->format('Y-m-d'));
        $this->assertSame('2026-09-05', $manualTask->job_due_at->format('Y-m-d'));
        $this->assertSame(65, $manualTask->job_progress);

        $derivedTask = $this->task($project, $admin, $member, 'Derived progress');
        $derivedTask->subtasks()->create(['created_by' => $admin->id, 'title' => 'Done', 'is_completed' => true, 'sort_order' => 1]);
        $derivedTask->subtasks()->create(['created_by' => $admin->id, 'title' => 'Pending', 'is_completed' => false, 'sort_order' => 2]);
        $this->actingAs($admin)
            ->postJson(route('tasks.progress.store', $derivedTask), ['note' => 'Must derive', 'progress' => 80])
            ->assertOk();
        $this->assertSame(50, $derivedTask->fresh()->job_progress);

        $this->actingAs($admin)
            ->patchJson(route('tasks.schedule.update', $manualTask), [
                'job_start_at' => '2026-09-10',
                'job_due_at' => '2026-09-09',
            ])
            ->assertUnprocessable();
    }

    public function test_admin_can_add_edit_and_complete_subtasks_from_member_workspace(): void
    {
        $department = Department::create(['department_name' => 'QA']);
        $admin = $this->user('admin', $department, 'Admin');
        $member = $this->user('user', $department, 'Member');
        $project = WorkOrderList::create(['user_id' => $admin->id, 'name' => 'QA Project', 'priority' => 2]);
        $task = $this->task($project, $admin, $member, 'QA task');

        $response = $this->actingAs($admin)
            ->postJson(route('mytasks.subtasks.store', $task), ['title' => 'Check result', 'details' => 'Use staging'])
            ->assertCreated();
        $subtask = $task->subtasks()->findOrFail($response->json('subtask_id'));
        $this->assertSame('Use staging', $subtask->details);

        $this->actingAs($admin)
            ->patchJson(route('mytasks.subtasks.update', $subtask), ['title' => 'Check final result', 'details' => 'Use production-like data'])
            ->assertOk();
        $this->actingAs($admin)
            ->patchJson(route('mytasks.subtasks.toggle', $subtask), ['completed' => true])
            ->assertOk();
        $subtask->refresh();
        $this->assertSame('Check final result', $subtask->title);
        $this->assertSame('Use production-like data', $subtask->details);
        $this->assertTrue($subtask->is_completed);
    }

    public function test_admin_can_upload_and_delete_project_attachments_from_member_workspace(): void
    {
        Storage::fake('public');
        $department = Department::create(['department_name' => 'Design']);
        $admin = $this->user('admin', $department, 'Admin');
        $member = $this->user('user', $department, 'Member');
        $project = WorkOrderList::create(['user_id' => $admin->id, 'name' => 'Design Project', 'priority' => 2]);
        $this->task($project, $admin, $member, 'Design task');

        $this->actingAs($admin)
            ->postJson(route('mytasks.lists.attachments.store', $project), [
                'attachments' => [UploadedFile::fake()->image('brief.png')],
            ])
            ->assertOk();
        $attachment = $project->attachments()->firstOrFail();
        Storage::disk('public')->assertExists($attachment->file_path);

        $this->actingAs($admin)
            ->deleteJson(route('mytasks.lists.attachments.destroy', [$project, $attachment]))
            ->assertOk();
        $this->assertDatabaseMissing('work_order_list_attachments', ['id' => $attachment->id]);
        Storage::disk('public')->assertMissing($attachment->file_path);
    }

    public function test_admin_task_delete_returns_json_for_workspace_requests(): void
    {
        $department = Department::create(['department_name' => 'Support']);
        $admin = $this->user('admin', $department, 'Admin');
        $member = $this->user('user', $department, 'Member');
        $project = WorkOrderList::create(['user_id' => $admin->id, 'name' => 'Support Project', 'priority' => 2]);
        $task = $this->task($project, $admin, $member, 'Delete me');

        $this->actingAs($admin)
            ->deleteJson(route('admin.tasks.destroy', $task))
            ->assertOk()
            ->assertJsonPath('ok', true);
        $this->assertSoftDeleted('work_orders', ['job_id' => $task->job_id]);
    }

    public function test_mixed_creators_use_task_markers_and_user_created_task_has_no_admin_marker(): void
    {
        $department = Department::create(['department_name' => 'QA']);
        $adminOne = $this->user('admin', $department, 'Admin One');
        $adminTwo = $this->user('admin', $department, 'Admin Two');
        $member = $this->user('user', $department, 'Member');
        $project = WorkOrderList::create(['user_id' => $adminOne->id, 'name' => 'Mixed Project', 'priority' => 2]);

        $this->task($project, $adminOne, $member, 'Admin one task');
        $this->task($project, $adminTwo, $member, 'Admin two task');
        $this->task($project, $member, $member, 'User-created task');

        $this->actingAs($member)
            ->get(route('mytasks.index'))
            ->assertOk()
            ->assertSee('มอบหมายโดย Admin One')
            ->assertSee('มอบหมายโดย Admin Two')
            ->assertDontSee('มอบหมายโดย Member');
    }

    public function test_admin_board_has_visible_assignment_entry_point_and_can_preselect_member(): void
    {
        $department = Department::create(['department_name' => 'Support']);
        $admin = $this->user('admin', $department, 'Admin');
        $member = $this->user('user', $department, 'Member');

        $response = $this->actingAs($admin)
            ->get(route('board.index', ['open_assignment' => 1, 'assign_to' => $member->id]))
            ->assertOk()
            ->assertSee('data-open-admin-assignment', false)
            ->assertSee('boardCreateTaskModal', false)
            ->assertSee(route('admin.work-board.department', $department), false)
            ->assertSee((string) $member->id, false);

        $html = $response->getContent();
        $filterStart = strpos($html, 'class="admin-board-filter"');
        $filterEnd = strpos($html, '</form>', $filterStart);
        $trigger = strpos($html, 'data-open-admin-assignment', $filterStart);

        $this->assertNotFalse($filterStart);
        $this->assertNotFalse($filterEnd);
        $this->assertNotFalse($trigger);
        $this->assertTrue($trigger < $filterEnd);
    }

    public function test_admin_overview_job_rows_show_assignee_context_and_open_the_correct_admin_workspace(): void
    {
        $adminDepartment = Department::create(['department_name' => 'Management']);
        $recentDepartment = Department::create(['department_name' => 'Sales']);
        $attentionDepartment = Department::create(['department_name' => 'IT']);
        $admin = $this->user('admin', $adminDepartment, 'Admin');
        $recentAssignee = $this->user('user', $recentDepartment, 'Recent User');
        $recentAssignee->update(['profile_image' => 'profiles/recent-user.jpg']);
        $attentionAssignee = $this->user('user', $attentionDepartment, 'Attention User');
        $fallbackAssignee = User::factory()->create([
            'name' => 'Fallback User',
            'role' => 'user',
            'department_id' => null,
            'is_active' => true,
            'must_change_password' => false,
        ]);
        $project = WorkOrderList::create([
            'user_id' => $admin->id,
            'name' => 'Overview Project',
            'priority' => 2,
            'is_visible' => true,
            'sort_order' => 1,
        ]);

        $recentJob = $this->task($project, $admin, $recentAssignee, 'Recent overview task');
        $recentJob->update(['job_due_at' => now()->addDays(30)]);
        $attentionJob = $this->task($project, $admin, $attentionAssignee, 'Attention overview task');
        $attentionJob->update(['job_due_at' => now()->subDay()]);
        $fallbackJob = $this->task($project, $admin, $fallbackAssignee, 'Fallback overview task');
        $fallbackJob->update(['job_due_at' => now()->addDays(30)]);

        $response = $this->actingAs($admin)->get(route('board.index'))->assertOk();
        $html = preg_replace('/[[:space:]]+/', ' ', $response->getContent());
        $recentUrl = route('admin.work-board.member', [$recentDepartment, $recentAssignee]);
        $attentionUrl = route('admin.work-board.member', [$attentionDepartment, $attentionAssignee]);

        $this->assertStringContainsString('href="'.$recentUrl.'" class="admin-activity-row"', $html);
        $this->assertStringContainsString('Recent User · Sales', $html);
        $this->assertStringContainsString(route('media.show', ['path' => $recentAssignee->profile_image]), $html);
        $this->assertStringContainsString('href="'.$attentionUrl.'" class="admin-attention-row"', $html);
        $this->assertStringContainsString('Attention User · IT', $html);
        $this->assertStringContainsString('title="Attention User"> AT </span>', $html);
        $this->assertStringContainsString(
            'href="'.route('admin.tasks.show', $fallbackJob->job_id).'" class="admin-activity-row"',
            $html
        );
        $this->assertStringContainsString('Fallback User · ไม่ระบุแผนก', $html);

        $viewer = User::factory()->create([
            'role' => 'viewer',
            'department_id' => $adminDepartment->id,
            'is_active' => true,
            'must_change_password' => false,
        ]);
        $viewerResponse = $this->actingAs($viewer)->get(route('board.index'))->assertOk();
        $viewerHtml = preg_replace('/[[:space:]]+/', ' ', $viewerResponse->getContent());

        $this->assertStringContainsString(
            'href="'.route('tasks.show', $recentJob->job_id).'" class="admin-activity-row"',
            $viewerHtml
        );
    }

    public function test_admin_and_user_today_workspaces_share_inclusive_date_range_rules(): void
    {
        $department = Department::create(['department_name' => 'Operations']);
        $admin = $this->user('admin', $department, 'Admin Owner');
        $member = $this->user('user', $department, 'Member A');
        $project = WorkOrderList::create([
            'user_id' => $admin->id,
            'name' => 'Range Project',
            'priority' => 2,
            'is_visible' => true,
            'sort_order' => 1,
        ]);
        $rangeTask = $this->task($project, $admin, $member, 'Aug range task');
        $rangeTask->update([
            'job_status' => 2,
            'job_progress' => 41,
            'job_start_at' => '2026-08-16',
            'job_due_at' => '2026-08-20',
        ]);
        $paused = $this->task($project, $admin, $member, 'Paused range task');
        $paused->update([
            'job_status' => 5,
            'job_start_at' => '2026-08-16',
            'job_due_at' => '2026-08-20',
            'paused_at' => '2026-08-17 09:00:00',
        ]);

        foreach (range(16, 20) as $day) {
            $this->travelTo(Carbon::parse("2026-08-{$day} 12:00:00"));
            $userResponse = $this->actingAs($member)->get(route('mytasks.index'))->assertOk();
            $adminResponse = $this->actingAs($admin)->get(route('admin.work-board.member', [$department, $member]))->assertOk();

            $userIds = $userResponse->viewData('todayTasks')->pluck('job_id')->sort()->values()->all();
            $adminIds = $adminResponse->viewData('todayTasks')->pluck('job_id')->sort()->values()->all();
            $this->assertSame($userIds, $adminIds);
            $this->assertContains($rangeTask->job_id, $adminIds);
            $this->assertContains($paused->job_id, $adminIds);
        }

        $this->travelTo(Carbon::parse('2026-08-18 12:00:00'));
        $adminResponse = $this->actingAs($admin)->get(route('admin.work-board.member', [$department, $member]))->assertOk();
        $adminResponse
            ->assertSee('16–20 ส.ค. 2569')
            ->assertSee('วันที่ 3/5 • เหลือ 2 วัน');
        $this->assertSame(41, (int) $rangeTask->fresh()->job_progress);

        $completed = $this->task($project, $admin, $member, 'Completed early task');
        $completed->update([
            'job_status' => 4,
            'job_start_at' => '2026-08-16',
            'job_due_at' => '2026-08-20',
            'job_completed_at' => '2026-08-18 10:00:00',
        ]);
        $sameDay = $this->actingAs($admin)->get(route('admin.work-board.member', [$department, $member]))->assertOk();
        $this->assertTrue($sameDay->viewData('todayTasks')->contains('job_id', $completed->job_id));

        $this->travelTo(Carbon::parse('2026-08-19 12:00:00'));
        $nextDay = $this->actingAs($admin)->get(route('admin.work-board.member', [$department, $member]))->assertOk();
        $this->assertFalse($nextDay->viewData('todayTasks')->contains('job_id', $completed->job_id));
        $this->assertTrue($nextDay->viewData('todayTasks')->contains('job_id', $paused->job_id));

        $this->travelTo(Carbon::parse('2026-08-21 12:00:00'));
        $lateResponse = $this->actingAs($admin)->get(route('admin.work-board.member', [$department, $member]))->assertOk();
        $this->assertSame(6, (int) $rangeTask->fresh()->job_status);
        $this->assertTrue($lateResponse->viewData('todayTasks')->contains('job_id', $rangeTask->job_id));
        $lateResponse->assertSee('ล่าช้า 1 วัน');
    }

    private function user(string $role, Department $department, string $name): User
    {
        return User::factory()->create([
            'name' => $name,
            'role' => $role,
            'department_id' => $department->id,
            'is_active' => true,
            'must_change_password' => false,
        ]);
    }

    private function task(WorkOrderList $project, User $creator, User $assignee, string $topic): WorkOrder
    {
        return WorkOrder::create([
            'user_id' => $assignee->id,
            'created_by' => $creator->id,
            'leader_user_id' => $assignee->id,
            'department_id' => $assignee->department_id,
            'work_order_list_id' => $project->id,
            'job_topic' => $topic,
            'job_details' => 'Task details',
            'job_priority' => 2,
            'job_status' => 1,
            'approval_status' => 'approved',
            'approved_by' => $creator->role === 'admin' ? $creator->id : null,
            'approved_at' => now(),
            'job_progress' => 0,
            'job_start_at' => now(),
            'job_due_at' => now()->addDay(),
        ]);
    }
}
