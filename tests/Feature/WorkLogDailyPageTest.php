<?php

namespace Tests\Feature;

use App\Models\Department;
use App\Models\User;
use App\Models\WorkLog;
use App\Models\WorkLogCategory;
use App\Models\WorkLogTemplate;
use App\Services\WorkLogQueryService;
use App\Models\WorkOrderList;
use App\Support\TodayWorkspace;
use App\Support\WorkLogDesign;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * หน้าไทม์ไลน์รายวันและการบันทึก/แก้ไข/ลบ
 *
 * ครอบคลุมสัญญาของ jsonOrBack() ด้วย เพราะ endpoint ชุดนี้ถูกเรียกทั้งจากฟอร์ม
 * ปกติและจาก AJAX การตอบผิดรูปแบบทางใดทางหนึ่งจะทำให้หน้าจอค้างโดยไม่มี error
 */
class WorkLogDailyPageTest extends TestCase
{
    use RefreshDatabase;

    public function test_employee_department_calendar_shows_colleagues_but_not_other_departments(): void
    {
        $department = Department::create(['department_name' => 'IT']);
        $otherDepartment = Department::create(['department_name' => 'Sales']);
        $employee = $this->user($department);
        $other = $this->user($department);
        $outside = $this->user($otherDepartment);
        $other->forceFill(['profile_image' => 'profiles/colleague.webp'])->save();
        $this->log($other, $department, ['title' => 'งานของเพื่อนร่วมแผนก']);
        $this->log($outside, $otherDepartment, ['title' => 'งานต่างแผนก']);
        WorkLogTemplate::create([
            'user_id' => $other->id,
            'title' => 'แผนงานประจำของเพื่อนร่วมแผนก',
            'kind' => 'routine',
            'weekday_mask' => 127,
            'starts_on' => $this->today(),
            'ends_on' => $this->today(),
            'is_active' => true,
        ]);

        $this->actingAs($employee)
            ->get(route('daily-logs.index', ['view' => 'calendar', 'scope' => 'team']))
            ->assertOk()
            ->assertViewHas('calendarScope', 'team')
            ->assertViewHas('calendarEntries', function ($entries) use ($other, $outside): bool {
                $items = collect($entries)->flatten(1);

                return $items->contains(fn ($item) => $item['owner'] === $other->name
                        && $item['avatar_url'] === route('media.profile', $other))
                    && $items->contains(fn ($item) => $item['title'] === 'แผนงานประจำของเพื่อนร่วมแผนก'
                        && $item['status'] === 'planned')
                    && $items->every(fn ($item) => $item['owner'] !== $outside->name);
            });
    }

    public function test_department_calendar_shows_existing_logs_and_future_routine_plans(): void
    {
        $department = Department::create(['department_name' => 'IT']);
        $head = $this->user($department, true);
        $employee = $this->user($department);
        $this->log($employee, $department, ['title' => 'งานที่เกิดขึ้นแล้ว']);
        WorkLogTemplate::create([
            'user_id' => $employee->id, 'title' => 'แผนงานประจำของสมาชิก',
            'kind' => 'routine', 'weekday_mask' => 127, 'is_active' => true,
        ]);

        $this->actingAs($head)
            ->get(route('daily-logs.index', ['view' => 'calendar', 'scope' => 'team']))
            ->assertOk()
            ->assertViewHas('calendarScope', 'team')
            ->assertSee('งานที่เกิดขึ้นแล้ว')
            ->assertSee('แผนงานประจำของสมาชิก');
    }

    public function test_employee_calendar_page_defaults_to_department_scope(): void
    {
        $department = Department::create(['department_name' => 'IT']);
        $employee = $this->user($department);
        $colleague = $this->user($department);
        $this->log($colleague, $department, ['title' => 'งานในปฏิทินแผนก']);

        $this->actingAs($employee)
            ->get(route('daily-logs.index', ['view' => 'calendar']))
            ->assertOk()
            ->assertViewHas('calendarScope', 'team')
            ->assertSee('งานในปฏิทินแผนก')
            ->assertSee('ปฏิทินแผนก');
    }

