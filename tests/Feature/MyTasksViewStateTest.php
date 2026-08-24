<?php

namespace Tests\Feature;

use App\Http\Controllers\MyTaskController;
use App\Models\Department;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * มุมมองของหน้า "งานของฉัน" ถูกตัดสินที่ server (?view= → session → ปฏิทิน)
 * เพื่อไม่ให้หน้าจอกระพริบจากตารางไปปฏิทินหลังเข้าสู่ระบบ
 */
class MyTasksViewStateTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('session.driver', 'database');
    }

    public function test_member_login_lands_on_calendar_view(): void
    {
        $user = $this->userWithRole('user', 'calendar.user');

        $this->post(route('login.submit'), [
            'username' => $user->username,
            'password' => 'password',
        ])
            ->assertRedirect(route('mytasks.index'))
            ->assertSessionHas(MyTaskController::WORKSPACE_VIEW_SESSION_KEY, 'calendar');
    }

    public function test_login_overrides_a_remembered_view_because_regenerate_keeps_session_data(): void
    {
        $user = $this->userWithRole('user', 'remembered.user');

        // session()->regenerate() เรียก migrate(false) จึงเก็บ attribute เดิมไว้ทั้งหมด
        $this->withSession([MyTaskController::WORKSPACE_VIEW_SESSION_KEY => 'board'])
            ->post(route('login.submit'), [
                'username' => $user->username,
                'password' => 'password',
            ])
            ->assertRedirect(route('mytasks.index'))
            ->assertSessionHas(MyTaskController::WORKSPACE_VIEW_SESSION_KEY, 'calendar');
    }

    public function test_admin_and_viewer_login_redirects_are_untouched(): void
    {
        $admin = $this->userWithRole('admin', 'board.admin');
        $viewer = $this->userWithRole('viewer', 'board.viewer');

        $this->post(route('login.submit'), [
            'username' => $admin->username,
            'password' => 'password',
        ])
            ->assertRedirect(route('board.index'))
            ->assertSessionMissing(MyTaskController::WORKSPACE_VIEW_SESSION_KEY);

        $this->post(route('logout'));

        $this->post(route('login.submit'), [
            'username' => $viewer->username,
            'password' => 'password',
        ])
            ->assertRedirect(route('board.index'))
            ->assertSessionMissing(MyTaskController::WORKSPACE_VIEW_SESSION_KEY);
    }

    public function test_my_tasks_without_query_renders_calendar_active_from_the_server(): void
    {
        $user = $this->userWithRole('user');

        $content = $this->actingAs($user)
            ->get(route('mytasks.index'))
            ->assertOk()
            ->assertSee('data-view="calendar"', false)
            ->getContent();

        $this->assertMatchesRegularExpression(
            '/<button class="active"[^>]*data-view="calendar"[^>]*aria-selected="true"/',
            $content,
            'ปุ่มปฏิทินต้องถูก render เป็น active ตั้งแต่ HTML แรก'
        );
        $this->assertMatchesRegularExpression(
            '/<button class=""[^>]*data-view="table"[^>]*aria-selected="false"/',
            $content,
            'ปุ่มตารางต้องไม่ active เพื่อพิสูจน์ว่าไม่มีการกระพริบ'
        );
        $this->assertStringContainsString('notion-database" data-view="calendar"', $content);
    }

    public function test_requested_view_is_honoured_and_invalid_view_falls_back_to_calendar(): void
    {
        $user = $this->userWithRole('user');

        $this->actingAs($user)
            ->get(route('mytasks.index', ['view' => 'board']))
            ->assertOk()
            ->assertSee('notion-database" data-view="board"', false);

        $this->actingAs($user)
            ->get(route('mytasks.index', ['view' => 'ไม่มีจริง']))
            ->assertOk()
            ->assertSee('notion-database" data-view="calendar"', false);
    }

    public function test_invalid_view_is_never_written_into_the_session(): void
    {
        $user = $this->userWithRole('user');

        $this->actingAs($user)
            ->withSession([MyTaskController::WORKSPACE_VIEW_SESSION_KEY => 'board'])
            ->get(route('mytasks.index', ['view' => 'nonsense']))
            ->assertOk()
            ->assertSessionHas(MyTaskController::WORKSPACE_VIEW_SESSION_KEY, 'board');
    }

    public function test_chosen_view_is_remembered_for_the_next_bare_visit(): void
    {
        $user = $this->userWithRole('user');

        $this->actingAs($user)
            ->get(route('mytasks.index', ['view' => 'board']))
            ->assertOk()
            ->assertSessionHas(MyTaskController::WORKSPACE_VIEW_SESSION_KEY, 'board');

        $this->actingAs($user)
            ->get(route('mytasks.index'))
            ->assertOk()
            ->assertSee('notion-database" data-view="board"', false);
    }

    private function userWithRole(string $role, ?string $username = null): User
    {
        $department = Department::create(['department_name' => 'View '.uniqid()]);

        return User::factory()->create([
            'username' => $username ?: 'view'.uniqid(),
            'role' => $role,
            'department_id' => $department->id,
            'is_active' => true,
            'must_change_password' => false,
        ]);
    }
}
