<?php

namespace App\Services;

use App\Models\Appointment;
use App\Models\AppointmentAttendee;
use App\Models\AppointmentAttendeeResponseEvent;
use App\Models\User;
use App\Support\ThaiDate;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * การตอบรับเข้าร่วมนัดหมายทั้งหมด: ตอบรับ แจ้งไม่ว่าง และการเชิญอีกครั้งของผู้สร้าง
 *
 * คู่ขนานกับ MeetingAttendeeResponseService แต่เป็นคนละ domain จึงไม่ใช้ร่วมกัน
 * (ดูเหตุผลใน create_appointment_attendees_table) ห้ามเรียกข้ามกัน
 *
 * เป็นที่เดียวที่เขียนสถานะบนแถว AppointmentAttendee ได้ เพราะทุกการเปลี่ยนสถานะ
 * ต้องเขียนแถวประวัติใน AppointmentAttendeeResponseEvent คู่กันไปด้วย
 *
 * กฎธุรกิจ: ผู้ร่วมตอบได้เมื่อสถานะเป็น pending เท่านั้น (บังคับที่
 * AppointmentPolicy::respondToInvitation) คำตอบที่ให้ไปแล้วเปลี่ยนเองไม่ได้
 * ต้องให้ผู้สร้างนัดหมาย "เชิญอีกครั้ง" ซึ่งรีเซ็ตกลับเป็น pending
 */
final class AppointmentAttendeeResponseService
{
    public function __construct(private readonly NotificationService $notifications) {}

    /**
     * แจ้งผู้ที่เพิ่งถูกเชิญ และบันทึกว่าถูกเชิญแล้ว
     *
     * ไม่แตะสถานะบน pivot เพราะแถวที่เพิ่งผูกเริ่มที่ pending จากค่า default อยู่แล้ว
     *
     * @param  array<int>  $attendeeIds
     */
    public function recordInvitations(Appointment $appointment, array $attendeeIds, User $appointmentCreator): void
    {
        $attendeeIds = $this->normalizedIds($attendeeIds);

        if ($attendeeIds === []) {
            return;
        }

        $invitedAt = CarbonImmutable::now();

        foreach ($attendeeIds as $attendeeId) {
            $this->appendResponseEvent(
                $appointment,
                $attendeeId,
                AppointmentAttendeeResponseEvent::EVENT_INVITED,
                (int) $appointmentCreator->id,
                null,
                $invitedAt
            );
        }

        $this->notifyAttendeesOfInvitation($appointment, $attendeeIds, $appointmentCreator);
    }

    public function acceptAppointmentInvitation(Appointment $appointment, User $appointmentAttendee): void
    {
        $this->recordAttendeeResponse(
            $appointment,
            $appointmentAttendee,
            AppointmentAttendee::STATUS_ACCEPTED,
            AppointmentAttendeeResponseEvent::EVENT_ACCEPTED,
            null
        );

        $this->notifyAppointmentCreatorOfAcceptance($appointment, $appointmentAttendee);
    }

    public function declineAppointmentInvitation(Appointment $appointment, User $appointmentAttendee, string $declineReason): void
    {
        $this->recordAttendeeResponse(
            $appointment,
            $appointmentAttendee,
            AppointmentAttendee::STATUS_DECLINED,
            AppointmentAttendeeResponseEvent::EVENT_DECLINED,
            $declineReason
        );

        $this->notifyAppointmentCreatorOfDecline($appointment, $appointmentAttendee, $declineReason);
    }

    /**
     * เชิญผู้ที่แจ้งไม่ว่างให้ตอบใหม่ — สถานะกลับเป็น pending และคำตอบเดิมหลุดจาก pivot
     * แต่ยังอ่านย้อนหลังได้จากแถวประวัติที่เขียนไว้ตอนตอบครั้งก่อน
     */
    public function reinviteAppointmentAttendee(Appointment $appointment, User $appointmentAttendee, User $appointmentCreator): void
    {
        $reinvitedAt = CarbonImmutable::now();

        DB::transaction(function () use ($appointment, $appointmentAttendee, $appointmentCreator, $reinvitedAt): void {
            $appointment->attendees()->updateExistingPivot($appointmentAttendee->id, [
                'status' => AppointmentAttendee::STATUS_PENDING,
                'responded_at' => null,
                'decline_reason' => null,
            ]);

            $this->appendResponseEvent(
                $appointment,
                (int) $appointmentAttendee->id,
                AppointmentAttendeeResponseEvent::EVENT_REINVITED,
                (int) $appointmentCreator->id,
                null,
                $reinvitedAt
            );
        });

        $this->notifyAttendeeOfReinvitation($appointment, $appointmentAttendee, $appointmentCreator);
    }

