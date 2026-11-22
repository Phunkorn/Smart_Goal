<?php

namespace Tests\Feature;

use App\Models\Department;
use App\Models\User;
use App\Support\RoleLabel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * เมนูหลักแนวนอนบน Topbar (App\Support\TopNavigation → layouts/components/top-navigation)
 *
 * แทน SidebarNavigationTest เดิม — กติกาเรื่องใครเห็นเมนูอะไร ลำดับเมนู และเมนูปัจจุบัน ถูกย้ายมาตรวจที่นี่
 * กับโครงเมนูใหม่: เมนูใช้บ่อยอยู่แถวบน ส่วนที่เหลืออยู่ในดร็อปดาวน์ตามหน้าที่
 */
class TopNavigationTest extends TestCase
{
    use RefreshDatabase;

    public function test_every_role_gets_the_horizontal_navigation_and_no_sidebar_remains(): void
    {
        foreach ([
            'user' => 'mytasks.index',
            'head' => 'mytasks.index',
            'admin' => 'board.index',
            'viewer' => 'board.index',
        ] as $role => $page) {
            $content = $this->actingAs($this->userWithRole($role))->get(route($page))->assertOk()
                ->assertSee('<header class="topbar">', false)
                ->assertSee('<nav class="top-nav" aria-label="เมนูหลัก" data-top-nav>', false)
                ->assertSee('<span class="topbar-brand__name">Smart Goals</span>', false)
                ->assertSee('<span class="topbar-brand__subtitle">ระบบบริหารงานองค์กร</span>', false)
                ->assertSee('data-global-search data-search-url="'.route('search').'"', false)
                ->getContent();

            foreach (['<aside', 'class="sidebar', 'data-sidebar', 'appSidebar', 'mobile-menu-btn', 'sidebar-collapsed', 'smartgoal.sidebar', 'components/sidebar'] as $legacy) {
                $this->assertStringNotContainsString($legacy, $content, "{$role}: {$legacy}");
            }

            // Topbar ไม่มี style หรือสคริปต์ inline และไม่มี event handler ใน HTML
            $header = $this->between($content, '<header class="topbar">', '</header>');
            $this->assertStringNotContainsString('<script', $header, $role);
            $this->assertDoesNotMatchRegularExpression('/\sstyle="/', $header, $role);
            $this->assertDoesNotMatchRegularExpression('/\son[a-z]+="/', $header, $role);

            // เมนูกลุ่มทุกชุดใช้การเปิด/ปิดแบบ static ชุดเดียวกับกระดิ่งและบัญชี
            $nav = $this->nav($content);
            $this->assertSame(
                substr_count($nav, 'data-bs-toggle="dropdown"'),
                substr_count($nav, 'data-bs-toggle="dropdown" data-bs-display="static"'),
                $role,
            );
            $this->assertSame(substr_count($nav, 'data-bs-toggle="dropdown"'), substr_count($nav, 'topbar-slide-menu nav-dropdown'), $role);
        }
    }

    public function test_member_navigation_keeps_personal_work_first_and_groups_the_rest(): void
    {
        $nav = $this->nav($this->actingAs($this->userWithRole('user'))->get(route('mytasks.index'))->assertOk()->getContent());

        $this->assertInOrder($nav, [
            'href="'.route('mytasks.index').'"',
            'href="'.route('daily-logs.index').'"',
            'href="'.route('work-board.index').'"',
            // ชุมชนอยู่ในตำแหน่งเดิมของแชร์งาน ซึ่งถูกถอดออกจากเมนู (หน้า /shared-tasks ยังเปิดตรง ๆ ได้)
            'href="'.route('social.index').'"',
            'href="'.route('workspace.index').'"',
            'id="top-nav-reports"',
            'id="top-nav-more"',
        ]);
        $this->assertSame(['reports', 'more'], $this->groupKeys($nav));
        $this->assertStringNotContainsString('href="'.route('shares.index').'"', $nav);
        $this->assertStringNotContainsString('แชร์งาน', $nav);
        $this->assertMatchesRegularExpression('/href="'.preg_quote(route('social.index'), '/').'" class="top-nav__link" data-top-nav-entry\s*>\s*<i class="bi bi-chat-square-text top-nav__icon" aria-hidden="true"><\/i>\s*<span class="top-nav__label">ชุมชน<\/span>/', $nav);

        $this->assertInOrder($this->menu($nav, 'reports'), [
            'href="'.route('reports.operational').'"',
            'href="'.route('reports.projects').'"',
        ]);

        // เพิ่มเติม: Smart Ticket แล้วจึงการสื่อสาร (การแจ้งเตือน)
        $more = $this->menu($nav, 'more');
        $this->assertInOrder($more, [
            'aria-label="Smart Ticket"',
            'href="'.route('tickets.index').'"',
            'aria-label="การสื่อสาร"',
            'href="'.route('notifications.index').'"',
        ]);
        $this->assertStringNotContainsString('aria-label="แผนกของฉัน"', $more);

        foreach (['meetings.index', 'announcements.index', 'admin.approvals.index', 'employees.index', 'admin.accounts.index', 'board.index'] as $hidden) {
            $this->assertStringNotContainsString('href="'.route($hidden).'"', $nav, $hidden);
        }
    }

