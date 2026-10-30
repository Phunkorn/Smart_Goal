<?php

namespace Tests\Feature\Tickets;

use App\Models\SystemNotification;
use App\Models\Ticket;
use App\Models\TicketAttachment;
use App\Models\User;
use App\Services\LineLogin;
use App\Services\NotificationService;
use App\Services\TicketNoteService;
use App\Services\TicketNotificationService;
use App\Services\TicketParticipantService;
use App\Services\TicketPhotoStore;
use App\Services\TicketSubmissionService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Mockery;
use Tests\TestCase;

/**
 * แยกหน้าที่ผู้แจ้ง (Reporter) กับผู้รับงาน (Handler) และ "หนึ่งผู้แจ้ง หนึ่ง Ticket ที่ยังไม่ปิด"
 *
 * Reporter = พนักงานทั่วไปที่ไม่ใช่ Handler / หัวหน้าแผนก / admin (TicketAgentAccess::canReport)
 * กติกาเปิดค้างได้ใบเดียวบังคับสามชั้น: StoreTicketRequest · TicketSubmissionService · unique index (open_requester_id)
 */
class TicketReporterSeparationTest extends TestCase
{
    use BuildsTickets;
    use BuildsTicketUploads;
    use RefreshDatabase;

    private function form(array $overrides = []): array
    {
        return $this->internalForm(['location_id' => null, ...$overrides]);
    }

    /** @return array<string, \Closure(Ticket, User): Ticket> สถานะที่ยังไม่ปิด */
    private function openStates(): array
    {
        return [
            'new' => fn (Ticket $ticket) => $ticket,
            'active' => fn (Ticket $ticket, $agent) => $this->startedBy($agent, $ticket),
            'follow_up' => fn (Ticket $ticket, $agent) => $this->transition($this->startedBy($agent, $ticket), Ticket::ACTION_FOLLOW_UP, $agent, [
                'note' => 'รออะไหล่', 'follow_up_date' => now('Asia/Bangkok')->addDay()->toDateString(),
            ]),
        ];
    }

    public function test_a_reporter_with_an_open_ticket_is_refused_at_every_layer_for_every_open_status(): void
    {
        $agent = $this->agent();

        foreach ($this->openStates() as $status => $reach) {
            $reporter = $this->staff($this->department('Sales '.$status));
            $open = $reach($this->ticketFrom($reporter), $agent)->fresh();
            $this->assertSame($status, $open->status);
            $message = TicketSubmissionService::openTicketMessage($open);

            // ชั้น 1: Form Request (HTTP)
            $this->actingAs($reporter)->post(route('tickets.store'), $this->form())
                ->assertSessionHasErrors(['form' => $message]);

            // ชั้น 2: service (ผู้เรียกที่ข้าม Form Request)
            try {
                app(TicketSubmissionService::class)->submitInternal($reporter, $this->form());
                $this->fail("service accepted a second open ticket ({$status})");
            } catch (ValidationException $e) {
                $this->assertSame([$message], $e->errors()['form']);
            }

            // ชั้น 3: ฐานข้อมูล — เขียนข้ามทุก service ก็ใส่ใบเปิดใบที่สองไม่ได้
            $row = collect(DB::table('tickets')->where('id', $open->id)->first())
                ->except(['id', 'ticket_number', 'open_line_user_id', 'open_requester_id'])->all();
            try {
                DB::table('tickets')->insert([...$row, 'status' => 'new']);
                $this->fail("the database stored a second open ticket ({$status})");
            } catch (UniqueConstraintViolationException) {
            }

            // การ์ดแสดงสถานะใบนั้น ไม่มีปุ่มแจ้งปัญหาใหม่
            $this->actingAs($reporter)->get(route('tickets.index'))->assertOk()
                ->assertSee($open->ticket_number)
                ->assertDontSee('data-ticket-open-modal', false);

            $this->assertSame(1, Ticket::query()->where('requester_id', $reporter->id)->count(), $status);
        }
    }

