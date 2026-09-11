<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\TrashLog;
use App\Models\User;
use App\Models\WorkOrder;
use App\Models\WorkOrderList;
use App\Support\LogRetention;
use App\Support\SchedulerHeartbeat;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * การล้างข้อมูลค้างในหน้า Audit Log
 *
 * ครอบคลุมสามอย่างที่เพิ่มเข้ามาพร้อมกัน เพราะทั้งหมดตอบปัญหาเดียวกันคือข้อมูลเก่า
 * ไม่เคยถูกล้างบนเครื่องที่ไม่ได้ตั้ง cron:
 *
 *  - นโยบายอายุของบันทึกกิจกรรม (LogRetention)
 *  - การกู้คืนและลบถาวรหลายรายการในถังขยะ
 *  - แถบบอกสถานะงานตามเวลา
 */
class AuditLogRetentionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::parse('2026-09-08 12:00:00'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_retention_keeps_critical_evidence_longer_than_routine_changes(): void
    {
        $actor = $this->user('user');

        // เข้าสู่ระบบเมื่อ 60 วันก่อน — ยังไม่ครบ 90 วัน ต้องอยู่ต่อ
        $login = $this->activity($actor, 'login', 60);
        // แก้ไขข้อมูลเมื่อ 200 วันก่อน — เกิน 30 วันแล้ว ต้องถูกล้าง
        $staleUpdate = $this->activity($actor, 'updated', 200);
        // แก้ไขข้อมูลเมื่อ 30 วันก่อน — ยังไม่ถึงกำหนด
        $freshUpdate = $this->activity($actor, 'updated', 30);
        // เข้าสู่ระบบเมื่อ 120 วันก่อน — เกิน 90 วันแล้ว แม้เป็นหลักฐานสำคัญก็ต้องถูกล้าง
        $ancientLogin = $this->activity($actor, 'login', 120);

        $this->assertSame(2, LogRetention::prunableCount());

        $deleted = LogRetention::pruneActivity($actor);

        $this->assertSame(2, $deleted);
        $this->assertDatabaseHas('activity_logs', ['id' => $login->id]);
        $this->assertDatabaseHas('activity_logs', ['id' => $freshUpdate->id]);
        $this->assertDatabaseMissing('activity_logs', ['id' => $staleUpdate->id]);
        $this->assertDatabaseMissing('activity_logs', ['id' => $ancientLogin->id]);
    }

    /**
     * การล้างบันทึกต้องทิ้งร่องรอยของตัวเองไว้ ไม่ใช่หายไปเงียบ ๆ
     *
     * และแถวสรุปนั้นต้องถูกจัดเป็นหลักฐานสำคัญ มิฉะนั้นการล้างครั้งถัดไปจะลบหลักฐาน
     * ว่าเคยมีการล้างเกิดขึ้น
     */
    public function test_pruning_writes_one_summary_row_that_survives_its_own_policy(): void
    {
        $admin = $this->user('admin');
        $this->activity($admin, 'updated', 120);
        $this->activity($admin, 'updated', 130);

        LogRetention::pruneActivity($admin);

        $summary = ActivityLog::where('action', 'activity_pruned')->get();

        $this->assertCount(1, $summary);
        $this->assertSame(2, $summary->first()->changes['deleted']);
        $this->assertSame($admin->id, $summary->first()->changes['pruned_by']);
        $this->assertContains('activity_pruned', LogRetention::CRITICAL_ACTIONS);

        // ล้างซ้ำทันทีต้องไม่ลบแถวสรุปที่เพิ่งเขียน
        $this->assertSame(0, LogRetention::pruneActivity($admin));
        $this->assertDatabaseHas('activity_logs', ['id' => $summary->first()->id]);
    }

    public function test_admin_can_prune_activity_from_the_page_and_others_cannot(): void
    {
        $admin = $this->user('admin');
        $stale = $this->activity($admin, 'updated', 200);

        foreach (['user', 'viewer'] as $role) {
            $this->actingAs($this->user($role))
                ->delete(route('admin.audit.activity.prune'))
                ->assertForbidden();
        }

        $this->assertDatabaseHas('activity_logs', ['id' => $stale->id]);

        $this->actingAs($admin)
            ->delete(route('admin.audit.activity.prune'))
            ->assertRedirect();

        $this->assertDatabaseMissing('activity_logs', ['id' => $stale->id]);
    }

    public function test_activity_tab_reports_how_many_rows_can_be_pruned(): void
    {
        $admin = $this->user('admin');
        $this->activity($admin, 'updated', 200);
        $this->activity($admin, 'updated', 10);

        $this->actingAs($admin)
            ->get(route('admin.audit.index', ['tab' => 'activity']))
            ->assertOk()
            ->assertViewHas('prunableCount', 1);
    }

    public function test_bulk_purge_removes_only_the_selected_rows(): void
    {
        $admin = $this->user('admin');
        $selected = $this->trash(WorkOrder::class, 601, ['work_order' => ['job_topic' => 'ลบชุด A']], $admin);
        $alsoSelected = $this->trash(WorkOrder::class, 602, ['work_order' => ['job_topic' => 'ลบชุด B']], $admin);
        $untouched = $this->trash(WorkOrder::class, 603, ['work_order' => ['job_topic' => 'เก็บไว้']], $admin);

        $this->actingAs($admin)
            ->delete(route('admin.trash.bulk-purge'), ['ids' => [$selected->id, $alsoSelected->id]])
            ->assertRedirect();

        $this->assertDatabaseMissing('trash_logs', ['id' => $selected->id]);
        $this->assertDatabaseMissing('trash_logs', ['id' => $alsoSelected->id]);
        $this->assertDatabaseHas('trash_logs', ['id' => $untouched->id]);

        // บันทึกสรุปแถวเดียวต่อการกดหนึ่งครั้ง ไม่ใช่แถวต่อรายการที่ลบ
        $this->assertSame(1, ActivityLog::where('action', 'bulk_purged')->count());
        $this->assertSame(2, ActivityLog::where('action', 'bulk_purged')->first()->changes['purged']);
    }

    /**
     * scope=filtered ต้องเคารพตัวกรองที่ผู้ใช้เห็นอยู่ ไม่ใช่กวาดทั้งถังขยะ
     */
    public function test_bulk_purge_with_filtered_scope_respects_the_active_filter(): void
    {
        $admin = $this->user('admin');
        $matching = $this->trash(WorkOrder::class, 701, ['work_order' => ['job_topic' => 'ตรงตัวกรอง']], $admin);
        $other = $this->trash(User::class, 702, ['user' => ['name' => 'คนละประเภท']], $admin);

        $this->actingAs($admin)
            ->delete(route('admin.trash.bulk-purge', ['entity_type' => WorkOrder::class]), ['scope' => 'filtered'])
            ->assertRedirect();

        $this->assertDatabaseMissing('trash_logs', ['id' => $matching->id]);
        $this->assertDatabaseHas('trash_logs', ['id' => $other->id]);
    }

    public function test_bulk_restore_skips_unrecoverable_rows_instead_of_failing_the_whole_batch(): void
    {
        $admin = $this->user('admin');
        $list = WorkOrderList::create([
            'user_id' => $admin->id,
            'name' => 'โปรเจกต์ที่กู้คืนได้',
            'is_visible' => true,
            'sort_order' => 1,
        ]);
        $recoverable = $this->trash(WorkOrderList::class, $list->id, ['list' => $list->getAttributes()], $admin);
        // พ้นกำหนดกู้คืนแล้ว ต้องถูกข้าม ไม่ใช่ทำให้ทั้งชุดล้มเหลว
        $expired = $this->trash(WorkOrder::class, 801, ['work_order' => ['job_topic' => 'หมดอายุ']], $admin, -1);

        $this->actingAs($admin)
            ->patch(route('admin.trash.bulk-restore'), ['ids' => [$recoverable->id, $expired->id]])
            ->assertRedirect();

        $this->assertDatabaseMissing('trash_logs', ['id' => $recoverable->id]);
        $this->assertDatabaseHas('trash_logs', ['id' => $expired->id]);
        $this->assertDatabaseHas('work_order_lists', ['id' => $list->id]);
    }

    public function test_bulk_endpoints_stay_admin_only(): void
    {
        $admin = $this->user('admin');
        $trash = $this->trash(WorkOrder::class, 901, ['work_order' => ['job_topic' => 'ห้ามแตะ']], $admin);

        foreach (['user', 'viewer'] as $role) {
            $actor = $this->user($role);

            $this->actingAs($actor)
                ->delete(route('admin.trash.bulk-purge'), ['ids' => [$trash->id]])
                ->assertForbidden();

            $this->actingAs($actor)
                ->patch(route('admin.trash.bulk-restore'), ['ids' => [$trash->id]])
                ->assertForbidden();
        }

        $this->assertDatabaseHas('trash_logs', ['id' => $trash->id]);
    }

    /**
     * หน้าภาพรวมต้องบอกเองว่างานตามเวลาทำงานอยู่หรือไม่
     *
     * เครื่องที่ไม่ได้ตั้ง cron ให้ schedule:run จะไม่มีอะไรล้างข้อมูลให้เลย ผู้ดูแลระบบ
     * ต้องรู้ตัวจากหน้าเว็บ ไม่ใช่จากการสังเกตว่าข้อมูลไม่ลดลงสักที
     */
    public function test_overview_reports_scheduler_health_from_its_heartbeat(): void
    {
        $admin = $this->user('admin');

        Cache::forget(SchedulerHeartbeat::KEY);

        $this->actingAs($admin)
            ->get(route('admin.audit.index'))
            ->assertOk()
            ->assertViewHas('scheduler', fn (array $scheduler): bool => $scheduler['is_healthy'] === false
                && $scheduler['last_run'] === null);

        SchedulerHeartbeat::record();

        $this->actingAs($admin)
            ->get(route('admin.audit.index'))
            ->assertOk()
            ->assertViewHas('scheduler', fn (array $scheduler): bool => $scheduler['is_healthy'] === true);

        // เงียบไปนานกว่าเกณฑ์ = ถือว่าไม่ทำงาน แม้จะเคยทำงานมาก่อน
        Carbon::setTestNow(Carbon::now()->addMinutes(SchedulerHeartbeat::STALE_MINUTES + 5));

        $this->actingAs($admin)
            ->get(route('admin.audit.index'))
            ->assertOk()
            ->assertViewHas('scheduler', fn (array $scheduler): bool => $scheduler['is_healthy'] === false
                && $scheduler['last_run'] !== null);
    }

    private function user(string $role): User
    {
        return User::factory()->create([
            'role' => $role,
            'must_change_password' => false,
            'is_active' => true,
        ]);
    }

    private function activity(User $actor, string $action, int $daysAgo): ActivityLog
    {
        return ActivityLog::create([
            'user_id' => $actor->id,
            'action' => $action,
            'subject_type' => WorkOrder::class,
            'subject_id' => 1,
            'description' => $action.' เมื่อ '.$daysAgo.' วันก่อน',
            'changes' => [],
            'ip_address' => '127.0.0.1',
            'created_at' => now()->subDays($daysAgo),
        ]);
    }

    private function trash(
        string $entityType,
        int $entityId,
        array $payload,
        User $deletedBy,
        int $purgeInDays = 20
    ): TrashLog {
        return TrashLog::create([
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'payload_json' => $payload,
            'deleted_by' => $deletedBy->id,
            'deleted_at' => now()->subDay(),
            'purge_after' => now()->addDays($purgeInDays),
        ]);
    }
}
