<?php

namespace Tests\Feature;

use App\Models\Department;
use App\Models\Meeting;
use App\Models\MeetingAttendee;
use App\Models\MeetingAttendeeResponseEvent;
use App\Models\SystemNotification;
use App\Models\User;
use App\Services\MeetingQueryService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MeetingAttendeeResponseTest extends TestCase
{
    use RefreshDatabase;

    private Department $department;

    protected function setUp(): void
    {
        parent::setUp();
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-08-23 12:00:00', MeetingQueryService::BUSINESS_TIMEZONE));
        $this->department = Department::create(['department_name' => 'IT']);
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    public function test_newly_invited_meeting_attendee_starts_as_pending(): void
    {
        $meetingCreator = $this->user();
        $meetingAttendee = $this->user();

        $this->actingAs($meetingCreator)
            ->post(route('meetings.store'), $this->payload(['attendees' => [$meetingAttendee->id]]))
            ->assertRedirect();

        $this->assertDatabaseHas('meeting_attendees', [
            'meeting_id' => Meeting::sole()->id,
            'user_id' => $meetingAttendee->id,
            'status' => MeetingAttendee::STATUS_PENDING,
            'responded_at' => null,
            'decline_reason' => null,
        ]);

        $this->assertDatabaseHas('meeting_attendee_response_events', [
            'user_id' => $meetingAttendee->id,
            'event' => MeetingAttendeeResponseEvent::EVENT_INVITED,
            'actor_user_id' => $meetingCreator->id,
        ]);
    }

    public function test_meeting_attendee_can_accept_invitation_and_the_response_time_is_stored(): void
    {
        [$meeting, $meetingCreator, $meetingAttendee] = $this->meetingWithAttendee();

        $this->actingAs($meetingAttendee)
            ->from(route('meetings.show', $meeting))
            ->post(route('meetings.attendance.accept', $meeting))
            ->assertRedirect(route('meetings.show', $meeting))
            ->assertSessionHas('meeting_success');

        $attendeeResponse = $meeting->fresh()->attendees->sole()->pivot;

        $this->assertSame(MeetingAttendee::STATUS_ACCEPTED, $attendeeResponse->status);
        $this->assertNotNull($attendeeResponse->responded_at);
        $this->assertSame(CarbonImmutable::now()->toDateTimeString(), $attendeeResponse->responded_at->toDateTimeString());
        $this->assertNull($attendeeResponse->decline_reason);

        $this->assertSame($meetingCreator->id, (int) $meeting->created_by);
    }

    public function test_meeting_creator_receives_the_acceptance_notification(): void
    {
        [$meeting, $meetingCreator, $meetingAttendee] = $this->meetingWithAttendee();

        $this->actingAs($meetingAttendee)->post(route('meetings.attendance.accept', $meeting))->assertRedirect();

        $notification = SystemNotification::where('user_id', $meetingCreator->id)->sole();

        $this->assertSame('meeting_attendee_accepted', $notification->type);
        $this->assertSame('meeting', $notification->category);
        $this->assertSame((int) $meetingAttendee->id, (int) $notification->actor_user_id);
        $this->assertSame('ตอบรับการประชุมแล้ว', $notification->title);
        $this->assertStringContainsString($meetingAttendee->name.' · IT', $notification->message);
        $this->assertStringContainsString('ตอบรับเข้าร่วมประชุม', $notification->message);
        $this->assertStringContainsString($meeting->title, $notification->message);
        $this->assertSame((int) $meeting->id, (int) $notification->data['meeting_id']);

        // ผู้สร้างประชุมต้องกดแจ้งเตือนแล้วเข้าหน้าประชุมใบนั้นได้
        $this->actingAs($meetingCreator)
            ->get(route('notifications.open', $notification->id))
            ->assertRedirect(route('meetings.show', $meeting));
    }

    public function test_meeting_attendee_can_decline_invitation_with_a_reason(): void
    {
        [$meeting, , $meetingAttendee] = $this->meetingWithAttendee();

        $this->actingAs($meetingAttendee)
            ->post(route('meetings.attendance.decline', $meeting), ['decline_reason' => '  ติดประชุมกับลูกค้า  '])
            ->assertRedirect()
            ->assertSessionHas('meeting_success');

        $attendeeResponse = $meeting->fresh()->attendees->sole()->pivot;

        $this->assertSame(MeetingAttendee::STATUS_DECLINED, $attendeeResponse->status);
        $this->assertSame('ติดประชุมกับลูกค้า', $attendeeResponse->decline_reason);
        $this->assertNotNull($attendeeResponse->responded_at);
    }

    public function test_meeting_attendee_must_provide_reason_when_declining(): void
    {
        [$meeting, $meetingCreator, $meetingAttendee] = $this->meetingWithAttendee();

        $this->actingAs($meetingAttendee)
            ->from(route('meetings.show', $meeting))
            ->post(route('meetings.attendance.decline', $meeting), ['decline_reason' => '   '])
            ->assertRedirect(route('meetings.show', $meeting))
            ->assertSessionHasErrors('decline_reason')
            // ต้องกลับไปเปิด modal เหตุผลใบเดิม ไม่ใช่ modal แก้ไขประชุม
            ->assertSessionHas('meeting_open_modal', 'declineMeetingInvitationModal');

        $this->assertSame(MeetingAttendee::STATUS_PENDING, $meeting->fresh()->attendees->sole()->pivot->status);
        $this->assertSame(0, SystemNotification::where('user_id', $meetingCreator->id)->count());
    }

    public function test_meeting_creator_receives_the_decline_notification_including_the_reason(): void
    {
        [$meeting, $meetingCreator, $meetingAttendee] = $this->meetingWithAttendee();

        $this->actingAs($meetingAttendee)
            ->post(route('meetings.attendance.decline', $meeting), ['decline_reason' => 'ติดงาน Support'])
            ->assertRedirect();

        $notification = SystemNotification::where('user_id', $meetingCreator->id)->sole();

        $this->assertSame('meeting_attendee_declined', $notification->type);
        $this->assertSame('meeting', $notification->category);
        $this->assertSame('ไม่สะดวกเข้าร่วมประชุม', $notification->title);
        $this->assertStringContainsString('ไม่สามารถเข้าร่วมประชุม', $notification->message);
        $this->assertStringContainsString('เหตุผล: ติดงาน Support', $notification->message);
    }

    public function test_meeting_creator_can_see_every_attendee_response(): void
    {
        $meetingCreator = $this->user();
        $accepting = $this->user(attributes: ['name' => 'Phunkorn']);
        $awaiting = $this->user(attributes: ['name' => 'Benz']);
        $declining = $this->user(attributes: ['name' => 'AUM']);
        $meeting = $this->meeting($meetingCreator);
        $meeting->attendees()->sync([$accepting->id, $awaiting->id, $declining->id]);

        $this->actingAs($accepting)->post(route('meetings.attendance.accept', $meeting))->assertRedirect();
        $this->actingAs($declining)
            ->post(route('meetings.attendance.decline', $meeting), ['decline_reason' => 'ติดงาน Support'])
            ->assertRedirect();

        $response = $this->actingAs($meetingCreator)->get(route('meetings.show', $meeting))->assertOk();

        $summary = $response->viewData('attendeeResponseSummary');

        $this->assertSame([
            MeetingAttendee::STATUS_ACCEPTED => 1,
            MeetingAttendee::STATUS_PENDING => 1,
            MeetingAttendee::STATUS_DECLINED => 1,
        ], $summary['totals']);

        $response->assertSee('สถานะผู้เข้าร่วม')
            ->assertSee('meetingAttendeeResponsesModal', false)
            ->assertSee('รอตอบรับ')
            ->assertSee('เข้าร่วม')
            ->assertSee('ไม่ว่าง')
            ->assertSee('ติดงาน Support')
            ->assertSee('เชิญอีกครั้ง');
    }

    public function test_attendees_and_unrelated_users_cannot_see_the_attendee_responses(): void
    {
        [$meeting, , $meetingAttendee] = $this->meetingWithAttendee();
        $otherAttendee = $this->user();
        $meeting->attendees()->attach($otherAttendee);
        $admin = $this->user('admin');

        $this->actingAs($meetingAttendee)
            ->post(route('meetings.attendance.decline', $meeting), ['decline_reason' => 'ลาป่วย'])
            ->assertRedirect();

        // ผู้เข้าร่วมคนอื่นและ admin ยังเปิดหน้าประชุมได้ แต่ต้องไม่เห็นคำตอบรายบุคคล
        foreach ([$otherAttendee, $admin] as $viewer) {
            $response = $this->actingAs($viewer)->get(route('meetings.show', $meeting))->assertOk();

            $this->assertNull($response->viewData('attendeeResponseSummary'));
            $response->assertDontSee('meetingAttendeeResponsesModal', false)
                ->assertDontSee('ลาป่วย');
        }
    }

    public function test_meeting_creator_can_reinvite_a_declined_attendee_who_may_then_answer_again(): void
    {
        [$meeting, $meetingCreator, $meetingAttendee] = $this->meetingWithAttendee();

        $this->actingAs($meetingAttendee)
            ->post(route('meetings.attendance.decline', $meeting), ['decline_reason' => 'ติดประชุมกับลูกค้า'])
            ->assertRedirect();

        $this->actingAs($meetingCreator)
            ->post(route('meetings.attendance.reinvite', [$meeting, $meetingAttendee]))
            ->assertRedirect()
            ->assertSessionHas('meeting_success');

        $afterReinvite = $meeting->fresh()->attendees->sole()->pivot;
        $this->assertSame(MeetingAttendee::STATUS_PENDING, $afterReinvite->status);
        $this->assertNull($afterReinvite->responded_at);
        $this->assertNull($afterReinvite->decline_reason);

        $reinvitation = SystemNotification::where('user_id', $meetingAttendee->id)
            ->where('type', 'meeting_attendee_reinvited')
            ->sole();
        $this->assertSame('เชิญเข้าร่วมประชุมอีกครั้ง', $reinvitation->title);
        $this->assertStringContainsString('อีกครั้ง', $reinvitation->message);
        $this->assertSame((int) $meeting->id, (int) $reinvitation->data['meeting_id']);

        // ตอบใหม่ได้จริงหลังถูกเชิญอีกครั้ง
        $this->actingAs($meetingAttendee)->post(route('meetings.attendance.accept', $meeting))->assertRedirect();
        $this->assertSame(MeetingAttendee::STATUS_ACCEPTED, $meeting->fresh()->attendees->sole()->pivot->status);
    }

    public function test_response_history_survives_a_reinvitation(): void
    {
        [$meeting, $meetingCreator, $meetingAttendee] = $this->meetingWithAttendee();

        $this->actingAs($meetingAttendee)
            ->post(route('meetings.attendance.decline', $meeting), ['decline_reason' => 'ติดประชุมกับลูกค้า'])
            ->assertRedirect();
        $this->actingAs($meetingCreator)
            ->post(route('meetings.attendance.reinvite', [$meeting, $meetingAttendee]))
            ->assertRedirect();
        $this->actingAs($meetingAttendee)->post(route('meetings.attendance.accept', $meeting))->assertRedirect();

        $history = MeetingAttendeeResponseEvent::query()
            ->forMeeting($meeting)
            ->where('user_id', $meetingAttendee->id)
            ->inChronologicalOrder()
            ->get();

        $this->assertSame([
            MeetingAttendeeResponseEvent::EVENT_INVITED,
            MeetingAttendeeResponseEvent::EVENT_DECLINED,
            MeetingAttendeeResponseEvent::EVENT_REINVITED,
            MeetingAttendeeResponseEvent::EVENT_ACCEPTED,
        ], $history->pluck('event')->all());

        // เหตุผลที่ปฏิเสธรอบก่อนหายไปจากสถานะปัจจุบันแล้ว แต่ยังอ่านย้อนหลังได้
        $this->assertSame('ติดประชุมกับลูกค้า', $history->firstWhere('event', MeetingAttendeeResponseEvent::EVENT_DECLINED)->decline_reason);
        $this->assertSame($meetingCreator->id, (int) $history->firstWhere('event', MeetingAttendeeResponseEvent::EVENT_REINVITED)->actor_user_id);
        $this->assertSame($meetingAttendee->id, (int) $history->firstWhere('event', MeetingAttendeeResponseEvent::EVENT_ACCEPTED)->actor_user_id);
    }

    public function test_a_user_cannot_answer_the_rsvp_on_behalf_of_somebody_else(): void
    {
        [$meeting, $meetingCreator, $meetingAttendee] = $this->meetingWithAttendee();
        $outsider = $this->user();

        // ไม่ได้ถูกเชิญ จึงตอบไม่ได้เลย และ route ไม่รับรหัสผู้ตอบให้สวมสิทธิ์คนอื่น
        $this->actingAs($outsider)->post(route('meetings.attendance.accept', $meeting))->assertForbidden();
        $this->actingAs($outsider)
            ->post(route('meetings.attendance.decline', $meeting), ['decline_reason' => 'ไม่เกี่ยวข้อง'])
            ->assertForbidden();

        // ผู้สร้างประชุมที่ไม่ได้ถูกเชิญเองก็ตอบแทนไม่ได้
        $this->actingAs($meetingCreator)->post(route('meetings.attendance.accept', $meeting))->assertForbidden();

        $this->assertSame(MeetingAttendee::STATUS_PENDING, $meeting->fresh()->attendees->sole()->pivot->status);
        $this->assertSame($meetingAttendee->id, (int) $meeting->attendees()->sole()->id);
    }

    public function test_an_answered_invitation_cannot_be_changed_until_the_creator_reinvites(): void
    {
        [$meeting, , $meetingAttendee] = $this->meetingWithAttendee();

        $this->actingAs($meetingAttendee)->post(route('meetings.attendance.accept', $meeting))->assertRedirect();

        $this->actingAs($meetingAttendee)
            ->post(route('meetings.attendance.decline', $meeting), ['decline_reason' => 'เปลี่ยนใจ'])
            ->assertForbidden();

        $this->assertSame(MeetingAttendee::STATUS_ACCEPTED, $meeting->fresh()->attendees->sole()->pivot->status);
    }

    public function test_only_the_meeting_creator_can_reinvite_a_declined_attendee(): void
    {
        [$meeting, , $meetingAttendee] = $this->meetingWithAttendee();
        $admin = $this->user('admin');
        $otherAttendee = $this->user();
        $meeting->attendees()->attach($otherAttendee);

        $this->actingAs($meetingAttendee)
            ->post(route('meetings.attendance.decline', $meeting), ['decline_reason' => 'ติดประชุมกับลูกค้า'])
            ->assertRedirect();

        foreach ([$admin, $otherAttendee, $meetingAttendee] as $actor) {
            $this->actingAs($actor)
                ->post(route('meetings.attendance.reinvite', [$meeting, $meetingAttendee]))
                ->assertForbidden();
        }

        $this->assertSame(MeetingAttendee::STATUS_DECLINED, $meeting->fresh()->attendees->firstWhere('id', $meetingAttendee->id)->pivot->status);
        $this->assertSame(0, SystemNotification::where('type', 'meeting_attendee_reinvited')->count());
    }

    public function test_reinvitation_is_rejected_for_attendees_who_have_not_declined(): void
    {
        [$meeting, $meetingCreator, $meetingAttendee] = $this->meetingWithAttendee();
        $stranger = $this->user();

        // ยังรอตอบรับ — ไม่มีอะไรต้องเชิญซ้ำ
        $this->actingAs($meetingCreator)
            ->post(route('meetings.attendance.reinvite', [$meeting, $meetingAttendee]))
            ->assertForbidden();

        // ไม่ได้อยู่ในประชุมใบนี้เลย
        $this->actingAs($meetingCreator)
            ->post(route('meetings.attendance.reinvite', [$meeting, $stranger]))
            ->assertForbidden();

        $this->assertSame(0, MeetingAttendeeResponseEvent::query()
            ->where('event', MeetingAttendeeResponseEvent::EVENT_REINVITED)
            ->count());
    }

    public function test_editing_a_meeting_keeps_existing_answers_and_only_invites_the_newcomers(): void
    {
        [$meeting, $meetingCreator, $meetingAttendee] = $this->meetingWithAttendee();
        $newcomer = $this->user();

        $this->actingAs($meetingAttendee)->post(route('meetings.attendance.accept', $meeting))->assertRedirect();

        $this->actingAs($meetingCreator)
            ->patch(route('meetings.update', $meeting), $this->payload([
                'title' => 'Product sync (ย้ายห้อง)',
                'attendees' => [$meetingAttendee->id, $newcomer->id],
            ]))
            ->assertRedirect();

        $attendees = $meeting->fresh()->attendees->keyBy('id');

        $this->assertSame(MeetingAttendee::STATUS_ACCEPTED, $attendees[$meetingAttendee->id]->pivot->status);
        $this->assertSame(MeetingAttendee::STATUS_PENDING, $attendees[$newcomer->id]->pivot->status);

        $this->assertSame(1, MeetingAttendeeResponseEvent::query()
            ->where('user_id', $newcomer->id)
            ->where('event', MeetingAttendeeResponseEvent::EVENT_INVITED)
            ->count());
        // คนเดิมไม่ถูกบันทึกว่า "ถูกเชิญ" ซ้ำเพียงเพราะมีการแก้ไขหัวข้อประชุม
        $this->assertSame(1, MeetingAttendeeResponseEvent::query()
            ->where('user_id', $meetingAttendee->id)
            ->where('event', MeetingAttendeeResponseEvent::EVENT_INVITED)
            ->count());
    }

    public function test_invited_attendee_sees_the_rsvp_buttons_and_others_do_not(): void
    {
        [$meeting, $meetingCreator, $meetingAttendee] = $this->meetingWithAttendee();
        $outsider = $this->user('admin');

        $this->actingAs($meetingAttendee)
            ->get(route('meetings.show', $meeting))
            ->assertOk()
            ->assertSee('เข้าร่วมประชุม')
            ->assertSee('ไม่ว่างประชุม')
            ->assertSee('declineMeetingInvitationModal', false);

        foreach ([$meetingCreator, $outsider] as $viewer) {
            $this->actingAs($viewer)
                ->get(route('meetings.show', $meeting))
                ->assertOk()
                ->assertDontSee('ไม่ว่างประชุม')
                ->assertDontSee('declineMeetingInvitationModal', false);
        }
    }

    public function test_accepted_attendee_sees_a_success_card_without_any_rsvp_button(): void
    {
        [$meeting, , $meetingAttendee] = $this->meetingWithAttendee();

        $this->actingAs($meetingAttendee)->post(route('meetings.attendance.accept', $meeting))->assertRedirect();

        $this->actingAs($meetingAttendee)
            ->get(route('meetings.show', $meeting))
            ->assertOk()
            ->assertSee('data-meeting-invitation-card', false)
            ->assertSee('meeting-rsvp-card--accepted', false)
            ->assertSee('คุณตอบรับเข้าร่วมประชุมแล้ว')
            // เวลาที่ตอบต้องเป็นเวลาไทยและปี พ.ศ. ตามที่ระบบใช้ทั่วทั้งรายงานและประกาศ
            ->assertSee('ตอบเมื่อ')
            ->assertSee('2569')
            ->assertDontSee('ไม่ว่างประชุม')
            ->assertDontSee('declineMeetingInvitationModal', false)
            ->assertDontSee('data-meeting-invitation-mobile-actions', false);
    }

    public function test_declined_attendee_sees_their_reason_and_cannot_answer_again(): void
    {
        [$meeting, , $meetingAttendee] = $this->meetingWithAttendee();

        $this->actingAs($meetingAttendee)
            ->post(route('meetings.attendance.decline', $meeting), ['decline_reason' => 'ติดประชุมกับลูกค้า'])
            ->assertRedirect();

        $this->actingAs($meetingAttendee)
            ->get(route('meetings.show', $meeting))
            ->assertOk()
            ->assertSee('meeting-rsvp-card--declined', false)
            ->assertSee('คุณแจ้งว่าไม่สะดวกเข้าร่วมประชุม')
            ->assertSee('เหตุผล: ติดประชุมกับลูกค้า')
            ->assertSee('ตอบเมื่อ')
            ->assertDontSee('declineMeetingInvitationModal', false)
            ->assertDontSee('data-meeting-invitation-mobile-actions', false);
    }

    public function test_pending_attendee_sees_the_invitation_card_above_the_meeting_details(): void
    {
        [$meeting, , $meetingAttendee] = $this->meetingWithAttendee();

        $html = $this->actingAs($meetingAttendee)
            ->get(route('meetings.show', $meeting))
            ->assertOk()
            ->assertSee('คำเชิญเข้าร่วมประชุม')
            ->assertSee('คุณได้รับเชิญให้เข้าร่วมการประชุมนี้ กรุณาตรวจสอบรายละเอียดและตอบกลับ')
            ->getContent();

        // ลำดับการมองเห็น: การ์ดคำเชิญต้องมาก่อนแผงรายละเอียดการประชุม ไม่ใช่อยู่ท้ายหน้า
        $this->assertLessThan(
            strpos($html, 'meetings-page__detail-main'),
            strpos($html, 'data-meeting-invitation-card'),
            'การ์ดคำเชิญต้องอยู่เหนือแผงรายละเอียดการประชุม'
        );

        // และต้องไม่อยู่ในแถบปุ่มของผู้สร้างประชุมอีกต่อไป
        preg_match('/<div class="meetings-page__actions">.*?<\/div>\s*<\/header>/s', $html, $actionBar);
        $this->assertNotEmpty($actionBar, 'ต้องเจอแถบปุ่มมุมขวาบนในหน้า');
        $this->assertStringNotContainsString('meetings.attendance.accept', $actionBar[0]);
        $this->assertStringNotContainsString('declineMeetingInvitationModal', $actionBar[0]);
    }

    public function test_a_reinvited_attendee_is_told_the_invitation_is_a_second_one(): void
    {
        [$meeting, $meetingCreator, $meetingAttendee] = $this->meetingWithAttendee();

        $this->actingAs($meetingAttendee)
            ->get(route('meetings.show', $meeting))
            ->assertOk()
            ->assertSee('คำเชิญเข้าร่วมประชุม')
            ->assertDontSee('ผู้จัดประชุมเชิญคุณเข้าร่วมอีกครั้ง');

        $this->actingAs($meetingAttendee)
            ->post(route('meetings.attendance.decline', $meeting), ['decline_reason' => 'ติดประชุมกับลูกค้า'])
            ->assertRedirect();
        $this->actingAs($meetingCreator)
            ->post(route('meetings.attendance.reinvite', [$meeting, $meetingAttendee]))
            ->assertRedirect();

        $response = $this->actingAs($meetingAttendee)
            ->get(route('meetings.show', $meeting))
            ->assertOk()
            ->assertSee('ผู้จัดประชุมเชิญคุณเข้าร่วมอีกครั้ง')
            ->assertSee('กรุณาตอบกลับคำเชิญใหม่')
            ->assertSee('data-meeting-invitation-mobile-actions', false);

        $this->assertTrue($response->viewData('viewerInvitation')['isReinvitation']);
    }

    public function test_the_sticky_mobile_action_bar_exists_only_while_a_reply_is_pending(): void
    {
        [$meeting, $meetingCreator, $meetingAttendee] = $this->meetingWithAttendee();

        $this->actingAs($meetingAttendee)
            ->get(route('meetings.show', $meeting))
            ->assertOk()
            ->assertSee('data-meeting-invitation-mobile-actions', false)
            // หน้าต้องเว้นที่ด้านล่างให้แถบปุ่ม ไม่ให้ทับเนื้อหา
            ->assertSee('meetings-page--awaiting-invitation-response', false);

        // ผู้สร้างประชุมที่ไม่ได้ถูกเชิญ ไม่มีแถบปุ่มตอบรับใด ๆ
        $this->actingAs($meetingCreator)
            ->get(route('meetings.show', $meeting))
            ->assertOk()
            ->assertDontSee('data-meeting-invitation-mobile-actions', false)
            ->assertDontSee('meetings-page--awaiting-invitation-response', false);

        $this->actingAs($meetingAttendee)->post(route('meetings.attendance.accept', $meeting))->assertRedirect();

        $this->actingAs($meetingAttendee)
            ->get(route('meetings.show', $meeting))
            ->assertOk()
            ->assertDontSee('data-meeting-invitation-mobile-actions', false)
            ->assertDontSee('meetings-page--awaiting-invitation-response', false);
    }

    public function test_creator_action_bar_keeps_its_own_buttons_and_no_attendee_actions(): void
    {
        [$meeting, $meetingCreator] = $this->meetingWithAttendee();

        $html = $this->actingAs($meetingCreator)
            ->get(route('meetings.show', $meeting))
            ->assertOk()
            ->assertSee('สถานะผู้เข้าร่วม')
            ->assertSee('แก้ไข')
            ->assertSee('ลบ')
            ->assertDontSee('data-meeting-invitation-card', false)
            ->getContent();

        preg_match('/<div class="meetings-page__actions">.*?<\/div>\s*<\/header>/s', $html, $actionBar);
        $this->assertNotEmpty($actionBar);
        $this->assertStringContainsString('meetingAttendeeResponsesModal', $actionBar[0]);
        $this->assertStringContainsString('editMeetingModal', $actionBar[0]);
        $this->assertStringNotContainsString('meetings.attendance.accept', $actionBar[0]);
    }

    public function test_a_meeting_without_attendees_still_renders_the_response_panel_empty(): void
    {
        $meetingCreator = $this->user();

        $this->actingAs($meetingCreator)
            ->post(route('meetings.store'), $this->payload())
            ->assertRedirect();

        $response = $this->actingAs($meetingCreator)
            ->get(route('meetings.show', Meeting::sole()))
            ->assertOk()
            ->assertSee('ยังไม่มีผู้เข้าร่วมในการประชุมนี้')
            ->assertDontSee('เชิญอีกครั้ง');

        $this->assertTrue($response->viewData('attendeeResponseSummary')['attendees']->isEmpty());
        $this->assertNull($response->viewData('viewerInvitation'));
    }

    public function test_response_events_disappear_with_the_meeting_they_belong_to(): void
    {
        [$meeting, $meetingCreator, $meetingAttendee] = $this->meetingWithAttendee();

        $this->actingAs($meetingAttendee)->post(route('meetings.attendance.accept', $meeting))->assertRedirect();
        $this->assertGreaterThan(0, MeetingAttendeeResponseEvent::count());

        $this->actingAs($meetingCreator)->delete(route('meetings.destroy', $meeting))->assertRedirect();

        $this->assertSame(0, MeetingAttendeeResponseEvent::count());
    }

    public function test_the_confirm_dialog_only_opens_when_arriving_from_a_notification(): void
    {
        [$meeting, , $meetingAttendee] = $this->meetingWithAttendee();

        // เปิดหน้าเอง — ไม่มีกล่องเด้ง ผู้ที่มาดูรายละเอียดไม่ได้ตั้งใจมาตอบ
        $this->assertNull($this->openModalFor($meetingAttendee, $meeting, []));

        // มาจากแจ้งเตือนที่ขอคำตอบ — เด้งกล่องยืนยันให้ตอบได้ทันที
        $this->assertSame('confirmInvitationModal', $this->openModalFor($meetingAttendee, $meeting, ['respond' => 1]));

        // ตอบไปแล้วถึงจะมีธงก็ไม่เด้ง
        $this->actingAs($meetingAttendee)->post(route('meetings.attendance.accept', $meeting))->assertRedirect();
        $this->assertNull($this->openModalFor($meetingAttendee, $meeting, ['respond' => 1]));
    }

    public function test_the_notification_link_carries_the_respond_flag_only_when_an_answer_is_wanted(): void
    {
        [$meeting, $meetingCreator, $meetingAttendee] = $this->meetingWithAttendee();

        // คำเชิญ — ขอคำตอบ จึงต่อธง respond
        $invitation = SystemNotification::where('user_id', $meetingAttendee->id)->sole();
        $this->actingAs($meetingAttendee)
            ->get(route('notifications.open', $invitation->id))
            ->assertRedirect(route('meetings.show', ['meeting' => $meeting->id, 'respond' => 1]));

        $this->actingAs($meetingAttendee)->post(route('meetings.attendance.accept', $meeting))->assertRedirect();

        // ผู้สร้างได้รับเพียงการรับทราบ ไม่ต้องตอบอะไร จึงไม่มีธง
        $acknowledgement = SystemNotification::where('user_id', $meetingCreator->id)->sole();
        $this->actingAs($meetingCreator)
            ->get(route('notifications.open', $acknowledgement->id))
            ->assertRedirect(route('meetings.show', $meeting));
    }

    public function test_a_pending_invitation_banner_is_louder_than_an_answered_one(): void
    {
        [$meeting, , $meetingAttendee] = $this->meetingWithAttendee();

        $this->actingAs($meetingAttendee)
            ->get(route('meetings.show', $meeting))
            ->assertOk()
            ->assertSee('meeting-rsvp-card--awaiting', false)
            // แบนเนอร์บอกวัน-เวลาเองเลย ผู้ใช้ไม่ต้องเลื่อนลงไปอ่านก่อนตัดสินใจ
            ->assertSee('meeting-rsvp-card__schedule', false);

        $this->actingAs($meetingAttendee)->post(route('meetings.attendance.accept', $meeting))->assertRedirect();

        $this->actingAs($meetingAttendee)
            ->get(route('meetings.show', $meeting))
            ->assertOk()
            ->assertDontSee('meeting-rsvp-card--awaiting', false)
            ->assertDontSee('meeting-rsvp-card__schedule', false);
    }

    public function test_a_meeting_map_link_must_be_an_http_address(): void
    {
        $meetingCreator = $this->user();

        foreach (['javascript:alert(1)', 'data:text/html,x', 'ไม่ใช่ลิงก์'] as $unsafeLink) {
            $this->actingAs($meetingCreator)
                ->post(route('meetings.store'), $this->payload(['map_url' => $unsafeLink]))
                ->assertSessionHasErrors('map_url');
        }

        $this->assertSame(0, Meeting::count());

        $this->actingAs($meetingCreator)
            ->post(route('meetings.store'), $this->payload(['map_url' => 'https://maps.app.goo.gl/abc123']))
            ->assertRedirect();

        $this->assertSame('https://maps.app.goo.gl/abc123', Meeting::sole()->map_url);
    }

    public function test_the_map_link_opens_in_a_new_tab_without_leaking_the_opener(): void
    {
        $meetingCreator = $this->user();

        $this->actingAs($meetingCreator)
            ->post(route('meetings.store'), $this->payload(['map_url' => 'https://maps.app.goo.gl/abc123']))
            ->assertRedirect();

        $this->actingAs($meetingCreator)
            ->get(route('meetings.show', Meeting::sole()))
            ->assertOk()
            ->assertSee('เปิดแผนที่')
            ->assertSee('href="https://maps.app.goo.gl/abc123" target="_blank" rel="noopener"', false);
    }

    /**
     * modal ที่หน้าสั่งให้เปิดตอนโหลด — อ่านจาก payload เดียวกับที่ JavaScript อ่าน
     *
     * @param  array<string, mixed>  $query
     */
    private function openModalFor(User $viewer, Meeting $meeting, array $query): ?string
    {
        $html = $this->actingAs($viewer)
            ->get(route('meetings.show', ['meeting' => $meeting->id] + $query))
            ->assertOk()
            ->getContent();

        preg_match('/data-meeting-feedback>(.*?)<\/script>/s', $html, $matches);

        return json_decode($matches[1] ?? '{}', true)['open_modal'] ?? null;
    }

    /**
     * ประชุมหนึ่งใบที่สร้างผ่าน endpoint จริง เพื่อให้แถว pivot และประวัติ "ถูกเชิญ"
     * ถูกสร้างตามเส้นทางเดียวกับการใช้งานจริง ไม่ใช่ประกอบด้วยมือในเทสต์
     *
     * @return array{0: Meeting, 1: User, 2: User}
     */
    private function meetingWithAttendee(): array
    {
        $meetingCreator = $this->user();
        $meetingAttendee = $this->user();

        $this->actingAs($meetingCreator)
            ->post(route('meetings.store'), $this->payload(['attendees' => [$meetingAttendee->id]]))
            ->assertRedirect();

        return [Meeting::sole(), $meetingCreator, $meetingAttendee];
    }

    private function user(string $role = 'user', array $attributes = []): User
    {
        return User::factory()->create(array_merge([
            'role' => $role,
            'department_id' => $role === 'user' ? $this->department->id : null,
            'must_change_password' => false,
            'is_active' => true,
        ], $attributes));
    }

    private function meeting(User $meetingCreator, array $attributes = []): Meeting
    {
        return Meeting::create(array_merge([
            'title' => 'Placeholder meeting',
            'starts_at' => CarbonImmutable::parse('2026-08-24 10:00', MeetingQueryService::BUSINESS_TIMEZONE)->utc(),
            'ends_at' => CarbonImmutable::parse('2026-08-24 11:00', MeetingQueryService::BUSINESS_TIMEZONE)->utc(),
            'created_by' => $meetingCreator->id,
        ], $attributes));
    }

    private function payload(array $overrides = []): array
    {
        return array_merge([
            'title' => 'Product sync',
            'description' => 'Weekly product sync',
            'starts_at' => '2026-08-24T10:00',
            'ends_at' => '2026-08-24T11:00',
            'location' => 'ห้องประชุมชั้น 2',
            'attendees' => [],
        ], $overrides);
    }
}
