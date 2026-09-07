<?php

namespace Tests\Feature;

use App\Models\Department;
use App\Models\User;
use App\Models\WorkLog;
use App\Support\TodayWorkspace;
use App\Support\WorkLogDesign;
use App\Support\WorkLogSummary;
use App\Support\WorkLogWeekdays;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Tests\TestCase;

/**
 * ตรรกะล้วน ๆ ของบันทึกงานประจำวัน — ป้ายชื่อ, การแปลงเวลา, ตัววันทำงาน และการสรุปยอด
 *
 * เทสต์กลุ่ม weekday ที่ผูกกับเวลากรุงเทพคือด่านสำคัญ เพราะเช้าวันจันทร์ที่กรุงเทพ
 * ยังเป็นวันอาทิตย์ตามเวลา UTC ถ้าคำนวณผิดจุดนี้ ระบบจะสร้างงานประจำผิดวันทุกเช้า
 */
class WorkLogDesignTest extends TestCase
{
    use RefreshDatabase;

    public function test_every_kind_and_status_has_complete_presentation_metadata(): void
    {
        $this->assertSame(['routine', 'interrupt', 'field'], WorkLogDesign::kindKeys());

        foreach (WorkLogDesign::KINDS as $key => $meta) {
            $this->assertArrayHasKey('label', $meta, $key);
            $this->assertArrayHasKey('tone', $meta, $key);
            $this->assertArrayHasKey('icon', $meta, $key);
            $this->assertNotSame('', $meta['label']);
        }

        foreach (WorkLogDesign::STATUSES as $key => $meta) {
            $this->assertArrayHasKey('label', $meta, $key);
            $this->assertArrayHasKey('tone', $meta, $key);
            $this->assertArrayHasKey('icon', $meta, $key);
        }
    }

    /**
     * คำว่า routine ถูกใช้เป็นป้ายระดับความสำคัญของงานโครงการอยู่แล้ว
     * ประเภทงานของบันทึกงานประจำวันจึงต้องแสดงเป็นภาษาไทยเสมอ
     */
    public function test_kind_labels_are_thai_to_avoid_clashing_with_project_priority_wording(): void
    {
        $this->assertSame('งานประจำ', WorkLogDesign::kind('routine')['label']);
        $this->assertSame('งานแทรก', WorkLogDesign::kind('interrupt')['label']);
        $this->assertSame('งานนอกสถานที่', WorkLogDesign::kind('field')['label']);
    }

    public function test_unknown_kind_and_status_fall_back_instead_of_throwing(): void
    {
        $this->assertSame('ไม่ระบุประเภท', WorkLogDesign::kind('nope')['label']);
        $this->assertSame('gray', WorkLogDesign::kind(null)['tone']);
        $this->assertSame('ไม่ระบุสถานะ', WorkLogDesign::status('nope')['label']);
    }

    public function test_duration_label_formats_thai_hours_and_minutes(): void
    {
        $this->assertSame('ไม่ระบุเวลา', WorkLogDesign::durationLabel(null));
        $this->assertSame('0 น.', WorkLogDesign::durationLabel(0));
        $this->assertSame('59 น.', WorkLogDesign::durationLabel(59));
        $this->assertSame('1 ชม.', WorkLogDesign::durationLabel(60));
        $this->assertSame('1 ชม. 1 น.', WorkLogDesign::durationLabel(61));
        $this->assertSame('2 ชม. 45 น.', WorkLogDesign::durationLabel(165));
        $this->assertSame('24 ชม.', WorkLogDesign::durationLabel(1440));
    }

    public function test_for_client_exposes_labels_so_javascript_never_duplicates_them(): void
    {
        $payload = WorkLogDesign::forClient();

        $this->assertSame(WorkLogDesign::KINDS, $payload['kinds']);
        $this->assertSame(WorkLogDesign::STATUSES, $payload['statuses']);
        $this->assertSame(WorkLogDesign::MAX_ATTACHMENTS, $payload['maxAttachments']);
        $this->assertSame(WorkLogDesign::MAX_BACKFILL_DAYS, $payload['maxBackfillDays']);
    }