    public function test_the_card_follows_the_real_status_and_a_closed_ticket_allows_a_new_one(): void
    {
        $agent = $this->agent();
        $reporter = $this->staff($this->department('Sales'));
        $ticket = $this->ticketFrom($reporter);

        $titles = [
            'new' => ['รอเจ้าหน้าที่รับงาน', 'reporter-2.png'],
            'active' => ['เจ้าหน้าที่รับงานแล้ว', 'reporter-3.png'],
            'follow_up' => ['อยู่ระหว่างติดตามงาน', 'reporter-3.png'],
        ];
        $this->actingAs($reporter)->get(route('tickets.index'))->assertSee($titles['new'][0])->assertSee($titles['new'][1]);
        $this->startedBy($agent, $ticket);
        $this->actingAs($reporter)->get(route('tickets.index'))->assertSee($titles['active'][0])->assertSee($titles['active'][1]);
        $this->transition($ticket, Ticket::ACTION_FOLLOW_UP, $agent, ['note' => 'รออะไหล่', 'follow_up_date' => now('Asia/Bangkok')->addDays(2)->toDateString()]);
        $this->actingAs($reporter)->get(route('tickets.index'))->assertSee($titles['follow_up'][0])->assertSee('ติดตามอีกครั้ง');

        // ปิดงาน → กลับเป็น "แจ้งปัญหา IT" แจ้งใหม่ได้ และใบเดิมยังอยู่ในประวัติ เปิดดูผลการแก้ไขได้ (อ่านอย่างเดียว)
        $this->transition($ticket, Ticket::ACTION_RESUME, $agent);
        $this->transition($ticket, Ticket::ACTION_CLOSE, $agent, ['note' => 'เปลี่ยนสายแลนแล้ว']);
        $this->actingAs($reporter)->get(route('tickets.index'))
            ->assertSee('<h1 class="ticket-reporter__title" id="ticket-reporter-title">แจ้งปัญหา IT</h1>', false)
            ->assertSee('data-ticket-open-modal', false)
            ->assertSee('data-bs-target="#ticket-history-modal"', false)
            ->assertSee($ticket->ticket_number)
            ->assertSee('href="'.route('tickets.index', ['ticket' => $ticket->id]).'"', false);
        $this->actingAs($reporter)->get(route('tickets.index', ['ticket' => $ticket->id]))->assertOk()
            ->assertSee('data-open-on-load', false)
            ->assertSee('เปลี่ยนสายแลนแล้ว');

        $this->actingAs($reporter)->post(route('tickets.store'), $this->form())->assertRedirect(route('tickets.index'));
        $this->assertSame(2, Ticket::query()->where('requester_id', $reporter->id)->count());
        $this->assertSame(1, Ticket::query()->where('requester_id', $reporter->id)->where('status', '!=', 'closed')->count());
    }

    public function test_two_tabs_racing_past_the_check_end_with_one_ticket_and_no_stray_photos(): void
    {
        Storage::fake('local');
        $reporter = $this->staff($this->department('Sales'));
        $this->ticketFrom($reporter);

        // แท็บที่สองตรวจ "ยังไม่มีใบเปิด" ก่อนแท็บแรกบันทึกเสร็จ → การบันทึกชน unique index → ได้ข้อความเดิม ไม่ใช่ 500
        $calls = 0;
        $service = Mockery::mock(TicketSubmissionService::class, [
            app(TicketPhotoStore::class), app(TicketNotificationService::class), app(LineLogin::class),
        ])->makePartial();
        $service->shouldReceive('openTicketForRequester')->andReturnUsing(function (int $id) use (&$calls) {
            return $calls++ < 2
                ? null
                : Ticket::query()->where('requester_id', $id)->where('status', '!=', 'closed')->first();
        });
        $this->app->instance(TicketSubmissionService::class, $service);

        $this->actingAs($reporter)->post(route('tickets.store'), $this->form(['photos' => [$this->photo()]]))
            ->assertSessionHasErrors(['form' => TicketSubmissionService::openTicketMessage(Ticket::query()->sole())]);
        $this->assertSame(1, Ticket::count());
        $this->assertSame(0, TicketAttachment::count());
        $this->assertSame([], Storage::disk('local')->allFiles('ticket-attachments'));
    }

    public function test_sending_twice_with_the_same_key_returns_the_same_ticket_instead_of_refusing(): void
    {
        $reporter = $this->staff($this->department('Sales'));
        $form = $this->form();

        $this->actingAs($reporter)->post(route('tickets.store'), $form)->assertRedirect(route('tickets.index'));
        $this->actingAs($reporter)->post(route('tickets.store'), $form)->assertRedirect(route('tickets.index'))->assertSessionHasNoErrors();
        $this->assertSame(1, Ticket::count());
    }

