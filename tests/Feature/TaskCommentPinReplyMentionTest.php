<?php

namespace Tests\Feature;

use App\Models\SystemNotification;
use App\Models\User;
use App\Models\WorkOrder;
use App\Models\WorkOrderUpdate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TaskCommentPinReplyMentionTest extends TestCase
{
    use RefreshDatabase;

    public function test_participant_can_pin_and_pinning_another_comment_unpins_the_previous_one(): void
    {
        $author = $this->user();
        $task = $this->task($author, $author);
        $first = $this->comment($task, $author, 'first');
        $second = $this->comment($task, $author, 'second');

        $this->actingAs($author)->postJson(route('tasks.comments.pin', [$task, $first]))
            ->assertOk()->assertJsonPath('comment.pinned', true);
        $this->assertNotNull($first->fresh()->pinned_at);

        $this->actingAs($author)->postJson(route('tasks.comments.pin', [$task, $second]))
            ->assertOk()->assertJsonPath('comment.pinned', true);

        $this->assertNull($first->fresh()->pinned_at, 'ปักหมุดคอมเมนต์ใหม่ต้องเลิกปักหมุดคอมเมนต์เดิมอัตโนมัติ');
        $this->assertNotNull($second->fresh()->pinned_at);
    }

    public function test_unpin_clears_pinned_state(): void
    {
        $author = $this->user();
        $task = $this->task($author, $author);
        $comment = $this->comment($task, $author, 'pin me');

        $this->actingAs($author)->postJson(route('tasks.comments.pin', [$task, $comment]))->assertOk();
        $this->actingAs($author)->postJson(route('tasks.comments.unpin', [$task, $comment]))
            ->assertOk()->assertJsonPath('comment.pinned', false);

        $this->assertNull($comment->fresh()->pinned_at);
    }

    public function test_pin_requires_the_same_comment_ability_as_commenting(): void
    {
        $assignee = $this->user();
        $task = $this->task($assignee, $assignee);
        $comment = $this->comment($task, $assignee, 'hello');
        $stranger = $this->user();

        $this->actingAs($stranger)->postJson(route('tasks.comments.pin', [$task, $comment]))->assertForbidden();
        $this->assertNull($comment->fresh()->pinned_at);
    }

    public function test_pinning_a_comment_that_belongs_to_another_task_is_rejected(): void
    {
        $author = $this->user();
        $task = $this->task($author, $author);
        $otherTask = $this->task($author, $author);
        $foreignComment = $this->comment($otherTask, $author, 'wrong task');

        $this->actingAs($author)->postJson(route('tasks.comments.pin', [$task, $foreignComment]))->assertNotFound();
    }

    public function test_reply_to_id_links_the_new_comment_to_the_quoted_one(): void
    {
        $author = $this->user();
        $task = $this->task($author, $author);
        $original = $this->comment($task, $author, 'ข้อความต้นทาง');

        $response = $this->actingAs($author)->postJson(route('tasks.comments.store', $task), [
            'message' => 'ตอบกลับ',
            'reply_to_id' => $original->id,
        ])->assertCreated();

        $response->assertJsonPath('comment.reply_to.id', $original->id);
        $response->assertJsonPath('comment.reply_to.author', $author->name);
        $this->assertSame($original->id, WorkOrderUpdate::latest('id')->first()->reply_to_id);
    }

    /**
     * บั๊กจริงที่ผู้ใช้รายงาน: ผู้โพสต์เห็นข้อความที่ตอบกลับ แต่คนอื่นที่เห็นคอมเมนต์เดียวกัน
     * ผ่านฟีด realtime (ไม่ใช่จาก response ตรงของ TaskCommentController::store()) กลับไม่เห็น
     * ข้อความต้นทางที่ถูกอ้างอิง เพราะ NotificationService::syncFeed() ไม่ได้ eager-load
     * ความสัมพันธ์ replyTo ไว้ TaskCommentPresenter::comment() จึงคืน reply_to เป็น null เสมอ
     */
    public function test_a_reply_is_visible_with_its_quoted_original_through_the_realtime_feed_not_just_to_the_author(): void
    {
        $author = $this->user();
        $collaborator = $this->user();
        $task = $this->task($author, $author);
        $task->collaborators()->attach($collaborator->id, ['status' => 'accepted', 'added_by' => $author->id]);
        $original = $this->comment($task, $collaborator, 'ข้อความต้นทาง');

        $this->actingAs($author)->postJson(route('tasks.comments.store', $task), [
            'message' => 'ตอบกลับ',
            'reply_to_id' => $original->id,
        ])->assertCreated();

        $response = $this->actingAs($collaborator)->getJson(route('realtime.sync', ['after' => 0]))
            ->assertOk();

        $event = collect($response->json('events'))->firstWhere('type', 'task_comment');
        $this->assertNotNull($event, 'ผู้ร่วมงานต้องเห็น event ของคอมเมนต์ใหม่ผ่าน realtime feed');
        $this->assertSame($original->id, $event['comment']['reply_to']['id'] ?? null);
        $this->assertSame('ข้อความต้นทาง', $event['comment']['reply_to']['note'] ?? null);
    }

    /**
     * บั๊กจริงในรูปแบบเดียวกับ replyTo ด้านบน: NotificationService::syncFeed() เก็บ comment_id
     * มาเทียบเฉพาะ notification ชนิด 'task_comment' เท่านั้น ไม่รวม 'task_comment_mention'
     * คนที่ถูก @กล่าวถึงได้ notification คนละชนิดกับคนอื่นในงาน (ดู TaskCommentService::post())
     * event ของเขาผ่าน realtime feed จึงไม่มี comment ติดมาด้วยเลย (comment เป็น null เสมอ)
     * ทำให้ถ้า Task Workspace ของเขาเปิดอยู่ตอนถูกกล่าวถึง คอมเมนต์ใหม่จะไม่โผล่ขึ้นมาสด ๆ
     * (ดู guard "! incoming.comment" ใน task-timeline.js ที่ดักทิ้ง event ที่ไม่มี comment)
     */
    public function test_a_mention_notification_carries_the_full_comment_through_the_realtime_feed(): void
    {
        $author = $this->user();
        $collaborator = $this->user();
        $task = $this->task($author, $author);
        $task->collaborators()->attach($collaborator->id, ['status' => 'accepted', 'added_by' => $author->id]);

        $this->actingAs($author)->postJson(route('tasks.comments.store', $task), [
            'message' => 'ฝากดูงานนี้ด้วยครับ',
            'mentions' => [$collaborator->id],
        ])->assertCreated();

        $response = $this->actingAs($collaborator)->getJson(route('realtime.sync', ['after' => 0]))->assertOk();

        $event = collect($response->json('events'))->firstWhere('type', 'task_comment_mention');
        $this->assertNotNull($event, 'ผู้ถูกกล่าวถึงต้องเห็น event ของฉบับกล่าวถึงผ่าน realtime feed');
        $this->assertNotNull($event['comment'] ?? null, 'event ของการกล่าวถึงต้องพ่วงข้อมูลคอมเมนต์มาด้วย ไม่งั้น Task Workspace ที่เปิดอยู่จะไม่อัปเดตสด');
        $this->assertSame('ฝากดูงานนี้ด้วยครับ', $event['comment']['note'] ?? null);
    }

    public function test_reply_to_id_pointing_at_another_task_is_silently_ignored(): void
    {
        $author = $this->user();
        $task = $this->task($author, $author);
        $otherTask = $this->task($author, $author);
        $foreignComment = $this->comment($otherTask, $author, 'other task comment');

        $response = $this->actingAs($author)->postJson(route('tasks.comments.store', $task), [
            'message' => 'ตอบกลับ',
            'reply_to_id' => $foreignComment->id,
        ])->assertCreated();

        $response->assertJsonPath('comment.reply_to', null);
        $this->assertNull(WorkOrderUpdate::latest('id')->first()->reply_to_id);
    }

    public function test_mentioning_a_task_participant_records_the_mention_and_sends_a_dedicated_notification(): void
    {
        $author = $this->user();
        $collaborator = $this->user();
        $task = $this->task($author, $author);
        $task->collaborators()->attach($collaborator->id, ['status' => 'accepted', 'added_by' => $author->id]);

        $response = $this->actingAs($author)->postJson(route('tasks.comments.store', $task), [
            'message' => 'ฝากดูงานนี้ด้วยครับ',
            'mentions' => [$collaborator->id],
        ])->assertCreated();

        $response->assertJsonPath('comment.mentions.0.id', $collaborator->id);
        $this->assertDatabaseHas('work_order_update_mentions', [
            'user_id' => $collaborator->id,
        ]);

        $notice = SystemNotification::where('user_id', $collaborator->id)->firstOrFail();
        $this->assertSame('task_comment_mention', $notice->type);
        $this->assertSame('comment', $notice->category);

        // คนที่ถูกกล่าวถึงได้แจ้งเตือนฉบับ "กล่าวถึง" ไปแล้ว ไม่ต้องได้ฉบับคอมเมนต์ทั่วไปซ้ำอีก
        $this->assertDatabaseMissing('system_notifications', [
            'user_id' => $collaborator->id,
            'type' => 'task_comment',
        ]);
    }

    public function test_mention_all_notifies_every_eligible_participant_but_not_the_author_or_a_viewer(): void
    {
        $author = $this->user();
        $collaboratorOne = $this->user();
        $collaboratorTwo = $this->user();
        $viewer = $this->user('viewer');
        $task = $this->task($author, $author);
        $task->collaborators()->attach($collaboratorOne->id, ['status' => 'accepted', 'added_by' => $author->id]);
        $task->collaborators()->attach($collaboratorTwo->id, ['status' => 'accepted', 'added_by' => $author->id]);
        // แม้ยัดเป็นผู้ร่วมงานไว้ตรง ๆ (ข้อมูลเพี้ยน) viewer ก็ต้องไม่ถูก @all กล่าวถึงได้เหมือน mention เดี่ยว
        $task->collaborators()->attach($viewer->id, ['status' => 'accepted', 'added_by' => $author->id]);

        $response = $this->actingAs($author)->postJson(route('tasks.comments.store', $task), [
            'message' => 'ประกาศด่วน @all ช่วยดูงานนี้ด้วยครับ',
            'mention_all' => true,
        ])->assertCreated();

        $mentionedIds = collect($response->json('comment.mentions'))->pluck('id')->sort()->values()->all();
        $this->assertSame([$collaboratorOne->id, $collaboratorTwo->id], collect($mentionedIds)->sort()->values()->all());

        foreach ([$collaboratorOne, $collaboratorTwo] as $recipient) {
            $notice = SystemNotification::where('user_id', $recipient->id)->firstOrFail();
            $this->assertSame('task_comment_mention', $notice->type);
        }

        $this->assertDatabaseMissing('system_notifications', ['user_id' => $viewer->id]);
        $this->assertDatabaseMissing('system_notifications', ['user_id' => $author->id]);
    }

    /**
     * บั๊กจริง: TaskCommentService::markRead() ล้าง read_at ให้เฉพาะ type='task_comment' เท่านั้น
     * ไม่รวม 'task_comment_mention' คนที่ถูก @กล่าวถึงเปิดแท็บอัปเดตอ่านคอมเมนต์ตามปกติแล้ว
     * แต่ตัวเลขแจ้งเตือนที่กระดิ่งของฉบับ "กล่าวถึงคุณ" จะค้างไม่มีวันหายไป
     */
    public function test_reading_the_task_updates_tab_also_clears_the_mention_notification(): void
    {
        $author = $this->user();
        $collaborator = $this->user();
        $task = $this->task($author, $author);
        $task->collaborators()->attach($collaborator->id, ['status' => 'accepted', 'added_by' => $author->id]);

        $this->actingAs($author)->postJson(route('tasks.comments.store', $task), [
            'message' => 'ฝากดูงานนี้ด้วยครับ',
            'mentions' => [$collaborator->id],
        ])->assertCreated();

        $notice = SystemNotification::where('user_id', $collaborator->id)->where('type', 'task_comment_mention')->firstOrFail();
        $this->assertNull($notice->read_at);

        $this->actingAs($collaborator)->postJson(route('tasks.comments.read', $task))->assertOk();

        $this->assertNotNull($notice->fresh()->read_at, 'อ่านคอมเมนต์ในงานแล้ว ฉบับกล่าวถึงต้องถูกอ่านไปด้วย ไม่ใช่ค้างแจ้งเตือนตลอดไป');
    }

    public function test_mentioning_someone_outside_the_task_is_dropped_silently(): void
    {
        $author = $this->user();
        $task = $this->task($author, $author);
        $outsider = $this->user();

        $response = $this->actingAs($author)->postJson(route('tasks.comments.store', $task), [
            'message' => 'ฝากดูงานนี้ด้วยครับ',
            'mentions' => [$outsider->id],
        ])->assertCreated();

        $response->assertJsonPath('comment.mentions', []);
        $this->assertDatabaseCount('work_order_update_mentions', 0);
        $this->assertDatabaseMissing('system_notifications', ['user_id' => $outsider->id]);
    }

    public function test_mentioning_a_viewer_on_the_task_is_dropped_silently(): void
    {
        $author = $this->user();
        $task = $this->task($author, $author);
        $viewer = $this->user('viewer');
        // แม้จะยัดเป็นผู้ร่วมงานไว้ตรง ๆ (ข้อมูลเพี้ยน) viewer ก็ต้องไม่ถูกกล่าวถึงได้
        $task->collaborators()->attach($viewer->id, ['status' => 'accepted', 'added_by' => $author->id]);

        $response = $this->actingAs($author)->postJson(route('tasks.comments.store', $task), [
            'message' => 'ฝากดูงานนี้ด้วยครับ',
            'mentions' => [$viewer->id],
        ])->assertCreated();

        $response->assertJsonPath('comment.mentions', []);
        $this->assertDatabaseCount('work_order_update_mentions', 0);
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

    private function comment(WorkOrder $task, User $author, string $message): WorkOrderUpdate
    {
        return WorkOrderUpdate::create([
            'work_order_id' => $task->job_id,
            'user_id' => $author->id,
            'note' => $message,
            'is_comment' => true,
        ]);
    }
}
