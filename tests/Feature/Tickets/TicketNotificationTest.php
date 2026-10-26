<?php

namespace Tests\Feature\Tickets;

use App\Models\SystemNotification;
use App\Models\TelegramMessage;
use App\Models\Ticket;
use App\Services\NotificationService;
use App\Services\TicketNoteService;
use App\Services\TicketNotificationService;
use App\Services\TicketParticipantService;
use App\Services\TicketSubmissionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\TestCase;

/**
 * การแจ้งเตือนของ Smart Ticket ผ่านระบบเดิม: ในระบบ, Telegram (TelegramOutbox) และ event สำหรับเสียง
 */
class TicketNotificationTest extends TestCase
{
    use BuildsTickets;
    use BuildsTicketUploads;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.telegram.enabled' => true,
            'services.telegram.bot_token' => '123456:TEST-TOKEN',
            'services.telegram.max_attempts' => 3,
        ]);
    }

    /* ---------- Ticket ใหม่ ---------- */

    public function test_a_new_ticket_notifies_agents_who_want_alerts_and_nobody_else(): void
    {
        $listening = $this->agent();
        $muted = $this->agent();
        $this->override($muted, enabled: true, alerts: false);
        $revoked = $this->agent();
        $this->override($revoked, enabled: false);
        $viewer = $this->staff($this->itDepartment(), role: 'viewer');
        $inactive = $this->staff($this->itDepartment(), active: false);
        $employee = $this->staff($this->department('Sales'));
        $admin = $this->staff($this->department('Management'), role: 'admin');

        $ticket = $this->publicTicket();

        $this->assertSame([$listening->id], $this->recipientsOf('ticket_created'));

        $notice = SystemNotification::query()->where('type', 'ticket_created')->sole();
        $this->assertSame('ticket', $notice->category);
        $this->assertSame($ticket->id, $notice->data['ticket_id']);
        $this->assertStringContainsString($ticket->ticket_number, $notice->title);
        $this->assertStringContainsString('สาขาบางนา', $notice->message);
        $this->assertStringContainsString('แจ้งเมื่อ', $notice->message);

        foreach ([$muted, $revoked, $viewer, $inactive, $employee, $admin] as $user) {
            $this->assertSame(0, SystemNotification::query()->where('user_id', $user->id)->count());
        }
    }

    public function test_an_agent_cannot_submit_so_no_ticket_created_alert_goes_out(): void
    {
        // แยกหน้าที่: Handler แจ้ง Ticket เองไม่ได้ (TicketAgentAccess::canReport) จึงไม่มีการแจ้งเตือน Ticket ใหม่
        $agent = $this->agent();
        $this->agent();

        $this->actingAs($agent)->post(route('tickets.store'), [
            'description' => 'คอมพิวเตอร์เปิดไม่ติด', 'location_name' => 'ชั้น 2', 'submission_key' => (string) Str::uuid(),
        ])->assertForbidden();

        $this->assertSame(0, Ticket::count());
        $this->assertSame([], $this->recipientsOf('ticket_created'));
    }

    public function test_without_any_agent_active_admins_are_notified_instead(): void
    {
        $admin = $this->staff($this->department('Management'), role: 'admin');
        $this->staff($this->department('Management'), role: 'admin', active: false);
        $this->staff($this->department('Sales'));

        $this->publicTicket();

        $this->assertSame([$admin->id], $this->recipientsOf('ticket_created'));
    }

    public function test_agents_who_all_muted_alerts_do_not_trigger_the_admin_fallback(): void
    {
        $this->override($this->agent(), enabled: true, alerts: false);
        $this->staff($this->department('Management'), role: 'admin');

        $this->publicTicket();

        $this->assertSame([], $this->recipientsOf('ticket_created'));
    }

    public function test_the_same_ticket_never_notifies_twice(): void
    {
        $agent = $this->agent();
        $agent->forceFill(['telegram_chat_id' => 4242, 'telegram_notifications_enabled' => true])->save();
        Http::fake();

        $form = $this->publicForm();
        $this->post(route('support.store'), $form);
        $this->post(route('support.store'), $form); // กดส่งซ้ำ / refresh ด้วยคีย์เดิม

        $ticket = Ticket::query()->sole();
        app(TicketNotificationService::class)->ticketCreated($ticket); // เรียกซ้ำโดยตรง

        $this->assertSame(1, SystemNotification::query()->where('type', 'ticket_created')->where('user_id', $agent->id)->count());
        $this->assertSame(1, TelegramMessage::query()->where('user_id', $agent->id)->count());
    }

    /* ---------- Telegram ---------- */

    public function test_telegram_message_has_the_essentials_and_no_contact_details(): void
    {
        $agent = $this->agent();
        $agent->forceFill(['telegram_chat_id' => 4242, 'telegram_notifications_enabled' => true])->save();
        Http::fake();

        $ticket = app(TicketSubmissionService::class)->submitPublic([
            'requester_name' => 'คุณลูกค้า',
            'description' => "เครื่องพิมพ์ไม่ทำงาน\nรายละเอียดบรรทัดสอง",
            'location_name' => 'สาขาลาดพร้าว',
            'contact' => '089-999-9999',
        ], '203.0.113.9');

        $message = TelegramMessage::query()->where('user_id', $agent->id)->sole();
        $this->assertStringContainsString($ticket->ticket_number, $message->text);
        $this->assertStringContainsString('เครื่องพิมพ์ไม่ทำงาน', $message->text);
        $this->assertStringContainsString('สาขาลาดพร้าว', $message->text);
        $this->assertStringContainsString('แจ้งเมื่อ', $message->text);
        $this->assertStringContainsString(route('tickets.show', $ticket), $message->text);
        $this->assertStringNotContainsString('089-999-9999', $message->text);
        $this->assertStringNotContainsString('203.0.113.9', $message->text);
    }

    public function test_a_failing_telegram_api_does_not_break_ticket_creation(): void
    {
        $agent = $this->agent();
        $agent->forceFill(['telegram_chat_id' => 4242, 'telegram_notifications_enabled' => true])->save();
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => false, 'description' => 'Internal'], 500)]);

        $this->post(route('support.store'), $this->publicForm())->assertRedirect(route('support.submitted'));

        $this->assertSame(1, Ticket::count());
        $this->assertSame(1, SystemNotification::query()->where('user_id', $agent->id)->count());

        // ส่งจริงหลังตอบ response (TelegramOutbox::drain ตอน terminate) แล้วล้ม — รอส่งใหม่ ไม่ใช่สำเร็จ
        $message = TelegramMessage::query()->sole();
        Http::assertSent(fn ($request) => str_contains($request->url(), 'api.telegram.org'));
        $this->assertGreaterThanOrEqual(1, $message->attempts);
        $this->assertNotSame(TelegramMessage::STATUS_SENT, $message->status);
        $this->assertNotNull($message->last_error);
    }

    public function test_a_telegram_network_timeout_does_not_break_ticket_creation(): void
    {
        $agent = $this->agent();
        $agent->forceFill(['telegram_chat_id' => 4242, 'telegram_notifications_enabled' => true])->save();
        Http::fake(fn () => throw new ConnectionException('cURL error 28: Operation timed out'));

        $this->post(route('support.store'), $this->publicForm())->assertRedirect(route('support.submitted'));

        $this->assertSame(1, Ticket::count());
        $message = TelegramMessage::query()->sole();
        $this->assertSame(TelegramMessage::STATUS_PENDING, $message->status, 'รอให้ telegram:drain ลองใหม่');
        $this->assertSame(1, $message->attempts);
        $this->assertStringContainsString('timed out', (string) $message->last_error);
    }

    public function test_a_failing_notification_layer_is_logged_and_never_rolls_back_the_ticket(): void
    {
        $this->agent();
        $this->mock(NotificationService::class)
            ->shouldReceive('notifyDetached')
            ->andThrow(new RuntimeException('notification store down'));
        Log::spy();

        $ticket = $this->publicTicket();

        $this->assertTrue(Ticket::query()->whereKey($ticket->id)->exists());
        Log::shouldHaveReceived('error')->withArgs(fn ($message, $context) => $message === 'Smart Ticket notification failed'
            && $context['ticket_number'] === $ticket->ticket_number
            && $context['event'] === 'created')->once();
    }

    /* ---------- เปลี่ยนสถานะและบันทึก ---------- */

    public function test_status_changes_notify_the_requester_and_participants_except_the_actor(): void
    {
        $sales = $this->department('Sales');
        $requester = $this->staff($sales);
        $head = $this->staff($sales, head: true);
        $lead = $this->agent();
        $helper = $this->agent();
        $ticket = $this->ticketFrom($requester);

        $this->startedBy($lead, $ticket);
        app(TicketParticipantService::class)->join($ticket, $helper);
        $this->transition($ticket, Ticket::ACTION_CLOSE, $helper, ['note' => 'เปลี่ยนสายแล้ว']);

        $closed = SystemNotification::query()->where('type', 'ticket_status_changed')->where('message', 'like', '%ปิดงาน%')->pluck('user_id')->sort()->values()->all();
        $this->assertSame(collect([$requester->id, $lead->id])->sort()->values()->all(), $closed);
        $this->assertSame(0, SystemNotification::query()->where('user_id', $head->id)->where('category', 'ticket')->count());
    }

    public function test_notes_notify_others_but_not_the_author_and_public_requesters_get_nothing(): void
    {
        $agent = $this->agent();
        $helper = $this->agent();
        $ticket = $this->startedBy($agent);
        app(TicketParticipantService::class)->join($ticket, $helper);

        app(TicketNoteService::class)->add($ticket, $agent, 'เปลี่ยนสาย LAN แล้ว');

        $this->assertSame([$helper->id], $this->recipientsOf('ticket_note_added'));
        $this->assertNull($ticket->requester_id);
    }

    /* ---------- เปิดจากการแจ้งเตือน ---------- */

    public function test_opening_a_ticket_notification_goes_to_the_ticket_after_a_fresh_permission_check(): void
    {
        $agent = $this->agent();
        $ticket = $this->publicTicket();
        $notice = SystemNotification::query()->where('user_id', $agent->id)->sole();

        $this->actingAs($agent)->get(route('notifications.open', $notice))
            ->assertRedirect(route('tickets.show', $ticket));

        // หมดสิทธิ์ agent ภายหลัง → ไม่พาไปหน้า Ticket อีก
        $this->override($agent, enabled: false);
        $this->actingAs($agent->fresh())->get(route('notifications.open', $notice))
            ->assertRedirect(route('notifications.index'));
    }

    public function test_ticket_notifications_appear_in_the_center_and_the_realtime_feed(): void
    {
        $agent = $this->agent();
        $ticket = $this->publicTicket();

        $this->actingAs($agent)->get(route('notifications.index', ['category' => 'ticket']))
            ->assertOk()
            ->assertSee($ticket->ticket_number)
            ->assertSee('notification-center__item--ticket', false)
            ->assertSee('bi-headset', false);

        $this->actingAs($agent)->getJson(route('realtime.sync', ['after' => 0]))
            ->assertOk()
            ->assertJsonPath('events.0.type', 'ticket_created')
            ->assertJsonPath('events.0.category', 'ticket');
    }

    /* ---------- เสียงแจ้งเตือนในทุกหน้า ---------- */

    public function test_the_sound_alert_loads_on_every_page_for_alert_agents_only(): void
    {
        $agent = $this->agent();
        $mutedAgent = $this->agent();
        $this->override($mutedAgent, enabled: true, alerts: false);
        $sales = $this->department('Sales');

        // ตัวเล่นเสียงอยู่ทุกหน้า (agent อยู่เมนูอื่นก็ได้ยิน) และแท็บที่ถูกซ่อนยังตรวจต่อ · ปุ่มเปิด/ปิดอยู่บนหน้ารับแจ้งปัญหาเท่านั้น
        foreach ([route('mytasks.index'), route('notifications.index'), route('tickets.index')] as $page) {
            $response = $this->actingAs($agent)->get($page)
                ->assertSee('ticket-alert', false)
                ->assertSee('data-realtime-background-interval="10000"', false);
            $page === route('tickets.index')
                ? $response->assertSee('data-ticket-sound-toggle', false)
                : $response->assertDontSee('data-ticket-sound-toggle', false);
            // ไฟล์เสียงของระบบ (ชื่อมีช่องว่าง → URL encode) เล่นวน 20 วินาทีต่อ Ticket
            $response->assertSee('data-ticket-alert-sound="'.asset('sounds/alert%20-1.mp3').'"', false)
                ->assertSee('data-ticket-alert-seconds="20"', false);
        }

        // ไฟล์ที่ config ชี้ต้องมีอยู่จริงใน public/ (ถ้าหายไป ระบบยังดังด้วยเสียงสังเคราะห์ แต่ต้องรู้ตั้งแต่เทสต์)
        $this->assertFileExists(public_path(config('tickets.alert.sound')));

        foreach ([
            'muted agent' => [$mutedAgent, route('mytasks.index')],
            'employee' => [$this->staff($sales), route('mytasks.index')],
            'department head' => [$this->staff($sales, head: true), route('mytasks.index')],
            'admin (not agent)' => [$this->staff($this->department('Management'), role: 'admin'), route('board.index')],
            'viewer' => [$this->staff($sales, role: 'viewer'), route('board.index')],
        ] as $who => [$user, $page]) {
            $content = $this->actingAs($user)->get($page)->assertOk()->getContent();
            $this->assertStringNotContainsString('data-ticket-sound-toggle', $content, $who);
            $this->assertStringNotContainsString('ticket-alert', $content, $who);
            $this->assertStringNotContainsString('data-realtime-background-interval', $content, $who);
        }

        // หน้ารับแจ้งปัญหาของ agent ที่ปิดรับแจ้งเตือน / admin ที่ไม่ใช่ agent ไม่มีปุ่มเสียง
        $this->actingAs($mutedAgent)->get(route('tickets.index'))->assertOk()->assertDontSee('data-ticket-sound-toggle', false);
        $this->actingAs($this->staff($this->department('Board'), role: 'admin'))->get(route('tickets.index'))
            ->assertOk()->assertDontSee('data-ticket-sound-toggle', false);
    }

    /** @return array<int, int> */
    private function recipientsOf(string $type): array
    {
        return SystemNotification::query()->where('type', $type)->pluck('user_id')->sort()->values()->all();
    }

    public function test_checking_each_recipients_access_does_not_load_agent_settings_one_person_at_a_time(): void
    {
        $relationQueries = function (callable $notify): int {
            $count = 0;
            DB::listen(function ($query) use (&$count): void {
                if (preg_match('/from "(ticket_agents|departments)" where "(ticket_agents"\."user_id|departments"\."id)" in/', $query->sql)) {
                    $count++;
                }
            });
            $notify();

            return $count;
        };

        // ผู้รับ 2 คน
        $lead = $this->agent();
        $this->agent();
        $ticket = $this->startedBy($lead);
        app(TicketParticipantService::class)->join($ticket, $this->agent());
        $few = $relationQueries(function () use ($ticket, $lead): void {
            $this->publicTicket();
            $this->transition($ticket, Ticket::ACTION_FOLLOW_UP, $lead, ['note' => 'รออะไหล่']);
        });

        // ผู้รับเพิ่มเป็น 7 คน — จำนวนคิวรีความสัมพันธ์ต้องไม่โตตาม
        foreach (range(1, 4) as $index) {
            app(TicketParticipantService::class)->join($ticket, $this->agent());
        }
        $many = $relationQueries(function () use ($ticket, $lead): void {
            $this->publicTicket();
            $this->transition($ticket, Ticket::ACTION_RESUME, $lead);
        });

        $this->assertSame($few, $many);
    }
}
