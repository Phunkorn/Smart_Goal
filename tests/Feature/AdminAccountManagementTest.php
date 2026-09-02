<?php

namespace Tests\Feature;

use App\Models\Department;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminAccountManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_employee_and_system_account_pages_show_only_their_own_account_types(): void
    {
        $department = Department::create(['department_name' => 'Operations']);
        $admin = $this->user('admin', 'Current Admin');
        $otherAdmin = $this->user('admin', 'Other System Admin');
        $viewer = $this->user('viewer', 'System Viewer');
        $employee = $this->user('user', 'Department Employee', $department);
        $head = $this->user('user', 'Department Head', $department, true);

        $this->actingAs($admin)
            ->get(route('employees.index'))
            ->assertOk()
            ->assertSee($employee->name)
            ->assertSee($head->name)
            ->assertDontSee($otherAdmin->name)
            ->assertDontSee($viewer->name)
            ->assertDontSee('<option value="admin"', false)
            ->assertDontSee('<option value="viewer"', false)
            ->assertSee('<option value="department_head"', false);

        $this->actingAs($admin)
            ->get(route('admin.accounts.index'))
            ->assertOk()
            ->assertSee($otherAdmin->name)
            ->assertSee($viewer->name)
            ->assertDontSee($employee->name)
            ->assertDontSee($head->name)
            ->assertSee('<option value="admin"', false)
            ->assertSee('<option value="viewer"', false)
            ->assertDontSee('<option value="department_head"', false);
    }

    public function test_only_admin_can_open_and_manage_system_accounts(): void
    {
        $admin = $this->user('admin', 'Admin');
        $viewer = $this->user('viewer', 'Viewer');
        $employee = $this->user('user', 'Employee');

        $this->actingAs($admin)
            ->get(route('admin.accounts.index'))
            ->assertOk();

        $this->actingAs($viewer)
            ->get(route('admin.accounts.index'))
            ->assertForbidden();

        $this->actingAs($employee)
            ->get(route('admin.accounts.index'))
            ->assertForbidden();

        $this->actingAs($viewer)
            ->post(route('admin.accounts.store'), [])
            ->assertForbidden();
    }

    public function test_each_management_context_rejects_roles_from_the_other_context(): void
    {
        $department = Department::create(['department_name' => 'Operations']);
        $admin = $this->user('admin', 'Admin');

        foreach (['admin', 'viewer'] as $role) {
            $this->actingAs($admin)
                ->post(route('employees.store'), $this->employeePayload($department, [
                    'username' => 'employee-route-'.$role,
                    'role' => $role,
                ]))
                ->assertSessionHasErrors('role');
        }

        foreach (['user', 'department_head'] as $role) {
            $this->actingAs($admin)
                ->post(route('admin.accounts.store'), $this->systemAccountPayload([
                    'username' => 'system-route-'.$role,
                    'role' => $role,
                ]))
                ->assertSessionHasErrors('role');
        }

        $this->assertDatabaseMissing('users', ['username' => 'employee-route-admin']);
        $this->assertDatabaseMissing('users', ['username' => 'employee-route-viewer']);
        $this->assertDatabaseMissing('users', ['username' => 'system-route-user']);
        $this->assertDatabaseMissing('users', ['username' => 'system-route-department_head']);
    }

    public function test_system_account_page_creates_admin_and_viewer_without_department_membership(): void
    {
        $admin = $this->user('admin', 'Admin');

        foreach (['admin', 'viewer'] as $role) {
            $username = 'new-'.$role;

            $this->actingAs($admin)
                ->post(route('admin.accounts.store'), $this->systemAccountPayload([
                    'name' => 'New '.ucfirst($role),
                    'username' => $username,
                    'role' => $role,
                    'department_id' => 999,
                    'is_department_head' => true,
                ]))
                ->assertRedirect(route('admin.accounts.index'))
                ->assertSessionDoesntHaveErrors();

            $this->assertDatabaseHas('users', [
                'username' => $username,
                'role' => $role,
                'department_id' => null,
                'is_department_head' => false,
            ]);
        }
    }

    public function test_management_routes_cannot_mutate_accounts_from_the_other_context(): void
    {
        $department = Department::create(['department_name' => 'Operations']);
        $admin = $this->user('admin', 'Admin');
        $viewer = $this->user('viewer', 'Viewer');
        $employee = $this->user('user', 'Employee', $department);

        $this->actingAs($admin)
            ->patch(route('employees.update', $viewer), $this->employeePayload($department))
            ->assertNotFound();
        $this->actingAs($admin)
            ->delete(route('employees.destroy', $viewer))
            ->assertNotFound();
        $this->actingAs($admin)
            ->patch(route('employees.resetPassword', $viewer), ['password' => 'Temporary!123'])
            ->assertNotFound();

        $this->actingAs($admin)
            ->patch(route('admin.accounts.update', $employee), $this->systemAccountPayload())
            ->assertNotFound();
        $this->actingAs($admin)
            ->delete(route('admin.accounts.destroy', $employee))
            ->assertNotFound();
        $this->actingAs($admin)
            ->patch(route('admin.accounts.resetPassword', $employee), ['password' => 'Temporary!123'])
            ->assertNotFound();
    }

    public function test_admin_cannot_demote_deactivate_or_delete_their_own_account(): void
    {
        $admin = $this->user('admin', 'Admin');

        $this->actingAs($admin)
            ->patch(route('admin.accounts.update', $admin), $this->systemAccountPayload([
                'name' => $admin->name,
                'username' => $admin->username,
                'email' => $admin->email,
                'role' => 'viewer',
            ]))
            ->assertSessionHasErrors('user');

        $this->actingAs($admin)
            ->patch(route('admin.accounts.update', $admin), $this->systemAccountPayload([
                'name' => $admin->name,
                'username' => $admin->username,
                'email' => $admin->email,
                'is_active' => false,
            ]))
            ->assertSessionHasErrors('user');

        $this->actingAs($admin)
            ->delete(route('admin.accounts.destroy', $admin))
            ->assertSessionHasErrors('user');

        $admin->refresh();
        $this->assertSame('admin', $admin->role);
        $this->assertTrue($admin->is_active);
        $this->assertNull($admin->deleted_at);
    }

    public function test_sidebar_shows_system_account_menu_to_admin_only(): void
    {
        $admin = $this->user('admin', 'Admin');
        $viewer = $this->user('viewer', 'Viewer');
        $employee = $this->user('user', 'Employee');

        $this->actingAs($admin)
            ->get(route('board.index'))
            ->assertOk()
            ->assertSee(route('admin.accounts.index'), false);

        $this->actingAs($viewer)
            ->get(route('board.index'))
            ->assertOk()
            ->assertDontSee(route('admin.accounts.index'), false);
    }

    private function employeePayload(Department $department, array $overrides = []): array
    {
        return array_replace([
            'name' => 'Employee Test',
            'username' => 'employee-test',
            'email' => null,
            'phone' => null,
            'password' => 'SecurePassword!123',
            'password_confirmation' => 'SecurePassword!123',
            'role' => 'user',
            'is_active' => true,
            'department_id' => $department->id,
        ], $overrides);
    }

    private function systemAccountPayload(array $overrides = []): array
    {
        return array_replace([
            'name' => 'System Account',
            'username' => 'system-account',
            'email' => null,
            'phone' => null,
            'password' => 'SecurePassword!123',
            'password_confirmation' => 'SecurePassword!123',
            'role' => 'admin',
            'is_active' => true,
        ], $overrides);
    }

    private function user(
        string $role,
        string $name,
        ?Department $department = null,
        bool $isDepartmentHead = false,
    ): User {
        return User::factory()->create([
            'name' => $name,
            'role' => $role,
            'department_id' => $department?->id,
            'is_department_head' => $isDepartmentHead,
            'must_change_password' => false,
            'is_active' => true,
        ]);
    }
}
