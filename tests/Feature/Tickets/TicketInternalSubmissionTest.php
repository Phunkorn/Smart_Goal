<?php

namespace Tests\Feature\Tickets;

use App\Models\Ticket;
use App\Models\TicketActivity;
use App\Models\TicketAttachment;
use App\Services\TicketSubmissionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Tests\TestCase;

/**
 * แจ้งปัญหาจากผู้ใช้ที่เข้าสู่ระบบ — /tickets/create
 */
class TicketInternalSubmissionTest extends TestCase
{
    use BuildsTickets;
    use BuildsTicketUploads;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        Storage::fake('public');
    }

    /** ค่าที่ service ได้รับจริงหลังผ่าน Form Request ("other" ถูกแปลงเป็น null แล้ว) */
    private function serviceInput(): array
    {
        return $this->internalForm(['location_id' => null]);
    }

    public function test_guests_are_sent_to_login(): void
    {
        $this->get(route('tickets.create'))->assertRedirect(route('login'));
        $this->post(route('tickets.store'), $this->internalForm())->assertRedirect(route('login'));
        $this->assertSame(0, Ticket::count());
    }

    public function test_viewers_and_inactive_accounts_cannot_open_or_submit(): void
    {
        $sales = $this->department('Sales');

        foreach ([$this->staff($sales, role: 'viewer'), $this->staff($sales, active: false)] as $user) {
            $this->actingAs($user)->get(route('tickets.create'))->assertStatus($user->is_active ? 403 : 302);
            $this->actingAs($user)->post(route('tickets.store'), $this->internalForm())->assertStatus($user->is_active ? 403 : 302);
        }

        $this->assertSame(0, Ticket::count());
    }

    public function test_the_form_shows_the_requester_from_the_account_and_active_locations_only(): void
    {
        $user = $this->staff($this->department('ฝ่ายขาย'));
        $this->location('สาขาบางนา');
        $this->location('สาขาที่ปิดแล้ว', active: false);

        $this->actingAs($user)->get(route('tickets.create'))
            ->assertOk()
            ->assertSee($user->name)
            ->assertSee('ฝ่ายขาย')
            ->assertSee('สาขาบางนา')
            ->assertDontSee('สาขาที่ปิดแล้ว')
            ->assertSee('name="submission_key"', false)
            ->assertSee('accept="image/jpeg,image/png"', false)
            // ช่องติดต่อกลับชุดเดียวกับฟอร์มสาธารณะ พร้อม dialog วิธีหาเลข AnyDesk
            ->assertSee('name="contact"', false)
            ->assertSee('name="remote_access"', false)
            ->assertSee('id="ticket-anydesk-help"', false)
            ->assertSee('data-help-dialog-open="ticket-anydesk-help"', false)
            // แผนกของผู้ใช้ภายในมาจากบัญชี ไม่มีช่องให้เลือกหรือส่งมาเอง
            ->assertDontSee('name="requester_department"', false)
            ->assertDontSee('name="requester_department_id"', false);
    }

    public function test_a_user_submits_with_a_listed_location_and_department_comes_from_the_account(): void
    {
        $sales = $this->department('Sales');
        $hr = $this->department('HR');
        $user = $this->staff($sales);
        $location = $this->location('สำนักงานใหญ่');

        $response = $this->actingAs($user)->from(route('tickets.create'))
            ->post(route('tickets.store'), $this->internalForm([
                'location_id' => (string) $location->id,
                'location_name' => '',
                // ค่าปลอมที่ต้องไม่มีผล
                'requester_department_id' => $hr->id,
                'requester_id' => 999,
                'requester_name' => 'คนอื่น',
                'status' => Ticket::STATUS_CLOSED,
                'ticket_number' => 'TK-HACKED',
            ]))
            ->assertSessionHas('success');

        $ticket = Ticket::query()->sole();
        // หลังส่งกลับ /tickets — การ์ดแสดง "รอเจ้าหน้าที่รับงาน" จากสถานะจริง ผู้แจ้งไม่เข้าหน้ารายละเอียดของเจ้าหน้าที่
        $response->assertRedirect(route('tickets.index'));
        $this->assertSame($user->id, $ticket->requester_id);
        $this->assertSame($user->name, $ticket->requester_name);
        $this->assertSame($sales->id, $ticket->requester_department_id);
        $this->assertSame('Sales', $ticket->requester_department_name);
        $this->assertSame($location->id, $ticket->location_id);
        $this->assertSame('สำนักงานใหญ่', $ticket->location_name);
        $this->assertSame(Ticket::STATUS_NEW, $ticket->status);
        $this->assertSame(Ticket::formatNumber($ticket->id), $ticket->ticket_number);
        $this->assertStringContainsString($ticket->ticket_number, session('success'));
        $this->assertSame(TicketActivity::TYPE_CREATED, $ticket->activities()->sole()->type);
    }

    public function test_the_phone_and_anydesk_id_are_saved_from_the_internal_form(): void
    {
        $user = $this->staff($this->department('Sales'));

        $this->actingAs($user)->post(route('tickets.store'), $this->internalForm([
            'contact' => '081-234-5678',
            'remote_access' => ' 123 456 789 ',
        ]))->assertSessionHas('success');

        $ticket = Ticket::query()->sole();
        // ขีดและช่องว่างถูกตัดเหมือนฟอร์มสาธารณะ — กติกาชุดเดียวกัน (TicketSubmissionRequest)
        $this->assertSame('0812345678', $ticket->contact);
        $this->assertSame('123 456 789', $ticket->remote_access);
        // แผนกยังมาจากบัญชี ไม่ใช่จากฟอร์ม
        $this->assertSame('Sales', $ticket->requester_department_name);
    }

    public function test_the_internal_form_requires_a_phone_and_refuses_a_password_as_an_anydesk_id(): void
    {
        $user = $this->staff($this->department('Sales'));

        foreach (['', '12345', '08123456789'] as $badPhone) {
            $this->actingAs($user)->post(route('tickets.store'), $this->internalForm(['contact' => $badPhone]))
                ->assertSessionHasErrors('contact');
        }

        // ช่อง AnyDesk รับเฉพาะเลขเครื่อง จึงใส่รหัสผ่านลงไปไม่ได้
        $this->actingAs($user)->post(route('tickets.store'), $this->internalForm(['remote_access' => 'hunter2']))
            ->assertSessionHasErrors('remote_access');

        $this->assertNothingSaved();
    }

    public function test_a_department_sent_from_the_internal_form_is_ignored(): void
    {
        $user = $this->staff($this->department('Sales'));

        $this->actingAs($user)->post(route('tickets.store'), $this->internalForm(['requester_department' => 'HR']))
            ->assertSessionHas('success');

        $ticket = Ticket::query()->sole();
        $this->assertSame('Sales', $ticket->requester_department_name);
        $this->assertNotNull($ticket->requester_department_id, 'ผู้ใช้ภายในผูก id ของแผนกตัวเองตามเดิม');
    }

    public function test_photos_are_optional_and_up_to_three_are_stored_privately(): void
    {
        $user = $this->staff($this->department('Sales'));

        $this->actingAs($user)->post(route('tickets.store'), $this->internalForm([
            'photos' => [$this->photo('a.jpg'), $this->photo('b.png'), $this->photo('c.jpg')],
        ]))->assertSessionHasNoErrors();

        $ticket = Ticket::query()->sole();
        $attachments = TicketAttachment::query()->where('ticket_id', $ticket->id)->get();
        $this->assertCount(3, $attachments);

        foreach ($attachments as $attachment) {
            $this->assertStringStartsWith('ticket-attachments/'.$ticket->id.'/', $attachment->file_path);
            Storage::disk('local')->assertExists($attachment->file_path);
            $this->assertContains($attachment->file_type, ['image/jpeg', 'image/png']);
            $this->assertSame($user->id, $attachment->uploaded_by);
            $this->assertNull($attachment->ticket_activity_id);
        }

        $this->assertSame([], Storage::disk('public')->allFiles());
    }

    public function test_more_than_three_photos_are_rejected_and_nothing_is_saved(): void
    {
        $user = $this->staff($this->department('Sales'));

        $this->actingAs($user)->post(route('tickets.store'), $this->internalForm([
            'photos' => [$this->photo('1.jpg'), $this->photo('2.jpg'), $this->photo('3.jpg'), $this->photo('4.jpg')],
        ]))->assertSessionHasErrors('photos');

        $this->assertNothingSaved();
    }

    public function test_unsafe_files_are_rejected(): void
    {
        $user = $this->staff($this->department('Sales'));

        foreach ([
            'disguised pdf' => $this->disguisedPdf(),
            'not an image' => UploadedFile::fake()->create('notes.txt', 4, 'text/plain'),
            'over 10 MB' => $this->photo('big.jpg')->size(10241),
            'decompression bomb' => $this->decompressionBombPng(),
        ] as $case => $file) {
            $this->actingAs($user)->post(route('tickets.store'), $this->internalForm(['photos' => [$file]]))
                ->assertSessionHasErrors('photos.0');

            $this->assertSame(0, Ticket::count(), $case);
        }

        $this->assertNothingSaved();
    }

    public function test_validation_messages_for_missing_fields(): void
    {
        $user = $this->staff($this->department('Sales'));

        $this->actingAs($user)->post(route('tickets.store'), $this->internalForm([
            'location_id' => 'other',
            'location_name' => '',
            'description' => 'สั้น',
        ]))->assertSessionHasErrors(['location_name', 'description']);

        $this->actingAs($user)->post(route('tickets.store'), $this->internalForm(['submission_key' => 'not-a-uuid']))
            ->assertSessionHasErrors('submission_key');

        $this->assertNothingSaved();
    }

    public function test_an_inactive_location_is_rejected(): void
    {
        $user = $this->staff($this->department('Sales'));
        $closed = $this->location('สาขาที่ปิดแล้ว', active: false);

        $this->actingAs($user)->post(route('tickets.store'), $this->internalForm(['location_id' => (string) $closed->id]))
            ->assertSessionHasErrors('location_id');

        $this->assertNothingSaved();
    }

    public function test_submitting_twice_with_the_same_key_creates_one_ticket(): void
    {
        $user = $this->staff($this->department('Sales'));
        $form = $this->internalForm(['photos' => [$this->photo()]]);

        $this->actingAs($user)->post(route('tickets.store'), $form)->assertSessionHas('success');
        $first = session('success');

        $form['photos'] = [$this->photo()];
        $this->actingAs($user)->post(route('tickets.store'), $form)->assertSessionHas('success');

        $this->assertSame(1, Ticket::count());
        $this->assertSame(1, TicketAttachment::count());
        $this->assertSame($first, session('success'));
    }

    public function test_the_same_key_from_another_user_is_not_shared(): void
    {
        $key = (string) Str::uuid();
        $sales = $this->department('Sales');

        $this->actingAs($this->staff($sales))->post(route('tickets.store'), $this->internalForm(['submission_key' => $key]));
        $this->actingAs($this->staff($sales))->post(route('tickets.store'), $this->internalForm(['submission_key' => $key]));

        $this->assertSame(2, Ticket::count());
    }

    public function test_a_failure_while_saving_photos_leaves_no_ticket_and_no_files(): void
    {
        $user = $this->staff($this->department('Sales'));
        $saved = 0;
        Event::listen('eloquent.creating: '.TicketAttachment::class, function () use (&$saved): void {
            if (++$saved === 2) {
                throw new RuntimeException('disk full');
            }
        });

        $input = $this->serviceInput();

        try {
            app(TicketSubmissionService::class)->submitInternal($user, $input, [$this->photo('a.jpg'), $this->photo('b.jpg')]);
            $this->fail('the failure should propagate');
        } catch (RuntimeException $exception) {
            $this->assertSame('disk full', $exception->getMessage());
        }

        $this->assertNothingSaved();

        // คีย์ถูกปล่อยแล้ว ส่งใหม่ด้วยคีย์เดิมได้เมื่อปัญหาหายไป
        Event::forget('eloquent.creating: '.TicketAttachment::class);
        $ticket = app(TicketSubmissionService::class)->submitInternal($user, $input, [$this->photo()]);
        $this->assertSame(1, $ticket->attachments()->count());
    }

    public function test_service_rejects_unsafe_photos_even_without_the_form_request(): void
    {
        $user = $this->staff($this->department('Sales'));

        try {
            app(TicketSubmissionService::class)->submitInternal($user, $this->serviceInput(), [$this->decompressionBombPng()]);
            $this->fail('the service must reject the file itself');
        } catch (ValidationException $exception) {
            $this->assertSame(['photos'], array_keys($exception->errors()));
        }

        $this->assertNothingSaved();
    }

    public function test_internal_submissions_are_rate_limited_per_account(): void
    {
        $user = $this->staff($this->department('Sales'));

        // ทุกคำขอถูกนับ แม้ใบที่ 2–10 ถูกปฏิเสธเพราะมี Ticket เปิดอยู่แล้ว (หนึ่งผู้แจ้งหนึ่งใบ)
        for ($i = 0; $i < 10; $i++) {
            $this->actingAs($user)->post(route('tickets.store'), $this->internalForm());
        }

        $this->actingAs($user)->post(route('tickets.store'), $this->internalForm())
            ->assertSessionHasErrors(['form' => 'ส่งแจ้งปัญหาถี่เกินไป กรุณารอสักครู่แล้วลองใหม่']);

        $this->assertSame(1, Ticket::count());
    }

    private function assertNothingSaved(): void
    {
        $this->assertSame(0, Ticket::count());
        $this->assertSame(0, TicketActivity::count());
        $this->assertSame(0, TicketAttachment::count());
        $this->assertSame([], Storage::disk('local')->allFiles('ticket-attachments'));
    }
}
