<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\TelegramLinkToken;
use App\Models\TelegramMessage;
use App\Models\User;
use App\Services\Telegram\TelegramLinkService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class TelegramLinkingTest extends TestCase
{
    use RefreshDatabase;

    private const SECRET = 'secret-token-for-tests';

    private mixed $telegramResponse;

    protected function setUp(): void
    {
        parent::setUp();

        $this->telegramResponse = Http::response(['ok' => true, 'result' => []]);

        config([
            'services.telegram.enabled' => true,
            'services.telegram.bot_token' => '123456:TEST-TOKEN',
            'services.telegram.bot_username' => 'SmartGoalTestBot',
            'services.telegram.webhook_secret' => self::SECRET,
        ]);

        // ตอบผ่าน closure ไม่ใช่ค่าคงที่ เพราะ Http::fake() ที่เรียกทีหลังจะถูก stub
        // ตัวแรกบังโดยสมบูรณ์ การทดสอบเคสล้มเหลวจึงต้องสลับคำตอบผ่านตัวแปรนี้แทน
        Http::fake(fn () => $this->telegramResponse);
    }

    private function failTelegramWith(string $description, int $status = 400): void
    {
        $this->telegramResponse = Http::response(
            ['ok' => false, 'error_code' => $status, 'description' => $description],
            $status
        );
    }

    public function test_settings_page_shows_the_telegram_card_for_working_roles_but_not_for_viewer(): void
    {
        foreach (['user', 'admin'] as $role) {
            $this->actingAs($this->user($role))->get(route('settings.index'))->assertOk()
                ->assertSee('data-telegram-settings', false)
                ->assertSee('data-telegram-connect', false);
        }

        $this->actingAs($this->user('viewer'))->get(route('settings.index'))->assertOk()
            ->assertDontSee('data-telegram-settings', false);
    }

    public function test_user_can_request_a_deep_link_but_viewer_cannot(): void
    {
        $user = $this->user();

        $response = $this->actingAs($user)->postJson(route('settings.telegram.link'))->assertOk();

        $token = TelegramLinkToken::query()->where('user_id', $user->id)->firstOrFail();
        $response->assertJson([
            'ok' => true,
            'deep_link' => 'https://t.me/SmartGoalTestBot?start='.$token->token,
        ]);
        $this->assertTrue($token->isUsable());

        $this->actingAs($this->user('viewer'))->postJson(route('settings.telegram.link'))->assertForbidden();
    }

    public function test_requesting_a_new_token_invalidates_the_previous_one(): void
    {
        $user = $this->user();
        $links = app(TelegramLinkService::class);

        $first = $links->issueToken($user);
        $second = $links->issueToken($user);

        $this->assertNull(TelegramLinkToken::query()->find($first->id));
        $this->assertNotNull(TelegramLinkToken::query()->find($second->id));
    }

    public function test_webhook_rejects_requests_without_the_shared_secret(): void
    {
        $this->postJson(route('telegram.webhook'), $this->update(1, '/start'))->assertForbidden();

        $this->withHeader('X-Telegram-Bot-Api-Secret-Token', 'wrong-secret')
            ->postJson(route('telegram.webhook'), $this->update(1, '/start'))
            ->assertForbidden();
    }

    public function test_start_with_a_valid_token_links_the_account_and_writes_an_audit_entry(): void
    {
        $user = $this->user();
        $token = app(TelegramLinkService::class)->issueToken($user);

        $this->webhook($this->update(555001, '/start '.$token->token, 'somchai'))->assertOk();

        $user->refresh();
        $this->assertSame(555001, (int) $user->telegram_chat_id);
        $this->assertSame('somchai', $user->telegram_username);
        $this->assertTrue($user->receivesTelegramNotifications());
        $this->assertNotNull($token->refresh()->consumed_at);
        $this->assertTrue(ActivityLog::query()->where('action', 'telegram_linked')->exists());
    }

    public function test_a_token_cannot_be_reused_or_used_after_it_expires(): void
    {
        $links = app(TelegramLinkService::class);

        $used = $links->issueToken($this->user());
        $this->webhook($this->update(555002, '/start '.$used->token))->assertOk();
        $this->webhook($this->update(555003, '/start '.$used->token))->assertOk();
        $this->assertNull(User::query()->where('telegram_chat_id', 555003)->first());

        $expired = $links->issueToken($this->user());
        $expired->forceFill(['expires_at' => now()->subMinute()])->save();
        $this->webhook($this->update(555004, '/start '.$expired->token))->assertOk();
        $this->assertNull(User::query()->where('telegram_chat_id', 555004)->first());
    }

    public function test_a_chat_already_linked_to_another_account_is_refused(): void
    {
        $owner = $this->linked(555010);
        $other = $this->user();
        $token = app(TelegramLinkService::class)->issueToken($other);

        $this->webhook($this->update(555010, '/start '.$token->token))->assertOk();

        $this->assertNull($other->refresh()->telegram_chat_id);
        $this->assertSame(555010, (int) $owner->refresh()->telegram_chat_id);
        $this->assertNull($token->refresh()->consumed_at);
    }

    public function test_stop_and_start_toggle_delivery_from_inside_the_chat(): void
    {
        $user = $this->linked(555020);

        $this->webhook($this->update(555020, '/stop'))->assertOk();
        $this->assertFalse((bool) $user->refresh()->telegram_notifications_enabled);

        $this->webhook($this->update(555020, '/start'))->assertOk();
        $this->assertTrue((bool) $user->refresh()->telegram_notifications_enabled);
    }

    public function test_group_chats_are_ignored_because_delivery_is_per_person(): void
    {
        $token = app(TelegramLinkService::class)->issueToken($this->user());

        $payload = $this->update(-100200300, '/start '.$token->token);
        $payload['message']['chat']['type'] = 'group';

        $this->webhook($payload)->assertOk();

        $this->assertNull($token->refresh()->consumed_at);
        $this->assertNull(User::query()->where('telegram_chat_id', -100200300)->first());
    }

    public function test_status_endpoint_reports_the_link_state_for_the_waiting_page(): void
    {
        $user = $this->user();

        $this->actingAs($user)->getJson(route('settings.telegram.status'))
            ->assertOk()->assertJson(['linked' => false]);

        $token = app(TelegramLinkService::class)->issueToken($user);
        $this->webhook($this->update(555030, '/start '.$token->token, 'nid'));

        $this->actingAs($user->refresh())->getJson(route('settings.telegram.status'))
            ->assertOk()->assertJson(['linked' => true, 'enabled' => true, 'username' => 'nid']);
    }

    public function test_user_can_toggle_and_unlink_from_the_settings_page(): void
    {
        $user = $this->linked(555040);

        $this->actingAs($user)->patch(route('settings.telegram.update'), [
            'telegram_notifications_enabled' => 0,
        ])->assertRedirect();
        $this->assertFalse((bool) $user->refresh()->telegram_notifications_enabled);

        TelegramMessage::create([
            'user_id' => $user->id, 'chat_id' => 555040,
            'text' => 'ค้างคิว', 'status' => TelegramMessage::STATUS_PENDING,
        ]);

        $this->actingAs($user)->delete(route('settings.telegram.destroy'))->assertRedirect();

        $user->refresh();
        $this->assertNull($user->telegram_chat_id);
        // ข้อความที่ค้างคิวต้องไม่ถูกส่งต่อไปยังแชทที่เจ้าของไม่ต้องการแล้ว
        $this->assertSame(TelegramMessage::STATUS_FAILED, TelegramMessage::query()->value('status'));
        $this->assertTrue(ActivityLog::query()->where('action', 'telegram_unlinked')->exists());
    }

    public function test_test_message_reports_success_and_failure_back_to_the_user(): void
    {
        $user = $this->linked(555050);

        $this->actingAs($user)->post(route('settings.telegram.test'))
            ->assertRedirect()->assertSessionHas('success');

        $this->failTelegramWith('Bad Request: message is too long');

        $this->actingAs($user)->post(route('settings.telegram.test'))
            ->assertRedirect()->assertSessionHasErrors('telegram');
    }

    private function webhook(array $payload)
    {
        return $this->withHeader('X-Telegram-Bot-Api-Secret-Token', self::SECRET)
            ->postJson(route('telegram.webhook'), $payload);
    }

    private function update(int $chatId, string $text, ?string $username = null): array
    {
        return [
            'update_id' => random_int(1, 999999),
            'message' => [
                'message_id' => 1,
                'chat' => ['id' => $chatId, 'type' => 'private'],
                'from' => ['id' => $chatId, 'username' => $username],
                'text' => $text,
            ],
        ];
    }

    private function user(string $role = 'user'): User
    {
        return User::factory()->create(['role' => $role, 'must_change_password' => false, 'is_active' => true]);
    }

    private function linked(int $chatId): User
    {
        $user = $this->user();
        $user->forceFill([
            'telegram_chat_id' => $chatId,
            'telegram_username' => 'chat'.$chatId,
            'telegram_linked_at' => now(),
            'telegram_notifications_enabled' => true,
        ])->save();

        return $user;
    }
}
