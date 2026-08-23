<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

class AuthenticationSecurityTest extends TestCase
{
    use RefreshDatabase;

    public function test_inactive_users_cannot_authenticate(): void
    {
        $user = User::factory()->create([
            'username' => 'inactive-user',
            'is_active' => false,
            'must_change_password' => false,
        ]);

        $this->post(route('login.submit'), [
            'username' => $user->username,
            'password' => 'password',
        ])->assertSessionHasErrors('username');

        $this->assertGuest();
    }

    public function test_username_login_is_case_insensitive_and_regenerates_the_session(): void
    {
        $user = User::factory()->create([
            'username' => 'case.user',
            'role' => 'user',
            'must_change_password' => false,
        ]);
        $this->get(route('login'));
        $oldSessionId = session()->getId();

        $this->post(route('login.submit'), [
            'username' => '  CASE.USER  ',
            'password' => 'password',
        ])->assertRedirect(route('mytasks.index'));

        $this->assertAuthenticatedAs($user);
        $this->assertNotSame($oldSessionId, session()->getId());
    }

    public function test_email_is_not_accepted_as_a_login_credential(): void
    {
        $user = User::factory()->create(['must_change_password' => false]);

        $this->post(route('login.submit'), [
            'email' => $user->email,
            'password' => 'password',
        ])->assertSessionHasErrors('username');

        $this->assertGuest();
    }

    public function test_wrong_username_and_password_share_a_generic_error(): void
    {
        $user = User::factory()->create([
            'username' => 'known-user',
            'must_change_password' => false,
        ]);

        $wrongPassword = $this->post(route('login.submit'), [
            'username' => $user->username,
            'password' => 'wrong-password',
        ])->assertSessionHasErrors('username');
        $unknownUsername = $this->post(route('login.submit'), [
            'username' => 'missing-user',
            'password' => 'wrong-password',
        ])->assertSessionHasErrors('username');

        $this->assertSame(
            $wrongPassword->getSession()->get('errors')->first('username'),
            $unknownUsername->getSession()->get('errors')->first('username')
        );
        $this->assertGuest();
    }

    public function test_soft_deleted_users_cannot_authenticate(): void
    {
        $user = User::factory()->create([
            'username' => 'deleted-user',
            'must_change_password' => false,
        ]);
        $user->delete();

        $this->post(route('login.submit'), [
            'username' => 'DELETED-USER',
            'password' => 'password',
        ])->assertSessionHasErrors('username');

        $this->assertGuest();
    }

    public function test_rate_limit_cannot_be_bypassed_with_username_case_variation(): void
    {
        $user = User::factory()->create([
            'username' => 'rate-user',
            'must_change_password' => false,
        ]);
        $key = 'rate-user|127.0.0.1';
        RateLimiter::clear($key);

        foreach (['RATE-USER', 'Rate-User', 'rate-user', 'RATE-user', 'rate-USER'] as $username) {
            $this->post(route('login.submit'), [
                'username' => $username,
                'password' => 'wrong-password',
            ])->assertSessionHasErrors('username');
        }

        $this->post(route('login.submit'), [
            'username' => $user->username,
            'password' => 'password',
        ])->assertSessionHasErrors('username');

        $this->assertGuest();
        RateLimiter::clear($key);
    }

    public function test_remember_me_and_logout_security_are_preserved(): void
    {
        $user = User::factory()->create([
            'username' => 'remember-user',
            'remember_token' => null,
            'must_change_password' => false,
        ]);

        $login = $this->post(route('login.submit'), [
            'username' => $user->username,
            'password' => 'password',
            'remember' => '1',
        ])->assertRedirect();

        $this->assertAuthenticatedAs($user);
        $this->assertNotNull($user->fresh()->remember_token);
        $login->assertCookie(Auth::guard()->getRecallerName());

        $this->withSession(['_token' => 'known-csrf-token'])
            ->post(route('logout'))
            ->assertRedirect(route('login'));

        $this->assertGuest();
        $this->assertNotSame('known-csrf-token', session()->token());
    }

    public function test_login_with_temporary_password_still_requires_password_setup(): void
    {
        $user = User::factory()->create([
            'username' => 'first-login-user',
            'must_change_password' => true,
        ]);

        $this->post(route('login.submit'), [
            'username' => $user->username,
            'password' => 'password',
        ])->assertRedirect(route('password.setup'));

        $this->assertAuthenticatedAs($user);
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
                'username' => 'new-user',
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
