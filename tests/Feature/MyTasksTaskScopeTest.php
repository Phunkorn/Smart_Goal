<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\WorkOrder;
use App\Models\WorkOrderList;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MyTasksTaskScopeTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_task_scopes_only_narrow_the_accessible_workspace(): void
    {
        $actor = $this->user();
        $assignee = $this->user();
        $other = $this->user();
        $list = $this->list($actor);

        $delegated = $this->task($assignee, $actor, $list, 'Delegated by actor');
        $selfAssigned = $this->task($actor, $actor, $list, 'Self assigned');
        $responsible = $this->task($actor, $other, $list, 'Assigned to actor');
        $createdButAssignedByOther = $this->task($assignee, $actor, $list, 'Created but assigned by other', [
            'assigned_by' => $other->id,
        ]);
        $legacyWithoutAssigner = $this->task($assignee, $actor, $list, 'Legacy without assigner', [
            'assigned_by' => null,
        ]);
        $collaborating = $this->task($other, $other, $list, 'Accepted collaboration');
        $collaborating->collaborators()->attach($actor->id, [
            'added_by' => $other->id,
            'status' => 'accepted',
            'responded_at' => now(),
        ]);
        $pending = $this->task($other, $other, $list, 'Pending collaboration');
        $pending->collaborators()->attach($actor->id, [
            'added_by' => $other->id,
            'status' => 'pending',
        ]);
        $unrelated = $this->task($assignee, $other, $this->list($other), 'Unrelated task');

        $expected = [
            'all' => [$delegated->job_id, $selfAssigned->job_id, $responsible->job_id, $createdButAssignedByOther->job_id, $legacyWithoutAssigner->job_id, $collaborating->job_id, $pending->job_id],
            'responsible' => [$selfAssigned->job_id, $responsible->job_id],
            'created' => [$delegated->job_id, $selfAssigned->job_id, $createdButAssignedByOther->job_id, $legacyWithoutAssigner->job_id],
            'assigned_by_me' => [$delegated->job_id],
            'collaborating' => [$collaborating->job_id],
        ];

        foreach ($expected as $scope => $taskIds) {
            $response = $this->actingAs($actor)
                ->get(route('mytasks.index', ['task_scope' => $scope]))
                ->assertOk()
                ->assertViewHas('taskScope', $scope);

            $this->assertEqualsCanonicalizing($taskIds, $this->workspaceTaskIds($response));
            $this->assertContains($delegated->job_id, $response->viewData('calendarTasks')->pluck('job_id')->all());
            $this->assertContains($pending->job_id, $response->viewData('calendarTasks')->pluck('job_id')->all());
            $this->assertNotContains($unrelated->job_id, $response->viewData('calendarTasks')->pluck('job_id')->all());
        }

        $invalid = $this->actingAs($actor)
            ->get(route('mytasks.index', ['task_scope' => 'someone_else', 'user_id' => $assignee->id]))
            ->assertOk()
            ->assertViewHas('taskScope', 'all');

        $this->assertEqualsCanonicalizing($expected['all'], $this->workspaceTaskIds($invalid));
        $this->assertNotContains($unrelated->job_id, $invalid->viewData('calendarTasks')->pluck('job_id')->all());

        $this->actingAs($assignee)
            ->get(route('mytasks.index'))
            ->assertOk()
            ->assertViewHas('activeTasks', fn ($tasks) => $tasks->contains('job_id', $delegated->job_id));
    }

    public function test_assigned_scope_keeps_completed_grouping_and_full_calendar_modal_source(): void
    {
        $actor = $this->user();
        $assignee = $this->user();
        $list = $this->list($actor);
        $active = $this->task($assignee, $actor, $list, 'Active delegated');
        $completed = $this->task($assignee, $actor, $list, 'Completed delegated', [
            'job_status' => 4,
            'job_completed_at' => now(),
        ]);
        $calendarOnlyList = $this->list($actor);
        $selfAssigned = $this->task($actor, $actor, $calendarOnlyList, 'Calendar only self task');

        $response = $this->actingAs($actor)
            ->get(route('mytasks.index', ['task_scope' => 'assigned_by_me']))
            ->assertOk()
            ->assertViewHas('activeTasks', fn ($tasks) => $tasks->pluck('job_id')->all() === [$active->job_id])
            ->assertViewHas('completedTasks', fn ($tasks) => $tasks->pluck('job_id')->all() === [$completed->job_id])
            ->assertViewHas('todayTasks', fn ($tasks) => $tasks->pluck('job_id')->contains($active->job_id))
            ->assertViewHas('workspaceTaskLists', fn ($lists) => $lists->contains('id', $list->id)
                && ! $lists->contains('id', $calendarOnlyList->id))
            ->assertSee($selfAssigned->job_topic)
            ->assertSee('data-completed-group', false);

        $calendarIds = $response->viewData('calendarTasks')->pluck('job_id')->all();
        $this->assertContains($active->job_id, $calendarIds);
        $this->assertContains($completed->job_id, $calendarIds);
        $this->assertContains($selfAssigned->job_id, $calendarIds);
    }

    public function test_assigned_scope_reuses_viewer_specific_unread_badges_in_both_views(): void
    {
        $actor = $this->user();
        $assignee = $this->user();
        $task = $this->task($assignee, $actor, $this->list($actor), 'Delegated discussion');

        $this->actingAs($assignee)
            ->postJson(route('tasks.comments.store', $task), ['message' => 'Please review'])
            ->assertCreated();

        $response = $this->actingAs($actor)
            ->get(route('mytasks.index', ['task_scope' => 'assigned_by_me']))
            ->assertOk();

        // บอร์ดและ Kanban ต้องบอกสถานะยังไม่ได้อ่านของผู้ชมคนเดียวกันตรงกัน
        $boardUnread = 'class="board-comments has-comments has-unread" data-open-task-modal data-task-id="'.$task->job_id.'"';
        $kanbanUnread = 'class="mytasks-kanban__comments" data-unread-comments="'.$task->job_id.'"';
        $this->assertStringContainsString($boardUnread, $response->getContent());
        $this->assertStringContainsString($kanbanUnread, $response->getContent());

        $this->actingAs($actor)->postJson(route('tasks.comments.read', $task))->assertOk();
        $content = $this->actingAs($actor)
            ->get(route('mytasks.index', ['task_scope' => 'assigned_by_me']))
            ->assertOk()
            ->getContent();

        $this->assertStringNotContainsString($boardUnread, $content);
        $this->assertStringNotContainsString($kanbanUnread, $content);
        // ช่องคอมเมนต์ของบอร์ดเป็นคอลัมน์หนึ่งของกริด จึงต้องคงอยู่พร้อมจำนวนรวมเดิม
        $this->assertStringContainsString('class="board-comments has-comments" data-open-task-modal data-task-id="'.$task->job_id.'"', $content);
    }

    public function test_viewer_is_still_redirected_and_admin_member_markup_has_no_scope_control(): void
    {
        $viewer = $this->user('viewer');

        $this->actingAs($viewer)
            ->get(route('mytasks.index', ['task_scope' => 'assigned_by_me']))
            ->assertRedirect(route('board.index'));

        $admin = $this->user('admin');
        $adminResponse = $this->actingAs($admin)
            ->get(route('mytasks.index', ['task_scope' => 'assigned_by_me']))
            ->assertOk()
            ->assertDontSee('data-task-scope-control', false);

        $this->assertSame('all', $adminResponse->viewData('taskScope'));
    }

    private function workspaceTaskIds($response): array
    {
        return $response->viewData('activeTasks')
            ->merge($response->viewData('completedTasks'))
            ->pluck('job_id')
            ->all();
    }

    private function user(string $role = 'user'): User
    {
        return User::factory()->create([
            'role' => $role,
            'must_change_password' => false,
            'is_active' => true,
        ]);
    }

    private function list(User $owner): WorkOrderList
    {
        return WorkOrderList::create([
            'user_id' => $owner->id,
            'name' => 'Scope project '.$owner->id.'-'.WorkOrderList::count(),
            'is_visible' => true,
            'sort_order' => WorkOrderList::count() + 1,
        ]);
    }

    private function task(User $assignee, User $creator, WorkOrderList $list, string $topic, array $overrides = []): WorkOrder
    {
        return WorkOrder::create(array_merge([
            'user_id' => $assignee->id,
            'created_by' => $creator->id,
            'assigned_by' => $creator->id,
            'leader_user_id' => $creator->id,
            'work_order_list_id' => $list->id,
            'job_topic' => $topic,
            'job_priority' => 2,
            'job_status' => 2,
            'approval_status' => 'approved',
            'job_start_at' => now()->subDay(),
            'job_due_at' => now()->addDay(),
        ], $overrides));
    }
}
