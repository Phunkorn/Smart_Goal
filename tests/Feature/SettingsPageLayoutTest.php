<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class SettingsPageLayoutTest extends TestCase
{
    use RefreshDatabase;

    public function test_account_modals_are_available_on_regular_pages_for_every_role(): void
    {
        foreach (['admin', 'user', 'viewer'] as $role) {
            $html = $this->actingAs($this->user($role))->get(route('settings.index'))->assertOk()->getContent();
            foreach (['settingsProfileModal', 'settingsPasswordModal', 'settingsModal', 'accountLogoutModal'] as $id) {
                $this->assertSame(1, substr_count($html, 'id="'.$id.'"'));
                $this->assertStringContainsString('data-account-open="'.$id.'"', $html);
            }
            $this->assertStringNotContainsString('sidebar-foot', $html);
            $this->assertStringNotContainsString('href="'.route('settings.index').'"', $html);
            $this->assertStringContainsString('method="POST"', $this->modal($html, 'accountLogoutModal'));
            $this->assertStringContainsString('action="'.route('logout').'"', $this->modal($html, 'accountLogoutModal'));
            $this->assertSame($role !== 'viewer', str_contains($html, 'data-telegram-settings'));
        }
        $html = $this->actingAs($this->user('user'))->get(route('mytasks.index'))->assertOk()->getContent();
        $this->assertStringContainsString('id="settingsProfileModal"', $html);
        $this->assertStringNotContainsString('data-open-on-load="true"', $this->modal($html, 'settingsModal'));
    }

    /**
     * เมนูบัญชีข้อที่เปิด settingsModal ชื่อ "การเชื่อม Telegram" และ modal นั้นมีเฉพาะการ์ด Telegram
     * โปรไฟล์กับรหัสผ่านมี modal ของตัวเองและเปิดจากเมนูบัญชีโดยตรง ไม่ซ้ำซ้อนอยู่ในนี้
     */
    public function test_the_telegram_modal_contains_only_the_telegram_card_and_the_menu_says_so(): void
    {
        $html = $this->actingAs($this->user('user'))->get(route('settings.index'))->assertOk()->getContent();
        $modal = $this->modal($html, 'settingsModal');

        $this->assertStringContainsString('การเชื่อม Telegram', $modal);
        $this->assertStringContainsString('settings-card__header settings-telegram__header', $modal);
        $this->assertSame(1, substr_count($modal, 'data-telegram-settings'));
        foreach (['accountSettingsIdentity', 'password-settings-title', 'ความปลอดภัย', 'แก้ไขโปรไฟล์', 'เปลี่ยนรหัสผ่าน', 'การเปลี่ยนสิทธิ์และแผนกต้องดำเนินการโดย Admin'] as $removed) {
            $this->assertStringNotContainsString($removed, $modal, $removed);
        }
        $this->assertDoesNotMatchRegularExpression('/data-account-open="settings(Profile|Password)Modal"/', $modal);

        // ปุ่มในเมนูบัญชีเปลี่ยนชื่อและไอคอน ส่วนโปรไฟล์/รหัสผ่านยังเปิด modal ของตัวเองจากเมนูเดิม
        $this->assertMatchesRegularExpression('/data-account-open="settingsModal"><i class="bi bi-telegram" aria-hidden="true"><\/i>การเชื่อม Telegram<\/button>/', $html);
        $this->assertStringNotContainsString('>ตั้งค่าระบบ</button>', $html);
        $this->assertMatchesRegularExpression('/data-account-open="settingsProfileModal"><i class="bi bi-person" aria-hidden="true"><\/i>แก้ไขโปรไฟล์<\/button>/', $html);
        $this->assertMatchesRegularExpression('/data-account-open="settingsPasswordModal"[^>]*><i class="bi bi-key" aria-hidden="true"><\/i>เปลี่ยนรหัสผ่าน<\/button>/', $html);
        $this->assertStringContainsString('เปิดการเชื่อม Telegram</button>', $html);
    }

    public function test_a_viewer_opening_the_telegram_modal_gets_an_explanation_not_an_empty_box(): void
    {
        $html = $this->actingAs($this->user('viewer'))->get(route('settings.index'))->assertOk()->getContent();
        $modal = $this->modal($html, 'settingsModal');

        $this->assertStringNotContainsString('data-telegram-settings', $modal);
        $this->assertStringContainsString('บัญชีสิทธิ์อ่านอย่างเดียวไม่รับการแจ้งเตือนผ่าน Telegram', $modal);
    }

    public function test_profile_modal_keeps_the_existing_editable_fields_and_endpoint(): void
    {
        $user = $this->user('user', ['name' => 'สมหญิง ทดสอบ']);
        $html = $this->actingAs($user)->get(route('settings.index'))->assertOk()->getContent();
        $form = $this->modal($html, 'settingsProfileModal');
        $this->assertStringContainsString('action="'.route('settings.update').'"', $form);
        $this->assertStringContainsString('enctype="multipart/form-data"', $form);
        foreach (['name', 'phone', 'profile_image'] as $field) {
            $this->assertStringContainsString('name="'.$field.'"', $form);
        }
        $this->assertStringContainsString('name="_method" value="PATCH"', $form);
        $this->assertStringContainsString('value="สมหญิง ทดสอบ"', $form);
        $this->assertStringNotContainsString('name="role"', $form);
        $this->assertStringNotContainsString('name="email"', $form);
        $this->assertStringContainsString('data-open-on-load="true"', $this->modal($html, 'settingsModal'));
    }

    public function test_profile_saves_as_json_without_allowing_privilege_changes(): void
    {
        Storage::fake('public');
        $user = $this->user('user');
        $response = $this->actingAs($user)->patchJson(route('settings.update'), [
            'name' => 'ชื่อใหม่', 'phone' => '0123456789', 'role' => 'admin',
            'is_department_head' => true, 'email' => 'changed@example.com',
            'profile_image' => UploadedFile::fake()->image('avatar.png'),
        ])->assertOk()->assertJsonPath('user.name', 'ชื่อใหม่')->assertJsonPath('user.initials', 'ชื');
        $this->assertStringContainsString(route('media.profile', $user), $response->json('user.avatar_url'));
        $user->refresh();
        $this->assertSame('user', $user->role);
        $this->assertFalse($user->is_department_head);
        $this->assertNotSame('changed@example.com', $user->email);
        Storage::disk('public')->assertExists($user->profile_image);
    }

    public function test_json_validation_preserves_profile_data(): void
    {
        $user = $this->user('user');
        $this->actingAs($user)->patchJson(route('settings.update'), [
            'name' => '', 'profile_image' => UploadedFile::fake()->create('wrong.pdf', 10, 'application/pdf'),
        ])->assertUnprocessable()->assertJsonValidationErrors(['name', 'profile_image']);
        $this->assertSame($user->name, $user->fresh()->name);
    }

    public function test_legacy_profile_validation_reopens_only_the_profile_modal(): void
    {
        $this->actingAs($this->user('user'))->from(route('settings.index'))
            ->patch(route('settings.update'), ['name' => ''])
            ->assertRedirect(route('settings.index'))->assertSessionHasErrors('name');
        $html = $this->get(route('settings.index'))->assertOk()->getContent();
        $this->assertStringContainsString('data-open-on-load="true"', $this->modal($html, 'settingsProfileModal'));
        $this->assertStringNotContainsString('data-open-on-load="true"', $this->modal($html, 'settingsModal'));
    }

    public function test_settings_fragment_preserves_telegram_permissions(): void
    {
        $this->actingAs($this->user('user'))->getJson(route('settings.index'))->assertOk()
            ->assertJsonStructure(['telegram_html']);
        $this->actingAs($this->user('viewer'))->getJson(route('settings.index'))->assertOk()
            ->assertJsonPath('telegram_html', '');
        foreach (['settings.telegram.link', 'settings.telegram.test'] as $route) {
            $this->postJson(route($route))->assertForbidden();
        }
        $this->patchJson(route('settings.telegram.update'), ['telegram_notifications_enabled' => true])->assertForbidden();
        $this->deleteJson(route('settings.telegram.destroy'))->assertForbidden();
    }

    public function test_validation_from_another_feature_cannot_open_or_populate_account_dialogs(): void
    {
        $user = $this->user('user', ['name' => 'My account']);
        $html = $this->actingAs($user)->withSession([
            '_old_input' => ['name' => 'An employee being edited'],
            'errors' => (new \Illuminate\Support\ViewErrorBag)->put('default', new \Illuminate\Support\MessageBag(['name' => ['Required']])),
        ])->get(route('mytasks.index'))->assertOk()->getContent();
        $profile = $this->modal($html, 'settingsProfileModal');
        $this->assertStringNotContainsString('data-open-on-load="true"', $profile);
        $this->assertStringNotContainsString('An employee being edited', $profile);
        $this->assertStringContainsString('value="My account"', $profile);
    }

    private function modal(string $html, string $id): string
    {
        $dom = new \DOMDocument;
        @$dom->loadHTML('<?xml encoding="utf-8" ?>'.$html);
        $node = (new \DOMXPath($dom))->query('//*[@id="'.$id.'"]')->item(0);
        $this->assertNotNull($node);

        return $dom->saveHTML($node);
    }

    private function user(string $role, array $attributes = []): User
    {
        return User::factory()->create(array_merge([
            'role' => $role, 'must_change_password' => false, 'is_active' => true,
        ], $attributes));
    }
}
