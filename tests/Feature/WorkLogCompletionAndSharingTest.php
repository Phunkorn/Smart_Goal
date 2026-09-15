<?php

namespace Tests\Feature;

use App\Models\Department;
use App\Models\SystemNotification;
use App\Models\User;
use App\Models\WorkLog;
use App\Models\WorkLogTemplate;
use App\Support\WorkLogWeekdays;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * กติกาการปิดงานและการทำงานร่วมกันของบันทึกงานประจำวัน
 *
 * A. เริ่มก่อนเวลาไม่ได้ เลยเวลาไม่เกิน 5 นาทียังไม่ต้องบอกเหตุผล
 * B. ปิดงานเลือก "เสร็จสิ้น" หรือ "พบปัญหา" (ต้องมีรายละเอียด) ทั้งงานประจำและงานนอกสถานที่
 * C. คนในแผนกเดียวกันเห็นสถานะของงานบนปฏิทิน
 * D. งานนอกสถานที่เพิ่มผู้ร่วมงานแล้ว แต่ละคนได้รายการของตัวเอง
 */
class WorkLogCompletionAndSharingTest extends TestCase
{
    use RefreshDatabase;

    /** จันทร์ 7 ก.ย. 2026 — แผนงาน 08:30–08:50 ที่กรุงเทพ = 01:30–01:50 UTC */
    private const DAY = '2026-09-07';

    public function test_starting_before_the_planned_time_is_still_refused(): void
    {
        $owner = $this->user();
        $log = $this->routineLog($owner);

        $this->travelTo(CarbonImmutable::parse(self::DAY.' 01:29:00', 'UTC'));
        $this->actingAs($owner)->post(route('daily-logs.start', $log))->assertSessionHasErrors('routine');

        $this->assertSame('open', $log->refresh()->status);
    }

    public function test_starting_within_five_minutes_needs_no_late_reason(): void
    {
        $owner = $this->user();
        $log = $this->routineLog($owner);

        $this->travelTo(CarbonImmutable::parse(self::DAY.' 01:35:00', 'UTC'));
        $this->actingAs($owner)->post(route('daily-logs.start', $log))->assertSessionHasNoErrors();

        $this->assertSame('in_progress', $log->refresh()->status);
    }

    public function test_starting_after_five_minutes_requires_a_late_reason(): void
    {
        $owner = $this->user();
        $log = $this->routineLog($owner);

        $this->travelTo(CarbonImmutable::parse(self::DAY.' 01:36:00', 'UTC'));
        $this->actingAs($owner)->post(route('daily-logs.start', $log))->assertSessionHasErrors('late_start_reason');

        $this->assertSame('open', $log->refresh()->status);
    }

    public function test_finishing_within_five_minutes_needs_no_late_reason(): void
    {
        $owner = $this->user();
        $log = $this->routineLog($owner, ['status' => 'in_progress', 'started_at' => self::DAY.' 01:30:00']);

        $this->travelTo(CarbonImmutable::parse(self::DAY.' 01:55:00', 'UTC'));
        $this->actingAs($owner)->post(route('daily-logs.complete', $log))->assertSessionHasNoErrors();

        $this->assertSame('done', $log->refresh()->status);
        $this->assertFalse($log->has_issue);
    }

    public function test_finishing_late_with_an_issue_is_answered_in_one_request(): void
    {
        $owner = $this->user();
        $log = $this->routineLog($owner, ['status' => 'in_progress', 'started_at' => self::DAY.' 01:30:00']);

        $this->travelTo(CarbonImmutable::parse(self::DAY.' 01:56:00', 'UTC'));
        $this->actingAs($owner)->post(route('daily-logs.complete', $log))->assertSessionHasErrors('late_completion_reason');

        $this->actingAs($owner)->post(route('daily-logs.complete', $log), [
            'outcome' => 'issue',
            'issue_details' => 'เครื่อง 3 เปิดไม่ติด แจ้งซ่อมแล้ว',
            'late_completion_reason' => 'ระบบขัดข้อง',
        ])->assertSessionHasNoErrors();

        $log->refresh();
        $this->assertSame('done', $log->status);
        $this->assertTrue($log->has_issue);
        $this->assertSame('เครื่อง 3 เปิดไม่ติด แจ้งซ่อมแล้ว', $log->issue_details);
        $this->assertSame('ระบบขัดข้อง', $log->late_completion_reason);
    }