    public function test_department_head_gets_the_department_board_and_department_tools(): void
    {
        $head = $this->userWithRole('head');
        $nav = $this->nav($this->actingAs($head)->get(route('mytasks.index'))->assertOk()->getContent());

        $this->assertStringContainsString('href="'.route('work-board.department', $head->department_id).'"', $nav);
        $this->assertStringNotContainsString('href="'.route('work-board.index').'"', $nav);

        // เครื่องมือดูแลแผนกอยู่หมวดแรกของ "เพิ่มเติม" ก่อน Smart Ticket และการแจ้งเตือน
        $this->assertInOrder($this->menu($nav, 'more'), [
            'aria-label="แผนกของฉัน"',
            'href="'.route('admin.approvals.index').'"',
            'งานข้ามแผนกของทีม',
            'href="'.route('announcements.index').'"',
            'aria-label="Smart Ticket"',
            'href="'.route('notifications.index').'"',
        ]);

        // ประกาศเป็นเครื่องมือของหัวหน้าแผนกเท่านั้น
        $link = 'href="'.route('announcements.index').'"';
        foreach (['user' => 'mytasks.index', 'admin' => 'board.index', 'viewer' => 'board.index'] as $role => $page) {
            $this->assertStringNotContainsString($link, $this->nav($this->actingAs($this->userWithRole($role))->get(route($page))->getContent()), $role);
        }
    }

    /**
     * admin เป็นผู้ดูแลระบบ ไม่ใช่ผู้อนุมัติงาน — "คำขออนุมัติ" ไม่ขึ้นเมื่อไม่มีคำขอค้าง
     * (AdminRoleScopeTest ตรวจเงื่อนไขการแสดง/ซ่อนเมนูนี้ครบทั้งสองทาง)
     */
    public function test_admin_navigation_groups_people_and_system_settings(): void
    {
        $nav = $this->nav($this->actingAs($this->userWithRole('admin'))->get(route('board.index'))->assertOk()->getContent());

        $this->assertInOrder($nav, [
            'href="'.route('board.index').'"',
            'href="'.route('daily-logs.index').'"',
            'href="'.route('social.index').'"',
            'href="'.route('workspace.index').'"',
            'id="top-nav-reports"',
            'id="top-nav-admin"',
            'id="top-nav-more"',
        ]);
        $this->assertSame(['reports', 'admin', 'more'], $this->groupKeys($nav));
        $this->assertStringNotContainsString('href="'.route('shares.index').'"', $nav);

        $this->assertInOrder($this->menu($nav, 'admin'), [
            'aria-label="บุคลากรและแผนก"',
            'href="'.route('employees.index').'"',
            'href="'.route('admin.departments.index').'"',
            'aria-label="การตั้งค่าระบบ"',
            'href="'.route('admin.accounts.index').'"',
            'href="'.route('admin.work-log-categories.index').'"',
            'href="'.route('admin.tickets.index').'"',
            'href="'.route('admin.audit.index').'"',
        ]);

        $this->assertInOrder($this->menu($nav, 'more'), [
            'aria-label="การสื่อสารและบริการ"',
            'href="'.route('notifications.index').'"',
            'href="'.route('tickets.index').'"',
        ]);

        // การประชุมของ admin อยู่ใน Member Workspace ส่วนคำขออนุมัติขึ้นเฉพาะตอนมีของค้าง
        foreach (['meetings.index', 'admin.approvals.index', 'announcements.index', 'admin.activity-logs.index', 'admin.trash.index'] as $hidden) {
            $this->assertStringNotContainsString('href="'.route($hidden).'"', $nav, $hidden);
        }
        $this->assertStringNotContainsString('การประชุม', $nav);
    }

