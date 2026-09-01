<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\TrashLog;
use App\Models\User;
use App\Models\WorkOrder;
use App\Models\WorkOrderList;
use App\Support\AuditSnapshot;
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
            ->get(route('admin.audit.index', ['tab' => 'trash']))
            ->assertOk()
            ->assertViewIs('admin.audit.index');

        $this->actingAs($admin)
            ->get(route('admin.audit.index', ['tab' => 'activity']))
            ->assertOk()
            ->assertViewIs('admin.audit.index');

        foreach (['user', 'viewer'] as $role) {
            $actor = $this->user($role);

            $this->actingAs($actor)->get(route('admin.audit.index', ['tab' => 'trash']))->assertForbidden();
            $this->actingAs($actor)->get(route('admin.audit.index', ['tab' => 'activity']))->assertForbidden();
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
            ->get(route('admin.audit.index', ['tab' => 'trash']))
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
            ->get(route('admin.audit.index', ['tab' => 'trash',
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
            ->get(route('admin.audit.index', ['tab' => 'activity', 'action' => 'updated']))
            ->assertViewHas('logs', fn ($logs): bool => in_array($matching->id, $this->paginatorIds($logs), true));

        $this->actingAs($admin)
            ->get(route('admin.audit.index', ['tab' => 'activity', 'user_id' => $actor->id]))
            ->assertViewHas('logs', fn ($logs): bool => in_array($matching->id, $this->paginatorIds($logs), true));

        $this->actingAs($admin)
            ->get(route('admin.audit.index', ['tab' => 'activity', 'subject_type' => WorkOrder::class]))
            ->assertViewHas('logs', fn ($logs): bool => in_array($matching->id, $this->paginatorIds($logs), true));

        $this->actingAs($admin)
            ->get(route('admin.audit.index', ['tab' => 'activity',
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
                ->get(route('admin.audit.index', ['tab' => 'activity', 'q' => $search]))
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
            ->get(route('admin.audit.index', ['tab' => 'activity']))
            ->assertOk()
            ->assertSee('New target topic')
            ->assertSee('New-shape project title')
            ->assertSee('Flat-shape target name')
            ->assertSee('Description fallback target')
            ->assertSee('auditLog'.$beforeAfter->id, false)
            ->assertSee('[REDACTED]')
            ->assertDontSee('top-level-password-secret')
            ->assertDontSee('new-password-secret')
            ->assertDontSee('nested-token-secret')
            ->assertDontSee('nested-password-secret');

        $this->assertStringContainsString('audit-change-row', $response->getContent());
    }

    public function test_all_three_tabs_open_for_admin_and_stay_closed_for_other_roles(): void
    {
        $admin = $this->user('admin');

        foreach (['overview', 'activity', 'trash'] as $tab) {
            $this->actingAs($admin)
                ->get(route('admin.audit.index', ['tab' => $tab]))
                ->assertOk()
                ->assertViewIs('admin.audit.index')
                ->assertViewHas('tab', $tab);
        }

        // แท็บที่ไม่รู้จักต้องตกกลับมาที่ภาพรวม ไม่ใช่ error
        $this->actingAs($admin)
            ->get(route('admin.audit.index', ['tab' => 'not-a-tab']))
            ->assertOk()
            ->assertViewHas('tab', 'overview');

        foreach (['user', 'viewer'] as $role) {
            $actor = $this->user($role);

            foreach (['overview', 'activity', 'trash'] as $tab) {
                $this->actingAs($actor)->get(route('admin.audit.index', ['tab' => $tab]))->assertForbidden();
            }
        }
    }

    public function test_legacy_urls_redirect_to_the_matching_tab_and_keep_their_filters(): void
    {
        $admin = $this->user('admin');
        $actor = $this->user('user');

        $this->actingAs($admin)
            ->get('/admin/activity-logs?q=alpha&user_id='.$actor->id)
            ->assertRedirect(route('admin.audit.index', [
                'tab' => 'activity',
                'q' => 'alpha',
                'user_id' => $actor->id,
            ]));

        $this->actingAs($admin)
            ->get('/admin/trash?department=Marketing')
            ->assertRedirect(route('admin.audit.index', [
                'tab' => 'trash',
                'department' => 'Marketing',
            ]));
    }

    public function test_shared_filters_apply_to_every_tab_so_switching_never_changes_the_question(): void
    {
        $admin = $this->user('admin');
        $actor = $this->user('user');
        $otherActor = $this->user('user');

        $mine = $this->activity($actor, 'updated', WorkOrder::class, 601, 'Actor entry');
        $this->activity($otherActor, 'updated', WorkOrder::class, 602, 'Other entry');
        $myTrash = $this->trash(WorkOrder::class, 603, ['work_order' => ['job_topic' => 'Actor deletion']], $actor, 10);
        $this->trash(WorkOrder::class, 604, ['work_order' => ['job_topic' => 'Other deletion']], $otherActor, 10);

        $this->actingAs($admin)
            ->get(route('admin.audit.index', ['tab' => 'activity', 'user_id' => $actor->id]))
            ->assertOk()
            ->assertViewHas('logs', fn ($logs): bool => $this->paginatorIds($logs) === [$mine->id]);

        // ตัวกรอง "ผู้ทำรายการ" ตัวเดียวกันต้องหมายถึงผู้ลบเมื่ออยู่บนแท็บถังขยะ
        $this->actingAs($admin)
            ->get(route('admin.audit.index', ['tab' => 'trash', 'user_id' => $actor->id]))
            ->assertOk()
            ->assertViewHas('trashLogs', fn ($trashLogs): bool => $this->paginatorIds($trashLogs) === [$myTrash->id]);
    }

    public function test_date_range_filter_cuts_on_bangkok_day_boundaries_not_utc(): void
    {
        $admin = $this->user('admin');
        $actor = $this->user('user');

        // 29 ส.ค. 23:30 เวลาไทย = 16:30 UTC ของวันเดียวกัน
        Carbon::setTestNow(Carbon::parse('2026-08-29 16:30:00'));
        $lateOnThe29th = $this->activity($actor, 'updated', WorkOrder::class, 701, 'Late evening in Bangkok');

        // 30 ส.ค. 00:30 เวลาไทย = 29 ส.ค. 17:30 UTC — ยัง "วันที่ 29" ถ้าอ่านแบบ UTC
        Carbon::setTestNow(Carbon::parse('2026-08-29 17:30:00'));
        $earlyOnThe30th = $this->activity($actor, 'updated', WorkOrder::class, 702, 'Just after midnight in Bangkok');

        Carbon::setTestNow(Carbon::parse('2026-08-31 12:00:00'));

        $this->actingAs($admin)
            ->get(route('admin.audit.index', ['tab' => 'activity', 'from' => '2026-08-30']))
            ->assertOk()
            ->assertViewHas('logs', fn ($logs): bool => $this->paginatorIds($logs) === [$earlyOnThe30th->id]);

        $this->actingAs($admin)
            ->get(route('admin.audit.index', ['tab' => 'activity', 'to' => '2026-08-29']))
            ->assertOk()
            ->assertViewHas('logs', fn ($logs): bool => $this->paginatorIds($logs) === [$lateOnThe29th->id]);
    }

    public function test_overview_counts_logins_failures_and_changes_separately(): void
    {
        $admin = $this->user('admin');
        $actor = $this->user('user');

        $this->activity($actor, 'login', User::class, $actor->id, 'เข้าสู่ระบบ');
        $this->activity($actor, 'login', User::class, $actor->id, 'เข้าสู่ระบบ');
        $this->activity($actor, 'login_failed', User::class, $actor->id, 'เข้าสู่ระบบไม่สำเร็จ');
        $this->activity($actor, 'updated', WorkOrder::class, 801, 'แก้ไขงาน');
        $this->trash(WorkOrder::class, 802, ['work_order' => ['job_topic' => 'Deleted task']], $actor, 3);

        $this->actingAs($admin)
            ->get(route('admin.audit.index', ['tab' => 'overview']))
            ->assertOk()
            ->assertViewHas('stats', function (array $stats): bool {
                return $stats['logins_today'] === 2
                    && $stats['failed_logins_today'] === 1
                    && $stats['changes_today'] === 1
                    && $stats['trash_total'] === 1
                    && $stats['near_expiry'] === 1;
            });
    }

    public function test_trash_snapshot_is_readable_and_never_leaks_sensitive_values(): void
    {
        $admin = $this->user('admin');
        $actor = $this->user('user');
        $this->trash(User::class, 901, [
            'user' => [
                'name' => 'พนักงานที่ถูกลบ',
                'email' => 'removed.member@example.test',
                'role' => 'user',
                'password' => 'trash-password-secret',
                'remember_token' => 'trash-token-secret',
            ],
        ], $actor, 12);

        $this->actingAs($admin)
            ->get(route('admin.audit.index', ['tab' => 'trash']))
            ->assertOk()
            // ป้ายภาษาไทยแทนการ dump JSON ดิบเป็นมุมมองหลัก
            ->assertSee('พนักงานที่ถูกลบ')
            ->assertSee('removed.member@example.test')
            ->assertSee('สิทธิ์การใช้งาน')
            ->assertSee('พนักงาน')
            ->assertDontSee('trash-password-secret')
            ->assertDontSee('trash-token-secret');
    }

    public function test_each_event_reads_as_one_sentence_instead_of_disconnected_columns(): void
    {
        $actor = User::factory()->create(['name' => 'System Admin', 'role' => 'admin', 'must_change_password' => false]);
        $log = $this->activity($actor, 'deleted', User::class, 55, 'ลบพนักงาน', [
            'before' => ['name' => 'solofrislo'],
        ]);

        $entry = AuditSnapshot::describe($log);

        $this->assertSame('System Admin', $entry['actor']);
        $this->assertSame('ลบ', $entry['action']);
        $this->assertSame('พนักงาน', $entry['subject']);
        $this->assertSame('solofrislo', $entry['target']);
        $this->assertSame('จากหมายเลข IP 127.0.0.1', $entry['meta']);
    }

    /** เหตุการณ์เข้าออกระบบมีเป้าหมายเป็นตัวผู้ใช้เอง การแสดงชื่อซ้ำสองครั้งทำให้อ่านสับสน */
    public function test_auth_events_do_not_repeat_the_actor_name_as_their_own_target(): void
    {
        $actor = User::factory()->create(['name' => 'System Admin', 'role' => 'admin', 'must_change_password' => false]);

        $entry = AuditSnapshot::describe($this->activity($actor, 'login', User::class, $actor->id, 'เข้าสู่ระบบ: System Admin'));

        $this->assertSame('System Admin', $entry['actor']);
        $this->assertSame('เข้าสู่ระบบ', $entry['action']);
        $this->assertNull($entry['subject']);
        $this->assertNull($entry['target']);
        $this->assertSame('จากหมายเลข IP 127.0.0.1', $entry['meta']);
    }

    public function test_a_failed_login_names_the_attempted_account_even_without_a_user(): void
    {
        $log = ActivityLog::create([
            'action' => 'login_failed',
            'description' => 'เข้าสู่ระบบไม่สำเร็จ',
            'changes' => ['username' => 'ghost.account'],
            'ip_address' => '10.0.0.9',
            'created_at' => now(),
        ]);

        $entry = AuditSnapshot::describe($log);

        $this->assertSame('บัญชี ghost.account', $entry['actor']);
        $this->assertSame('จากหมายเลข IP 10.0.0.9', $entry['meta']);
    }

    public function test_retention_note_states_the_outcome_not_only_the_number(): void
    {
        $this->assertSame('เหลืออีก 12 วันก่อนลบถาวร', AuditSnapshot::retentionNote(12, true));
        $this->assertSame('ครบกำหนดแล้ว กู้คืนไม่ได้', AuditSnapshot::retentionNote(0, false));
        $this->assertSame('ไม่มีกำหนดลบถาวร กู้คืนได้', AuditSnapshot::retentionNote(null, true));
        $this->assertSame('เหลืออีก 3 วันก่อนลบถาวร (กู้คืนไม่ได้)', AuditSnapshot::retentionNote(3, false));
    }

    public function test_overview_renders_the_sentence_and_the_stat_notes(): void
    {
        $admin = $this->user('admin');
        $actor = User::factory()->create(['name' => 'System Admin', 'role' => 'admin', 'must_change_password' => false]);
        $this->activity($actor, 'deleted', User::class, 55, 'ลบพนักงาน', ['before' => ['name' => 'solofrislo']]);

        $this->actingAs($admin)
            ->get(route('admin.audit.index', ['tab' => 'overview']))
            ->assertOk()
            ->assertSee('audit-sentence', false)
            ->assertSee('System Admin')
            ->assertSee('solofrislo')
            // ตัวเลขบนการ์ดต้องมีคำขยายบอกว่านับอะไรถึงเมื่อไร
            ->assertSee('วันนี้ นับการสร้าง แก้ไข และลบ')
            ->assertSee('เก็บไว้ 30 วันนับจากวันที่ลบ');
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
