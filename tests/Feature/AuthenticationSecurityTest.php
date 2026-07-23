<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuthenticationSecurityTest extends TestCase
{
    use RefreshDatabase;

    public function test_inactive_users_cannot_authenticate(): void
    {
        $user = User::factory()->create([
            'email' => 'inactive@example.test',
            'is_active' => false,
            'must_change_password' => false,
        ]);

        $this->post(route('login.submit'), [
            'email' => $user->email,
            'password' => 'password',
        ])->assertSessionHasErrors('email');

        $this->assertGuest();
    }

    public function test_first_login_is_limited_to_password_setup(): void
    {
        $user = User::factory()->create(['must_change_password' => true]);

        $this->actingAs($user)
            ->get(route('board.index'))
            ->assertRedirect(route('password.setup'));
    }

    public function test_user_with_a_configured_password_cannot_revisit_password_setup(): void
    {
        $user = User::factory()->create([
            'role' => 'user',
            'must_change_password' => false,
        ]);
        $originalPassword = $user->password;

        $this->actingAs($user)
            ->get(route('password.setup'))
            ->assertRedirect(route('mytasks.index'));

        $this->actingAs($user)
            ->post(route('password.update.first'), [
                'password' => 'AnotherSecure!123',
                'password_confirmation' => 'AnotherSecure!123',
            ])
            ->assertRedirect(route('mytasks.index'));

        $this->assertSame($originalPassword, $user->fresh()->password);
    }

    public function test_first_password_must_follow_the_shared_policy(): void
    {
        $user = User::factory()->create(['must_change_password' => true]);

        $this->actingAs($user)
            ->post(route('password.update.first'), [
                'password' => 'short',
                'password_confirmation' => 'short',
            ])
            ->assertSessionHasErrors('password');

        $this->actingAs($user)
            ->post(route('password.update.first'), [
                'password' => 'SecurePassword!123',
                'password_confirmation' => 'SecurePassword!123',
            ])
            ->assertRedirect(route('welcome'));

        $this->assertFalse($user->fresh()->must_change_password);
    }

    public function test_non_admin_cannot_manage_employees(): void
    {
        $user = User::factory()->create(['role' => 'user', 'must_change_password' => false]);

        $this->actingAs($user)
            ->post(route('employees.store'), [
                'name' => 'New User',
                'email' => 'new@example.test',
                'password' => 'SecurePassword!123',
                'password_confirmation' => 'SecurePassword!123',
                'role' => 'user',
                'is_active' => true,
            ])
            ->assertForbidden();
    }

    public function test_admin_password_reset_uses_the_shared_policy(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'must_change_password' => false]);
        $employee = User::factory()->create(['must_change_password' => false]);

        $this->actingAs($admin)
            ->patch(route('employees.resetPassword', $employee), [
                'password' => 'weak',
            ])
            ->assertSessionHasErrors('password');

        $this->actingAs($admin)
            ->patch(route('employees.resetPassword', $employee), [
                'password' => 'SecurePassword!123',
            ])
            ->assertRedirect(route('employees.index'));

        $this->assertTrue($employee->fresh()->must_change_password);
    }

}