    public function test_viewer_navigation_is_read_only(): void
    {
        $nav = $this->nav($this->actingAs($this->userWithRole('viewer'))->get(route('board.index'))->assertOk()->getContent());

        $this->assertInOrder($nav, [
            'href="'.route('board.index').'"',
            // กระดานที่ตั้งเป็น "ทั้งองค์กร" เปิดให้อ่านได้ทุกคน ส่วนการวาดถูกปิดที่ WorkspaceBoardPolicy
            'href="'.route('workspace.index').'"',
            // ชุมชนเปิดให้ทุกบทบาท รวมถึง viewer (SocialPostPolicy)
            'href="'.route('social.index').'"',
            'id="top-nav-reports"',
            'href="'.route('meetings.index').'"',
            'href="'.route('employees.index').'"',
            'href="'.route('notifications.index').'"',
        ]);
        $this->assertSame(['reports'], $this->groupKeys($nav));
        $this->assertStringContainsString('href="'.route('reports.projects').'"', $this->menu($nav, 'reports'));

        // viewer อ่านอย่างเดียว: ไม่มีบันทึกงานประจำวัน แชร์งาน หรือรายงานปฏิบัติงาน (กดแล้วเจอ 403)
        foreach (['daily-logs.index', 'shares.index', 'reports.operational', 'mytasks.index', 'announcements.index'] as $hidden) {
            $this->assertStringNotContainsString('href="'.route($hidden).'"', $nav, $hidden);
        }
    }

    /**
     * ทุกหน้าบอกเมนูปัจจุบันด้วย aria-current หนึ่งรายการ และมีเมนูแถวบนที่ไฮไลต์ได้รายการเดียว
     * (เมนูในดร็อปดาวน์ → หัวกลุ่มของมันเป็นตัวที่ไฮไลต์บนแถวบน)
     */
    public function test_exactly_one_entry_is_current_and_its_group_is_highlighted(): void
    {
        foreach ([
            ['user', 'mytasks.index', 'mytasks.index', null],
            ['user', 'social.index', 'social.index', null],
            ['head', 'social.index', 'social.index', null],
            ['admin', 'social.index', 'social.index', null],
            ['viewer', 'social.index', 'social.index', null],
            ['user', 'notifications.index', 'notifications.index', 'more'],
            ['user', 'reports.projects', 'reports.projects', 'reports'],
            ['user', 'reports.operational', 'reports.operational', 'reports'],
            ['head', 'announcements.index', 'announcements.index', 'more'],
            ['admin', 'board.index', 'board.index', null],
            ['admin', 'employees.index', 'employees.index', 'admin'],
            ['admin', 'admin.audit.index', 'admin.audit.index', 'admin'],
            ['viewer', 'workspace.index', 'workspace.index', null],
            ['viewer', 'meetings.index', 'meetings.index', null],
        ] as [$role, $page, $link, $group]) {
            $nav = $this->nav($this->actingAs($this->userWithRole($role))->get(route($page))->assertOk()->getContent());
            $label = "{$role} {$page}";

            $this->assertSame(1, substr_count($nav, 'aria-current='), $label);
            $this->assertSame(1, preg_match_all('/class="top-nav__link[^"]*\bis-active\b/', $nav), $label);

            if ($group === null) {
                $this->assertMatchesRegularExpression('/href="'.preg_quote(route($link), '/').'" class="top-nav__link is-active" data-top-nav-entry\s+aria-current="page"/', $nav, $label);
            } else {
                $this->assertMatchesRegularExpression('/class="top-nav__link top-nav__toggle is-active" data-top-nav-entry\s+id="top-nav-'.$group.'"/', $nav, $label);
                $this->assertMatchesRegularExpression('/href="'.preg_quote(route($link), '/').'" class="dropdown-item nav-dropdown__item is-active"\s+aria-current="page"/', $this->menu($nav, $group), $label);
            }
        }

        // หน้าที่ไม่อยู่ในเมนู (ตั้งค่า และแชร์งานที่ถูกถอดจากเมนู) ไม่มีเมนูใดถูกไฮไลต์
        foreach (['settings.index', 'shares.index'] as $page) {
            $nav = $this->nav($this->actingAs($this->userWithRole('user'))->get(route($page))->assertOk()->getContent());
            $this->assertSame(0, substr_count($nav, 'aria-current='), $page);
            $this->assertStringNotContainsString('is-active', $nav, $page);
        }
    }

