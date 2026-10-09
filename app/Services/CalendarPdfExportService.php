<?php

namespace App\Services;

use App\Models\User;
use App\Support\ThaiDate;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * ข้อมูลและหัวเรื่องของเอกสาร "Export ปฏิทิน"
 *
 * ทำไมไม่เรนเดอร์เป็นไฟล์ PDF ฝั่งเซิร์ฟเวอร์
 * ---------------------------------------------------------------
 * โปรเจกต์นี้ยังไม่มีตัวเรนเดอร์ PDF เลย (composer.json ไม่มี dompdf/snappy และ
 * การ export ทุกที่เป็น CSV ผ่าน StreamedResponse) การเพิ่ม dependency ใหม่ขัดกับ
 * ข้อกำหนด "ห้ามเพิ่ม dependency ใหม่ถ้าไม่จำเป็น" จึงส่งออกเป็นหน้าเอกสารที่มีสไตล์
 * สำหรับการพิมพ์ ให้ผู้ใช้สั่งพิมพ์หรือบันทึกเป็น PDF จากเบราว์เซอร์
 *
 * ชั้นนี้คือข้อมูลกับถ้อยคำของเอกสาร ไม่ผูกกับวิธีเรนเดอร์ ถ้าภายหลังรับ dompdf
 * เข้ามา จะเปลี่ยนเฉพาะ view ที่ render ผลของ exportCalendarPdf() เท่านั้น
 */
final class CalendarPdfExportService
{
    private const HEADINGS = [
        MeetingAppointmentCalendarQuery::TYPE_ALL => 'ปฏิทินประชุมและนัดหมาย',
        MeetingAppointmentCalendarQuery::TYPE_MEETING => 'ปฏิทินการประชุม',
        MeetingAppointmentCalendarQuery::TYPE_APPOINTMENT => 'ปฏิทินนัดหมาย',
    ];

    public function __construct(private readonly MeetingAppointmentCalendarQuery $calendar) {}

    /**
     * คีย์ของเหตุการณ์หนึ่งรายการสำหรับการเลือก — 'meeting:12' / 'appointment:5'
     *
     * ต้องมีทั้งประเภทและรหัส เพราะรหัสซ้ำกันได้ระหว่างสอง domain
     * ฝั่ง JavaScript ประกอบคีย์ด้วยสูตรเดียวกัน (ดู eventSelectionKey)
     *
     * @param  array<string, mixed>  $event
     */
    public static function selectionKey(array $event): string
    {
        return $event['type'].':'.$event['id'];
    }

    /**
     * เอกสารหนึ่งฉบับ: หัวเรื่อง ช่วงวันที่ และรายการที่เรียงตามวันแล้วตามเวลาเริ่ม
     *
     * การเรียงมาจาก calendarEventsForUser() ซึ่งเรียง date ASC แล้ว startTime ASC
     * อยู่แล้ว จึงไม่เรียงซ้ำที่นี่
     *
     * @param  array<int, string>|null  $selectedKeys  เมื่อไม่ใช่ null ให้เอาเฉพาะรายการที่คีย์ตรง
     * @return array{heading: string, typeLabel: string, rangeLabel: string, events: Collection<int, array<string, mixed>>, eventParts: Collection<int, array<string, mixed>>}
     */
    public function exportCalendarPdf(
        User $viewer,
        CarbonImmutable $fromDate,
        CarbonImmutable $toDate,
        string $type,
        ?array $selectedKeys = null
    ): array {
        $type = MeetingAppointmentCalendarQuery::normalizeType($type);

        $events = $this->selectedEvents(
            $this->calendar->calendarEventsForUser($viewer, $fromDate, $toDate, $type),
            $selectedKeys
        );

        return [
            'heading' => self::HEADINGS[$type],
            'typeLabel' => $type === MeetingAppointmentCalendarQuery::TYPE_ALL
                ? 'ทั้งหมด'
                : MeetingAppointmentCalendarQuery::typeLabel($type),
            'rangeLabel' => ThaiDate::day($fromDate).' – '.ThaiDate::day($toDate),
            // ใช้สำหรับเช็กว่าเอกสารว่างทั้งฉบับและนับยอดรวมเท่านั้น
            // การแสดงผลทั้งหมดเดินผ่าน eventParts
            'events' => $events,
            'eventParts' => $this->groupByType($events),
        ];
    }

