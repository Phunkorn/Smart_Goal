<?php

namespace Tests\Feature;

use App\Models\User;
use App\Support\PasswordPolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

class AuthenticationSecurityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('session.driver', 'database');
    }

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

    public function test_first_password_change_revokes_real_sessions_and_remember_cookie_but_preserves_current_session(): void
    {
        config()->set('session.driver', 'database');
        $temporaryPassword = 'TemporaryPassword!123';
        $user = User::factory()->create([
            'password' => Hash::make($temporaryPassword),
            'remember_token' => null,
            'must_change_password' => true,
            'is_active' => true,
        ]);
        $currentDevice = $this->loginAsNewBrowser($user, $temporaryPassword);
        $otherDevice = $this->loginAsNewBrowser($user, $temporaryPassword);
        $rememberedDevice = $this->loginAsNewBrowser($user, $temporaryPassword, true);
        $this->assertNotSame($currentDevice['session'], $otherDevice['session']);
        $this->assertNotSame($currentDevice['session'], $rememberedDevice['session']);

        $this->useBrowserCookies([
            config('session.cookie') => $otherDevice['session'],
        ])->get(route('password.setup'))->assertOk();
        $this->useBrowserCookies([
            Auth::guard()->getRecallerName() => $rememberedDevice['remember'],
        ])->get(route('password.setup'))->assertOk();

        $changePassword = $this->useBrowserCookies([
            config('session.cookie') => $currentDevice['session'],
        ])->post(route('password.update.first'), [
            'password' => 'PermanentPassword!456',
            'password_confirmation' => 'PermanentPassword!456',
        ]);
        $changePassword->assertRedirect(route('welcome'));
        $currentSessionCookie = $changePassword->getCookie(config('session.cookie'));

        $this->assertNotNull($currentSessionCookie);
        $this->assertNotSame($currentDevice['session'], $currentSessionCookie->getValue());
        $this->assertDatabaseMissing('sessions', ['id' => $otherDevice['session']]);
        $this->useBrowserCookies([
            config('session.cookie') => $currentSessionCookie->getValue(),
        ])->get(route('welcome'))->assertOk();

        $revokedSessionResponse = $this->useBrowserCookies([
            config('session.cookie') => $otherDevice['session'],
        ])->get(route('mytasks.index'));
        $this->assertArrayNotHasKey(Auth::guard()->getRecallerName(), request()->cookies->all());
        $this->assertNull(request()->session()->get(Auth::guard()->getName()));
        $this->assertGuest();
        $revokedSessionResponse->assertRedirect(route('login'));
        $this->useBrowserCookies([
            Auth::guard()->getRecallerName() => $rememberedDevice['remember'],
        ])->get(route('mytasks.index'))->assertRedirect(route('login'));

        $user->refresh();
        $this->assertFalse($user->must_change_password);
        $this->assertFalse(Hash::check($temporaryPassword, $user->password));
        $this->assertTrue(Hash::check('PermanentPassword!456', $user->password));
    }

    public function test_first_password_change_fails_closed_without_database_sessions(): void
    {
        config()->set('session.driver', 'array');
        $user = User::factory()->create([
            'password' => Hash::make('TemporaryPassword!123'),
            'must_change_password' => true,
            'is_active' => true,
        ]);

        $this->withoutExceptionHandling();

        try {
            $this->actingAs($user)->post(route('password.update.first'), [
                'password' => 'PermanentPassword!456',
                'password_confirmation' => 'PermanentPassword!456',
            ]);
            $this->fail('Password setup should fail without database-backed session revocation.');
        } catch (\LogicException $exception) {
            $this->assertStringContainsString('SESSION_DRIVER=database', $exception->getMessage());
        }

        $user->refresh();
        $this->assertTrue($user->must_change_password);
        $this->assertTrue(Hash::check('TemporaryPassword!123', $user->password));
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

    public function test_first_password_shorter_than_the_minimum_is_rejected(): void
    {
        $this->assertFirstPasswordViolatesPolicy('Sh0rt!Aa');
    }

    public function test_first_password_without_a_lowercase_letter_is_rejected(): void
    {
        $this->assertFirstPasswordViolatesPolicy('UPPERCASE!123');
    }

    public function test_first_password_without_an_uppercase_letter_is_rejected(): void
    {
        $this->assertFirstPasswordViolatesPolicy('lowercase!123');
    }

    public function test_first_password_without_a_number_is_rejected(): void
    {
        $this->assertFirstPasswordViolatesPolicy('NoDigitsHere!');
    }

    public function test_first_password_without_a_symbol_is_rejected(): void
    {
        $this->assertFirstPasswordViolatesPolicy('NoSymbols12345');
    }

    public function test_first_password_is_rejected_when_the_confirmation_does_not_match(): void
    {
        $user = $this->userAwaitingFirstPassword();

        $this->actingAs($user)
            ->post(route('password.update.first'), [
                'password' => 'ValidPassword!123',
                'password_confirmation' => 'DifferentPassword!456',
            ])
            ->assertSessionHasErrors(['password' => 'รหัสผ่านทั้งสองช่องไม่ตรงกัน'])
            ->assertSessionDoesntHaveErrors('password_confirmation');

        $user->refresh();
        $this->assertTrue($user->must_change_password);
        $this->assertTrue(Hash::check('TemporaryPassword!123', $user->password));
    }

    public function test_first_password_matching_every_policy_rule_is_accepted(): void
    {
        $user = $this->userAwaitingFirstPassword();

        $this->actingAs($user)
            ->post(route('password.update.first'), [
                'password' => 'PolicyPerfect!2026',
                'password_confirmation' => 'PolicyPerfect!2026',
            ])
            ->assertRedirect(route('welcome'))
            ->assertSessionHasNoErrors();

        $user->refresh();
        $this->assertFalse($user->must_change_password);
        $this->assertTrue(Hash::check('PolicyPerfect!2026', $user->password));
    }

    public function test_password_policy_failures_report_the_shared_thai_requirement(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'must_change_password' => false]);
        $employee = User::factory()->create(['must_change_password' => false]);
        $staff = User::factory()->create(['role' => 'user', 'must_change_password' => false]);

        $this->actingAs($admin)
            ->patch(route('employees.resetPassword', $employee), ['password' => 'weak'])
            ->assertSessionHasErrors(['password' => PasswordPolicy::description()]);

        $this->actingAs($staff)
            ->patch(route('settings.password.update'), [
                'current_password' => 'password',
                'password' => 'weak',
                'password_confirmation' => 'weak',
            ])
            ->assertSessionHasErrors(['password' => PasswordPolicy::description()]);
    }

    public function test_login_is_throttled_after_five_failed_attempts(): void
    {
        $user = User::factory()->create([
            'username' => 'throttle-user',
            'role' => 'user',
            'must_change_password' => false,
        ]);
        $key = 'throttle-user|127.0.0.1';
        RateLimiter::clear($key);

        foreach (range(1, 5) as $ignored) {
            $this->post(route('login.submit'), [
                'username' => $user->username,
                'password' => 'wrong-password',
            ])->assertSessionHasErrors('username');
        }

        $this->assertSame(5, RateLimiter::attempts($key));

        $throttled = $this->post(route('login.submit'), [
            'username' => $user->username,
            'password' => 'password',
        ])->assertSessionHasErrors('username');

        $this->assertGuest();
        $this->assertStringContainsString(
            'พยายามเข้าสู่ระบบหลายครั้งเกินไป',
            $throttled->getSession()->get('errors')->first('username')
        );
        $this->assertGreaterThan(0, RateLimiter::availableIn($key));
        $this->assertLessThanOrEqual(60, RateLimiter::availableIn($key));

        RateLimiter::clear($key);
    }

    public function test_login_rate_limit_shares_one_bucket_across_username_casing(): void
    {
        $user = User::factory()->create([
            'username' => 'mixed-case-user',
            'role' => 'user',
            'must_change_password' => false,
        ]);
        $key = 'mixed-case-user|127.0.0.1';
        RateLimiter::clear($key);

        foreach (['MIXED-CASE-USER', '  Mixed-Case-User  ', 'mixed-CASE-user', 'MiXeD-cAsE-uSeR', 'mixed-case-USER'] as $variation) {
            $this->post(route('login.submit'), [
                'username' => $variation,
                'password' => 'wrong-password',
            ])->assertSessionHasErrors('username');
        }

        $this->assertSame(5, RateLimiter::attempts($key));

        $throttled = $this->post(route('login.submit'), [
            'username' => 'MIXED-CASE-USER',
            'password' => 'password',
        ])->assertSessionHasErrors('username');

        $this->assertGuest();
        $this->assertStringContainsString(
            'พยายามเข้าสู่ระบบหลายครั้งเกินไป',
            $throttled->getSession()->get('errors')->first('username')
        );
        $this->assertSame($user->username, 'mixed-case-user');

        RateLimiter::clear($key);
    }

    public function test_successful_login_clears_the_rate_limiter(): void
    {
        $user = User::factory()->create([
            'username' => 'clearing-user',
            'role' => 'user',
            'must_change_password' => false,
        ]);
        $key = 'clearing-user|127.0.0.1';
        RateLimiter::clear($key);

        foreach (range(1, 4) as $ignored) {
            $this->post(route('login.submit'), [
                'username' => 'CLEARING-USER',
                'password' => 'wrong-password',
            ])->assertSessionHasErrors('username');
        }

        $this->assertSame(4, RateLimiter::attempts($key));

        $this->post(route('login.submit'), [
            'username' => 'clearing-user',
            'password' => 'password',
        ])->assertRedirect(route('mytasks.index'));

        $this->assertAuthenticatedAs($user);
        $this->assertSame(0, RateLimiter::attempts($key));

        $this->post(route('logout'))->assertRedirect(route('login'));

        // The bucket is empty again, so the next failure returns credentials wording, not throttling.
        $afterReset = $this->post(route('login.submit'), [
            'username' => 'clearing-user',
            'password' => 'wrong-password',
        ])->assertSessionHasErrors('username');

        $this->assertStringNotContainsString(
            'พยายามเข้าสู่ระบบหลายครั้งเกินไป',
            $afterReset->getSession()->get('errors')->first('username')
        );

        RateLimiter::clear($key);
    }

    private function userAwaitingFirstPassword(): User
    {
        return User::factory()->create([
            'password' => Hash::make('TemporaryPassword!123'),
            'must_change_password' => true,
            'is_active' => true,
        ]);
    }

    private function assertFirstPasswordViolatesPolicy(string $password): void
    {
        $user = $this->userAwaitingFirstPassword();

        $this->actingAs($user)
            ->post(route('password.update.first'), [
                'password' => $password,
                'password_confirmation' => $password,
            ])
            ->assertSessionHasErrors(['password' => PasswordPolicy::description()]);

        $user->refresh();
        $this->assertTrue($user->must_change_password);
        $this->assertTrue(Hash::check('TemporaryPassword!123', $user->password));
    }

    /** @return array{session: string, remember: ?string} */
    private function loginAsNewBrowser(User $user, string $password, bool $remember = false): array
    {
        $response = $this->useBrowserCookies([])->post(route('login.submit'), [
            'username' => $user->username,
            'password' => $password,
            'remember' => $remember ? '1' : '0',
        ]);
        $response->assertRedirect(route('password.setup'));
        $sessionCookie = $response->getCookie(config('session.cookie'));
        $rememberCookie = $response->getCookie(Auth::guard()->getRecallerName());

        $this->assertNotNull($sessionCookie);

        if ($remember) {
            $this->assertNotNull($rememberCookie);
        }

        return [
            'session' => $sessionCookie->getValue(),
            'remember' => $rememberCookie?->getValue(),
        ];
    }

    private function useBrowserCookies(array $cookies): static
    {
        Auth::forgetGuards();
        $this->app['session']->forgetDrivers();
        $this->app->forgetInstance('session.store');
        $this->defaultCookies = [];
        $this->unencryptedCookies = [];

        return $this->withCookies($cookies);
    }
}
