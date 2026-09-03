<?php

namespace Tests\Feature;

use App\Models\Department;
use App\Models\User;
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

    public function test_sidebar_brand_uses_organization_icon_and_separate_subtitle_for_every_role(): void
    {
        foreach ([
            'user' => 'mytasks.index',
            'admin' => 'board.index',
            'viewer' => 'board.index',
        ] as $role => $routeName) {
            $this->actingAs($this->userWithRole($role))
                ->get(route($routeName))
                ->assertOk()
                ->assertSee('<i class="bi bi-buildings"></i>', false)
                ->assertSee('<div class="brand-name">Smart Goals</div>', false)
                ->assertSee('<div class="brand-subtitle">ระบบจัดการองค์กร</div>', false)
                ->assertDontSee('bi-bullseye', false);
        }
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
                    '<span class="role-chip '.$chipClass.'" aria-label="'.$label.'" title="'.$label.'">',
                    false
                )
                ->assertSee('<span class="role-chip__label">'.$label.'</span>', false);
        }
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
            ->assertSee('<span class="role-chip user" aria-label="พนักงาน IT" title="พนักงาน IT">', false)
            ->assertSee('<span class="role-chip__label">พนักงาน IT</span>', false);

        $this->actingAs($head)->get(route('mytasks.index'))
            ->assertOk()
            ->assertSee('<span class="role-chip department-head" aria-label="หัวหน้าแผนก IT" title="หัวหน้าแผนก IT">', false)
            ->assertSee('<span class="role-chip__label">หัวหน้าแผนก IT</span>', false);
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
