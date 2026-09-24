<?php

namespace Tests\Feature;

use App\Models\Department;
use App\Models\SystemNotification;
use App\Models\User;
use App\Models\WorkLog;
use App\Models\WorkLogCategory;
use App\Models\WorkLogTemplate;
use App\Services\RoutineAttentionService;
use App\Services\WorkLogRoutineMaterializer;
use App\Support\TodayWorkspace;
use App\Support\WorkLogWeekdays;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * เส้นทางจริงของงานประจำที่ทำกันหลายคน
 *
 * สิ่งที่ทดสอบที่นี่คือสามอาการที่รายงานเข้ามาจากการใช้งานจริง:
 * 1. เลือกเพื่อนร่วมงานไว้แล้วเขาไม่เห็นอะไรเลยในรายการของตัวเอง
 * 2. การเพิ่มคนไม่ส่งการแจ้งเตือนไปหาใครเลย
 * 3. ไม่มีวิธีปิดงานประจำนอกจากกดเริ่ม/หยุดตัวจับเวลา ซึ่งไม่ตรงกับวิธีทำงานจริง
 *    ที่เข้าไปทำตามช่วงเวลาที่กำหนดแล้วกลับมายืนยันว่าทำแล้ว
 */
class WorkLogRoutineFlowTest extends TestCase
{
    use RefreshDatabase;

    /** จันทร์ 7 ก.ย. 2026 เวลา 09:00 ที่กรุงเทพ (= 02:00 UTC) */
    private const MONDAY_MORNING_UTC = '2026-09-07 02:00:00';

    public function test_a_single_selected_date_uses_existing_template_window_without_creating_today_log(): void
    {
        $this->travelTo(CarbonImmutable::parse(self::MONDAY_MORNING_UTC, 'UTC'));
        $owner = $this->user();
        $category = WorkLogCategory::create(['name' => 'ตรวจเช็ก', 'tone' => 'gray']);

        $this->actingAs($owner)->post(route('daily-logs.routines.store'), [
            'title' => 'ตรวจระบบวันพุธ',
            'work_log_category_id' => $category->id,
            'plan_date' => '2026-09-09',
            'default_start_time' => '08:00',
            'default_end_time' => '09:00',
        ])->assertRedirect();

        $template = WorkLogTemplate::firstOrFail();
        $this->assertSame('2026-09-09', $template->starts_on->format('Y-m-d'));
        $this->assertSame('2026-09-09', $template->ends_on->format('Y-m-d'));
        $this->assertSame(WorkLogWeekdays::mask([2]), $template->weekday_mask);
        $this->assertDatabaseMissing('work_logs', ['work_log_template_id' => $template->id]);
    }

    /**
     * ฟอร์มงานประจำเคยรับได้วันเดียว ต้องกรอกซ้ำทีละวัน
     * เลือกหลายวันแล้วต้องได้แม่แบบวันเดียวครบทุกวันจากการส่งครั้งเดียว
     */
    public function test_several_selected_dates_create_one_single_day_template_each(): void
    {
        $this->travelTo(CarbonImmutable::parse(self::MONDAY_MORNING_UTC, 'UTC'));
        $owner = $this->user();
        $category = WorkLogCategory::create(['name' => 'ตรวจเช็ก', 'tone' => 'gray']);

        $this->actingAs($owner)->post(route('daily-logs.routines.store'), [
            'title' => 'ตรวจระบบกลางสัปดาห์',
            'work_log_category_id' => $category->id,
            'plan_date' => '2026-09-09',
            'plan_dates' => ['2026-09-11', '2026-09-09', '2026-09-10'],
            'default_start_time' => '08:00',
            'default_end_time' => '09:00',
        ])->assertRedirect();

        $templates = WorkLogTemplate::orderBy('starts_on')->get();

        $this->assertCount(3, $templates);
        $this->assertSame(['2026-09-09', '2026-09-10', '2026-09-11'], $templates->map(fn ($t) => $t->starts_on->format('Y-m-d'))->all());
        $this->assertSame(
            [WorkLogWeekdays::mask([2]), WorkLogWeekdays::mask([3]), WorkLogWeekdays::mask([4])],
            $templates->pluck('weekday_mask')->map(fn ($mask) => (int) $mask)->all()
        );
        $templates->each(fn ($t) => $this->assertTrue($t->starts_on->isSameDay($t->ends_on)));

        $this->actingAs($owner)
            ->get(route('daily-logs.index', ['view' => 'calendar']))
            ->assertOk()
            ->assertSee('daily-plan-management__group', false)
            ->assertSee('3 วัน')
            ->assertSee('ดูวันที่และจัดการรายวัน');
    }