    public function test_calendar_query_itself_does_not_materialize_a_due_template(): void
    {
        $employee = $this->user(Department::create(['department_name' => 'IT']));
        $template = WorkLogTemplate::create([
            'user_id' => $employee->id, 'title' => 'แผนที่ยังไม่ลงบันทึก',
            'kind' => 'routine', 'weekday_mask' => 127, 'is_active' => true,
        ]);

        $entries = app(WorkLogQueryService::class)
            ->calendarFor($employee, collect([$employee]), TodayWorkspace::businessNow());
        $this->assertTrue(collect($entries)->flatten(1)
            ->contains(fn ($item) => $item['title'] === 'แผนที่ยังไม่ลงบันทึก'));
        $this->assertDatabaseMissing('work_logs', ['work_log_template_id' => $template->id]);
    }

    public function test_two_people_can_have_the_same_day_and_category_in_team_calendar(): void
    {
        $department = Department::create(['department_name' => 'IT']);
        $head = $this->user($department, true);
        $first = $this->user($department);
        $second = $this->user($department);
        $category = WorkLogCategory::create(['name' => 'ตรวจเช็ก', 'tone' => 'gray']);
        $this->log($first, $department, ['title' => 'งานคนแรก', 'work_log_category_id' => $category->id]);
        $this->log($second, $department, ['title' => 'งานคนที่สอง', 'work_log_category_id' => $category->id]);

        $this->actingAs($head)
            ->get(route('daily-logs.index', ['view' => 'calendar', 'scope' => 'team', 'category' => $category->id]))
            ->assertOk()
            ->assertViewHas('calendarEntries', fn ($entries) => count($entries[$this->today()] ?? []) === 2);
    }

    public function test_monthly_tab_counts_only_actual_worklogs_with_existing_summary_formula(): void
    {
        $department = Department::create(['department_name' => 'IT']);
        $owner = $this->user($department);
        $this->log($owner, $department, ['title' => 'งานที่บันทึกแล้ว', 'duration_minutes' => 60, 'status' => 'done']);
        WorkLogTemplate::create([
            'user_id' => $owner->id, 'title' => 'แผนที่ยังไม่เกิด',
            'kind' => 'routine', 'weekday_mask' => 127,
            'starts_on' => TodayWorkspace::businessNow()->copy()->addDays(2)->format('Y-m-d'),
            'ends_on' => TodayWorkspace::businessNow()->copy()->addDays(2)->format('Y-m-d'),
            'is_active' => true,
        ]);

        $this->actingAs($owner)
            ->get(route('daily-logs.index', ['view' => 'monthly']))
            ->assertOk()
            ->assertViewHas('monthSummary', fn ($summary) => $summary['total_count'] === 1
                && $summary['total_minutes'] === 60)
            ->assertSee('สรุปรายเดือน')
            ->assertSee('1 ชม.');
    }

    public function test_member_sees_own_today_list_and_view_tabs(): void
    {
        $department = Department::create(['department_name' => 'IT']);
        $owner = $this->user($department);

        $this->log($owner, $department, [
            'title' => 'ตรวจสอบคอมพิวเตอร์ประจำวัน',
            'kind' => 'routine',
            'duration_minutes' => 40,
            'status' => 'done',
        ]);
        $this->log($owner, $department, [
            'title' => 'ไปส่งรถที่ศูนย์บริการ',
            'kind' => 'field',
            'duration_minutes' => 165,
            'status' => 'done',
        ]);

        $this->actingAs($owner)
            ->get(route('daily-logs.index', ['date' => $this->today()]))
            ->assertOk()
            ->assertSee('บันทึกงานประจำวัน')
            ->assertSee('ตรวจสอบคอมพิวเตอร์ประจำวัน')
            ->assertSee('ไปส่งรถที่ศูนย์บริการ')
            ->assertSee('รายการงานวันนี้')
            ->assertSee('ปฏิทินงาน')
            ->assertSee('สรุปรายเดือน')
            ->assertSee('(2 รายการ)');
    }

