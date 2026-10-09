<?php

namespace App\Http\Controllers;

use App\Models\Meeting;
use App\Models\User;
use App\Services\MeetingAttendeeResponseService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;

/**
 * การตอบรับเข้าร่วมประชุมของผู้ถูกเชิญ และการเชิญอีกครั้งของผู้สร้างประชุม
 *
 * แยกจาก MeetingController เพราะนั่นคือวงจรชีวิตของ "ใบประชุม" (สร้าง/แก้ไข/ลบ) ส่วนที่นี่คือ
 * คำตอบของแต่ละคนต่อใบนั้น สิทธิ์และผู้ลงมือก็ต่างกันคนละชุด
 *
 * ตรรกะธุรกิจทั้งหมดอยู่ใน MeetingAttendeeResponseService ที่นี่มีเฉพาะงาน HTTP
 */
class MeetingAttendeeResponseController extends Controller
{
    /** id ของ modal แจ้งไม่ว่างในหน้ารายละเอียดประชุม ใช้สั่งให้เปิดกลับมาเมื่อเหตุผลไม่ผ่าน */
    private const DECLINE_MODAL_ID = 'declineMeetingInvitationModal';

    public function acceptInvitation(Request $request, Meeting $meeting, MeetingAttendeeResponseService $attendeeResponses): RedirectResponse
    {
        Gate::authorize('respondToInvitation', $meeting);

        $attendeeResponses->acceptMeetingInvitation($meeting, $request->user());

        return back()->with('meeting_success', 'ตอบรับเข้าร่วมประชุมเรียบร้อยแล้ว');
    }

    public function declineInvitation(Request $request, Meeting $meeting, MeetingAttendeeResponseService $attendeeResponses): RedirectResponse
    {
        Gate::authorize('respondToInvitation', $meeting);

        $validator = Validator::make($request->all(), [
            'decline_reason' => ['required', 'string', 'max:500'],
        ], [
            'decline_reason.required' => 'กรุณาระบุเหตุผลที่ไม่สะดวกเข้าร่วมประชุม',
            'decline_reason.max' => 'เหตุผลยาวเกินไป กรุณาสรุปให้สั้นลง',
        ]);

        // ส่งผู้ใช้กลับไปที่ modal เดิมพร้อมข้อความ ไม่ใช่ปล่อยให้หน้ารายละเอียดเปิด modal แก้ไขประชุม
        // ตามกฎ $errors->any() ที่มีอยู่เดิมใน meetings/show.blade.php
        if ($validator->fails()) {
            return back()
                ->withInput()
                ->withErrors($validator)
                ->with('meeting_open_modal', self::DECLINE_MODAL_ID);
        }

        $attendeeResponses->declineMeetingInvitation(
            $meeting,
            $request->user(),
            trim($validator->validated()['decline_reason'])
        );

        return back()->with('meeting_success', 'บันทึกว่าไม่สะดวกเข้าร่วมประชุมเรียบร้อยแล้ว');
    }

    public function reinviteAttendee(Request $request, Meeting $meeting, User $attendee, MeetingAttendeeResponseService $attendeeResponses): RedirectResponse
    {
        Gate::authorize('reinviteAttendee', [$meeting, $attendee]);

        $attendeeResponses->reinviteMeetingAttendee($meeting, $attendee, $request->user());

        return back()->with('meeting_success', 'ส่งคำเชิญเข้าร่วมประชุมอีกครั้งเรียบร้อยแล้ว');
    }
}
