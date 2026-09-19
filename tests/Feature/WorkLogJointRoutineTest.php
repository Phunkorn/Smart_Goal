<?php

namespace Tests\Feature;

use App\Models\Department;
use App\Models\SystemNotification;
use App\Models\User;
use App\Models\WorkLog;
use App\Models\WorkLogTemplate;
use App\Support\JointRoutineWork;
use App\Support\WorkLogWeekdays;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * งานประจำที่ทำร่วมกัน — ปฏิทินแผนกและรายงานระดับแผนกเห็นเป็นงานชิ้นเดียว
 *
 * การเริ่ม/จบพร้อมกันทดสอบที่ WorkLogRoutineAccountabilityTest ที่นี่ตรวจสิ่งที่คนอื่นมองเห็น
 * วันที่ใช้: จันทร์ 7 ก.ย. 2026 ตามเวลาไทย
 */
class WorkLogJointRoutineTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_department_calendar_shows_joint_work_as_one_row_with_everyone_on_it(): void
    {
        [$head, $owner, $mate, $absentee] = $this->team();

        $this->at('2026-09-07 09:00');
        $this->actingAs($owner)->get(route('daily-logs.index'));
        $this->startJson($owner, $this->logOf($owner), ['attendance' => [$mate->id => 'present', $absentee->id => 'absent']])->assertOk();

        $entries = $this->calendar($head);

        // วันนี้: งานร่วม 1 แถวมีสองคน ส่วนคนที่ไม่มายังเป็นแถวของตัวเอง
        $today = collect($entries['2026-09-07']);
        $this->assertCount(2, $today);
        $joint = $today->firstWhere('status_key', 'in_progress');
        $this->assertSame([$mate->name, $owner->name], collect($joint['people'])->pluck('name')->sort()->values()->all());
        $absent = $today->firstWhere('status_key', 'absent');
        $this->assertSame([$absentee->name], collect($absent['people'])->pluck('name')->all());

        // วันข้างหน้า: แผนของทั้งสามคนเป็นงานชิ้นเดียว
        $tomorrow = collect($entries['2026-09-08']);
        $this->assertCount(1, $tomorrow);
        $this->assertCount(3, $tomorrow->first()['people']);

        // หน้าจอวาดชิปของทุกคนในแถวเดียว
        $this->actingAs($head)->get(route('daily-logs.index', ['view' => 'calendar', 'scope' => 'team', 'month' => '2026-09']))
            ->assertOk()
            ->assertSee('daily-plan__item-people', false);
    }

    public function test_the_morning_before_anyone_starts_is_one_row_even_if_only_one_person_opened_the_system(): void
    {
        [$head, $owner, $mate] = $this->team(withAbsentee: false);
        WorkLogTemplate::query()->update(['default_start_time' => '08:30', 'default_duration_minutes' => 20]);

        // Aum เปิดระบบแล้ว (มีรายการจริง "รอเริ่ม") ส่วน Anutida ยังไม่เปิด (เป็นแผนจากแม่แบบ "ยังไม่เริ่ม")
        $this->at('2026-09-07 08:00');
        $this->actingAs($owner)->get(route('daily-logs.index'));
        $this->assertSame(0, WorkLog::where('user_id', $mate->id)->count());

        $today = collect($this->calendar($head)['2026-09-07']);
        $this->assertCount(1, $today);
        $this->assertSame([$mate->name, $owner->name], collect($today->first()['people'])->pluck('name')->sort()->values()->all());
        $this->assertSame('open', $today->first()['status_key'], 'ป้ายใช้ "รอเริ่ม" ของคนที่เปิดระบบแล้ว');

        // เลยเวลาสิ้นสุดแล้วยังไม่มีใครเริ่ม — ป้าย "เกินเวลา" ต้องไม่ถูกกลบ
        $this->at('2026-09-07 09:30');
        $today = collect($this->calendar($head)['2026-09-07']);
        $this->assertCount(1, $today);
        $this->assertSame('overdue', $today->first()['status_key']);
    }

    public function test_an_issue_reported_by_the_finisher_is_what_the_joint_row_shows(): void
    {
        [$head, $owner, $mate] = $this->team(withAbsentee: false);

        $this->at('2026-09-07 09:00');
        $this->actingAs($owner)->get(route('daily-logs.index'));
        $this->startJson($owner, $this->logOf($owner), ['attendance' => [$mate->id => 'present']])->assertOk();

        $this->at('2026-09-07 09:30');
        $this->actingAs($mate)->postJson(route('daily-logs.complete', $this->logOf($mate)), [
            'outcome' => 'issue',
            'issue_details' => 'จอเครื่อง 3 กระพริบ',
        ])->assertOk();

        $today = collect($this->calendar($head)['2026-09-07']);
        $this->assertCount(1, $today, 'แถวของคนกดเสร็จ (พบปัญหา) กับอีกคน (เสร็จแล้ว) เป็นงานเดียวกัน');
        $this->assertSame('issue', $today->first()['status_key']);
        $this->assertCount(2, $today->first()['people']);
    }

    public function test_department_totals_count_joint_work_once_but_each_person_keeps_it(): void
    {
        [$head, $owner, $mate] = $this->team(withAbsentee: false);

        $this->at('2026-09-07 09:00');
        $this->actingAs($owner)->get(route('daily-logs.index'));
        $this->startJson($owner, $this->logOf($owner), ['attendance' => [$mate->id => 'present']])->assertOk();

        $this->at('2026-09-07 10:00');
        $this->actingAs($owner)->postJson(route('daily-logs.complete', $this->logOf($owner)))->assertOk();

        $response = $this->actingAs($head)->get(route('reports.operational', ['month' => '2026-09']))->assertOk();

        $kpis = $response->viewData('kpis');
        $this->assertSame('1 ครั้ง', $kpis['routine']['value'], 'งานร่วมนับเป็นงานชิ้นเดียวของแผนก');
        $this->assertSame('1 ชม.', $kpis['hours']['value'], 'ชั่วโมงของแผนกนับครั้งเดียว');

        $people = $response->viewData('people')->keyBy('id');
        $this->assertSame([1, 1], [$people[$owner->id]['count'], $people[$mate->id]['count']], 'ยอดรายคนได้ทั้งคู่');
        $this->assertSame(['1 ชม.', '1 ชม.'], [$people[$owner->id]['hours_text'], $people[$mate->id]['hours_text']]);
    }

    public function test_an_open_page_learns_that_a_partner_started_and_gets_the_new_card_without_reloading(): void
    {
        [, $owner, $mate] = $this->team(withAbsentee: false);

        $this->at('2026-09-07 09:00');
        $this->actingAs($mate)->get(route('daily-logs.index'));
        $page = $this->actingAs($mate)->get(route('daily-logs.index'))->assertOk();
        $pageFingerprint = $page->viewData('dayFingerprint');
        $page->assertSee('data-live-day="1"', false);

        // ยังไม่มีอะไรเปลี่ยน — หน้าที่เปิดอยู่ต้องไม่ขอการ์ดใหม่
        $this->actingAs($mate)->getJson(route('daily-logs.routine-status'))
            ->assertOk()->assertJsonPath('fingerprint', $pageFingerprint)->assertJsonMissingPath('cards');

        $this->actingAs($owner)->get(route('daily-logs.index'));
        $this->startJson($owner, $this->logOf($owner), ['attendance' => [$mate->id => 'present']])->assertOk();

        $status = $this->actingAs($mate)->getJson(route('daily-logs.routine-status', ['cards' => 1]))->assertOk();
        $this->assertNotSame($pageFingerprint, $status->json('fingerprint'));
        $this->assertSame('in_progress', $status->json('cards.0.log.status'));
        $this->assertStringContainsString('data-row-complete', $status->json('cards.0.html'), 'ปุ่มเริ่มหายไป เหลือปุ่มเสร็จงาน');
        $this->assertStringNotContainsString('data-row-start', $status->json('cards.0.html'));

        // หน้าที่เปิดวันก่อนหน้าไม่ต้องเช็กสด
        $this->actingAs($mate)->get(route('daily-logs.index', ['date' => '2026-09-04']))
            ->assertOk()->assertSee('data-live-day="0"', false);
    }

    public function test_a_head_watching_a_members_day_sees_field_work_added_and_removed_without_reloading(): void
    {
        [$head, $owner] = $this->team(withAbsentee: false);

        $this->at('2026-09-07 09:00');
        $this->actingAs($owner)->get(route('daily-logs.index'));
        $page = $this->actingAs($head)->get(route('daily-logs.index', ['user' => $owner->id]))->assertOk();
        $page->assertSee('data-live-day="1"', false);
        $watched = $page->viewData('dayFingerprint');
        $status = fn (array $query = []) => $this->actingAs($head)
            ->getJson(route('daily-logs.routine-status', ['user' => $owner->id, 'live' => 1, ...$query]))
            ->assertOk();
        $this->assertSame($watched, $status()->json('fingerprint'), 'ยังไม่มีอะไรเปลี่ยน');

        // พนักงานเพิ่มงานนอกสถานที่ระหว่างที่หัวหน้าเปิดหน้าค้างไว้
        $field = WorkLog::create([
            'user_id' => $owner->id, 'created_by' => $owner->id, 'department_id' => $owner->department_id,
            'kind' => 'field', 'status' => 'done', 'source' => 'manual', 'title' => 'ส่งเครื่องสาขาบางนา',
            'work_date' => '2026-09-07', 'duration_minutes' => 30,
        ]);

        $added = $status(['cards' => 1]);
        $this->assertNotSame($watched, $added->json('fingerprint'));
        $card = collect($added->json('cards'))->firstWhere('log.id', $field->id);
        $this->assertNotNull($card, 'การ์ดงานนอกสถานที่ต้องมากับการเช็กสดด้วย');
        $this->assertStringContainsString('ส่งเครื่องสาขาบางนา', $card['html']);
        $this->assertStringNotContainsString('data-log-menu-trigger', $card['html'], 'หัวหน้าได้การ์ดแบบอ่านอย่างเดียว');

        // แก้ชื่อก็ต้องรู้ แม้สถานะไม่เปลี่ยน
        $this->travel(1)->minutes();
        $before = $status()->json('fingerprint');
        $field->update(['title' => 'ส่งเครื่องสาขาบางนา (รอบ 2)']);
        $this->assertNotSame($before, $status()->json('fingerprint'));

        // ลบแล้วการ์ดต้องหายจากชุด
        $field->delete();
        $this->assertNull(collect($status(['cards' => 1])->json('cards'))->firstWhere('log.id', $field->id));
    }

    public function test_the_ten_second_live_check_only_reads(): void
    {
        [, , $mate] = $this->team(withAbsentee: false);

        // วันที่ไม่มีใครเปิดระบบ — การเช็กสดต้องไม่สร้างงาน ไม่ปิดรอบ และไม่ส่งแจ้งเตือน
        $this->at('2026-09-08 09:00');
        $live = $this->actingAs($mate)->getJson(route('daily-logs.routine-status', ['live' => 1]))->assertOk();
        $this->assertSame(['fingerprint'], array_keys($live->json()));
        $this->assertSame(0, WorkLog::count());
        $this->assertSame(0, SystemNotification::count());

        // การเรียกแบบเดิม (แถบด้านบนทุก 60 วินาที) ยังปิดรอบและสร้างงานของวันนี้ตามปกติ
        $this->actingAs($mate)->getJson(route('daily-logs.routine-status'))->assertOk()->assertJsonStructure(['attention', 'items', 'fingerprint']);
        $this->assertGreaterThan(0, WorkLog::where('user_id', $mate->id)->count());
    }

    public function test_someone_viewing_another_persons_day_gets_read_only_cards(): void
    {
        [$head, $owner, $mate] = $this->team(withAbsentee: false);

        $this->at('2026-09-07 09:00');
        $this->actingAs($owner)->get(route('daily-logs.index'));
        $this->startJson($owner, $this->logOf($owner), ['attendance' => [$mate->id => 'present']])->assertOk();

        $html = $this->actingAs($head)
            ->getJson(route('daily-logs.routine-status', ['user' => $mate->id, 'cards' => 1]))
            ->assertOk()
            ->json('cards.0.html');

        $this->assertStringNotContainsString('data-row-complete', $html);
        $this->assertStringNotContainsString('data-row-start', $html);
    }

    public function test_the_daily_report_row_shows_times_coworkers_and_every_reason(): void
    {
        [, $owner, $mate] = $this->team(withAbsentee: false);
        WorkLogTemplate::query()->update(['default_start_time' => '08:30', 'default_duration_minutes' => 20]);

        $this->at('2026-09-07 09:10');
        $this->actingAs($owner)->get(route('daily-logs.index'));
        $this->startJson($owner, $this->logOf($owner), ['attendance' => [$mate->id => 'present'], 'late_start_reason' => 'รถติด'])->assertOk();

        $this->at('2026-09-07 09:40');
        $this->actingAs($owner)->postJson(route('daily-logs.complete', $this->logOf($owner)), [
            'outcome' => 'issue',
            'issue_details' => 'จอเครื่อง 3 กระพริบ',
            'late_completion_reason' => 'งานเยอะกว่าปกติ',
        ])->assertOk();

        WorkLog::create([
            'user_id' => $owner->id, 'created_by' => $owner->id, 'department_id' => $owner->department_id,
            'kind' => 'field', 'status' => 'done', 'source' => 'manual', 'title' => 'ส่งเครื่องสาขา',
            'work_date' => '2026-09-07', 'location' => 'สาขาบางนา', 'requester_name' => 'สมชาย', 'duration_minutes' => 30,
        ])->participants()->attach($mate->id, ['added_by' => $owner->id]);

        $rows = $this->actingAs($owner)->get(route('reports.operational.daily', ['month' => '2026-09']))
            ->assertOk()->viewData('rows')->keyBy('title');

        $routine = $rows['เช็คคอมชั้น 5'];
        $this->assertSame(['08:30–08:50', '09:10', '09:40'], [$routine['planned_time'], $routine['start_time'], $routine['end_time']]);
        $this->assertSame('พบปัญหา', $routine['status_label']);
        $this->assertSame($mate->name, $routine['coworkers']);
        $this->assertSame('เริ่มช้า: รถติด · เสร็จช้า: งานเยอะกว่าปกติ · ปัญหาที่พบ: จอเครื่อง 3 กระพริบ', $routine['reason'], 'เหตุผลข้อหนึ่งต้องไม่บังอีกข้อ');

        $field = $rows['ส่งเครื่องสาขา'];
        $this->assertSame('สาขาบางนา (ผู้แจ้ง: สมชาย)', $field['place']);
        $this->assertSame($mate->name, $field['coworkers']);

        // ผู้ร่วมงานเห็นชื่อคนกดในแถวของตัวเอง แต่เหตุผลอยู่ที่คนกดคนเดียว
        $mateRow = $this->actingAs($mate)->get(route('reports.operational.daily', ['month' => '2026-09']))
            ->viewData('rows')->firstWhere('title', 'เช็คคอมชั้น 5');
        $this->assertSame($owner->name, $mateRow['coworkers']);
        $this->assertSame('เสร็จแล้ว', $mateRow['status_label']);
        $this->assertSame('—', $mateRow['reason']);
    }

    public function test_only_statuses_people_share_are_grouped(): void
    {
        $this->assertSame('5|2026-09-07|done', JointRoutineWork::key(5, '2026-09-07', 'done'));
        $this->assertSame('5|2026-09-07|done', JointRoutineWork::key(5, '2026-09-07', 'issue'), 'พบปัญหา = เสร็จแล้วของทีมเดียวกัน');
        $this->assertSame('5|2026-09-08|planned', JointRoutineWork::key(5, '2026-09-08', 'planned'));

        // ยังไม่มีใครเริ่ม: รอเริ่ม / ยังไม่เริ่ม (ยังไม่เปิดระบบ) / เกินเวลา คือช่วงเดียวกันของงานชิ้นเดียว
        foreach (['waiting', 'overdue'] as $status) {
            $this->assertSame(JointRoutineWork::key(5, '2026-09-07', 'open'), JointRoutineWork::key(5, '2026-09-07', $status), $status);
        }
        $this->assertNotSame(JointRoutineWork::key(5, '2026-09-07', 'open'), JointRoutineWork::key(5, '2026-09-07', 'in_progress'));

        foreach (['absent', 'not_started', 'unfinished', 'skipped', 'not_logged'] as $status) {
            $this->assertNull(JointRoutineWork::key(5, '2026-09-07', $status), $status.' ต้องแยกแถวของใครของมัน');
        }

        $this->assertNull(JointRoutineWork::key(null, '2026-09-07', 'done'), 'งานที่ไม่ใช่งานประจำไม่รวม');
    }

    /** @return array<string, list<array<string, mixed>>> */
    private function calendar(User $viewer): array
    {
        return $this->actingAs($viewer)
            ->get(route('daily-logs.index', ['view' => 'calendar', 'scope' => 'team', 'month' => '2026-09']))
            ->assertOk()
            ->viewData('calendarEntries');
    }

    /** @return array{0: User, 1: User, 2: User, 3: ?User} */
    private function team(bool $withAbsentee = true): array
    {
        $this->at('2026-09-07 07:00');
        $department = Department::create(['department_name' => 'IT']);
        $head = $this->user($department, 'หัวหน้า', true);
        $owner = $this->user($department, 'Aum');
        $mate = $this->user($department, 'Anutida');
        $absentee = $withAbsentee ? $this->user($department, 'คนที่ไม่มา') : null;

        $template = WorkLogTemplate::create([
            'user_id' => $owner->id,
            'title' => 'เช็คคอมชั้น 5',
            'kind' => 'routine',
            'weekday_mask' => WorkLogWeekdays::WORKWEEK,
            'is_active' => true,
        ]);
        foreach (array_filter([$mate, $absentee]) as $person) {
            $template->participants()->attach($person->id, ['added_by' => $owner->id]);
        }

        return [$head, $owner, $mate, $absentee];
    }

    private function startJson(User $user, WorkLog $log, array $payload = [])
    {
        return $this->actingAs($user)->postJson(route('daily-logs.start', $log), $payload);
    }

    private function logOf(User $user): WorkLog
    {
        return WorkLog::where('user_id', $user->id)->whereDate('work_date', '2026-09-07')->firstOrFail();
    }

    private function at(string $bangkok): void
    {
        $this->travelTo(CarbonImmutable::parse($bangkok, 'Asia/Bangkok'));
    }

    private function user(Department $department, string $name, bool $head = false): User
    {
        return User::factory()->create([
            'role' => 'user',
            'name' => $name,
            'department_id' => $department->id,
            'is_department_head' => $head,
            'is_active' => true,
            'must_change_password' => false,
        ]);
    }
}
