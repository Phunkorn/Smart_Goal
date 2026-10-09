<?php

namespace App\Http\Controllers;

use App\Models\Appointment;
use App\Models\User;
use App\Services\AppointmentAttendeeResponseService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;

/**
 * การตอบรับเข้าร่วมนัดหมายของผู้ถูกเชิญ และการเชิญอีกครั้งของผู้สร้างนัดหมาย
 *
 * แยกจาก AppointmentController เพราะนั่นคือวงจรชีวิตของ "ใบนัดหมาย" (สร้าง/แก้ไข/ลบ)
 * ส่วนที่นี่คือคำตอบของแต่ละคนต่อใบนั้น สิทธิ์และผู้ลงมือก็ต่างกันคนละชุด
 *
 * ตรรกะธุรกิจทั้งหมดอยู่ใน AppointmentAttendeeResponseService ที่นี่มีเฉพาะงาน HTTP
 */
class AppointmentAttendeeResponseController extends Controller
{
    /** id ของ modal แจ้งไม่ว่างในหน้ารายละเอียดนัดหมาย ใช้สั่งให้เปิดกลับมาเมื่อเหตุผลไม่ผ่าน */
    private const DECLINE_MODAL_ID = 'declineMeetingInvitationModal';

    public function acceptInvitation(Request $request, Appointment $appointment, AppointmentAttendeeResponseService $attendeeResponses): RedirectResponse
    {
        Gate::authorize('respondToInvitation', $appointment);

        $attendeeResponses->acceptAppointmentInvitation($appointment, $request->user());

        return back()->with('meeting_success', 'ตอบรับเข้าร่วมนัดหมายเรียบร้อยแล้ว');
    }

    public function declineInvitation(Request $request, Appointment $appointment, AppointmentAttendeeResponseService $attendeeResponses): RedirectResponse
    {
        Gate::authorize('respondToInvitation', $appointment);

        $validator = Validator::make($request->all(), [
            'decline_reason' => ['required', 'string', 'max:500'],
        ], [
            'decline_reason.required' => 'กรุณาระบุเหตุผลที่ไม่สะดวกเข้าร่วมนัดหมาย',
            'decline_reason.max' => 'เหตุผลยาวเกินไป กรุณาสรุปให้สั้นลง',
        ]);

        // ส่งผู้ใช้กลับไปที่ modal เดิมพร้อมข้อความ ไม่ใช่ปล่อยให้หน้ารายละเอียดเปิด modal แก้ไข
        if ($validator->fails()) {
            return back()
                ->withInput()
                ->withErrors($validator)
                ->with('meeting_open_modal', self::DECLINE_MODAL_ID);
        }

        $attendeeResponses->declineAppointmentInvitation(
            $appointment,
            $request->user(),
            trim($validator->validated()['decline_reason'])
        );

        return back()->with('meeting_success', 'บันทึกว่าไม่สะดวกเข้าร่วมนัดหมายเรียบร้อยแล้ว');
    }

    public function reinviteAttendee(Request $request, Appointment $appointment, User $attendee, AppointmentAttendeeResponseService $attendeeResponses): RedirectResponse
    {
        Gate::authorize('reinviteAttendee', [$appointment, $attendee]);

        $attendeeResponses->reinviteAppointmentAttendee($appointment, $attendee, $request->user());

        return back()->with('meeting_success', 'ส่งคำเชิญเข้าร่วมนัดหมายอีกครั้งเรียบร้อยแล้ว');
    }
}