    public function test_field_work_reporting_an_issue_must_describe_it(): void
    {
        $owner = $this->user();
        $log = $this->fieldLog($owner);

        $this->actingAs($owner)->post(route('daily-logs.complete', $log), ['outcome' => 'issue'])
            ->assertSessionHasErrors('issue_details');
        $this->assertSame('open', $log->refresh()->status);

        $this->actingAs($owner)->post(route('daily-logs.complete', $log), [
            'outcome' => 'issue',
            'issue_details' => 'ลูกค้าไม่อยู่หน้างาน',
        ])->assertSessionHasNoErrors();

        $this->assertTrue($log->refresh()->has_issue);
    }

    /**
     * กดเสร็จงานแล้วคือยืนยันว่าเช็กแล้ว — ไม่มีทางย้อนกลับไปรอเริ่ม และผลพบปัญหาต้องคงอยู่
     */
    public function test_a_confirmed_log_is_final_and_keeps_its_reported_issue(): void
    {
        $owner = $this->user();
        $log = $this->fieldLog($owner);

        $this->actingAs($owner)->post(route('daily-logs.complete', $log), ['outcome' => 'issue', 'issue_details' => 'ของไม่ครบ']);

        $this->assertFalse(Route::has('daily-logs.reopen'), 'ต้องไม่มี route ย้อนสถานะของบันทึกงาน');
        $this->actingAs($owner)->post('/daily-logs/'.$log->id.'/reopen')->assertNotFound();

        // กดเสร็จซ้ำก็ต้องไม่ล้างผลเดิม
        $this->actingAs($owner)->post(route('daily-logs.complete', $log), ['outcome' => 'done'])->assertSessionHasNoErrors();

        $log->refresh();
        $this->assertSame('done', $log->status);
        $this->assertTrue($log->has_issue);
        $this->assertSame('ของไม่ครบ', $log->issue_details);

        $this->actingAs($owner)->get(route('daily-logs.index', ['date' => self::DAY]))
            ->assertOk()
            ->assertDontSee('data-row-reopen', false)
            ->assertDontSee('แก้สถานะ');
    }

    public function test_field_work_participants_get_their_own_log_to_close(): void
    {
        $this->travelTo(CarbonImmutable::parse(self::DAY.' 05:00:00', 'UTC'));
        $department = Department::create(['department_name' => 'IT']);
        $owner = $this->user($department);
        $mate = $this->user($department);

        $this->actingAs($owner)->post(route('daily-logs.store'), [
            'title' => 'ส่งเครื่องสาขาบางนา',
            'kind' => 'field',
            'location' => 'สาขาบางนา',
            'start_time' => '09:00',
            'end_time' => '10:00',
            'participants' => [$mate->id],
        ])->assertSessionHasNoErrors();

        $original = WorkLog::where('user_id', $owner->id)->firstOrFail();
        $copy = WorkLog::where('user_id', $mate->id)->firstOrFail();

        $this->assertSame($original->id, $copy->shared_from_work_log_id);
        $this->assertSame('open', $copy->status);
        $this->assertSame('สาขาบางนา', $copy->location);
        $this->assertSame(self::DAY, $copy->work_date->format('Y-m-d'));
        $this->assertDatabaseHas('system_notifications', ['user_id' => $mate->id, 'type' => 'work_log_participant_added']);

        // ผู้ร่วมงานเห็นในบันทึกของตัวเอง พร้อมชื่อคนที่เพิ่มเข้ามา
        $this->actingAs($mate)->get(route('daily-logs.index'))
            ->assertOk()
            ->assertSee('ส่งเครื่องสาขาบางนา')
            ->assertSee($owner->name.' เพิ่มคุณเข้างานนี้');

        // ปิดงานของตัวเองได้ และได้ช่วงเวลาเดียวกับต้นฉบับ
        $this->actingAs($mate)->post(route('daily-logs.complete', $copy))->assertSessionHasNoErrors();
        $copy->refresh();
        $this->assertSame('done', $copy->status);
        $this->assertSame(60, $copy->duration_minutes);
        // การปิดงานของผู้ร่วมงานไม่แตะรายการของคนสร้าง (ต้นฉบับกรอกเวลาไว้จึงเป็น done ตั้งแต่สร้าง)
        $original->refresh();
        $this->assertSame('done', $original->status);
        $this->assertSame(60, $original->duration_minutes);
        $this->assertNotSame($original->id, $copy->id);
    }

