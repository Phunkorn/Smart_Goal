<?php

namespace Tests\Feature;

use App\Models\Department;
use App\Models\User;
use App\Support\RoleLabel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Sidebar ใช้โครงสร้างข้อมูลตามบทบาท โดยคง route สิทธิ์ และลำดับงานสำคัญเดิม
 * ส่วนแบรนด์ใช้ markup ชุดเดียวกันในทุกบทบาท
 */
class SidebarNavigationTest extends TestCase
{
    use RefreshDatabase;

    public function test_member_sidebar_is_grouped_and_hides_the_meetings_menu(): void
    {
        $user = $this->userWithRole('user');

        $content = $this->actingAs($user)
            ->get(route('mytasks.index'))
            ->assertOk()
            ->assertDontSee('href="'.route('meetings.index').'"', false)
            ->assertSee('<div class="nav-section-label">งานของฉัน</div>', false)
            ->assertSee('<div class="nav-section-label">การสื่อสาร</div>', false)
            ->assertSee('<div class="nav-section-label">ระบบ</div>', false)
            ->getContent();

        $myTasksPosition = strpos($content, 'href="'.route('mytasks.index').'"');
        $workBoardPosition = strpos($content, 'href="'.route('work-board.index').'"');
        $reportPosition = strpos($content, 'href="'.route('reports.my').'"');

        $this->assertNotFalse($myTasksPosition);
        $this->assertNotFalse($workBoardPosition);
        $this->assertNotFalse($reportPosition);
        $this->assertLessThan($workBoardPosition, $myTasksPosition, '"งานของฉัน" ต้องอยู่ก่อน "บอร์ดงาน"');
        $this->assertLessThan($reportPosition, $workBoardPosition, '"บอร์ดงาน" ต้องอยู่ก่อน "รายงานของฉัน"');
    }

    public function test_admin_sidebar_is_split_into_clear_operational_sections(): void
    {
        $admin = $this->userWithRole('admin');

        $content = $this->actingAs($admin)
            ->get(route('board.index'))
            ->assertOk()
            ->assertDontSee('href="'.route('meetings.index').'"', false)
            ->assertDontSee('<span class="nav-item__label">การประชุม</span>', false)
            ->assertSee('<div class="nav-section-label">ภาพรวม</div>', false)
            ->assertSee('<div class="nav-section-label">งานและคำขอ</div>', false)
            ->assertSee('<div class="nav-section-label">องค์กร</div>', false)
            ->assertSee('<div class="nav-section-label">ระบบ</div>', false)
            ->assertDontSee('<div class="nav-section-label">การสื่อสาร</div>', false)
            ->getContent();

        $positions = [
            strpos($content, 'href="'.route('board.index').'"'),
            strpos($content, 'href="'.route('reports.index').'"'),
            strpos($content, 'href="'.route('notifications.index').'"'),
            strpos($content, 'href="'.route('admin.approvals.index').'"'),
            strpos($content, 'href="'.route('employees.index').'"'),
            strpos($content, 'href="'.route('admin.departments.index').'"'),
            strpos($content, 'href="'.route('admin.audit.index').'"'),
            strpos($content, 'href="'.route('settings.index').'"'),
        ];

        $this->assertNotContains(false, $positions);
        $this->assertSame($positions, collect($positions)->sort()->values()->all());
    }

    public function test_viewer_sidebar_uses_the_shared_information_architecture(): void
    {
        $viewer = $this->userWithRole('viewer');

        $content = $this->actingAs($viewer)
            ->get(route('board.index'))
            ->assertOk()
            ->assertSee('href="'.route('meetings.index').'"', false)
            ->assertSee('<div class="nav-section-label">ภาพรวม</div>', false)
            ->assertSee('<div class="nav-section-label">การสื่อสาร</div>', false)
            ->assertSee('<div class="nav-section-label">องค์กร</div>', false)
            ->assertSee('<div class="nav-section-label">ระบบ</div>', false)
            ->assertDontSee('<div class="nav-section-label">พื้นที่ของฉัน</div>', false)
            ->getContent();

        $positions = [
            strpos($content, 'href="'.route('board.index').'"'),
            strpos($content, 'href="'.route('reports.index').'"'),
            strpos($content, 'href="'.route('notifications.index').'"'),
            strpos($content, 'href="'.route('meetings.index').'"'),
            strpos($content, 'href="'.route('employees.index').'"'),
            strpos($content, 'href="'.route('settings.index').'"'),
        ];

        $this->assertNotContains(false, $positions);
        $this->assertSame($positions, collect($positions)->sort()->values()->all());
    }

