<?php

namespace Tests\Feature;

use App\Models\User;
use App\Support\RoleLabel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * หน้าตั้งค่าบัญชีถูกจัดวางใหม่เป็นแถบสรุปด้านบนและสองคอลัมน์ด้านล่าง
 * การทดสอบนี้ล็อกว่าฟอร์มและปุ่มเดิมยังส่งไปที่เดิมด้วยชื่อ field เดิม
 * และการ์ดแต่ละใบอยู่ในส่วนที่ถูกต้องของเลย์เอาต์ใหม่
 */
class SettingsPageLayoutTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('session.driver', 'database');
    }

    public function test_profile_form_keeps_its_action_method_and_field_names(): void
    {
        foreach (['user', 'admin'] as $role) {
            $html = $this->actingAs($this->user($role))->get(route('settings.index'))->assertOk()->getContent();

            $form = $this->between($html, 'class="settings-card settings-details"', 'class="settings-page__side"');
            $this->assertStringContainsString('action="'.route('settings.update').'"', $form, $role);
            $this->assertStringContainsString('enctype="multipart/form-data"', $form, $role);
            $this->assertStringContainsString('name="_method" value="PATCH"', $form, $role);
            $this->assertStringContainsString('name="name"', $form, $role);
            $this->assertStringContainsString('name="phone"', $form, $role);
            $this->assertMatchesRegularExpression('/<input[^>]*type="file"[^>]*name="profile_image"[^>]*accept="image\/png,image\/jpeg,image\/webp"/s', $form, $role);
            $this->assertStringContainsString('type="submit"', $form, $role);
        }
    }

    public function test_summary_bar_shows_identity_role_and_department_above_the_columns(): void
    {
        $user = $this->user('user', ['name' => 'สมชาย ทดสอบ', 'email' => 'somchai@example.com']);

        $html = $this->actingAs($user)->get(route('settings.index'))->assertOk()->getContent();
        $summary = $this->between($html, 'class="settings-card settings-summary"', 'class="settings-page__layout"');

        $this->assertStringContainsString('สมชาย ทดสอบ', $summary);
        $this->assertStringContainsString('somchai@example.com', $summary);
        $this->assertStringContainsString('สิทธิ์การใช้งาน', $summary);
        $this->assertStringContainsString(RoleLabel::for($user), $summary);
        $this->assertStringContainsString('แผนก', $summary);
        $this->assertStringContainsString('การเปลี่ยนสิทธิ์และแผนกต้องดำเนินการโดย Admin', $summary);
    }

    public function test_password_and_telegram_cards_share_the_side_column(): void
    {
        $html = $this->actingAs($this->user('user'))->get(route('settings.index'))->assertOk()->getContent();
        $side = $this->between($html, 'class="settings-page__side"', 'data-password-modal');

        $this->assertStringContainsString('data-bs-target="#settingsPasswordModal"', $side);
        $this->assertStringContainsString('data-telegram-settings', $side);
    }

    public function test_viewer_side_column_has_only_the_password_card(): void
    {
        $html = $this->actingAs($this->user('viewer'))->get(route('settings.index'))->assertOk()->getContent();
        $side = $this->between($html, 'class="settings-page__side"', 'data-password-modal');

        $this->assertStringContainsString('data-bs-target="#settingsPasswordModal"', $side);
        $this->assertStringNotContainsString('data-telegram-settings', $html);
    }

    public function test_profile_image_error_is_shown_under_the_upload_box(): void
    {
        $html = $this->actingAs($this->user('user'))
            ->from(route('settings.index'))
            ->followingRedirects()
            ->patch(route('settings.update'), [
                'name' => 'ชื่อทดสอบ',
                'profile_image' => UploadedFile::fake()->create('not-an-image.pdf', 10, 'application/pdf'),
            ])
            ->assertOk()
            ->getContent();

        $this->assertMatchesRegularExpression('/class="settings-upload\s+is-invalid\s*"/', $html);
        $this->assertMatchesRegularExpression('/<div class="invalid-feedback d-block">[^<]+<\/div>\s*<p id="settingsProfileImageHelp"/', $html);
    }

    private function between(string $html, string $start, string $end): string
    {
        $from = strpos($html, $start);
        $this->assertNotFalse($from, "ไม่พบ {$start}");
        $to = strpos($html, $end, $from);
        $this->assertNotFalse($to, "ไม่พบ {$end} หลัง {$start}");

        return substr($html, $from, $to - $from);
    }

    private function user(string $role, array $attributes = []): User
    {
        return User::factory()->create(array_merge([
            'role' => $role,
            'password' => Hash::make('password'),
            'must_change_password' => false,
            'is_active' => true,
        ], $attributes));
    }
}
