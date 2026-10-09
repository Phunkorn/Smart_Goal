<?php

namespace App\Services;

use App\Models\Meeting;
use App\Models\MeetingAttendee;
use App\Models\MeetingAttendeeResponseEvent;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * การตอบรับเข้าร่วมประชุมทั้งหมด: ตอบรับ แจ้งไม่ว่าง และการเชิญอีกครั้งของผู้สร้างประชุม
 *
 * เป็นที่เดียวที่เขียนสถานะบนแถว MeetingAttendee ได้ เพราะทุกการเปลี่ยนสถานะต้องเขียน
 * แถวประวัติใน MeetingAttendeeResponseEvent คู่กันไปด้วย ถ้า Controller เขียน pivot เอง
 * ประวัติจะขาดช่วงโดยไม่มีใครรู้
 *
 * กฎธุรกิจ: ผู้เข้าร่วมตอบได้เมื่อสถานะเป็น pending เท่านั้น (บังคับที่
 * MeetingPolicy::respondToInvitation) คำตอบที่ให้ไปแล้วเปลี่ยนเองไม่ได้ ต้องให้ผู้สร้าง
 * ประชุม "เชิญอีกครั้ง" ซึ่งรีเซ็ตกลับเป็น pending — ไม่อย่างนั้นปุ่มเชิญอีกครั้งจะไม่มีความหมาย
 */
final class MeetingAttendeeResponseService
{
    public function __construct(private readonly NotificationService $notifications) {}

    /**
     * บันทึกว่าผู้เข้าร่วมเหล่านี้ถูกเชิญแล้ว — เรียกตอนสร้างประชุมและตอนเพิ่มคนเข้าประชุมภายหลัง
     *
     * ไม่แตะสถานะบน pivot เพราะแถวที่เพิ่งผูกเริ่มที่ pending จากค่า default ของคอลัมน์อยู่แล้ว
     *
     * @param  array<int>  $attendeeIds
     */
    public function recordInvitations(Meeting $meeting, array $attendeeIds, User $meetingCreator): void
    {
        $invitedAt = CarbonImmutable::now();

        foreach ($this->normalizedIds($attendeeIds) as $attendeeId) {
            $this->appendResponseEvent(
                $meeting,
                $attendeeId,
                MeetingAttendeeResponseEvent::EVENT_INVITED,
                (int) $meetingCreator->id,
                null,
                $invitedAt
            );
        }
    }

    public function acceptMeetingInvitation(Meeting $meeting, User $meetingAttendee): void
    {
        $this->recordAttendeeResponse(
            $meeting,
            $meetingAttendee,
            MeetingAttendee::STATUS_ACCEPTED,
            MeetingAttendeeResponseEvent::EVENT_ACCEPTED,
            null
        );

        $this->notifyMeetingCreatorOfAcceptance($meeting, $meetingAttendee);
    }

    public function declineMeetingInvitation(Meeting $meeting, User $meetingAttendee, string $declineReason): void
    {
        $this->recordAttendeeResponse(
            $meeting,
            $meetingAttendee,
            MeetingAttendee::STATUS_DECLINED,
            MeetingAttendeeResponseEvent::EVENT_DECLINED,
            $declineReason
        );

        $this->notifyMeetingCreatorOfDecline($meeting, $meetingAttendee, $declineReason);
    }

    /**
     * เชิญผู้ที่แจ้งไม่ว่างให้ตอบใหม่ — สถานะกลับเป็น pending และคำตอบเดิมหลุดจาก pivot
     * แต่ยังอ่านย้อนหลังได้จากแถวประวัติที่เขียนไว้ตอนตอบครั้งก่อน
     */
    public function reinviteMeetingAttendee(Meeting $meeting, User $meetingAttendee, User $meetingCreator): void
    {
        $reinvitedAt = CarbonImmutable::now();

        DB::transaction(function () use ($meeting, $meetingAttendee, $meetingCreator, $reinvitedAt): void {
            $meeting->attendees()->updateExistingPivot($meetingAttendee->id, [
                'status' => MeetingAttendee::STATUS_PENDING,
                'responded_at' => null,
                'decline_reason' => null,
            ]);

            $this->appendResponseEvent(
                $meeting,
                (int) $meetingAttendee->id,
                MeetingAttendeeResponseEvent::EVENT_REINVITED,
                (int) $meetingCreator->id,
                null,
                $reinvitedAt
            );
        });

        $this->notifyAttendeeOfReinvitation($meeting, $meetingAttendee, $meetingCreator);
    }

