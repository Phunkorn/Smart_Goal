<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\User;
use App\Models\WorkOrder;
use App\Models\WorkOrderList;
use App\Services\AuditRevertService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ย้อนค่าที่ถูกแก้ทับกลับไปเป็นค่าเดิม
 *
 * ชื่องาน หัวข้อโปรเจกต์ และรายละเอียด ไม่ได้ถูกลบ จึงไม่เคยเข้าถังขยะ แต่ถูกเขียนทับ
 * ค่าเดิมยังอยู่ครบใน changes.before ของบันทึกกิจกรรม เพราะ handler ส่วนใหญ่
 * snapshot ทั้งแถวไม่ใช่เฉพาะฟิลด์ที่แตะ
 */
class AuditRevertTest extends TestCase
{
    use RefreshDatabase;

    public function test_an_admin_can_revert_a_task_title_to_its_previous_value(): void
    {
        $admin = $this->user('admin');
        $task = $this->task($admin, 'ชื่อใหม่ที่พิมพ์ผิด');
        $log = $this->log($admin, WorkOrder::class, $task->job_id, [
            'before' => ['job_topic' => 'ชื่อเดิมที่ถูกต้อง', 'job_details' => 'รายละเอียดเดิม'],
            'after' => ['job_topic' => 'ชื่อใหม่ที่พิมพ์ผิด', 'job_details' => 'รายละเอียดเดิม'],
        ]);

        $this->actingAs($admin)
            ->post(route('admin.audit.revert', $log), ['fields' => ['job_topic']])
            ->assertRedirect();

        $this->assertSame('ชื่อเดิมที่ถูกต้อง', $task->fresh()->job_topic);
    }

    /**
     * ผู้ใช้เลือกได้ทีละฟิลด์ ฟิลด์ที่ไม่ได้ติ๊กต้องไม่ถูกแตะ
     */
    public function test_only_the_selected_fields_are_reverted(): void
    {
        $admin = $this->user('admin');
        $task = $this->task($admin, 'ชื่อปัจจุบัน');
        $task->forceFill(['job_details' => 'รายละเอียดปัจจุบัน'])->save();

        $log = $this->log($admin, WorkOrder::class, $task->job_id, [
            'before' => ['job_topic' => 'ชื่อเก่า', 'job_details' => 'รายละเอียดเก่า'],
        ]);

        $this->actingAs($admin)
            ->post(route('admin.audit.revert', $log), ['fields' => ['job_details']])
            ->assertRedirect();

        $fresh = $task->fresh();
        $this->assertSame('ชื่อปัจจุบัน', $fresh->job_topic, 'ฟิลด์ที่ไม่ได้เลือกต้องไม่ถูกแตะ');
        $this->assertSame('รายละเอียดเก่า', $fresh->job_details);
    }

    /**
     * สถานะงานเป็น state machine ต้องผ่าน TaskStatusTransitionService เท่านั้น
     * การเขียนทับตรง ๆ จะข้ามการตรวจงานย่อย ประวัติ และการแจ้งเตือนทั้งหมด
     */
    public function test_job_status_can_never_be_reverted(): void
    {
        $admin = $this->user('admin');
        $task = $this->task($admin, 'งานทดสอบ');
        $log = $this->log($admin, WorkOrder::class, $task->job_id, [
            'before' => ['job_status' => 5, 'approval_status' => 'pending', 'job_topic' => 'ชื่อเก่า'],
        ]);

        $fields = collect(app(AuditRevertService::class)->revertableFields($log))->pluck('field');

        $this->assertNotContains('job_status', $fields);
        $this->assertNotContains('approval_status', $fields);
        $this->assertContains('job_topic', $fields);

        // ยิงตรงไปที่ปลายทางก็ต้องไม่ผ่าน
        $this->actingAs($admin)
            ->post(route('admin.audit.revert', $log), ['fields' => ['job_status']])
            ->assertStatus(422);

        $this->assertSame(2, (int) $task->fresh()->job_status);
    }

    public function test_reverting_is_admin_only(): void
    {
        $admin = $this->user('admin');
        $task = $this->task($admin, 'ชื่อปัจจุบัน');
        $log = $this->log($admin, WorkOrder::class, $task->job_id, ['before' => ['job_topic' => 'ชื่อเก่า']]);

        foreach (['user', 'viewer'] as $role) {
            $this->actingAs($this->user($role))
                ->post(route('admin.audit.revert', $log), ['fields' => ['job_topic']])
                ->assertForbidden();
        }

        $this->assertSame('ชื่อปัจจุบัน', $task->fresh()->job_topic);
    }