    public function test_weekday_mask_round_trips_and_labels_common_patterns(): void
    {
        $this->assertSame(31, WorkLogWeekdays::WORKWEEK);
        $this->assertSame(31, WorkLogWeekdays::mask([0, 1, 2, 3, 4]));
        $this->assertSame(127, WorkLogWeekdays::mask([0, 1, 2, 3, 4, 5, 6]));
        $this->assertSame([0, 1, 2, 3, 4], WorkLogWeekdays::days(31));

        $this->assertSame('จ–ศ', WorkLogWeekdays::label(31));
        $this->assertSame('ทุกวัน', WorkLogWeekdays::label(127));
        $this->assertSame('จ, พ, ศ', WorkLogWeekdays::label(WorkLogWeekdays::mask([0, 2, 4])));
        $this->assertSame('อา', WorkLogWeekdays::label(WorkLogWeekdays::mask([6])));
        $this->assertSame('ไม่มีวันที่กำหนด', WorkLogWeekdays::label(0));
    }

    public function test_mask_ignores_out_of_range_day_indexes(): void
    {
        $this->assertSame(0, WorkLogWeekdays::mask([7, 99, -1]));
        $this->assertFalse(WorkLogWeekdays::includes(127, 7));
    }

    /**
     * ตัวแปลง dayOfWeek ของ Carbon (อาทิตย์ = 0) มาเป็น bit index ของเรา (จันทร์ = 0)
     */
    public function test_day_index_starts_the_week_on_monday(): void
    {
        $this->assertSame(0, WorkLogWeekdays::dayIndex(CarbonImmutable::parse('2026-09-07'))); // จันทร์
        $this->assertSame(4, WorkLogWeekdays::dayIndex(CarbonImmutable::parse('2026-09-11'))); // ศุกร์
        $this->assertSame(5, WorkLogWeekdays::dayIndex(CarbonImmutable::parse('2026-09-12'))); // เสาร์
        $this->assertSame(6, WorkLogWeekdays::dayIndex(CarbonImmutable::parse('2026-09-13'))); // อาทิตย์
    }

    /**
     * ด่านสำคัญที่สุดของฟีเจอร์นี้
     *
     * 2026-09-06 17:30 UTC = จันทร์ 7 ก.ย. 00:30 ที่กรุงเทพ แม่แบบ จ–ศ ต้องเข้าเงื่อนไข
     * ถ้าเผลอเช็คด้วยเวลา UTC จะได้วันอาทิตย์และไม่สร้างงานประจำให้เลย
     */
    public function test_weekday_matching_uses_bangkok_time_not_utc(): void
    {
        $utcMoment = CarbonImmutable::parse('2026-09-06 17:30:00', 'UTC');

        $this->assertSame('Sunday', $utcMoment->format('l'));
        $this->assertSame('Monday', TodayWorkspace::businessNow($utcMoment)->format('l'));

        $this->assertFalse(WorkLogWeekdays::matches(WorkLogWeekdays::WORKWEEK, $utcMoment));
        $this->assertTrue(WorkLogWeekdays::matches(
            WorkLogWeekdays::WORKWEEK,
            TodayWorkspace::businessNow($utcMoment)
        ));
    }

    public function test_business_day_bounds_cover_the_full_bangkok_day_in_utc(): void
    {
        [$start, $end] = TodayWorkspace::businessDayBounds('2026-09-07');

        // 7 ก.ย. 00:00 ที่กรุงเทพ = 6 ก.ย. 17:00 UTC
        $this->assertSame('2026-09-06 17:00:00', $start->format('Y-m-d H:i:s'));
        $this->assertSame('2026-09-07 16:59:59', $end->format('Y-m-d H:i:s'));
    }

    public function test_summary_totals_minutes_and_splits_them_by_kind(): void
    {
        $logs = $this->logs([
            ['kind' => 'routine', 'duration_minutes' => 40],
            ['kind' => 'field', 'duration_minutes' => 165],
            ['kind' => 'interrupt', 'duration_minutes' => 80],
            ['kind' => 'interrupt', 'duration_minutes' => 20],
        ]);

        $summary = WorkLogSummary::fromLogs($logs);

        $this->assertSame(305, $summary['total_minutes']);
        $this->assertSame(4, $summary['total_count']);
        $this->assertSame(40, $summary['by_kind']['routine']['minutes']);
        $this->assertSame(1, $summary['by_kind']['routine']['count']);
        $this->assertSame(100, $summary['by_kind']['interrupt']['minutes']);
        $this->assertSame(2, $summary['by_kind']['interrupt']['count']);
        $this->assertSame(165, $summary['by_kind']['field']['minutes']);
    }

