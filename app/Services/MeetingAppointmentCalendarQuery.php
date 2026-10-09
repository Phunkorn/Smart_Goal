<?php

namespace App\Services;

use App\Models\Appointment;
use App\Models\Meeting;
use App\Models\User;
use App\Support\CalendarAttendeeRoster;
use App\Support\ThaiDate;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * เหตุการณ์ของ "ปฏิทินประชุมและนัดหมาย" — รวมการประชุมกับนัดหมายให้เป็นรูปแบบเดียว
 *
 * ทำไมต้องมีชั้นนี้
 * ---------------------------------------------------------------
 * การประชุมกับนัดหมายเป็นคนละ domain เก็บเวลาไม่เหมือนกัน (meetings.starts_at เป็น
 * datetime UTC ที่ข้ามวันได้ ส่วน appointments เก็บ date + time ตามเวลาไทยตรง ๆ) และ
 * สิทธิ์การมองเห็นต่างกัน (MeetingPolicy เปิดให้หัวหน้า/admin เห็นของทีม ส่วน
 * AppointmentPolicy เป็นของเจ้าของคนเดียว)
 *
 * ถ้าปล่อยให้ Blade หรือ JavaScript รวมเอง ทุกที่ที่วาดปฏิทินจะต้องรู้เรื่องทั้งสอง
 * domain และต้องถาม `instanceof Meeting` เอง ชั้นนี้จึงคืน event ที่มีรูปร่างเดียว
 * ให้ปฏิทินวาดได้โดยไม่ต้องรู้ว่ามาจากตารางไหน
 *
 * ขอบเขตสิทธิ์ของการประชุมยืมมาจาก MeetingQueryService::visibleQuery() ตัวเดิม
 * ไม่ประกาศกฎการมองเห็นชุดใหม่
 */
final class MeetingAppointmentCalendarQuery
{
    public const TYPE_MEETING = 'meeting';

    public const TYPE_APPOINTMENT = 'appointment';

    public const TYPE_ALL = 'all';

    /** ป้ายประเภทที่ผู้ใช้เห็น — ใช้ร่วมกันทั้งปฏิทิน ตัวกรอง และ PDF */
    public const TYPE_LABELS = [
        self::TYPE_MEETING => 'ประชุม',
        self::TYPE_APPOINTMENT => 'นัดหมาย',
    ];

    public function __construct(private readonly MeetingQueryService $meetings) {}

    public static function normalizeType(?string $requestedType): string
    {
        return array_key_exists((string) $requestedType, self::TYPE_LABELS) ? $requestedType : self::TYPE_ALL;
    }

    public static function typeLabel(string $type): string
    {
        return self::TYPE_LABELS[$type] ?? $type;
    }