    public function test_reopening_an_old_internal_ticket_is_refused_while_the_reporter_has_another_open_one(): void
    {
        $agent = $this->agent();
        $reporter = $this->staff($this->department('Sales'));
        $old = $this->transition($this->startedBy($agent, $this->ticketFrom($reporter)), Ticket::ACTION_CLOSE, $agent, ['note' => 'จบ']);
        $current = $this->ticketFrom($reporter, 'เรื่องใหม่หลังปิดเรื่องแรก');

        $reopen = ['action' => 'reopen', 'expected_status' => 'closed', 'note' => 'อาการกลับมา'];
        $this->actingAs($agent)->patchJson(route('tickets.status.update', $old), $reopen)
            ->assertStatus(422)
            ->assertJsonPath('errors.status.0', fn (string $message) => str_contains($message, $current->ticket_number) && str_contains($message, 'บัญชีผู้ใช้เดียวกัน'));
        $this->assertSame(Ticket::STATUS_CLOSED, $old->fresh()->status);

        // ใบปัจจุบันปิดแล้ว → เปิดใบเก่าใหม่ได้ · Ticket ของผู้แจ้งคนอื่นไม่เกี่ยว
        $this->transition($this->startedBy($agent, $current), Ticket::ACTION_CLOSE, $agent, ['note' => 'จบ']);
        $this->ticketFrom($this->staff($this->department('HR')));
        $this->transition($old->fresh(), Ticket::ACTION_REOPEN, $agent, ['note' => 'อาการกลับมา']);
        $this->assertSame(Ticket::STATUS_ACTIVE, $old->fresh()->status);
    }

    public function test_handlers_department_heads_and_admins_cannot_create_from_any_channel(): void
    {
        $it = $this->itDepartment();
        foreach ([
            'IT staff (department handler)' => $this->staff($it),
            'IT head (handler)' => $this->staff($it, head: true),
            'handler by override' => tap($this->staff($this->department('Sales')), fn ($user) => $this->override($user, enabled: true)),
            // หัวหน้าแผนกที่ดูคิวของแผนกตัวเอง (แผนกรับผิดชอบ Ticket แต่ถูกปิดสิทธิ์ Handler รายคน)
            'overseeing head (not handler)' => $this->overseeingHead($this->overseenDepartment('IT Helpdesk')),
            'admin (not handler)' => $this->staff($this->department('Management'), role: 'admin'),
        ] as $who => $user) {
            $user = $user->fresh();
            $this->actingAs($user)->get(route('tickets.create'))->assertForbidden();
            $this->actingAs($user)->post(route('tickets.store'), $this->form())->assertForbidden();

            try {
                app(TicketSubmissionService::class)->submitInternal($user, $this->form());
                $this->fail("{$who} created a ticket through the service");
            } catch (AuthorizationException) {
            }

            $this->actingAs($user)->get(route('tickets.index'))->assertOk()
                ->assertDontSee('data-ticket-open-modal', false)
                ->assertDontSee('id="ticket-submit-modal"', false);
        }
        $this->assertSame(0, Ticket::count());
    }

    public function test_handlers_keep_working_tickets_from_other_people_as_before(): void
    {
        $agent = $this->agent();
        $colleague = $this->agent();
        $ticket = $this->ticketFrom($this->staff($this->department('Sales')));

        $act = fn ($user, string $action, array $extra = []) => $this->actingAs($user)->from(route('tickets.show', $ticket))
            ->patch(route('tickets.status.update', $ticket), ['action' => $action, 'expected_status' => $ticket->fresh()->status, ...$extra]);

        $act($agent, 'start')->assertSessionHas('success');
        $this->actingAs($colleague)->post(route('tickets.participants.store', $ticket))->assertSessionHas('success');
        $act($agent, 'follow_up', ['note' => 'รออะไหล่', 'follow_up_date' => now('Asia/Bangkok')->addDay()->toDateString()])->assertSessionHas('success');
        $act($colleague, 'resume')->assertSessionHas('success');
        $act($agent, 'close', ['note' => 'แก้ไขแล้ว'])->assertSessionHas('success');
        $this->assertSame(Ticket::STATUS_CLOSED, $ticket->fresh()->status);
        $this->actingAs($agent)->get(route('tickets.show', $ticket))->assertOk()->assertSee('แก้ไขแล้ว');
    }

