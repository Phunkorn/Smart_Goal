<?php

namespace Tests\Feature;

use App\Models\ComputerCheckSession;
use App\Models\Department;
use App\Models\User;
use App\Support\TodayWorkspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * สิทธิ์ใช้งาน "ตรวจเช็กคอมพิวเตอร์ประจำวัน" รายแผนก
 *
 * ฟังก์ชันนี้เคยเปิดให้ทุกแผนกโดยอัตโนมัติ ตอนนี้ admin กำหนดรายแผนก
 * สิ่งที่ต้องตรึงไว้:
 *   1. ค่าเริ่มต้นของแผนกใหม่คือ "ปิด" — ไม่ใช่เปิด
 *   2. แผนกที่ปิดต้องเข้าไม่ได้ทั้งทาง URL และ POST ไม่ใช่แค่ไม่เห็นเมนู
 *   3. เมนูต้องหายไปจากกล่องเพิ่มงานของแผนกที่ปิด
 *   4. เปิด/ปิดได้เฉพาะ admin
 *   5. รอบที่บันทึกไว้แล้วยังอ่านย้อนหลังได้ แม้แผนกถูกปิดสิทธิ์ภายหลัง
 */
class ComputerCheckDepartmentAccessTest extends TestCase
{
    use RefreshDatabase;

    /* ---------- ข้อ 1 ---------- */

    public function test_a_new_department_starts_with_the_feature_disabled(): void
    {
        $department = Department::create(['department_name' => 'Sales']);

        $this->assertFalse($department->fresh()->computer_check_enabled);
    }

    /* ---------- ข้อ 2 ---------- */

    public function test_a_department_without_permission_cannot_reach_the_page_or_start_a_session(): void
    {
        $member = $this->user($this->department(enabled: false));

        $this->actingAs($member)
            ->get(route('daily-logs.computer-check.index'))
            ->assertForbidden();

        $this->actingAs($member)
            ->post(route('daily-logs.computer-check.store'), [
                'check_date' => TodayWorkspace::businessNow()->format('Y-m-d'),
                'shift' => 'AM',
            ])
            ->assertForbidden();

        $this->assertSame(0, ComputerCheckSession::count());
    }

    public function test_an_admin_in_a_disabled_department_is_not_a_way_around_the_rule(): void
    {
        // ไม่มีทางผ่านของบทบาทบน viewAny()/create() โดยตั้งใจ — นี่เป็นงานของแผนก
        // admin ที่ต้องใช้จริงเปิดสิทธิ์ให้แผนกของตัวเองได้อยู่แล้ว
        $admin = $this->user($this->department(enabled: false), role: 'admin');

        $this->actingAs($admin)
            ->get(route('daily-logs.computer-check.index'))
            ->assertForbidden();
    }

    public function test_a_user_without_a_department_cannot_use_the_feature(): void
    {
        $this->department(enabled: true);
        $stray = User::factory()->create([
            'role' => 'user',
            'department_id' => null,
            'must_change_password' => false,
            'is_active' => true,
        ]);

        $this->actingAs($stray)
            ->get(route('daily-logs.computer-check.index'))
            ->assertForbidden();
    }

    public function test_a_permitted_department_can_open_the_page(): void
    {
        $member = $this->user($this->department(enabled: true));

        $this->actingAs($member)
            ->get(route('daily-logs.computer-check.index'))
            ->assertOk();
    }

    /* ---------- ข้อ 3 ---------- */

