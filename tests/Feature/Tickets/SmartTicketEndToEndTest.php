<?php

namespace Tests\Feature\Tickets;

use App\Models\SystemNotification;
use App\Models\TelegramMessage;
use App\Models\Ticket;
use App\Models\TicketActivity;
use App\Models\TicketAttachment;
use App\Models\TicketParticipant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Smart Ticket ตั้งแต่ต้นจนจบ ผ่าน HTTP จริงทุกขั้น (Phase 6 Step 7)
 *
 * ฐานทดสอบ = SQLite in-memory ที่ RefreshDatabase migrate ครบทุกไฟล์ (รวม 2026_10_23_000001–000008)
 * แยกจากฐาน dev/production โดยสิ้นเชิง
 *
 * Telegram ใช้ Http::fake — ยืนยันการเข้าคิวและการส่งของ outbox เท่านั้น
 * ไม่ใช่การส่งจริงถึง Telegram บน production
 */
class SmartTicketEndToEndTest extends TestCase
{
    use BuildsTickets;
    use BuildsTicketUploads;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true, 'result' => []])]);
        config([
            'services.telegram.enabled' => true,
            'services.telegram.bot_token' => '123456:TEST-TOKEN',
            'tickets.sla.response_minutes' => null,
            'tickets.sla.resolution_hours' => null,
        ]);
        // ไม่ตรึงเวลา: formToken() ของฟอร์มสาธารณะรีเซ็ตนาฬิกาของเทสต์เป็นเวลาจริง ขั้นตอนที่ต้องการ
        // ระยะเวลาใช้ travel() แบบสัมพัทธ์แทน
    }

    public function test_the_test_database_has_all_eight_ticket_migrations(): void
    {
        $ran = DB::table('migrations')->where('migration', 'like', '2026_10_23_%')->orderBy('migration')->pluck('migration');

        $this->assertCount(8, $ran);
        $this->assertSame('2026_10_23_000008_add_identity_refs_to_ticket_history', $ran->last());
        $this->assertTrue(Schema::hasColumns('ticket_participants', ['user_ref_id']));
        $this->assertSame('sqlite', DB::connection()->getDriverName(), 'ไม่ใช่ฐานจริง');
    }

    /* ---------------------------------------------------------------
     * Scenario A — พนักงานแจ้ง → ทีม IT ทำงานร่วมกันจนปิด → KPI
     * ------------------------------------------------------------- */

    public function test_scenario_a_internal_submission_to_closed_ticket_and_kpi(): void
    {
        $sales = $this->department('ฝ่ายขาย');
        $employee = $this->staff($sales);
        $employee->update(['name' => 'สุดา พนักงานขาย']);
        [$agentA, $agentB] = [$this->linkedAgent(9001), $this->linkedAgent(9002)];

        // 1–4 ส่ง Ticket — ชื่อ/แผนกมาจากบัญชี ไม่ใช่จากฟอร์ม
        $this->actingAs($employee)
            ->post(route('tickets.store'), $this->internalForm(['requester_department_id' => 999, 'requester_name' => 'ปลอม']))
            ->assertRedirect();
        $ticket = Ticket::query()->sole();
        $this->assertSame('TK-'.str_pad((string) $ticket->id, 6, '0', STR_PAD_LEFT), $ticket->ticket_number);
        $this->assertSame(['สุดา พนักงานขาย', $sales->id, 'ฝ่ายขาย'], [$ticket->requester_name, $ticket->requester_department_id, $ticket->requester_department_name]);

        // 5 agent ได้แจ้งเตือนในระบบ + Telegram
        $this->assertEqualsCanonicalizing([$agentA->id, $agentB->id], $this->notified('ticket_created'));
        $this->assertSame(2, TelegramMessage::query()->where('status', TelegramMessage::STATUS_SENT)->count());

        // 6 A รับงาน (เวลาตอบรับ 30 นาที) · 7 B เข้าร่วม
        $this->travel(30)->minutes();
        $this->act($agentA, $ticket, 'start')->assertSessionHas('success');
        $this->actingAs($agentB)->post(route('tickets.participants.store', $ticket))->assertSessionHas('success');

        // 8 บันทึก + รูป
        $this->actingAs($agentB)->post(route('tickets.notes.store', $ticket), ['body' => 'เปลี่ยนสายไฟแล้ว', 'photos' => [$this->photo()]])
            ->assertSessionHas('success');
        $this->assertSame(1, TicketAttachment::query()->whereNotNull('ticket_activity_id')->count());

        // 9–10 ติดตาม → กลับมาดำเนินการ
        $this->act($agentA, $ticket, 'follow_up', ['note' => 'รออะไหล่'])->assertSessionHas('success');
        $this->act($agentA, $ticket, 'resume')->assertSessionHas('success');

        // 11 ปิด · 12 เปิดใหม่พร้อมเหตุผล (ไม่มีเหตุผล = ปฏิเสธ) · 13 ปิดอีกครั้ง
        $this->act($agentB, $ticket, 'close', ['note' => 'ใช้งานได้แล้ว'])->assertSessionHas('success');
        $this->act($agentA, $ticket, 'reopen')->assertSessionHasErrors('note');
        $this->act($agentA, $ticket, 'reopen', ['note' => 'ผู้ใช้แจ้งว่ายังดับอีก'])->assertSessionHas('success');
        $this->travel(90)->minutes();
        $this->act($agentA, $ticket, 'close', ['note' => 'เปลี่ยนปลั๊กแล้ว'])->assertSessionHas('success');

        $ticket->refresh();
        $this->assertSame([Ticket::STATUS_CLOSED, 1, $agentA->id], [$ticket->status, $ticket->reopen_count, (int) $ticket->closed_by]);

        // 14 timeline ครบทุกเหตุการณ์ตามลำดับ
        $this->assertSame(
            ['created', 'joined', 'status_changed', 'joined', 'note', 'status_changed', 'status_changed', 'status_changed', 'status_changed', 'status_changed'],
            $ticket->activities()->pluck('type')->all(),
        );
        // ผู้แจ้งไม่เข้าหน้ารายละเอียดเต็ม — กลับ /tickets แล้วเห็น modal อ่านอย่างเดียว:
        // ผลการแก้ไขเมื่อปิดงาน แต่ไม่เห็นบันทึกการดำเนินงาน (ภายใน) หรือเหตุผลที่เปิดใหม่ — ทั้งในหน้าและในการแจ้งเตือน
        $this->actingAs($employee)->get(route('tickets.show', $ticket))->assertRedirect(route('tickets.index', ['ticket' => $ticket->id]));
        $this->actingAs($employee)->get(route('tickets.index', ['ticket' => $ticket->id]))->assertOk()
            ->assertSee('data-open-on-load', false)
            ->assertSee('เปลี่ยนปลั๊กแล้ว')
            ->assertDontSee('เปลี่ยนสายไฟแล้ว')
            ->assertDontSee('ผู้ใช้แจ้งว่ายังดับอีก')
            ->assertSee('แจ้งปัญหา IT');
        $this->assertNotContains($employee->id, $this->notified('ticket_note_added'));

        // ผู้แจ้งได้แจ้งเตือนเรื่องสถานะ และไม่ได้แจ้งเตือนจากการกระทำของตัวเอง
        $this->assertContains($employee->id, $this->notified('ticket_status_changed'));

        // 15 KPI: ทีม 1 ใบ, A และ B คนละ 1, ผู้ปิดล่าสุด = A, เวลาแก้ไข = 2 ชม. (ปฏิทิน จากแจ้งถึงปิดครั้งล่าสุด)
        $report = $this->actingAs($agentA)->get(route('reports.tickets'))->assertOk();
        $kpis = collect($report->viewData('kpis'))->keyBy('key');
        $people = collect($report->viewData('people')->items())->keyBy('key');
        $this->assertSame(['1', '1', '30 นาที', '2 ชม.'], [$kpis['total']['value'], $kpis['closed']['value'], $kpis['response']['value'], $kpis['resolution']['value']]);
        $this->assertSame([1, 1, 0], [$people['user:'.$agentA->id]['participated'], $people['user:'.$agentA->id]['closed'], $people['user:'.$agentA->id]['still_involved']]);
        $this->assertSame([1, 0], [$people['user:'.$agentB->id]['participated'], $people['user:'.$agentB->id]['closed']]);
        $this->assertSame(['ฝ่ายขาย'], array_column($report->viewData('byDepartment'), 'label'));
    }

    /* ---------------------------------------------------------------
     * Scenario B — บุคคลภายนอกแจ้งผ่าน /support
     * ------------------------------------------------------------- */

    public function test_scenario_b_public_submission(): void
    {
        $agent = $this->linkedAgent(9101);
        $form = $this->publicForm(['photos' => [$this->photo('front.jpg')]]);

        // 1–5 ไม่ login, ช่องบังคับ 3 ช่อง + รูป
        $this->assertGuest();
        $this->get(route('support.create'))->assertOk()->assertSee('name="requester_name"', false);
        $this->post(route('support.store'), $form)->assertRedirect(route('support.submitted'));
        $ticket = Ticket::query()->sole();
        $this->get(route('support.submitted'))->assertOk()->assertSee($ticket->ticket_number);
        $this->assertSame([Ticket::SOURCE_PUBLIC, null, 'สาขาบางนา'], [$ticket->source, $ticket->requester_id, $ticket->location_name]);

        // 9 ส่งซ้ำด้วยฟอร์มเดิม (กดซ้ำ / refresh) → ใบเดิม ไม่มีแจ้งเตือนซ้ำ
        $this->post(route('support.store'), $form)->assertRedirect(route('support.submitted'));
        $this->assertSame(1, Ticket::query()->count());
        $this->assertSame([$agent->id], $this->notified('ticket_created'));

        // 6 agent เห็นในรายการและเปิดได้
        $this->actingAs($agent)->get(route('tickets.index'))->assertSee('id="ticket-row-'.$ticket->id.'"', false);
        $this->actingAs($agent)->get(route('tickets.show', $ticket))->assertOk();

        // 7 ผู้แจ้งภายนอกไม่มีทางเปิด Ticket ใด ๆ — ไม่มี route สาธารณะที่รับเลข Ticket
        auth()->guard('web')->logout();
        $this->get(route('tickets.show', $ticket))->assertRedirect(route('login'));
        $this->get('/support/'.$ticket->id)->assertNotFound();
        $this->get('/support/'.$ticket->ticket_number)->assertNotFound();

        // 8 รูปเปิดได้ผ่าน policy เท่านั้น
        $photo = TicketAttachment::query()->sole();
        $this->get(route('media.ticket-attachments.show', $photo))->assertRedirect(route('login'));
        $this->actingAs($this->staff($this->department('Sales')))->get(route('media.ticket-attachments.show', $photo))->assertForbidden();
        $this->actingAs($agent)->get(route('media.ticket-attachments.show', $photo))->assertOk()->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->assertFalse(Storage::disk('public')->exists($photo->file_path), 'ไม่อยู่ใน public disk');
    }

    /* ---------------------------------------------------------------
     * Scenario C — หลาย agent ทำ Ticket เดียวกัน
     * ------------------------------------------------------------- */

    public function test_scenario_c_collaboration_and_conflicts(): void
    {
        [$a, $b, $c] = [$this->agent(), $this->agent(), $this->agent()];
        $ticket = $this->publicTicket();
        $this->act($a, $ticket, 'start');

        // join ซ้ำ / join พร้อมกัน (สองคำขอจากคนเดียว) → แถวเดียว
        $this->actingAs($b)->postJson(route('tickets.participants.store', $ticket))->assertOk();
        $this->actingAs($b)->postJson(route('tickets.participants.store', $ticket))->assertOk()->assertJson(['message' => 'คุณเข้าร่วม Ticket นี้อยู่แล้ว']);
        $this->actingAs($c)->postJson(route('tickets.participants.store', $ticket))->assertOk();
        $this->assertSame(3, TicketParticipant::query()->where('ticket_id', $ticket->id)->count());

        // leave → rejoin: แถวเดิม ไม่เพิ่ม
        $this->actingAs($c)->deleteJson(route('tickets.participants.destroy', $ticket))->assertOk();
        $this->actingAs($c)->postJson(route('tickets.participants.store', $ticket))->assertOk();
        $this->assertSame(3, TicketParticipant::query()->where('ticket_id', $ticket->id)->count());

        // ผู้ประสานงาน: ต้องเป็นผู้เข้าร่วมปัจจุบัน
        $outsider = $this->agent();
        $this->actingAs($a)->patchJson(route('tickets.coordinator.update', $ticket), ['coordinator_id' => $outsider->id])->assertStatus(422);
        $this->actingAs($a)->patchJson(route('tickets.coordinator.update', $ticket), ['coordinator_id' => $b->id])->assertOk();

        // เปลี่ยนสถานะพร้อมกัน: ทั้งคู่เห็น "active" — คนแรกสำเร็จ คนที่สองได้ 409 พร้อมสถานะล่าสุด
        $this->actingAs($a)->patchJson(route('tickets.status.update', $ticket), ['action' => 'follow_up', 'expected_status' => 'active', 'note' => 'รอ'])->assertOk();
        $this->actingAs($b)->patchJson(route('tickets.status.update', $ticket), ['action' => 'close', 'expected_status' => 'active', 'note' => 'จบ'])
            ->assertStatus(409)->assertJson(['status' => 'follow_up']);

        // ปิดพร้อมกัน: ปิดได้ครั้งเดียว
        $this->actingAs($a)->patchJson(route('tickets.status.update', $ticket), ['action' => 'close', 'expected_status' => 'follow_up', 'note' => 'จบ'])->assertOk();
        $this->actingAs($b)->patchJson(route('tickets.status.update', $ticket), ['action' => 'close', 'expected_status' => 'follow_up', 'note' => 'จบเหมือนกัน'])->assertStatus(409);
        $this->assertSame(1, TicketActivity::query()->where('ticket_id', $ticket->id)->where('to_status', 'closed')->count());

        // เปิดใหม่
        $this->actingAs($b)->patchJson(route('tickets.status.update', $ticket), ['action' => 'reopen', 'expected_status' => 'closed', 'note' => 'ยังเสีย'])->assertOk();

        // agent ถูกปิดสิทธิ์ระหว่างทำงาน → ทำ action ไม่ได้ทันที ประวัติยังอยู่
        $this->override($c, enabled: false);
        $this->actingAs($c->fresh())->patchJson(route('tickets.status.update', $ticket), ['action' => 'close', 'expected_status' => 'active', 'note' => 'x'])->assertForbidden();
        $this->actingAs($c->fresh())->postJson(route('tickets.notes.store', $ticket), ['body' => 'x'])->assertForbidden();
        $this->actingAs($c->fresh())->get(route('tickets.show', $ticket))->assertForbidden();
        $this->assertTrue(TicketParticipant::query()->where('ticket_id', $ticket->id)->where('user_id', $c->id)->exists());

        $ticket->refresh();
        $this->assertSame(Ticket::STATUS_ACTIVE, $ticket->status);
        $this->assertNull($ticket->closed_by);
        $this->assertSame(1, $ticket->reopen_count);
    }

    /* ---------------------------------------------------------------
     * Scenario D — ตัวตนทางประวัติใน KPI
     * ------------------------------------------------------------- */

    public function test_scenario_d_historical_identity_in_kpi(): void
    {
        $admin = $this->staff(null, 'admin');
        [$twinA, $twinB] = [$this->agent(), $this->agent()];
        $twinA->update(['name' => 'วิชัย ใจดี']);
        $twinB->update(['name' => 'วิชัย ใจดี']);
        $retired = $this->agent();
        $gone = $this->agent();

        foreach ([$twinA, $twinB, $twinB, $retired, $gone, $gone] as $index => $agent) {
            $ticket = $this->startedBy($agent);
            if ($index % 2 === 1) {
                $this->transition($ticket, Ticket::ACTION_CLOSE, $agent, ['note' => 'จบ']);
            }
        }

        $retired->delete();       // soft delete
        $gone->forceDelete();     // ลบถาวร (เคยปิด Ticket)

        $report = $this->actingAs($admin)->get(route('reports.tickets'))->assertOk();
        $people = collect($report->viewData('people')->items())->keyBy('key');

        $this->assertSame(6, $report->viewData('total'));
        $this->assertSame([1, 0], [$people['user:'.$twinA->id]['participated'], $people['user:'.$twinA->id]['closed']]);
        $this->assertSame([2, 1], [$people['user:'.$twinB->id]['participated'], $people['user:'.$twinB->id]['closed']], 'ชื่อซ้ำไม่ถูกรวม');
        $this->assertSame('ลบบัญชีแล้ว', $people['user:'.$retired->id]['state']);
        $this->assertSame(['บัญชีถูกลบถาวร', 2, 1], [$people['user:'.$gone->id]['state'], $people['user:'.$gone->id]['participated'], $people['user:'.$gone->id]['closed']]);

        // เลือกคนที่ลบถาวรในตัวกรองได้
        $filtered = $this->actingAs($admin)->get(route('reports.tickets', ['agent' => $gone->id]))->assertOk();
        $this->assertSame(2, $filtered->viewData('total'));
        $filtered->assertSee('value="'.$gone->id.'" selected', false);
    }

    /* ---------------------------------------------------------------
     * Scenario E — การแจ้งเตือน
     * ------------------------------------------------------------- */

    public function test_scenario_e_notifications_reach_only_allowed_people_once(): void
    {
        $agent = $this->linkedAgent(9301);
        $muted = $this->linkedAgent(9302);
        $this->override($muted, enabled: true, alerts: false);
        $viewer = $this->staff($this->department('Sales'), 'viewer');
        $viewer->forceFill(['telegram_chat_id' => 9303])->save();
        $employee = $this->staff($this->department('Sales'));

        $this->post(route('support.store'), $form = $this->publicForm())->assertRedirect();
        $this->post(route('support.store'), $form); // ซ้ำ
        $ticket = Ticket::query()->sole();

        // ในระบบ: agent ที่รับแจ้งเตือนเท่านั้น ครั้งเดียว · Telegram: แถวเดียว ส่งครั้งเดียว
        $this->assertSame([$agent->id], $this->notified('ticket_created'));
        $this->assertSame([9301], TelegramMessage::query()->pluck('chat_id')->all());
        Http::assertSentCount(1);
        $this->assertStringNotContainsString('203.0.113', (string) TelegramMessage::query()->value('text'));

        // เสียง: layout โหลดตัวเล่นเสียงเฉพาะ agent ที่รับแจ้งเตือน (การเล่นจริงตรวจในเบราว์เซอร์/JS test)
        $this->actingAs($agent)->get(route('mytasks.index'))->assertSee('ticket-alert', false);
        $this->actingAs($agent)->get(route('tickets.index'))->assertSee('data-ticket-sound-toggle', false);
        $this->actingAs($muted)->get(route('mytasks.index'))->assertDontSee('ticket-alert', false);
        $this->actingAs($employee)->get(route('mytasks.index'))->assertDontSee('ticket-alert', false);

        // ลิงก์ในแจ้งเตือนตรวจสิทธิ์ตอนเปิด — หมดสิทธิ์แล้วไม่ถูกพาเข้า Ticket
        $notice = SystemNotification::query()->where('user_id', $agent->id)->sole();
        $this->actingAs($agent)->get(route('notifications.open', $notice->id))->assertRedirect(route('tickets.show', $ticket));
        $this->override($agent, enabled: false);
        $this->actingAs($agent->fresh())->get(route('notifications.open', $notice->id))->assertRedirect(route('notifications.index'));
        $this->actingAs($employee)->get(route('notifications.open', $notice->id))->assertNotFound();
    }

    /* ---------------------------------------------------------------
     * helpers
     * ------------------------------------------------------------- */

    private function linkedAgent(int $chatId): User
    {
        $agent = $this->agent();
        $agent->forceFill(['telegram_chat_id' => $chatId, 'telegram_notifications_enabled' => true])->save();

        return $agent;
    }

    private function act(User $actor, Ticket $ticket, string $action, array $extra = []): TestResponse
    {
        return $this->actingAs($actor)->from(route('tickets.show', $ticket))->patch(route('tickets.status.update', $ticket), [
            'action' => $action,
            'expected_status' => $ticket->fresh()->status,
            ...$extra,
        ]);
    }

    /** @return array<int, int> */
    private function notified(string $type): array
    {
        return SystemNotification::query()->where('type', $type)->pluck('user_id')->map(fn ($id) => (int) $id)->unique()->values()->all();
    }
}