    public function test_removing_a_participant_deletes_only_an_unfinished_copy(): void
    {
        $this->travelTo(CarbonImmutable::parse(self::DAY.' 05:00:00', 'UTC'));
        $department = Department::create(['department_name' => 'IT']);
        $owner = $this->user($department);
        $finished = $this->user($department);
        $pending = $this->user($department);

        $this->actingAs($owner)->post(route('daily-logs.store'), [
            'title' => 'ติดตั้งเครือข่าย',
            'kind' => 'field',
            'participants' => [$finished->id, $pending->id],
        ]);
        $original = WorkLog::where('user_id', $owner->id)->firstOrFail();
        $this->actingAs($finished)->post(route('daily-logs.complete', WorkLog::where('user_id', $finished->id)->firstOrFail()));

        $this->actingAs($owner)->patch(route('daily-logs.update', $original), [
            'title' => 'ติดตั้งเครือข่าย',
            'kind' => 'field',
        ])->assertSessionHasNoErrors();

        $this->assertSame(1, WorkLog::where('user_id', $finished->id)->count(), 'งานที่ปิดแล้วเป็นประวัติจริง ต้องเก็บไว้');
        $this->assertSame(0, WorkLog::where('user_id', $pending->id)->count());
        $this->assertSoftDeleted('work_logs', ['user_id' => $pending->id]);
    }

    public function test_editing_the_original_updates_unfinished_copies(): void
    {
        $this->travelTo(CarbonImmutable::parse(self::DAY.' 05:00:00', 'UTC'));
        $department = Department::create(['department_name' => 'IT']);
        $owner = $this->user($department);
        $mate = $this->user($department);

        $this->actingAs($owner)->post(route('daily-logs.store'), [
            'title' => 'ชื่อเดิม', 'kind' => 'field', 'participants' => [$mate->id],
        ]);
        $original = WorkLog::where('user_id', $owner->id)->firstOrFail();

        $this->actingAs($owner)->patch(route('daily-logs.update', $original), [
            'title' => 'ชื่อใหม่', 'kind' => 'field', 'location' => 'สาขาใหม่', 'participants' => [$mate->id],
        ]);

        $copy = WorkLog::where('user_id', $mate->id)->firstOrFail();
        $this->assertSame('ชื่อใหม่', $copy->title);
        $this->assertSame('สาขาใหม่', $copy->location);
        $this->assertSame(1, SystemNotification::where('user_id', $mate->id)->where('type', 'work_log_participant_added')->count());
    }

    public function test_deleting_the_original_removes_unfinished_copies(): void
    {
        $this->travelTo(CarbonImmutable::parse(self::DAY.' 05:00:00', 'UTC'));
        $department = Department::create(['department_name' => 'IT']);
        $owner = $this->user($department);
        $mate = $this->user($department);

        $this->actingAs($owner)->post(route('daily-logs.store'), [
            'title' => 'งานที่ยกเลิก', 'kind' => 'field', 'participants' => [$mate->id],
        ]);
        $original = WorkLog::where('user_id', $owner->id)->firstOrFail();

        $this->actingAs($owner)->delete(route('daily-logs.destroy', $original))->assertSessionHasNoErrors();

        $this->assertSame(0, WorkLog::where('user_id', $mate->id)->count());
    }

    public function test_a_copy_cannot_invite_more_people(): void
    {
        $this->travelTo(CarbonImmutable::parse(self::DAY.' 05:00:00', 'UTC'));
        $department = Department::create(['department_name' => 'IT']);
        $owner = $this->user($department);
        $mate = $this->user($department);
        $third = $this->user($department);

        $this->actingAs($owner)->post(route('daily-logs.store'), [
            'title' => 'ส่งของ', 'kind' => 'field', 'participants' => [$mate->id],
        ]);
        $copy = WorkLog::where('user_id', $mate->id)->firstOrFail();

        $this->actingAs($mate)->patch(route('daily-logs.update', $copy), [
            'title' => 'ส่งของ', 'kind' => 'field', 'participants' => [$third->id],
        ])->assertSessionHasNoErrors();

        $this->assertSame(0, WorkLog::where('user_id', $third->id)->count());
    }

