<?php

namespace Tests\Feature;

use App\Models\SystemNotification;
use App\Models\TelegramMessage;
use App\Models\User;
use App\Models\WorkOrder;
use App\Services\NotificationService;
use App\Services\Telegram\TelegramOutbox;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class TelegramNotificationDeliveryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.telegram.enabled' => true,
            'services.telegram.bot_token' => '123456:TEST-TOKEN',
            'services.telegram.bot_username' => 'SmartGoalTestBot',
            'services.telegram.webhook_secret' => 'secret-token-for-tests',
            'services.telegram.max_attempts' => 3,
        ]);
    }

    public function test_notification_is_queued_only_for_users_who_can_receive_telegram(): void
    {
        Http::fake();

        $actor = $this->user('admin');
        $linked = $this->linkedUser(1001);
        $unlinked = $this->user();
        $muted = $this->linkedUser(1002);
        $muted->forceFill(['telegram_notifications_enabled' => false])->save();
        $viewer = $this->linkedUser(1003, 'viewer');
        $inactive = $this->linkedUser(1004);
        $inactive->forceFill(['is_active' => false])->save();

        app(NotificationService::class)->notifyDetached(
            [$linked->id, $unlinked->id, $muted->id, $viewer->id, $inactive->id, $actor->id],
            'system',
            'มีงานใหม่รออยู่',
            'รายละเอียดของงาน',
            $actor
        );

        $this->assertSame([$linked->id], TelegramMessage::query()->pluck('user_id')->all());
        $this->assertSame(1001, (int) TelegramMessage::query()->value('chat_id'));
    }

    public function test_queued_message_carries_title_message_and_deep_link(): void
    {
        Http::fake();

        $actor = $this->user('admin');
        $recipient = $this->linkedUser(2001);

        app(NotificationService::class)->notifyDetached(
            [$recipient->id],
            'meeting_scheduled',
            'ประชุมทีม <ด่วน>',
            'เริ่ม 10:00 น.',
            $actor,
            ['meeting_id' => 999]
        );

        $text = TelegramMessage::query()->value('text');

        // หัวข้อที่ผู้ใช้พิมพ์เองต้องถูก escape ไม่งั้น Telegram ปฏิเสธทั้งข้อความ
        $this->assertStringContainsString('&lt;ด่วน&gt;', $text);
        $this->assertStringContainsString('เริ่ม 10:00 น.', $text);
        $this->assertStringContainsString(route('notifications.index'), $text);
    }

    public function test_repeated_deduplicated_notification_is_queued_only_once(): void
    {
        Http::fake();

        $actor = $this->user('admin');
        $recipient = $this->linkedUser(3001);
        $notifications = app(NotificationService::class);

        foreach (range(1, 3) as $ignored) {
            $notifications->notifyDetached(
                [$recipient->id],
                'deadline_overdue',
                'งานเลยกำหนด',
                null,
                $actor,
                [],
                [],
                'deadline:overdue:77:2026-09-08'
            );
        }

        $this->assertSame(1, SystemNotification::query()->count());
        $this->assertSame(1, TelegramMessage::query()->count());
    }

    public function test_disabled_integration_queues_nothing(): void
    {
        Http::fake();
        config(['services.telegram.enabled' => false]);

        $actor = $this->user('admin');
        $recipient = $this->linkedUser(4001);

        app(NotificationService::class)->notifyDetached([$recipient->id], 'system', 'ปิดอยู่', null, $actor);

        $this->assertSame(1, SystemNotification::query()->count());
        $this->assertSame(0, TelegramMessage::query()->count());
    }

    public function test_drain_marks_message_sent_and_posts_to_telegram(): void
    {
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['message_id' => 5]])]);

        $message = $this->queued(5001);

        $this->assertSame(1, app(TelegramOutbox::class)->drain());

        $message->refresh();
        $this->assertSame(TelegramMessage::STATUS_SENT, $message->status);
        $this->assertSame(1, $message->attempts);
        $this->assertNotNull($message->sent_at);

        Http::assertSent(fn ($request) => str_contains($request->url(), '/sendMessage')
            && $request['chat_id'] == 5001
            && $request['parse_mode'] === 'HTML');
    }

    public function test_server_error_keeps_message_pending_until_attempts_run_out(): void
    {
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => false, 'description' => 'Bad Gateway'], 502)]);

        $message = $this->queued(6001);
        $outbox = app(TelegramOutbox::class);

        $outbox->drain();
        $this->assertSame(TelegramMessage::STATUS_PENDING, $message->refresh()->status);
        $this->assertSame(1, $message->attempts);

        $outbox->drain();
        $this->assertSame(TelegramMessage::STATUS_PENDING, $message->refresh()->status);

        $outbox->drain();
        $message->refresh();
        $this->assertSame(TelegramMessage::STATUS_FAILED, $message->status);
        $this->assertSame(3, $message->attempts);
        $this->assertStringContainsString('Bad Gateway', (string) $message->last_error);
    }

    public function test_blocked_bot_unlinks_the_account_and_stops_retrying(): void
    {
        Http::fake(['api.telegram.org/*' => Http::response([
            'ok' => false,
            'error_code' => 403,
            'description' => 'Forbidden: bot was blocked by the user',
        ], 403)]);

        $message = $this->queued(7001);
        $user = $message->user;

        app(TelegramOutbox::class)->drain();

        $message->refresh();
        $user->refresh();

        $this->assertSame(TelegramMessage::STATUS_FAILED, $message->status);
        $this->assertNull($user->telegram_chat_id);
        $this->assertNull($user->telegram_linked_at);
        $this->assertFalse($user->receivesTelegramNotifications());
    }

    /**
     * ทุก request ที่สร้างการแจ้งเตือนจะ drain ด้วย สอง request ที่เกิดพร้อมกัน
     * จึงต้องไม่หยิบแถวเดียวกันไปส่ง มิฉะนั้นผู้ใช้จะได้ข้อความซ้ำสองครั้ง
     */
    public function test_a_message_already_claimed_by_another_round_is_not_sent_again(): void
    {
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true, 'result' => []])]);

        $message = $this->queued(10001);
        $message->forceFill(['status' => TelegramMessage::STATUS_SENDING])->save();

        $this->assertSame(0, app(TelegramOutbox::class)->drain());

        Http::assertNothingSent();
    }

    public function test_a_round_that_died_mid_flight_is_reclaimed_after_the_stale_window(): void
    {
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true, 'result' => []])]);

        $message = $this->queued(10002);
        $message->forceFill(['status' => TelegramMessage::STATUS_SENDING])->save();
        $message->forceFill([
            'updated_at' => now()->subMinutes(TelegramMessage::STALE_CLAIM_MINUTES + 1),
        ])->saveQuietly();

        $this->assertSame(1, app(TelegramOutbox::class)->drain());
        $this->assertSame(TelegramMessage::STATUS_SENT, $message->refresh()->status);
    }

    public function test_drain_does_nothing_when_integration_is_disabled(): void
    {
        Http::fake();
        $this->queued(8001);
        config(['services.telegram.enabled' => false]);

        $this->assertSame(0, app(TelegramOutbox::class)->drain());

        Http::assertNothingSent();
    }

    public function test_task_notification_respects_view_permission_before_queueing(): void
    {
        Http::fake();

        $actor = $this->user('admin');
        $outsider = $this->linkedUser(9001);
        $assignee = $this->linkedUser(9002);

        $task = WorkOrder::create([
            'user_id' => $assignee->id, 'created_by' => $actor->id, 'leader_user_id' => $actor->id,
            'job_topic' => 'งานลับเฉพาะทีม', 'job_priority' => 2, 'job_status' => 2,
            'approval_status' => 'approved',
            'job_start_at' => '2026-09-01 00:00:00', 'job_due_at' => '2026-09-30 00:00:00',
        ]);

        app(NotificationService::class)->notify(
            [$assignee->id, $outsider->id],
            'task_comment',
            'มีความคิดเห็นใหม่',
            'ข้อความ',
            $task,
            $actor
        );

        $this->assertSame([$assignee->id], TelegramMessage::query()->pluck('user_id')->all());
    }

    /**
     * เส้นทางจริงตั้งแต่ผู้ใช้กดส่งคอมเมนต์จนข้อความออกไปถึง Telegram
     *
     * เป็นส่วนที่เสี่ยงที่สุดของฟีเจอร์นี้ เพราะเครื่อง production ไม่มี queue worker
     * การส่งจึงอาศัย terminating callback ของ request ล้วน ๆ ถ้ากลไกนี้ไม่ทำงาน
     * ข้อความจะค้างคิวเงียบ ๆ โดยที่ทุกอย่างอย่างอื่นดูปกติดี
     *
     * คอมเมนต์ถูกสร้างใน DB::transaction ด้วย จึงยืนยันไปพร้อมกันว่าการยิง HTTP
     * ไม่ได้เกิดขึ้นภายในทรานแซกชันนั้น
     */
    public function test_comment_request_delivers_to_telegram_after_the_response_is_sent(): void
    {
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true, 'result' => []])]);

        $author = $this->user('admin');
        $assignee = $this->linkedUser(11001);

        $task = WorkOrder::create([
            'user_id' => $assignee->id, 'created_by' => $author->id, 'leader_user_id' => $author->id,
            'job_topic' => 'งานที่มีคอมเมนต์', 'job_priority' => 2, 'job_status' => 2,
            'approval_status' => 'approved',
            'job_start_at' => '2026-09-01 00:00:00', 'job_due_at' => '2026-09-30 00:00:00',
        ]);

        $this->actingAs($author)
            ->postJson(route('tasks.comments.store', $task), ['message' => 'ช่วยดูงานนี้ด้วยครับ'])
            ->assertSuccessful();

        $message = TelegramMessage::query()->where('user_id', $assignee->id)->firstOrFail();
        $this->assertSame(TelegramMessage::STATUS_SENT, $message->status);

        Http::assertSent(fn ($request) => str_contains($request->url(), '/sendMessage')
            && $request['chat_id'] == 11001
            && str_contains($request['text'], 'งานที่มีคอมเมนต์')
            // ลิงก์อยู่ในแอตทริบิวต์ href ของ HTML เครื่องหมาย & จึงถูก escape เป็น &amp;
            && str_contains($request['text'], e(route('mytasks.index', ['open_task' => $task->job_id, 'task_tab' => 'updates']))));
    }

    private function user(string $role = 'user'): User
    {
        return User::factory()->create(['role' => $role, 'must_change_password' => false, 'is_active' => true]);
    }

    private function linkedUser(int $chatId, string $role = 'user'): User
    {
        $user = $this->user($role);
        $user->forceFill([
            'telegram_chat_id' => $chatId,
            'telegram_username' => 'chat'.$chatId,
            'telegram_linked_at' => now(),
            'telegram_notifications_enabled' => true,
        ])->save();

        return $user;
    }

    private function queued(int $chatId): TelegramMessage
    {
        $user = $this->linkedUser($chatId);

        return TelegramMessage::create([
            'user_id' => $user->id,
            'chat_id' => $chatId,
            'text' => 'ข้อความทดสอบ',
            'status' => TelegramMessage::STATUS_PENDING,
        ]);
    }
}