    public function test_a_deep_link_never_reveals_someone_elses_ticket(): void
    {
        $reporter = $this->staff($this->department('Sales'));
        $mine = $this->ticketFrom($reporter, 'ปัญหาของฉัน');
        $theirs = $this->ticketFrom($this->staff($this->department('HR')), 'ข้อมูลของคนอื่นที่ห้ามรั่ว');

        $this->actingAs($reporter)->get(route('tickets.index', ['ticket' => $theirs->id]))->assertOk()
            ->assertDontSee($theirs->ticket_number)
            ->assertDontSee('ข้อมูลของคนอื่นที่ห้ามรั่ว')
            ->assertDontSee('data-open-on-load', false)
            ->assertSee($mine->ticket_number);
        $this->actingAs($reporter)->get(route('tickets.index', ['ticket' => 'x<script>']))->assertOk()->assertDontSee('data-open-on-load', false);
        $this->actingAs($reporter)->get(route('tickets.show', $theirs))->assertForbidden();
        $this->actingAs($reporter)->get(route('tickets.preview', $theirs))->assertForbidden();

        // ลิงก์ของตัวเองเปิด modal ให้เอง
        $this->actingAs($reporter)->get(route('tickets.index', ['ticket' => $mine->id]))->assertSee('data-open-on-load', false);
    }

    public function test_reporters_and_department_heads_never_see_internal_notes_or_their_photos(): void
    {
        Storage::fake('local');
        $agent = $this->agent();
        $sales = $this->overseenDepartment('Sales');
        $reporter = $this->reporterIn($sales);
        $head = $this->overseeingHead($sales);
        $ticket = $this->startedBy($agent, $this->ticketFrom($reporter));
        app(TicketNoteService::class)->add($ticket, $agent, 'บันทึกภายใน: รหัสเราเตอร์ชั้น 3', [$this->photo('note.jpg')]);
        $notePhoto = TicketAttachment::query()->whereNotNull('ticket_activity_id')->sole();

        // หน้าผู้แจ้ง (รวมแจ้งเตือนในเลย์เอาต์) และ preview ของหัวหน้าแผนก/ผู้แจ้ง — ไม่มีข้อความบันทึก ไม่มีลิงก์รูปบันทึก
        foreach ([[$reporter, route('tickets.index')], [$head, route('tickets.preview', $ticket)], [$reporter, route('tickets.preview', $ticket)]] as [$user, $url]) {
            $this->actingAs($user)->get($url)->assertOk()
                ->assertDontSee('บันทึกภายใน')
                ->assertDontSee(route('media.ticket-attachments.show', $notePhoto), false)
                ->assertSee('รับงาน');
        }
        // เนื้อหา modal อ่านอย่างเดียวไม่มีรายชื่อผู้เข้าร่วม (ชื่อผู้รับงานอยู่ในแจ้งเตือนสถานะได้ตามเดิม)
        $this->actingAs($head)->get(route('tickets.preview', $ticket))
            ->assertDontSee('เจ้าหน้าที่ที่เข้าร่วม')->assertDontSee($agent->name);
        $this->assertSame(0, SystemNotification::query()->where('type', 'ticket_note_added')->where('user_id', $reporter->id)->count());

        // เดา id ของรูปบันทึกแล้วดึงตรง → 403 · Handler ดึงได้
        $this->actingAs($reporter)->get(route('media.ticket-attachments.show', $notePhoto))->assertForbidden();
        $this->actingAs($head)->get(route('media.ticket-attachments.show', $notePhoto))->assertForbidden();
        $this->actingAs($agent)->get(route('media.ticket-attachments.show', $notePhoto))->assertOk();
        $this->actingAs($agent)->get(route('tickets.show', $ticket))->assertSee('บันทึกภายใน: รหัสเราเตอร์ชั้น 3');
    }

