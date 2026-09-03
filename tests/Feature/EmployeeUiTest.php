<?php

namespace Tests\Feature;

use App\Models\Department;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class EmployeeUiTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_employee_page_has_ordered_actions_and_resolvable_unique_modal_targets(): void
    {
        $admin = $this->user('admin');
        $employee = $this->user('user', Department::create(['department_name' => 'Operations']));

        $response = $this->actingAs($admin)
            ->get(route('employees.index'))
            ->assertOk()
            ->assertSee('employee-page__header', false)
            ->assertSee('employee-toolbar', false)
            ->assertSee('employee-card__actions', false)
            ->assertDontSee('employee-current-work', false)
            ->assertDontSee('employee-meeting-link', false)
            ->assertDontSee('modal-dialog-scrollable', false)
            ->assertSee('ข้อมูลบัญชี')
            ->assertSee('ข้อมูลติดต่อ')
            ->assertSee('สิทธิ์และองค์กร')
            ->assertSee('รูปภาพโปรไฟล์')
            ->assertSee('บัญชีผู้ใช้งาน')
            ->assertSee('แผนก:')
            ->assertSee($employee->username)
            ->assertDontSee('@'.$employee->username)
            ->assertSee('id="createUserModalPassword"', false)
            ->assertDontSee('id="editUserModal'.$employee->id.'Password"', false);

        $html = $response->getContent();
        $editPosition = strpos($html, 'employee-action--edit');
        $resetPosition = strpos($html, 'employee-action--reset');
        $deletePosition = strpos($html, 'employee-action--delete');

        $this->assertIsInt($editPosition);
        $this->assertIsInt($resetPosition);
        $this->assertIsInt($deletePosition);
        $this->assertTrue($editPosition < $resetPosition && $resetPosition < $deletePosition);
        $this->assertStringNotContainsString('employee-action--view', $html);
        $this->assertFalse(Route::has('employees.show'));

        preg_match_all('/\sdata-bs-target="#([A-Za-z][A-Za-z0-9_-]*)"/', $html, $targets);
        preg_match_all('/\sid="([A-Za-z][A-Za-z0-9_-]*)"/', $html, $ids);

        $idCounts = array_count_values($ids[1]);
        foreach ($targets[1] as $targetId) {
            $this->assertSame(1, $idCounts[$targetId] ?? 0, "Modal target {$targetId} must resolve to one element.");
        }

        $this->assertSame([], array_filter($idCounts, fn (int $count) => $count > 1));
    }

    public function test_create_employee_modal_has_one_simple_temporary_password_field(): void
    {
        $admin = $this->user('admin');
        $employee = $this->user('user', Department::create(['department_name' => 'Operations']));
        $html = $this->actingAs($admin)
            ->get(route('employees.index'))
            ->assertOk()
            ->getContent();

        $createIdPosition = strpos($html, 'id="createUserModal"');
        $createStart = $createIdPosition === false
            ? false
            : strrpos(substr($html, 0, $createIdPosition), '<div class="modal fade employee-form-modal');
        $createEnd = strpos($html, 'id="editUserModal'.$employee->id.'"', $createStart);

        $this->assertIsInt($createIdPosition);
        $this->assertIsInt($createStart);
        $this->assertIsInt($createEnd);

        $createModal = substr($html, $createStart, $createEnd - $createStart);

        $this->assertStringContainsString('employee-form-modal--create', $createModal);
        $this->assertSame(1, substr_count($createModal, 'name="password"'));
        $this->assertStringNotContainsString('name="password_confirmation"', $createModal);
        $this->assertStringContainsString('ใช้สำหรับเข้าสู่ระบบครั้งแรก พนักงานจะต้องตั้งรหัสผ่านใหม่หลังเข้าสู่ระบบ', $createModal);
        $this->assertStringNotContainsString(\App\Support\PasswordPolicy::description(), $createModal);

        preg_match('/<input id="createUserModalPassword"[^>]*>/', $createModal, $passwordInput);
        $this->assertNotEmpty($passwordInput);
        $this->assertStringContainsString('required', $passwordInput[0]);
        $this->assertStringNotContainsString('minlength=', $passwordInput[0]);
    }

    public function test_viewer_sees_employee_information_without_management_controls_or_modals(): void
    {
        $viewer = $this->user('viewer');
        $employee = $this->user('user');

        $this->actingAs($viewer)
            ->get(route('employees.index'))
            ->assertOk()
            ->assertSee('บัญชีผู้ใช้งาน')
            ->assertSee($employee->username)
            ->assertDontSee('@'.$employee->username)
            ->assertDontSee('employee-current-work', false)
            ->assertDontSee('employee-meeting-link', false)
            ->assertDontSee('data-bs-target="#createUserModal"', false)
            ->assertDontSee('data-bs-target="#editUserModal', false)
            ->assertDontSee('data-bs-target="#resetPasswordModal', false)
            ->assertDontSee('employee-card__actions', false)
            ->assertDontSee('employee-delete-form', false);
    }

    public function test_employee_page_permissions_remain_admin_write_viewer_read_and_user_denied(): void
    {
        $viewer = $this->user('viewer');
        $user = $this->user('user');

        $this->actingAs($viewer)
            ->post(route('employees.store'), [])
            ->assertForbidden();

        $this->actingAs($user)
            ->get(route('employees.index'))
            ->assertForbidden();
    }

    private function user(string $role, ?Department $department = null): User
    {
        return User::factory()->create([
            'role' => $role,
            'department_id' => $department?->id,
            'must_change_password' => false,
            'is_active' => true,
        ]);
    }
}