    public function test_pages_below_a_menu_entry_are_announced_as_a_location_not_the_page(): void
    {
        $nav = $this->nav($this->actingAs($this->userWithRole('user'))->get(route('reports.projects.details'))->assertOk()->getContent());

        $this->assertMatchesRegularExpression('/href="'.preg_quote(route('reports.projects'), '/').'" class="dropdown-item nav-dropdown__item is-active"\s+aria-current="location"/', $nav);
        $this->assertStringNotContainsString('aria-current="page"', $nav);
    }

    public function test_notification_badge_keeps_the_realtime_hook_even_when_zero(): void
    {
        $nav = $this->nav($this->actingAs($this->userWithRole('user'))->get(route('mytasks.index'))->assertOk()->getContent());

        // realtime-sync.js อัปเดตทุก [data-notification-count] ในหน้า ป้ายจึงต้องอยู่ใน DOM แม้ยังเป็นศูนย์
        $this->assertMatchesRegularExpression('/<span class="top-nav__badge top-nav__badge--danger" data-notification-count data-nav-notification-count\s+hidden >0<\/span>/', $nav);
        // ไม่มีเรื่องรอผู้ใช้ จึงไม่มีจุดเตือนบนหัวกลุ่ม
        $this->assertStringNotContainsString('top-nav__dot', $nav);
    }

    public function test_account_dropdown_shows_the_real_username(): void
    {
        foreach (['admin', 'viewer', 'user'] as $role) {
            $user = $this->userWithRole($role);
            $this->actingAs($user)->get(route('settings.index'))->assertOk()
                ->assertSee('aria-label="เมนูบัญชีผู้ใช้"', false)
                ->assertSee('เข้าสู่ระบบในชื่อ <strong>'.$user->username.'</strong>', false)
                // ใช้การเปิด/ปิดชุดเดียวกับเมนูหลัก
                ->assertSee('id="topbarAccountTrigger" data-bs-toggle="dropdown" data-bs-display="static"', false)
                ->assertSee('class="dropdown-menu dropdown-menu-end topbar-slide-menu topbar-account__menu"', false);
        }
    }

    /**
     * ปุ่มบัญชีบน Topbar แสดงเฉพาะรูปโปรไฟล์ — ไม่มีชื่อ บทบาท แผนก หรือลูกศร
     * ชื่อกับบทบาท/แผนกยังอยู่ในหน้าตั้งค่าบัญชี (test ถัดไป)
     */
    public function test_the_account_button_shows_only_the_profile_picture(): void
    {
        foreach (['user', 'head', 'admin', 'viewer'] as $role) {
            $user = $this->userWithRole($role);
            $user->forceFill(['name' => 'สมชาย ทดสอบบัญชี'])->save();
            // settings.index เปิดได้ทุกบทบาท (viewer เปิด mytasks ไม่ได้)
            $content = $this->actingAs($user)->get(route('settings.index'))->assertOk()->getContent();
            $trigger = $this->between($content, '<button type="button" class="topbar-account__trigger"', '</button>');

            $this->assertStringContainsString('data-account-avatar', $trigger, $role);
            $this->assertStringContainsString('aria-label="เมนูบัญชีผู้ใช้"', $trigger, $role);
            $this->assertStringContainsString('สม', $trigger, $role);
            foreach (['topbar-account__identity', 'topbar-account__name', 'topbar-account__role', 'topbar-account__chevron', 'สมชาย ทดสอบบัญชี', RoleLabel::for($user), $user->department->department_name] as $hidden) {
                $this->assertStringNotContainsString($hidden, $trigger, "{$role}: {$hidden}");
            }
            $this->assertStringNotContainsString('sidebar-foot', $content);
            $this->assertStringNotContainsString('role-chip--mobile-only', $content);
            $this->assertStringNotContainsString('href="'.route('settings.index').'"', $content);
        }
    }

    /**
     * เดิมป้ายบทบาทมีแค่คลาส admin กับ user หัวหน้าแผนกและพนักงานจึงเป็นสีเขียว
     * เหมือนกันหมด และไม่บอกว่าอยู่แผนกไหน ทั้งที่ทั้งสองบทบาทมีสิทธิ์ต่างกันชัดเจน
     */
    public function test_role_label_separates_head_from_staff(): void
    {
        foreach (['user' => 'พนักงาน', 'head' => 'หัวหน้าแผนก'] as $role => $label) {
            $user = $this->userWithRole($role);

            // ตัวช่วยกลางยังแยกสองบทบาทออกจากกัน (ใช้ที่การ์ดสมาชิก ผู้เข้าร่วมประชุม ฯลฯ)
            // ส่วน Topbar และเมนูบัญชีไม่แสดงบทบาทหรือแผนกของผู้ใช้อีกแล้ว
            $this->assertSame($label, RoleLabel::for($user));
            $this->actingAs($user)->get(route('settings.index'))
                ->assertOk()
                ->assertDontSee('role-chip__label', false)
                ->assertDontSee('topbar-account__role', false)
                ->assertDontSee('<p>'.$label.' · '.$user->department->department_name.'</p>', false);
        }
    }