    /**
     * แยกเอกสารเป็นส่วนของ "ประชุม" และ "นัดหมาย" คนละส่วนกัน
     *
     * ทำไมต้องแยก ไม่ใช่ใส่คอลัมน์ประเภทแล้วเรียงปนกันตามวัน
     * ---------------------------------------------------------------
     * สองอย่างนี้ถูกใช้ต่างกันหลังพิมพ์ — เอกสารการประชุมถูกเก็บเป็นหลักฐานของ
     * การประชุมนั้น ส่วนนัดหมายเป็นตารางงานของคน การเรียงปนกันทำให้ต้องคัดแยก
     * ด้วยมือทุกครั้งก่อนเอาไปใช้ แยกตั้งแต่ในเอกสารจึงฉีกแจกได้เลย
     *
     * ผลข้างเคียงที่ต้องรู้: ลำดับตามวันเป็นจริง "ภายในแต่ละส่วน" ไม่ใช่ทั้งฉบับ
     * ประชุมวันที่ 20 จะอยู่ก่อนนัดหมายวันที่ 5 เพราะอยู่คนละส่วนกัน
     *
     * ลำดับส่วนมาจาก TYPE_LABELS (ประชุมก่อน นัดหมายทีหลัง) ไม่ใช่ลำดับที่ข้อมูล
     * บังเอิญมา เอกสารจึงเรียงส่วนเหมือนกันทุกฉบับ และส่วนที่ไม่มีรายการเลยถูกตัดออก
     *
     * @param  Collection<int, array<string, mixed>>  $events
     * @return Collection<int, array{type: string, typeLabel: string, events: Collection<int, array<string, mixed>>, eventDays: Collection<int, array<string, mixed>>}>
     */
    private function groupByType(Collection $events): Collection
    {
        return collect(MeetingAppointmentCalendarQuery::TYPE_LABELS)
            ->map(fn (string $typeLabel, string $type): array => [
                'type' => $type,
                'typeLabel' => $typeLabel,
                'events' => $partEvents = $events->where('type', $type)->values(),
                'eventDays' => $this->groupByDay($partEvents),
            ])
            ->reject(fn (array $part): bool => $part['events']->isEmpty())
            ->values();
    }

    /**
     * จัดรายการเป็นกลุ่มตามวัน พร้อมป้ายวันแบบไทยของแต่ละกลุ่ม
     *
     * เอกสารอ่านเป็น "วันละหัวข้อ" ไม่ใช่ตารางที่ซ้ำคอลัมน์วันที่ทุกบรรทัด
     * การจัดกลุ่มกับการตั้งป้ายวันอยู่ที่นี่ ไม่ใช่ใน Blade เพื่อให้ชั้นแสดงผล
     * ไม่ต้องตัดสินถ้อยคำหรือลำดับเอง (และรูปแบบวัน พ.ศ. ยังมีที่เดียวคือ ThaiDate)
     *
     * groupBy() รักษาลำดับเดิมไว้ และ calendarEventsForUser() เรียง date ASC
     * แล้ว startTime ASC มาแล้ว จึงไม่ต้องเรียงซ้ำ
     *
     * @param  Collection<int, array<string, mixed>>  $events
     * @return Collection<int, array{date: string, dateLabel: string, events: Collection<int, array<string, mixed>>}>
     */
    private function groupByDay(Collection $events): Collection
    {
        return $events
            ->groupBy('date')
            ->map(fn (Collection $dayEvents, string $date): array => [
                'date' => $date,
                'dateLabel' => ThaiDate::weekdayLongDay(CarbonImmutable::parse($date)),
                'events' => $dayEvents->values(),
            ])
            ->values();
    }

    /**
     * กรองเหลือเฉพาะรายการที่ผู้ใช้ติ๊กเลือก
     *
     * ความปลอดภัยไม่ได้มาจากรายการคีย์ที่ส่งมา แต่มาจากลำดับของการทำงาน:
     * รายการตั้งต้นมาจาก calendarEventsForUser() ซึ่งจำกัดสิทธิ์ที่ SQL มาแล้ว
     * การเลือกจึงเป็นการ "ตัดออก" เท่านั้น ส่งคีย์ของรายการที่ไม่มีสิทธิ์เห็นมา
     * ก็ไม่มีอะไรให้จับคู่ ไม่ใช่การดึงข้อมูลเพิ่ม
     *
     * null แปลว่าไม่ได้ระบุการเลือกมา — เอาทั้งช่วงวันที่ตามพฤติกรรมเดิมของปุ่ม Export
     * ส่วน array ว่างแปลว่าเลือกมาแล้วแต่ไม่เลือกอะไรเลย ผลจึงเป็นเอกสารว่าง
     * ซึ่งต่างกัน และต้องไม่ถูกตีความสลับกัน
     *
     * @param  Collection<int, array<string, mixed>>  $events
     * @param  array<int, string>|null  $selectedKeys
     * @return Collection<int, array<string, mixed>>
     */
    private function selectedEvents(Collection $events, ?array $selectedKeys): Collection
    {
        if ($selectedKeys === null) {
            return $events;
        }

        $wanted = array_flip($selectedKeys);

        return $events
            ->filter(fn (array $event): bool => isset($wanted[self::selectionKey($event)]))
            ->values();
    }
}
