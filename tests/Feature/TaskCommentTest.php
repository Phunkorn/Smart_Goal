<?php

namespace Tests\Feature;

use App\Models\SystemNotification;
use App\Models\Department;
use App\Models\User;
use App\Models\WorkOrder;
use App\Models\WorkOrderCommentRead;
use App\Models\WorkOrderUpdate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TaskCommentTest extends TestCase
{
    use RefreshDatabase;

    public function test_participants_can_comment_without_changing_task_or_creating_activity(): void
    {
        $assignee = $this->user();
        $creator = $this->user('admin');
        $collaborator = $this->user();
        $task = $this->task($assignee, $creator);
        $task->collaborators()->attach($collaborator->id, ['status' => 'accepted', 'added_by' => $creator->id]);

        foreach ([$assignee, $creator, $collaborator] as $index => $author) {
            $this->actingAs($author)->postJson(route('tasks.comments.store', $task), [
                'message' => 'comment '.($index + 1),
            ])->assertCreated()->assertJsonPath('comment.author', $author->name);
        }

        $task->refresh();
        $this->assertSame(2, $task->job_status);
        $this->assertCount(3, $task->updates);
        $this->assertTrue($task->updates->every(fn ($update) => $update->is_comment));
        $this->assertDatabaseCount('activity_logs', 0);
    }

    public function test_pending_collaborator_viewer_and_unrelated_user_cannot_comment(): void
    {
        $assignee = $this->user();
        $task = $this->task($assignee, $assignee);
        $pending = $this->user();
        $task->collaborators()->attach($pending->id, ['status' => 'pending', 'added_by' => $assignee->id]);

        foreach ([$pending, $this->user('viewer'), $this->user()] as $user) {
            $this->actingAs($user)->postJson(route('tasks.comments.store', $task), ['message' => 'blocked'])
                ->assertForbidden();
        }
        $this->assertDatabaseCount('work_order_updates', 0);
    }

    public function test_notifications_are_deduplicated_exclude_author_and_include_all_admins(): void
    {
        $author = $this->user();
        $creatorAdmin = $this->user('admin');
        $collaborator = $this->user();
        $unrelatedAdmin = $this->user('admin');
        $task = $this->task($author, $creatorAdmin);
        $task->forceFill(['leader_user_id' => $author->id])->save();
        $task->collaborators()->attach($collaborator->id, ['status' => 'accepted', 'added_by' => $creatorAdmin->id]);

        $response = $this->actingAs($author)->postJson(route('tasks.comments.store', $task), ['message' => 'hello'])
            ->assertCreated();
        $commentId = $response->json('comment.id');

        $this->assertEqualsCanonicalizing([$creatorAdmin->id, $collaborator->id, $unrelatedAdmin->id], SystemNotification::pluck('user_id')->all());
        $this->assertDatabaseMissing('system_notifications', ['user_id' => $author->id]);
        $this->assertDatabaseHas('system_notifications', ['user_id' => $unrelatedAdmin->id]);
        $notice = SystemNotification::first();
        $this->assertSame('task_comment', $notice->type);
        $this->assertSame($commentId, data_get($notice->data, 'comment_id'));
    }

    public function test_unread_state_is_per_user_excludes_legacy_updates_and_syncs_notifications(): void
    {
        $author = $this->user();
        $reader = $this->user();
        $otherReader = $this->user();
        $task = $this->task($reader, $reader);
        $task->collaborators()->attach([$author->id => ['status' => 'accepted', 'added_by' => $reader->id], $otherReader->id => ['status' => 'accepted', 'added_by' => $reader->id]]);
        WorkOrderUpdate::create(['work_order_id' => $task->job_id, 'user_id' => $author->id, 'note' => 'legacy']);

        $this->actingAs($author)->postJson(route('tasks.comments.store', $task), ['message' => 'first'])->assertCreated();
        $this->assertDatabaseCount('work_order_comment_reads', 1);
        $this->assertDatabaseHas('work_order_comment_reads', [
            'work_order_id' => $task->job_id,
            'user_id' => $author->id,
            'last_read_update_id' => WorkOrderUpdate::where('is_comment', true)->max('id'),
        ]);

        $this->actingAs($reader)->postJson(route('tasks.comments.read', $task))->assertOk();
        $receipt = WorkOrderCommentRead::where(['work_order_id' => $task->job_id, 'user_id' => $reader->id])->firstOrFail();
        $this->assertSame(WorkOrderUpdate::where('is_comment', true)->max('id'), $receipt->last_read_update_id);
        $this->assertNotNull(SystemNotification::where('user_id', $reader->id)->firstOrFail()->read_at);
        $this->assertNull(SystemNotification::where('user_id', $otherReader->id)->firstOrFail()->read_at);

        $this->actingAs($author)->postJson(route('tasks.comments.store', $task), ['message' => 'second'])->assertCreated();
        $this->assertSame(1, app(\App\Services\TaskCommentService::class)->unreadCounts(collect([$task->job_id]), $reader)->get($task->job_id));
        $this->assertSame(2, app(\App\Services\TaskCommentService::class)->unreadCounts(collect([$task->job_id]), $otherReader)->get($task->job_id));
    }

    public function test_comment_validation_and_read_visibility_are_enforced(): void
    {
        $owner = $this->user();
        $task = $this->task($owner, $owner);
        $stranger = $this->user();

        $this->actingAs($owner)->postJson(route('tasks.comments.store', $task), ['message' => '   '])->assertUnprocessable();
        $this->actingAs($owner)->postJson(route('tasks.comments.store', $task), ['message' => str_repeat('x', 2001)])->assertUnprocessable();
        $this->actingAs($stranger)->postJson(route('tasks.comments.read', $task))->assertForbidden();
    }

    public function test_user_and_admin_cards_render_the_same_viewer_specific_unread_badge(): void
    {
        $department = Department::create(['department_name' => 'Comments']);
        $admin = $this->user('admin');
        $member = $this->user();
        $author = $this->user();
        $member->update(['department_id' => $department->id]);
        $task = $this->task($member, $admin);
        $task->update(['department_id' => $department->id]);
        $task->collaborators()->attach($author->id, ['status' => 'accepted', 'added_by' => $admin->id]);
        $this->actingAs($author)->postJson(route('tasks.comments.store', $task), ['message' => 'shared unread'])->assertCreated();

        // ช่องคอมเมนต์บนบอร์ดมีอยู่ทุกแถวเสมอ สถานะยังไม่ได้อ่านจึงอยู่ที่คลาส has-unread ของช่องนั้น
        $unread = 'class="board-comments has-comments has-unread" data-open-task-modal data-task-id="'.$task->job_id.'"';
        $read = 'class="board-comments has-comments" data-open-task-modal data-task-id="'.$task->job_id.'"';

        $this->actingAs($member)->get(route('mytasks.index'))
            ->assertOk()->assertSee($unread, false);
        $this->actingAs($admin)->get(route('admin.work-board.member', [$department, $member]))
            ->assertOk()->assertSee($unread, false);

        $this->actingAs($member)->postJson(route('tasks.comments.read', $task))->assertOk();
        // อ่านแล้วต้องหายเฉพาะสถานะ unread ส่วนช่องและจำนวนรวมยังต้องอยู่ครบ
        $this->actingAs($member)->get(route('mytasks.index'))
            ->assertOk()->assertDontSee($unread, false)->assertSee($read, false);
        $this->actingAs($admin)->get(route('admin.work-board.member', [$department, $member]))
            ->assertOk()->assertSee($unread, false);
    }

    private function user(string $role = 'user'): User
    {
        return User::factory()->create(['role' => $role, 'must_change_password' => false, 'is_active' => true]);
    }

    private function task(User $assignee, User $creator): WorkOrder
    {
        return WorkOrder::create([
            'user_id' => $assignee->id,
            'created_by' => $creator->id,
            'leader_user_id' => $creator->id,
            'job_topic' => 'Collaborative task',
            'job_priority' => 2,
            'job_status' => 2,
            'approval_status' => 'approved',
            'job_start_at' => now()->subDay(),
            'job_due_at' => now()->addDay(),
        ]);
    }
}