    public function test_editing_an_existing_weekly_template_without_active_field_preserves_its_state(): void
    {
        $this->travelTo(CarbonImmutable::parse(self::MONDAY_MORNING_UTC, 'UTC'));
        $owner = $this->user();
        $template = $this->template($owner);

        $this->actingAs($owner)->patch(route('daily-logs.routines.update', $template), [
            'title' => 'งานเดิมที่แก้ชื่อ',
            'weekdays' => [0, 1, 2, 3, 4],
        ])->assertRedirect();

        $this->assertTrue($template->refresh()->is_active);
        $this->assertSame('งานเดิมที่แก้ชื่อ', $template->title);
    }

    public function test_a_routine_reaches_the_selected_teammate_own_day(): void
    {
        $this->travelTo(CarbonImmutable::parse(self::MONDAY_MORNING_UTC, 'UTC'));

        $department = Department::create(['department_name' => 'IT']);
        $owner = $this->user($department);
        $mate = $this->user($department);

        $this->actingAs($owner)
            ->post(route('daily-logs.routines.store'), [
                'title' => 'เช็คคอมพิวเตอร์ห้องบัญชี',
                'weekdays' => [0, 1, 2, 3, 4],
                'default_start_time' => '08:30',
                'default_end_time' => '08:50',
                'participants' => [$mate->id],
            ])
            ->assertRedirect();

        // ทั้งคู่ได้รายการของตัวเองคนละแถว การยืนยันจึงเป็นของแต่ละคน
        $this->assertDatabaseHas('work_logs', [
            'user_id' => $owner->id,
            'title' => 'เช็คคอมพิวเตอร์ห้องบัญชี',
            'status' => 'open',
        ]);
        $this->assertDatabaseHas('work_logs', [
            'user_id' => $mate->id,
            'title' => 'เช็คคอมพิวเตอร์ห้องบัญชี',
            'status' => 'open',
        ]);

        // ช่วงเวลาถูกเก็บเป็นนาทีคอลัมน์เดียว ไม่มีเวลาสิ้นสุดเป็นแหล่งความจริงที่สอง
        $this->assertSame(20, (int) WorkLogTemplate::first()->default_duration_minutes);
        // ฟอร์มไม่ถามประเภทงาน — แม่แบบในเมนูนี้เป็นงานประจำอยู่แล้วโดยนิยาม
        $this->assertSame('routine', WorkLogTemplate::first()->kind);
    }

    public function test_the_selected_teammate_is_notified(): void
    {
        $this->travelTo(CarbonImmutable::parse(self::MONDAY_MORNING_UTC, 'UTC'));

        $department = Department::create(['department_name' => 'IT']);
        $owner = $this->user($department);
        $mate = $this->user($department);

        $this->actingAs($owner)->post(route('daily-logs.routines.store'), [
            'title' => 'เช็คคอมพิวเตอร์ห้องบัญชี',
            'kind' => 'routine',
            'weekdays' => [0, 1, 2, 3, 4],
            'default_start_time' => '08:30',
            'default_end_time' => '08:50',
            'participants' => [$mate->id],
        ]);

        $notification = SystemNotification::where('user_id', $mate->id)->first();

        $this->assertNotNull($notification, 'คนที่ถูกเลือกต้องได้รับการแจ้งเตือน');
        $this->assertSame('work_log_routine_assigned', $notification->type);
        $this->assertSame('worklog', $notification->category);
        // ข้อความต้องบอกช่วงเวลาที่ต้องเข้าไปทำ ไม่ใช่แค่บอกว่ามีชื่อเพิ่มเข้ามา
        $this->assertStringContainsString('08:30 - 08:50', (string) $notification->message);
    }

