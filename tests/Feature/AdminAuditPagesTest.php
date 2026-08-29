<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\TrashLog;
use App\Models\User;
use App\Models\WorkOrder;
use App\Models\WorkOrderList;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminAuditPagesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::parse('2026-08-29 12:00:00'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_admin_can_access_audit_pages_and_non_admin_roles_cannot(): void
    {
        $admin = $this->user('admin');

        $this->actingAs($admin)
            ->get(route('admin.trash.index'))
            ->assertOk()
            ->assertViewIs('admin.trash.index');

        $this->actingAs($admin)
            ->get(route('admin.activity-logs.index'))
            ->assertOk()
            ->assertViewIs('admin.activity-logs.index');

        foreach (['user', 'viewer'] as $role) {
            $actor = $this->user($role);

            $this->actingAs($actor)->get(route('admin.trash.index'))->assertForbidden();
            $this->actingAs($actor)->get(route('admin.activity-logs.index'))->assertForbidden();
        }
    }

    public function test_trash_export_and_restore_remain_admin_only(): void
    {
        $admin = $this->user('admin');
        $member = $this->user('user');
        $list = WorkOrderList::create([
            'user_id' => $admin->id,
            'name' => 'Recoverable project',
            'is_visible' => true,
            'sort_order' => 1,
        ]);
        $trash = $this->trash(WorkOrderList::class, $list->id, [
            'list' => $list->getAttributes(),
        ], $admin, 14);

        $this->actingAs($member)->get(route('admin.trash.export'))->assertForbidden();
        $this->actingAs($member)->patch(route('admin.trash.restore', $trash))->assertForbidden();

        $this->actingAs($admin)->get(route('admin.trash.export'))->assertOk();
        $this->actingAs($admin)
            ->patch(route('admin.trash.restore', $trash))
            ->assertRedirect();

        $this->assertDatabaseMissing('trash_logs', ['id' => $trash->id]);
        $this->assertDatabaseHas('work_order_lists', ['id' => $list->id]);
    }

    public function test_trash_metrics_count_work_items_and_only_future_near_expiry_records(): void
    {
        $admin = $this->user('admin');

        $this->trash(WorkOrder::class, 101, ['work_order' => ['job_topic' => 'Task A']], $admin, 3);
        $this->trash(WorkOrderList::class, 102, ['list' => ['name' => 'Project A']], $admin, 7);
        $this->trash(User::class, 103, ['user' => ['name' => 'Former member']], $admin, 8);
        $this->trash('App\\Models\\UnknownAuditSubject', 104, ['name' => 'No retention'], $admin, null);

        $this->actingAs($admin)
            ->get(route('admin.trash.index'))
            ->assertOk()
            ->assertViewHas('stats', function (array $stats): bool {
                return $stats === [
                    'total' => 4,
                    'work_items' => 2,
                    'users' => 1,
                    'near_expiry' => 2,
                ];
            });
    }

    public function test_existing_trash_filters_continue_to_work_together(): void
    {
        $admin = $this->user('admin');
        $otherAdmin = $this->user('admin');
        $matching = $this->trash(WorkOrder::class, 201, [
            'work_order' => [
                'job_topic' => 'Quarterly audit target',
                'department_name' => 'Marketing',
            ],
        ], $admin, 12);
        $this->trash(WorkOrder::class, 202, [
            'work_order' => [
                'job_topic' => 'Wrong actor',
                'department_name' => 'Marketing',
            ],
        ], $otherAdmin, 12);
        $this->trash(User::class, 203, [
            'user' => [
                'name' => 'Wrong type',
                'department_name' => 'Marketing',
            ],
        ], $admin, 12);

        $this->actingAs($admin)
            ->get(route('admin.trash.index', [
                'q' => 'Quarterly audit',
                'entity_type' => WorkOrder::class,
                'department' => 'Marketing',
                'deleted_by' => $admin->id,
            ]))
            ->assertOk()
            ->assertViewHas('trashLogs', fn ($logs): bool => $this->paginatorIds($logs) === [$matching->id]);
    }

    public function test_activity_action_user_and_subject_type_filters_work(): void
    {
        $admin = $this->user('admin');
        $actor = $this->user('user');
        $otherActor = $this->user('user');
        $matching = $this->activity($actor, 'updated', WorkOrder::class, 301, 'Matching activity');
        $this->activity($otherActor, 'updated', WorkOrder::class, 302, 'Wrong actor');
        $this->activity($actor, 'deleted', WorkOrderList::class, 303, 'Wrong action and type');

        $this->actingAs($admin)
            ->get(route('admin.activity-logs.index', ['action' => 'updated']))
            ->assertViewHas('logs', fn ($logs): bool => in_array($matching->id, $this->paginatorIds($logs), true));

        $this->actingAs($admin)
            ->get(route('admin.activity-logs.index', ['user_id' => $actor->id]))
            ->assertViewHas('logs', fn ($logs): bool => in_array($matching->id, $this->paginatorIds($logs), true));

        $this->actingAs($admin)
            ->get(route('admin.activity-logs.index', ['subject_type' => WorkOrder::class]))
            ->assertViewHas('logs', fn ($logs): bool => in_array($matching->id, $this->paginatorIds($logs), true));

        $this->actingAs($admin)
            ->get(route('admin.activity-logs.index', [
                'action' => 'updated',
                'user_id' => $actor->id,
                'subject_type' => WorkOrder::class,
            ]))
            ->assertViewHas('logs', fn ($logs): bool => $this->paginatorIds($logs) === [$matching->id]);
    }

    public function test_activity_search_supports_actor_description_and_subject_id(): void
    {
        $admin = $this->user('admin');
        $actor = User::factory()->create([
            'name' => 'Unique Audit Actor',
            'email' => 'audit.actor@example.test',
            'role' => 'user',
            'must_change_password' => false,
            'is_active' => true,
        ]);
        $matching = $this->activity(
            $actor,
            'updated',
            WorkOrder::class,
            987654,
            'Unique searchable description'
        );
        $this->activity($this->user('user'), 'created', WorkOrderList::class, 500, 'Unrelated entry');

        foreach (['Unique Audit Actor', 'audit.actor@example.test', 'searchable description', '987654'] as $search) {
            $this->actingAs($admin)
                ->get(route('admin.activity-logs.index', ['q' => $search]))
                ->assertOk()
                ->assertViewHas('logs', fn ($logs): bool => $this->paginatorIds($logs) === [$matching->id]);
        }
    }

    public function test_activity_target_fallback_detail_and_sensitive_redaction_are_safe(): void
    {
        $admin = $this->user('admin');
        $actor = $this->user('user');
        $beforeAfter = $this->activity(
            $actor,
            'updated',
            WorkOrder::class,
            401,
            'Updated task details',
            [
                'before' => [
                    'name' => 'Old target name',
                    'password' => 'top-level-password-secret',
                    'profile' => [
                        'label' => 'Old profile',
                        'remember_token' => 'nested-token-secret',
                    ],
                ],
                'after' => [
                    'job_topic' => 'New target topic',
                    'password' => 'new-password-secret',
                    'profile' => [
                        'label' => 'New profile',
                        'password' => 'nested-password-secret',
                    ],
                ],
            ]
        );
        $this->activity($actor, 'created', WorkOrderList::class, 402, null, [
            'new' => ['title' => 'New-shape project title'],
        ]);
        $this->activity($actor, 'updated', WorkOrderList::class, 403, null, [
            'name' => 'Flat-shape target name',
        ]);
        $this->activity($actor, 'updated', WorkOrder::class, 404, 'Description fallback target', [
            'before' => 'invalid-shape',
            'after' => 42,
        ]);

        $response = $this->actingAs($admin)
            ->get(route('admin.activity-logs.index'))
            ->assertOk()
            ->assertSee('New target topic')
            ->assertSee('New-shape project title')
            ->assertSee('Flat-shape target name')
            ->assertSee('Description fallback target')
            ->assertSee('logModal'.$beforeAfter->id, false)
            ->assertSee('[REDACTED]')
            ->assertDontSee('top-level-password-secret')
            ->assertDontSee('new-password-secret')
            ->assertDontSee('nested-token-secret')
            ->assertDontSee('nested-password-secret');

        $this->assertStringContainsString('log-change-row', $response->getContent());
    }

    private function user(string $role): User
    {
        return User::factory()->create([
            'role' => $role,
            'must_change_password' => false,
            'is_active' => true,
        ]);
    }

    private function trash(
        string $entityType,
        int $entityId,
        array $payload,
        User $deletedBy,
        ?int $purgeInDays
    ): TrashLog {
        return TrashLog::create([
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'payload_json' => $payload,
            'deleted_by' => $deletedBy->id,
            'deleted_at' => now()->subDay(),
            'purge_after' => $purgeInDays === null ? null : now()->addDays($purgeInDays),
        ]);
    }

    private function activity(
        User $actor,
        string $action,
        string $subjectType,
        int $subjectId,
        ?string $description,
        array $changes = []
    ): ActivityLog {
        return ActivityLog::create([
            'user_id' => $actor->id,
            'action' => $action,
            'subject_type' => $subjectType,
            'subject_id' => $subjectId,
            'description' => $description,
            'changes' => $changes,
            'ip_address' => '127.0.0.1',
            'created_at' => now(),
        ]);
    }

    private function paginatorIds($paginator): array
    {
        return collect($paginator->items())
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->values()
            ->all();
    }
}
