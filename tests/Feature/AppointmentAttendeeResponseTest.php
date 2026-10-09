<?php

namespace Tests\Feature;

use App\Models\Appointment;
use App\Models\AppointmentAttendee;
use App\Models\AppointmentAttendeeResponseEvent;
use App\Models\Department;
use App\Models\SystemNotification;
use App\Models\User;
use App\Services\MeetingQueryService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * การตอบรับเข้าร่วมนัดหมาย — มิเรอร์ MeetingAttendeeResponseTest ทั้งชุด
 *
 * นัดหมายกับการประชุมมีวงจรตอบรับเหมือนกันในสายตาผู้ใช้ แต่เป็นคนละ domain
 * คนละตาราง คนละ service และคนละ policy ไฟล์นี้จึงพิสูจน์ของนัดหมายแยกต่างหาก
 */
class AppointmentAttendeeResponseTest extends TestCase
{
    use RefreshDatabase;

    private Department $department;

    protected function setUp(): void
    {
        parent::setUp();
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-10-08 09:00:00', MeetingQueryService::BUSINESS_TIMEZONE));
        $this->department = Department::create(['department_name' => 'IT']);
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    public function test_newly_invited_appointment_attendee_starts_as_pending(): void
    {
        $creator = $this->user();
        $attendee = $this->user();

        $this->actingAs($creator)
            ->post(route('appointments.store'), $this->payload(['attendees' => [$attendee->id]]))
            ->assertRedirect();

        $this->assertDatabaseHas('appointment_attendees', [
            'appointment_id' => Appointment::sole()->id,
            'user_id' => $attendee->id,
            'status' => AppointmentAttendee::STATUS_PENDING,
            'responded_at' => null,
            'decline_reason' => null,
        ]);

        $this->assertDatabaseHas('appointment_attendee_response_events', [
            'user_id' => $attendee->id,
            'event' => AppointmentAttendeeResponseEvent::EVENT_INVITED,
            'actor_user_id' => $creator->id,
        ]);
    }

    public function test_an_invited_attendee_is_notified_and_can_open_the_appointment(): void
    {
        [$appointment, , $attendee] = $this->appointmentWithAttendee();

        $notification = SystemNotification::where('user_id', $attendee->id)->sole();

        $this->assertSame('appointment_scheduled', $notification->type);
        $this->assertSame('appointment', $notification->category);
        $this->assertSame('มีนัดหมายใหม่', $notification->title);
        $this->assertStringContainsString('นัดหมอฟัน', $notification->message);
        // เวลาในข้อความต้องเป็นปี พ.ศ. เหมือนทุกหน้าในกลุ่มนี้
        $this->assertStringContainsString('2569', $notification->message);
        $this->assertSame((int) $appointment->id, (int) $notification->data['appointment_id']);

        // ผู้ถูกเชิญต้องเปิดนัดหมายได้ แม้ไม่ใช่เจ้าของ
        $this->actingAs($attendee)
            ->get(route('notifications.open', $notification->id))
            ->assertRedirect(route('appointments.show', ['appointment' => $appointment->id, 'respond' => 1]));
    }

    public function test_appointment_attendee_can_accept_invitation_and_the_response_time_is_stored(): void
    {
        [$appointment, , $attendee] = $this->appointmentWithAttendee();

        $this->actingAs($attendee)
            ->from(route('appointments.show', $appointment))
            ->post(route('appointments.attendance.accept', $appointment))
            ->assertRedirect(route('appointments.show', $appointment))
            ->assertSessionHas('meeting_success');

        $response = $appointment->fresh()->attendees->sole()->pivot;

        $this->assertSame(AppointmentAttendee::STATUS_ACCEPTED, $response->status);
        $this->assertSame(CarbonImmutable::now()->toDateTimeString(), $response->responded_at->toDateTimeString());
        $this->assertNull($response->decline_reason);
    }

    public function test_appointment_creator_receives_the_acceptance_notification(): void
    {
        [$appointment, $creator, $attendee] = $this->appointmentWithAttendee();

        $this->actingAs($attendee)->post(route('appointments.attendance.accept', $appointment))->assertRedirect();

        $notification = SystemNotification::where('user_id', $creator->id)->sole();

        $this->assertSame('appointment_attendee_accepted', $notification->type);
        $this->assertSame('appointment', $notification->category);
        $this->assertSame('ตอบรับนัดหมายแล้ว', $notification->title);
        $this->assertStringContainsString($attendee->name.' · IT', $notification->message);
        $this->assertStringContainsString('ตอบรับเข้าร่วมนัดหมาย', $notification->message);
        // แจ้งเตือนของผู้สร้างเป็นการรับทราบ ไม่ใช่คำขอคำตอบ จึงไม่ต่อธง respond
        $this->actingAs($creator)
            ->get(route('notifications.open', $notification->id))
            ->assertRedirect(route('appointments.show', $appointment));
    }

    public function test_appointment_attendee_must_provide_reason_when_declining(): void
    {
        [$appointment, $creator, $attendee] = $this->appointmentWithAttendee();
        SystemNotification::query()->delete();

        $this->actingAs($attendee)
            ->from(route('appointments.show', $appointment))
            ->post(route('appointments.attendance.decline', $appointment), ['decline_reason' => '   '])
            ->assertRedirect(route('appointments.show', $appointment))
            ->assertSessionHasErrors('decline_reason')
            ->assertSessionHas('meeting_open_modal', 'declineMeetingInvitationModal');

        $this->assertSame(AppointmentAttendee::STATUS_PENDING, $appointment->fresh()->attendees->sole()->pivot->status);
        $this->assertSame(0, SystemNotification::where('user_id', $creator->id)->count());
    }

    public function test_appointment_creator_receives_the_decline_notification_including_the_reason(): void
    {
        [$appointment, $creator, $attendee] = $this->appointmentWithAttendee();

        $this->actingAs($attendee)
            ->post(route('appointments.attendance.decline', $appointment), ['decline_reason' => '  ติดงาน Support  '])
            ->assertRedirect();

        $response = $appointment->fresh()->attendees->sole()->pivot;
        $this->assertSame(AppointmentAttendee::STATUS_DECLINED, $response->status);
        $this->assertSame('ติดงาน Support', $response->decline_reason);

        $notification = SystemNotification::where('user_id', $creator->id)->sole();
        $this->assertSame('appointment_attendee_declined', $notification->type);
        $this->assertSame('ไม่สะดวกเข้าร่วมนัดหมาย', $notification->title);
        $this->assertStringContainsString('เหตุผล: ติดงาน Support', $notification->message);
    }

    public function test_appointment_creator_can_see_every_attendee_response(): void
    {
        $creator = $this->user();
        $accepting = $this->user(['name' => 'Phunkorn']);
        $awaiting = $this->user(['name' => 'Benz']);
        $declining = $this->user(['name' => 'AUM']);

        $this->actingAs($creator)->post(route('appointments.store'), $this->payload([
            'attendees' => [$accepting->id, $awaiting->id, $declining->id],
        ]))->assertRedirect();
        $appointment = Appointment::sole();

        $this->actingAs($accepting)->post(route('appointments.attendance.accept', $appointment))->assertRedirect();
        $this->actingAs($declining)
            ->post(route('appointments.attendance.decline', $appointment), ['decline_reason' => 'ติดงาน Support'])
            ->assertRedirect();

        $summary = $this->actingAs($creator)
            ->get(route('appointments.show', $appointment))
            ->assertOk()
            ->assertSee('สถานะผู้เข้าร่วม')
            ->assertSee('ติดงาน Support')
            ->assertSee('เชิญอีกครั้ง')
            ->viewData('attendeeResponseSummary');

        $this->assertSame([
            AppointmentAttendee::STATUS_ACCEPTED => 1,
            AppointmentAttendee::STATUS_PENDING => 1,
            AppointmentAttendee::STATUS_DECLINED => 1,
        ], $summary['totals']);
    }

    public function test_other_attendees_cannot_see_the_attendee_responses(): void
    {
        [$appointment, , $attendee] = $this->appointmentWithAttendee();
        $otherAttendee = $this->user();
        $appointment->attendees()->attach($otherAttendee);

        $this->actingAs($attendee)
            ->post(route('appointments.attendance.decline', $appointment), ['decline_reason' => 'ลาป่วย'])
            ->assertRedirect();

        $response = $this->actingAs($otherAttendee)
            ->get(route('appointments.show', $appointment))
            ->assertOk();

        $this->assertNull($response->viewData('attendeeResponseSummary'));
        $response->assertDontSee('meetingAttendeeResponsesModal', false)->assertDontSee('ลาป่วย');
    }

    public function test_appointment_creator_can_reinvite_a_declined_attendee_who_may_then_answer_again(): void
    {
        [$appointment, $creator, $attendee] = $this->appointmentWithAttendee();

        $this->actingAs($attendee)
            ->post(route('appointments.attendance.decline', $appointment), ['decline_reason' => 'ติดธุระ'])
            ->assertRedirect();

        $this->actingAs($creator)
            ->post(route('appointments.attendance.reinvite', [$appointment, $attendee]))
            ->assertRedirect()
            ->assertSessionHas('meeting_success');

        $afterReinvite = $appointment->fresh()->attendees->sole()->pivot;
        $this->assertSame(AppointmentAttendee::STATUS_PENDING, $afterReinvite->status);
        $this->assertNull($afterReinvite->responded_at);
        $this->assertNull($afterReinvite->decline_reason);

        $reinvitation = SystemNotification::where('user_id', $attendee->id)
            ->where('type', 'appointment_attendee_reinvited')
            ->sole();
        $this->assertSame('เชิญเข้าร่วมนัดหมายอีกครั้ง', $reinvitation->title);

        $this->actingAs($attendee)->post(route('appointments.attendance.accept', $appointment))->assertRedirect();
        $this->assertSame(AppointmentAttendee::STATUS_ACCEPTED, $appointment->fresh()->attendees->sole()->pivot->status);
    }

    public function test_appointment_response_history_survives_a_reinvitation(): void
    {
        [$appointment, $creator, $attendee] = $this->appointmentWithAttendee();

        $this->actingAs($attendee)
            ->post(route('appointments.attendance.decline', $appointment), ['decline_reason' => 'ติดธุระ'])
            ->assertRedirect();
        $this->actingAs($creator)
            ->post(route('appointments.attendance.reinvite', [$appointment, $attendee]))
            ->assertRedirect();
        $this->actingAs($attendee)->post(route('appointments.attendance.accept', $appointment))->assertRedirect();

        $history = AppointmentAttendeeResponseEvent::query()
            ->forAppointment($appointment)
            ->where('user_id', $attendee->id)
            ->inChronologicalOrder()
            ->get();

        $this->assertSame([
            AppointmentAttendeeResponseEvent::EVENT_INVITED,
            AppointmentAttendeeResponseEvent::EVENT_DECLINED,
            AppointmentAttendeeResponseEvent::EVENT_REINVITED,
            AppointmentAttendeeResponseEvent::EVENT_ACCEPTED,
        ], $history->pluck('event')->all());

        // เหตุผลรอบก่อนหายจากสถานะปัจจุบันแล้ว แต่ยังอ่านย้อนหลังได้
        $this->assertSame('ติดธุระ', $history->firstWhere('event', AppointmentAttendeeResponseEvent::EVENT_DECLINED)->decline_reason);
        $this->assertSame($creator->id, (int) $history->firstWhere('event', AppointmentAttendeeResponseEvent::EVENT_REINVITED)->actor_user_id);
    }

    public function test_a_user_cannot_answer_an_appointment_on_behalf_of_somebody_else(): void
    {
        [$appointment, $creator, $attendee] = $this->appointmentWithAttendee();
        $outsider = $this->user();

        $this->actingAs($outsider)->post(route('appointments.attendance.accept', $appointment))->assertForbidden();
        $this->actingAs($outsider)
            ->post(route('appointments.attendance.decline', $appointment), ['decline_reason' => 'ไม่เกี่ยว'])
            ->assertForbidden();

        // ผู้สร้างที่ไม่ได้เชิญตัวเองก็ตอบแทนไม่ได้
        $this->actingAs($creator)->post(route('appointments.attendance.accept', $appointment))->assertForbidden();

        $this->assertSame(AppointmentAttendee::STATUS_PENDING, $appointment->fresh()->attendees->sole()->pivot->status);
        $this->assertSame($attendee->id, (int) $appointment->attendees()->sole()->id);
    }

    public function test_an_answered_appointment_invitation_cannot_be_changed_until_reinvited(): void
    {
        [$appointment, , $attendee] = $this->appointmentWithAttendee();

        $this->actingAs($attendee)->post(route('appointments.attendance.accept', $appointment))->assertRedirect();

        $this->actingAs($attendee)
            ->post(route('appointments.attendance.decline', $appointment), ['decline_reason' => 'เปลี่ยนใจ'])
            ->assertForbidden();

        $this->assertSame(AppointmentAttendee::STATUS_ACCEPTED, $appointment->fresh()->attendees->sole()->pivot->status);
    }

    public function test_only_the_appointment_creator_can_reinvite(): void
    {
        [$appointment, , $attendee] = $this->appointmentWithAttendee();
        $admin = $this->user([], 'admin');
        $otherAttendee = $this->user();
        $appointment->attendees()->attach($otherAttendee);

        $this->actingAs($attendee)
            ->post(route('appointments.attendance.decline', $appointment), ['decline_reason' => 'ติดธุระ'])
            ->assertRedirect();

        foreach ([$admin, $otherAttendee, $attendee] as $actor) {
            $this->actingAs($actor)
                ->post(route('appointments.attendance.reinvite', [$appointment, $attendee]))
                ->assertForbidden();
        }

        $this->assertSame(0, SystemNotification::where('type', 'appointment_attendee_reinvited')->count());
    }

    public function test_reinvitation_is_rejected_for_attendees_who_have_not_declined(): void
    {
        [$appointment, $creator, $attendee] = $this->appointmentWithAttendee();
        $stranger = $this->user();

        $this->actingAs($creator)
            ->post(route('appointments.attendance.reinvite', [$appointment, $attendee]))
            ->assertForbidden();

        $this->actingAs($creator)
            ->post(route('appointments.attendance.reinvite', [$appointment, $stranger]))
            ->assertForbidden();

        $this->assertSame(0, AppointmentAttendeeResponseEvent::query()
            ->where('event', AppointmentAttendeeResponseEvent::EVENT_REINVITED)
            ->count());
    }

    public function test_editing_an_appointment_keeps_existing_answers_and_only_invites_the_newcomers(): void
    {
        [$appointment, $creator, $attendee] = $this->appointmentWithAttendee();
        $newcomer = $this->user();

        $this->actingAs($attendee)->post(route('appointments.attendance.accept', $appointment))->assertRedirect();

        $this->actingAs($creator)
            ->patch(route('appointments.update', $appointment), $this->payload([
                'title' => 'เลื่อนนัดหมอฟัน',
                'attendees' => [$attendee->id, $newcomer->id],
            ]))
            ->assertRedirect();

        $attendees = $appointment->fresh()->attendees->keyBy('id');
        $this->assertSame(AppointmentAttendee::STATUS_ACCEPTED, $attendees[$attendee->id]->pivot->status);
        $this->assertSame(AppointmentAttendee::STATUS_PENDING, $attendees[$newcomer->id]->pivot->status);

        $invitedCount = fn (int $userId) => AppointmentAttendeeResponseEvent::query()
            ->where('user_id', $userId)
            ->where('event', AppointmentAttendeeResponseEvent::EVENT_INVITED)
            ->count();

        $this->assertSame(1, $invitedCount($newcomer->id));
        // คนเดิมไม่ถูกบันทึกว่า "ถูกเชิญ" ซ้ำเพียงเพราะมีการแก้ไขหัวข้อ
        $this->assertSame(1, $invitedCount($attendee->id));
    }

    public function test_removing_an_attendee_takes_the_appointment_off_their_calendar(): void
    {
        [$appointment, $creator, $attendee] = $this->appointmentWithAttendee();

        $this->assertNotEmpty($this->calendarAppointmentTitles($attendee));

        $this->actingAs($creator)
            ->patch(route('appointments.update', $appointment), $this->payload(['attendees' => []]))
            ->assertRedirect();

        $this->assertSame([], $this->calendarAppointmentTitles($attendee));
        $this->actingAs($attendee)->get(route('appointments.show', $appointment))->assertForbidden();
    }

    public function test_the_invited_attendee_sees_the_banner_and_the_creator_does_not(): void
    {
        [$appointment, $creator, $attendee] = $this->appointmentWithAttendee();

        $this->actingAs($attendee)
            ->get(route('appointments.show', $appointment))
            ->assertOk()
            ->assertSee('data-meeting-invitation-card', false)
            ->assertSee('meeting-rsvp-card--awaiting', false)
            ->assertSee('คำเชิญเข้าร่วมนัดหมาย')
            ->assertSee('เข้าร่วมนัดหมาย')
            ->assertSee('ไม่ว่างนัดหมาย')
            ->assertSee('data-meeting-invitation-mobile-actions', false);

        $this->actingAs($creator)
            ->get(route('appointments.show', $appointment))
            ->assertOk()
            ->assertDontSee('data-meeting-invitation-card', false)
            ->assertDontSee('data-meeting-invitation-mobile-actions', false);
    }

    public function test_the_confirm_dialog_only_opens_when_arriving_from_a_notification(): void
    {
        [$appointment, , $attendee] = $this->appointmentWithAttendee();

        // เปิดหน้าเอง — ไม่มีกล่องเด้ง
        $this->assertNull($this->openModalFor($attendee, $appointment, []));

        // มาจากแจ้งเตือนที่ขอคำตอบ — เด้งกล่องยืนยัน
        $this->assertSame('confirmInvitationModal', $this->openModalFor($attendee, $appointment, ['respond' => 1]));

        // ตอบไปแล้วถึงจะมีธงก็ไม่เด้ง
        $this->actingAs($attendee)->post(route('appointments.attendance.accept', $appointment))->assertRedirect();
        $this->assertNull($this->openModalFor($attendee, $appointment, ['respond' => 1]));
    }

    public function test_response_events_disappear_with_the_appointment_they_belong_to(): void
    {
        [$appointment, $creator, $attendee] = $this->appointmentWithAttendee();

        $this->actingAs($attendee)->post(route('appointments.attendance.accept', $appointment))->assertRedirect();
        $this->assertGreaterThan(0, AppointmentAttendeeResponseEvent::count());

        $this->actingAs($creator)->delete(route('appointments.destroy', $appointment))->assertRedirect();

        // นัดหมายใช้ SoftDeletes แถวประวัติยังอยู่เพื่อให้กู้คืนแล้วอ่านต่อได้
        $this->assertSoftDeleted('appointments', ['id' => $appointment->id]);
        $this->assertGreaterThan(0, AppointmentAttendeeResponseEvent::count());
    }

    /* ---------- helpers ---------- */

    /** @return array{0: Appointment, 1: User, 2: User} */
    private function appointmentWithAttendee(): array
    {
        $creator = $this->user();
        $attendee = $this->user();

        $this->actingAs($creator)
            ->post(route('appointments.store'), $this->payload(['attendees' => [$attendee->id]]))
            ->assertRedirect();

        return [Appointment::sole(), $creator, $attendee];
    }

    /** @return array<int, string> */
    private function calendarAppointmentTitles(User $viewer): array
    {
        return $this->actingAs($viewer)
            ->get(route('mytasks.index', ['view' => 'meeting', 'month' => '2026-10']))
            ->assertOk()
            ->viewData('meetingCalendarData')['calendarEvents']
            ->where('type', 'appointment')
            ->pluck('title')
            ->all();
    }

    /**
     * modal ที่หน้าสั่งให้เปิดตอนโหลด — อ่านจาก payload เดียวกับที่ JavaScript อ่าน
     *
     * @param  array<string, mixed>  $query
     */
    private function openModalFor(User $viewer, Appointment $appointment, array $query): ?string
    {
        $html = $this->actingAs($viewer)
            ->get(route('appointments.show', ['appointment' => $appointment->id] + $query))
            ->assertOk()
            ->getContent();

        preg_match('/data-meeting-feedback>(.*?)<\/script>/s', $html, $matches);

        return json_decode($matches[1] ?? '{}', true)['open_modal'] ?? null;
    }

    /** @param array<string, mixed> $attributes */
    private function user(array $attributes = [], string $role = 'user'): User
    {
        return User::factory()->create(array_merge([
            'role' => $role,
            'department_id' => $role === 'user' ? $this->department->id : null,
            'must_change_password' => false,
            'is_active' => true,
        ], $attributes));
    }

    /** @param array<string, mixed> $overrides */
    private function payload(array $overrides = []): array
    {
        return array_merge([
            'title' => 'นัดหมอฟัน',
            'appointment_date' => '2026-10-20',
            'starts_at_time' => '09:30',
            'ends_at_time' => '10:30',
            'location' => 'โรงพยาบาล',
            'description' => 'ตรวจฟันประจำปี',
            'attendees' => [],
            // ช่องบังคับของฟอร์มนัดหมาย — ชุดนี้ทดสอบรอบการตอบรับ ไม่ใช่ขอบเขตการมองเห็น
            // จึงใช้ "ส่วนตัว" เพื่อให้คนที่เห็นมีเฉพาะผู้สร้างกับผู้ที่ถูกเชิญเหมือนเดิม
            'visibility' => Appointment::VISIBILITY_PERSONAL,
        ], $overrides);
    }
}