    public function test_adding_a_participant_to_a_log_notifies_them(): void
    {
        $department = Department::create(['department_name' => 'IT']);
        $owner = $this->user($department);
        $mate = $this->user($department);

        $this->actingAs($owner)->post(route('daily-logs.store'), [
            'title' => 'ยกของขึ้นชั้นสาม',
            'kind' => 'field',
            'participants' => [$mate->id],
        ])->assertRedirect();

        $this->assertDatabaseHas('system_notifications', [
            'user_id' => $mate->id,
            'type' => 'work_log_participant_added',
            'category' => 'worklog',
        ]);
    }

    /**
     * แก้ไขบันทึกเดิมซ้ำ ๆ ต้องไม่ยิงแจ้งเตือนเดิมให้คนที่อยู่ในรายชื่ออยู่แล้ว
     */
    public function test_editing_a_log_does_not_re_notify_existing_participants(): void
    {
        $department = Department::create(['department_name' => 'IT']);
        $owner = $this->user($department);
        $mate = $this->user($department);

        $this->actingAs($owner)->post(route('daily-logs.store'), [
            'title' => 'ยกของขึ้นชั้นสาม',
            'kind' => 'field',
            'participants' => [$mate->id],
        ]);

        $log = WorkLog::where('user_id', $owner->id)->firstOrFail();

        $this->actingAs($owner)->patch(route('daily-logs.update', $log), [
            'title' => 'ยกของขึ้นชั้นสาม (แก้ชื่อ)',
            'kind' => 'field',
            'participants' => [$mate->id],
        ])->assertRedirect();

        $this->assertSame(
            1,
            SystemNotification::where('user_id', $mate->id)->where('type', 'work_log_participant_added')->count()
        );
    }

    public function test_routine_must_start_before_it_can_be_completed(): void
    {
        $this->travelTo(CarbonImmutable::parse(self::MONDAY_MORNING_UTC, 'UTC'));

        $owner = $this->user();
        $this->template($owner, [
            'default_start_time' => '08:30',
            'default_duration_minutes' => 20,
        ]);

        app(WorkLogRoutineMaterializer::class)->materializeToday($owner);
        $log = WorkLog::where('user_id', $owner->id)->firstOrFail();

        $this->assertSame('open', $log->status);

        $this->actingAs($owner)->post(route('daily-logs.complete', $log))->assertSessionHasErrors('routine');
        $this->actingAs($owner)->post(route('daily-logs.start', $log), [
            'late_start_reason' => 'ติดงานอื่น',
        ])->assertRedirect();
        $this->actingAs($owner)->post(route('daily-logs.complete', $log), [
            'late_completion_reason' => 'งานมากกว่าที่ประเมิน',
        ])->assertRedirect();

        $log->refresh();

        $this->assertSame('done', $log->status);
        // เวลาที่ตั้งไว้ถูกเติมให้ เพื่อไม่ให้เวลารวมของวันเป็นศูนย์ทั้งที่ทำงานไปแล้ว
        $this->assertSame('09:00', TodayWorkspace::businessNow($log->started_at)->format('H:i'));
        $this->assertSame('09:00', TodayWorkspace::businessNow($log->ended_at)->format('H:i'));
        $this->assertSame('ติดงานอื่น', $log->late_start_reason);
    }

