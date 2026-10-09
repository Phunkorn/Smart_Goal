<?php

namespace App\Http\Controllers;

use App\Models\Meeting;
use App\Services\CalendarPdfExportService;
use App\Services\MeetingAppointmentCalendarQuery;
use App\Services\MeetingQueryService;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

/**
 * ปฏิทินประชุมและนัดหมาย — ส่วนที่ไม่ใช่การวาดหน้าแรก
 *
 * หน้าแรกของปฏิทินถูกเรนเดอร์พร้อม Workspace ที่ MyTaskController::index (มุมมอง
 * data-view="meeting") เพื่อให้เปิดมาเห็นเดือนปัจจุบันทันทีโดยไม่ต้องรอ fetch
 * ที่นี่รับเฉพาะการเปลี่ยนเดือน/ตัวกรองแบบไม่โหลดหน้าใหม่ และเอกสารสำหรับพิมพ์
 */
class MeetingCalendarController extends Controller
{
    /** เพดานช่วงวันที่ของเอกสารหนึ่งฉบับ กัน export ที่กวาดทั้งฐานข้อมูล */
    private const EXPORT_RANGE_MAX_DAYS = 366;

    public function calendarEvents(Request $request, MeetingAppointmentCalendarQuery $calendar): JsonResponse
    {
        Gate::authorize('viewAny', Meeting::class);

        $validated = $request->validate([
            'start' => ['required', 'date_format:Y-m-d'],
            'end' => ['required', 'date_format:Y-m-d', 'after_or_equal:start'],
            'type' => ['nullable', 'string'],
        ]);

        [$fromDate, $toDate] = $this->businessDayRange($validated['start'], $validated['end']);

        abort_if($fromDate->diffInDays($toDate) > self::EXPORT_RANGE_MAX_DAYS, 422, 'ช่วงเวลาที่ขอกว้างเกินไป');

        return response()->json([
            'type' => MeetingAppointmentCalendarQuery::normalizeType($validated['type'] ?? null),
            'events' => $calendar->calendarEventsForUser(
                $request->user(),
                $fromDate,
                $toDate,
                MeetingAppointmentCalendarQuery::normalizeType($validated['type'] ?? null)
            ),
        ]);
    }

    /**
     * เอกสารปฏิทินสำหรับสั่งพิมพ์หรือบันทึกเป็น PDF จากเบราว์เซอร์
     * (ดูเหตุผลที่ไม่เรนเดอร์ไฟล์ PDF ฝั่งเซิร์ฟเวอร์ใน CalendarPdfExportService)
     */
    public function exportCalendarPdf(Request $request, CalendarPdfExportService $export): View
    {
        Gate::authorize('viewAny', Meeting::class);

        $validated = $request->validate([
            'from_date' => ['required', 'date_format:Y-m-d'],
            'to_date' => ['required', 'date_format:Y-m-d', 'after_or_equal:from_date'],
            'type' => ['nullable', 'string'],
            /*
             * รายการที่ผู้ใช้ติ๊กเลือก เป็นคีย์ 'meeting:12' / 'appointment:5'
             *
             * ไม่ส่งมาเลย = เอาทั้งช่วงวันที่ (พฤติกรรมเดิมของปุ่ม Export ซึ่งยังมี
             * ผู้ใช้ที่ bookmark URL ไว้) ส่วนการตรวจสิทธิ์ไม่ได้พึ่งค่านี้เลย
             * ดู CalendarPdfExportService::selectedEvents()
             */
            'selected' => ['nullable', 'array'],
            'selected.*' => ['string', 'regex:/^(meeting|appointment):[1-9][0-9]*$/'],
        ], [
            'from_date.required' => 'กรุณาระบุวันที่เริ่มต้น',
            'from_date.date_format' => 'รูปแบบวันที่เริ่มต้นไม่ถูกต้อง',
            'to_date.required' => 'กรุณาระบุวันที่สิ้นสุด',
            'to_date.date_format' => 'รูปแบบวันที่สิ้นสุดไม่ถูกต้อง',
            'to_date.after_or_equal' => 'วันที่สิ้นสุดต้องไม่อยู่ก่อนวันที่เริ่มต้น',
        ]);

        [$fromDate, $toDate] = $this->businessDayRange($validated['from_date'], $validated['to_date']);

        abort_if($fromDate->diffInDays($toDate) > self::EXPORT_RANGE_MAX_DAYS, 422, 'ช่วงเวลาที่ขอกว้างเกินไป');

        // array_values() เพราะ selected[] ที่มาจาก query string อาจมี key เว้นช่วง
        $selectedKeys = array_key_exists('selected', $validated) && is_array($validated['selected'])
            ? array_values(array_unique($validated['selected']))
            : null;

        return view('meeting-calendar.export', $export->exportCalendarPdf(
            $request->user(),
            $fromDate,
            $toDate,
            MeetingAppointmentCalendarQuery::normalizeType($validated['type'] ?? null),
            $selectedKeys
        ));
    }

    /** @return array{0: CarbonImmutable, 1: CarbonImmutable} */
    private function businessDayRange(string $fromDate, string $toDate): array
    {
        return [
            CarbonImmutable::createFromFormat('!Y-m-d', $fromDate, MeetingQueryService::BUSINESS_TIMEZONE),
            CarbonImmutable::createFromFormat('!Y-m-d', $toDate, MeetingQueryService::BUSINESS_TIMEZONE),
        ];
    }
}