    public function test_today_list_contains_only_the_selected_day(): void
    {
        $department = Department::create(['department_name' => 'IT']);
        $owner = $this->user($department);

        $this->log($owner, $department, ['duration_minutes' => 60, 'work_date' => $this->today()]);
        $this->log($owner, $department, ['duration_minutes' => 300, 'work_date' => $this->daysAgo(3)]);

        $this->actingAs($owner)
            ->get(route('daily-logs.index', ['date' => $this->today()]))
            ->assertOk()
            ->assertViewHas('logs', fn ($logs) => $logs->count() === 1
                && $logs->first()->work_date->format('Y-m-d') === $this->today());
    }

    public function test_empty_day_shows_a_helpful_empty_state(): void
    {
        $owner = $this->user(Department::create(['department_name' => 'IT']));

        // สถานะว่างยังมีทางเพิ่มงานผ่านกล่องเลือกประเภท
        $this->actingAs($owner)
            ->get(route('daily-logs.index'))
            ->assertOk()
            ->assertSee('ยังไม่มีรายการของวันนี้', false)
            ->assertSee('เพิ่มงาน', false)
            ->assertSee('data-entry-panel="choice"', false);
    }

    public function test_member_can_store_a_log_with_a_time_range(): void
    {
        $department = Department::create(['department_name' => 'IT']);
        $owner = $this->user($department);
        $category = WorkLogCategory::query()->firstOrFail();

        $this->actingAs($owner)
            ->post(route('daily-logs.store'), [
                'title' => 'Support เครื่องพิมพ์บัญชี',
                'kind' => 'routine',
                'work_log_category_id' => $category->id,
                'work_date' => $this->today(),
                'start_time' => '10:45',
                'end_time' => '13:30',
                'requester_name' => 'ฝ่ายบัญชี',
            ])
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->assertDatabaseHas('work_logs', [
            'user_id' => $owner->id,
            'title' => 'Support เครื่องพิมพ์บัญชี',
            'kind' => 'routine',
            // 10:45 ถึง 13:30 = 165 นาที
            'duration_minutes' => 165,
            'status' => 'done',
            // แผนกถูกเก็บเป็น snapshot ตอนสร้าง
            'department_id' => $department->id,
        ]);
    }

    public function test_member_can_store_the_same_log_on_multiple_selected_dates(): void
    {
        $department = Department::create(['department_name' => 'IT']);
        $owner = $this->user($department);
        $category = WorkLogCategory::create(['name' => 'ตรวจเช็ก']);
        $dates = [$this->daysAgo(2), $this->daysAgo(1), $this->today()];

        $this->actingAs($owner)
            ->post(route('daily-logs.store'), [
                'title' => 'ตรวจเช็กคอมพิวเตอร์',
                'kind' => 'routine',
                'work_log_category_id' => $category->id,
                'work_dates' => $dates,
                'duration_minutes' => 30,
            ])
            ->assertRedirect();

        $storedDates = WorkLog::query()
            ->where('user_id', $owner->id)
            ->where('title', 'ตรวจเช็กคอมพิวเตอร์')
            ->orderBy('work_date')
            ->get()
            ->map(fn (WorkLog $log): string => $log->work_date->format('Y-m-d'))
            ->all();

        $this->assertSame($dates, $storedDates);
    }

    /**
     * เคส "ทำ backup 30 นาที" ที่จำเวลานาฬิกาไม่ได้ ต้องบันทึกได้
     */
    public function test_member_can_store_a_log_with_only_a_duration(): void
    {
        $owner = $this->user(Department::create(['department_name' => 'IT']));

        $this->actingAs($owner)->post(route('daily-logs.store'), [
            'title' => 'Backup ข้อมูลรายวัน',
            'kind' => 'routine',
            'duration_minutes' => 30,
        ])->assertRedirect();

        $this->assertDatabaseHas('work_logs', [
            'title' => 'Backup ข้อมูลรายวัน',
            'duration_minutes' => 30,
            'status' => 'done',
        ]);
    }

