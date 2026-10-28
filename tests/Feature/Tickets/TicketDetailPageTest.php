<?php

namespace Tests\Feature\Tickets;

use App\Models\Ticket;
use App\Services\TicketNoteService;
use App\Services\TicketParticipantService;
use App\Services\TicketSubmissionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * หน้ารายละเอียด /tickets/{ticket} — สิทธิ์การเปิด, ปุ่มตามสิทธิ์จริง, timeline, รูป
 */
class TicketDetailPageTest extends TestCase
{
    use BuildsTickets;
    use BuildsTicketUploads;
    use RefreshDatabase;

    public function test_who_can_open_a_ticket_page(): void
    {
        $sales = $this->department('Sales');
        $requester = $this->staff($sales);
        $ticket = $this->ticketFrom($requester);
        $url = route('tickets.show', $ticket);

        $this->get($url)->assertRedirect(route('login'));

        // หน้ารายละเอียดเต็มเป็นของผู้ที่เห็นคิวงาน (Handler / admin) — ผู้แจ้งและหัวหน้าแผนกที่เห็นใบนี้ได้
        // ถูกพากลับ /tickets?ticket= เพื่อเปิด modal อ่านอย่างเดียว · คนที่ไม่มีสิทธิ์เห็นได้ 403 (เปลี่ยน id ไม่ได้อะไร)
        $back = route('tickets.index', ['ticket' => $ticket->id]);
        foreach ([
            'requester' => [$requester, 302],
            'same department head' => [$this->staff($sales, head: true), 302],
            'agent' => [$this->agent(), 200],
            'admin' => [$this->staff($this->department('Management'), role: 'admin'), 200],
            'colleague' => [$this->staff($sales), 403],
            'other department head' => [$this->staff($this->department('HR'), head: true), 403],
            'viewer' => [$this->staff($sales, role: 'viewer'), 403],
        ] as $who => [$user, $status]) {
            $response = $this->actingAs($user)->get($url)->assertStatus($status);
            if ($status === 302) {
                $response->assertRedirect($back);
            }
        }
    }

    public function test_read_only_viewers_of_a_ticket_see_no_action_buttons(): void
    {
        $sales = $this->department('Sales');
        $ticket = $this->ticketFrom($this->staff($sales));

        // admin (ไม่ใช่ Handler) เปิดหน้ารายละเอียดเต็มได้แต่ไม่มีปุ่ม · หัวหน้าแผนกเห็นผ่าน modal อ่านอย่างเดียว
        $pages = [
            'admin' => $this->actingAs($this->staff($this->department('Management'), role: 'admin'))->get(route('tickets.show', $ticket))->assertOk(),
            'department head' => $this->actingAs($this->staff($sales, head: true))->get(route('tickets.preview', $ticket))->assertOk()
                ->assertViewIs('tickets.components.readonly-preview'),
        ];
        foreach ($pages as $who => $page) {
            $page->assertDontSee('การดำเนินการ</h2>', false);
            $page->assertDontSee('name="action"', false);
            $page->assertDontSee(route('tickets.participants.store', $ticket), false);
        }
    }

    public function test_a_new_ticket_offers_only_take_to_agents(): void
    {
        $page = $this->actingAs($this->agent())->get(route('tickets.show', $this->publicTicket()))->assertOk();

        // ต้องรับงานก่อน (คนรับงานเข้าร่วมอัตโนมัติ) แล้วคนอื่นจึงเข้าร่วมได้ · ปุ่มรับงานสีเขียว ไม่ใช่ primary
        $page->assertSee('value="start"', false)->assertDontSee('data-ticket-join', false)
            ->assertSee('class="ticket-button ticket-button--success ticket-actions__button"', false)
            ->assertSee('bi-clipboard-check', false);
        $page->assertDontSee('data-bs-target="#ticket-modal-close"', false);
    }

    public function test_a_participant_sees_follow_up_close_and_cancel_while_another_agent_only_sees_join(): void
    {
        $worker = $this->agent();
        $ticket = $this->startedBy($worker);

        // ปุ่ม "ออกจาก Ticket" ถูกแทนที่ด้วย "ยกเลิกงาน" ในการ์ดการดำเนินการ
        $this->actingAs($worker)->get(route('tickets.show', $ticket))
            ->assertSee('data-bs-target="#ticket-modal-close"', false)
            ->assertSee('data-bs-target="#ticket-modal-follow-up"', false)
            ->assertSee('data-bs-target="#ticket-modal-cancel"', false)
            ->assertSee('id="ticket-modal-cancel"', false)
            ->assertDontSee('data-ticket-leave', false)
            ->assertSee('id="ticket-modal-close"', false)
            ->assertSee('name="expected_status" value="active"', false)
            ->assertDontSee('data-ticket-join', false);

        $this->actingAs($this->agent())->get(route('tickets.show', $ticket))
            ->assertSee('data-ticket-join', false)
            ->assertDontSee('data-bs-target="#ticket-modal-cancel"', false)
            ->assertDontSee('data-bs-target="#ticket-modal-close"', false)
            ->assertDontSee('id="ticket-modal-close"', false);
    }