    public function test_a_self_recorded_routine_keeps_participants_as_a_name_list_only(): void
    {
        $department = Department::create(['department_name' => 'IT']);
        $owner = $this->user($department);
        $mate = $this->user($department);

        $this->actingAs($owner)->post(route('daily-logs.store'), [
            'title' => 'งานประจำที่บันทึกเอง', 'kind' => 'routine', 'participants' => [$mate->id],
        ]);

        $this->assertSame(0, WorkLog::where('user_id', $mate->id)->count());
    }

    public function test_the_department_calendar_shows_each_status_to_colleagues(): void
    {
        $this->travelTo(CarbonImmutable::parse(self::DAY.' 05:00:00', 'UTC'));
        $it = Department::create(['department_name' => 'IT']);
        $owner = $this->user($it, 'ผู้ตรวจเช็ก');
        $colleague = $this->user($it);
        $outsider = $this->user(Department::create(['department_name' => 'HR']));

        $this->routineLog($owner, [
            'title' => 'ตรวจเช็กคอมพิวเตอร์',
            'status' => 'done',
            'has_issue' => true,
            'issue_details' => 'จอเครื่อง 2 กระพริบ',
        ]);
        WorkLogTemplate::create([
            'user_id' => $owner->id, 'title' => 'สำรองข้อมูล', 'kind' => 'routine',
            'weekday_mask' => WorkLogWeekdays::WORKWEEK, 'is_active' => true, 'default_start_time' => '16:00',
        ]);

        $labels = fn (User $viewer): array => collect(
            $this->actingAs($viewer)->get(route('daily-logs.index', ['view' => 'calendar', 'month' => '2026-09']))
                ->assertOk()->viewData('calendarEntries')[self::DAY] ?? []
        )->where('owner_id', $owner->id)->pluck('status_label', 'title')->all();

        $this->assertSame(['ตรวจเช็กคอมพิวเตอร์' => 'พบปัญหา', 'สำรองข้อมูล' => 'ยังไม่เริ่ม'], $labels($colleague));
        $this->assertSame([], $labels($outsider));
    }

    private function user(?Department $department = null, ?string $name = null): User
    {
        return User::factory()->create(array_filter([
            'role' => 'user',
            'department_id' => ($department ?? Department::create(['department_name' => 'IT-'.uniqid()]))->id,
            'is_active' => true,
            'must_change_password' => false,
            'name' => $name,
        ], fn ($value) => $value !== null));
    }

    private function routineLog(User $owner, array $overrides = []): WorkLog
    {
        $this->travelTo(CarbonImmutable::parse(self::DAY.' 01:00:00', 'UTC'));

        $template = WorkLogTemplate::create([
            'user_id' => $owner->id,
            'title' => $overrides['title'] ?? 'ตรวจเช็กคอมพิวเตอร์',
            'kind' => 'routine',
            'weekday_mask' => WorkLogWeekdays::WORKWEEK,
            'is_active' => true,
            'default_start_time' => '08:30',
            'default_duration_minutes' => 20,
        ]);

        return WorkLog::create(array_merge([
            'user_id' => $owner->id,
            'created_by' => $owner->id,
            'department_id' => $owner->department_id,
            'work_log_template_id' => $template->id,
            'kind' => 'routine',
            'status' => 'open',
            'source' => 'template',
            'title' => 'ตรวจเช็กคอมพิวเตอร์',
            'work_date' => self::DAY,
            'planned_start_at' => self::DAY.' 01:30:00',
            'planned_end_at' => self::DAY.' 01:50:00',
        ], $overrides));
    }

    private function fieldLog(User $owner): WorkLog
    {
        $this->travelTo(CarbonImmutable::parse(self::DAY.' 05:00:00', 'UTC'));

        return WorkLog::create([
            'user_id' => $owner->id,
            'created_by' => $owner->id,
            'department_id' => $owner->department_id,
            'kind' => 'field',
            'status' => 'open',
            'source' => 'manual',
            'title' => 'ติดตั้งเครื่องพิมพ์',
            'work_date' => self::DAY,
        ]);
    }
}
