<?php

namespace App\Services;

use App\Models\Appointment;
use App\Models\AppointmentAttendee;
use App\Models\AppointmentAttendeeResponseEvent;
use App\Models\User;
use App\Support\InvitationCandidates;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

/**
 * ข้อมูลที่หน้ารายละเอียดนัดหมายต้องใช้
 *
 * คู่ขนานกับ MeetingQueryService::detailData() / attendeeResponseSummary() เพื่อให้
 * Blade ของสองหน้าใช้รูปร่างข้อมูลเดียวกันได้ และใช้ partial ของคำเชิญร่วมกัน
 *
 * Blade ไม่คิวรีและไม่แปลงโซนเวลาเอง ทุกอย่างถูกเตรียมมาจากที่นี่
 */
final class AppointmentQueryService
{
    /** @return array<string, mixed> */
    public function detailData(User $viewer, Appointment $appointment): array
    {
        $appointment->load(['creator.department', 'attendees.department']);

        return [
            'appointment' => $appointment,
            'viewerInvitation' => $this->viewerInvitation($appointment, $viewer),
            'attendeeResponseSummary' => $viewer->can('viewAttendeeResponses', $appointment)
                ? $this->attendeeResponseSummary($appointment)
                : null,
            'attendeeOptions' => $viewer->can('update', $appointment)
                ? InvitationCandidates::browsableOptions($appointment->attendees->pluck('id')->all())
                : collect(),
            'attendeeDepartments' => $viewer->can('update', $appointment) ? InvitationCandidates::departments() : collect(),
        ];
    }

    /**
     * คำเชิญของผู้เปิดหน้านี้ สำหรับการ์ดตอบรับเข้าร่วม
     *
     * isReinvitation บอกว่าคำเชิญรอบนี้มาจากการที่ผู้สร้างกด "เชิญอีกครั้ง" อ่านจาก
     * ประวัติที่ AppointmentAttendeeResponseService เขียนไว้แล้ว ไม่ได้เพิ่มสถานะใหม่
     * คิวรีประวัติถูกถามเฉพาะตอนที่ยังรอตอบอยู่
     *
     * @return array{status: string, respondedAt: CarbonInterface|null, declineReason: string|null, isReinvitation: bool}|null
     */
    public function viewerInvitation(Appointment $appointment, User $viewer): ?array
    {
        $appointment->loadMissing('attendees');
        $invitation = $appointment->attendees->firstWhere('id', $viewer->id)?->pivot;

        if (! $invitation) {
            return null;
        }

        return [
            'status' => $invitation->status,
            'respondedAt' => $invitation->responded_at?->copy()->timezone(MeetingQueryService::BUSINESS_TIMEZONE),
            'declineReason' => $invitation->decline_reason,
            // Blade ใช้ค่านี้แทนการเทียบกับค่าคงที่ของโมเดล จึงใช้การ์ดคำเชิญใบเดียวกันได้ทั้งสอง domain
            'hasAccepted' => $invitation->hasAccepted(),
            'isReinvitation' => $invitation->isAwaitingResponse()
                && $this->latestInvitationEvent($appointment, $viewer) === AppointmentAttendeeResponseEvent::EVENT_REINVITED,
        ];
    }

    /**
     * คำตอบของผู้ร่วมทุกคน พร้อมประวัติย้อนหลังของแต่ละคน
     *
     * ประวัติถูกดึงมาทีเดียวแล้วจัดกลุ่มในหน่วยความจำ ไม่ใช่คิวรีต่อผู้ร่วมหนึ่งคน
     *
     * @return array{attendees: Collection<int, array<string, mixed>>, totals: array<string, int>}
     */
    public function attendeeResponseSummary(Appointment $appointment): array
    {
        $appointment->loadMissing('attendees.department');

        $historyByAttendee = AppointmentAttendeeResponseEvent::query()
            ->forAppointment($appointment)
            ->inChronologicalOrder()
            ->get()
            ->groupBy('user_id');

        $attendees = $appointment->attendees
            ->map(fn (User $appointmentAttendee): array => [
                'name' => $appointmentAttendee->name,
                'department' => $appointmentAttendee->department?->department_name ?? 'ไม่ระบุแผนก',
                'attendee' => $appointmentAttendee,
                'status' => $appointmentAttendee->pivot->status,
                'respondedAt' => $appointmentAttendee->pivot->responded_at?->copy()->timezone(MeetingQueryService::BUSINESS_TIMEZONE),
                'declineReason' => $appointmentAttendee->pivot->decline_reason,
                'canBeReinvited' => $appointmentAttendee->pivot->hasDeclined(),
                'reinviteUrl' => route('appointments.attendance.reinvite', [$appointment, $appointmentAttendee]),
                'history' => collect($historyByAttendee[$appointmentAttendee->id] ?? [])
                    ->map(fn (AppointmentAttendeeResponseEvent $responseEvent): array => [
                        'event' => $responseEvent->event,
                        'occurredAt' => $responseEvent->occurred_at->copy()->timezone(MeetingQueryService::BUSINESS_TIMEZONE),
                        'declineReason' => $responseEvent->decline_reason,
                    ])
                    ->values(),
            ])
            ->values();

        return [
            'attendees' => $attendees,
            'totals' => [
                AppointmentAttendee::STATUS_ACCEPTED => $attendees->where('status', AppointmentAttendee::STATUS_ACCEPTED)->count(),
                AppointmentAttendee::STATUS_PENDING => $attendees->where('status', AppointmentAttendee::STATUS_PENDING)->count(),
                AppointmentAttendee::STATUS_DECLINED => $attendees->where('status', AppointmentAttendee::STATUS_DECLINED)->count(),
            ],
        ];
    }

    private function latestInvitationEvent(Appointment $appointment, User $viewer): ?string
    {
        return AppointmentAttendeeResponseEvent::query()
            ->forAppointment($appointment)
            ->where('user_id', $viewer->id)
            ->orderByDesc('occurred_at')
            ->orderByDesc('id')
            ->value('event');
    }
}
