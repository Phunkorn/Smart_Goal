<?php

namespace Tests\Feature\Tickets;

use App\Models\Ticket;
use App\Models\TicketAttachment;
use App\Support\TicketSubmissionGuard;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\MessageBag;
use Illuminate\Support\ViewErrorBag;
use Tests\TestCase;

/**
 * ฟอร์มแจ้งปัญหาสาธารณะ /support — ไม่ต้องเข้าสู่ระบบ
 */
class TicketPublicSubmissionTest extends TestCase
{
    use BuildsTickets;
    use BuildsTicketUploads;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
    }

    public function test_the_form_opens_without_login_and_shows_only_active_locations(): void
    {
        $this->location('สาขาบางนา');
        $this->location('สาขาที่ปิดแล้ว', active: false);
        $insider = $this->staff($this->department('ฝ่ายลับ'));

        $this->get(route('support.create'))
            ->assertOk()
            ->assertSee('แจ้งปัญหา IT')
            ->assertSee('สาขาบางนา')
            ->assertSee('อื่น ๆ (ระบุเอง)')
            ->assertDontSee('สาขาที่ปิดแล้ว')
            // ไม่มีข้อมูลภายในระบบ: ไม่มีชื่อผู้ใช้ แผนก หรือลิงก์ภายใน
            ->assertDontSee($insider->name)
            ->assertDontSee('ฝ่ายลับ')
            ->assertDontSee(route('tickets.create'))
            ->assertSee('name="'.TicketSubmissionGuard::TOKEN_FIELD.'"', false)
            ->assertSee('name="'.TicketSubmissionGuard::HONEYPOT_FIELD.'"', false);
    }

    public function test_without_any_locations_the_form_asks_for_a_typed_location(): void
    {
        $this->get(route('support.create'))
            ->assertOk()
            ->assertDontSee('name="location_id"', false)
            ->assertSee('name="location_name"', false);
    }

    public function test_name_location_description_and_phone_are_required(): void
    {
        $this->post(route('support.store'), $this->publicForm())
            ->assertRedirect(route('support.submitted'));

        $ticket = Ticket::query()->sole();
        $this->assertSame(Ticket::SOURCE_PUBLIC, $ticket->source);
        $this->assertSame('สมชาย ใจดี', $ticket->requester_name);
        $this->assertSame('สาขาบางนา', $ticket->location_name);
        $this->assertSame('0812345678', $ticket->contact);
        $this->assertNull($ticket->remote_access);
        $this->assertNull($ticket->line_user_id, 'LINE ไม่ได้ตั้งค่า → ไม่ผูก LINE');
        $this->assertNull($ticket->requester_id);
        $this->assertNull($ticket->requester_department_id);
        $this->assertSame(0, $ticket->attachments()->count());
    }

    public function test_each_required_field_is_enforced(): void
    {
        foreach (['requester_name', 'description', 'contact'] as $field) {
            $this->post(route('support.store'), $this->publicForm([$field => '']))
                ->assertSessionHasErrors($field);
        }

        $this->post(route('support.store'), $this->publicForm(['location_id' => 'other', 'location_name' => '']))
            ->assertSessionHasErrors('location_name');

        $this->assertSame(0, Ticket::count());
    }

    public function test_phone_remote_id_and_photos_are_saved_when_given(): void
    {
        $location = $this->location('สาขาลาดพร้าว');

        $this->post(route('support.store'), $this->publicForm([
            'location_id' => (string) $location->id,
            'location_name' => '',
            'contact' => '081-234-5678',
            'remote_access' => ' 123 456 789 ',
            'photos' => [$this->photo('a.jpg'), $this->photo('b.png')],
        ]))->assertRedirect(route('support.submitted'));

        $ticket = Ticket::query()->sole();
        // ขีด/ช่องว่างถูกตัดเหลือตัวเลข · รหัสรีโมทเก็บตามที่พิมพ์ (ตัดช่องว่างหัวท้าย)
        $this->assertSame('0812345678', $ticket->contact);
        $this->assertSame('123 456 789', $ticket->remote_access);
        $this->assertSame($location->id, $ticket->location_id);
        $this->assertSame('สาขาลาดพร้าว', $ticket->location_name);
        $this->assertSame(2, $ticket->attachments()->count());
        $this->assertNull($ticket->attachments()->first()->uploaded_by);
    }

    public function test_the_confirmation_shows_this_sessions_own_summary_and_nothing_else(): void
    {
        $this->get(route('support.submitted'))->assertRedirect(route('support.create'));

        $this->post(route('support.store'), $this->publicForm());
        $ticket = Ticket::query()->sole();

        $this->get(route('support.submitted'))
            ->assertOk()
            ->assertSee('ส่งข้อมูลสำเร็จ')
            ->assertSee('ทีม IT ได้รับข้อมูลของคุณแล้ว')
            ->assertSee($ticket->ticket_number)
            ->assertSee('สมชาย ใจดี')
            ->assertSee('สาขาบางนา')
            ->assertSee('src="'.asset('images/premiuum-care-logo.png').'"', false)
            ->assertSee('href="'.route('support.create').'"', false)
            ->assertDontSee($ticket->description)
            ->assertDontSee('ติดต่อทีม IT เพิ่มเติม')
            ->assertDontSee('ติดตามสถานะ');

        // refresh แล้วยังเห็นสรุปเดิม เพราะอยู่ใน session ของผู้ส่งเอง
        $this->get(route('support.submitted'))->assertOk()->assertSee($ticket->ticket_number)->assertSee('สาขาบางนา');
    }

    public function test_the_confirmation_number_comes_from_the_database_and_escapes_what_was_typed(): void
    {
        $this->post(route('support.store'), $this->publicForm(['requester_name' => '<b>x</b>', 'location_name' => '<script>alert(1)</script>']));
        $ticket = Ticket::query()->sole();

        $html = $this->get(route('support.submitted'))->assertOk()->getContent();

        $this->assertMatchesRegularExpression('~data-ticket-number>'.preg_quote($ticket->ticket_number, '~').'</dd>~', $html);
        $this->assertStringNotContainsString('<script>alert(1)</script>', $html);
        $this->assertStringNotContainsString('<b>x</b>', $html);
    }

    public function test_the_form_is_two_steps_with_the_reference_fields_and_labels(): void
    {
        $html = $this->get(route('support.create'))->assertOk()->getContent();

        foreach ([
            'data-ticket-stepper', 'data-ticket-step="1"', 'data-ticket-step="2"', 'data-ticket-step-indicator="1"', 'data-ticket-step-indicator="2"',
            'data-ticket-step-back', 'data-ticket-step-next', 'data-ticket-step-submit',
            'ข้อมูลและปัญหา', 'รายละเอียดเพิ่มเติม', 'รายละเอียดปัญหา', 'เบอร์โทรติดต่อกลับ', 'ถัดไป', 'ย้อนกลับ', 'ส่งแจ้งปัญหา',
            'placeholder="กรอกชื่อผู้แจ้ง"', 'placeholder="0812345678"',
            'placeholder="เช่น เครื่องพิมพ์ชั้น 2 พิมพ์ไม่ออก มีไฟสีแดงกะพริบ"', 'บอกอาการ ตำแหน่งเครื่อง และสิ่งที่ลองทำไปแล้ว',
            // หัวเรื่องของแต่ละขั้น (สำหรับ screen reader และโฟกัสเมื่อเปลี่ยนขั้น) — แถบคำแนะนำของขั้น (ticket-step__note) ถูกถอดออกตามดีไซน์ปัจจุบัน
            'id="ticket-step-1-title" tabindex="-1">ขั้นที่ 1 ข้อมูลและปัญหา</h2>', 'id="ticket-step-2-title" tabindex="-1">ขั้นที่ 2 รายละเอียดเพิ่มเติม</h2>',
            'src="'.asset('images/premiuum-care-logo.png').'"', 'Smart Ticket',
        ] as $expected) {
            $this->assertStringContainsString($expected, $html, $expected);
        }

        $this->assertStringNotContainsString('ticket-step__note', $html, 'ไม่มีแถบคำแนะนำของขั้นแล้ว');

        // ขั้นที่ 1: ชื่อ สาขา รายละเอียดปัญหา · ขั้นที่ 2: เบอร์โทร รหัสรีโมท รูปภาพ — ฟอร์มเดียวส่งไป support.store
        $this->assertSame(1, substr_count($html, '<form '));
        $stepTwo = strpos($html, '<section class="ticket-step" data-ticket-step="2"');
        $this->assertNotFalse($stepTwo);
        foreach (['name="requester_name"', 'name="location_id"', 'id="ticket-description"'] as $field) {
            $this->assertLessThan($stepTwo, strpos($html, $field), $field.' อยู่ขั้นที่ 1');
        }
        foreach (['id="ticket-contact"', 'name="photos[]"'] as $field) {
            $this->assertGreaterThan($stepTwo, strpos($html, $field), $field.' อยู่ขั้นที่ 2');
        }
        $this->assertLessThan(strpos($html, 'name="photos[]"'), strpos($html, 'id="ticket-contact"'), 'ช่องทางติดต่อมาก่อนรูปในขั้นที่ 2');
        $this->assertStringContainsString('action="'.route('support.store').'"', $html);
        // สถานะเริ่มต้นตรงกับขั้นที่ 1: ถัดไปแสดง ส่ง/ย้อนกลับซ่อน
        $this->assertMatchesRegularExpression('~data-ticket-step-back\s+hidden~', $html);
        $this->assertMatchesRegularExpression('~data-ticket-step-submit\s+hidden~', $html);
        $this->assertDoesNotMatchRegularExpression('~data-ticket-step-next\s+hidden~', $html);
    }

    public function test_step_two_asks_for_a_ten_digit_phone_and_an_optional_remote_id(): void
    {
        $html = $this->get(route('support.create'))->assertOk()->getContent();
        $stepTwo = strpos($html, '<section class="ticket-step" data-ticket-step="2"');

        // เบอร์โทร: บังคับ ตัวเลข 10 หลัก (ไม่มี maxlength — form.js จำกัดหลังตัดขีด เพื่อให้วาง "081-234-5678" ได้ครบ)
        $this->assertMatchesRegularExpression(
            '~<input id="ticket-contact" type="tel" name="contact" inputmode="numeric"[^>]*required[^>]*pattern="0\[0-9\]\{9\}" data-ticket-digits="10"~s',
            $html
        );
        $phone = substr($html, (int) strpos($html, 'id="ticket-contact"'), 600);
        $this->assertStringNotContainsString('maxlength', $phone);
        $this->assertStringContainsString('data-pattern-message="เบอร์โทรต้องเป็นตัวเลข 10 หลัก ขึ้นต้นด้วย 0"', $phone);

        // AnyDesk/รีโมท: ไม่บังคับ อยู่ขั้นที่ 2 ระหว่างเบอร์โทรกับรูป
        $this->assertStringContainsString('AnyDesk / รหัสรีโมท <span class="ticket-form__optional">(ไม่บังคับ)</span>', $html);
        $remote = strpos($html, 'id="ticket-remote"');
        $this->assertGreaterThan($stepTwo, $remote);
        $this->assertGreaterThan(strpos($html, 'id="ticket-contact"'), $remote);
        $this->assertLessThan(strpos($html, 'name="photos[]"'), $remote);
        $this->assertDoesNotMatchRegularExpression('~id="ticket-remote"[^>]*required~s', $html);

        // ปุ่ม ? เปิด dialog วิธีหาเลข AnyDesk — รูปตัวอย่างมีอยู่จริงและ URL เข้ารหัสช่องว่างในชื่อไฟล์แล้ว
        $this->assertStringContainsString('data-help-dialog-open="ticket-anydesk-help"', $html);
        $this->assertStringContainsString('<dialog class="ticket-help-dialog" id="ticket-anydesk-help"', $html);
        $this->assertStringContainsString('วิธีหาเลข AnyDesk', $html);
        $this->assertStringContainsString('src="'.asset('images/Anydesk%20-%20Example.png').'"', $html);
        $this->assertFileExists(public_path('images/Anydesk - Example.png'));
    }

    public function test_the_phone_must_be_ten_digits_starting_with_zero(): void
    {
        // IP ต่างกันต่อคำขอ — ฟอร์มสาธารณะจำกัด 5 ครั้ง/10 นาทีต่อ IP
        $from = fn (int $n) => $this->withServerVariables(['REMOTE_ADDR' => '10.0.0.'.$n]);

        foreach (['081234567', '08123456789', '1812345678', 'abcdefghij', ''] as $n => $phone) {
            $from($n)->post(route('support.store'), $this->publicForm(['contact' => $phone]))
                ->assertSessionHasErrors('contact');
        }
        // รหัสรีโมทรับเฉพาะเลขเครื่อง — ตัวอักษร (เช่นรหัสผ่าน) และความยาวผิดถูกปฏิเสธ ไม่ถูกเก็บ
        foreach (['abc123', '123 456 789 / pass1234', '12345', str_repeat('1', 21)] as $n => $remote) {
            $from(30 + $n)->post(route('support.store'), $this->publicForm(['remote_access' => $remote]))
                ->assertSessionHasErrors('remote_access');
        }
        $this->assertSame(0, Ticket::count());

        $from(21)->post(route('support.store'), $this->publicForm(['contact' => '081 234 5678']))->assertRedirect(route('support.submitted'));
        $this->assertSame('0812345678', Ticket::query()->sole()->contact);
    }

    public function test_server_errors_land_in_the_right_step_markup(): void
    {
        $this->from(route('support.create'))
            ->post(route('support.store'), $this->publicForm(['description' => 'สั้น', 'requester_name' => '', 'contact' => '12345']))
            ->assertRedirect(route('support.create'));

        $html = $this->get(route('support.create'))->getContent();
        $stepTwo = strpos($html, '<section class="ticket-step" data-ticket-step="2"');

        // stepper.js เปิดขั้นแรกที่มี .is-invalid / .ticket-form__error — ชื่อและรายละเอียดผิดอยู่ขั้นที่ 1 ช่องทางติดต่อผิดอยู่ขั้นที่ 2
        $this->assertLessThan($stepTwo, strpos($html, 'id="ticket-requester-name-error"'));
        $this->assertLessThan($stepTwo, strpos($html, 'id="ticket-description-error"'));
        $this->assertGreaterThan($stepTwo, strpos($html, 'id="ticket-contact-error"'));
        $this->assertStringContainsString('>สั้น</textarea>', $html, 'ข้อความที่พิมพ์ไว้กลับมาครบ');
    }

    public function test_a_visitor_cannot_reach_any_ticket_by_guessing(): void
    {
        $ticket = $this->ticketFrom($this->staff($this->department('Sales')), 'ข้อมูลภายในที่ห้ามรั่ว');

        // session ใหม่ที่ไม่เคยส่งอะไร — พารามิเตอร์ใน URL ไม่มีผล
        $this->get(route('support.submitted', ['ticket' => $ticket->id, 'number' => $ticket->ticket_number]))
            ->assertRedirect(route('support.create'));

        $this->get('/support/'.$ticket->id)->assertNotFound();

        // หน้ารายละเอียดภายใน (Phase 3) อยู่หลังการเข้าสู่ระบบเสมอ — ไม่ว่าจะมี route แล้วหรือยัง
        $detail = $this->get('/tickets/'.$ticket->id);
        $this->assertContains($detail->status(), [302, 404]);
        $detail->assertDontSee('ข้อมูลภายในที่ห้ามรั่ว');
    }

    public function test_the_honeypot_blocks_bots(): void
    {
        $this->from(route('support.create'))
            ->post(route('support.store'), $this->publicForm([TicketSubmissionGuard::HONEYPOT_FIELD => 'http://spam.example']))
            ->assertSessionHasErrors('form');

        $this->assertSame(0, Ticket::count());

        // ผู้ใช้ที่ถูกกันเห็นข้อความบนฟอร์ม ไม่ใช่หน้าว่าง
        $this->get(route('support.create'))->assertSee('role="alert"', false)->assertSee('ไม่สามารถส่งแบบฟอร์มได้');
    }

    public function test_whole_form_errors_from_the_shared_handlers_are_shown(): void
    {
        foreach (['token' => 'หน้านี้เปิดค้างไว้นานเกิน', 'attachments' => 'ไฟล์ที่ส่งมารวมกันใหญ่เกิน'] as $key => $message) {
            $this->withSession(['errors' => (new ViewErrorBag)->put('default', new MessageBag([$key => $message]))])
                ->get(route('support.create'))
                ->assertSee($message);
        }
    }

    public function test_submitting_faster_than_a_human_is_blocked(): void
    {
        $this->post(route('support.store'), $this->publicForm([TicketSubmissionGuard::TOKEN_FIELD => $this->formToken(secondsAgo: 1)]))
            ->assertSessionHasErrors('form');

        $this->assertSame(0, Ticket::count());
    }

    public function test_a_missing_tampered_or_expired_token_is_blocked(): void
    {
        foreach ([
            'missing' => '',
            'tampered' => 'not-an-encrypted-value',
            'expired' => $this->formToken(secondsAgo: TicketSubmissionGuard::MAX_AGE_SECONDS + 60),
        ] as $case => $token) {
            $this->post(route('support.store'), $this->publicForm([TicketSubmissionGuard::TOKEN_FIELD => $token]))
                ->assertSessionHasErrors('form');

            $this->assertSame(0, Ticket::count(), $case);
        }
    }

    public function test_the_public_form_is_rate_limited_per_ip(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->post(route('support.store'), $this->publicForm());
        }

        $this->post(route('support.store'), $this->publicForm())
            ->assertSessionHasErrors('form');

        $this->assertSame(5, Ticket::count());
    }

    public function test_double_submit_with_the_same_key_creates_one_ticket(): void
    {
        $form = $this->publicForm();

        $this->post(route('support.store'), $form)->assertRedirect(route('support.submitted'));
        $this->post(route('support.store'), $form)->assertRedirect(route('support.submitted'));

        $this->assertSame(1, Ticket::count());
    }

    public function test_unsafe_photos_are_rejected_on_the_public_form(): void
    {
        $this->post(route('support.store'), $this->publicForm(['photos' => [$this->disguisedPdf()]]))
            ->assertSessionHasErrors('photos.0');
        $this->post(route('support.store'), $this->publicForm(['photos' => [$this->decompressionBombPng()]]))
            ->assertSessionHasErrors('photos.0');

        $this->assertSame(0, Ticket::count());
        $this->assertSame(0, TicketAttachment::count());
        $this->assertSame([], Storage::disk('local')->allFiles('ticket-attachments'));
    }

    public function test_validation_errors_keep_the_typed_text_but_not_the_spam_token(): void
    {
        $this->post(route('support.store'), $this->publicForm(['description' => 'สั้น']))
            ->assertSessionHasErrors('description')
            ->assertSessionHasInput('requester_name', 'สมชาย ใจดี');

        // ฟอร์มที่ถูกแสดงใหม่ได้ token ใหม่ ไม่ใช่ค่าเก่า
        $page = $this->get(route('support.create'))->getContent();
        $this->assertSame(1, substr_count($page, 'name="'.TicketSubmissionGuard::TOKEN_FIELD.'"'));
        $this->assertStringContainsString('value="สมชาย ใจดี"', $page);
    }
}