    /**
     * กติกาเดิมให้ "แก้สถานะ" ย้อนงานที่ยืนยันแล้วกลับไปรอเริ่มได้ ซึ่งทำให้การยืนยันว่าเช็กแล้วไม่มีความหมาย
     * กติกาปัจจุบัน: เสร็จแล้ว และ ไม่ได้ทำ เป็นสถานะสุดท้าย เวลาที่บันทึกไว้ต้องคงอยู่
     */
    public function test_a_confirmed_or_skipped_routine_cannot_be_moved_back_to_open(): void
    {
        $this->travelTo(CarbonImmutable::parse(self::MONDAY_MORNING_UTC, 'UTC'));

        $owner = $this->user();
        $this->template($owner, [
            'default_start_time' => '08:30',
            'default_duration_minutes' => 20,
        ]);
        $this->template($owner, ['title' => 'งานที่วันนี้ไม่ได้ทำ']);

        app(WorkLogRoutineMaterializer::class)->materializeToday($owner);
        $log = WorkLog::where('user_id', $owner->id)->where('title', '!=', 'งานที่วันนี้ไม่ได้ทำ')->firstOrFail();
        $skipped = WorkLog::where('user_id', $owner->id)->where('title', 'งานที่วันนี้ไม่ได้ทำ')->firstOrFail();

        $this->actingAs($owner)->post(route('daily-logs.start', $log), ['late_start_reason' => 'ประชุม']);
        $this->actingAs($owner)->post(route('daily-logs.complete', $log), ['late_completion_reason' => 'รอข้อมูล']);
        $this->actingAs($owner)->post(route('daily-logs.skip', $skipped), ['skip_reason' => 'ลางาน']);

        $this->assertFalse(Route::has('daily-logs.reopen'));

        // ปุ่มเริ่ม/เสร็จ/ไม่ได้ทำ ที่ถูกยิงซ้ำหลังปิดงานแล้วต้องไม่ย้อนสถานะเช่นกัน
        $this->actingAs($owner)->post(route('daily-logs.start', $log));
        $this->actingAs($owner)->post(route('daily-logs.start', $skipped));
        $this->actingAs($owner)->post(route('daily-logs.skip', $log), ['skip_reason' => 'กดผิด']);

        $log->refresh();
        $skipped->refresh();

        $this->assertSame('done', $log->status);
        $this->assertNotNull($log->ended_at);
        $this->assertNotNull($log->duration_minutes);
        $this->assertSame('skipped', $skipped->status);
        $this->assertSame('ลางาน', $skipped->skip_reason);

        $this->actingAs($owner)->get(route('daily-logs.index'))
            ->assertOk()
            ->assertDontSee('data-row-reopen', false);
    }

    /**
     * รายการที่ยืนยันไปแล้วต้องไม่กลับไปค้าง เพียงเพราะเจ้าของมาแก้ชื่อทีหลัง
     */
    public function test_editing_a_confirmed_log_keeps_it_done(): void
    {
        $owner = $this->user();

        $this->actingAs($owner)->post(route('daily-logs.store'), [
            'title' => 'งานที่ยืนยันแล้ว',
            'kind' => 'routine',
        ]);

        $log = WorkLog::where('user_id', $owner->id)->firstOrFail();
        $this->actingAs($owner)->post(route('daily-logs.complete', $log));

        $this->actingAs($owner)->patch(route('daily-logs.update', $log), [
            'title' => 'งานที่ยืนยันแล้ว (แก้ชื่อ)',
            'kind' => 'routine',
        ])->assertRedirect();

        $this->assertSame('done', $log->refresh()->status);
    }