    /**
     * "หัวหน้าแผนก" ไม่ใช่ค่าใน users.role แต่เป็นธง users.is_department_head
     * ทุกหน้าอ่านจาก App\Support\RoleLabel ตัวเดียว
     */
    public function test_department_head_role_label_is_consistent_across_pages(): void
    {
        $head = $this->userWithRole('head');

        $this->assertSame('หัวหน้าแผนก', RoleLabel::for($head));

        // ธง is_department_head เปลี่ยนชื่อบทบาททุกที่ที่ใช้ตัวช่วยนี้ — ปิดธงแล้วกลับเป็นพนักงาน
        $head->forceFill(['is_department_head' => false])->save();
        $this->assertSame('พนักงาน', RoleLabel::for($head->fresh()));
    }

    public function test_logout_lives_in_the_topbar_after_notifications(): void
    {
        $content = $this->actingAs($this->userWithRole('user'))->get(route('mytasks.index'))
            ->assertOk()
            ->assertSee('data-account-open="accountLogoutModal"', false)
            ->getContent();

        $bell = strpos($content, 'data-bell-notification-count');
        $logout = strpos($content, 'data-account-open="accountLogoutModal"');

        $this->assertNotFalse($bell);
        $this->assertGreaterThan(strpos($content, '<header class="topbar">'), $logout);
        $this->assertGreaterThan($bell, $logout, 'ปุ่มออกจากระบบต้องอยู่หลังไอคอนแจ้งเตือน');
        $this->assertLessThan(strpos($content, '</header>'), $logout, 'ปุ่มออกจากระบบต้องอยู่ใน Topbar');
    }

    public function test_the_topbar_initials_cut_by_character_not_by_byte(): void
    {
        $user = $this->userWithRole('user');
        $user->forceFill(['name' => 'สมหญิง ใจดี', 'profile_image' => null])->save();

        $content = $this->actingAs($user)->get(route('mytasks.index'))->assertOk()->getContent();
        preg_match('/data-account-avatar>(.*?)<\/span>/s', $content, $match);
        $avatar = $match[1];

        $this->assertStringContainsString('สม', $avatar);
        $this->assertTrue(mb_check_encoding($avatar, 'UTF-8'), 'ไม่มีอักษรที่ถูกตัดกลางตัว');
        $this->assertStringNotContainsString("\u{FFFD}", $avatar);
    }

    /**
     * แถบเมนูด้านข้าง (< 992px) ต้องมีรายการเดียวกับเมนูแนวนอน (≥ 992px) ทุกบทบาท ทุกลำดับ
     * เพราะทั้งคู่วาดจาก $navigation ชุดเดียว (App\Support\TopNavigation) ไม่มีกฎสิทธิ์ชุดที่สอง
     */
    public function test_mobile_sidebar_lists_exactly_the_same_entries_as_the_desktop_menu_for_every_role(): void
    {
        foreach ([
            'user' => 'mytasks.index',
            'head' => 'announcements.index',
            'admin' => 'employees.index',
            'viewer' => 'social.index',
        ] as $role => $page) {
            $content = $this->actingAs($this->userWithRole($role))->get(route($page))->assertOk()->getContent();
            $desktop = $this->nav($content);
            $this->assertStringContainsString('href="'.route('social.index').'"', $this->mobileNav($content), $role);
            $mobile = $this->mobileNav($content);

            $this->assertSame($this->hrefs($desktop), $this->hrefs($mobile), "{$role}: ลิงก์และลำดับต้องตรงกัน");
            $this->assertSame(1, substr_count($mobile, 'aria-current='), $role);
            $this->assertMatchesRegularExpression('/href="'.preg_quote(route($page), '/').'" class="mobile-nav__(sub)?link is-active"\s+aria-current="page"/', $mobile, $role);
            // ป้ายแจ้งเตือนในแถบข้างยังมี hook ของ realtime-sync.js
            $this->assertStringContainsString('data-notification-count data-nav-notification-count', $mobile, $role);
        }
    }

