<?php

namespace Tests\Feature;

use App\Models\Department;
use App\Models\User;
use App\Models\WorkOrder;
use App\Models\WorkOrderList;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TaskCommentReadReceiptTest extends TestCase
{
    use RefreshDatabase;

    private ?Department $sharedDepartment = null;

    public function test_history_reconciles_for_readers_without_notifications_and_survives_reload(): void
    {
        $owner = $this->user();
        $member = $this->user();
        $admin = $this->user('admin');
        $project = $this->project($owner);
        $direct = $this->task($owner, $owner, $project);
        $sibling = $this->task($owner, $owner, $project);
        $direct->collaborators()->attach($member->id, ['added_by' => $owner->id, 'status' => 'accepted']);
        $parentComment = $this->actingAs($owner)->postJson(route('tasks.comments.store', $sibling), ['message' => 'Parent history'])
            ->assertCreated()->json('comment');
        $child = $this->task($owner, $owner, $project);
        $child->update(['parent_job_id' => $sibling->job_id]);
        $childComment = $this->actingAs($owner)->postJson(route('tasks.comments.store', $child), ['message' => 'Child history'])
            ->assertCreated()->json('comment');
        $this->assertDatabaseMissing('system_notifications', ['user_id' => $member->id, 'work_order_id' => $sibling->job_id]);
        foreach ([$member, $admin] as $reader) {
            $this->actingAs($reader)->getJson(route('tasks.comments.index', $sibling))->assertOk()
                ->assertJsonCount(1, 'comments')->assertJsonPath('comments.0.id', $parentComment['id'])
                ->assertHeader('Cache-Control', 'no-store, private');
        }
        $this->actingAs($owner)->getJson(route('tasks.comments.index', $child))->assertOk()
            ->assertJsonCount(1, 'comments')->assertJsonPath('comments.0.id', $childComment['id']);
        $this->actingAs($member)->getJson(route('tasks.comments.index', $sibling))->assertOk()
            ->assertJsonCount(1, 'comments')->assertJsonPath('comments.0.id', $parentComment['id']);
        foreach ([$this->user(), $this->user('viewer')] as $denied) {
            $this->actingAs($denied)->getJson(route('tasks.comments.index', $sibling))->assertForbidden();
        }
    }

    public function test_read_boundary_is_required_task_scoped_monotonic_and_preserves_new_notifications(): void
    {
        $owner = $this->user();
        $reader = $this->user();
        $task = $this->task($reader, $owner);
        $other = $this->task($owner, $owner);
        $first = $this->actingAs($owner)->postJson(route('tasks.comments.store', $task), ['message' => 'First'])->json('comment.id');
        $second = $this->actingAs($owner)->postJson(route('tasks.comments.store', $task), ['message' => 'Second'])->json('comment.id');
        $foreign = $this->actingAs($owner)->postJson(route('tasks.comments.store', $other), ['message' => 'Other'])->json('comment.id');
        $url = route('tasks.comments.read', $task);
        $this->actingAs($reader)->postJson($url)->assertUnprocessable();
        $this->postJson($url, ['latest_comment_id' => $foreign])->assertUnprocessable();
        $this->postJson($url, ['latest_comment_id' => 999999])->assertUnprocessable();
        $this->postJson($url, ['latest_comment_id' => 0])->assertOk()->assertJsonPath('unread_comments', 2);
        $this->postJson($url, ['latest_comment_id' => $first])->assertOk()
            ->assertJsonPath('latest_comment_id', $first)->assertJsonPath('unread_comments', 1);
        $this->assertDatabaseHas('system_notifications', ['user_id' => $reader->id, 'work_order_id' => $task->job_id, 'read_at' => null]);
        $this->postJson($url, ['latest_comment_id' => $second])->assertOk()->assertJsonPath('unread_comments', 0);
        $this->postJson($url, ['latest_comment_id' => $first])->assertOk()->assertJsonPath('latest_comment_id', $second);
    }

    public function test_history_uses_presenter_for_images_reply_mentions_pin_and_receipts(): void
    {
        $owner = $this->user();
        $reader = $this->user();
        $task = $this->task($reader, $owner);
        $first = $this->actingAs($owner)->postJson(route('tasks.comments.store', $task), ['message' => 'Image source'])->json('comment.id');
        $comment = \App\Models\WorkOrderUpdate::findOrFail($first);
        $comment->attachments()->create(['file_path' => 'comment-attachments/'.$task->job_id.'/test.png', 'original_name' => 'test.png', 'file_type' => 'png', 'uploaded_by' => $owner->id]);
        $reply = $this->postJson(route('tasks.comments.store', $task), ['message' => '@'.$reader->name, 'reply_to_id' => $first, 'mentions' => [$reader->id]])
            ->assertCreated()->json('comment.id');
        $this->postJson(route('tasks.comments.pin', [$task, $reply]))->assertOk();
        $this->actingAs($reader)->postJson(route('tasks.comments.read', $task), ['latest_comment_id' => $reply])->assertOk();
        $history = $this->actingAs($owner)->getJson(route('tasks.comments.index', $task))->assertOk()->json('comments');
        $this->assertSame($reply, $history[0]['id']);
        $this->assertTrue($history[0]['pinned']);
        $this->assertSame($reader->id, $history[0]['mentions'][0]['id']);
        $this->assertSame($reader->id, $history[0]['readers'][0]['id']);
        $this->assertCount(1, $history[0]['reply_to']['images']);
        $this->assertCount(1, $history[1]['images']);
    }

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
            ->postJson(route('tasks.comments.read', $task), ['latest_comment_id' => (int) $task->updates()->where('is_comment', true)->max('id')])
            ->assertOk()
            ->json('receipts');

        $this->assertSame($member->id, $memberRead[(string) $adminComment['id']][0]['id']);

        $memberComment = $this->actingAs($member)
            ->postJson(route('tasks.comments.store', $task), ['message' => 'Member reply'])
            ->assertCreated()
            ->json('comment');

        $adminRead = $this->actingAs($admin)
            ->postJson(route('tasks.comments.read', $task), ['latest_comment_id' => (int) $task->updates()->where('is_comment', true)->max('id')])
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
            ->postJson(route('tasks.comments.read', $siblingTask), ['latest_comment_id' => (int) $siblingTask->updates()->where('is_comment', true)->max('id')])
            ->assertOk();
        $this->actingAs($member)
            ->postJson(route('tasks.comments.store', $siblingTask), ['message' => 'Not a direct participant'])
            ->assertForbidden();
        $this->actingAs($outsider)
            ->postJson(route('tasks.comments.read', $siblingTask), ['latest_comment_id' => (int) $siblingTask->updates()->where('is_comment', true)->max('id')])
            ->assertForbidden();
        $this->actingAs($viewer)
            ->postJson(route('tasks.comments.read', $siblingTask), ['latest_comment_id' => (int) $siblingTask->updates()->where('is_comment', true)->max('id')])
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

        $this->actingAs($admin)->postJson(route('tasks.comments.read', $task), ['latest_comment_id' => (int) $task->updates()->where('is_comment', true)->max('id')])->assertOk();

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

    /**
     * ผู้ใช้ในแผนกเดียวกันเป็นค่าเริ่มต้น
     *
     * สิทธิ์ระดับโปรเจกต์ผูกกับแผนกแล้ว (คนต่างแผนกที่ถูกเชิญมาช่วยงานหนึ่งใบ
     * จะไม่เห็นงานพี่น้องในโปรเจกต์นั้น) การไม่ระบุแผนกจะทำให้ทุกคนในไฟล์นี้
     * กลายเป็นคนละแผนกกันโดยไม่ได้ตั้งใจ ซึ่งไม่ใช่สถานการณ์ที่ไฟล์นี้ตรวจ
     */
    private function user(string $role = 'user'): User
    {
        return User::factory()->create([
            'role' => $role,
            'must_change_password' => false,
            'is_active' => true,
            'department_id' => $this->sharedDepartment()->id,
        ]);
    }

    /** แผนกร่วมของไฟล์นี้ สร้างครั้งเดียวแล้วใช้ซ้ำ */
    private function sharedDepartment(): Department
    {
        return $this->sharedDepartment ??= Department::create(['department_name' => 'แผนกร่วม']);
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