    public function test_a_closed_ticket_offers_only_reopen(): void
    {
        $worker = $this->agent();
        $ticket = $this->transition($this->startedBy($worker), Ticket::ACTION_CLOSE, $worker, ['note' => 'เปลี่ยนเมาส์แล้ว']);

        $this->actingAs($worker)->get(route('tickets.show', $ticket))
            ->assertSee('data-bs-target="#ticket-modal-reopen"', false)
            ->assertDontSee('data-bs-target="#ticket-modal-close"', false)
            ->assertDontSee('data-ticket-leave', false)
            ->assertDontSee('data-ticket-join', false)
            ->assertDontSee('id="ticket-note-body"', false)
            ->assertSee('Ticket นี้ปิดแล้ว');
    }

    public function test_the_requester_sees_a_read_only_modal_without_notes_forms_or_actions(): void
    {
        $requester = $this->staff($this->department('Sales'));
        $ticket = $this->ticketFrom($requester);

        $html = $this->actingAs($requester)->get(route('tickets.index'))->assertOk()->getContent();
        $modal = substr($html, (int) strpos($html, 'id="ticket-reporter-modal"'));

        $this->assertStringContainsString($ticket->ticket_number, $modal);
        $this->assertStringNotContainsString('id="ticket-note-body"', $modal);
        $this->assertStringNotContainsString('name="action"', $modal);
        $this->assertStringNotContainsString('<form', $modal);
    }

    public function test_participants_coordinator_and_former_participants_are_listed(): void
    {
        $lead = $this->agent();
        $helper = $this->agent();
        $leaver = $this->agent();
        $ticket = $this->startedBy($lead);
        $participants = app(TicketParticipantService::class);
        $participants->join($ticket, $helper);
        $participants->join($ticket, $leaver);
        $participants->leave($ticket, $leaver);
        $participants->setCoordinator($ticket, $lead, $helper);

        $this->actingAs($lead)->get(route('tickets.show', $ticket))
            ->assertSeeInOrder(['เจ้าหน้าที่ที่เข้าร่วม', $lead->name, $helper->name, 'ผู้ประสานงาน', 'เคยเข้าร่วม', $leaver->name])
            ->assertSee('id="ticket-coordinator"', false);
    }

    public function test_participant_avatars_use_the_profile_image_and_fall_back_to_initials(): void
    {
        Storage::fake('public');
        $withPhoto = $this->agent();
        $withoutPhoto = $this->agent();
        $withPhoto->forceFill(['name' => 'วีระ ซัพพอร์ต', 'profile_image' => 'profiles/helper.jpg'])->save();
        $withoutPhoto->forceFill(['name' => 'ธนากร ไอที'])->save();
        Storage::disk('public')->put('profiles/helper.jpg', 'jpg-bytes');

        $ticket = $this->startedBy($withPhoto);
        app(TicketParticipantService::class)->join($ticket, $withoutPhoto);

        // ผู้ดูไม่มีรูปของตัวเอง รูปเดียวในหน้าจึงเป็นของผู้เข้าร่วม ไม่ใช่ avatar ที่แถบข้าง
        $viewer = $this->agent();

        // แผงเดียวกันทั้งหน้ารายละเอียดเต็มและ modal ของคิวงาน (components/participants)
        foreach ([route('tickets.show', $ticket), route('tickets.preview', $ticket)] as $url) {
            $html = $this->actingAs($viewer)->get($url)->assertOk()->getContent();

            $this->assertStringContainsString('<img src="'.route('media.profile', $withPhoto).'" alt="" data-avatar-image>', $html, $url);
            $this->assertStringNotContainsString(route('media.profile', $withoutPhoto), $html, $url);
            // ตัวย่อชื่อเรนเดอร์ไว้ข้างล่างเสมอ เป็นเนื้อหาสำรองของทั้งสองคน (avatar-fallback.js ถอด <img> เมื่อโหลดไม่ขึ้น)
            $this->assertStringContainsString('<span aria-hidden="true">ว</span>', $html, $url);
            $this->assertStringContainsString('<span aria-hidden="true">ธ</span>', $html, $url);
            $this->assertSame(1, substr_count($html, 'data-avatar-image'), $url);
        }

        // รูปถูกเสิร์ฟผ่านเส้นทางที่ตรวจสิทธิ์เดิม ไม่ใช่ path ตรงของ storage
        $this->actingAs($viewer)->get(route('media.profile', $withPhoto))->assertOk();
        $this->assertStringNotContainsString('profiles/helper.jpg', $this->actingAs($viewer)->get(route('tickets.show', $ticket))->getContent());
    }