    public function test_mobile_sidebar_starts_closed_and_expands_only_the_group_of_the_current_page(): void
    {
        $content = $this->actingAs($this->userWithRole('admin'))->get(route('employees.index'))->assertOk()->getContent();

        // ปุ่ม ☰ อยู่ใน Topbar และควบคุมแถบที่อยู่นอก header (backdrop-filter ของ header ยึด position: fixed)
        $header = $this->between($content, '<header class="topbar">', '</header>');
        $this->assertStringContainsString('data-mobile-sidebar-open aria-controls="mobileSidebar"', $header);
        $this->assertStringContainsString('aria-expanded="false" aria-label="เปิดเมนู"', $header);
        $this->assertStringNotContainsString('data-mobile-sidebar ', $header);
        $this->assertMatchesRegularExpression('/<div class="mobile-sidebar" id="mobileSidebar" role="dialog" aria-modal="true" aria-labelledby="mobileSidebarTitle"\s+data-mobile-sidebar inert>/', $content);

        $mobile = $this->mobileNav($content);
        // กลุ่ม "ผู้ดูแลระบบ" มีหน้าปัจจุบัน — กางและ Tab เข้าได้ กลุ่มอื่นพับและเป็น inert
        $this->assertMatchesRegularExpression('/<li class="mobile-nav__group is-open" data-mobile-nav-group>\s*<button[^>]*aria-expanded="true"\s+aria-controls="mobile-nav-admin"/', $mobile);
        $this->assertMatchesRegularExpression('/id="mobile-nav-admin" data-mobile-nav-panel\s*>/', $mobile);
        foreach (['reports', 'more'] as $collapsed) {
            $this->assertMatchesRegularExpression('/aria-expanded="false"\s+aria-controls="mobile-nav-'.$collapsed.'"/', $mobile, $collapsed);
            $this->assertMatchesRegularExpression('/id="mobile-nav-'.$collapsed.'" data-mobile-nav-panel\s+inert\s*>/', $mobile, $collapsed);
        }

        // ไม่มีชื่อ view-transition หรือ id ซ้ำกับเมนูแนวนอน
        $this->assertStringNotContainsString('top-nav__link', $mobile);
        $this->assertStringNotContainsString('id="top-nav-', $mobile);
    }

    private function mobileNav(string $content): string
    {
        return $this->between($content, '<nav class="mobile-nav"', '</nav>');
    }

    /** @return list<string> */
    private function hrefs(string $html): array
    {
        preg_match_all('/href="([^"]+)"/', $html, $matches);

        return $matches[1];
    }

    private function userWithRole(string $role): User
    {
        $department = Department::create(['department_name' => 'Navigation '.uniqid()]);

        return User::factory()->create([
            'role' => $role === 'head' ? 'user' : $role,
            'is_department_head' => $role === 'head',
            'department_id' => $department->id,
            'must_change_password' => false,
            'is_active' => true,
        ]);
    }

    private function nav(string $content): string
    {
        return $this->between($content, '<nav class="top-nav"', '</nav>');
    }

    /** เนื้อหาในดร็อปดาวน์ของกลุ่มหนึ่ง (ตั้งแต่ id ของเมนูถึงปิด <li> ของกลุ่ม) */
    private function menu(string $nav, string $group): string
    {
        return $this->between($nav, 'id="top-nav-'.$group.'-menu"', '</li>');
    }

    /** @return list<string> */
    private function groupKeys(string $nav): array
    {
        preg_match_all('/id="top-nav-([a-z-]+)" data-bs-toggle="dropdown"/', $nav, $matches);

        return $matches[1];
    }

    private function between(string $content, string $start, string $end): string
    {
        $from = strpos($content, $start);
        $this->assertNotFalse($from, "ไม่พบ {$start}");
        $to = strpos($content, $end, $from);
        $this->assertNotFalse($to, "ไม่พบ {$end}");

        return substr($content, $from, $to - $from + strlen($end));
    }

    /** @param  list<string>  $needles */
    private function assertInOrder(string $haystack, array $needles): void
    {
        $positions = array_map(fn (string $needle) => strpos($haystack, $needle), $needles);

        foreach ($positions as $index => $position) {
            $this->assertNotFalse($position, 'ไม่พบ '.$needles[$index]);
        }

        $this->assertSame($positions, collect($positions)->sort()->values()->all(), 'ลำดับไม่ตรง: '.implode(' → ', $needles));
    }
}