    /**
     * modal อ่านอย่างเดียวของผู้แจ้งไม่แสดงสิ่งที่รู้อยู่แล้วหรือซ้ำกับความคืบหน้า (ชื่อ แผนก ช่องทางที่แจ้ง เวลารับงาน/ปิดงาน)
     * ส่วนหัวหน้าแผนกที่เปิดใบของลูกทีม และ Handler ที่หน้ารายละเอียด ยังเห็นครบ
     */
    public function test_the_requesters_own_modal_hides_facts_they_already_know_but_others_keep_them(): void
    {
        $agent = $this->agent();
        $sales = $this->overseenDepartment('Sales');
        $reporter = $this->reporterIn($sales);
        $head = $this->overseeingHead($sales);
        $ticket = $this->ticketFrom($reporter, 'อินเทอร์เน็ตหลุดบ่อย');
        $this->transition($this->startedBy($agent, $ticket), Ticket::ACTION_CLOSE, $agent, ['note' => 'เปลี่ยนสายแลนแล้ว']);

        $facts = ['<dt>ผู้แจ้ง</dt>', '<dt>แผนก</dt>', '<dt>ช่องทางที่แจ้ง</dt>', '<dt>รับงานครั้งแรก</dt>', '<dt>ปิดงานเมื่อ</dt>'];
        // เฉพาะ modal รายละเอียด — หน้าหลักยังมี modal ประวัติและฟอร์มแจ้งปัญหา (ซึ่งมีป้าย "ผู้แจ้ง"/"แผนก" ของฟอร์ม) ต่อท้าย
        $modalOf = function (string $html): string {
            $modal = substr($html, (int) strpos($html, 'ticket-readonly'));

            return preg_split('/id="ticket-(?:history|submit)-modal"/', $modal)[0];
        };

        // ผู้แจ้งเปิดใบของตัวเอง ทั้งจากหน้าหลัก (ลิงก์ ?ticket) และ preview
        foreach ([route('tickets.index', ['ticket' => $ticket->id]), route('tickets.preview', $ticket)] as $url) {
            $html = $modalOf($this->actingAs($reporter)->get($url)->assertOk()->getContent());

            foreach ($facts as $fact) {
                $this->assertStringNotContainsString($fact, $html, $url.' '.$fact);
            }
            // ที่ยังต้องเห็น: ปัญหาที่แจ้ง สถานที่ ความคืบหน้า และผลการแก้ไข
            foreach (['อินเทอร์เน็ตหลุดบ่อย', '<dt>สาขา/สถานที่</dt>', 'ความคืบหน้า', 'ผลการแก้ไข', 'เปลี่ยนสายแลนแล้ว'] as $kept) {
                $this->assertStringContainsString($kept, $html, $url.' '.$kept);
            }
        }

        // หัวหน้าแผนกเปิดใบของลูกทีม: ต้องรู้ว่าใครแจ้งและแผนกไหน
        $html = $modalOf($this->actingAs($head)->get(route('tickets.preview', $ticket))->assertOk()->getContent());
        foreach (['<dt>ผู้แจ้ง</dt>', '<dt>แผนก</dt>', '<dt>ช่องทางที่แจ้ง</dt>'] as $fact) {
            $this->assertStringContainsString($fact, $html, $fact);
        }
        $this->assertStringContainsString($reporter->name, $html);

        // Handler ที่หน้ารายละเอียดเห็นข้อมูลครบเหมือนเดิม
        $html = $this->actingAs($agent)->get(route('tickets.show', $ticket))->assertOk()->getContent();
        foreach ($facts as $fact) {
            $this->assertStringContainsString($fact, $html, 'agent '.$fact);
        }
    }