    public function test_the_menu_entry_only_appears_for_a_permitted_department(): void
    {
        $permitted = $this->user($this->department(enabled: true));
        $blocked = $this->user(Department::create(['department_name' => 'Sales']));

        $this->actingAs($permitted)
            ->get(route('daily-logs.index'))
            ->assertOk()
            ->assertSee('ตรวจเช็กคอมพิวเตอร์ประจำวัน');

        /*
         * ลิงก์ต้องไม่อยู่ในหน้าเลย ไม่ใช่ถูกซ่อนด้วย CSS
         * จึงตรวจที่ href ของเส้นทางจริง ไม่ใช่ตรวจแค่ข้อความ เพราะชื่อหมวดงาน
         * เดียวกันอาจปรากฏในรายการงานที่เคยบันทึกไว้
         */
        $html = $this->actingAs($blocked)
            ->get(route('daily-logs.index'))
            ->assertOk()
            ->getContent();

        $this->assertStringNotContainsString(route('daily-logs.computer-check.index'), $html);
    }

    /* ---------- ข้อ 4 ---------- */

    public function test_only_an_admin_can_change_the_permission(): void
    {
        $department = $this->department(enabled: false);
        $member = $this->user($department);

        $this->actingAs($member)
            ->patch(route('admin.departments.computer-check-access', $department), [
                'computer_check_enabled' => 1,
            ])
            ->assertForbidden();

        $this->assertFalse($department->fresh()->computer_check_enabled);
    }

    public function test_an_admin_can_turn_the_permission_on_and_off(): void
    {
        $department = $this->department(enabled: false);
        $admin = $this->user($department, role: 'admin');

        $this->actingAs($admin)
            ->patch(route('admin.departments.computer-check-access', $department), [
                'computer_check_enabled' => 1,
            ])
            ->assertRedirect(route('admin.departments.index'));

        $this->assertTrue($department->fresh()->computer_check_enabled);

        $this->actingAs($admin)
            ->patch(route('admin.departments.computer-check-access', $department), [
                'computer_check_enabled' => 0,
            ])
            ->assertRedirect(route('admin.departments.index'));

        $this->assertFalse($department->fresh()->computer_check_enabled);
    }

    public function test_an_invalid_permission_value_is_rejected(): void
    {
        $department = $this->department(enabled: false);
        $admin = $this->user($department, role: 'admin');

        $this->actingAs($admin)
            ->patch(route('admin.departments.computer-check-access', $department), [
                'computer_check_enabled' => 'yes please',
            ])
            ->assertSessionHasErrors('computer_check_enabled');

        $this->assertFalse($department->fresh()->computer_check_enabled);
    }

    /* ---------- ข้อ 5 ---------- */

    public function test_a_recorded_session_is_still_readable_after_the_department_loses_permission(): void
    {
        $department = $this->department(enabled: true);
        $checker = $this->user($department);

        $this->actingAs($checker)->post(route('daily-logs.computer-check.store'), [
            'check_date' => TodayWorkspace::businessNow()->format('Y-m-d'),
            'shift' => 'AM',
        ]);

        $session = ComputerCheckSession::query()->firstOrFail();

        // admin ปิดสิทธิ์ของแผนกภายหลัง
        $department->update(['computer_check_enabled' => false]);

        /*
         * รอบที่บันทึกไว้แล้วเป็นหลักฐานการปฏิบัติงาน ต้องตรวจย้อนหลังได้
         * view() จึงไม่ถูกผูกกับสิทธิ์รายแผนกโดยตั้งใจ (ดู ComputerCheckSessionPolicy)
         */
        $this->actingAs($checker)
            ->get(route('daily-logs.computer-check.report', $session))
            ->assertOk();

        // แต่เปิดหน้าตรวจเพื่อเริ่มรอบใหม่ไม่ได้อีก
        $this->actingAs($checker)
            ->get(route('daily-logs.computer-check.index'))
            ->assertForbidden();
    }

    /* ---------- helpers ---------- */

    private function department(bool $enabled): Department
    {
        return Department::create([
            'department_name' => 'IT',
            'computer_check_enabled' => $enabled,
        ]);
    }

    private function user(Department $department, string $role = 'user'): User
    {
        return User::factory()->create([
            'role' => $role,
            'department_id' => $department->id,
            'must_change_password' => false,
            'is_active' => true,
        ]);
    }
}