    public function test_a_deleted_agent_keeps_the_name_snapshot_but_loses_the_avatar(): void
    {
        Storage::fake('public');
        $agent = $this->agent();
        $agent->forceFill(['name' => 'วีระ ซัพพอร์ต', 'profile_image' => 'profiles/gone.jpg'])->save();
        $ticket = $this->startedBy($agent);
        $agent->delete();

        $html = $this->actingAs($this->agent())->get(route('tickets.show', $ticket))->assertOk()->getContent();

        $this->assertStringContainsString('วีระ ซัพพอร์ต', $html);
        $this->assertStringNotContainsString('data-avatar-image', $html);
    }

    public function test_the_coordinator_picker_is_its_own_card_only_for_those_who_may_set_it(): void
    {
        $lead = $this->agent();
        $ticket = $this->startedBy($lead);

        $this->actingAs($lead)->get(route('tickets.show', $ticket))
            ->assertSeeInOrder(['id="ticket-people-title"', 'id="ticket-coordinator-title"', 'id="ticket-coordinator"'], false);

        // ไม่เข้าร่วม Ticket จึงตั้งผู้ประสานงานไม่ได้ — ไม่มีการ์ดนี้
        $this->actingAs($this->agent())->get(route('tickets.show', $ticket))
            ->assertDontSee('id="ticket-coordinator-title"', false)
            ->assertDontSee('id="ticket-coordinator"', false);
    }

    public function test_the_timeline_shows_every_action_and_escapes_user_text(): void
    {
        $agent = $this->agent();
        $ticket = $this->ticketFrom($this->staff($this->department('Sales')), '<script>alert(1)</script> จอดับ');
        $ticket = $this->startedBy($agent, $ticket);
        app(TicketNoteService::class)->add($ticket, $agent, 'เปลี่ยนสาย <b>HDMI</b> แล้ว');
        $this->transition($ticket, Ticket::ACTION_FOLLOW_UP, $agent, ['note' => 'รออะไหล่', 'follow_up_date' => now()->addDays(2)->toDateString()]);

        $this->actingAs($agent)->get(route('tickets.show', $ticket))
            ->assertSeeInOrder(['แจ้งปัญหา', 'เข้าร่วม Ticket', 'รับงาน', 'บันทึกการดำเนินงาน', 'ย้ายเป็นงานที่ต้องติดตาม', 'รออะไหล่', 'ติดตามอีกครั้ง'])
            ->assertDontSee('<script>alert(1)</script>', false)
            ->assertSee('&lt;script&gt;alert(1)&lt;/script&gt;', false)
            ->assertDontSee('<b>HDMI</b>', false);
    }

    public function test_photos_are_served_through_the_authorised_media_route_only(): void
    {
        Storage::fake('local');
        $requester = $this->staff($this->department('Sales'));
        $ticket = app(TicketSubmissionService::class)->submitInternal($requester, $this->internalForm(['location_id' => null]), [$this->photo()]);
        $photo = $ticket->attachments()->sole();

        // ผู้แจ้งเห็นรูปตอนแจ้งใน modal อ่านอย่างเดียว ผ่าน media route ที่ตรวจสิทธิ์ · Handler เห็นในหน้ารายละเอียด
        foreach ([[$requester, route('tickets.index')], [$this->agent(), route('tickets.show', $ticket)]] as [$user, $url]) {
            $this->actingAs($user)->get($url)
                ->assertSee(route('media.ticket-attachments.show', $photo), false)
                ->assertDontSee($photo->file_path, false)
                ->assertDontSee('storage/', false);
        }
    }

    public function test_a_validation_error_reopens_the_same_modal(): void
    {
        $worker = $this->agent();
        $ticket = $this->startedBy($worker);

        $this->actingAs($worker)->from(route('tickets.show', $ticket))
            ->patch(route('tickets.status.update', $ticket), ['action' => 'close', 'expected_status' => 'active', 'note' => ''])
            ->assertRedirect(route('tickets.show', $ticket))
            ->assertSessionHasErrors('note');

        $content = $this->actingAs($worker)->get(route('tickets.show', $ticket))->getContent();
        $this->assertMatchesRegularExpression('/id="ticket-modal-close"[^>]*data-open-on-load="true"/s', $content);
        $this->assertDoesNotMatchRegularExpression('/id="ticket-modal-follow-up"[^>]*data-open-on-load="true"/s', $content);
    }

    public function test_a_deleted_agent_still_appears_by_name(): void
    {
        $agent = $this->agent();
        $ticket = $this->startedBy($agent);
        $name = $agent->name;
        $agent->forceDelete();

        $this->actingAs($this->agent())->get(route('tickets.show', $ticket))
            ->assertOk()
            ->assertSee($name);
    }
}
