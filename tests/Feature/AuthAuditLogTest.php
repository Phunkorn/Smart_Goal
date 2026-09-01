<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

/**
 * การบันทึกการเข้าออกระบบลงบันทึกตรวจสอบ
 *
 * ก่อนหน้านี้ระบบไม่ได้บันทึกการเข้าออกเลย ทั้งที่หน้าบันทึกมีป้ายเตรียมไว้แล้ว
 * บันทึกนี้ผู้ดูแลระบบทุกคนเปิดอ่านได้ จึงต้องพิสูจน์ด้วยว่ารหัสผ่านไม่เคยรั่วลงไป
 */
class AuthAuditLogTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        RateLimiter::clear('');
    }

    public function test_successful_login_is_recorded_against_the_real_user(): void
    {
        $user = $this->member('audit.actor');

        $this->post(route('login.submit'), [
            'username' => 'audit.actor',
            'password' => 'CorrectHorse#1',
        ])->assertRedirect();

        $log = ActivityLog::where('action', 'login')->sole();

        $this->assertSame($user->id, (int) $log->user_id);
        $this->assertSame(User::class, $log->subject_type);
        $this->assertStringContainsString($user->name, (string) $log->description);
    }

    public function test_failed_login_is_recorded_without_a_user_and_never_stores_the_password(): void
    {
        $this->member('audit.actor');

        $this->post(route('login.submit'), [
            'username' => 'audit.actor',
            'password' => 'WrongPassword#9',
        ])->assertRedirect();

        $log = ActivityLog::where('action', 'login_failed')->sole();

        $this->assertNull($log->user_id);
        $this->assertSame('audit.actor', $log->changes['username'] ?? null);

        // รหัสผ่านห้ามปรากฏในบันทึกไม่ว่าในคีย์ใด
        $encoded = json_encode($log->getAttributes(), JSON_UNESCAPED_UNICODE);
        $this->assertStringNotContainsString('WrongPassword#9', $encoded);
        $this->assertArrayNotHasKey('password', $log->changes ?? []);
    }

    public function test_an_unknown_username_is_still_recorded_so_probing_is_visible(): void
    {
        $this->post(route('login.submit'), [
            'username' => 'ghost.account',
            'password' => 'AnyPassword#1',
        ])->assertRedirect();

        $log = ActivityLog::where('action', 'login_failed')->sole();

        $this->assertNull($log->user_id);
        $this->assertSame('ghost.account', $log->changes['username'] ?? null);
    }

    public function test_lockout_is_recorded_once_when_the_threshold_is_crossed(): void
    {
        $this->member('audit.actor');

        // ห้าครั้งแรกทำให้ถูกล็อก ครั้งที่หกขึ้นไปถูกปฏิเสธก่อนถึงการตรวจรหัสผ่าน
        for ($attempt = 0; $attempt < 8; $attempt++) {
            $this->post(route('login.submit'), [
                'username' => 'audit.actor',
                'password' => 'WrongPassword#9',
            ]);
        }

        $this->assertSame(1, ActivityLog::where('action', 'login_locked')->count());
        $this->assertSame(5, ActivityLog::where('action', 'login_failed')->count());
    }

    public function test_logout_is_recorded_against_the_user_who_left(): void
    {
        $user = $this->member('audit.actor');

        $this->actingAs($user)->post(route('logout'))->assertRedirect(route('login'));

        $log = ActivityLog::where('action', 'logout')->sole();

        // ถ้าบันทึกหลัง Auth::logout() ค่านี้จะกลายเป็น null เงียบ ๆ
        $this->assertSame($user->id, (int) $log->user_id);
    }

    public function test_auth_events_reach_the_audit_log_page(): void
    {
        $admin = User::factory()->create([
            'role' => 'admin',
            'is_active' => true,
            'must_change_password' => false,
        ]);
        $this->member('audit.actor');

        $this->post(route('login.submit'), [
            'username' => 'audit.actor',
            'password' => 'CorrectHorse#1',
        ]);

        $this->actingAs($admin)
            ->get(route('admin.audit.index', ['tab' => 'activity', 'action' => 'login']))
            ->assertOk()
            ->assertSee('เข้าสู่ระบบ');
    }

    private function member(string $username): User
    {
        return User::factory()->create([
            'username' => $username,
            'password' => Hash::make('CorrectHorse#1'),
            'role' => 'user',
            'is_active' => true,
            'must_change_password' => false,
        ]);
    }
}
