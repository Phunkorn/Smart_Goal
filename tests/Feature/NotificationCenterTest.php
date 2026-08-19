<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\SystemNotification;
use App\Models\User;
use App\Models\WorkOrder;
use App\Models\WorkOrderList;
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
                ->assertSee('data-notification-count', false);
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
        $this->actingAs($owner)->patchJson(route('notifications.read', $otherNotice))->assertNotFound();
        $this->actingAs($owner)->patchJson(route('notifications.unread', $oldUnread))->assertOk()->assertJsonPath('unread_count', 1);
        $this->actingAs($owner)->postJson(route('notifications.read-all'))->assertOk()->assertJsonPath('unread_count', 0);
        $this->assertDatabaseHas('system_notifications', ['id' => $oldRead->id]);
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