    /**
     * "ผู้รับผิดชอบ" ใน modal ของผู้แจ้ง — เจ้าหน้าที่ที่กำลังดูแลใบนี้ พร้อมรูปโปรไฟล์ (ตัวย่อชื่อเมื่อไม่มีรูป)
     * ผู้ประสานงานขึ้นก่อน · คนที่ออกไปแล้วไม่แสดง · หัวหน้าแผนกไม่เห็นแผงนี้
     */
    public function test_the_requester_sees_who_is_responsible_with_avatars_and_the_head_does_not(): void
    {
        Storage::fake('public');
        $sales = $this->overseenDepartment('Sales');
        $reporter = $this->reporterIn($sales);
        $head = $this->overseeingHead($sales);
        $lead = $this->agent();
        $helper = $this->agent();
        $leaver = $this->agent();
        $lead->forceFill(['name' => 'ธนากร ไอที'])->save();
        $helper->forceFill(['name' => 'วีระ ซัพพอร์ต', 'profile_image' => 'profiles/helper.jpg'])->save();
        $leaver->forceFill(['name' => 'ออกไปแล้ว คนเดิม'])->save();
        Storage::disk('public')->put('profiles/helper.jpg', 'jpg-bytes');

        $ticket = $this->startedBy($lead, $this->ticketFrom($reporter));
        $participants = app(TicketParticipantService::class);
        $participants->join($ticket, $helper);
        $participants->join($ticket, $leaver);
        $participants->leave($ticket, $leaver);
        $participants->setCoordinator($ticket, $lead, $helper);

        $modalOf = fn (string $html): string => preg_split('/id="ticket-(?:history|submit)-modal"/', substr($html, (int) strpos($html, 'ticket-readonly')))[0];

        foreach ([route('tickets.index'), route('tickets.preview', $ticket)] as $url) {
            $html = $modalOf($this->actingAs($reporter)->get($url)->assertOk()->getContent());

            $this->assertStringContainsString('>ผู้รับผิดชอบ</h3>', $html, $url);
            // ผู้ประสานงาน (วีระ) ขึ้นก่อนพร้อมป้าย · ธนากรตามมา · คนที่ออกไปแล้วไม่แสดง
            $this->assertTrue(strpos($html, 'วีระ ซัพพอร์ต') < strpos($html, 'ธนากร ไอที'), $url);
            $this->assertStringContainsString('ผู้ประสานงาน', $html);
            $this->assertStringNotContainsString('ออกไปแล้ว คนเดิม', $html);
            // วีระมีรูป → <img> จาก media.profile (ตัวย่อสำรองอยู่ข้างล่าง) · ธนากรไม่มีรูป → ตัวย่อชื่ออย่างเดียว
            $this->assertStringContainsString('<img src="'.route('media.profile', $helper).'" alt="" data-avatar-image>', $html);
            $this->assertStringNotContainsString(route('media.profile', $lead), $html);
            $this->assertStringContainsString('<span aria-hidden="true">ธ</span>', $html);
            $this->assertSame(1, substr_count($html, 'data-avatar-image'));
            // ไม่เปิดเผยเวลาเข้าร่วมหรือรายการ "เคยเข้าร่วม"
            $this->assertStringNotContainsString('เข้าร่วม ', $html);
            $this->assertStringNotContainsString('เคยเข้าร่วม', $html);
        }

        // ผู้แจ้งดึงรูปโปรไฟล์ของเจ้าหน้าที่ได้จริง (เส้นทางเดิมของทั้งระบบ)
        $this->actingAs($reporter)->get(route('media.profile', $helper))->assertOk();

        // หัวหน้าแผนกไม่เห็นแผงนี้และไม่เห็นรายชื่อเจ้าหน้าที่
        $this->actingAs($head)->get(route('tickets.preview', $ticket))->assertOk()
            ->assertDontSee('ผู้รับผิดชอบ')->assertDontSee('วีระ ซัพพอร์ต')->assertDontSee('ธนากร ไอที');
    }

    public function test_the_requester_sees_a_waiting_message_until_someone_takes_the_ticket(): void
    {
        $reporter = $this->staff($this->department('Sales'));
        $this->ticketFrom($reporter);

        $html = $this->actingAs($reporter)->get(route('tickets.index'))->assertOk()->getContent();

        $this->assertStringContainsString('>ผู้รับผิดชอบ</h3>', $html);
        $this->assertStringContainsString('ยังไม่มีเจ้าหน้าที่รับผิดชอบ', $html);
        $this->assertStringNotContainsString('data-avatar-image', $html);
    }

    public function test_notification_links_follow_the_viewers_permission(): void
    {
        $agent = $this->agent();
        $reporter = $this->staff($this->department('Sales'));
        $ticket = $this->ticketFrom($reporter);
        $this->startedBy($agent, $ticket);

        $notice = SystemNotification::query()->where('user_id', $reporter->id)->where('type', 'ticket_status_changed')->firstOrFail();
        $this->assertSame(route('tickets.index', ['ticket' => $ticket->id]), app(NotificationService::class)->target($notice, $reporter));

        $alert = SystemNotification::query()->where('user_id', $agent->id)->where('type', 'ticket_created')->first()
            ?? SystemNotification::query()->forceCreate([
                'user_id' => $agent->id, 'type' => 'ticket_created', 'category' => 'ticket', 'title' => 't', 'message' => 'm',
                'data' => ['ticket_id' => $ticket->id],
            ]);
        $this->assertSame(route('tickets.show', $ticket), app(NotificationService::class)->target($alert, $agent));
    }
}
