<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\WorkOrder;
use App\Models\WorkOrderList;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TaskCommentReadReceiptTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_and_user_share_comments_read_receipts_and_bangkok_time(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-09-01 06:26:00', 'UTC'));

        $admin = $this->user('admin');
        $member = $this->user();
        $owner = $this->user();
        $task = $this->task($member, $owner);

        $adminComment = $this->actingAs($admin)
            ->postJson(route('tasks.comments.store', $task), ['message' => 'Admin reply'])
            ->assertCreated()
            ->assertJsonPath('comment.author_id', $admin->id)
            ->assertJsonPath('comment.is_mine', true)
            ->json('comment');

        $this->assertStringEndsWith('13:26', $adminComment['at']);

        $memberRead = $this->actingAs($member)
            ->postJson(route('tasks.comments.read', $task))
            ->assertOk()
            ->json('receipts');

        $this->assertSame($member->id, $memberRead[(string) $adminComment['id']][0]['id']);

        $memberComment = $this->actingAs($member)
            ->postJson(route('tasks.comments.store', $task), ['message' => 'Member reply'])
            ->assertCreated()
            ->json('comment');

        $adminRead = $this->actingAs($admin)
            ->postJson(route('tasks.comments.read', $task))
            ->assertOk()
            ->json('receipts');

        $this->assertSame($admin->id, $adminRead[(string) $memberComment['id']][0]['id']);
        $this->assertDatabaseHas('work_order_comment_reads', [
            'work_order_id' => $task->job_id,
            'user_id' => $admin->id,
            'last_read_update_id' => $memberComment['id'],
        ]);
    }

    public function test_project_member_can_read_sibling_comments_but_outsiders_and_viewers_cannot(): void
    {
        $owner = $this->user();
        $member = $this->user();
        $outsider = $this->user();
        $viewer = $this->user('viewer');
        $list = $this->project($owner);
        $directTask = $this->task($owner, $owner, $list);
        $siblingTask = $this->task($owner, $owner, $list);
        $directTask->collaborators()->attach($member->id, [
            'added_by' => $owner->id,
            'status' => 'accepted',
            'responded_at' => now(),
        ]);

        $this->actingAs($owner)
            ->postJson(route('tasks.comments.store', $siblingTask), ['message' => 'Project-only message'])
            ->assertCreated();

        $this->actingAs($member)
            ->postJson(route('tasks.comments.read', $siblingTask))
            ->assertOk();
        $this->actingAs($member)
            ->postJson(route('tasks.comments.store', $siblingTask), ['message' => 'Not a direct participant'])
            ->assertForbidden();
        $this->actingAs($outsider)
            ->postJson(route('tasks.comments.read', $siblingTask))
            ->assertForbidden();
        $this->actingAs($viewer)
            ->postJson(route('tasks.comments.read', $siblingTask))
            ->assertForbidden();
    }

    public function test_realtime_poll_returns_receipts_only_for_an_authorized_open_task(): void
    {
        $admin = $this->user('admin');
        $member = $this->user();
        $outsider = $this->user();
        $task = $this->task($member, $member);
        $comment = $this->actingAs($member)
            ->postJson(route('tasks.comments.store', $task), ['message' => 'Live receipt'])
            ->assertCreated()
            ->json('comment');

        $this->actingAs($admin)->postJson(route('tasks.comments.read', $task))->assertOk();

        $this->actingAs($member)
            ->getJson(route('realtime.sync', ['after' => 0, 'task_id' => $task->job_id]))
            ->assertOk()
            ->assertJsonPath('comment_receipts.task_id', $task->job_id)
            ->assertJsonPath('comment_receipts.receipts.'.$comment['id'].'.0.id', $admin->id);

        $this->actingAs($outsider)
            ->getJson(route('realtime.sync', ['after' => 0, 'task_id' => $task->job_id]))
            ->assertOk()
            ->assertJsonPath('comment_receipts', null);
    }

    private function user(string $role = 'user'): User
    {
        return User::factory()->create([
            'role' => $role,
            'must_change_password' => false,
            'is_active' => true,
        ]);
    }

    private function project(User $owner): WorkOrderList
    {
        return WorkOrderList::create([
            'user_id' => $owner->id,
            'name' => 'Comment project',
            'is_visible' => true,
            'sort_order' => 1,
        ]);
    }

    private function task(User $assignee, User $creator, ?WorkOrderList $project = null): WorkOrder
    {
        return WorkOrder::create([
            'user_id' => $assignee->id,
            'created_by' => $creator->id,
            'assigned_by' => $creator->id,
            'leader_user_id' => $creator->id,
            'work_order_list_id' => $project?->id,
            'job_topic' => 'Comment task '.WorkOrder::count(),
            'job_priority' => 2,
            'job_status' => 2,
            'approval_status' => 'approved',
            'job_start_at' => now()->subDay(),
            'job_due_at' => now()->addDay(),
        ]);
    }
}