    private function recordAttendeeResponse(
        Meeting $meeting,
        User $meetingAttendee,
        string $status,
        string $event,
        ?string $declineReason
    ): void {
        $respondedAt = CarbonImmutable::now();

        DB::transaction(function () use ($meeting, $meetingAttendee, $status, $event, $declineReason, $respondedAt): void {
            $meeting->attendees()->updateExistingPivot($meetingAttendee->id, [
                'status' => $status,
                'responded_at' => $respondedAt,
                'decline_reason' => $declineReason,
            ]);

            $this->appendResponseEvent(
                $meeting,
                (int) $meetingAttendee->id,
                $event,
                (int) $meetingAttendee->id,
                $declineReason,
                $respondedAt
            );
        });
    }

    private function appendResponseEvent(
        Meeting $meeting,
        int $attendeeId,
        string $event,
        ?int $actorUserId,
        ?string $declineReason,
        CarbonImmutable $occurredAt
    ): void {
        MeetingAttendeeResponseEvent::create([
            'meeting_id' => $meeting->id,
            'user_id' => $attendeeId,
            'actor_user_id' => $actorUserId,
            'event' => $event,
            'decline_reason' => $declineReason,
            'occurred_at' => $occurredAt,
        ]);
    }

    private function notifyMeetingCreatorOfAcceptance(Meeting $meeting, User $meetingAttendee): void
    {
        $this->notifyMeetingCreator(
            $meeting,
            'meeting_attendee_accepted',
            'ตอบรับการประชุมแล้ว',
            $this->attendeeIdentity($meetingAttendee).' ตอบรับเข้าร่วมประชุม'."\n".'“'.$meeting->title.'”',
            $meetingAttendee
        );
    }

    private function notifyMeetingCreatorOfDecline(Meeting $meeting, User $meetingAttendee, string $declineReason): void
    {
        $this->notifyMeetingCreator(
            $meeting,
            'meeting_attendee_declined',
            'ไม่สะดวกเข้าร่วมประชุม',
            $this->attendeeIdentity($meetingAttendee).' ไม่สามารถเข้าร่วมประชุม'."\n"
                .'“'.$meeting->title.'”'."\n\n".'เหตุผล: '.$declineReason,
            $meetingAttendee
        );
    }

    private function notifyAttendeeOfReinvitation(Meeting $meeting, User $meetingAttendee, User $meetingCreator): void
    {
        $this->notifications->notifyDetached(
            [$meetingAttendee->id],
            'meeting_attendee_reinvited',
            'เชิญเข้าร่วมประชุมอีกครั้ง',
            'ผู้สร้างประชุมเชิญคุณเข้าร่วม'."\n".'“'.$meeting->title.'” อีกครั้ง',
            $meetingCreator,
            ['meeting_id' => $meeting->id],
        );
    }

    /**
     * ไม่ใส่ dedupePrefix โดยเจตนา — ผู้สร้างประชุมต้องเห็นทุกครั้งที่มีคนตอบ และหนึ่งคน
     * ตอบได้หลายครั้งเมื่อถูกเชิญอีกครั้ง การรวบเป็นใบเดียวจะกลืนคำตอบรอบใหม่หายไป
     */
    private function notifyMeetingCreator(Meeting $meeting, string $type, string $title, string $message, User $meetingAttendee): void
    {
        $this->notifications->notifyDetached(
            [$meeting->created_by],
            $type,
            $title,
            $message,
            $meetingAttendee,
            ['meeting_id' => $meeting->id],
        );
    }

    private function attendeeIdentity(User $meetingAttendee): string
    {
        $meetingAttendee->loadMissing('department');

        return $meetingAttendee->name.' · '.($meetingAttendee->department?->department_name ?? 'ไม่ระบุแผนก');
    }

    /**
     * @param  array<int|string>  $attendeeIds
     * @return array<int>
     */
    private function normalizedIds(array $attendeeIds): array
    {
        return collect($attendeeIds)
            ->map(fn ($attendeeId) => (int) $attendeeId)
            ->filter()
            ->unique()
            ->values()
            ->all();
    }
}
