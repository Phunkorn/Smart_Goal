<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\Department;
use App\Models\User;
use App\Models\WorkOrder;
use App\Models\WorkOrderCommentRead;
use App\Models\WorkOrderList;
use App\Models\WorkOrderUpdateAttachment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ไทม์ไลน์ของงาน (อัปเดต + กิจกรรม) ถูกขอทีละงานเมื่อเปิดโมดัล แทนการฝังของทุกงานไว้ในหน้า
 *
 * การย้ายครั้งนี้เป็นเรื่องความเร็วล้วน ๆ ข้อมูลที่ผู้ใช้เห็นและขอบเขตสิทธิ์ต้องเหมือนตอนที่ยังฝังในหน้า
 */
class TaskTimelineEndpointTest extends TestCase
{
    use RefreshDatabase;

    private ?Department $department = null;

    public function test_participant_gets_every_update_and_activity_with_the_same_payload_as_before(): void
    {
        $actor = $this->user();
        $member = $this->user();
        $task = $this->task($member, $actor);

        $original = $task->updates()->create(['user_id' => $member->id, 'note' => 'Original with photo', 'is_comment' => true]);
        $photo = WorkOrderUpdateAttachment::create(['work_order_update_id' => $original->id, 'file_path' => 'comments/a.jpg', 'original_name' => 'a.jpg', 'file_type' => 'image/jpeg', 'byte_size' => 10, 'uploaded_by' => $member->id]);
        $reply = $task->updates()->create(['user_id' => $actor->id, 'note' => '@'.$member->name.' reply', 'is_comment' => true, 'reply_to_id' => $original->id, 'pinned_at' => now(), 'pinned_by' => $actor->id]);
        $reply->mentions()->attach($member->id);
        // อัปเดตแบบเก่าก่อนมีระบบคอมเมนต์ ยังต้องแสดงในแท็บ "อัปเดต" เหมือนเดิม
        $legacy = $task->updates()->create(['user_id' => $member->id, 'note' => 'Legacy progress note', 'is_comment' => false]);
        WorkOrderCommentRead::create(['work_order_id' => $task->job_id, 'user_id' => $member->id, 'last_read_update_id' => $reply->id]);
        ActivityLog::create(['user_id' => $actor->id, 'action' => 'updated', 'subject_type' => WorkOrder::class, 'subject_id' => $task->job_id, 'description' => 'เปลี่ยนสถานะงาน', 'created_at' => now()]);

        $response = $this->actingAs($actor)->getJson(route('tasks.timeline', $task))
            ->assertOk()
            ->assertHeader('Cache-Control', 'no-store, private')
            ->assertJsonPath('task_id', $task->job_id);

        $updates = collect($response->json('updates'))->keyBy('id');
        $this->assertEqualsCanonicalizing([$original->id, $reply->id, $legacy->id], $updates->keys()->all());
        $this->assertFalse($updates[$legacy->id]['is_comment']);
        $this->assertSame('Legacy progress note', $updates[$legacy->id]['note']);
        $this->assertSame([['url' => route('media.comment-attachments.show', $photo), 'name' => 'a.jpg']], $updates[$original->id]['images']);
        $this->assertSame($original->id, $updates[$reply->id]['reply_to']['id']);
        $this->assertSame([['url' => route('media.comment-attachments.show', $photo), 'name' => 'a.jpg']], $updates[$reply->id]['reply_to']['images']);
        $this->assertSame([['id' => $member->id, 'name' => $member->name]], $updates[$reply->id]['mentions']);
        $this->assertTrue($updates[$reply->id]['pinned']);
        $this->assertTrue($updates[$reply->id]['is_mine']);
        $this->assertSame([$member->id], array_column($updates[$reply->id]['readers'], 'id'), 'Read receipts stay attached to the right comment.');
        $this->assertSame([['author' => $actor->name, 'avatar_url' => route('media.profile', $actor), 'note' => 'เปลี่ยนสถานะงาน', 'at' => $response->json('activity.0.at'), 'is_mine' => true]], $response->json('activity'));
        $this->assertNotNull($response->json('activity.0.at'));
    }

    public function test_admin_overseeing_the_task_gets_the_timeline_too(): void
    {
        $admin = $this->user('admin');
        $member = $this->user();
        $task = $this->task($member, $member);
        $task->updates()->create(['user_id' => $member->id, 'note' => 'Member comment', 'is_comment' => true]);

        $this->actingAs($admin)->getJson(route('tasks.timeline', $task))
            ->assertOk()
            ->assertJsonPath('updates.0.note', 'Member comment');
    }