    /**
     * เครื่องหมายประจำแอปเป็นโลโก้บริษัทจริง ไม่ใช่ไอคอนอาคารทั่วไปของ Bootstrap Icons
     * และต้องเป็นชุดเดียวกันทุกบทบาท เพราะเป็นตัวตนของระบบ ไม่ใช่ของผู้ใช้คนใดคนหนึ่ง
     */
    public function test_sidebar_brand_uses_the_company_logo_and_separate_subtitle_for_every_role(): void
    {
        $logo = asset('images/premiuum-care-logo.png');

        foreach ([
            'user' => 'mytasks.index',
            'admin' => 'board.index',
            'viewer' => 'board.index',
        ] as $role => $routeName) {
            $this->actingAs($this->userWithRole($role))
                ->get(route($routeName))
                ->assertOk()
                ->assertSee('<span class="brand-mark" aria-hidden="true"><img src="'.$logo.'" alt=""></span>', false)
                ->assertSee('<div class="brand-name">Smart Goals</div>', false)
                ->assertSee('<div class="brand-subtitle">ระบบจัดการองค์กร</div>', false)
                ->assertDontSee('bi-buildings', false)
                ->assertDontSee('bi-bullseye', false);
        }

        // ไฟล์โลโก้ต้องมีอยู่จริง ไม่เช่นนั้นทุกหน้าจะโหลดรูปที่ 404 โดยไม่มีใครสังเกต
        $this->assertFileExists(public_path('images/premiuum-care-logo.png'));
    }

    public function test_role_chip_shows_its_role_label_and_keeps_an_accessible_name(): void
    {
        // admin และ viewer ไม่ผูกกับแผนก ป้ายจึงมีแต่ชื่อบทบาท
        foreach ([
            'admin' => ['board.index', 'admin', 'ผู้ดูแลระบบ'],
            'viewer' => ['board.index', 'viewer', 'ผู้เข้าชม'],
        ] as $role => [$routeName, $chipClass, $label]) {
            $this->actingAs($this->userWithRole($role))
                ->get(route($routeName))
                ->assertOk()
                ->assertSee(
                    '<span class="role-chip role-chip--mobile-only '.$chipClass.'" aria-label="'.$label.'" title="'.$label.'">',
                    false
                )
                ->assertSee('<span class="role-chip__label">'.$label.'</span>', false);
        }
    }

    /**
     * ป้ายบทบาทมุมขวาบนเป็นของจอเล็กเท่านั้น
     *
     * บนเดสก์ท็อป Sidebar กางอยู่และท้ายเมนูบอกทั้งชื่อ บทบาท และแผนกครบแล้ว
     * ป้ายนี้จึงเป็นข้อมูลซ้ำที่กินพื้นที่แถบบน แต่ยังต้องอยู่ใน DOM
     * เพราะจอเล็กที่ Sidebar ปิดอยู่ ป้ายนี้คือที่เดียวที่บอกว่ากำลังใช้งานในบทบาทใด
     */
    public function test_role_chip_is_present_but_hidden_on_desktop_widths(): void
    {
        $content = $this->actingAs($this->userWithRole('user'))
            ->get(route('mytasks.index'))
            ->assertOk()
            ->assertSee('role-chip--mobile-only', false)
            ->getContent();

        // ท้าย Sidebar ยังบอกบทบาทอยู่ ป้ายมุมขวาบนจึงไม่ใช่แหล่งเดียวบนเดสก์ท็อป
        $this->assertStringContainsString('<div class="role">', $content);
    }

