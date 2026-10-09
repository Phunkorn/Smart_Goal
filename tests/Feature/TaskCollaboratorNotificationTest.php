<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\Department;
use App\Models\SystemNotification;
use App\Models\User;
use App\Models\WorkOrder;
use App\Services\NotificationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
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

    public function test_cross_department_add_joins_immediately_and_never_bothers_admins(): void
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

        // ต่างแผนกเข้าร่วมได้ทันทีเหมือนคนในแผนก
        $this->assertSame('accepted', $task->collaborators()->findOrFail($outsider->id)->pivot->status);

        // admin ไม่ใช่ผู้ดูแลงาน การเพิ่มผู้เข้าร่วมไม่ใช่เรื่องของ admin อีกต่อไป
        foreach ([$adminOne, $adminTwo] as $admin) {
            $this->assertDatabaseMissing('system_notifications', ['user_id' => $admin->id]);
        }

        $this->assertSame(0, SystemNotification::where('type', 'collaborator_approval_request')->count());

        // ผู้เข้าร่วมได้รับแจ้งว่าเข้าร่วมแล้วทันที
        $this->assertDatabaseHas('system_notifications', [
            'user_id' => $outsider->id,
            'work_order_id' => $task->job_id,
            'type' => 'collaborator_added',
        ]);
    }

    public function test_origin_department_head_is_told_their_member_joined_and_lands_on_the_tracking_tab(): void
    {
        $ownerDepartment = Department::create(['department_name' => 'IT']);
        $otherDepartment = Department::create(['department_name' => 'Marketing']);
        $owner = $this->user('user', $ownerDepartment);
        $candidate = $this->user('user', $otherDepartment);
        $head = $this->user('user', $otherDepartment);
        $head->update(['is_department_head' => true]);
        $task = $this->taskFor($owner, $ownerDepartment);

        // ก่อนที่ลูกทีมจะเข้าร่วม หัวหน้าต้นสังกัดยังไม่เกี่ยวกับงานใบนี้
        $this->assertFalse($head->can('view', $task));

        $this->actingAs($owner)
            ->postJson(route('tasks.collaborators.store', $task), ['collaborators' => [$candidate->id]])
            ->assertOk();

        $notification = SystemNotification::where('user_id', $head->id)
            ->where('work_order_id', $task->job_id)
            ->where('type', 'cross_department_participation')
            ->sole();

        $this->assertSame($candidate->id, (int) data_get($notification->data, 'participant_user_id'));
        $this->actingAs($head)
            ->get(route('notifications.open', $notification))
            ->assertRedirect(route('admin.approvals.index', ['approval_queue' => 'outgoing']));

        // พอมีลูกทีมอยู่ในงานแล้ว หัวหน้าต้นสังกัดจึงเปิดดูงานได้เพื่อติดตาม
        $this->assertTrue($head->fresh()->can('view', $task->fresh()));
    }

    public function test_cross_department_head_collaborator_joins_immediately(): void
    {
        $ownerDepartment = Department::create(['department_name' => 'IT']);
        $otherDepartment = Department::create(['department_name' => 'Marketing']);
        $owner = $this->user('user', $ownerDepartment);
        $head = $this->user('user', $otherDepartment);
        $head->update(['is_department_head' => true]);
        $task = $this->taskFor($owner, $ownerDepartment);

        $this->actingAs($owner)
            ->postJson(route('tasks.collaborators.store', $task), ['collaborators' => [$head->id]])
            ->assertOk();

        $this->assertSame('accepted', $task->collaborators()->findOrFail($head->id)->pivot->status);
        $this->assertDatabaseHas('system_notifications', [
            'user_id' => $head->id,
            'work_order_id' => $task->job_id,
            'type' => 'collaborator_added',
        ]);
        $this->assertDatabaseMissing('system_notifications', [
            'work_order_id' => $task->job_id,
            'type' => 'collaborator_approval_request',
        ]);
    }

    public function test_cross_department_retry_does_not_duplicate_but_remove_and_rejoin_notifies_again(): void
    {
        $ownerDepartment = Department::create(['department_name' => 'IT']);
        $otherDepartment = Department::create(['department_name' => 'Marketing']);
        $owner = $this->user('user', $ownerDepartment);
        $candidate = $this->user('user', $otherDepartment);
        $head = $this->user('user', $otherDepartment);
        $head->update(['is_department_head' => true]);
        $task = $this->taskFor($owner, $ownerDepartment);

        $this->actingAs($owner)
            ->postJson(route('tasks.collaborators.store', $task), ['collaborators' => [$candidate->id]])
            ->assertOk();
        // ยิงซ้ำคนเดิมต้องไม่สร้างแถวหรือแจ้งเตือนซ้ำ เพราะอยู่ในโปรเจกต์แล้ว
        $this->actingAs($owner)
            ->postJson(route('tasks.collaborators.store', $task), ['collaborators' => [$candidate->id]])
            ->assertOk();

        $headNotices = SystemNotification::where('user_id', $head->id)
            ->where('work_order_id', $task->job_id)
            ->where('type', 'cross_department_participation');
        $this->assertSame(1, $headNotices->count());
        $this->assertSame(1, $task->fresh()->collaborators->where('id', $candidate->id)->count());

        $this->actingAs($owner)
            ->deleteJson(route('tasks.collaborators.destroy', [$task, $candidate]))
            ->assertOk();
        $this->actingAs($owner)
            ->postJson(route('tasks.collaborators.store', $task), ['collaborators' => [$candidate->id]])
            ->assertOk();

        // นำออกแล้วเพิ่มกลับคือเหตุการณ์ใหม่ หัวหน้าต้นสังกัดต้องรับทราบอีกครั้ง
        $this->assertSame(2, $headNotices->count());
    }

    public function test_a_cross_department_participant_can_work_on_the_task_right_away(): void
    {
        $ownerDepartment = Department::create(['department_name' => 'IT']);
        $otherDepartment = Department::create(['department_name' => 'Marketing']);
        $owner = $this->user('user', $ownerDepartment);
        $candidate = $this->user('user', $otherDepartment);
        $admin = $this->user('admin');
        $task = $this->taskFor($owner, $ownerDepartment);

        $this->actingAs($owner)
            ->postJson(route('tasks.collaborators.store', $task), ['collaborators' => [$candidate->id]])
            ->assertOk();

        $pivot = $task->fresh()->collaborators()->findOrFail($candidate->id)->pivot;
        $this->assertSame('accepted', $pivot->status);
        $this->assertSame($owner->id, $pivot->decided_by, 'หัวหน้าโปรเจกต์เป็นผู้รับคนเข้าโปรเจกต์เอง');
        $this->assertNotNull($pivot->responded_at);

        // ลงมือทำงานได้ทันทีโดยไม่ต้องรอใคร
        $this->actingAs($candidate)
            ->patchJson(route('tasks.updateStatus', $task), ['job_status' => 2])
            ->assertOk();

        // แจ้งเตือนและร่องรอยต้องเกิดครั้งเดียวต่อการเพิ่มหนึ่งครั้ง
        $this->assertSame(1, SystemNotification::where('user_id', $candidate->id)
            ->where('work_order_id', $task->job_id)
            ->where('type', 'collaborator_added')
            ->count());
        $this->assertSame(1, ActivityLog::where('subject_id', $task->job_id)
            ->where('action', 'collaborator_added')
            ->count());

        // ไม่มีเส้นทางให้ใครมาอนุมัติหรือปฏิเสธการเข้าร่วมนี้
        $this->assertFalse(Route::has('admin.tasks.collaborators.approval'));
        $this->assertSame(0, ActivityLog::where('subject_id', $task->job_id)
            ->whereIn('action', ['collaborator_accepted', 'collaborator_rejected'])
            ->count());
        $this->assertDatabaseMissing('system_notifications', ['user_id' => $admin->id]);
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
            'job_status' => 2,
            'approval_status' => 'approved',
            'job_start_at' => now(),
            'job_due_at' => now()->addDay(),
        ]);
    }
}