    public function test_a_log_without_any_time_stays_open(): void
    {
        $owner = $this->user(Department::create(['department_name' => 'IT']));

        $this->actingAs($owner)->post(route('daily-logs.store'), [
            'title' => 'ติดตั้ง Software เครื่องใหม่',
            'kind' => 'routine',
        ])->assertRedirect();

        $this->assertDatabaseHas('work_logs', [
            'title' => 'ติดตั้ง Software เครื่องใหม่',
            'status' => 'open',
            'duration_minutes' => null,
        ]);
    }

    /**
     * งานเวรและงานนอกสถานที่ข้ามเที่ยงคืนได้จริง ต้องไม่กลายเป็นค่าติดลบ
     * และต้องยังนับเป็นงานของวันที่เริ่ม
     */
    public function test_overnight_range_counts_forward_and_keeps_the_start_day(): void
    {
        $owner = $this->user(Department::create(['department_name' => 'IT']));
        $day = $this->today();

        $this->actingAs($owner)->post(route('daily-logs.store'), [
            'title' => 'เฝ้าระบบข้ามคืน',
            'kind' => 'field',
            'work_date' => $day,
            'start_time' => '22:30',
            'end_time' => '01:15',
        ])->assertRedirect();

        $log = WorkLog::query()->where('title', 'เฝ้าระบบข้ามคืน')->firstOrFail();

        $this->assertSame(165, $log->duration_minutes);
        $this->assertSame($day, $log->work_date->format('Y-m-d'));
    }

    public function test_time_range_and_manual_duration_together_are_rejected(): void
    {
        $owner = $this->user(Department::create(['department_name' => 'IT']));

        $this->actingAs($owner)
            ->post(route('daily-logs.store'), [
                'title' => 'งานทดสอบ',
                'kind' => 'routine',
                'start_time' => '09:00',
                'end_time' => '10:00',
                'duration_minutes' => 90,
            ])
            ->assertSessionHasErrors('duration_minutes');

        $this->assertDatabaseMissing('work_logs', ['title' => 'งานทดสอบ']);
    }

    public function test_future_dates_are_rejected(): void
    {
        $owner = $this->user(Department::create(['department_name' => 'IT']));

        $this->actingAs($owner)
            ->post(route('daily-logs.store'), [
                'title' => 'งานพรุ่งนี้',
                'kind' => 'routine',
                'work_date' => TodayWorkspace::businessNow()->addDay()->format('Y-m-d'),
            ])
            ->assertSessionHasErrors('work_date');
    }

    public function test_backfill_beyond_the_limit_is_rejected(): void
    {
        $owner = $this->user(Department::create(['department_name' => 'IT']));

        $this->actingAs($owner)
            ->post(route('daily-logs.store'), [
                'title' => 'งานเก่ามาก',
                'kind' => 'routine',
                'work_date' => $this->daysAgo(WorkLogDesign::MAX_BACKFILL_DAYS + 5),
            ])
            ->assertSessionHasErrors('work_date');
    }

    public function test_owner_can_update_and_delete_own_log(): void
    {
        $department = Department::create(['department_name' => 'IT']);
        $owner = $this->user($department);
        $log = $this->log($owner, $department, ['title' => 'ชื่อเดิม']);

        $this->actingAs($owner)->patch(route('daily-logs.update', $log), [
            'title' => 'ชื่อใหม่',
            'kind' => 'field',
            'location' => 'ศูนย์บริการ',
        ])->assertRedirect();

        $this->assertDatabaseHas('work_logs', [
            'id' => $log->id,
            'title' => 'ชื่อใหม่',
            'kind' => 'field',
            'location' => 'ศูนย์บริการ',
        ]);

        $this->actingAs($owner)->delete(route('daily-logs.destroy', $log))->assertRedirect();

        $this->assertSoftDeleted('work_logs', ['id' => $log->id]);
    }

