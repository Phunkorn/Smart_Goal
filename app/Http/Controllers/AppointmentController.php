<?php

namespace App\Http\Controllers;

use App\Models\Appointment;
use App\Services\AppointmentQueryService;
use App\Services\AppointmentService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * นัดหมายของผู้ใช้ — สร้าง ดู แก้ไข ลบ
 *
 * แยกจาก MeetingController เพราะนัดหมายเป็นคนละ domain ไม่มีผู้เข้าร่วมและไม่มีการตอบรับ
 * (ดู create_appointments_table) ตรรกะการบันทึกอยู่ใน AppointmentService
 */
class AppointmentController extends Controller
{
    public function store(Request $request, AppointmentService $appointments): RedirectResponse
    {
        Gate::authorize('create', Appointment::class);

        $appointment = $appointments->createAppointment(
            $this->validatedAppointment($request),
            $request->user(),
            $this->requestedAttendeeIds($request)
        );

        return redirect()->route('appointments.show', $appointment)
            ->with('meeting_success', 'บันทึกนัดหมายเรียบร้อยแล้ว');
    }

    public function show(Request $request, Appointment $appointment, AppointmentQueryService $appointments): View
    {
        Gate::authorize('view', $appointment);

        return view('appointments.show', $appointments->detailData($request->user(), $appointment));
    }

    public function update(Request $request, Appointment $appointment, AppointmentService $appointments): RedirectResponse
    {
        Gate::authorize('update', $appointment);

        $appointments->updateAppointment(
            $appointment,
            $this->validatedAppointment($request),
            $this->requestedAttendeeIds($request),
            $request->user()
        );

        return redirect()->route('appointments.show', $appointment)
            ->with('meeting_success', 'แก้ไขนัดหมายเรียบร้อยแล้ว');
    }

    public function destroy(Appointment $appointment, AppointmentService $appointments): RedirectResponse
    {
        Gate::authorize('delete', $appointment);

        $appointments->deleteAppointment($appointment);

        return redirect()->route('mytasks.index', ['view' => 'meeting'])
            ->with('meeting_success', 'ลบนัดหมายเรียบร้อยแล้ว');
    }

    /**
     * รหัสผู้ร่วมที่ฟอร์มส่งมา — ยังไม่ผ่านการตรวจสิทธิ์
     * AppointmentService กรองผ่าน InvitationCandidates อีกชั้นเสมอ
     *
     * @return array<int|string>
     */
    private function requestedAttendeeIds(Request $request): array
    {
        $attendeeIds = $request->input('attendees', []);

        return is_array($attendeeIds) ? $attendeeIds : [];
    }

    /** @return array<string, mixed> */
    private function validatedAppointment(Request $request): array
    {
        return $request->validate([
            'title' => ['required', 'string', 'max:160'],
            'appointment_date' => ['required', 'date_format:Y-m-d'],
            'starts_at_time' => ['required', 'date_format:H:i'],
            'ends_at_time' => ['nullable', 'date_format:H:i', 'after:starts_at_time'],
            'location' => ['nullable', 'string', 'max:255'],
            // url:http,https ไม่ใช่ url เปล่า — url เปล่ายอมรับ javascript: ซึ่งจะกลายเป็น XSS ใน href
            'map_url' => ['nullable', 'url:http,https', 'max:2048'],
            'description' => ['nullable', 'string', 'max:5000'],
            'attendees' => ['nullable', 'array'],
            'attendees.*' => ['integer'],
            /*
             * ขอบเขตการมองเห็น — "ส่วนตัว" หรือ "แผนก" เท่านั้น
             *
             * ไม่มีค่าเริ่มต้นที่นี่โดยตั้งใจ ฟอร์มส่งค่ามาเสมอ (radio ที่มีตัวถูกเลือกไว้)
             * ถ้าคำขอไม่มีค่านี้ ให้ fail แทนการเดา เพราะการเดาผิดฝั่งหนึ่งคือการ
             * เปิดเผยนัดหมายที่ผู้ใช้ตั้งใจให้เป็นส่วนตัว
             */
            'visibility' => ['required', Rule::in(array_keys(Appointment::VISIBILITY_LABELS))],
        ], [
            'title.required' => 'กรุณาระบุหัวข้อนัดหมาย',
            'appointment_date.required' => 'กรุณาระบุวันที่นัดหมาย',
            'appointment_date.date_format' => 'รูปแบบวันที่นัดหมายไม่ถูกต้อง',
            'starts_at_time.required' => 'กรุณาระบุเวลาเริ่มนัดหมาย',
            'starts_at_time.date_format' => 'รูปแบบเวลาเริ่มนัดหมายไม่ถูกต้อง',
            'ends_at_time.date_format' => 'รูปแบบเวลาสิ้นสุดนัดหมายไม่ถูกต้อง',
            'ends_at_time.after' => 'เวลาสิ้นสุดต้องอยู่หลังเวลาเริ่มนัดหมาย',
            'map_url.url' => 'ลิงก์แผนที่ต้องเป็นที่อยู่เว็บที่ขึ้นต้นด้วย http หรือ https',
            'visibility.required' => 'กรุณาเลือกว่านัดหมายนี้เป็นส่วนตัวหรือของแผนก',
            'visibility.in' => 'ขอบเขตการมองเห็นของนัดหมายไม่ถูกต้อง',
        ]);
    }
}
