<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\SystemNotification;
use App\Models\User;
use App\Models\WorkOrder;
use App\Models\WorkOrderList;
use App\Models\WorkOrderListTaskRequest;
use App\Services\NotificationMaintenanceService;
use App\Services\NotificationService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class NotificationCenterTest extends TestCase
{
    use RefreshDatabase;

    public function test_sidebar_entry_count_and_active_state_are_shared_for_every_role(): void
    {
        foreach (['user', 'admin', 'viewer'] as $role) {
            $user = $this->user($role);
            $this->notice($user, 'sidebar unread', now());
            $response = $this->actingAs($user)->get(route('notifications.index'))->assertOk();
            $response->assertSee(route('notifications.index'), false)
                ->assertSee('nav-item active', false)
                ->assertSee('nav-item__count', false)
                ->assertSee('data-notification-count', false)
                ->assertSee('data-delete-notification-form', false);
        }

        $zero = $this->user();
        $this->actingAs($zero)->get(route('notifications.index'))->assertOk()
            ->assertDontSee('data-sidebar-notification-count', false)
            ->assertDontSee('data-bell-notification-count', false);

        $known = $this->user();
        foreach (range(1, 5) as $index) $this->notice($known, 'known '.$index, now());
        $this->actingAs($known)->get(route('notifications.index'))->assertOk()
            ->assertSee('data-sidebar-notification-count>5</span>', false)
            ->assertSee('data-bell-notification-count>5</span>', false);

        $large = $this->user();
        foreach (range(1, 100) as $index) $this->notice($large, 'large '.$index, now());
        $this->actingAs($large)->get(route('notifications.index'))->assertOk()
            ->assertSee('data-sidebar-notification-count>99+</span>', false)
            ->assertSee('data-bell-notification-count>99+</span>', false);
    }

    public function test_deleted_task_keeps_indexed_project_metadata_and_project_filter_visibility(): void
    {
        $admin = $this->user('admin');
        $assignee = $this->user();
        $project = WorkOrderList::create(['user_id' => $assignee->id, 'name' => 'Historical project']);
        $otherProject = WorkOrderList::create(['user_id' => $assignee->id, 'name' => 'Other project']);
        $task = $this->task($assignee, $admin, now()->addDay()->toDateTimeString());
        $task->update(['work_order_list_id' => $project->id]);

        $this->actingAs($admin)->delete(route('admin.tasks.destroy', $task))->assertRedirect();

        $notice = SystemNotification::where('user_id', $assignee->id)->where('type', 'task_deleted')->firstOrFail();
        $otherNotice = $this->notice($assignee, 'Other project notification', now());
        $otherNotice->update(['work_order_list_id' => $otherProject->id]);
        $this->assertSame($project->id, $notice->work_order_list_id);
        $this->actingAs($assignee)->get(route('notifications.index', ['project' => $project->id]))
            ->assertOk()
            ->assertSee('data-notification-id="'.$notice->id.'"', false)
            ->assertDontSee('data-notification-id="'.$otherNotice->id.'"', false);
        $this->actingAs($assignee)->get(route('notifications.index', ['project' => $otherProject->id]))
            ->assertOk()
            ->assertSee('data-notification-id="'.$otherNotice->id.'"', false)
            ->assertDontSee('data-notification-id="'.$notice->id.'"', false);
    }

    public function test_guarded_and_removed_participant_paths_exclude_actor_viewer_and_inactive_accounts(): void
    {
        $actor = $this->user('admin');
        $participant = $this->user();
        $viewer = $this->user('viewer');
        $inactive = $this->user();
        $inactive->update(['is_active' => false]);
        $task = $this->task($participant, $actor, now()->addDay()->toDateTimeString());
        $notifications = app(NotificationService::class);

        $notifications->notify([$actor, $participant, $viewer, $inactive, $participant], 'task_assigned', 'Assigned', null, $task, $actor);
        $this->assertEquals([$participant->id], SystemNotification::pluck('user_id')->all());

        SystemNotification::query()->delete();
        $this->assertNull($notifications->notifyRemovedParticipant($actor, 'collaborator_removed', 'Removed', null, $task, $actor));
        $this->assertNull($notifications->notifyRemovedParticipant($viewer, 'collaborator_removed', 'Removed', null, $task, $actor));
        $this->assertNull($notifications->notifyRemovedParticipant($inactive, 'collaborator_removed', 'Removed', null, $task, $actor));
        $this->assertNotNull($notifications->notifyRemovedParticipant($participant, 'collaborator_removed', 'Removed', null, $task, $actor));
        $this->assertDatabaseCount('system_notifications', 1);
    }

    public function test_dropdown_uses_persisted_age_rules_limit_and_exact_unread_count(): void
    {
        $user = $this->user();
        foreach (range(1, 16) as $index) $this->notice($user, 'unread '.$index, now()->subDays(100));
        $recentRead = $this->notice($user, 'recent read', now()->subDays(6), now());
        $oldRead = $this->notice($user, 'old read', now()->subDays(8), now());

        $service = app(NotificationService::class);
        $this->assertSame(16, $service->unreadCount($user));
        $this->assertCount(15, $service->dropdown($user));
        $this->assertTrue($service->dropdown($user)->contains($recentRead));
        $this->assertFalse($service->dropdown($user)->contains($oldRead));
    }

    public function test_center_actions_are_owner_scoped_and_preserve_old_unread(): void
    {
        $owner = $this->user();
        $other = $this->user();
        $oldUnread = $this->notice($owner, 'old unread', now()->subDays(120));
        $oldRead = $this->notice($owner, 'old read', now()->subDays(120), now());
        $otherNotice = $this->notice($other, 'private', now());

        $this->actingAs($owner)->get(route('notifications.index'))->assertOk()->assertSee('old unread')->assertDontSee('old read')->assertDontSee('private');
        $this->actingAs($owner)->patchJson(route('notifications.read', $oldUnread))->assertOk()->assertJsonPath('unread_count', 0);
        $this->assertDatabaseHas('system_notifications', ['id' => $oldUnread->id, 'is_read' => true]);
        $this->actingAs($owner)->patchJson(route('notifications.read', $otherNotice))->assertNotFound();
        $this->actingAs($owner)->patchJson(route('notifications.unread', $oldUnread))->assertOk()->assertJsonPath('unread_count', 1);
        $this->assertDatabaseHas('system_notifications', ['id' => $oldUnread->id, 'read_at' => null, 'is_read' => false]);
        $this->actingAs($owner)->postJson(route('notifications.read-all'))->assertOk()->assertJsonPath('unread_count', 0);
        $this->assertDatabaseHas('system_notifications', ['id' => $oldRead->id]);
    }

    public function test_delete_one_succeeds_for_owner_and_rejects_idor_across_roles(): void
    {
        $userA = $this->user();
        $userB = $this->user();
        $admin = $this->user('admin');

        $owned = $this->notice($userA, 'owned unread', now());
        $this->actingAs($userA)->deleteJson(route('notifications.destroy', $owned))
            ->assertOk()
            ->assertJsonPath('deleted_count', 1)
            ->assertJsonPath('unread_count', 0);
        $this->assertDatabaseMissing('system_notifications', ['id' => $owned->id]);

        $userBNotice = $this->notice($userB, 'user B private', now());
        $adminNotice = $this->notice($admin, 'admin private', now());
        $userANotice = $this->notice($userA, 'user A private', now());

        $this->actingAs($userA)->deleteJson(route('notifications.destroy', $userBNotice))->assertNotFound();
        $this->actingAs($userA)->deleteJson(route('notifications.destroy', $adminNotice))->assertNotFound();
        $this->actingAs($admin)->deleteJson(route('notifications.destroy', $userANotice))->assertNotFound();

        foreach ([$userBNotice, $adminNotice, $userANotice] as $notice) {
            $this->assertDatabaseHas('system_notifications', ['id' => $notice->id]);
        }
    }

    public function test_delete_read_removes_only_current_owners_read_notifications(): void
    {
        $owner = $this->user();
        $other = $this->user();
        $ownerReadOne = $this->notice($owner, 'read one', now(), now());
        $ownerReadTwo = $this->notice($owner, 'read two', now(), now());
        $ownerUnread = $this->notice($owner, 'keep unread', now());
        $otherRead = $this->notice($other, 'keep other read', now(), now());

        $this->actingAs($owner)->deleteJson(route('notifications.destroy-read'), ['user_id' => $other->id])
            ->assertOk()
            ->assertJsonPath('deleted_count', 2)
            ->assertJsonPath('unread_count', 1)
            ->assertJsonPath('read_count', 0);

        $this->assertDatabaseMissing('system_notifications', ['id' => $ownerReadOne->id]);
        $this->assertDatabaseMissing('system_notifications', ['id' => $ownerReadTwo->id]);
        $this->assertDatabaseHas('system_notifications', ['id' => $ownerUnread->id]);
        $this->assertDatabaseHas('system_notifications', ['id' => $otherRead->id]);
    }

    public function test_center_renders_progressive_delete_and_responsive_action_contract(): void
    {
        $user = $this->user();
        $notice = $this->notice($user, 'Long notification title', now(), now());

        $this->actingAs($user)->get(route('notifications.index'))->assertOk()
            ->assertSee('data-delete-notification-form', false)
            ->assertSee('data-delete-read-form', false)
            ->assertSee('data-delete-count="1"', false)
            ->assertSee('data-bs-boundary="viewport"', false)
            ->assertSee(route('notifications.destroy', $notice), false);
    }

    public function test_missing_or_revoked_deep_link_falls_back_with_warning_instead_of_error(): void
    {
        $owner = $this->user();
        $project = WorkOrderList::create(['user_id' => $owner->id, 'name' => 'Request project']);
        $missingRequest = SystemNotification::create([
            'user_id' => $owner->id,
            'work_order_list_id' => $project->id,
            'type' => 'project_task_request_submitted',
            'title' => 'Missing request',
            'data' => ['task_request_id' => 999999],
        ]);

        $this->actingAs($owner)->get(route('notifications.open', $missingRequest))
            ->assertRedirect(route('notifications.index'))
            ->assertSessionHas('warning', 'รายการนี้ไม่มีอยู่แล้วหรือคุณไม่มีสิทธิ์เข้าถึง');
        $this->assertNotNull($missingRequest->fresh()->read_at);

        $taskOwner = $this->user();
        $outsider = $this->user();
        $task = $this->task($taskOwner, $taskOwner, now()->addDay()->toDateTimeString());
        $revoked = SystemNotification::create([
            'user_id' => $outsider->id,
            'work_order_id' => $task->job_id,
            'type' => 'task_assigned',
            'title' => 'No longer available',
        ]);

        $this->actingAs($outsider)->get(route('notifications.open', $revoked))
            ->assertRedirect(route('notifications.index'))
            ->assertSessionHas('warning');
    }

    public function test_existing_project_task_request_deep_link_remains_available(): void
    {
        $owner = $this->user();
        $requester = $this->user();
        $project = WorkOrderList::create(['user_id' => $owner->id, 'name' => 'Request project']);
        $taskRequest = WorkOrderListTaskRequest::create([
            'work_order_list_id' => $project->id,
            'requester_id' => $requester->id,
            'status' => 'pending',
            'job_topic' => 'Requested task',
            'job_priority' => 2,
            'job_start_at' => now(),
            'job_due_at' => now()->addDay(),
        ]);
        $notice = SystemNotification::create([
            'user_id' => $owner->id,
            'work_order_list_id' => $project->id,
            'type' => 'project_task_request_submitted',
            'title' => 'Task request',
            'data' => ['task_request_id' => $taskRequest->id],
        ]);

        $this->actingAs($owner)->get(route('notifications.open', $notice))
            ->assertRedirect(route('mytasks.index', ['view' => 'board', 'task_request' => $taskRequest->id]));
    }

    public function test_all_declared_notification_types_have_an_explicit_category_and_unknown_is_tested(): void
    {
        $allowed = ['task', 'review', 'comment', 'deadline', 'system'];

        foreach (SystemNotification::TYPE_CATEGORIES as $type => $category) {
            $this->assertContains($category, $allowed, "Unexpected category for {$type}");
            $this->assertSame($category, SystemNotification::categoryForType($type));
        }

        $this->assertSame('system', SystemNotification::categoryForType('future_unknown_type'));
    }

    public function test_relative_time_is_consistently_thai(): void
    {
        $now = CarbonImmutable::parse('2026-08-20 12:00:00', 'Asia/Bangkok');
        $service = app(NotificationService::class);

        $this->assertSame('เมื่อสักครู่', $service->relativeTime($now->subSeconds(30), $now));
        $this->assertSame('5 นาทีที่แล้ว', $service->relativeTime($now->subMinutes(5), $now));
        $this->assertSame('3 ชั่วโมงที่แล้ว', $service->relativeTime($now->subHours(3), $now));
        $this->assertSame('3 วันที่แล้ว', $service->relativeTime($now->subDays(3), $now));
    }

    public function test_deadline_generation_uses_bangkok_day_is_idempotent_and_excludes_viewers(): void
    {
        $assignee = $this->user();
        $viewerCreator = $this->user('viewer');
        $task = $this->task($assignee, $viewerCreator, '2026-08-20 16:59:00');
        $service = app(NotificationMaintenanceService::class);

        $this->assertSame(1, $service->generateDeadlines(CarbonImmutable::parse('2026-08-20 00:01', 'Asia/Bangkok')));
        $this->assertDatabaseHas('system_notifications', ['user_id' => $assignee->id, 'type' => 'deadline_due_today']);
        $this->assertDatabaseMissing('system_notifications', ['user_id' => $viewerCreator->id]);
        $this->assertSame(0, $service->generateDeadlines(CarbonImmutable::parse('2026-08-20 23:59', 'Asia/Bangkok')));
        $this->assertSame(1, $service->generateDeadlines(CarbonImmutable::parse('2026-08-21 00:01', 'Asia/Bangkok')));
        $this->assertDatabaseHas('system_notifications', ['work_order_id' => $task->job_id, 'type' => 'deadline_overdue']);
    }

    public function test_future_next_bangkok_day_is_never_classified_as_overdue(): void
    {
        $assignee = $this->user();
        $creator = $this->user('admin');
        $future = $this->task($assignee, $creator, '2026-08-20 17:30:00'); // 21 Aug 00:30 Bangkok

        app(NotificationMaintenanceService::class)->generateDeadlines(CarbonImmutable::parse('2026-08-20 23:00', 'Asia/Bangkok'));
        $this->assertDatabaseMissing('system_notifications', ['work_order_id' => $future->job_id]);

        app(NotificationMaintenanceService::class)->generateDeadlines(CarbonImmutable::parse('2026-08-21 00:01', 'Asia/Bangkok'));
        $this->assertDatabaseHas('system_notifications', ['work_order_id' => $future->job_id, 'type' => 'deadline_due_today']);
    }

    public function test_filters_pagination_and_bangkok_grouping_are_deterministic(): void
    {
        $user = $this->user();
        $project = WorkOrderList::create(['user_id' => $user->id, 'name' => 'Training']);
        foreach (range(1, 26) as $index) {
            $notice = $this->notice($user, 'comment '.$index, now());
            $notice->update(['category' => 'comment', 'work_order_list_id' => $project->id]);
        }

        $response = $this->actingAs($user)->get(route('notifications.index', [
            'status' => 'unread', 'category' => 'comment', 'project' => $project->id,
        ]))->assertOk();
        $response->assertSee('status=unread', false)->assertSee('category=comment', false)
            ->assertSee('project='.$project->id, false)->assertSee('page=2', false);

        $service = app(NotificationService::class);
        $now = CarbonImmutable::parse('2026-08-20 12:00', 'Asia/Bangkok');
        $this->assertSame('วันนี้', $service->groupLabel($now, $now));
        $this->assertSame('เมื่อวาน', $service->groupLabel($now->subDay(), $now));
        $this->assertSame('7 วันที่ผ่านมา', $service->groupLabel($now->subDays(5), $now));
        $this->assertSame('30 วันที่ผ่านมา', $service->groupLabel($now->subDays(20), $now));
        $this->assertSame('เก่ากว่านั้น', $service->groupLabel($now->subDays(31), $now));
    }

    public function test_notification_center_renders_all_bangkok_calendar_groups(): void
    {
        $user = $this->user();
        $now = CarbonImmutable::parse('2026-08-20 12:00', 'Asia/Bangkok');
        CarbonImmutable::setTestNow($now);
        foreach ([0, 1, 5, 20, 31] as $days) $this->notice($user, 'group '.$days, $now->subDays($days)->utc());

        $this->actingAs($user)->get(route('notifications.index'))->assertOk()
            ->assertSee('วันนี้')->assertSee('เมื่อวาน')->assertSee('7 วันที่ผ่านมา')
            ->assertSee('30 วันที่ผ่านมา')->assertSee('เก่ากว่านั้น');

        CarbonImmutable::setTestNow();
    }

    public function test_pruning_deletes_only_old_read_notifications(): void
    {
        $user = $this->user();
        $oldRead = $this->notice($user, 'old read', now()->subDays(91), now()->subDays(91));
        $oldUnread = $this->notice($user, 'old unread', now()->subDays(91));
        $recentRead = $this->notice($user, 'recent read', now()->subDays(89), now());

        $this->assertSame(1, app(NotificationMaintenanceService::class)->prune(CarbonImmutable::now('Asia/Bangkok')));
        $this->assertDatabaseMissing('system_notifications', ['id' => $oldRead->id]);
        $this->assertDatabaseHas('system_notifications', ['id' => $oldUnread->id]);
        $this->assertDatabaseHas('system_notifications', ['id' => $recentRead->id]);
        $this->assertSame(0, ActivityLog::count());
    }

    private function user(string $role = 'user'): User
    {
        return User::factory()->create(['role' => $role, 'must_change_password' => false, 'is_active' => true]);
    }

    private function notice(User $user, string $title, $createdAt, $readAt = null): SystemNotification
    {
        $notice = SystemNotification::create(['user_id' => $user->id, 'type' => 'system', 'title' => $title, 'read_at' => $readAt]);
        $notice->forceFill(['created_at' => $createdAt, 'updated_at' => $createdAt])->saveQuietly();
        return $notice;
    }

    private function task(User $assignee, User $creator, string $due): WorkOrder
    {
        return WorkOrder::create([
            'user_id' => $assignee->id, 'created_by' => $creator->id, 'leader_user_id' => $creator->id,
            'job_topic' => 'Deadline task', 'job_priority' => 2, 'job_status' => 2,
            'approval_status' => 'approved', 'job_progress' => 20,
            'job_start_at' => '2026-08-19 00:00:00', 'job_due_at' => $due,
        ]);
    }
}