    public function test_a_member_cannot_edit_another_members_log(): void
    {
        $department = Department::create(['department_name' => 'IT']);
        $owner = $this->user($department);
        $colleague = $this->user($department);
        $log = $this->log($owner, $department);

        $this->actingAs($colleague)
            ->patch(route('daily-logs.update', $log), ['title' => 'แก้ของคนอื่น', 'kind' => 'routine'])
            ->assertForbidden();

        $this->actingAs($colleague)
            ->delete(route('daily-logs.destroy', $log))
            ->assertForbidden();
    }

    /**
     * หัวหน้าและ admin เปิดดูวันของลูกทีมได้ แต่หน้าจอต้องบอกชัดว่าอ่านอย่างเดียว
     * และต้องไม่มีช่องบันทึกงานให้กรอกแทนกัน
     */
    public function test_department_head_can_open_a_member_day_read_only(): void
    {
        $department = Department::create(['department_name' => 'IT']);
        $owner = $this->user($department);
        $head = $this->user($department, true);
        $this->log($owner, $department, ['title' => 'งานของลูกทีม']);

        $this->actingAs($head)
            ->get(route('daily-logs.index', ['user' => $owner->id]))
            ->assertOk()
            ->assertSee('งานของลูกทีม')
            ->assertSee('แบบอ่านอย่างเดียว')
            ->assertDontSee('data-log-composer', false);
    }

    public function test_department_head_cannot_open_a_member_of_another_department(): void
    {
        $it = Department::create(['department_name' => 'IT']);
        $sales = Department::create(['department_name' => 'Sales']);
        $owner = $this->user($it);
        $salesHead = $this->user($sales, true);

        $this->actingAs($salesHead)
            ->get(route('daily-logs.index', ['user' => $owner->id]))
            ->assertForbidden();
    }

    public function test_plain_member_cannot_open_another_members_day(): void
    {
        $department = Department::create(['department_name' => 'IT']);
        $owner = $this->user($department);
        $colleague = $this->user($department);

        $this->actingAs($colleague)
            ->get(route('daily-logs.index', ['user' => $owner->id]))
            ->assertForbidden();
    }

    public function test_viewer_is_blocked_at_the_route_level(): void
    {
        $viewer = $this->user(Department::create(['department_name' => 'IT']), false, 'viewer');

        $this->actingAs($viewer)->get(route('daily-logs.index'))->assertForbidden();
        $this->actingAs($viewer)->post(route('daily-logs.store'), [
            'title' => 'งานของ viewer',
            'kind' => 'routine',
        ])->assertForbidden();

        $this->assertDatabaseMissing('work_logs', ['title' => 'งานของ viewer']);
    }

    /**
     * สัญญาของ jsonOrBack(): AJAX ได้ JSON ส่วนฟอร์มปกติได้ redirect พร้อม flash
     */
    public function test_mutations_answer_json_for_ajax_and_redirect_otherwise(): void
    {
        $department = Department::create(['department_name' => 'IT']);
        $owner = $this->user($department);

        $response = $this->actingAs($owner)
            ->withHeaders(['X-Requested-With' => 'XMLHttpRequest'])
            ->postJson(route('daily-logs.store'), [
                'title' => 'งานผ่าน AJAX',
                'kind' => 'routine',
                'duration_minutes' => 25,
            ])
            ->assertOk()
            ->assertJson(['ok' => true]);

        // payload ต้องมีครบพอให้หน้าจออัปเดตเองโดยไม่ต้อง reload
        $response->assertJsonStructure(['ok', 'message', 'log', 'summary', 'html']);
        $this->assertSame(25, $response->json('log.duration_minutes'));
        $this->assertSame('25 น.', $response->json('log.duration_label'));
        $this->assertSame(25, $response->json('summary.total_minutes'));
        $this->assertStringContainsString('data-log-card', $response->json('html'));
    }