    /**
     * เดิมป้ายบทบาทมีแค่คลาส admin กับ user หัวหน้าแผนกและพนักงานจึงเป็นสีเขียว
     * เหมือนกันหมด และไม่บอกว่าอยู่แผนกไหน ทั้งที่ทั้งสองบทบาทมีสิทธิ์ต่างกันชัดเจน
     */
    public function test_role_chip_names_the_department_and_separates_head_from_staff(): void
    {
        $department = Department::create(['department_name' => 'IT']);

        $staff = User::factory()->create([
            'role' => 'user',
            'department_id' => $department->id,
            'must_change_password' => false,
            'is_active' => true,
        ]);

        $head = User::factory()->create([
            'role' => 'user',
            'department_id' => $department->id,
            'is_department_head' => true,
            'must_change_password' => false,
            'is_active' => true,
        ]);

        $this->actingAs($staff)->get(route('mytasks.index'))
            ->assertOk()
            ->assertSee('<span class="role-chip role-chip--mobile-only user" aria-label="พนักงาน IT" title="พนักงาน IT">', false)
            ->assertSee('<span class="role-chip__label">พนักงาน IT</span>', false);

        $this->actingAs($head)->get(route('mytasks.index'))
            ->assertOk()
            ->assertSee('<span class="role-chip role-chip--mobile-only department-head" aria-label="หัวหน้าแผนก IT" title="หัวหน้าแผนก IT">', false)
            ->assertSee('<span class="role-chip__label">หัวหน้าแผนก IT</span>', false);
    }

    /**
     * ชื่อบทบาทต้องตรงกันทุกหน้า
     *
     * "หัวหน้าแผนก" ไม่ใช่ค่าใน users.role แต่เป็นธง users.is_department_head
     * หน้าที่แปลง role เป็นข้อความเองด้วยตารางแปลงจึงมองข้ามธงนี้ไป
     * หน้า "ตั้งค่า" เคยแสดงหัวหน้าแผนกเป็น "พนักงาน" ทั้งที่แถบบนของหน้าเดียวกันแสดงถูกต้อง
     * ตอนนี้ทุกหน้าอ่านจาก App\\Support\\RoleLabel ตัวเดียว
     */
    public function test_department_head_role_label_is_consistent_across_pages(): void
    {
        $department = Department::create(['department_name' => 'IT']);
        $head = User::factory()->create([
            'role' => 'user',
            'department_id' => $department->id,
            'is_department_head' => true,
            'must_change_password' => false,
            'is_active' => true,
        ]);

        $this->assertSame('หัวหน้าแผนก', RoleLabel::for($head));

        $this->actingAs($head)->get(route('settings.index'))
            ->assertOk()
            ->assertSee('<strong>หัวหน้าแผนก</strong>', false)
            ->assertDontSee('<strong>พนักงาน</strong>', false);

        // ท้าย Sidebar ยังบอกว่ากำลังใช้งานในนามใคร ด้วยชื่อบทบาทเดียวกัน
        $this->actingAs($head)->get(route('mytasks.index'))
            ->assertOk()
            ->assertSee('<div class="role">หัวหน้าแผนก · IT</div>', false);
    }

    /**
     * ปุ่มออกจากระบบอยู่ที่ Topbar ถัดจากไอคอนแจ้งเตือน ไม่ใช่ท้าย Sidebar อีกต่อไป
     * ท้าย Sidebar เข้าถึงไม่ได้เมื่อเมนูถูกย่อบนเดสก์ท็อปหรือปิดอยู่บนจอเล็ก
     */
    public function test_logout_lives_in_the_topbar_next_to_notifications(): void
    {
        $user = $this->userWithRole('user');

        $content = $this->actingAs($user)->get(route('mytasks.index'))
            ->assertOk()
            ->assertSee('class="topbar-logout"', false)
            ->assertDontSee('class="sidebar-foot__logout"', false)
            ->getContent();

        $notificationBell = strpos($content, 'data-bell-notification-count');
        $logout = strpos($content, 'class="topbar-logout"');
        $topbarEnd = strpos($content, '</header>');
        $sidebarEnd = strpos($content, '</aside>');

        $this->assertNotFalse($notificationBell);
        $this->assertNotFalse($logout);
        $this->assertGreaterThan($sidebarEnd, $logout, 'ปุ่มออกจากระบบต้องไม่อยู่ใน Sidebar');
        $this->assertGreaterThan($notificationBell, $logout, 'ปุ่มออกจากระบบต้องอยู่หลังไอคอนแจ้งเตือน');
        $this->assertLessThan($topbarEnd, $logout, 'ปุ่มออกจากระบบต้องอยู่ใน Topbar');
    }

    private function userWithRole(string $role): User
    {
        $department = Department::create(['department_name' => 'Sidebar '.uniqid()]);

        return User::factory()->create([
            'role' => $role,
            'department_id' => $department->id,
            'is_active' => true,
        ]);
    }
}
