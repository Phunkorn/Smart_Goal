<?php

namespace Tests\Feature;

use App\Models\Department;
use App\Models\SystemNotification;
use App\Models\User;
use App\Models\WorkOrder;
use App\Services\NotificationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ครอบพฤติกรรมแจ้งเตือนของปุ่ม "เพิ่มผู้ร่วมงาน" (POST /tasks/{id}/collaborators)
 *
 * เคยมีบั๊กที่ pivot ถูกเขียนสำเร็จแต่ notification หายเงียบ ๆ เพราะ
 * WorkOrderPolicy::view() อ่าน collaborators จาก relation ที่ค้างอยู่ใน memory
 * ทำให้ Gate ใน NotificationService มองไม่เห็นผู้ร่วมงานคนที่เพิ่งถูกเพิ่ม
 */
class TaskCollaboratorNotificationTest extends TestCase
{
    use RefreshDatabase;

    public function test_same_department_collaborator_receives_exactly_one_notification(): void
    {
        $department = Department::create(['department_name' => 'IT']);
        $owner = $this->user('user', $department);
        $teammate = $this->user('user', $department);
        $task = $this->taskFor($owner, $department);

        $this->actingAs($owner)
            ->postJson(route('tasks.collaborators.store', $task), [
                'collaborators' => [$teammate->id],
            ])
            ->assertOk();

        // แผนกเดียวกันต้องเข้าทีมทันที ไม่ต้องรออนุมัติ
        $this->assertSame('accepted', $task->collaborators()->findOrFail($teammate->id)->pivot->status);

        $notifications = SystemNotification::where('user_id', $teammate->id)
            ->where('type', 'collaborator_added')
            ->get();

        $this->assertCount(1, $notifications);
        $this->assertSame($owner->id, $notifications->first()->actor_user_id);
        $this->assertSame($task->job_id, $notifications->first()->work_order_id);
        $this->assertSame('task', $notifications->first()->category);
        $this->assertNull($notifications->first()->read_at);
    }

    public function test_collaborator_notification_raises_unread_count_and_opens_the_right_task(): void
    {
        $department = Department::create(['department_name' => 'IT']);
        $owner = $this->user('user', $department);
        $teammate = $this->user('user', $department);
        $otherTask = $this->taskFor($owner, $department);
        $task = $this->taskFor($owner, $department);
        $notifications = app(NotificationService::class);

        $this->assertSame(0, $notifications->unreadCount($teammate));

        $this->actingAs($owner)
            ->postJson(route('tasks.collaborators.store', $task), [
                'collaborators' => [$teammate->id],
            ])
            ->assertOk();

        $this->assertSame(1, $notifications->unreadCount($teammate));

        $notification = SystemNotification::where('user_id', $teammate->id)->firstOrFail();
        $target = $notifications->target($notification, $teammate);

        $this->assertStringContainsString('open_task='.$task->job_id, $target);
        $this->assertStringNotContainsString('open_task='.$otherTask->job_id, $target);
    }

    public function test_adding_several_teammates_at_once_notifies_each_of_them_once(): void
    {
        $department = Department::create(['department_name' => 'IT']);
        $owner = $this->user('user', $department);
        $first = $this->user('user', $department);
        $second = $this->user('user', $department);
        $third = $this->user('user', $department);
        $task = $this->taskFor($owner, $department);

        $this->actingAs($owner)
            ->postJson(route('tasks.collaborators.store', $task), [
                'collaborators' => [$first->id, $second->id, $third->id],
            ])
            ->assertOk();

        // การรีเฟรช relation ใน loop ต้องไม่ทำให้คนก่อนหน้าถูกแจ้งซ้ำ
        foreach ([$first, $second, $third] as $teammate) {
            $this->assertSame(1, SystemNotification::where('user_id', $teammate->id)
                ->where('work_order_id', $task->job_id)
                ->where('type', 'collaborator_added')
                ->count());
        }

        $this->assertSame(3, SystemNotification::where('work_order_id', $task->job_id)
            ->where('type', 'collaborator_added')
            ->count());
    }

    public function test_adding_yourself_creates_no_notification(): void
    {
        $department = Department::create(['department_name' => 'IT']);
        $owner = $this->user('user', $department);
        $task = $this->taskFor($owner, $department);

        $this->actingAs($owner)
            ->postJson(route('tasks.collaborators.store', $task), [
                'collaborators' => [$owner->id],
            ])
            ->assertOk();

        $this->assertDatabaseMissing('system_notifications', ['user_id' => $owner->id]);
        $this->assertDatabaseMissing('work_order_collaborators', [
            'work_order_id' => $task->job_id,
            'user_id' => $owner->id,
        ]);
    }