    public function test_optional_project_link_is_kept(): void
    {
        $department = Department::create(['department_name' => 'IT']);
        $owner = $this->user($department);
        $project = WorkOrderList::create(['user_id' => $owner->id, 'name' => 'ระบบเครือข่ายสำนักงานใหม่']);

        $this->actingAs($owner)->post(route('daily-logs.store'), [
            'title' => 'ประชุม Supplier',
            'kind' => 'field',
            'work_order_list_id' => $project->id,
            'duration_minutes' => 90,
        ])->assertRedirect();

        $this->assertDatabaseHas('work_logs', [
            'title' => 'ประชุม Supplier',
            'work_order_list_id' => $project->id,
        ]);
    }

    /**
     * เวลาที่ผูกโปรเจกต์แล้วต้องไม่ถูกนับเป็น "เวลาที่ไม่ได้ลงโปรเจกต์"
     * ซึ่งเป็นตัวเลขที่ผู้บริหารใช้ตอบว่าทำไมโปรเจกต์ไม่ขยับ
     */
    public function test_unlinked_time_excludes_project_linked_work(): void
    {
        $department = Department::create(['department_name' => 'IT']);
        $owner = $this->user($department);
        $project = WorkOrderList::create(['user_id' => $owner->id, 'name' => 'ระบบใหม่']);

        $this->log($owner, $department, ['duration_minutes' => 240, 'kind' => 'field']);
        $this->log($owner, $department, ['duration_minutes' => 60, 'work_order_list_id' => $project->id]);

        $response = $this->actingAs($owner)
            ->withHeaders(['X-Requested-With' => 'XMLHttpRequest'])
            ->postJson(route('daily-logs.store'), [
                'title' => 'งานเพิ่ม',
                'kind' => 'routine',
                'duration_minutes' => 30,
            ])->assertOk();

        $this->assertSame(330, $response->json('summary.total_minutes'));
        $this->assertSame(270, $response->json('summary.unlinked_minutes'));
        $this->assertSame(60, $response->json('summary.project_linked_minutes'));
    }

    public function test_a_future_date_parameter_falls_back_to_today_instead_of_erroring(): void
    {
        $owner = $this->user(Department::create(['department_name' => 'IT']));

        $this->actingAs($owner)
            ->get(route('daily-logs.index', ['date' => '2099-01-01']))
            ->assertOk()
            ->assertSee('วันนี้');
    }

    public function test_menu_appears_for_users_and_admins_but_not_viewers(): void
    {
        $department = Department::create(['department_name' => 'IT']);

        $this->actingAs($this->user($department))
            ->get(route('mytasks.index'))
            ->assertOk()
            ->assertSee('บันทึกงานประจำวัน');

        $this->actingAs($this->user(null, false, 'admin'))
            ->get(route('board.index'))
            ->assertOk()
            ->assertSee('บันทึกงานประจำวัน');

        $this->actingAs($this->user($department, false, 'viewer'))
            ->get(route('board.index'))
            ->assertOk()
            ->assertDontSee('บันทึกงานประจำวัน');
    }

    private function today(): string
    {
        return TodayWorkspace::businessNow()->format('Y-m-d');
    }

    private function daysAgo(int $days): string
    {
        return TodayWorkspace::businessNow()->subDays($days)->format('Y-m-d');
    }

    private function user(?Department $department, bool $head = false, string $role = 'user'): User
    {
        return User::factory()->create([
            'role' => $role,
            'department_id' => $department?->id,
            'is_department_head' => $head,
            'is_active' => true,
            'must_change_password' => false,
        ]);
    }

    private function log(User $owner, ?Department $department, array $overrides = []): WorkLog
    {
        return WorkLog::create(array_merge([
            'user_id' => $owner->id,
            'created_by' => $owner->id,
            'department_id' => $department?->id,
            'kind' => 'routine',
            'status' => 'done',
            'source' => 'manual',
            'title' => 'งานทดสอบ',
            'work_date' => $this->today(),
        ], $overrides));
    }
}