    /**
     * เหตุการณ์ทั้งหมดที่ผู้ใช้เห็นได้ในช่วงวันที่ขอ เรียงตามวันแล้วตามเวลาเริ่ม
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function calendarEventsForUser(
        User $viewer,
        CarbonImmutable $fromDate,
        CarbonImmutable $toDate,
        string $type = self::TYPE_ALL
    ): Collection {
        $events = collect();

        if ($type !== self::TYPE_APPOINTMENT) {
            $events = $events->concat($this->meetingEventsForDateRange($viewer, $fromDate, $toDate));
        }

        if ($type !== self::TYPE_MEETING) {
            $events = $events->concat($this->appointmentEventsForDateRange($viewer, $fromDate, $toDate));
        }

        return $events
            ->sortBy([['date', 'asc'], ['startTime', 'asc'], ['title', 'asc']])
            ->values();
    }

    /**
     * การประชุมที่ทับซ้อนช่วงวันที่ขอ
     *
     * ใช้ MeetingQueryService::calendarMeetings() เป็นแหล่งข้อมูลเดียว แล้วแปลงเป็น
     * รูปร่าง event ของปฏิทินนี้ จึงไม่มีกฎการมองเห็นของการประชุมชุดที่สองในระบบ
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function meetingEventsForDateRange(User $viewer, CarbonImmutable $fromDate, CarbonImmutable $toDate): Collection
    {
        return $this->meetings
            ->calendarMeetings($viewer, $fromDate->startOfDay(), $toDate->endOfDay())
            ->map(fn (array $meeting): array => [
                'type' => self::TYPE_MEETING,
                'typeLabel' => self::TYPE_LABELS[self::TYPE_MEETING],
                'id' => $meeting['entityId'],
                'title' => $meeting['title'],
                'date' => $meeting['start'],
                'endDate' => $meeting['due'],
                'startTime' => $meeting['startTime'],
                'endTime' => $meeting['endTime'],
                'location' => $meeting['location'],
                'owner' => $meeting['organizer'],
                'attendeeCount' => count($meeting['attendees']),
                /*
                 * รายชื่อถูกส่งต่อโดยไม่เปลี่ยนชื่อคีย์ — CalendarAttendeeRoster
                 * เป็นเจ้าของรูปร่างนี้ ถ้า re-map ที่นี่ รายชื่อก้อนเดียวจะถูกบรรยาย
                 * สองชุดคีย์ในสองชั้น แล้วต้องตามแก้ทั้งคู่ทุกครั้ง
                 *
                 * attendeeCount มาจาก array ก้อนเดียวกับรายชื่อ จึงไม่มีทางได้
                 * "9 คน" แต่มีชื่อ 8 ชื่อ
                 */
                'attendees' => $meeting['attendees'],
                'detail' => $meeting['description'],
                // ปฏิทินนี้อยู่ใน Workspace กดประชุมแล้วต้องกลับเข้า Workspace ได้
                // ไม่ใช่ถูกทิ้งไว้ที่หน้า /meetings (ดู MeetingController::showRouteParameters)
                'detailUrl' => route('meetings.show', ['meeting' => $meeting['entityId'], 'from' => 'workspace']),
                'icon' => 'bi-people',
            ])
            ->values();
    }

    /**
     * นัดหมายของผู้ใช้ในช่วงวันที่ขอ
     *
     * จำกัดด้วย visibleTo() ให้ตรงกับ AppointmentPolicy::view() เป๊ะ ๆ — เห็นได้เฉพาะ
     * ผู้สร้างกับผู้ที่ถูกเชิญ ไม่มีบทบาทใดเห็นของคนอื่น การกรองจึงอยู่ที่ SQL ไม่ใช่ที่หน้าจอ
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function appointmentEventsForDateRange(User $viewer, CarbonImmutable $fromDate, CarbonImmutable $toDate): Collection
    {
        if (! $viewer->can('viewAny', Appointment::class)) {
            return collect();
        }

        return Appointment::query()
            ->visibleTo($viewer)
            ->withinDateRange($fromDate->format('Y-m-d'), $toDate->format('Y-m-d'))
            /*
             * คอลัมน์ของผู้เข้าร่วมต้องครบตามที่ RoleLabel::withDepartment() ใช้
             * (role, is_active, is_department_head, department_id) ด้วยเหตุผลเดียวกับ
             * ฝั่งประชุม — ดู MeetingQueryService::calendarMeetings()
             * ของเดิมโหลดแค่ attendees:id จึงมีแต่จำนวน ไม่มีชื่อมาตั้งแต่ต้น
             */
            ->with([
                'creator:id,name',
                'attendees:id,name,department_id,role,is_department_head,is_active',
                'attendees.department:id,department_name',
            ])
            ->orderBy('appointment_date')
            ->orderBy('starts_at_time')
            ->get()
            ->map(function (Appointment $appointment): array {
                return [
                    'type' => self::TYPE_APPOINTMENT,
                    'typeLabel' => self::TYPE_LABELS[self::TYPE_APPOINTMENT],
                    'id' => $appointment->id,
                    'title' => $appointment->title,
                    'date' => $appointment->appointment_date->format('Y-m-d'),
                    'endDate' => $appointment->appointment_date->format('Y-m-d'),
                    'startTime' => substr((string) $appointment->starts_at_time, 0, 5),
                    'endTime' => $appointment->ends_at_time ? substr((string) $appointment->ends_at_time, 0, 5) : null,
                    'location' => $appointment->location ?: 'ไม่ระบุสถานที่',
                    'owner' => $appointment->creator?->name ?? 'บัญชีที่ถูกลบ',
                    'attendeeCount' => $appointment->attendees->count(),
                    'attendees' => CalendarAttendeeRoster::rows($appointment->attendees),
                    'detail' => $appointment->description,
                    'detailUrl' => route('appointments.show', $appointment),
                    'icon' => 'bi-bookmark-star',
                ];
            })
            ->values();
    }

    /**
     * ข้อมูลที่หน้าปฏิทินต้องใช้วาดรอบแรก
     *
     * เดือนที่แสดงมาจาก ?month=YYYY-MM ค่าที่อ่านไม่ได้จะกลับเป็นเดือนปัจจุบันตามเวลาไทย
     *
     * @return array<string, mixed>
     */
    public function calendarPageData(User $viewer, ?string $requestedMonth, ?string $requestedType): array
    {
        $month = $this->resolveMonth($requestedMonth);
        $type = self::normalizeType($requestedType);

        // ปฏิทินวาดเป็นตาราง 6 สัปดาห์ จึงต้องมีเหตุการณ์ของวันที่ล้นออกไปหัว/ท้ายเดือนด้วย
        $gridStart = $month->startOfMonth()->startOfWeek(CarbonImmutable::SUNDAY);
        $gridEnd = $month->endOfMonth()->endOfWeek(CarbonImmutable::SATURDAY);

        return [
            'calendarMonth' => $month,
            // ปฏิทินนี้เป็นปฏิทินของแผนก หัวเรื่องจึงต้องบอกว่าแผนกไหน
            // ผู้ใช้ที่ยังไม่ถูกจัดแผนกเห็นเฉพาะเรื่องของตัวเอง หัวเรื่องจึงต้องไม่โกหก
            'calendarDepartmentName' => $viewer->loadMissing('department')->department?->department_name,
            'calendarMonthLabel' => ThaiDate::month($month),
            'calendarMonthValue' => $month->format('Y-m'),
            'calendarPreviousMonth' => $month->subMonth()->format('Y-m'),
            'calendarNextMonth' => $month->addMonth()->format('Y-m'),
            'calendarGridStart' => $gridStart,
            'calendarGridEnd' => $gridEnd,
            'calendarType' => $type,
            'calendarEvents' => $this->calendarEventsForUser($viewer, $gridStart, $gridEnd, $type),
            'calendarToday' => CarbonImmutable::now(MeetingQueryService::BUSINESS_TIMEZONE)->startOfDay(),
        ];
    }

    private function resolveMonth(?string $requestedMonth): CarbonImmutable
    {
        $now = CarbonImmutable::now(MeetingQueryService::BUSINESS_TIMEZONE);

        if (! is_string($requestedMonth) || preg_match('/^\d{4}-\d{2}$/', $requestedMonth) !== 1) {
            return $now->startOfMonth();
        }

        try {
            $month = CarbonImmutable::createFromFormat('!Y-m-d', $requestedMonth.'-01', MeetingQueryService::BUSINESS_TIMEZONE);
        } catch (\Throwable) {
            return $now->startOfMonth();
        }

        // เดือนที่ไม่มีจริง เช่น 2569-13 ถูก Carbon เลื่อนไปเงียบ ๆ จึงเทียบกลับกับข้อความเดิม
        return $month->format('Y-m') === $requestedMonth ? $month->startOfMonth() : $now->startOfMonth();
    }
}