    /**
     * คนที่ถูกเพิ่มเห็นแผนร่วมในปฏิทิน แต่แก้ไม่ได้
     * เจ้าของแม่แบบเป็นคนเดียวที่แก้การตั้งค่าได้
     */
    public function test_a_teammate_sees_the_shared_routine_in_their_calendar_plan_card(): void
    {
        $department = Department::create(['department_name' => 'IT']);
        $owner = $this->user($department);
        $mate = $this->user($department);

        $template = $this->template($owner, ['title' => 'เช็คคอมพิวเตอร์ห้องบัญชี']);
        $template->participants()->attach($mate->id, ['added_by' => $owner->id]);

        $this->actingAs($mate)
            ->get(route('daily-logs.index', ['view' => 'calendar']))
            ->assertOk()
            ->assertSee('แผนที่ร่วมกับผู้อื่น')
            ->assertSee('เช็คคอมพิวเตอร์ห้องบัญชี');

        $this->actingAs($mate)
            ->delete(route('daily-logs.routines.destroy', $template))
            ->assertForbidden();
    }

    public function test_shared_single_day_routines_are_grouped_into_one_card_with_a_date_range(): void
    {
        $department = Department::create(['department_name' => 'IT']);
        $owner = $this->user($department);
        $mate = $this->user($department);

        foreach (['2026-09-09', '2026-09-10', '2026-09-11'] as $date) {
            $template = $this->template($owner, [
                'title' => 'เช็คคอมพิวเตอร์',
                'starts_on' => $date,
                'ends_on' => $date,
            ]);
            $template->participants()->attach($mate->id, ['added_by' => $owner->id]);
        }

        $response = $this->actingAs($mate)
            ->get(route('daily-logs.index', ['view' => 'calendar']))
            ->assertOk()
            ->assertSee('3 วัน')
            ->assertSee('9 ก.ย. 2569 – 11 ก.ย. 2569')
            ->assertSee('ดูรายละเอียดวันที่');

        $this->assertSame(1, substr_count($response->getContent(), 'daily-plan-management__group--shared'));
    }

    /**
     * คนนอกแผนกถูกตัดทิ้งเงียบ ๆ เพราะรายชื่อที่เลือกได้มาจากเซิร์ฟเวอร์อยู่แล้ว
     * การส่ง id ที่ใช้ไม่ได้มาจึงเป็นการเลี่ยงกติกา ไม่ใช่ความผิดพลาดของผู้ใช้
     */
    public function test_someone_outside_the_department_cannot_be_added_to_a_routine(): void
    {
        $owner = $this->user(Department::create(['department_name' => 'IT']));
        $outsider = $this->user(Department::create(['department_name' => 'HR']));

        $this->actingAs($owner)->post(route('daily-logs.routines.store'), [
            'title' => 'เช็คคอมพิวเตอร์ห้องบัญชี',
            'kind' => 'routine',
            'weekdays' => [0, 1, 2, 3, 4],
            'participants' => [$outsider->id],
        ])->assertRedirect();

        $this->assertDatabaseCount('work_log_template_participants', 0);
        $this->assertDatabaseMissing('system_notifications', ['user_id' => $outsider->id]);
    }

    /**
     * หน้าจอมีทางเข้าเดียวและมีปุ่มลงมือต่อแถวเพียงปุ่มเดียว
     *
     * ระบบจับเวลาถูกถอดออกไปแล้ว งานประจำถูกปิดด้วยการกดยืนยันครั้งเดียว
     * ตามที่ผู้ใช้ทำจริงตอนเช้า คือเข้าไปทำแล้วกลับมากดว่าทำแล้ว
     */
    public function test_the_day_has_one_entry_point_and_no_timer_controls(): void
    {
        $this->travelTo(CarbonImmutable::parse(self::MONDAY_MORNING_UTC, 'UTC'));

        $owner = $this->user();
        $this->template($owner, ['default_start_time' => '08:30', 'default_duration_minutes' => 20]);

        app(WorkLogRoutineMaterializer::class)->materializeToday($owner);

        $response = $this->actingAs($owner)->get(route('daily-logs.index'))->assertOk();

        // ปุ่มเพิ่มงานปุ่มเดียว ครอบทั้งงานครั้งเดียวและงานประจำ
        $this->assertSame(1, substr_count($response->getContent(), 'data-open-entry-modal'));
        $response->assertSee('data-entry-panel="routine"', false)
            ->assertDontSee('data-quick-add-form', false)
            ->assertDontSee('data-roster-board', false);

        // ไม่มีตัวจับเวลาทั่วไป แต่งานประจำมีปุ่มเริ่ม/เสร็จที่ชัดเจน
        $response->assertDontSee('data-row-timer-start', false)
            ->assertDontSee('data-row-timer-stop', false)
            ->assertDontSee('data-timer-banner', false)
            ->assertSee('data-row-start', false);

        $this->assertFalse(Route::has('daily-logs.timer.start'), 'route ของตัวจับเวลาต้องถูกถอดออก');
        $this->assertFalse(Route::has('daily-logs.timer.stop'));
    }

