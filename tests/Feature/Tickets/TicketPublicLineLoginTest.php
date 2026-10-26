<?php

namespace Tests\Feature\Tickets;

use App\Models\Ticket;
use App\Services\LineLogin;
use App\Services\TicketSubmissionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * ฟอร์มแจ้งปัญหาสาธารณะ + LINE Login (LineLogin) — หนึ่งบัญชี LINE มี Ticket ที่ยังไม่ปิดได้ใบเดียว
 * ไม่เรียก LINE จริง (Http::fake) ตัวตนอยู่ใน session ของผู้แจ้งเท่านั้น
 */
class TicketPublicLineLoginTest extends TestCase
{
    use BuildsTickets;
    use BuildsTicketUploads;
    use RefreshDatabase;

    private const LINE = ['user_id' => 'U1234567890abcdef1234567890abcdef', 'name' => 'สมหญิง LINE'];

    protected function setUp(): void
    {
        parent::setUp();

        // production: บังคับ LINE เสมอ (phpunit.xml ปิดไว้ให้เทสต์อื่น — ที่นี่เปิดกลับ)
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

    public function test_without_line_login_the_page_asks_to_log_in_and_shows_no_form(): void
    {
        $this->get(route('support.create'))->assertOk()
            ->assertViewIs('tickets.public.line-login')
            ->assertSee('เข้าสู่ระบบด้วย LINE')
            ->assertSee('href="'.route('support.line.redirect').'"', false)
            ->assertDontSee('name="description"', false);

        // ส่งตรงโดยไม่ยืนยันตัว → ไม่สร้าง Ticket
        $this->post(route('support.store'), $this->publicForm())->assertSessionHasErrors(['form' => 'กรุณาเข้าสู่ระบบด้วย LINE ก่อนส่งแจ้งปัญหา']);
        $this->assertSame(0, Ticket::count());
    }

    public function test_development_mode_without_line_keeps_the_plain_form(): void
    {
        // เฉพาะเครื่องพัฒนา (require_line = false) — production ดู TicketPublicLineSecurityTest
        config(['tickets.public.require_line' => false, 'services.line_login.channel_id' => null, 'services.line_login.channel_secret' => null]);

        $this->get(route('support.create'))->assertOk()->assertViewIs('tickets.public.create')->assertDontSee('ticket-public__line-account', false);
        $this->get(route('support.line.redirect'))->assertNotFound();
        $this->post(route('support.store'), $this->publicForm())->assertRedirect(route('support.submitted'));
        $this->assertNull(Ticket::query()->sole()->line_user_id);
    }

    public function test_the_redirect_sends_state_and_nonce_and_the_callback_checks_them(): void
    {
        Http::fake([
            'api.line.me/oauth2/v2.1/token' => Http::response(['id_token' => 'id.token.value', 'access_token' => 'x']),
            'api.line.me/oauth2/v2.1/verify' => Http::response(['sub' => self::LINE['user_id'], 'name' => self::LINE['name']]),
        ]);

        $redirect = $this->get(route('support.line.redirect'));
        $location = $redirect->headers->get('Location');
        $this->assertStringStartsWith('https://access.line.me/oauth2/v2.1/authorize?', $location);
        parse_str((string) parse_url($location, PHP_URL_QUERY), $query);
        $this->assertSame('1650000000', $query['client_id']);
        $this->assertSame(route('support.line.callback'), $query['redirect_uri']);
        $this->assertSame('profile openid', $query['scope']);
        $this->assertSame(40, strlen($query['state']));

        // state ไม่ตรง → ไม่ยืนยันตัว และไม่เรียก LINE
        $this->get(route('support.line.callback', ['state' => 'wrong', 'code' => 'abc']))
            ->assertRedirect(route('support.create'))->assertSessionHasErrors('line');
        $this->assertNull(session(LineLogin::SESSION_KEY));
        Http::assertNothingSent();

        // รอบใหม่ที่ถูกต้อง: แลก code → ตรวจ ID token พร้อม nonce ของรอบนี้ → เก็บตัวตนใน session ใหม่
        $location = $this->get(route('support.line.redirect'))->headers->get('Location');
        parse_str((string) parse_url($location, PHP_URL_QUERY), $query);
        $before = session()->getId();

        $this->get(route('support.line.callback', ['state' => $query['state'], 'code' => 'auth-code']))
            ->assertRedirect(route('support.create'))->assertSessionHasNoErrors();

        $this->assertSame(self::LINE, session(LineLogin::SESSION_KEY));
        $this->assertNotSame($before, session()->getId(), 'session ID ถูกออกใหม่หลังยืนยันตัว');
        Http::assertSent(fn (HttpRequest $request) => str_ends_with($request->url(), '/token')
            && $request['code'] === 'auth-code' && $request['client_secret'] === 'test-secret');
        Http::assertSent(fn (HttpRequest $request) => str_ends_with($request->url(), '/verify')
            && $request['nonce'] === $query['nonce'] && $request['client_id'] === '1650000000');

        // ใช้ state เดิมซ้ำไม่ได้
        $this->get(route('support.line.callback', ['state' => $query['state'], 'code' => 'again']))->assertSessionHasErrors('line');
    }

    public function test_a_failed_verification_or_a_cancel_does_not_log_in(): void
    {
        Http::fake(['api.line.me/oauth2/v2.1/token' => Http::response(['error' => 'invalid_grant'], 400)]);

        $location = $this->get(route('support.line.redirect'))->headers->get('Location');
        parse_str((string) parse_url($location, PHP_URL_QUERY), $query);
        $this->get(route('support.line.callback', ['state' => $query['state'], 'code' => 'bad']))
            ->assertSessionHasErrors(['line' => 'เข้าสู่ระบบด้วย LINE ไม่สำเร็จ กรุณาลองอีกครั้ง']);
        $this->assertNull(session(LineLogin::SESSION_KEY));

        $location = $this->get(route('support.line.redirect'))->headers->get('Location');
        parse_str((string) parse_url($location, PHP_URL_QUERY), $query);
        $this->get(route('support.line.callback', ['state' => $query['state'], 'error' => 'access_denied']))
            ->assertSessionHasErrors('line');
        $this->get(route('support.create'))->assertViewIs('tickets.public.line-login')->assertSee('ยกเลิกการเข้าสู่ระบบด้วย LINE แล้ว');
    }

    public function test_a_logged_in_reporter_gets_the_form_prefilled_without_being_told_which_account(): void
    {
        $html = $this->asLine()->get(route('support.create'))->assertOk()
            ->assertViewIs('tickets.public.create')
            // ชื่อใน LINE ตั้งต้นให้ช่อง "ชื่อผู้แจ้ง" เท่านั้น
            ->assertSee('value="'.self::LINE['name'].'"', false)
            ->getContent();

        // ไม่มีแถบบอกว่าเข้าสู่ระบบด้วยบัญชีใด ไม่มีปุ่มเปลี่ยนบัญชี และไม่มี userId ของ LINE ในหน้า
        $this->assertStringNotContainsString('ticket-public__line-account', $html);
        $this->assertStringNotContainsString('เข้าสู่ระบบด้วย LINE', $html);
        $this->assertStringNotContainsString('เปลี่ยนบัญชี', $html);
        $this->assertStringNotContainsString(self::LINE['user_id'], $html);
    }

    public function test_one_line_account_has_one_open_ticket_until_staff_close_it(): void
    {
        $this->asLine()->post(route('support.store'), $this->publicForm())->assertRedirect(route('support.submitted'));
        $first = Ticket::query()->sole();
        $this->assertSame(self::LINE['user_id'], $first->line_user_id);
        $this->assertSame(self::LINE['name'], $first->line_display_name);

        // รอรับงาน / กำลังดำเนินการ / งานที่ต้องติดตาม → ยังเปิดใหม่ไม่ได้ หน้าแสดงสถานะใบเดิมแทนฟอร์ม
        $agent = $this->agent();
        foreach ([
            fn () => $first,
            fn () => $this->startedBy($agent, $first),
            fn () => $this->transition($first->fresh(), Ticket::ACTION_FOLLOW_UP, $agent, ['note' => 'รออะไหล่', 'follow_up_date' => now('Asia/Bangkok')->addDay()->toDateString()]),
        ] as $i => $step) {
            $ticket = $step()->fresh();
            $this->asLine()->get(route('support.create'))->assertOk()
                ->assertViewIs('tickets.public.open-ticket')
                ->assertSee($ticket->ticket_number)
                ->assertDontSee('name="description"', false);

            $this->asLine()->withServerVariables(['REMOTE_ADDR' => '10.1.0.'.$i])
                ->post(route('support.store'), $this->publicForm(['description' => 'เรื่องใหม่ระหว่างที่เรื่องเดิมยังไม่ปิด']))
                ->assertSessionHasErrors(['form' => TicketSubmissionService::openTicketMessage($ticket)]);
            $this->assertSame(1, Ticket::count(), $ticket->status);
        }

        // บัญชี LINE อื่นไม่ถูกกระทบ
        $other = ['user_id' => 'Uffffffffffffffffffffffffffffffff', 'name' => 'อีกคน'];
        $this->asLine($other)->withServerVariables(['REMOTE_ADDR' => '10.2.0.1'])
            ->post(route('support.store'), $this->publicForm())->assertRedirect(route('support.submitted'));
        $this->assertSame(2, Ticket::count());

        // เจ้าหน้าที่ปิดงานแล้ว → แจ้งเรื่องใหม่ได้
        $this->transition($first->fresh(), Ticket::ACTION_RESUME, $agent);
        $this->transition($first->fresh(), Ticket::ACTION_CLOSE, $agent, ['note' => 'แก้ไขแล้ว']);
        $this->asLine()->get(route('support.create'))->assertViewIs('tickets.public.create');
        $this->asLine()->withServerVariables(['REMOTE_ADDR' => '10.3.0.1'])
            ->post(route('support.store'), $this->publicForm())->assertRedirect(route('support.submitted'));
        $this->assertSame(2, Ticket::query()->where('line_user_id', self::LINE['user_id'])->count());
    }

    public function test_the_line_id_is_never_rendered_for_staff_but_the_line_name_and_contacts_are(): void
    {
        $this->asLine()->post(route('support.store'), $this->publicForm(['remote_access' => '123 456 789']));
        $ticket = Ticket::query()->sole();
        $agent = $this->agent();

        foreach ([route('tickets.show', $ticket), route('tickets.preview', $ticket)] as $url) {
            $this->actingAs($agent)->get($url)->assertOk()
                ->assertSee('<a href="tel:0812345678">0812345678</a>', false)
                ->assertSee('123 456 789')
                ->assertSee(self::LINE['name'])
                ->assertDontSee(self::LINE['user_id']);
        }

        $this->assertArrayNotHasKey('line_user_id', $ticket->toArray());
    }
}
