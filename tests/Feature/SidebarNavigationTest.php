<?php

namespace Tests\Feature;

use App\Models\Department;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Sidebar ของพนักงานถูกจัดหมวดใหม่และย้ายเมนู "การประชุม" ออก
 * ส่วน Admin และ Viewer ต้องไม่เปลี่ยนแปลงเลย
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

    public function test_admin_sidebar_hides_the_meetings_menu_and_keeps_its_own_sections(): void
    {
        // "การประชุม" ของ Admin ย้ายไปเป็น view ที่ 4 ของ Admin Member Workspace แล้ว
        // sidebar จึงต้องไม่มีเมนูนี้อีก แต่ section อื่นต้องไม่ถูกกระทบ
        $admin = $this->userWithRole('admin');

        $this->actingAs($admin)
            ->get(route('board.index'))
            ->assertOk()
            ->assertDontSee('href="'.route('meetings.index').'"', false)
            ->assertDontSee('<span class="nav-item__label">การประชุม</span>', false)
            ->assertSee('<div class="nav-section-label">ผู้บริหาร</div>', false)
            ->assertSee('href="'.route('board.index').'"', false)
            ->assertSee('<div class="nav-section-label">การจัดการ</div>', false)
            ->assertDontSee('<div class="nav-section-label">การสื่อสาร</div>', false);
    }

    public function test_viewer_sidebar_keeps_the_meetings_menu_unchanged(): void
    {
        $viewer = $this->userWithRole('viewer');

        $this->actingAs($viewer)
            ->get(route('board.index'))
            ->assertOk()
            ->assertSee('href="'.route('meetings.index').'"', false)
            ->assertSee('<div class="nav-section-label">ดูข้อมูล</div>', false)
            ->assertSee('<div class="nav-section-label">พื้นที่ของฉัน</div>', false)
            ->assertDontSee('<div class="nav-section-label">การสื่อสาร</div>', false);
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