    private function recordAttendeeResponse(
        Appointment $appointment,
        User $appointmentAttendee,
        string $status,
        string $event,
        ?string $declineReason
    ): void {
        $respondedAt = CarbonImmutable::now();

        DB::transaction(function () use ($appointment, $appointmentAttendee, $status, $event, $declineReason, $respondedAt): void {
            $appointment->attendees()->updateExistingPivot($appointmentAttendee->id, [
                'status' => $status,
                'responded_at' => $respondedAt,
                'decline_reason' => $declineReason,
            ]);

            $this->appendResponseEvent(
                $appointment,
                (int) $appointmentAttendee->id,
                $event,
                (int) $appointmentAttendee->id,
                $declineReason,
                $respondedAt
            );
        });
    }

    private function appendResponseEvent(
        Appointment $appointment,
        int $attendeeId,
        string $event,
        ?int $actorUserId,
        ?string $declineReason,
        CarbonImmutable $occurredAt
    ): void {
        AppointmentAttendeeResponseEvent::create([
            'appointment_id' => $appointment->id,
            'user_id' => $attendeeId,
            'actor_user_id' => $actorUserId,
            'event' => $event,
            'decline_reason' => $declineReason,
            'occurred_at' => $occurredAt,
        ]);
    }

    /** @param  array<int>  $attendeeIds */
    private function notifyAttendeesOfInvitation(Appointment $appointment, array $attendeeIds, User $appointmentCreator): void
    {
        $this->notifications->notifyDetached(
            $attendeeIds,
            'appointment_scheduled',
            'มีนัดหมายใหม่',
            $appointment->title.' · '.ThaiDate::day($appointment->appointment_date).' เวลา '
                .ThaiDate::timeRangeLabel($appointment->starts_at_time, $appointment->ends_at_time),
            $appointmentCreator,
            ['appointment_id' => $appointment->id],
            dedupePrefix: 'appointment_scheduled:'.$appointment->id,
        );
    }

    private function notifyAppointmentCreatorOfAcceptance(Appointment $appointment, User $appointmentAttendee): void
    {
        $this->notifyAppointmentCreator(
            $appointment,
            'appointment_attendee_accepted',
            'ตอบรับนัดหมายแล้ว',
            $this->attendeeIdentity($appointmentAttendee).' ตอบรับเข้าร่วมนัดหมาย'."\n".'“'.$appointment->title.'”',
            $appointmentAttendee
        );
    }

    private function notifyAppointmentCreatorOfDecline(Appointment $appointment, User $appointmentAttendee, string $declineReason): void
    {
        $this->notifyAppointmentCreator(
            $appointment,
            'appointment_attendee_declined',
            'ไม่สะดวกเข้าร่วมนัดหมาย',
            $this->attendeeIdentity($appointmentAttendee).' ไม่สามารถเข้าร่วมนัดหมาย'."\n"
                .'“'.$appointment->title.'”'."\n\n".'เหตุผล: '.$declineReason,
            $appointmentAttendee
        );
    }

    private function notifyAttendeeOfReinvitation(Appointment $appointment, User $appointmentAttendee, User $appointmentCreator): void
    {
        $this->notifications->notifyDetached(
            [$appointmentAttendee->id],
            'appointment_attendee_reinvited',
            'เชิญเข้าร่วมนัดหมายอีกครั้ง',
            'ผู้สร้างนัดหมายเชิญคุณเข้าร่วม'."\n".'“'.$appointment->title.'” อีกครั้ง',
            $appointmentCreator,
            ['appointment_id' => $appointment->id],
        );
    }

    /**
     * ไม่ใส่ dedupePrefix โดยเจตนา — ผู้สร้างนัดหมายต้องเห็นทุกครั้งที่มีคนตอบ และหนึ่งคน
     * ตอบได้หลายครั้งเมื่อถูกเชิญอีกครั้ง การรวบเป็นใบเดียวจะกลืนคำตอบรอบใหม่หายไป
     */
    private function notifyAppointmentCreator(Appointment $appointment, string $type, string $title, string $message, User $appointmentAttendee): void
    {
        $this->notifications->notifyDetached(
            [$appointment->created_by],
            $type,
            $title,
            $message,
            $appointmentAttendee,
            ['appointment_id' => $appointment->id],
        );
    }

    private function attendeeIdentity(User $appointmentAttendee): string
    {
        $appointmentAttendee->loadMissing('department');

        return $appointmentAttendee->name.' · '.($appointmentAttendee->department?->department_name ?? 'ไม่ระบุแผนก');
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