    /**
     * การย้อนต้องตรวจสอบย้อนหลังได้เหมือนการแก้ครั้งอื่น และย้อนซ้ำได้อีกชั้น
     */
    public function test_a_revert_writes_its_own_activity_entry(): void
    {
        $admin = $this->user('admin');
        $task = $this->task($admin, 'ชื่อปัจจุบัน');
        $log = $this->log($admin, WorkOrder::class, $task->job_id, ['before' => ['job_topic' => 'ชื่อเก่า']]);

        $this->actingAs($admin)->post(route('admin.audit.revert', $log), ['fields' => ['job_topic']]);

        $revertLog = ActivityLog::where('action', 'reverted')->firstOrFail();

        $this->assertSame($admin->id, $revertLog->user_id);
        $this->assertSame('ชื่อปัจจุบัน', $revertLog->changes['before']['job_topic']);
        $this->assertSame('ชื่อเก่า', $revertLog->changes['after']['job_topic']);
        $this->assertSame($log->id, $revertLog->changes['reverted_from_log_id']);

        // ย้อนของการย้อนได้อีก จึงไม่มีทางติดอยู่ในค่าที่ย้อนผิด
        $this->actingAs($admin)
            ->post(route('admin.audit.revert', $revertLog), ['fields' => ['job_topic']])
            ->assertRedirect();

        $this->assertSame('ชื่อปัจจุบัน', $task->fresh()->job_topic);
    }

    /**
     * ค่าที่ตรงกับปัจจุบันอยู่แล้วต้องไม่ขึ้นปุ่มให้กดเปล่า
     */
    public function test_fields_that_already_match_are_not_offered(): void
    {
        $admin = $this->user('admin');
        $task = $this->task($admin, 'ชื่อเดียวกัน');
        $log = $this->log($admin, WorkOrder::class, $task->job_id, ['before' => ['job_topic' => 'ชื่อเดียวกัน']]);

        $this->assertSame([], app(AuditRevertService::class)->revertableFields($log));
        $this->assertFalse(app(AuditRevertService::class)->canRevert($log));
    }

    /**
     * ข้อมูลที่ถูกลบไปแล้วต้องกู้คืนจากถังขยะก่อน ไม่ใช่เขียนลงแถวที่มองไม่เห็น
     */
    public function test_a_soft_deleted_subject_cannot_be_reverted(): void
    {
        $admin = $this->user('admin');
        $task = $this->task($admin, 'ชื่อปัจจุบัน');
        $log = $this->log($admin, WorkOrder::class, $task->job_id, ['before' => ['job_topic' => 'ชื่อเก่า']]);
        $task->delete();

        $this->assertSame([], app(AuditRevertService::class)->revertableFields($log));

        $this->actingAs($admin)
            ->post(route('admin.audit.revert', $log), ['fields' => ['job_topic']])
            ->assertNotFound();
    }

    public function test_project_names_and_descriptions_are_revertable(): void
    {
        $admin = $this->user('admin');
        $list = WorkOrderList::create(['user_id' => $admin->id, 'name' => 'ชื่อโปรเจกต์ใหม่', 'priority' => 2]);
        $log = $this->log($admin, WorkOrderList::class, $list->id, [
            'before' => ['name' => 'ชื่อโปรเจกต์เดิม'],
        ]);

        $this->actingAs($admin)
            ->post(route('admin.audit.revert', $log), ['fields' => ['name']])
            ->assertRedirect();

        $this->assertSame('ชื่อโปรเจกต์เดิม', $list->fresh()->name);
    }

    private function user(string $role): User
    {
        return User::factory()->create([
            'role' => $role,
            'is_active' => true,
            'must_change_password' => false,
        ]);
    }

    private function task(User $owner, string $topic): WorkOrder
    {
        return WorkOrder::create([
            'user_id' => $owner->id,
            'created_by' => $owner->id,
            'leader_user_id' => $owner->id,
            'job_topic' => $topic,
            'job_priority' => 2,
            'job_status' => 2,
            'approval_status' => 'approved',
            'job_start_at' => now()->subDay(),
            'job_due_at' => now()->addDay(),
        ]);
    }

    private function log(User $actor, string $subjectType, int $subjectId, array $changes): ActivityLog
    {
        return ActivityLog::create([
            'user_id' => $actor->id,
            'action' => 'updated',
            'subject_type' => $subjectType,
            'subject_id' => $subjectId,
            'description' => 'แก้ไขข้อมูล',
            'changes' => $changes,
            'ip_address' => '127.0.0.1',
            'created_at' => now(),
        ]);
    }
}