    public function test_owner_can_skip_today_with_a_reason_and_head_cannot_change_it(): void
    {
        $this->travelTo(CarbonImmutable::parse(self::MONDAY_MORNING_UTC, 'UTC'));
        $department = Department::create(['department_name' => 'IT']);
        $owner = $this->user($department);
        $head = $this->user($department);
        $head->update(['is_department_head' => true]);
        $this->template($owner);
        app(WorkLogRoutineMaterializer::class)->materializeToday($owner);
        $log = WorkLog::firstOrFail();

        $this->actingAs($head)->post(route('daily-logs.skip', $log), ['skip_reason' => 'ลางาน'])->assertForbidden();
        $this->actingAs($owner)->post(route('daily-logs.skip', $log), ['skip_reason' => 'ลางาน'])->assertRedirect();

        $this->assertDatabaseHas('work_logs', [
            'id' => $log->id,
            'status' => 'skipped',
            'skip_reason' => 'ลางาน',
        ]);
    }

    public function test_department_head_accounts_cannot_be_added_as_routine_participants(): void
    {
        $department = Department::create(['department_name' => 'IT']);
        $owner = $this->user($department);
        $head = $this->user($department);
        $head->update(['is_department_head' => true]);

        $this->actingAs($owner)->post(route('daily-logs.routines.store'), [
            'title' => 'ตรวจระบบ',
            'weekdays' => [0, 1, 2, 3, 4],
            'participants' => [$head->id],
        ])->assertRedirect();

        $this->assertDatabaseCount('work_log_template_participants', 0);
    }

    public function test_due_routine_appears_in_topbar_and_creates_one_deduplicated_reminder(): void
    {
        $this->travelTo(CarbonImmutable::parse(self::MONDAY_MORNING_UTC, 'UTC'));
        $owner = $this->user();
        $this->template($owner, ['default_start_time' => '08:30', 'default_duration_minutes' => 20]);

        $first = app(RoutineAttentionService::class)->summary($owner);
        $second = app(RoutineAttentionService::class)->summary($owner);

        $this->assertSame(1, $first['total']);
        $this->assertSame(1, $first['waiting']);
        $this->assertSame(1, $first['overdue']);
        $this->assertSame($first['total'], $second['total']);
        $this->assertDatabaseCount('system_notifications', 2);

        $this->actingAs($owner)->get(route('daily-logs.index'))
            ->assertOk()
            ->assertSee('data-routine-topbar', false)
            ->assertSee('งานประจำวันนี้');
    }

