<?php

namespace Tests\Feature\Tickets;

use App\Models\Ticket;
use App\Models\TicketActivity;
use App\Models\TicketAttachment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * ปิดงานแบบขั้นตอน (components/close-modal) — ผลการแก้ไข (บังคับ) → รูปหลังแก้ไข (ไม่บังคับ) → ตรวจทาน
 * ฟอร์มเดียวส่ง PATCH ไป tickets.status.update ตามเดิม รูปผูกกับ activity ของการปิดงาน จึงเป็นข้อมูลภายใน
 */
class TicketCloseStepsTest extends TestCase
{
    use BuildsTickets;
    use BuildsTicketUploads;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
    }

    public function test_the_close_button_opens_a_three_step_form_with_quick_notes_and_an_after_fix_photo_picker(): void
    {
        $agent = $this->agent();
        $ticket = $this->startedBy($agent);

        $html = $this->actingAs($agent)->get(route('tickets.show', $ticket))->assertOk()->getContent();
        $modal = substr($html, (int) strpos($html, 'id="ticket-modal-close"'));
        $modal = substr($modal, 0, (int) strpos($modal, '</form>'));

        // ปุ่มปิดงานพื้นเขียวเข้ม ตัวอักษรขาว (ticket-actions__button--close ใน detail.css) ไม่ใช่เขียวอ่อนของปุ่มรับงาน
        $this->assertStringContainsString('class="ticket-button ticket-actions__button ticket-actions__button--close"', $html);
        $this->assertMatchesRegularExpression('~enctype="multipart/form-data" data-ticket-form data-ticket-stepper data-ticket-stepper-hash="#close-ticket"~', $modal);
        $this->assertSame(3, substr_count($modal, 'data-ticket-step-indicator='));
        $this->assertSeeInOrderIn(['ผลการแก้ไข', 'รูปหลังแก้ไข', 'ตรวจทาน'], $modal);
        $this->assertMatchesRegularExpression('~<textarea id="ticket-close-note" name="note"[^>]*required~s', $modal);
        $this->assertStringContainsString('data-ticket-quick-note="แก้ไขเรียบร้อย ทดสอบใช้งานได้ปกติ"', $modal);
        $this->assertStringContainsString('id="ticket-close-photos-input" type="file" name="photos[]"', $modal);
        // มือถือเลือกได้ทั้งถ่ายรูป (กล้องหลัง) และคลังรูป — input ของกล้องไม่มี name (form.js รวมเข้า photos[])
        $this->assertStringContainsString('id="ticket-close-photos-camera" type="file" accept="image/*" capture="environment"', $modal);
        $this->assertStringContainsString('เห็นเฉพาะเจ้าหน้าที่', $modal);
        $this->assertStringContainsString('data-ticket-review="note"', $modal);
        $this->assertStringContainsString('data-ticket-review="photos[]"', $modal);
        $this->assertStringContainsString('ticket-button--success ticket-form__submit', $modal);
        // ปิดงานเป็นที่เดียวที่แนบรูปได้ในหน้านี้ — ไม่มีปุ่ม/หน้าต่างบันทึกหรือแนบรูปแยก
        $this->assertSame(1, substr_count($html, 'id="ticket-close-photos-input"'));
        $this->assertStringNotContainsString('ticket-note-modal', $html);
    }

    public function test_after_fix_photos_belong_to_the_close_activity_and_stay_internal(): void
    {
        $sales = $this->overseenDepartment('Sales');
        $reporter = $this->reporterIn($sales);
        $head = $this->overseeingHead($sales);
        $agent = $this->agent();
        $ticket = $this->startedBy($agent, $this->ticketFrom($reporter));

        $this->actingAs($agent)->from(route('tickets.show', $ticket))
            ->patch(route('tickets.status.update', $ticket), [
                'action' => Ticket::ACTION_CLOSE,
                'expected_status' => Ticket::STATUS_ACTIVE,
                'note' => 'เปลี่ยนสาย LAN แล้ว ใช้งานได้ปกติ',
                'photos' => [$this->photo('after-1.jpg'), $this->photo('after-2.jpg')],
            ])
            ->assertRedirect(route('tickets.show', $ticket))
            ->assertSessionHasNoErrors();

        $close = TicketActivity::query()->where('ticket_id', $ticket->id)->where('to_status', Ticket::STATUS_CLOSED)->sole();
        $photos = TicketAttachment::query()->where('ticket_id', $ticket->id)->get();
        $this->assertSame(Ticket::STATUS_CLOSED, $ticket->fresh()->status);
        $this->assertSame('เปลี่ยนสาย LAN แล้ว ใช้งานได้ปกติ', $close->body);
        $this->assertCount(2, $photos);
        $this->assertSame([$close->id, $close->id], $photos->pluck('ticket_activity_id')->all());
        $photos->each(fn (TicketAttachment $photo) => Storage::disk('local')->assertExists($photo->file_path));

        // เจ้าหน้าที่เห็นรูปใน timeline ของการปิดงาน และเปิดไฟล์ได้
        $first = $photos->first();
        $this->actingAs($agent)->get(route('tickets.show', $ticket))->assertSee(route('media.ticket-attachments.show', $first), false);
        $this->actingAs($agent)->get(route('media.ticket-attachments.show', $first))->assertOk();

        // ผู้แจ้งและหัวหน้าแผนกเห็นผลการแก้ไข แต่ไม่เห็นรูปหลังแก้ไข และดึงไฟล์ตรงไม่ได้
        $this->actingAs($reporter)->get(route('tickets.index', ['ticket' => $ticket->id]))->assertOk()
            ->assertSee('เปลี่ยนสาย LAN แล้ว ใช้งานได้ปกติ')
            ->assertDontSee(route('media.ticket-attachments.show', $first), false);
        $this->actingAs($head)->get(route('tickets.preview', $ticket))->assertOk()
            ->assertDontSee(route('media.ticket-attachments.show', $first), false);
        $this->actingAs($reporter)->get(route('media.ticket-attachments.show', $first))->assertForbidden();
        $this->actingAs($head)->get(route('media.ticket-attachments.show', $first))->assertForbidden();
    }

    public function test_closing_without_photos_still_works(): void
    {
        $agent = $this->agent();
        $ticket = $this->startedBy($agent);

        $this->actingAs($agent)->patch(route('tickets.status.update', $ticket), [
            'action' => Ticket::ACTION_CLOSE, 'expected_status' => Ticket::STATUS_ACTIVE, 'note' => 'แก้ไขเรียบร้อย ทดสอบใช้งานได้ปกติ',
        ])->assertSessionHasNoErrors();

        $this->assertSame(Ticket::STATUS_CLOSED, $ticket->fresh()->status);
        $this->assertSame(0, TicketAttachment::query()->count());
    }

    public function test_photos_are_only_for_closing_and_follow_the_shared_photo_rules(): void
    {
        $agent = $this->agent();

        // แนบรูปกับการย้ายเป็นงานที่ต้องติดตาม → ปฏิเสธ สถานะไม่เปลี่ยน ไม่มีไฟล์
        $ticket = $this->startedBy($agent);
        $this->actingAs($agent)->from(route('tickets.show', $ticket))->patch(route('tickets.status.update', $ticket), [
            'action' => Ticket::ACTION_FOLLOW_UP, 'expected_status' => Ticket::STATUS_ACTIVE, 'note' => 'รออะไหล่',
            'photos' => [$this->photo()],
        ])->assertSessionHasErrors(['photos' => 'แนบรูปได้เฉพาะตอนปิดงาน']);
        $this->assertSame(Ticket::STATUS_ACTIVE, $ticket->fresh()->status);

        // เกิน 3 รูป หรือไฟล์ที่ไม่ใช่รูปจริง → ปฏิเสธ ยังไม่ปิดงาน
        foreach ([
            'photos' => [$this->photo('1.jpg'), $this->photo('2.jpg'), $this->photo('3.jpg'), $this->photo('4.jpg')],
            'photos.0' => [$this->disguisedPdf()],
        ] as $error => $photos) {
            $this->actingAs($agent)->from(route('tickets.show', $ticket))->patch(route('tickets.status.update', $ticket), [
                'action' => Ticket::ACTION_CLOSE, 'expected_status' => Ticket::STATUS_ACTIVE, 'note' => 'เรียบร้อย', 'photos' => $photos,
            ])->assertSessionHasErrors($error);
            $this->assertSame(Ticket::STATUS_ACTIVE, $ticket->fresh()->status, $error);
        }

        // หน้าที่ส่งไม่ผ่านเปิด modal ปิดงานค้างไว้พร้อมข้อความ — ข้อผิดพลาดของรูปไม่ไปโผล่ที่ฟอร์มบันทึก
        $html = $this->actingAs($agent)->get(route('tickets.show', $ticket))->getContent();
        $this->assertMatchesRegularExpression('/id="ticket-modal-close"[^>]*data-open-on-load="true"/s', $html);
        $this->assertStringNotContainsString('id="ticket-note-photos-error"', $html);
        $this->assertStringContainsString('id="ticket-close-photos-error"', $html);
        $this->assertSame(0, TicketAttachment::query()->count());
        $this->assertSame([], Storage::disk('local')->allFiles());
    }

    public function test_a_conflicting_close_stores_no_photos(): void
    {
        $agent = $this->agent();
        $ticket = $this->startedBy($agent);
        $this->transition($ticket, Ticket::ACTION_FOLLOW_UP, $agent, ['note' => 'รออะไหล่']);

        // หน้าจอเห็นสถานะ "กำลังดำเนินการ" แต่มีคนย้ายเป็นงานที่ต้องติดตามไปก่อน → 409 ไม่มีไฟล์ค้าง
        $this->actingAs($agent)->patchJson(route('tickets.status.update', $ticket), [
            'action' => Ticket::ACTION_CLOSE, 'expected_status' => Ticket::STATUS_ACTIVE, 'note' => 'เรียบร้อย',
            'photos' => [$this->photo()],
        ])->assertStatus(409);

        $this->assertSame(Ticket::STATUS_FOLLOW_UP, $ticket->fresh()->status);
        $this->assertSame(0, TicketAttachment::query()->count());
        $this->assertSame([], Storage::disk('local')->allFiles());
    }

    /** @param  array<int, string>  $needles */
    private function assertSeeInOrderIn(array $needles, string $haystack): void
    {
        $positions = array_map(fn (string $needle) => mb_strpos($haystack, $needle), $needles);
        $this->assertNotContains(false, $positions);
        $this->assertSame($positions, collect($positions)->sort()->values()->all());
    }
}
