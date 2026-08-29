<?php

namespace Tests\Feature;

use App\Models\Department;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class UsernameEmployeeManagementTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('session.driver', 'database');
    }

    public function test_admin_creates_a_normalized_username_without_email(): void
    {
        $admin = $this->user('admin');

        $this->actingAs($admin)
            ->post(route('employees.store'), $this->employeePayload([
                'name' => 'No Email User',
                'username' => '  No.Email_User  ',
                'email' => '',
                'role' => 'viewer',
            ]))
            ->assertRedirect(route('employees.index'))
            ->assertSessionDoesntHaveErrors();

        $this->assertDatabaseHas('users', [
            'name' => 'No Email User',
            'username' => 'no.email_user',
            'email' => null,
        ]);
    }

    public function test_username_is_required_and_case_insensitively_unique_including_soft_deleted_users(): void
    {
        $admin = $this->user('admin');
        $deleted = User::factory()->create(['username' => 'reserved-user']);
        $deleted->delete();

        $this->actingAs($admin)
            ->post(route('employees.store'), $this->employeePayload(['username' => '']))
            ->assertSessionHasErrors('username');

        $this->actingAs($admin)
            ->post(route('employees.store'), $this->employeePayload(['username' => 'RESERVED-USER']))
            ->assertSessionHasErrors('username');

        $this->assertSame(1, User::withTrashed()->where('username', 'reserved-user')->count());
    }

    public function test_editing_username_rotates_remember_token_and_invalidates_database_sessions(): void
    {
        config()->set('session.driver', 'database');
        $admin = $this->user('admin');
        $employee = User::factory()->create([
            'username' => 'old-user',
            'remember_token' => 'old-remember-token',
            'role' => 'viewer',
            'must_change_password' => false,
        ]);
        DB::table('sessions')->insert([
            'id' => 'employee-session',
            'user_id' => $employee->id,
            'ip_address' => '127.0.0.1',
            'user_agent' => 'test',
            'payload' => 'test-payload',
            'last_activity' => now()->timestamp,
        ]);

        $this->actingAs($admin)
            ->patch(route('employees.update', $employee), $this->employeePayload([
                'name' => $employee->name,
                'username' => '  NEW-USER  ',
                'email' => '',
                'role' => 'viewer',
                'password' => '',
                'password_confirmation' => '',
            ]))
            ->assertRedirect(route('employees.index'));

        $employee->refresh();
        $this->assertSame('new-user', $employee->username);
        $this->assertNull($employee->email);
        $this->assertNull($employee->email_verified_at);
        $this->assertNotSame('old-remember-token', $employee->remember_token);
        $this->assertDatabaseMissing('sessions', ['id' => 'employee-session']);
    }

    public function test_credential_change_fails_before_mutation_without_database_sessions(): void
    {
        config()->set('session.driver', 'array');
        $admin = $this->user('admin');
        $employee = User::factory()->create([
            'username' => 'unchanged-user',
            'remember_token' => 'unchanged-remember-token',
            'role' => 'viewer',
            'must_change_password' => false,
        ]);
        $this->withoutExceptionHandling();

        try {
            $this->actingAs($admin)
                ->patch(route('employees.update', $employee), $this->employeePayload([
                    'name' => $employee->name,
                    'username' => 'must-not-persist',
                    'email' => $employee->email,
                    'role' => 'viewer',
                    'password' => '',
                    'password_confirmation' => '',
                ]));
            $this->fail('Credential changes should fail before mutation without database sessions.');
        } catch (\LogicException $exception) {
            $this->assertStringContainsString('SESSION_DRIVER=database', $exception->getMessage());
        }

        $employee->refresh();
        $this->assertSame('unchanged-user', $employee->username);
        $this->assertSame('unchanged-remember-token', $employee->remember_token);
    }

    public function test_admin_changing_own_username_must_log_in_again(): void
    {
        $admin = $this->user('admin');

        $this->actingAs($admin)
            ->patch(route('employees.update', $admin), $this->employeePayload([
                'name' => $admin->name,
                'username' => 'changed-admin',
                'email' => $admin->email,
                'role' => 'admin',
                'password' => '',
                'password_confirmation' => '',
            ]))
            ->assertRedirect(route('login'));

        $this->assertGuest();
        $this->assertSame('changed-admin', $admin->fresh()->username);
    }

    public function test_user_without_email_can_open_employee_settings_and_work_board_pages(): void
    {
        $department = Department::create(['department_name' => 'No Email Department']);
        $admin = $this->user('admin');
        $employee = User::factory()->create([
            'name' => 'No Email Employee',
            'username' => 'noemail-user',
            'email' => null,
            'role' => 'user',
            'department_id' => $department->id,
            'must_change_password' => false,
            'is_active' => true,
        ]);

        $this->actingAs($admin)
            ->get(route('employees.index'))
            ->assertOk()
            ->assertSee('@noemail-user');
        $this->actingAs($employee)
            ->get(route('settings.index'))
            ->assertOk()
            ->assertSee('@noemail-user');
        $this->actingAs($employee)
            ->get(route('work-board.department', ['department' => $department, 'search' => 'noemail-user']))
            ->assertOk()
            ->assertSee('No Email Employee')
            ->assertDontSee('@noemail-user');
        $this->actingAs($employee)
            ->get(route('work-board.member', [$department, $employee]))
            ->assertOk()
            ->assertSee('No Email Employee')
            ->assertDontSee('@noemail-user');
    }

    private function employeePayload(array $overrides = []): array
    {
        return array_replace([
            'name' => 'Employee Test',
            'username' => 'employee-test',
            'email' => null,
            'phone' => null,
            'password' => 'SecurePassword!123',
            'password_confirmation' => 'SecurePassword!123',
            'role' => 'admin',
            'is_active' => true,
            'department_id' => null,
        ], $overrides);
    }

    private function user(string $role): User
    {
        return User::factory()->create([
            'role' => $role,
            'must_change_password' => false,
            'is_active' => true,
        ]);
    }
}
