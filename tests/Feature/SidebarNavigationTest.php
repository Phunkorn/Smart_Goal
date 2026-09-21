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
        $dailyLogPosition = strpos($content, 'href="'.route('daily-logs.index').'"');
        $workBoardPosition = strpos($content, 'href="'.route('work-board.index').'"');
        // กระดานไอเดียเป็นงานร่วมของทั้งแผนก จึงอยู่ต่อจากบอร์ดงานซึ่งเป็นงานของทีม
        // เหมือนกัน และมาก่อนรายงานซึ่งเป็นการสรุปผลย้อนหลัง
        $workspacePosition = strpos($content, 'href="'.route('workspace.index').'"');
        // พนักงานเข้าหน้าเลือกรายงานเหมือนหัวหน้าแล้ว (มีสองรายงานให้เลือก
        // คือรายงานตัวเองกับรายงานปฏิบัติงาน) ไม่ได้ถูกส่งตรงไป reports.my อีก
        $reportPosition = strpos($content, 'href="'.route('reports.index').'"');

        $this->assertNotFalse($myTasksPosition);
        $this->assertNotFalse($dailyLogPosition);
        $this->assertNotFalse($workBoardPosition);
        $this->assertNotFalse($workspacePosition);
        $this->assertNotFalse($reportPosition);
        $this->assertLessThan($dailyLogPosition, $myTasksPosition, '"งานของฉัน" ต้องอยู่ก่อน "บันทึกงานประจำวัน"');
        // บันทึกงานประจำวันเป็นงานส่วนตัวเช่นเดียวกับ "งานของฉัน" จึงอยู่ก่อนบอร์ดของทีม
        $this->assertLessThan($workBoardPosition, $dailyLogPosition, '"บันทึกงานประจำวัน" ต้องอยู่ก่อน "บอร์ดงาน"');
        // แชร์งานเป็นการทำงานร่วมกับคนอื่น จึงอยู่ในกลุ่มเดียวกับบอร์ดของทีม
        $sharesPosition = strpos($content, 'href="'.route('shares.index').'"');
        $this->assertNotFalse($sharesPosition, 'พนักงานต้องเห็นเมนู "แชร์งาน"');
        $this->assertLessThan($sharesPosition, $workBoardPosition, '"บอร์ดงาน" ต้องอยู่ก่อน "แชร์งาน"');
        $this->assertLessThan($workspacePosition, $sharesPosition, '"แชร์งาน" ต้องอยู่ก่อน "กระดานไอเดีย"');
        $this->assertLessThan($workspacePosition, $workBoardPosition, '"บอร์ดงาน" ต้องอยู่ก่อน "กระดานไอเดีย"');
        $this->assertLessThan($reportPosition, $workspacePosition, '"กระดานไอเดีย" ต้องอยู่ก่อน "รายงาน"');
    }

    /**
     * admin เป็นผู้ดูแลระบบ ไม่ใช่ผู้อนุมัติงาน
     *
     * เมนู "คำขออนุมัติ" จึงไม่อยู่ในแถบข้างของ admin เมื่อไม่มีคำขอค้าง — คิวของ admin
     * ถูกจำกัดเหลือเฉพาะคำขอที่ไม่มีหัวหน้าแผนกรับผิดชอบแล้ว (AdminRoleScopeTest
     * ตรวจเงื่อนไขการแสดง/ซ่อนเมนูนี้ครบทั้งสองทาง)
     */
    public function test_admin_sidebar_is_split_into_clear_operational_sections(): void
    {
        $admin = $this->userWithRole('admin');

        $content = $this->actingAs($admin)
            ->get(route('board.index'))
            ->assertOk()
            ->assertDontSee('href="'.route('meetings.index').'"', false)
            ->assertDontSee('<span class="nav-item__label">การประชุม</span>', false)
            // สามหัวข้อตามบทบาทจริง: ดูภาพรวม / ลงมือทำเอง / งานที่มีแต่ admin ทำได้
            ->assertSee('<div class="nav-section-label">ภาพรวมองค์กร</div>', false)
            ->assertSee('<div class="nav-section-label">งานและการสื่อสาร</div>', false)
            ->assertSee('<div class="nav-section-label">ดูแลระบบ</div>', false)
            // หัวข้อเดิมที่ถูกยุบรวมต้องไม่เหลืออยู่ ไม่ใช่ว่างเปล่าแต่ยังขึ้นหัวข้อ
            ->assertDontSee('<div class="nav-section-label">ภาพรวม</div>', false)
            ->assertDontSee('<div class="nav-section-label">งานและคำขอ</div>', false)
            ->assertDontSee('<div class="nav-section-label">องค์กร</div>', false)
            ->assertDontSee('<div class="nav-section-label">ระบบ</div>', false)
            ->assertDontSee('<div class="nav-section-label">การสื่อสาร</div>', false)
            ->getContent();

        $positions = [
            // ภาพรวมองค์กร
            strpos($content, 'href="'.route('board.index').'"'),
            strpos($content, 'href="'.route('reports.index').'"'),
            // งานและการสื่อสาร
            strpos($content, 'href="'.route('notifications.index').'"'),
            strpos($content, 'href="'.route('shares.index').'"'),
            strpos($content, 'href="'.route('daily-logs.index').'"'),
            strpos($content, 'href="'.route('workspace.index').'"'),
            // ดูแลระบบ
            strpos($content, 'href="'.route('employees.index').'"'),
            strpos($content, 'href="'.route('admin.departments.index').'"'),
            strpos($content, 'href="'.route('admin.accounts.index').'"'),
            strpos($content, 'href="'.route('admin.work-log-categories.index').'"'),
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
            // viewer เป็น read-only จึงไม่มีบันทึกงานประจำวันของตัวเอง
            // และต้องไม่เห็นเมนูที่กดแล้วเจอ 403
            ->assertDontSee('href="'.route('daily-logs.index').'"', false)
            // viewer เป็นผู้ร่วมงานไม่ได้ จึงไม่มีอะไรทำในหน้าแชร์งาน และ route
            // ถูกกันด้วย middleware role:admin,user อยู่แล้ว
            ->assertDontSee('href="'.route('shares.index').'"', false)
            ->getContent();

        $positions = [
            strpos($content, 'href="'.route('board.index').'"'),
            // viewer เห็นเมนูกระดานไอเดียได้ เพราะกระดานที่ตั้งเป็น "ทั้งองค์กร"
            // เปิดให้อ่านได้ทุกคน ส่วนการวาดถูกปิดที่ WorkspaceBoardPolicy
            strpos($content, 'href="'.route('workspace.index').'"'),
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
    /**
     * โลโก้อยู่ในหัว Sidebar ส่วนชื่อระบบอยู่บนแถบบน
     *
     * ชื่อระบบต้องอ่านได้แม้ Sidebar ถูกย่อหรือปิดอยู่ จึงย้ายมาไว้ข้างปุ่มเมนูบน Topbar
     * และต้องมีที่เดียวเท่านั้น ไม่ใช่ประกาศซ้ำทั้งสองที่จนกลายเป็นสองแหล่งความจริง
     */
    public function test_sidebar_shows_only_the_logo_while_the_topbar_carries_the_product_name(): void
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
                ->assertSee('<div class="topbar-brand">', false)
                ->assertDontSee('<a class="topbar-brand"', false)
                ->assertSee('<span class="topbar-brand__name">Smart Goals</span>', false)
                ->assertSee('<span class="topbar-brand__subtitle">ระบบจัดการองค์กร</span>', false)
                // ชื่อระบบต้องไม่เหลือค้างอยู่ในหัว Sidebar อีก
                ->assertDontSee('<div class="brand-name">', false)
                ->assertDontSee('<div class="brand-subtitle">', false)
                ->assertDontSee('bi-buildings', false)
                ->assertDontSee('bi-bullseye', false);
        }

        // ไฟล์โลโก้ต้องมีอยู่จริง ไม่เช่นนั้นทุกหน้าจะโหลดรูปที่ 404 โดยไม่มีใครสังเกต
        $this->assertFileExists(public_path('images/premiuum-care-logo.png'));
    }

    public function test_role_chip_keeps_an_accessible_name_after_the_visible_label_was_removed(): void
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
                // ป้ายข้อความถูกถอดออกแล้ว บทบาทจึงเหลืออยู่ใน aria-label กับ title เท่านั้น
                ->assertDontSee('role-chip__label', false);
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
            ->assertDontSee('role-chip__label', false);

        $this->actingAs($head)->get(route('mytasks.index'))
            ->assertOk()
            ->assertSee('<span class="role-chip role-chip--mobile-only department-head" aria-label="หัวหน้าแผนก IT" title="หัวหน้าแผนก IT">', false)
            ->assertDontSee('role-chip__label', false);
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

    /**
     * เมนูของพนักงานและหัวหน้าแผนกถูกแยกหมวดตามคำถามที่ผู้ใช้ถามตอนกดเมนู
     *
     * เดิมทั้งห้าเมนูกองอยู่ใต้ "งานของฉัน" หัวข้อเดียว ทั้งที่ครึ่งหนึ่งเป็นงานของทีม
     * และรายงาน หัวข้อจึงไม่ได้ช่วยหาเมนูเลย
     */
    public function test_member_and_head_menus_are_split_into_meaningful_sections(): void
    {
        $member = $this->userWithRole('user');

        $content = $this->actingAs($member)
            ->get(route('mytasks.index'))
            ->assertOk()
            ->assertSee('<div class="nav-section-label">งานของฉัน</div>', false)
            ->assertSee('<div class="nav-section-label">งานของทีม</div>', false)
            ->assertSee('<div class="nav-section-label">รายงาน</div>', false)
            ->getContent();

        // หัวข้อต้องมาก่อนเมนูที่มันครอบ ไม่ใช่ลอยอยู่ผิดที่
        $teamLabel = strpos($content, '<div class="nav-section-label">งานของทีม</div>');
        $reportLabel = strpos($content, '<div class="nav-section-label">รายงาน</div>');

        $this->assertGreaterThan(
            strpos($content, 'href="'.route('daily-logs.index').'"'),
            $teamLabel,
            '"งานของทีม" ต้องอยู่หลังเมนูงานส่วนตัว'
        );
        $this->assertLessThan(
            strpos($content, 'href="'.route('work-board.index').'"'),
            $teamLabel,
            '"งานของทีม" ต้องอยู่ก่อน "บอร์ดงาน"'
        );
        $this->assertLessThan(
            strpos($content, 'href="'.route('reports.index').'"'),
            $reportLabel,
            '"รายงาน" ต้องอยู่ก่อนเมนูรายงาน'
        );

        // หัวหน้าแผนกเห็นหัวข้อเดียวกัน แต่กลุ่มของทีมพูดในนามแผนกที่ตัวเองดูแล
        $head = $this->userWithRole('user');
        $head->forceFill(['is_department_head' => true])->save();

        $this->actingAs($head)
            ->get(route('mytasks.index'))
            ->assertOk()
            ->assertSee('<div class="nav-section-label">งานของฉัน</div>', false)
            ->assertSee('<div class="nav-section-label">แผนกของฉัน</div>', false)
            ->assertSee('<div class="nav-section-label">รายงาน</div>', false)
            ->assertDontSee('<div class="nav-section-label">งานของทีม</div>', false);
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
