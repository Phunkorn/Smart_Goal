<?php

namespace App\Services;

use App\Models\Appointment;
use App\Models\User;
use App\Support\AuditTrail;
use App\Support\InvitationCandidates;
use Illuminate\Support\Facades\DB;

/**
 * การสร้าง แก้ไข และลบนัดหมาย
 *
 * เป็นที่เดียวที่กำหนด created_by ของนัดหมาย เพื่อไม่ให้ค่านี้มาจากฟอร์มได้
 * (Appointment::$fillable ไม่มี created_by โดยตั้งใจ)
 *
 * บันทึก AuditTrail ด้วยรูปแบบเดียวกับ MeetingController เพื่อให้หน้าบันทึกกิจกรรม
 * และถังขยะอ่านนัดหมายได้แบบเดียวกับข้อมูลอื่นในระบบ
 *
 * การแจ้งเตือนและประวัติการตอบรับเป็นหน้าที่ของ AppointmentAttendeeResponseService
 * ที่นี่แค่ผูกผู้ร่วมแล้วส่งต่อรายชื่อคนที่เพิ่งถูกเพิ่มไปให้บันทึก
 */
final class AppointmentService
{
    public function __construct(private readonly AppointmentAttendeeResponseService $attendeeResponses) {}

    /**
     * @param  array<string, mixed>  $appointmentAttributes
     * @param  array<int|string>  $attendeeIds
     */
    public function createAppointment(array $appointmentAttributes, User $appointmentOwner, array $attendeeIds = []): Appointment
    {
        [$appointment, $invitedIds] = DB::transaction(function () use ($appointmentAttributes, $appointmentOwner, $attendeeIds): array {
            $appointment = new Appointment($appointmentAttributes);
            $appointment->created_by = $appointmentOwner->id;
            /*
             * แผนกเจ้าของมาจากแผนกของผู้สร้าง "ตอนที่สร้าง" ไม่ใช่ค่าที่ฟอร์มส่งมา
             * และไม่ใช่การอ่านสด ๆ ตอนแสดงผล — เป็น snapshot แบบเดียวกับ
             * work_logs และ computer_check_sessions เพื่อให้นัดหมายของคนที่ย้ายแผนก
             * ยังอยู่บนปฏิทินของแผนกที่เป็นเจ้าของเรื่องนั้นจริงในตอนนั้น
             */
            $appointment->department_id = $appointmentOwner->department_id;
            $appointment->save();

            // ห้ามเชื่อรหัสที่ client ส่งมาตรง ๆ กรองผ่านกติกาเดียวกับผู้เข้าร่วมประชุม
            $invitedIds = InvitationCandidates::eligibleIds($attendeeIds);
            $appointment->attendees()->sync($invitedIds);

            AuditTrail::log('created', $appointment, 'สร้างนัดหมาย: '.$appointment->title, [
                'after' => $this->auditPayload($appointment, $invitedIds),
            ]);

            return [$appointment, $invitedIds];
        });

        // แจ้งเตือนหลัง commit เท่านั้น ถ้า rollback จะเหลือแจ้งเตือนของนัดหมายที่ไม่มีอยู่จริง
        $this->attendeeResponses->recordInvitations($appointment, $invitedIds, $appointmentOwner);

        return $appointment;
    }

    /**
     * @param  array<string, mixed>  $appointmentAttributes
     * @param  array<int|string>  $attendeeIds
     */
    public function updateAppointment(
        Appointment $appointment,
        array $appointmentAttributes,
        array $attendeeIds,
        User $appointmentOwner
    ): Appointment {
        $newcomerIds = DB::transaction(function () use ($appointment, $appointmentAttributes, $attendeeIds): array {
            $before = $this->auditPayload($appointment, $appointment->attendees()->pluck('users.id')->all());
            $appointment->update($appointmentAttributes);

            /*
             * sync() ไม่แตะแถวของผู้ร่วมเดิม คำตอบที่ตอบไว้แล้วจึงไม่ถูกรีเซ็ตเพราะการแก้ไข
             * ส่วนคนที่เพิ่งถูกเพิ่มเข้ามาต้องได้แจ้งเตือนและมีแถวประวัติ "ถูกเชิญ" ของตัวเอง
             */
            $invitedIds = InvitationCandidates::eligibleIds($attendeeIds);
            $changes = $appointment->attendees()->sync($invitedIds);

            AuditTrail::log('updated', $appointment, 'แก้ไขนัดหมาย: '.$appointment->title, [
                'before' => $before,
                'after' => $this->auditPayload($appointment->fresh(), $invitedIds),
            ]);

            return $changes['attached'];
        });

        $this->attendeeResponses->recordInvitations($appointment, $newcomerIds, $appointmentOwner);

        return $appointment;
    }

    public function deleteAppointment(Appointment $appointment): void
    {
        DB::transaction(function () use ($appointment): void {
            $payload = $this->auditPayload($appointment);

            // นัดหมายใช้ SoftDeletes จึงกู้คืนจากแถวเดิมได้ ไม่ต้องสำเนาทั้งแถวลง TrashLog
            // แบบที่การประชุมต้องทำ (Meeting ไม่มี SoftDeletes — ดู MeetingController::destroy)
            AuditTrail::trash($appointment, null, ['appointment' => $payload]);
            AuditTrail::log('deleted', $appointment, 'ลบนัดหมาย: '.$appointment->title, ['before' => $payload]);

            $appointment->delete();
        });
    }

    /**
     * @param  array<int>|null  $attendeeIds
     * @return array<string, mixed>
     */
    private function auditPayload(Appointment $appointment, ?array $attendeeIds = null): array
    {
        return [
            'title' => $appointment->title,
            'appointment_date' => $appointment->appointment_date?->format('Y-m-d'),
            'starts_at_time' => $appointment->starts_at_time,
            'ends_at_time' => $appointment->ends_at_time,
            'location' => $appointment->location,
            'map_url' => $appointment->map_url,
            'description' => $appointment->description,
            'created_by' => $appointment->created_by,
            'attendee_ids' => collect($attendeeIds ?? $appointment->attendees()->pluck('users.id')->all())
                ->map(fn ($id) => (int) $id)->unique()->sort()->values()->all(),
        ];
    }
}
