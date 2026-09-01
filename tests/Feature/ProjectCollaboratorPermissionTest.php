<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\WorkOrder;
use App\Models\WorkOrderList;
use App\Services\PersonalReportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ProjectCollaboratorPermissionTest extends TestCase
{
    use RefreshDatabase;

    public function test_accepted_collaborator_sees_every_task_in_the_project_but_not_other_projects(): void
    {
        $owner = $this->user();
        $collaborator = $this->user();
        $project = $this->project($owner, 'อบรม');
        $anchor = $this->task($owner, $project, 'ออกแบบโลโก้');
        $secondTask = $this->task($owner, $project, 'ติดต่อลูกค้า');
        $thirdTask = $this->task($owner, $project, 'ทำสื่อ');
        $hiddenProject = $this->project($owner, 'เว็บไซต์');
        $hiddenTask = $this->task($owner, $hiddenProject, 'หน้า Login');
        $anchor->collaborators()->attach($collaborator->id, ['status' => 'accepted']);

        foreach (['table', 'board', 'calendar'] as $view) {
            $this->actingAs($collaborator)->get(route('mytasks.index', ['view' => $view]))
                ->assertOk()
                ->assertSee($project->name)
                ->assertSee($anchor->job_topic)
                ->assertSee($secondTask->job_topic)
                ->assertSee($thirdTask->job_topic)
                ->assertDontSee('data-project-name="'.$hiddenProject->name.'"', false)
                ->assertDontSee($hiddenTask->job_topic);
        }

        $this->actingAs($collaborator)->get(route('mytasks.quickview.task', $secondTask))->assertOk();
        $this->actingAs($collaborator)->get(route('tasks.show', $thirdTask))->assertRedirect(route('mytasks.index'));
        $this->actingAs($collaborator)->get(route('mytasks.quickview.task', $hiddenTask))->assertForbidden();
        $this->actingAs($collaborator)->get(route('tasks.show', $hiddenTask))->assertForbidden();
    }

    public function test_unrelated_user_cannot_see_project_or_tasks(): void
    {
        $owner = $this->user();
        $unrelated = $this->user();
        $project = $this->project($owner, 'Private project');
        $task = $this->task($owner, $project, 'Private task');

        $this->actingAs($unrelated)->get(route('mytasks.index', ['view' => 'board']))
            ->assertOk()
            ->assertDontSee($project->name)
            ->assertDontSee($task->job_topic);
        $this->actingAs($unrelated)->get(route('mytasks.quickview.task', $task))->assertForbidden();
        $this->actingAs($unrelated)->get(route('tasks.show', $task))->assertForbidden();
    }

    public function test_pending_rejected_and_removed_collaborators_have_no_project_level_visibility(): void
    {
        foreach (['pending', 'rejected'] as $status) {
            $owner = $this->user();
            $collaborator = $this->user();
            $project = $this->project($owner, 'Project '.$status);
            $anchor = $this->task($owner, $project, 'Anchor '.$status);
            $other = $this->task($owner, $project, 'Private '.$status);
            $anchor->collaborators()->attach($collaborator->id, ['status' => $status]);

            $this->actingAs($collaborator)->get(route('mytasks.index', ['view' => 'table']))
                ->assertOk()
                ->assertDontSee('data-project-name="'.$project->name.'"', false)
                ->assertDontSee($other->job_topic);
            $this->actingAs($collaborator)->get(route('mytasks.quickview.task', $other))->assertForbidden();
        }

        $owner = $this->user();
        $removed = $this->user();
        $project = $this->project($owner, 'Removed project');
        $anchor = $this->task($owner, $project, 'Removed anchor');
        $other = $this->task($owner, $project, 'Removed private');
        $anchor->collaborators()->attach($removed->id, ['status' => 'accepted']);
        $this->actingAs($removed)->get(route('mytasks.quickview.task', $other))->assertOk();
        $anchor->collaborators()->detach($removed->id);
        $this->actingAs($removed)->get(route('mytasks.index', ['view' => 'calendar']))
            ->assertOk()
            ->assertDontSee('data-project-name="'.$project->name.'"', false)
            ->assertDontSee($other->job_topic);
        $this->actingAs($removed)->get(route('mytasks.quickview.task', $other))->assertForbidden();
    }

    public function test_sibling_task_is_read_only_and_cannot_be_commented_on(): void
    {
        $owner = $this->user();
        $collaborator = $this->user();
        $project = $this->project($owner);
        $directTask = $this->task($owner, $project, 'Direct collaboration');
        $siblingTask = $this->task($owner, $project, 'Read-only sibling');
        $directTask->collaborators()->attach($collaborator->id, ['status' => 'accepted']);

        $management = $this->taskManagementFor($collaborator);
        $this->assertTrue($management[$directTask->job_id]['can_work']);
        $this->assertTrue($management[$directTask->job_id]['transitions']['can_edit']);
        $this->assertTrue($management[$directTask->job_id]['can_comment']);
        $this->assertSame(route('tasks.comments.store', $directTask), $management[$directTask->job_id]['comment_url']);
        $this->assertFalse($management[$siblingTask->job_id]['can_work']);
        $this->assertFalse($management[$siblingTask->job_id]['transitions']['can_edit']);
        $this->assertFalse($management[$siblingTask->job_id]['can_comment']);
        $this->assertNull($management[$siblingTask->job_id]['comment_url']);

        $content = $this->actingAs($collaborator)
            ->get(route('mytasks.index', ['view' => 'board']))
            ->assertOk()
            ->getContent();
        preg_match('/<article[^>]*data-board-task[^>]*data-task-id="'.$siblingTask->job_id.'"[^>]*>(.*?)<\/article>/s', $content, $matches);
        $this->assertNotEmpty($matches, 'ไม่พบ sibling task card ที่ต้องตรวจสิทธิ์');
        $this->assertStringNotContainsString('bi-three-dots-vertical', $matches[0]);
        $this->assertStringNotContainsString('แก้ไขชื่อรายการงาน', $matches[0]);
        $this->assertStringNotContainsString('ลบรายการงาน', $matches[0]);

        preg_match('/<article[^>]*data-board-task[^>]*data-task-id="'.$directTask->job_id.'"[^>]*>(.*?)<\/article>/s', $content, $directMatches);
        $this->assertStringContainsString('data-board-status-menu', $directMatches[0]);
        $this->assertStringContainsString('bi-people-fill', $directMatches[0]);
        $this->assertStringNotContainsString('bi-person-plus-fill', $directMatches[0]);

        $tableContent = $this->actingAs($collaborator)
            ->get(route('mytasks.index', ['view' => 'table']))
            ->assertOk()
            ->getContent();
        $directRow = $this->tableRowMarkup($tableContent, $directTask->job_id);
        $siblingRow = $this->tableRowMarkup($tableContent, $siblingTask->job_id);
        $this->assertStringContainsString('data-table-status-menu', $directRow);
        $this->assertStringContainsString('class="cell-date"', $directRow);
        $this->assertStringNotContainsString('data-table-status-menu', $siblingRow);
        $this->assertStringNotContainsString('class="cell-date"', $siblingRow);

        $this->actingAs($collaborator)
            ->postJson(route('tasks.comments.store', $directTask), ['message' => 'Direct comment'])
            ->assertCreated();
        $this->actingAs($collaborator)
            ->postJson(route('tasks.comments.store', $siblingTask), ['message' => 'Sibling comment'])
            ->assertForbidden();
        $this->actingAs($collaborator)
            ->patchJson(route('tasks.details.update', $siblingTask), ['job_topic' => 'Forbidden change'])
            ->assertForbidden();

        $this->actingAs($collaborator)
            ->patchJson(route('tasks.updateStatus', $siblingTask), ['job_status' => 2])
            ->assertForbidden();
        $this->actingAs($collaborator)
            ->patchJson(route('tasks.schedule.update', $siblingTask), [
                'job_start_at' => now()->addDay()->toDateString(),
                'job_due_at' => now()->addDays(2)->toDateString(),
            ])
            ->assertForbidden();
        $this->actingAs($collaborator)
            ->postJson(route('tasks.attachments.store', $siblingTask), [])
            ->assertForbidden();
        $this->actingAs($collaborator)
            ->postJson(route('tasks.collaborators.store', $siblingTask), ['collaborators' => [$this->user()->id]])
            ->assertForbidden();
        $this->actingAs($collaborator)
            ->postJson(route('tasks.deleteRequest.store', $siblingTask), ['reason' => 'Forbidden'])
            ->assertForbidden();

        foreach ([
            $this->actingAs($collaborator)->postJson(route('mytasks.updateStatus', $siblingTask), ['job_status' => 2]),
            $this->actingAs($collaborator)->postJson(route('mytasks.updatePriority', $siblingTask), ['job_priority' => 3]),
            $this->actingAs($collaborator)->postJson(route('mytasks.updateDueDate', $siblingTask), ['job_due_at' => now()->addDays(2)->toDateString()]),
            $this->actingAs($collaborator)->deleteJson(route('mytasks.destroy', $siblingTask)),
        ] as $response) {
            $this->assertContains($response->status(), [403, 404]);
        }

        $this->assertSame('Read-only sibling', $siblingTask->fresh()->job_topic);
        $this->assertSame(2, (int) $siblingTask->fresh()->job_status);
        $this->assertSame(2, (int) $siblingTask->fresh()->job_priority);
        $this->assertSame(0, $siblingTask->images()->count());
    }

    public function test_assignee_creator_leader_and_admin_keep_comment_permission(): void
    {
        foreach (['user_id', 'created_by', 'leader_user_id'] as $roleColumn) {
            $owner = $this->user();
            $actor = $this->user();
            $task = $this->task($owner, $this->project($owner, 'Comment '.$roleColumn));
            $task->update([$roleColumn => $actor->id]);

            $this->actingAs($actor)
                ->postJson(route('tasks.comments.store', $task), ['message' => 'Comment as '.$roleColumn])
                ->assertCreated();
        }

        $owner = $this->user();
        $admin = User::factory()->create(['role' => 'admin', 'must_change_password' => false, 'is_active' => true]);
        $task = $this->task($owner, $this->project($owner, 'Admin comment'));

        $this->actingAs($admin)
            ->postJson(route('tasks.comments.store', $task), ['message' => 'Admin comment'])
            ->assertCreated();
    }

    public function test_direct_assignee_role_on_sibling_keeps_stronger_permission(): void
    {
        $owner = $this->user();
        $collaborator = $this->user();
        $project = $this->project($owner);
        $anchor = $this->task($owner, $project, 'Collaboration anchor');
        $siblingTask = $this->task($owner, $project, 'Assigned sibling');
        $anchor->collaborators()->attach($collaborator->id, ['status' => 'accepted']);
        $siblingTask->update(['user_id' => $collaborator->id]);

        $this->actingAs($collaborator)
            ->postJson(route('mytasks.updatePriority', $siblingTask), ['job_priority' => 3])
            ->assertOk();

        $this->assertSame(3, (int) $siblingTask->fresh()->job_priority);
    }

    public function test_project_context_siblings_do_not_contaminate_personal_report(): void
    {
        $owner = $this->user();
        $collaborator = $this->user();
        $project = $this->project($owner);
        $directTask = $this->task($owner, $project, 'Direct personal scope');
        $siblingTask = $this->task($owner, $project, 'Project context only');
        $directTask->collaborators()->attach($collaborator->id, ['status' => 'accepted']);

        $personalTaskIds = app(PersonalReportService::class)
            ->queryFor($collaborator->id)
            ->pluck('job_id');

        $this->assertTrue($personalTaskIds->contains($directTask->job_id));
        $this->assertFalse($personalTaskIds->contains($siblingTask->job_id));

        $this->actingAs($collaborator)->get(route('reports.my'))
            ->assertOk()
            ->assertSee($directTask->job_topic)
            ->assertDontSee($siblingTask->job_topic);
    }

    public function test_direct_accepted_collaborator_has_worker_actions_but_not_management_actions(): void
    {
        $owner = $this->user();
        $collaborator = $this->user();
        $project = $this->project($owner);
        $task = $this->task($owner, $project);
        $task->collaborators()->attach($collaborator->id, ['status' => 'accepted']);

        $this->assertTrue(Gate::forUser($collaborator)->allows('work', $task));
        $this->assertFalse(Gate::forUser($collaborator)->allows('update', $task));
        $this->assertFalse(Gate::forUser($collaborator)->allows('manageTeam', $task));
        $this->assertFalse(Gate::forUser($collaborator)->allows('deleteOwn', $task));

        $this->actingAs($collaborator)->patchJson(route('tasks.updateStatus', $task), [
            'job_status' => 2,
        ])->assertOk();
        $this->actingAs($collaborator)->patchJson(route('tasks.details.update', $task), ['job_topic' => 'Worker edit'])->assertOk();
        $this->actingAs($collaborator)->postJson(route('mytasks.updatePriority', $task), ['job_priority' => 3])->assertOk();
        $this->actingAs($collaborator)->postJson(route('mytasks.updateDueDate', $task), ['job_due_at' => now()->addDays(2)->toDateString()])->assertOk();
        $this->actingAs($collaborator)->patchJson(route('tasks.schedule.update', $task), [
            'job_start_at' => now()->addDay()->toDateString(),
            'job_due_at' => now()->addDays(3)->toDateString(),
        ])->assertOk();
        $this->actingAs($collaborator)->postJson(route('tasks.comments.store', $task), ['message' => 'Worker comment'])->assertCreated();

        Storage::fake('local');
        $png = UploadedFile::fake()->createWithContent(
            'proof.png',
            base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAusB9Y9ZQmcAAAAASUVORK5CYII=')
        );
        $this->actingAs($collaborator)->postJson(route('tasks.attachments.store', $task), [
            'completion_attachments' => [$png],
        ])->assertOk();
        $attachment = $task->images()->sole();
        $this->actingAs($collaborator)
            ->deleteJson(route('tasks.attachments.destroy', [$task, $attachment]))
            ->assertOk();

        $this->actingAs($collaborator)->postJson(route('tasks.collaborators.store', $task), ['collaborators' => [$this->user()->id]])->assertForbidden();
        $this->actingAs($collaborator)->postJson(route('tasks.deleteRequest.store', $task), ['reason' => 'No'])->assertForbidden();
        $this->actingAs($collaborator)->deleteJson(route('mytasks.destroy', $task))->assertForbidden();

        $this->actingAs($collaborator)->patchJson(route('tasks.updateStatus', $task), ['job_status' => 3])->assertOk();
        $this->actingAs($collaborator)->patchJson(route('tasks.updateStatus', $task), ['job_status' => 4])->assertForbidden();

        $this->assertSame(3, (int) $task->fresh()->job_status);
        $this->assertSame(3, (int) $task->fresh()->job_priority);
        $this->assertSame('Worker edit', $task->fresh()->job_topic);
        $this->assertSame(0, $task->images()->count());
    }

    public function test_direct_task_role_keeps_stronger_permission_even_when_user_is_also_a_collaborator(): void
    {
        $owner = $this->user();
        $project = $this->project($owner);
        $task = $this->task($owner, $project);
        $task->collaborators()->attach($owner->id, ['status' => 'accepted']);

        $this->actingAs($owner)
            ->patchJson(route('tasks.details.update', $task), ['job_topic' => 'Owner update'])
            ->assertOk();
    }

    public function test_assignee_creator_and_leader_each_keep_editor_permission(): void
    {
        foreach (['user_id', 'created_by', 'leader_user_id'] as $roleColumn) {
            $projectOwner = $this->user();
            $actor = $this->user();
            $project = $this->project($projectOwner, 'Direct role '.$roleColumn);
            $task = $this->task($projectOwner, $project, 'Direct role task '.$roleColumn);
            $task->update([$roleColumn => $actor->id]);
            $task->collaborators()->attach($actor->id, ['status' => 'accepted']);

            $this->actingAs($actor)
                ->postJson(route('mytasks.updatePriority', $task), ['job_priority' => 3])
                ->assertOk();
            $this->assertSame(3, (int) $task->fresh()->job_priority);
        }
    }

    public function test_collaborator_pivot_defaults_to_pending_instead_of_granting_silent_access(): void
    {
        $owner = $this->user();
        $candidate = $this->user();
        $project = $this->project($owner);
        $task = $this->task($owner, $project);

        DB::table('work_order_collaborators')->insert([
            'work_order_id' => $task->job_id,
            'user_id' => $candidate->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->assertDatabaseHas('work_order_collaborators', [
            'work_order_id' => $task->job_id,
            'user_id' => $candidate->id,
            'status' => 'pending',
        ]);
        $this->actingAs($candidate)->get(route('mytasks.quickview.task', $task))->assertForbidden();
    }

    private function user(): User
    {
        return User::factory()->create(['role' => 'user', 'must_change_password' => false, 'is_active' => true]);
    }

    private function project(User $owner, string $name = 'Permission project'): WorkOrderList
    {
        return WorkOrderList::create(['user_id' => $owner->id, 'name' => $name, 'is_visible' => true, 'sort_order' => 1]);
    }

    private function task(User $owner, WorkOrderList $project, string $topic = 'Permission task'): WorkOrder
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
            'job_start_at' => now(),
            'job_due_at' => now()->addDay(),
        ]);
    }

    /** @return array<int|string, array<string, mixed>> */
    private function taskManagementFor(User $user): array
    {
        $content = $this->actingAs($user)
            ->get(route('mytasks.index', ['view' => 'board']))
            ->assertOk()
            ->getContent();

        $this->assertMatchesRegularExpression('/<script type="application\/json" data-task-management-data>.*?<\/script>/s', $content);
        preg_match('/<script type="application\/json" data-task-management-data>(.*?)<\/script>/s', $content, $matches);

        return json_decode($matches[1], true, flags: JSON_THROW_ON_ERROR);
    }

    private function tableRowMarkup(string $content, int $taskId): string
    {
        $rowPrefix = '<div class="notion-row" data-row data-id="';
        $start = strpos($content, $rowPrefix.$taskId.'"');
        $this->assertNotFalse($start, 'Task row was not rendered.');
        $next = strpos($content, $rowPrefix, $start + strlen($rowPrefix));

        return substr($content, $start, $next === false ? null : $next - $start);
    }
}
