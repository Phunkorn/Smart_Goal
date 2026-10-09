<?php

namespace Tests\Feature;

use App\Models\ComputerCheckResult;
use App\Models\ComputerCheckSession;
use App\Models\ComputerCheckWorkstation;
use App\Models\Department;
use App\Models\User;
use App\Models\WorkLog;
use App\Services\ComputerCheckService;
use App\Support\ComputerCheckDesign;
use App\Support\TodayWorkspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * รอบการตรวจเช็กคอมพิวเตอร์ — เปิดรอบ ติ๊กผล ปิดรอบ และสิทธิ์
 *
 * ครอบคลุมกติกาที่ตัดสินใจไว้ชัดเจนสามข้อซึ่งพังเงียบได้ถ้ามีใครแก้ผิดด้าน:
 *   1. 'pending' ต้องไม่ถูกนับรวมกับ 'issue' ไม่ว่าจะมองจากมุมไหน
 *   2. โหมดเลือกหลายเครื่องบันทึกได้เฉพาะ "ปกติ" เท่านั้น
 *   3. ปิดรอบแล้วแก้ย้อนหลังไม่ได้ เพราะข้อมูลถูกส่งต่อไปรายงานแล้ว
 */
class ComputerCheckSessionTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_floor_plan_matches_the_paper_form(): void
    {
        $stations = ComputerCheckWorkstation::query()->active()->get();

        // 20 เครื่องตามแบบฟอร์มกระดาษ IT26-0-01 — Zone B ซ้าย 9 เครื่อง, Zone A ขวา 11 เครื่อง
        $this->assertCount(20, $stations);
        $this->assertSame(11, $stations->where('zone', 'A')->count());
        $this->assertSame(9, $stations->where('zone', 'B')->count());

        // ทุกเครื่องต้องมีพิกัดอยู่ในกรอบห้อง ไม่งั้นจะวาดหลุดออกนอก viewBox
        $stations->each(function (ComputerCheckWorkstation $station): void {
            $this->assertLessThanOrEqual(
                ComputerCheckDesign::ROOM_WIDTH,
                $station->position_x + $station->width,
                $station->code.' ล้นขอบขวาของผัง'
            );
            $this->assertLessThanOrEqual(
                ComputerCheckDesign::ROOM_HEIGHT,
                $station->position_y + $station->height,
                $station->code.' ล้นขอบล่างของผัง'
            );
        });

        // Zone B อยู่ฝั่งซ้ายของ Zone A เสมอ ตามห้องจริง ห้ามเรียงตามตัวอักษร
        $this->assertLessThan(
            $stations->where('zone', 'A')->min('position_x'),
            $stations->where('zone', 'B')->max('position_x'),
        );
    }

    public function test_starting_a_session_records_two_checkers_the_shift_and_one_pending_row_per_workstation(): void
    {
        $department = Department::create(['department_name' => 'IT', 'computer_check_enabled' => true]);
        $checker = $this->user($department);
        $partner = $this->user($department);

        $this->actingAs($checker)
            ->post(route('daily-logs.computer-check.store'), [
                'check_date' => $this->today(),
                'shift' => 'AM',
                'checker_2_id' => $partner->id,
            ])
            ->assertRedirect();

        $session = ComputerCheckSession::query()->firstOrFail();

        $this->assertSame($checker->id, $session->checker_1_id);
        $this->assertSame($partner->id, $session->checker_2_id);
        $this->assertSame('AM', $session->shift);
        $this->assertSame('in_progress', $session->status);
        $this->assertSame($department->id, $session->department_id);
        // เวลาเริ่มถูกเก็บอัตโนมัติ ไม่ใช่ค่าที่ผู้ใช้กรอก
        $this->assertNotNull($session->started_at);
        $this->assertNull($session->completed_at);

        // แถวผลถูกสร้างครบทุกเครื่องตั้งแต่เปิดรอบ เพื่อให้แบบตรวจย้อนหลังนับจำนวนเครื่องได้
        $this->assertSame(20, $session->results()->count());
        $this->assertSame(20, $session->results()->where('keyboard_mouse_status', 'pending')->count());
    }

    public function test_the_pm_shift_is_a_separate_form_from_the_am_shift(): void
    {
        $checker = $this->user(Department::create(['department_name' => 'IT', 'computer_check_enabled' => true]));

        $morning = $this->openSession($checker, 'AM');
        $afternoon = $this->openSession($checker, 'PM');

        $this->assertNotSame($morning->id, $afternoon->id);

        // เปิดรอบเดิมซ้ำต้องได้ใบเดิม ไม่ใช่ใบที่สอง (กระดาษจริงมีใบเดียวต่อวันต่อกะ)
        $this->assertSame($morning->id, $this->openSession($checker, 'AM')->id);
        $this->assertSame(2, ComputerCheckSession::query()->count());
    }

    public function test_saving_one_workstation_stores_each_device_separately(): void
    {
        $checker = $this->user(Department::create(['department_name' => 'IT', 'computer_check_enabled' => true]));
        $session = $this->openSession($checker);
        $station = $this->station('A-04');

        $this->actingAs($checker)
            ->patch($this->resultRoute($session, $station), [
                'keyboard_mouse' => 'normal',
                'speaker' => 'normal',
                'microphone' => 'issue',
                'internet' => 'normal',
                'computer' => 'normal',
                'note' => 'Microphone ไม่มีเสียง',
            ])
            ->assertRedirect();

        $result = $this->resultOf($session, $station);

        $this->assertSame('issue', $result->microphone_status);
        $this->assertSame('normal', $result->keyboard_mouse_status);
        $this->assertSame('Microphone ไม่มีเสียง', $result->note);
        $this->assertSame($checker->id, $result->checked_by);
        $this->assertNotNull($result->checked_at);
        $this->assertSame('issue', $result->rolledUpStatus());
    }

    public function test_marking_an_issue_without_details_is_rejected(): void
    {
        $checker = $this->user(Department::create(['department_name' => 'IT', 'computer_check_enabled' => true]));
        $session = $this->openSession($checker);
        $station = $this->station('A-04');

        $this->actingAs($checker)
            ->patchJson($this->resultRoute($session, $station), [
                'keyboard_mouse' => 'normal',
                'speaker' => 'normal',
                'microphone' => 'issue',
                'internet' => 'normal',
                'computer' => 'normal',
                'note' => '',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('note');

        $this->assertSame('pending', $this->resultOf($session, $station)->microphone_status);
    }

    /**
     * สถานะรวมของเครื่องต้องแยก "ยังไม่ได้ตรวจ" ออกจาก "ตรวจครบแล้ว" เสมอ
     * นี่คือเหตุผลทั้งหมดที่อุปกรณ์มีสามสถานะแทน boolean
     */
    public function test_a_partly_checked_workstation_is_not_counted_as_checked(): void
    {
        $checker = $this->user(Department::create(['department_name' => 'IT', 'computer_check_enabled' => true]));
        $session = $this->openSession($checker);
        $station = $this->station('A-01');

        $this->actingAs($checker)->patch($this->resultRoute($session, $station), [
            'keyboard_mouse' => 'normal',
            'speaker' => 'normal',
            'microphone' => 'pending',
            'internet' => 'pending',
            'computer' => 'pending',
        ]);

        $result = $this->resultOf($session, $station);

        $this->assertSame('partial', $result->rolledUpStatus());
        $this->assertFalse($result->isFullyChecked());
    }

    public function test_marking_several_workstations_normal_at_once_clears_their_notes(): void
    {
        $checker = $this->user(Department::create(['department_name' => 'IT', 'computer_check_enabled' => true]));
        $session = $this->openSession($checker);
        $first = $this->station('B-01');
        $second = $this->station('B-02');

        // เครื่องแรกเคยมีปัญหาไว้ก่อน — ตั้งเป็นปกติแล้วรายละเอียดเดิมต้องไม่ค้าง
        $this->actingAs($checker)->patch($this->resultRoute($session, $first), [
            'keyboard_mouse' => 'issue',
            'speaker' => 'normal',
            'microphone' => 'normal',
            'internet' => 'normal',
            'computer' => 'normal',
            'note' => 'เมาส์ค้าง',
        ]);

        $this->actingAs($checker)
            ->post(route('daily-logs.computer-check.mark-normal', ['session' => $session->id]), [
                'workstations' => [$first->id, $second->id],
            ])
            ->assertRedirect();

        foreach ([$first, $second] as $station) {
            $result = $this->resultOf($session, $station);

            $this->assertSame('normal', $result->rolledUpStatus());
            $this->assertNull($result->note);
            $this->assertSame($checker->id, $result->checked_by);
        }
    }

    public function test_the_session_summary_counts_every_state_separately(): void
    {
        $checker = $this->user(Department::create(['department_name' => 'IT', 'computer_check_enabled' => true]));
        $session = $this->openSession($checker);

        $this->markNormal($checker, $session, ['A-01', 'A-02', 'A-03']);
        $this->markIssue($checker, $session, 'A-04', 'microphone');
        $this->actingAs($checker)->patch($this->resultRoute($session, $this->station('A-05')), [
            'keyboard_mouse' => 'normal',
            'speaker' => 'pending',
            'microphone' => 'pending',
            'internet' => 'pending',
            'computer' => 'pending',
        ]);

        $summary = ComputerCheckService::summarize($session->results()->with('workstation')->get());

        $this->assertSame([
            'total' => 20,
            'normal' => 3,
            'issue' => 1,
            'partial' => 1,
            'pending' => 15,
            'checked' => 4,
        ], $summary);
    }

    public function test_completing_a_session_is_blocked_until_every_workstation_is_checked(): void
    {
        $checker = $this->user(Department::create(['department_name' => 'IT', 'computer_check_enabled' => true]));
        $session = $this->openSession($checker);

        $this->markNormal($checker, $session, ['A-01']);

        $this->actingAs($checker)
            ->postJson(route('daily-logs.computer-check.complete', ['session' => $session->id]))
            ->assertStatus(422)
            ->assertJsonValidationErrors('results');

        $this->assertSame('in_progress', $session->refresh()->status);
        $this->assertSame(0, WorkLog::query()->count());
    }

    public function test_completing_a_session_creates_one_daily_log_for_the_whole_room(): void
    {
        $department = Department::create(['department_name' => 'IT', 'computer_check_enabled' => true]);
        $checker = $this->user($department);
        $partner = $this->user($department);
        $session = $this->openSession($checker, 'AM', $partner);

        $this->checkEverything($checker, $session);
        $this->markIssue($checker, $session, 'A-04', 'microphone', 'ไม่มีเสียง');

        $this->actingAs($checker)
            ->post(route('daily-logs.computer-check.complete', ['session' => $session->id]))
            ->assertRedirect();

        $session->refresh();

        $this->assertSame('completed', $session->status);
        $this->assertNotNull($session->completed_at);

        // หนึ่งรอบได้บันทึกงานหนึ่งรายการ ไม่ใช่หนึ่งรายการต่อหนึ่งเครื่อง
        $this->assertSame(1, WorkLog::query()->count());

        $log = WorkLog::query()->firstOrFail();

        $this->assertSame($session->work_log_id, $log->id);
        $this->assertSame(ComputerCheckDesign::LOG_TITLE, $log->title);
        $this->assertSame($checker->id, $log->user_id);
        $this->assertSame('done', $log->status);
        // พบปัญหาแม้เครื่องเดียวต้องขึ้นป้าย "พบปัญหา" ไม่ใช่ "เสร็จแล้ว" เฉย ๆ
        $this->assertTrue((bool) $log->has_issue);
        $this->assertStringContainsString('A-04', (string) $log->issue_details);

        $this->assertStringContainsString('ตรวจทั้งหมด 20 เครื่อง', (string) $log->details);
        $this->assertStringContainsString('ปกติ 19 เครื่อง', (string) $log->details);
        $this->assertStringContainsString('พบปัญหา 1 เครื่อง', (string) $log->details);
        $this->assertStringContainsString($partner->name, (string) $log->details);

        // ผู้ตรวจคนที่ 2 เป็นผู้ร่วมงานของบันทึก ไม่ใช่เจ้าของบันทึกอีกใบ
        $this->assertTrue($log->participants()->where('users.id', $partner->id)->exists());

        // หมวดงานถูกผูกกับ IT Support ที่ migration seed ไว้
        $this->assertSame(ComputerCheckDesign::LOG_CATEGORY, $log->category?->name);
    }

    public function test_a_completed_session_cannot_be_edited_afterwards(): void
    {
        $checker = $this->user(Department::create(['department_name' => 'IT', 'computer_check_enabled' => true]));
        $session = $this->openSession($checker);
        $this->checkEverything($checker, $session);
        $this->actingAs($checker)->post(route('daily-logs.computer-check.complete', ['session' => $session->id]));

        $this->actingAs($checker)
            ->patchJson($this->resultRoute($session->refresh(), $this->station('A-01')), [
                'keyboard_mouse' => 'issue',
                'speaker' => 'normal',
                'microphone' => 'normal',
                'internet' => 'normal',
                'computer' => 'normal',
                'note' => 'แก้ย้อนหลัง',
            ])
            ->assertForbidden();

        $this->assertSame('normal', $this->resultOf($session, $this->station('A-01'))->rolledUpStatus());
    }

    public function test_a_colleague_who_is_not_a_checker_cannot_edit_the_session(): void
    {
        $department = Department::create(['department_name' => 'IT', 'computer_check_enabled' => true]);
        $checker = $this->user($department);
        $outsider = $this->user($department);
        $session = $this->openSession($checker);

        $this->actingAs($outsider)
            ->patchJson($this->resultRoute($session, $this->station('A-01')), [
                'keyboard_mouse' => 'normal',
                'speaker' => 'normal',
                'microphone' => 'normal',
                'internet' => 'normal',
                'computer' => 'normal',
            ])
            ->assertForbidden();
    }

    /**
     * ไม่มี admin bypass บนการติ๊กผล — ดูเหตุผลเต็มในหัว ComputerCheckSessionPolicy
     */
    public function test_an_admin_can_read_a_session_but_cannot_tick_results_for_someone_else(): void
    {
        $department = Department::create(['department_name' => 'IT', 'computer_check_enabled' => true]);
        $checker = $this->user($department);
        $admin = $this->user($department, role: 'admin');
        $session = $this->openSession($checker);

        $this->actingAs($admin)
            ->get(route('daily-logs.computer-check.index', ['session' => $session->id]))
            ->assertOk();

        $this->actingAs($admin)
            ->patchJson($this->resultRoute($session, $this->station('A-01')), [
                'keyboard_mouse' => 'normal',
                'speaker' => 'normal',
                'microphone' => 'normal',
                'internet' => 'normal',
                'computer' => 'normal',
            ])
            ->assertForbidden();
    }

    public function test_a_viewer_cannot_reach_the_inspection_page_at_all(): void
    {
        $viewer = $this->user(Department::create(['department_name' => 'IT', 'computer_check_enabled' => true]), role: 'viewer');

        $this->actingAs($viewer)
            ->get(route('daily-logs.computer-check.index'))
            ->assertForbidden();

        $this->actingAs($viewer)
            ->post(route('daily-logs.computer-check.store'), [
                'check_date' => $this->today(),
                'shift' => 'AM',
            ])
            ->assertForbidden();
    }

    public function test_the_second_checker_must_be_an_eligible_colleague(): void
    {
        $checker = $this->user(Department::create(['department_name' => 'IT', 'computer_check_enabled' => true]));
        $otherDepartment = $this->user(Department::create(['department_name' => 'Sales', 'computer_check_enabled' => true]));

        $this->actingAs($checker)
            ->postJson(route('daily-logs.computer-check.store'), [
                'check_date' => $this->today(),
                'shift' => 'AM',
                'checker_2_id' => $otherDepartment->id,
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('checker_2_id');

        $this->actingAs($checker)
            ->postJson(route('daily-logs.computer-check.store'), [
                'check_date' => $this->today(),
                'shift' => 'AM',
                'checker_2_id' => $checker->id,
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('checker_2_id');
    }

    public function test_a_session_cannot_be_opened_for_a_future_date(): void
    {
        $checker = $this->user(Department::create(['department_name' => 'IT', 'computer_check_enabled' => true]));

        $this->actingAs($checker)
            ->postJson(route('daily-logs.computer-check.store'), [
                'check_date' => TodayWorkspace::businessNow()->addDay()->format('Y-m-d'),
                'shift' => 'AM',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('check_date');
    }

    /* ---------- helpers ---------- */

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

    private function today(): string
    {
        return TodayWorkspace::businessNow()->format('Y-m-d');
    }

    private function openSession(User $checker, string $shift = 'AM', ?User $partner = null): ComputerCheckSession
    {
        $this->actingAs($checker)->post(route('daily-logs.computer-check.store'), [
            'check_date' => $this->today(),
            'shift' => $shift,
            'checker_2_id' => $partner?->id,
        ]);

        return ComputerCheckSession::query()
            ->whereDate('check_date', $this->today())
            ->where('shift', $shift)
            ->firstOrFail();
    }

    private function station(string $code): ComputerCheckWorkstation
    {
        return ComputerCheckWorkstation::query()->where('code', $code)->firstOrFail();
    }

    private function resultRoute(ComputerCheckSession $session, ComputerCheckWorkstation $station): string
    {
        return route('daily-logs.computer-check.results.update', [
            'session' => $session->id,
            'workstation' => $station->id,
        ]);
    }

    private function resultOf(ComputerCheckSession $session, ComputerCheckWorkstation $station): ComputerCheckResult
    {
        return ComputerCheckResult::query()
            ->where('computer_check_session_id', $session->id)
            ->where('computer_check_workstation_id', $station->id)
            ->firstOrFail();
    }

    /**
     * @param  array<int, string>  $codes
     */
    private function markNormal(User $checker, ComputerCheckSession $session, array $codes): void
    {
        $this->actingAs($checker)->post(
            route('daily-logs.computer-check.mark-normal', ['session' => $session->id]),
            ['workstations' => array_map(fn (string $code): int => $this->station($code)->id, $codes)]
        );
    }

    private function markIssue(
        User $checker,
        ComputerCheckSession $session,
        string $code,
        string $device,
        string $note = 'มีปัญหา'
    ): void {
        $statuses = array_fill_keys(ComputerCheckDesign::deviceKeys(), 'normal');
        $statuses[$device] = 'issue';

        $this->actingAs($checker)->patch(
            $this->resultRoute($session, $this->station($code)),
            [...$statuses, 'note' => $note]
        );
    }

    /** ตั้งทุกเครื่องเป็นปกติ เพื่อให้ปิดรอบได้ */
    private function checkEverything(User $checker, ComputerCheckSession $session): void
    {
        $this->actingAs($checker)->post(
            route('daily-logs.computer-check.mark-normal', ['session' => $session->id]),
            ['workstations' => ComputerCheckWorkstation::query()->active()->pluck('id')->all()]
        );
    }
}