    public function test_comments_stay_hidden_without_view_comments_while_legacy_updates_and_activity_remain(): void
    {
        $requester = $this->user();
        $member = $this->user();
        // งานที่ยังรออนุมัติ ผู้ขอเห็นงานได้ (view) แต่ยังอ่านคอมเมนต์ไม่ได้ (viewComments)
        $task = $this->task($member, $requester, ['approval_status' => 'pending']);
        $task->updates()->create(['user_id' => $member->id, 'note' => 'Hidden comment', 'is_comment' => true]);
        $task->updates()->create(['user_id' => $member->id, 'note' => 'Visible legacy', 'is_comment' => false]);
        ActivityLog::create(['user_id' => $requester->id, 'action' => 'created', 'subject_type' => WorkOrder::class, 'subject_id' => $task->job_id, 'description' => 'สร้างงาน', 'created_at' => now()]);

        $this->assertTrue($requester->can('view', $task));
        $this->assertFalse($requester->can('viewComments', $task));

        $response = $this->actingAs($requester)->getJson(route('tasks.timeline', $task))->assertOk();
        $this->assertSame(['Visible legacy'], array_column($response->json('updates'), 'note'));
        $this->assertSame(['สร้างงาน'], array_column($response->json('activity'), 'note'));
    }

    public function test_outsiders_and_viewers_cannot_read_a_timeline(): void
    {
        $owner = $this->user();
        $task = $this->task($owner, $owner);
        $outsiderDepartment = Department::create(['department_name' => 'แผนกอื่น']);
        $outsider = User::factory()->create(['role' => 'user', 'must_change_password' => false, 'is_active' => true, 'department_id' => $outsiderDepartment->id]);
        $viewer = $this->user('viewer');

        $this->actingAs($outsider)->getJson(route('tasks.timeline', $task))->assertForbidden();
        $this->actingAs($viewer)->getJson(route('tasks.timeline', $task))->assertForbidden();
    }

    public function test_workspaces_no_longer_embed_timelines_but_link_each_task_to_its_own_for_user_and_admin(): void
    {
        foreach (['user', 'admin'] as $role) {
            $actor = $this->user($role);
            $member = $this->user();
            $this->acknowledgeDailyBrief($actor);
            $task = $this->task($member, $actor);
            $task->updates()->create(['user_id' => $member->id, 'note' => 'Comment body '.$role, 'is_comment' => true]);

            $html = $this->actingAs($actor)->get(route('mytasks.index'))->assertOk()->getContent();

            $this->assertStringNotContainsString('data-timeline-data', $html);
            $this->assertStringNotContainsString('Comment body '.$role, $html, "{$role}: comment bodies are fetched when the task opens.");
            preg_match('/<script type="application\/json" data-task-management-data>(.*?)<\/script>/s', $html, $matches);
            $management = json_decode($matches[1], true, flags: JSON_THROW_ON_ERROR);
            $this->assertSame(route('tasks.timeline', $task), $management[(string) $task->job_id]['timeline_url']);
        }
    }

    private function user(string $role = 'user'): User
    {
        return User::factory()->create([
            'role' => $role,
            'must_change_password' => false,
            'is_active' => true,
            'department_id' => ($this->department ??= Department::create(['department_name' => 'แผนกไทม์ไลน์']))->id,
            'profile_image' => 'profiles/'.$role.'.jpg',
        ]);
    }

    private function task(User $assignee, User $creator, array $overrides = []): WorkOrder
    {
        $list = WorkOrderList::create(['user_id' => $creator->id, 'name' => 'Timeline project '.WorkOrderList::count(), 'is_visible' => true, 'sort_order' => 1]);

        return WorkOrder::create(array_merge([
            'user_id' => $assignee->id,
            'created_by' => $creator->id,
            'assigned_by' => $creator->id,
            'leader_user_id' => $creator->id,
            'department_id' => $creator->department_id,
            'work_order_list_id' => $list->id,
            'job_topic' => 'Timeline task',
            'job_priority' => 2,
            'job_status' => 2,
            'approval_status' => 'approved',
            'job_start_at' => now()->subDay(),
            'job_due_at' => now()->addDay(),
        ], $overrides));
    }
}