    /**
     * ฝั่งแสดงผลไม่ควรต้องเดาว่าคีย์ไหนหายไป จึงต้องคืนครบทุกประเภทเสมอ
     */
    public function test_summary_returns_every_kind_even_when_unused(): void
    {
        $summary = WorkLogSummary::fromLogs($this->logs([
            ['kind' => 'routine', 'duration_minutes' => 30],
        ]));

        $this->assertSame(
            ['routine', 'interrupt', 'field'],
            array_keys($summary['by_kind'])
        );
        $this->assertSame(0, $summary['by_kind']['field']['minutes']);
        $this->assertSame(0, $summary['by_kind']['field']['count']);
    }

    /**
     * ตัวเลขที่ตอบคำถาม "ทำไมโปรเจกต์ไม่ขยับวันนี้"
     */
    public function test_summary_separates_time_not_linked_to_any_project(): void
    {
        $department = Department::create(['department_name' => 'IT']);
        $owner = $this->owner($department);
        $project = $owner->taskLists()->create(['name' => 'ระบบเครือข่ายสำนักงานใหม่']);

        $logs = $this->logs([
            ['kind' => 'routine', 'duration_minutes' => 60],
            ['kind' => 'field', 'duration_minutes' => 240],
            ['kind' => 'interrupt', 'duration_minutes' => 80, 'work_order_list_id' => $project->id],
        ], $owner, $department);

        $summary = WorkLogSummary::fromLogs($logs);

        $this->assertSame(300, $summary['unlinked_minutes']);
        $this->assertSame(80, $summary['project_linked_minutes']);
    }

    public function test_summary_counts_open_untimed_and_auto_closed_entries(): void
    {
        $logs = $this->logs([
            ['status' => 'open', 'duration_minutes' => null],
            ['status' => 'done', 'duration_minutes' => 45],
            ['status' => 'done', 'duration_minutes' => 720, 'auto_closed_at' => '2026-09-04 17:00:00'],
        ]);

        $summary = WorkLogSummary::fromLogs($logs);

        $this->assertSame(1, $summary['open_count']);
        $this->assertSame(1, $summary['untimed_count']);
        $this->assertSame(1, $summary['auto_closed_count']);
        $this->assertSame(765, $summary['total_minutes']);
    }

    /**
     * ระบบอนุญาตให้ช่วงเวลาซ้อนกันได้ แต่ต้องตรวจจับเพื่อขึ้นป้ายเตือน
     */
    public function test_summary_detects_overlapping_entries_without_rejecting_them(): void
    {
        $sequential = $this->logs([
            ['started_at' => '2026-09-04 01:00:00', 'ended_at' => '2026-09-04 02:00:00'],
            ['started_at' => '2026-09-04 02:00:00', 'ended_at' => '2026-09-04 03:00:00'],
        ]);

        $overlapping = $this->logs([
            ['started_at' => '2026-09-04 01:00:00', 'ended_at' => '2026-09-04 03:00:00'],
            ['started_at' => '2026-09-04 02:00:00', 'ended_at' => '2026-09-04 02:30:00'],
        ]);

        $this->assertFalse(WorkLogSummary::fromLogs($sequential)['overlaps']);
        $this->assertTrue(WorkLogSummary::fromLogs($overlapping)['overlaps']);
    }

    public function test_summary_of_an_empty_day_is_all_zero(): void
    {
        $summary = WorkLogSummary::fromLogs(collect());

        $this->assertSame(0, $summary['total_minutes']);
        $this->assertSame(0, $summary['total_count']);
        $this->assertSame(0, $summary['unlinked_minutes']);
        $this->assertFalse($summary['overlaps']);
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     * @return Collection<int, WorkLog>
     */
    private function logs(array $rows, ?User $owner = null, ?Department $department = null): Collection
    {
        $department ??= Department::create(['department_name' => 'IT-'.uniqid()]);
        $owner ??= $this->owner($department);

        return collect($rows)->map(fn (array $row): WorkLog => WorkLog::create(array_merge([
            'user_id' => $owner->id,
            'created_by' => $owner->id,
            'department_id' => $department->id,
            'kind' => 'routine',
            'status' => 'done',
            'source' => 'manual',
            'title' => 'งานทดสอบ',
            'work_date' => '2026-09-04',
        ], $row)))->values();
    }

    private function owner(Department $department): User
    {
        return User::factory()->create([
            'role' => 'user',
            'department_id' => $department->id,
            'is_active' => true,
            'must_change_password' => false,
        ]);
    }
}