    public function test_adding_an_existing_collaborator_creates_no_duplicate_notification(): void
    {
        $department = Department::create(['department_name' => 'IT']);
        $owner = $this->user('user', $department);
        $teammate = $this->user('user', $department);
        $task = $this->taskFor($owner, $department);

        $this->actingAs($owner)
            ->postJson(route('tasks.collaborators.store', $task), ['collaborators' => [$teammate->id]])
            ->assertOk();
        $this->actingAs($owner)
            ->postJson(route('tasks.collaborators.store', $task), ['collaborators' => [$teammate->id]])
            ->assertOk();

        $this->assertSame(1, SystemNotification::where('user_id', $teammate->id)
            ->where('type', 'collaborator_added')
            ->count());
    }

    public function test_removed_collaborator_is_notified_again_when_re_added(): void
    {
        $department = Department::create(['department_name' => 'IT']);
        $owner = $this->user('user', $department);
        $teammate = $this->user('user', $department);
        $task = $this->taskFor($owner, $department);

        $this->actingAs($owner)
            ->postJson(route('tasks.collaborators.store', $task), ['collaborators' => [$teammate->id]])
            ->assertOk();
        $this->actingAs($owner)
            ->deleteJson(route('tasks.collaborators.destroy', [$task, $teammate]))
            ->assertOk();
        $this->actingAs($owner)
            ->postJson(route('tasks.collaborators.store', $task), ['collaborators' => [$teammate->id]])
            ->assertOk();

        // ต้องแจ้งใหม่ได้จริง กันการเผลอใส่ dedupe_key ซึ่งจะโดน unique (user_id, dedupe_key) บล็อก
        $this->assertSame(2, SystemNotification::where('user_id', $teammate->id)
            ->where('type', 'collaborator_added')
            ->count());
    }

    public function test_cross_department_add_stays_pending_and_only_notifies_admins(): void
    {
        $ownerDepartment = Department::create(['department_name' => 'IT']);
        $otherDepartment = Department::create(['department_name' => 'Marketing']);
        $owner = $this->user('user', $ownerDepartment);
        $outsider = $this->user('user', $otherDepartment);
        $adminOne = $this->user('admin');
        $adminTwo = $this->user('admin');
        $task = $this->taskFor($owner, $ownerDepartment);

        $this->actingAs($owner)
            ->postJson(route('tasks.collaborators.store', $task), ['collaborators' => [$outsider->id]])
            ->assertOk();

        // ห้าม bypass approval
        $this->assertSame('pending', $task->collaborators()->findOrFail($outsider->id)->pivot->status);

        foreach ([$adminOne, $adminTwo] as $admin) {
            $this->assertDatabaseHas('system_notifications', [
                'user_id' => $admin->id,
                'work_order_id' => $task->job_id,
                'type' => 'collaborator_approval_request',
            ]);
        }

        $this->assertSame(2, SystemNotification::where('work_order_id', $task->job_id)
            ->where('type', 'collaborator_approval_request')
            ->count());

        // คนข้ามแผนกยังไม่เข้าทีมจริง จึงยังไม่ได้รับแจ้งว่าถูกเพิ่ม
        $this->assertDatabaseMissing('system_notifications', [
            'user_id' => $outsider->id,
            'type' => 'collaborator_added',
        ]);
    }

    public function test_viewer_and_inactive_accounts_are_never_added_or_notified(): void
    {
        $department = Department::create(['department_name' => 'IT']);
        $owner = $this->user('user', $department);
        $viewer = $this->user('viewer', $department);
        $inactive = $this->user('user', $department);
        $inactive->update(['is_active' => false]);
        $task = $this->taskFor($owner, $department);

        $this->actingAs($owner)
            ->postJson(route('tasks.collaborators.store', $task), [
                'collaborators' => [$viewer->id, $inactive->id],
            ])
            ->assertOk();

        foreach ([$viewer, $inactive] as $blocked) {
            $this->assertDatabaseMissing('work_order_collaborators', [
                'work_order_id' => $task->job_id,
                'user_id' => $blocked->id,
            ]);
            $this->assertDatabaseMissing('system_notifications', ['user_id' => $blocked->id]);
        }
    }

    private function user(string $role = 'user', ?Department $department = null): User
    {
        return User::factory()->create([
            'role' => $role,
            'department_id' => $department?->id,
            'must_change_password' => false,
            'is_active' => true,
        ]);
    }

    private function taskFor(User $owner, Department $department): WorkOrder
    {
        return WorkOrder::create([
            'user_id' => $owner->id,
            'created_by' => $owner->id,
            'leader_user_id' => $owner->id,
            'department_id' => $department->id,
            'job_topic' => 'Collaborator notification task',
            'job_priority' => 2,
            'job_status' => 1,
            'approval_status' => 'approved',
            'job_progress' => 0,
            'job_start_at' => now(),
            'job_due_at' => now()->addDay(),
        ]);
    }
}
