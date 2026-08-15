<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\WorkOrder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TaskAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    public function test_unrelated_user_cannot_update_or_delete_another_users_task(): void
    {
        $owner = $this->user();
        $unrelatedUser = $this->user();
        $task = $this->taskFor($owner);

        $this->actingAs($unrelatedUser)
            ->patch(route('tasks.updateStatus', $task), ['job_status' => 2])
            ->assertForbidden();

        $this->actingAs($unrelatedUser)
            ->delete(route('mytasks.destroy', $task))
            ->assertForbidden();
    }

    public function test_unrelated_user_cannot_view_a_task(): void
    {
        $owner = $this->user();
        $unrelatedUser = $this->user();
        $task = $this->taskFor($owner);

        $this->actingAs($unrelatedUser)
            ->get(route('tasks.show', $task))
            ->assertForbidden();
    }

    public function test_viewer_is_read_only_on_task_write_endpoints(): void
    {
        $viewer = $this->user('viewer');
        $task = $this->taskFor($viewer);

        $this->actingAs($viewer)
            ->post(route('tasks.store'), $this->creationPayload())
            ->assertForbidden();

        $this->actingAs($viewer)
            ->patch(route('tasks.updateStatus', $task), ['job_status' => 2])
            ->assertForbidden();
    }

    public function test_admin_can_manage_any_task_and_create_tasks(): void
    {
        $owner = $this->user();
        $admin = $this->user('admin');
        $task = $this->taskFor($owner);

        $this->actingAs($admin)
            ->get(route('tasks.show', $task))
            ->assertRedirect(route('mytasks.index'));

        $this->actingAs($admin)
            ->patch(route('tasks.updateStatus', $task), ['job_status' => 2])
            ->assertRedirect();

        $this->actingAs($admin)
            ->post(route('tasks.store'), $this->creationPayload())
            ->assertRedirect();

        $this->actingAs($admin)
            ->delete(route('mytasks.destroy', $task))
            ->assertOk();
    }

    private function user(string $role = 'user'): User
    {
        return User::factory()->create([
            'role' => $role,
            'must_change_password' => false,
            'is_active' => true,
        ]);
    }

    private function taskFor(User $owner): WorkOrder
    {
        return WorkOrder::create([
            'user_id' => $owner->id,
            'created_by' => $owner->id,
            'leader_user_id' => $owner->id,
            'job_topic' => 'Authorization test task',
            'job_priority' => 2,
            'job_status' => 1,
            'approval_status' => 'approved',
            'job_progress' => 0,
            'job_start_at' => now(),
            'job_due_at' => now()->addDay(),
        ]);
    }

    private function creationPayload(): array
    {
        return [
            'job_topic' => 'New authorized task',
            'job_start_at' => now()->toDateTimeString(),
            'job_due_at' => now()->addDay()->toDateTimeString(),
        ];
    }
}
