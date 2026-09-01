<?php

namespace Tests\Feature;

use App\Models\SystemNotification;
use App\Models\User;
use App\Models\WorkOrder;
use App\Services\TaskCommentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RealtimeSyncTest extends TestCase
{
    use RefreshDatabase;

    public function test_sync_requires_authentication_and_a_cursor(): void
    {
        $this->getJson(route('realtime.sync', ['after' => 0]))->assertUnauthorized();

        $user = $this->user();
        $this->actingAs($user)->getJson(route('realtime.sync'))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('after');
    }

    public function test_sync_returns_only_the_current_users_notifications_after_cursor(): void
    {
        $user = $this->user();
        $other = $this->user();
        $before = $this->notice($user, 'before cursor');
        $mine = $this->notice($user, 'new assignment', 'task_assigned');
        $this->notice($other, 'private notification');

        $this->actingAs($user)
            ->getJson(route('realtime.sync', ['after' => $before->id]))
            ->assertOk()
            ->assertHeader('Cache-Control', 'no-store, private')
            ->assertJsonPath('cursor', $mine->id)
            ->assertJsonPath('unread_count', 2)
            ->assertJsonCount(1, 'events')
            ->assertJsonPath('events.0.id', $mine->id)
            ->assertJsonPath('events.0.title', 'new assignment');
    }

    public function test_comment_event_contains_the_authorized_comment_payload(): void
    {
        $assignee = $this->user();
        $author = $this->user('admin');
        $task = WorkOrder::create([
            'user_id' => $assignee->id,
            'created_by' => $author->id,
            'leader_user_id' => $author->id,
            'job_topic' => 'Realtime task',
            'job_priority' => 2,
            'job_status' => 2,
            'approval_status' => 'approved',
            'job_start_at' => now(),
            'job_due_at' => now()->addDay(),
        ]);
        $task->load(['collaborators', 'user', 'creator']);

        app(TaskCommentService::class)->post($task, $author, 'อัปเดตแบบเรียลไทม์');

        $this->actingAs($assignee)
            ->getJson(route('realtime.sync', ['after' => 0]))
            ->assertOk()
            ->assertJsonPath('events.0.category', 'comment')
            ->assertJsonPath('events.0.task_id', $task->job_id)
            ->assertJsonPath('events.0.comment.author', $author->name)
            ->assertJsonPath('events.0.comment.note', 'อัปเดตแบบเรียลไทม์');
    }

    private function user(string $role = 'user'): User
    {
        return User::factory()->create([
            'role' => $role,
            'must_change_password' => false,
            'is_active' => true,
        ]);
    }

    private function notice(User $user, string $title, string $type = 'system'): SystemNotification
    {
        return SystemNotification::create([
            'user_id' => $user->id,
            'type' => $type,
            'title' => $title,
            'message' => 'message',
        ]);
    }
}
