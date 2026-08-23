<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class SettingsPasswordTest extends TestCase
{
    use RefreshDatabase;

    public function test_authenticated_user_can_change_password_with_the_current_password(): void
    {
        $user = $this->user();
        $oldRememberToken = $user->remember_token;

        $this->actingAs($user)
            ->get(route('settings.index'))
            ->assertOk()
            ->assertSee(route('settings.password.update'), false)
            ->assertSee('current_password', false);

        $oldSessionId = session()->getId();

        $this->patch(route('settings.password.update'), [
            'current_password' => 'password',
            'password' => 'NewSecurePassword!123',
            'password_confirmation' => 'NewSecurePassword!123',
        ])->assertRedirect()
            ->assertSessionHas('success')
            ->assertSessionDoesntHaveErrors();

        $user->refresh();

        $this->assertAuthenticatedAs($user);
        $this->assertTrue(Hash::check('NewSecurePassword!123', $user->password));
        $this->assertNotSame($oldRememberToken, $user->remember_token);
        $this->assertNotSame($oldSessionId, session()->getId());
        $this->assertDatabaseHas('activity_logs', [
            'user_id' => $user->id,
            'action' => 'password_changed',
            'subject_type' => User::class,
            'subject_id' => $user->id,
        ]);

        $this->post(route('logout'));

        $this->post(route('login.submit'), [
            'username' => $user->username,
            'password' => 'password',
        ])->assertSessionHasErrors('username');

        $this->post(route('login.submit'), [
            'username' => $user->username,
            'password' => 'NewSecurePassword!123',
        ])->assertRedirect(route('mytasks.index'));
    }

    public function test_wrong_current_password_does_not_change_the_password(): void
    {
        $user = $this->user();
        $originalHash = $user->password;

        $this->actingAs($user)
            ->patch(route('settings.password.update'), [
                'current_password' => 'wrong-password',
                'password' => 'NewSecurePassword!123',
                'password_confirmation' => 'NewSecurePassword!123',
            ])->assertSessionHasErrors('current_password');

        $this->assertSame($originalHash, $user->fresh()->password);
    }

    public function test_password_confirmation_and_shared_policy_are_enforced(): void
    {
        $user = $this->user();
        $originalHash = $user->password;

        $this->actingAs($user)
            ->patch(route('settings.password.update'), [
                'current_password' => 'password',
                'password' => 'NewSecurePassword!123',
                'password_confirmation' => 'DifferentPassword!123',
            ])->assertSessionHasErrors('password');

        $this->actingAs($user)
            ->patch(route('settings.password.update'), [
                'current_password' => 'password',
                'password' => 'weak-password',
                'password_confirmation' => 'weak-password',
            ])->assertSessionHasErrors('password');

        $this->assertSame($originalHash, $user->fresh()->password);
    }

    public function test_password_change_revokes_other_database_sessions_and_keeps_current_login(): void
    {
        config()->set('session.driver', 'database');
        $user = $this->user();

        DB::table('sessions')->insert([
            'id' => 'other-device-session',
            'user_id' => $user->id,
            'ip_address' => '127.0.0.1',
            'user_agent' => 'other device',
            'payload' => 'payload',
            'last_activity' => now()->timestamp,
        ]);

        $this->actingAs($user)
            ->patch(route('settings.password.update'), [
                'current_password' => 'password',
                'password' => 'NewSecurePassword!123',
                'password_confirmation' => 'NewSecurePassword!123',
            ])->assertSessionDoesntHaveErrors();

        $this->assertAuthenticatedAs($user);
        $this->assertDatabaseMissing('sessions', ['id' => 'other-device-session']);
    }

    public function test_first_login_user_cannot_bypass_password_setup_through_settings(): void
    {
        $user = User::factory()->create([
            'must_change_password' => true,
            'is_active' => true,
        ]);

        $this->actingAs($user)
            ->patch(route('settings.password.update'), [
                'current_password' => 'password',
                'password' => 'NewSecurePassword!123',
                'password_confirmation' => 'NewSecurePassword!123',
            ])->assertRedirect(route('password.setup'));

        $this->assertTrue(Hash::check('password', $user->fresh()->password));
        $this->assertTrue($user->fresh()->must_change_password);
    }

    public function test_inactive_user_cannot_change_password_through_an_existing_session(): void
    {
        $user = $this->user();
        $originalHash = $user->password;
        $user->update(['is_active' => false]);

        $this->actingAs($user)
            ->patch(route('settings.password.update'), [
                'current_password' => 'password',
                'password' => 'NewSecurePassword!123',
                'password_confirmation' => 'NewSecurePassword!123',
            ])->assertRedirect(route('login'));

        $this->assertGuest();
        $this->assertSame($originalHash, $user->fresh()->password);
    }

    private function user(): User
    {
        return User::factory()->create([
            'role' => 'user',
            'password' => Hash::make('password'),
            'remember_token' => 'remember-token-before-change',
            'must_change_password' => false,
            'is_active' => true,
        ]);
    }
}
