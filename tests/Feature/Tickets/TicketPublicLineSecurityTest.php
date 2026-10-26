<?php

namespace Tests\Feature\Tickets;

use App\Models\Ticket;
use App\Models\TicketAttachment;
use App\Services\LineLogin;
use App\Services\TicketNotificationService;
use App\Services\TicketPhotoStore;
use App\Services\TicketSubmissionService;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Illuminate\Validation\ValidationException;
use Mockery;
use Tests\TestCase;

/**
 * /support ผูก LINE แบบ production (tickets.public.require_line = true) — ความปลอดภัยของตัวตนและกติกา
 * "หนึ่งบัญชี LINE หนึ่งเคสที่ยังไม่ปิด" ทั้งตอนสร้าง ตอนเปิดงานใหม่ และตอนส่งพร้อมกัน
 *
 * ทุกการเรียก LINE เป็นของจำลอง (Http::fake) — ไม่ได้พิสูจน์การเชื่อมต่อ LINE จริง
 */
class TicketPublicLineSecurityTest extends TestCase
{
    use BuildsTickets;
    use BuildsTicketUploads;
    use RefreshDatabase;

    private const LINE = ['user_id' => 'U1234567890abcdef1234567890abcdef', 'name' => 'สมหญิง LINE'];

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'tickets.public.require_line' => true,
            'services.line_login.channel_id' => '1650000000',
            'services.line_login.channel_secret' => 'test-secret',
        ]);
    }

    private function asLine(array $line = self::LINE): static
    {
        return $this->withSession([LineLogin::SESSION_KEY => $line]);
    }

    /** เริ่มรอบ LINE Login แล้วคืน query ที่ส่งไป LINE (state, nonce, ...) */
    private function startLogin(): array
    {
        parse_str((string) parse_url($this->get(route('support.line.redirect'))->headers->get('Location'), PHP_URL_QUERY), $query);

        return $query;
    }

    private function fromIp(string $ip): static
    {
        return $this->withServerVariables(['REMOTE_ADDR' => $ip]);
    }

    public function test_required_line_without_configuration_closes_the_service_instead_of_falling_back(): void
    {
        config(['services.line_login.channel_id' => null, 'services.line_login.channel_secret' => null]);

        $this->get(route('support.create'))->assertStatus(503)
            ->assertViewIs('tickets.public.unavailable')
            ->assertSee('ปิดให้บริการชั่วคราว')
            ->assertDontSee('name="description"', false);
        $this->get(route('support.line.redirect'))->assertNotFound();

        // ส่งตรงไม่ได้ ทั้งที่ไม่มีและมีตัวตน LINE เก่าค้างใน session
        $this->post(route('support.store'), $this->publicForm())->assertSessionHasErrors(['form' => TicketSubmissionService::SERVICE_UNAVAILABLE]);
        $this->asLine()->post(route('support.store'), $this->publicForm())->assertSessionHasErrors(['form' => TicketSubmissionService::SERVICE_UNAVAILABLE]);
        $this->assertSame(0, Ticket::count());

        // ชั้น service ปฏิเสธเองด้วย (ผู้เรียกที่ข้าม Form Request)
        $this->expectException(ValidationException::class);
        app(TicketSubmissionService::class)->submitPublic($this->publicForm(), '10.0.0.1', [], self::LINE);
    }

    public function test_the_service_refuses_a_public_ticket_without_a_verified_line_identity(): void
    {
        try {
            app(TicketSubmissionService::class)->submitPublic($this->publicForm(), '10.0.0.1');
            $this->fail('a public ticket without LINE was accepted');
        } catch (ValidationException $e) {
            $this->assertSame([TicketSubmissionService::LINE_REQUIRED], $e->errors()['form']);
        }
        $this->assertSame(0, Ticket::count());
    }

    public function test_an_expired_or_missing_login_round_is_refused_without_calling_line(): void
    {
        Http::fake();

        // ไม่เคยเริ่มรอบ หรือ session หมดอายุระหว่างไป LINE → ไม่มี state ใน session
        $this->get(route('support.line.callback', ['state' => str_repeat('a', 40), 'code' => 'abc']))
            ->assertRedirect(route('support.create'))
            ->assertSessionHasErrors(['line' => 'เข้าสู่ระบบด้วย LINE ไม่สำเร็จ กรุณาลองอีกครั้ง']);

        // state ถูกแต่ไม่มี code
        $query = $this->startLogin();
        $this->get(route('support.line.callback', ['state' => $query['state']]))->assertSessionHasErrors('line');

        Http::assertNothingSent();
        $this->assertNull(session(LineLogin::SESSION_KEY));
    }

    public function test_an_expired_session_must_log_in_again_before_sending(): void
    {
        // session หมดอายุ = ไม่มีตัวตน LINE แล้ว — ฟอร์มที่เปิดค้างไว้ส่งไม่ได้
        $this->post(route('support.store'), $this->publicForm())
            ->assertSessionHasErrors(['form' => TicketSubmissionService::LINE_REQUIRED]);
        $this->get(route('support.create'))->assertViewIs('tickets.public.line-login');
        $this->assertSame(0, Ticket::count());
    }

    public function test_the_line_identity_comes_only_from_the_verified_session_never_from_form_or_query(): void
    {
        $spoof = ['line_user_id' => 'Uattacker000000000000000000000000', 'line_display_name' => 'ปลอม'];
        $url = route('support.store').'?'.http_build_query($spoof);

        // ไม่มี session → แนบ userId มาเองก็ส่งไม่ได้
        $this->post($url, $this->publicForm($spoof))->assertSessionHasErrors(['form' => TicketSubmissionService::LINE_REQUIRED]);
        $this->assertSame(0, Ticket::count());

        // มี session ของ A → Ticket ผูกกับ A เสมอ ค่าที่แนบมาถูกทิ้ง
        $this->asLine()->fromIp('10.1.0.1')->post($url, $this->publicForm($spoof))->assertRedirect(route('support.submitted'));
        $ticket = Ticket::query()->sole();
        $this->assertSame(self::LINE['user_id'], $ticket->line_user_id);
        $this->assertSame(self::LINE['name'], $ticket->line_display_name);
    }

    public function test_an_open_ticket_offers_no_way_to_switch_line_account(): void
    {
        $this->asLine()->fromIp('10.2.0.1')->post(route('support.store'), $this->publicForm())->assertRedirect(route('support.submitted'));

        // หน้า "มีเรื่องที่ยังไม่ปิด" ไม่มีทางออกไปล็อกอินบัญชีอื่น — ไม่งั้นกติกาหนึ่งบัญชีหนึ่งเคสที่ยังไม่ปิดถูกข้ามได้
        $html = $this->asLine()->get(route('support.create'))->assertOk()
            ->assertViewIs('tickets.public.open-ticket')->getContent();
        $this->assertStringNotContainsString('เปลี่ยนบัญชี', $html);
        $this->assertStringNotContainsString(route('support.line.redirect'), $html);

        // ไม่มี endpoint ล้างตัวตนให้เรียกตรง ๆ ด้วย
        $this->assertFalse(Route::has('support.line.logout'));
        $this->asLine()->post('/support/line/logout')->assertNotFound();
        $this->assertSame(1, Ticket::count());
    }

    public function test_line_api_outages_fail_closed_with_a_message(): void
    {
        foreach ([
            fn () => throw new ConnectionException('timeout'),
            fn () => Http::response('upstream error', 500),
        ] as $response) {
            Http::fake(['api.line.me/*' => $response]);

            $query = $this->startLogin();
            $this->get(route('support.line.callback', ['state' => $query['state'], 'code' => 'c']))
                ->assertRedirect(route('support.create'))
                ->assertSessionHasErrors(['line' => 'เข้าสู่ระบบด้วย LINE ไม่สำเร็จ กรุณาลองอีกครั้ง']);
            $this->assertNull(session(LineLogin::SESSION_KEY));
        }

        // แลก token ได้ แต่ LINE ปฏิเสธ ID token (nonce ไม่ตรง / หมดอายุ)
        Http::fake([
            'api.line.me/oauth2/v2.1/token' => Http::response(['id_token' => 'id.token.value']),
            'api.line.me/oauth2/v2.1/verify' => Http::response(['error' => 'invalid_request', 'error_description' => 'Invalid IdToken Nonce.'], 400),
        ]);
        $query = $this->startLogin();
        $this->get(route('support.line.callback', ['state' => $query['state'], 'code' => 'c']))->assertSessionHasErrors('line');
        $this->assertNull(session(LineLogin::SESSION_KEY));
        $this->get(route('support.create'))->assertViewIs('tickets.public.line-login');
    }

    public function test_reopening_an_old_ticket_is_refused_while_the_same_line_account_has_another_open_one(): void
    {
        $agent = $this->agent();
        $this->asLine()->post(route('support.store'), $this->publicForm());
        $old = Ticket::query()->sole();
        $this->transition($this->startedBy($agent, $old), Ticket::ACTION_CLOSE, $agent, ['note' => 'จบ']);

        $this->asLine()->fromIp('10.3.0.1')
            ->post(route('support.store'), $this->publicForm(['description' => 'ปัญหาเรื่องที่สองหลังปิดเรื่องแรก']))
            ->assertRedirect(route('support.submitted'));
        $current = Ticket::query()->whereKeyNot($old->id)->sole();

        // เปิดใบเก่าใหม่ไม่ได้ (AJAX และ form ปกติ) เพราะจะมีสองเคสเปิดพร้อมกัน — ข้อความบอกใบที่เปิดอยู่
        $reopen = ['action' => 'reopen', 'expected_status' => 'closed', 'note' => 'อาการกลับมา'];
        $this->actingAs($agent)->patchJson(route('tickets.status.update', $old), $reopen)
            ->assertStatus(422)
            ->assertJsonPath('errors.status.0', fn (string $message) => str_contains($message, $current->ticket_number));
        $this->actingAs($agent)->from(route('tickets.show', $old))
            ->patch(route('tickets.status.update', $old), $reopen)
            ->assertRedirect(route('tickets.show', $old))->assertSessionHasErrors('status');
        $this->actingAs($agent)->get(route('tickets.show', $old))->assertSee($current->ticket_number);
        $this->assertSame(Ticket::STATUS_CLOSED, $old->fresh()->status);

        // ใบปัจจุบันปิดแล้ว → เปิดใบเก่าใหม่ได้ · หลังเปิดใหม่ ผู้แจ้งแจ้งเรื่องใหม่ไม่ได้จนกว่าจะปิดอีกครั้ง
        $this->transition($this->startedBy($agent, $current), Ticket::ACTION_CLOSE, $agent, ['note' => 'จบ']);
        $this->transition($old->fresh(), Ticket::ACTION_REOPEN, $agent, ['note' => 'อาการกลับมา']);
        $this->assertSame(Ticket::STATUS_ACTIVE, $old->fresh()->status);
        $this->asLine()->get(route('support.create'))->assertViewIs('tickets.public.open-ticket')->assertSee($old->ticket_number);
    }

    public function test_the_database_allows_only_one_open_ticket_per_line_account(): void
    {
        $this->asLine()->post(route('support.store'), $this->publicForm());
        $first = Ticket::query()->sole();

        // ด่านสุดท้ายคือ unique index บนคอลัมน์คำนวณ — แม้เขียนข้ามทุก service ก็ใส่ใบเปิดใบที่สองไม่ได้
        $row = collect(DB::table('tickets')->where('id', $first->id)->first())
            ->except(['id', 'ticket_number', 'open_line_user_id', 'open_requester_id'])->all();
        try {
            DB::table('tickets')->insert($row);
            $this->fail('a second open ticket for the same LINE account was stored');
        } catch (UniqueConstraintViolationException) {
            $this->assertSame(1, Ticket::count());
        }

        // ใบที่ปิดแล้วไม่นับ และใบปิดหลายใบของบัญชีเดียวกันอยู่ร่วมกันได้
        DB::table('tickets')->where('id', $first->id)->update(['status' => 'closed']);
        DB::table('tickets')->insert([...$row, 'status' => 'closed']);
        DB::table('tickets')->insert($row);
        $this->assertSame(1, Ticket::query()->where('line_user_id', self::LINE['user_id'])->where('status', '!=', 'closed')->count());

        // Ticket ภายในและ Ticket ที่ไม่ผูก LINE (NULL) ไม่ชนกัน
        $this->ticketFrom($this->staff($this->department('Sales')));
        $this->ticketFrom($this->staff($this->department('Sales')));
        $this->assertSame(2, Ticket::query()->whereNull('line_user_id')->count());
    }

    public function test_two_tabs_racing_past_the_check_end_with_one_ticket_and_a_clear_message(): void
    {
        $this->asLine()->post(route('support.store'), $this->publicForm());
        $this->assertSame(1, Ticket::count());

        // จำลองแท็บที่สองที่ตรวจ "ยังไม่มีเคสเปิด" ก่อนแท็บแรกบันทึกเสร็จ: การตรวจครั้งแรกตอบว่าไม่มี
        // การบันทึกจึงชน unique index → ได้ข้อความเดียวกับกรณีปกติ ไม่ใช่ 500 และไม่มีใบที่สองหรือรูปค้าง
        $calls = 0;
        $service = Mockery::mock(TicketSubmissionService::class, [
            app(TicketPhotoStore::class), app(TicketNotificationService::class), app(LineLogin::class),
        ])->makePartial();
        $service->shouldReceive('openTicketForLine')->andReturnUsing(function (string $id) use (&$calls) {
            return $calls++ === 0
                ? null
                : Ticket::query()->where('line_user_id', $id)->where('status', '!=', 'closed')->first();
        });
        $this->app->instance(TicketSubmissionService::class, $service);

        $this->asLine()->fromIp('10.4.0.1')
            ->post(route('support.store'), $this->publicForm([
                'description' => 'แท็บที่สองส่งพร้อมกัน',
                'photos' => [$this->photo('a.jpg')],
            ]))
            ->assertSessionHasErrors(['form' => TicketSubmissionService::openTicketMessage(Ticket::query()->sole())]);
        $this->assertSame(1, Ticket::count());
        $this->assertSame(0, TicketAttachment::count());
    }
}
