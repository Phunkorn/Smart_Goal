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
use Illuminate\Support\Facades\DB;
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
        $memberTask->collaborators()->attach($collaborator->id, [
            'added_by' => $admin->id,
            'status' => 'accepted',
            'responded_at' => now(),
        ]);
        $otherTask = $this->task($project, $admin, $otherMember, 'Other member task');

        $queries = [];
        DB::listen(function ($query) use (&$queries): void {
            $queries[] = strtolower($query->sql);
        });

        $this->actingAs($admin)
            ->get(route('admin.work-board.department', $department))
            ->assertOk()
            ->assertSee($department->department_name)
            ->assertSee($member->name)
            ->assertSee('data-work-board-directory', false)
            ->assertSee('data-work-board-mode', false)
            ->assertSee('data-member-card', false)
            ->assertSee(route('admin.work-board.member.preview', [$department, $member]), false)
            ->assertDontSee('wb-overview', false)
            ->assertDontSee('admin-member-selector__grid', false);

        $this->actingAs($admin)
            ->get(route('admin.work-board.member.preview', [$department, $member]))
            ->assertOk()
            ->assertSee('Member task')
            ->assertDontSee('Other member task')
            ->assertSee('data-preview-task-link', false)
            ->assertSee(route('admin.work-board.member', [
                $department,
                $member,
                'open_task' => $memberTask->job_id,
            ]), false)
            ->assertDontSee('data-preview-readonly', false);

        $response = $this->actingAs($admin)
            ->get(route('admin.work-board.member', [$department, $member]))
            ->assertOk()
            ->assertSee('Admin Project')
            ->assertSee('Member task')
            ->assertSee('brief.docx')
            ->assertSee('Collaborator X')
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
            // overlay ใช้ theme scope (sg-task-theme) ไม่ใช่ page-layout class
            // เพื่อไม่ให้ width/margin ของหน้ารั่วลงมาบีบ backdrop
            ->assertSee('class="task-workspace-modal notion-modal sg-task-theme" data-task-modal hidden', false)
            ->assertDontSee('task-workspace-modal notion-modal my-tasks-page', false)
            ->assertSee('class="task-workspace"', false)
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
            ->assertDontSee('data-progress-template', false)
            ->assertSee('data-quick-template', false)
            ->assertSee('data-row', false)
            ->assertSee('data-total-count="2"', false)
            // ผู้รับผิดชอบเป็นข้อมูลอ่านอย่างเดียวใน Workspace ใหม่ จึงไม่ใช่ช่องกรอกอีกต่อไป
            ->assertSee('data-workspace-assignee', false)
            ->assertSee('name="assignee" type="hidden"', false)
            ->assertDontSee('data-create-modal', false)
            ->assertDontSee('data-create-form', false)
            ->assertDontSee('data-group>', false)
            // ปุ่มมอบหมายงานเปิดโมดัลในหน้าเดิม ไม่พา Admin กลับไปหน้าบอร์ดรวมอีกต่อไป
            ->assertSee('<button type="button" class="admin-assignment-launch admin-assign-button" data-open-admin-assignment>', false)
            ->assertDontSee(route('board.index', ['open_assignment' => 1, 'assign_to' => $member->id]))
            ->assertSee('data-admin-assignment-modal', false)
            ->assertSee('name="assignment_origin" value="admin-member"', false)
            ->assertSee('name="origin_department_id" value="'.$department->id.'"', false)
            ->assertSee('name="origin_member_id" value="'.$member->id.'"', false)
            ->assertSee('data-default-assignee-id="'.$member->id.'"', false)
            ->assertSee('name="user_id" data-task-assignee value="'.$member->id.'"', false)
            ->assertSee('name="work_order_list_id" data-selected-project-id value="'.$project->id.'"', false);
        $this->assertSame(1, substr_count($response->getContent(), 'data-task-modal'));
        $this->assertSame(1, substr_count($response->getContent(), 'data-admin-assignment-modal'));

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
        $this->actingAs($user)->get(route('admin.work-board.member.preview', [$first, $user]))->assertForbidden();
        $this->actingAs($user)->get(route('admin.work-board.member', [$first, $user]))->assertForbidden();
        $this->actingAs($admin)->get(route('admin.work-board.member.preview', [$first, $otherDepartmentUser]))->assertNotFound();
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

    public function test_admin_preview_hides_unapproved_work_and_never_links_to_wrong_member_context(): void
    {
        // Preview เปลี่ยนเป็นรายการ "งานที่ต้องจัดการวันนี้" งานที่ยังรออนุมัติจึงไม่อยู่ที่นี่แล้ว
        // เพราะมันไม่เคยเข้าวงจร auto-start/auto-late และมีหน้า "คำขออนุมัติ" เป็นเจ้าของอยู่แล้ว
        $department = Department::create(['department_name' => 'Preview']);
        $admin = $this->user('admin', $department, 'Admin');
        $member = $this->user('user', $department, 'Member');
        $otherMember = $this->user('user', $department, 'Other');
        $project = WorkOrderList::create(['user_id' => $admin->id, 'name' => 'Preview Project', 'priority' => 2]);
        $approved = $this->task($project, $admin, $member, 'Approved today task');
        $pending = $this->task($project, $admin, $member, 'Pending admin-visible task');
        $pending->update(['approval_status' => 'pending', 'approved_by' => null, 'approved_at' => null]);
        $otherTask = $this->task($project, $admin, $otherMember, 'Wrong member task');

        $response = $this->actingAs($admin)
            ->get(route('admin.work-board.member.preview', [$department, $member]))
            ->assertOk()
            ->assertSee('Approved today task')
            ->assertDontSee('Pending admin-visible task')
            ->assertDontSee('Wrong member task')
            ->assertSee(route('admin.work-board.member', [
                $department,
                $member,
                'open_task' => $approved->job_id,
            ]), false)
            ->assertDontSee(route('admin.work-board.member', [
                $department,
                $member,
                'open_task' => $pending->job_id,
            ]), false)
            ->assertDontSee(route('admin.work-board.member', [
                $department,
                $otherMember,
                'open_task' => $otherTask->job_id,
            ]), false);

        $this->assertSame(1, substr_count($response->getContent(), 'data-preview-task-link'));
        $this->assertFalse(collect($queries)->contains(fn (string $sql) => str_contains($sql, 'work_order_subtasks')));

        // งานที่รออนุมัติต้องยังอยู่ครบใน Member Workspace เต็ม ไม่ได้หายไปจากระบบ
        $this->actingAs($admin)
            ->get(route('admin.work-board.member', [$department, $member]))
            ->assertOk()
            ->assertSee('Pending admin-visible task');
    }

    public function test_admin_workspace_can_update_schedule_and_reject_an_invalid_range(): void
    {
        $department = Department::create(['department_name' => 'Delivery']);
        $admin = $this->user('admin', $department, 'Admin');
        $member = $this->user('user', $department, 'Member');
        $project = WorkOrderList::create(['user_id' => $admin->id, 'name' => 'Delivery Project', 'priority' => 2]);
        $manualTask = $this->task($project, $admin, $member, 'Scheduled task');

        $this->actingAs($admin)
            ->patchJson(route('tasks.schedule.update', $manualTask), [
                'job_start_at' => '2026-09-01',
                'job_due_at' => '2026-09-05',
            ])
            ->assertOk();
        $manualTask->refresh();
        $this->assertSame('2026-09-01', $manualTask->job_start_at->format('Y-m-d'));
        $this->assertSame('2026-09-05', $manualTask->job_due_at->format('Y-m-d'));

        $this->actingAs($admin)
            ->patchJson(route('tasks.schedule.update', $manualTask), [
                'job_start_at' => '2026-09-10',
                'job_due_at' => '2026-09-09',
            ])
            ->assertUnprocessable();
    }

    public function test_admin_can_upload_and_delete_project_attachments_from_member_workspace(): void
    {
        Storage::fake('public');
        Storage::fake('local');
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
        Storage::disk('local')->assertExists($attachment->file_path);

        $this->actingAs($admin)
            ->deleteJson(route('mytasks.lists.attachments.destroy', [$project, $attachment]))
            ->assertOk();
        $this->assertDatabaseMissing('work_order_list_attachments', ['id' => $attachment->id]);
        Storage::disk('local')->assertMissing($attachment->file_path);
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
        $headerStart = strpos($html, 'class="admin-board-header"');
        $headerEnd = strpos($html, '</header>', $headerStart);
        $trigger = strpos($html, 'data-open-admin-assignment');

        $this->assertNotFalse($headerStart);
        $this->assertNotFalse($headerEnd);
        $this->assertNotFalse($trigger);
        $this->assertTrue($headerStart < $trigger && $trigger < $headerEnd, 'ปุ่มสร้างโปรเจกต์ต้องอยู่ใน header');
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
        $this->assertStringContainsString(route('media.profile', $recentAssignee), $html);
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

        $completed = $this->task($project, $admin, $member, 'Completed early task');
        $completed->update([
            'job_status' => 4,
            'job_start_at' => '2026-08-16',
            'job_due_at' => '2026-08-20',
            'job_completed_at' => '2026-08-18 10:00:00',
        ]);
        $sameDay = $this->actingAs($admin)->get(route('admin.work-board.member', [$department, $member]))->assertOk();
        $this->assertTrue($sameDay->viewData('todayTasks')->contains('job_id', $completed->job_id));
        $sameDayHtml = $sameDay->getContent();
        $sameDayTableStart = strpos($sameDayHtml, 'data-table-kanban');
        $sameDayCalendarStart = strpos($sameDayHtml, 'data-calendar', $sameDayTableStart);
        $this->assertNotFalse($sameDayTableStart);
        $this->assertNotFalse($sameDayCalendarStart);
        $this->assertStringContainsString(
            'data-id='.chr(34).$completed->job_id.chr(34),
            substr($sameDayHtml, $sameDayTableStart, $sameDayCalendarStart - $sameDayTableStart)
        );

        $this->travelTo(Carbon::parse('2026-08-19 12:00:00'));
        $nextDay = $this->actingAs($admin)->get(route('admin.work-board.member', [$department, $member]))->assertOk();
        $this->assertFalse($nextDay->viewData('todayTasks')->contains('job_id', $completed->job_id));
        $this->assertTrue($nextDay->viewData('todayTasks')->contains('job_id', $paused->job_id));
        $nextDayHtml = $nextDay->getContent();
        $nextDayTableStart = strpos($nextDayHtml, 'data-table-kanban');
        $nextDayCalendarStart = strpos($nextDayHtml, 'data-calendar', $nextDayTableStart);
        $this->assertNotFalse($nextDayTableStart);
        $this->assertNotFalse($nextDayCalendarStart);
        $nextDayTableHtml = substr($nextDayHtml, $nextDayTableStart, $nextDayCalendarStart - $nextDayTableStart);
        $this->assertStringNotContainsString('data-id='.chr(34).$completed->job_id.chr(34), $nextDayTableHtml);
        $this->assertStringContainsString('data-id='.chr(34).$paused->job_id.chr(34), $nextDayTableHtml);

        $this->travelTo(Carbon::parse('2026-08-21 12:00:00'));
        $lateResponse = $this->actingAs($admin)->get(route('admin.work-board.member', [$department, $member]))->assertOk();
        $this->assertSame(6, (int) $rangeTask->fresh()->job_status);
        $this->assertTrue($lateResponse->viewData('todayTasks')->contains('job_id', $rangeTask->job_id));
        $lateResponse->assertSee('ล่าช้า 1 วัน');
    }

    public function test_member_without_tasks_shows_admin_workspace_entry_while_user_preview_stays_read_only(): void
    {
        $department = Department::create(['department_name' => 'Empty Desk']);
        $admin = $this->user('admin', $department, 'Admin');
        $member = $this->user('user', $department, 'Idle Member');
        $teammate = $this->user('user', $department, 'Teammate');
        $workspaceUrl = route('admin.work-board.member', [$department, $member]);

        $this->actingAs($admin)
            ->get(route('admin.work-board.member.preview', [$department, $member]))
            ->assertOk()
            ->assertSee('data-preview-empty', false)
            ->assertSee('วันนี้ไม่มีงานที่ต้องติดตาม')
            ->assertSee('เปิดพื้นที่งานของสมาชิกเพื่อดูงานทั้งหมด หรือสร้างโปรเจกต์และมอบหมายงานใหม่')
            ->assertSee('เปิดพื้นที่งานของสมาชิก')
            ->assertSee('href="'.$workspaceUrl.'"', false)
            ->assertSee('data-preview-workspace-link', false)
            // CTA ต้องไม่ใช่ลิงก์รายการงาน เพื่อไม่ให้แย่ง action จาก task list
            ->assertDontSee('data-preview-task-link', false);

        $this->actingAs($teammate)
            ->get(route('work-board.member', [$department, $member]))
            ->assertOk()
            ->assertSee('data-preview-readonly', false)
            ->assertSee('ดูแบบอ่านอย่างเดียว')
            ->assertSee('เมื่อมีงานที่ต้องจัดการในวันนี้ รายการจะแสดงที่นี่')
            ->assertDontSee('เปิดพื้นที่งานของสมาชิก')
            ->assertDontSee('data-preview-workspace-link', false)
            ->assertDontSee($workspaceUrl, false);

        // ซ่อนปุ่มไม่ใช่ security boundary — route ฝั่ง Admin ต้องยังปฏิเสธ user/viewer เหมือนเดิม
        $this->actingAs($teammate)->get($workspaceUrl)->assertForbidden();
        $viewer = $this->user('viewer', $department, 'Viewer');
        $this->actingAs($viewer)->get($workspaceUrl)->assertForbidden();
        $this->actingAs($admin)->get(route('admin.work-board.member.preview', [$department, $viewer]))->assertNotFound();
    }

    public function test_member_workspace_without_tasks_opens_with_zero_totals_and_in_page_assignment_modal(): void
    {
        $department = Department::create(['department_name' => 'Blank']);
        $otherDepartment = Department::create(['department_name' => 'Elsewhere']);
        $admin = $this->user('admin', $department, 'Admin');
        $member = $this->user('user', $department, 'Fresh Member');
        $outsider = $this->user('user', $otherDepartment, 'Outsider');

        $response = $this->actingAs($admin)
            ->get(route('admin.work-board.member', [$department, $member]))
            ->assertOk()
            ->assertViewHas('totals', ['projects' => 0, 'tasks' => 0])
            ->assertSee('admin-assign-button', false)
            ->assertSee('data-open-admin-assignment', false)
            ->assertSee('data-admin-assignment-modal', false)
            ->assertSee('action="'.route('mytasks.create').'"', false)
            ->assertSee('action="'.route('tasks.store').'"', false)
            // โปรเจกต์และงานใหม่ต้องตั้งต้นที่สมาชิกคนนี้
            ->assertSee('data-default-assignee-id="'.$member->id.'"', false)
            ->assertSee('name="project_owner_id" value="'.$member->id.'"', false)
            ->assertSee('name="user_id" data-task-assignee value="'.$member->id.'"', false)
            ->assertSee('name="assignment_origin" value="admin-member"', false)
            ->assertSee('name="origin_department_id" value="'.$department->id.'"', false)
            ->assertSee('name="origin_member_id" value="'.$member->id.'"', false)
            // ยังคงเป็นการเปิดในหน้าเดิม ไม่มีลิงก์ออกไปบอร์ดรวมพร้อม query string
            ->assertDontSee(route('board.index', ['open_assignment' => 1, 'assign_to' => $member->id]))
            // ไม่มี validation error ค้างอยู่ Modal จึงต้องยังไม่เปิดเอง
            ->assertDontSee('data-open-on-load', false);

        // ผู้รับผิดชอบที่เลือกได้ต้องเป็น role user เท่านั้น (รวมคนต่างแผนก ตาม logic เดิมของ modal)
        $this->assertStringContainsString('data-id="'.$outsider->id.'"', $response->getContent());
        $viewer = $this->user('viewer', $department, 'Viewer');
        $this->assertStringNotContainsString('data-id="'.$viewer->id.'"', $response->getContent());
        $this->assertSame(1, substr_count($response->getContent(), 'data-admin-assignment-modal'));
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
            'job_status' => 2,
            'approval_status' => 'approved',
            'approved_by' => $creator->role === 'admin' ? $creator->id : null,
            'approved_at' => now(),
            'job_start_at' => now(),
            'job_due_at' => now()->addDay(),
        ]);
    }
    public function test_task_added_by_admin_stays_in_its_project_after_member_opens_my_tasks(): void
    {
        $department = Department::create(['department_name' => 'Retention IT']);
        $admin = User::factory()->create(['role' => 'admin']);
        $member = User::factory()->create(['role' => 'user', 'department_id' => $department->id]);

        $list = WorkOrderList::create([
            'user_id' => $member->id,
            'name' => 'Member multi task project',
            'is_visible' => true,
            'sort_order' => 1,
        ]);

        foreach (['Existing A', 'Existing B'] as $topic) {
            WorkOrder::create([
                'user_id' => $member->id,
                'created_by' => $member->id,
                'leader_user_id' => $member->id,
                'department_id' => $department->id,
                'work_order_list_id' => $list->id,
                'job_topic' => $topic,
                'job_priority' => 2,
                'job_status' => 2,
                'approval_status' => 'approved',
                'job_start_at' => now(),
                'job_due_at' => now()->addDay(),
            ]);
        }

        $this->actingAs($admin)
            ->postJson(
                route('admin.work-board.member.tasks.store', [$department, $member, $list]),
                ['job_topic' => 'Admin added task']
            )
            ->assertCreated();

        $added = WorkOrder::where('job_topic', 'Admin added task')->firstOrFail();
        $this->assertSame($list->id, (int) $added->work_order_list_id);

        $this->actingAs($member)->get(route('mytasks.index'))->assertOk();

        $this->assertSame(
            $list->id,
            (int) $added->fresh()->work_order_list_id,
            'Opening My Tasks must not undo a task placement made by an admin.'
        );
        $this->assertSame(1, WorkOrderList::where('user_id', $member->id)->count());
    }

    public function test_ungrouped_admin_assignment_still_receives_its_own_project(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $member = User::factory()->create(['role' => 'user']);

        $job = WorkOrder::create([
            'user_id' => $member->id,
            'created_by' => $admin->id,
            'leader_user_id' => $member->id,
            'work_order_list_id' => null,
            'job_topic' => 'Loose admin assignment',
            'job_priority' => 2,
            'job_status' => 2,
            'approval_status' => 'approved',
            'job_start_at' => now(),
            'job_due_at' => now()->addDay(),
        ]);

        $this->actingAs($member)->get(route('mytasks.index'))->assertOk();

        $project = WorkOrderList::where('user_id', $member->id)->firstOrFail();
        $this->assertSame('Loose admin assignment', $project->name);
        $this->assertSame($project->id, (int) $job->fresh()->work_order_list_id);
    }

}
