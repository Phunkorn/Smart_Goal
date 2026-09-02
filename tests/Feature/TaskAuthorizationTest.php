<?php

namespace Tests\Feature;

use App\Models\SystemNotification;
use App\Models\User;
use App\Models\WorkOrder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
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

    public function test_owner_can_upload_and_delete_task_attachment(): void
    {
        Storage::fake('public');
        Storage::fake('local');

        $owner = $this->user();
        $task = $this->taskFor($owner);

        // payload ส่งรายการไฟล์ล่าสุดกลับมาด้วย เพื่อให้ modal อัปเดตในที่เดิม
        // แทนการ reload ทั้งหน้าซึ่งเคยทำให้ modal ปิดทิ้งทันทีที่แนบสำเร็จ
        $this->actingAs($owner)
            ->postJson(route('tasks.attachments.store', $task), [
                'completion_attachments' => [UploadedFile::fake()->image('evidence.png')],
            ])
            ->assertOk()
            ->assertExactJson([
                'ok' => true,
                'message' => 'เพิ่มไฟล์อ้างอิงงานสำเร็จ',
                'files' => [[
                    'name' => 'evidence.png',
                    'url' => route('media.task-attachments.show', $task->images()->firstOrFail()),
                    'delete_url' => route('tasks.attachments.destroy', [$task->job_id, $task->images()->firstOrFail()]),
                ]],
            ]);

        $attachment = $task->images()->firstOrFail();
        Storage::disk('local')->assertExists($attachment->file_path);

        $this->actingAs($owner)
            ->deleteJson(route('tasks.attachments.destroy', [$task, $attachment]))
            ->assertOk()
            ->assertExactJson([
                'ok' => true,
                'message' => 'ลบไฟล์แนบแล้ว',
                'files' => [],
            ]);

        $this->assertDatabaseMissing('job_images', ['id' => $attachment->id]);
        Storage::disk('local')->assertMissing($attachment->file_path);
    }

    public function test_admin_approves_collaborator_invitation_and_owner_can_remove_them(): void
    {
        $owner = $this->user();
        $candidate = $this->user();
        $admin = $this->user('admin');
        $task = $this->taskFor($owner);

        $this->actingAs($owner)
            ->postJson(route('tasks.collaborators.store', $task), [
                'collaborators' => [$candidate->id],
            ])
            ->assertOk();

        $this->assertSame('pending', $task->collaborators()->findOrFail($candidate->id)->pivot->status);

        $this->actingAs($candidate)
            ->patch(route('tasks.invitation.respond', $task), ['status' => 'accepted'])
            ->assertForbidden();

        $this->actingAs($admin)
            ->patchJson(route('admin.tasks.collaborators.approval', [$task, $candidate]), ['status' => 'accepted'])
            ->assertOk();

        $this->assertSame('accepted', $task->collaborators()->findOrFail($candidate->id)->pivot->status);

        $this->actingAs($owner)
            ->deleteJson(route('tasks.collaborators.destroy', [$task, $candidate]))
            ->assertOk()
            ->assertExactJson([
                'ok' => true,
                'message' => 'นำผู้ร่วมงานออกจากทีมแล้ว',
            ]);

        $this->assertDatabaseMissing('work_order_collaborators', [
            'work_order_id' => $task->job_id,
            'user_id' => $candidate->id,
        ]);
        $this->assertDatabaseHas('system_notifications', [
            'user_id' => $candidate->id,
            'work_order_id' => $task->job_id,
            'type' => 'collaborator_removed',
        ]);
        $this->assertSame(1, SystemNotification::where('user_id', $candidate->id)
            ->where('work_order_id', $task->job_id)
            ->where('type', 'collaborator_removed')
            ->count());
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
            'job_status' => 2,
            'approval_status' => 'approved',
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
