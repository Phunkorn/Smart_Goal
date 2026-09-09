<?php

namespace Tests\Feature;

use App\Models\Department;
use App\Models\User;
use App\Models\WorkLog;
use App\Models\WorkLogTemplate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Tests\TestCase;

/**
 * กติกาสิทธิ์ของบันทึกงานประจำวัน
 *
 * จุดที่ต้องคุมให้แน่นที่สุดคือ "เห็นได้ แต่แก้ไม่ได้" ของหัวหน้าแผนกและ admin
 * เพราะเป็นการตัดสินใจที่ต่างจาก policy อื่นในระบบที่ admin มักผ่านได้เสมอ
 * ถ้ามีใครเผลอเติม admin bypass ทีหลัง เทสต์ชุดนี้ต้องแดงทันที
 */
class WorkLogPolicyTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_can_view_update_and_delete_own_log(): void
    {
        $department = Department::create(['department_name' => 'IT']);
        $owner = $this->user($department);
        $log = $this->log($owner, $department);

        $this->assertTrue(Gate::forUser($owner)->allows('view', $log));
        $this->assertTrue(Gate::forUser($owner)->allows('update', $log));
        $this->assertTrue(Gate::forUser($owner)->allows('delete', $log));
    }

    public function test_department_head_can_view_but_cannot_edit_or_delete_member_log(): void
    {
        $department = Department::create(['department_name' => 'IT']);
        $owner = $this->user($department);
        $head = $this->user($department, true);
        $log = $this->log($owner, $department);

        $this->assertTrue(Gate::forUser($head)->allows('view', $log));
        $this->assertTrue(Gate::forUser($head)->allows('viewDay', [WorkLog::class, $owner]));

        $this->assertFalse(Gate::forUser($head)->allows('update', $log));
        $this->assertFalse(Gate::forUser($head)->allows('delete', $log));
    }

    public function test_department_head_of_another_department_cannot_view(): void
    {
        $it = Department::create(['department_name' => 'IT']);
        $sales = Department::create(['department_name' => 'Sales']);
        $owner = $this->user($it);
        $salesHead = $this->user($sales, true);
        $log = $this->log($owner, $it);

        $this->assertFalse(Gate::forUser($salesHead)->allows('view', $log));
        $this->assertFalse(Gate::forUser($salesHead)->allows('viewDay', [WorkLog::class, $owner]));
    }

    public function test_admin_can_view_any_log_but_cannot_edit_or_delete_it(): void
    {
        $department = Department::create(['department_name' => 'IT']);
        $owner = $this->user($department);
        $admin = $this->user(null, false, 'admin');
        $log = $this->log($owner, $department);

        $this->assertTrue(Gate::forUser($admin)->allows('view', $log));
        $this->assertTrue(Gate::forUser($admin)->allows('viewDay', [WorkLog::class, $owner]));

        // ตั้งใจให้ไม่ผ่าน — บันทึกงานประจำวันเป็นบันทึกของเจ้าตัว
        $this->assertFalse(Gate::forUser($admin)->allows('update', $log));
        $this->assertFalse(Gate::forUser($admin)->allows('delete', $log));
    }

    public function test_unrelated_member_cannot_view_another_members_log(): void
    {
        $department = Department::create(['department_name' => 'IT']);
        $owner = $this->user($department);
        $colleague = $this->user($department);
        $log = $this->log($owner, $department);

        $this->assertFalse(Gate::forUser($colleague)->allows('view', $log));
        $this->assertFalse(Gate::forUser($colleague)->allows('viewDay', [WorkLog::class, $owner]));
    }

    public function test_viewer_is_denied_every_work_log_ability(): void
    {
        $department = Department::create(['department_name' => 'IT']);
        $owner = $this->user($department);
        $viewer = $this->user($department, false, 'viewer');
        $log = $this->log($owner, $department);

        $this->assertFalse(Gate::forUser($viewer)->allows('viewAny', WorkLog::class));
        $this->assertFalse(Gate::forUser($viewer)->allows('create', WorkLog::class));
        $this->assertFalse(Gate::forUser($viewer)->allows('view', $log));
        $this->assertFalse(Gate::forUser($viewer)->allows('viewDay', [WorkLog::class, $owner]));
        $this->assertFalse(Gate::forUser($viewer)->allows('update', $log));
        $this->assertFalse(Gate::forUser($viewer)->allows('delete', $log));
        $this->assertFalse(Gate::forUser($viewer)->allows('viewReport', WorkLog::class));
    }

    /**
     * ทุก role ยกเว้น viewer เปิดรายงานปฏิบัติงานได้
     *
     * พนักงานเปิดได้เพื่อดูงานประจำของตัวเองว่าวันนี้ตรวจไปแล้วหรือยัง ส่วนขอบเขต
     * ว่าเห็นข้อมูลของใครถูกบังคับที่ ReportController::forcedOwnerId() ไม่ใช่ที่
     * ability นี้ (มีเทสต์แยกใน OperationalWorkloadReportTest)
     *
     * viewer ยังต้องไม่เห็น เพราะไม่มีบันทึกงานประจำวันเป็นของตัวเองเลย
     */
    public function test_every_role_except_viewer_can_open_the_operational_report(): void
    {
        $department = Department::create(['department_name' => 'IT']);

        $this->assertTrue(Gate::forUser($this->user(null, false, 'admin'))->allows('viewReport', WorkLog::class));
        $this->assertTrue(Gate::forUser($this->user($department, true))->allows('viewReport', WorkLog::class));
        $this->assertTrue(Gate::forUser($this->user($department))->allows('viewReport', WorkLog::class));
        $this->assertFalse(Gate::forUser($this->user($department, false, 'viewer'))->allows('viewReport', WorkLog::class));
    }

    /**
     * ธง is_department_head มีผลเฉพาะกับ role user ที่ยัง active และมีแผนก
     * ตามที่ User::isDepartmentHead() กำหนดไว้
     */
    public function test_inactive_or_non_user_role_does_not_gain_department_head_visibility(): void
    {
        $department = Department::create(['department_name' => 'IT']);
        $owner = $this->user($department);
        $log = $this->log($owner, $department);

        $inactiveHead = User::factory()->create([
            'role' => 'user',
            'department_id' => $department->id,
            'is_department_head' => true,
            'is_active' => false,
            'must_change_password' => false,
        ]);

        $viewerWithHeadFlag = User::factory()->create([
            'role' => 'viewer',
            'department_id' => $department->id,
            'is_department_head' => true,
            'is_active' => true,
            'must_change_password' => false,
        ]);

        $this->assertFalse(Gate::forUser($inactiveHead)->allows('view', $log));
        $this->assertFalse(Gate::forUser($viewerWithHeadFlag)->allows('view', $log));
    }

    /**
     * บันทึกเก็บ department_id ไว้เป็น snapshot ตอนสร้าง หัวหน้าแผนกเดิมจึงยังเห็น
     * งานที่เกิดขึ้นตอนลูกทีมยังอยู่แผนกนั้น แม้ภายหลังจะย้ายแผนกไปแล้ว
     */
    public function test_department_snapshot_keeps_history_with_the_original_head(): void
    {
        $it = Department::create(['department_name' => 'IT']);
        $sales = Department::create(['department_name' => 'Sales']);
        $itHead = $this->user($it, true);
        $salesHead = $this->user($sales, true);
        $owner = $this->user($it);

        $log = $this->log($owner, $it);

        $owner->update(['department_id' => $sales->id]);
        $log->refresh();

        $this->assertTrue(Gate::forUser($itHead)->allows('view', $log));
        $this->assertFalse(Gate::forUser($salesHead)->allows('view', $log));
    }

    public function test_cancelled_log_cannot_be_edited_even_by_its_owner(): void
    {
        $department = Department::create(['department_name' => 'IT']);
        $owner = $this->user($department);
        $log = $this->log($owner, $department, ['status' => 'cancelled']);

        $this->assertFalse(Gate::forUser($owner)->allows('update', $log));
        $this->assertTrue(Gate::forUser($owner)->allows('delete', $log));
    }

    public function test_routine_template_is_private_to_its_owner(): void
    {
        $department = Department::create(['department_name' => 'IT']);
        $owner = $this->user($department);
        $head = $this->user($department, true);
        $admin = $this->user(null, false, 'admin');
        $viewer = $this->user($department, false, 'viewer');

        $template = WorkLogTemplate::create([
            'user_id' => $owner->id,
            'title' => 'ตรวจสอบคอมพิวเตอร์ประจำวัน',
            'kind' => 'routine',
            'weekday_mask' => 31,
        ]);

        $this->assertTrue(Gate::forUser($owner)->allows('update', $template));

        // แม่แบบเป็นการตั้งค่าส่วนตัว ไม่ใช่ข้อมูลผลงานที่ต้องรายงานขึ้นไป
        $this->assertFalse(Gate::forUser($head)->allows('view', $template));
        $this->assertFalse(Gate::forUser($admin)->allows('update', $template));
        $this->assertFalse(Gate::forUser($viewer)->allows('create', WorkLogTemplate::class));
    }

    private function user(?Department $department, bool $head = false, string $role = 'user'): User
    {
        return User::factory()->create([
            'role' => $role,
            'department_id' => $department?->id,
            'is_department_head' => $head,
            'is_active' => true,
            'must_change_password' => false,
        ]);
    }

    private function log(User $owner, ?Department $department, array $overrides = []): WorkLog
    {
        return WorkLog::create(array_merge([
            'user_id' => $owner->id,
            'created_by' => $owner->id,
            'department_id' => $department?->id,
            'kind' => 'routine',
            'status' => 'open',
            'source' => 'manual',
            'title' => 'ตรวจสอบคอมพิวเตอร์ประจำวัน',
            'work_date' => '2026-09-04',
        ], $overrides));
    }
}