    /**
     * งานประจำที่ค้างข้ามวันมาแล้ว เริ่มย้อนหลังไม่ได้เด็ดขาด
     *
     * ถ้ากดเริ่มได้ เวลาที่บันทึกจะเป็นเวลาของวันนี้ทั้งที่รายการผูกอยู่กับเมื่อวาน
     * ทำให้เวลารวมผิดทั้งสองวันพร้อมกัน สิ่งที่ทำได้คือระบุเหตุผลที่ไม่ได้ทำ
     */
    public function test_a_routine_from_a_past_day_cannot_be_started_or_completed(): void
    {
        $this->travelTo(CarbonImmutable::parse(self::MONDAY_MORNING_UTC, 'UTC'));

        $owner = $this->user();
        $this->template($owner, [
            'default_start_time' => '08:30',
            'default_duration_minutes' => 20,
        ]);

        app(WorkLogRoutineMaterializer::class)->materializeToday($owner);
        $log = WorkLog::where('user_id', $owner->id)->firstOrFail();

        // ข้ามไปวันถัดไป รายการของเมื่อวานยังค้างอยู่ในสถานะ open
        $this->travelTo(CarbonImmutable::parse('2026-09-08 02:00:00', 'UTC'));

        $this->actingAs($owner)
            ->post(route('daily-logs.start', $log), ['late_start_reason' => 'ลืมกด'])
            ->assertSessionHasErrors('routine');

        $this->actingAs($owner)
            ->post(route('daily-logs.complete', $log))
            ->assertSessionHasErrors('routine');

        // ปิดรอบ 17:00 ของวันจันทร์แล้ว — สถานะเป็น "ไม่ได้เริ่ม" ไม่ใช่ open ค้าง
        $this->assertSame('not_started', $log->refresh()->status);

        // เหลือทางเดียวคือระบุเหตุผล ซึ่งไม่บันทึกเวลาทำงานให้วันนั้นเลย
        // กติกาปัจจุบัน: สถานะคงเป็น "ไม่ได้เริ่ม" ไม่ถูกแปลงเป็น "ไม่ได้ทำ" (skipped)
        $this->actingAs($owner)
            ->post(route('daily-logs.skip', $log), ['skip_reason' => 'ลืมทำ'])
            ->assertRedirect();

        $log->refresh();

        $this->assertSame('not_started', $log->status);
        $this->assertSame('ลืมทำ', $log->skip_reason);
        $this->assertNull($log->duration_minutes);
    }

    /**
     * หน้าจอของวันย้อนหลังต้องแสดงปุ่มระบุเหตุผลแทนปุ่มเริ่มงาน
     */
    public function test_the_past_day_screen_asks_for_a_reason_instead_of_a_start_button(): void
    {
        $this->travelTo(CarbonImmutable::parse(self::MONDAY_MORNING_UTC, 'UTC'));

        $owner = $this->user();
        $this->template($owner, ['default_start_time' => '08:30', 'default_duration_minutes' => 20]);
        app(WorkLogRoutineMaterializer::class)->materializeToday($owner);

        $this->travelTo(CarbonImmutable::parse('2026-09-08 02:00:00', 'UTC'));

        $this->actingAs($owner)
            ->get(route('daily-logs.index', ['date' => '2026-09-07']))
            ->assertOk()
            ->assertSee('ระบุเหตุผล')
            ->assertSee('ไม่ได้เริ่ม')
            ->assertSee('data-explain-type="not_started"', false)
            ->assertDontSee('data-row-start', false)
            // ปฏิทินเลือกวันได้เอง ไม่ต้องกดลูกศรทีละวัน
            ->assertSee('data-date-input', false);
    }

    private function user(?Department $department = null, string $role = 'user'): User
    {
        return User::factory()->create([
            'role' => $role,
            'department_id' => ($department ?? Department::create(['department_name' => 'IT-'.uniqid()]))->id,
            'is_active' => true,
            'must_change_password' => false,
        ]);
    }

    private function template(User $owner, array $overrides = []): WorkLogTemplate
    {
        return WorkLogTemplate::create(array_merge([
            'user_id' => $owner->id,
            'title' => 'ตรวจสอบคอมพิวเตอร์ประจำวัน',
            'kind' => 'routine',
            'weekday_mask' => WorkLogWeekdays::WORKWEEK,
            'is_active' => true,
        ], $overrides));
    }
}
