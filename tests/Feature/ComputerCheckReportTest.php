<?php

namespace Tests\Feature;

use App\Models\ComputerCheckSession;
use App\Models\ComputerCheckWorkstation;
use App\Models\Department;
use App\Models\User;
use App\Services\OperationalWorkloadReportService;
use App\Support\ComputerCheckDesign;
use App\Support\TodayWorkspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * แบบตรวจ A4 ประวัติการตรวจ และการเปิดย้อนหลังจากรายงานปฏิบัติงาน
 *
 * หัวใจของไฟล์นี้คือ "เปิดย้อนหลังแล้วต้องได้ข้อมูลของวันนั้น" — แบบตรวจต้องไม่
 * เปลี่ยนไปตามผังปัจจุบันหรือผลการตรวจรอบหลัง ถ้าข้อนี้พัง แบบตรวจจะใช้เป็น
 * หลักฐานไม่ได้เลย ซึ่งเป็นเหตุผลเดียวที่ฟีเจอร์นี้มีอยู่
 */
class ComputerCheckReportTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_report_renders_every_workstation_with_its_device_marks(): void
    {
        $checker = $this->user();
        $session = $this->completedSession($checker, issueAt: 'A-04', device: 'microphone', note: 'ไม่มีเสียง');

        $response = $this->actingAs($checker)
            ->get(route('daily-logs.computer-check.report', ['session' => $session->id]))
            ->assertOk();

        // ผังในแบบตรวจต้องมีครบทั้ง 20 เครื่อง พร้อมรหัสเครื่อง
        foreach (ComputerCheckWorkstation::query()->active()->pluck('code') as $code) {
            $response->assertSee($code);
        }

        // Legend ของตัวอักษรย่อ ต้องมีเสมอ ไม่งั้นช่อง K/S/M/I/C อ่านไม่ออก
        foreach (ComputerCheckDesign::DEVICES as $device) {
            $response->assertSee($device['label']);
        }

        // รายการปัญหาท้ายแบบตรวจ
        $response->assertSee('รายการปัญหา');
        $response->assertSee('ไม่มีเสียง');
        $response->assertSee('Microphone');

        // ช่องรอบติ๊กตามกะจริง และหัวแบบตรวจมีรหัสฟอร์มของกระดาษต้นฉบับ
        $response->assertSee(ComputerCheckDesign::FORM_CODE);
        $response->assertSee('รอบเช้า (AM)');
    }

    /**
     * ผังในแบบตรวจกับผังบนเว็บต้องวาดจากพิกัดชุดเดียวกัน
     *
     * ตรวจด้วยการมองหาพิกัดจริงของเครื่องหนึ่งใน SVG ของทั้งสองหน้า ถ้าวันหนึ่ง
     * มีคนทำผังชุดที่สองขึ้นมา เทสต์นี้จะพังทันที
     */
    public function test_the_web_plan_and_the_printed_plan_use_the_same_coordinates(): void
    {
        $checker = $this->user();
        $session = $this->completedSession($checker);
        $station = ComputerCheckWorkstation::query()->where('code', 'A-04')->firstOrFail();

        $marker = sprintf('x="%d"', $station->position_x);

        $this->actingAs($checker)
            ->get(route('daily-logs.computer-check.index', ['session' => $session->id]))
            ->assertOk()
            ->assertSee($marker, false)
            ->assertSee(sprintf('viewBox="0 0 %d %d"', ComputerCheckDesign::ROOM_WIDTH, ComputerCheckDesign::ROOM_HEIGHT), false);

        $this->actingAs($checker)
            ->get(route('daily-logs.computer-check.report', ['session' => $session->id]))
            ->assertOk()
            ->assertSee($marker, false)
            ->assertSee(sprintf('viewBox="0 0 %d %d"', ComputerCheckDesign::ROOM_WIDTH, ComputerCheckDesign::ROOM_HEIGHT), false);
    }

    /**
     * ข้อกำหนดสำคัญ: ใบเก่าต้องไม่อ่านสถานะของรอบใหม่มาแทน
     */
    public function test_an_older_report_keeps_the_results_of_its_own_session(): void
    {
        $checker = $this->user();

        // รอบเช้า — A-04 มีปัญหา
        $morning = $this->completedSession($checker, issueAt: 'A-04', device: 'microphone', note: 'ไม่มีเสียง');

        // รอบบ่าย — ทุกเครื่องปกติ
        $afternoon = $this->completedSession($checker, shift: 'PM');

        $this->actingAs($checker)
            ->get(route('daily-logs.computer-check.report', ['session' => $morning->id]))
            ->assertOk()
            ->assertSee('ไม่มีเสียง')
            ->assertSee('รายการปัญหา');

        // ใบบ่ายต้องไม่มีปัญหาของรอบเช้าติดมา
        $this->actingAs($checker)
            ->get(route('daily-logs.computer-check.report', ['session' => $afternoon->id]))
            ->assertOk()
            ->assertDontSee('ไม่มีเสียง')
            ->assertDontSee('รายการปัญหา');
    }

    /**
     * เพิ่มเครื่องเข้าผังทีหลัง ต้องไม่ทำให้จำนวนเครื่องของใบเก่าเปลี่ยน
     */
    public function test_adding_a_workstation_later_does_not_change_an_older_report(): void
    {
        $checker = $this->user();
        $session = $this->completedSession($checker);

        ComputerCheckWorkstation::create([
            'code' => 'A-12',
            'zone' => 'A',
            'position_x' => 794,
            'position_y' => 20,
            'width' => 178,
            'height' => 76,
            'is_active' => true,
            'display_order' => 21,
        ]);

        $this->actingAs($checker)
            ->get(route('daily-logs.computer-check.report', ['session' => $session->id]))
            ->assertOk()
            // ใบเก่ายังเป็น 20 เครื่องตามวันที่ตรวจจริง
            ->assertSee('<td>20</td>', false)
            ->assertDontSee('A-12');
    }

    public function test_the_history_tab_lists_past_sessions_with_their_counts(): void
    {
        $checker = $this->user();
        $this->completedSession($checker, issueAt: 'A-04', device: 'internet', note: 'เชื่อมต่อไม่ได้');
        $this->completedSession($checker, shift: 'PM');

        $this->actingAs($checker)
            ->get(route('daily-logs.computer-check.index', ['tab' => 'history']))
            ->assertOk()
            ->assertViewHas('history', function (array $history): bool {
                $morning = collect($history)->firstWhere('shift', 'AM');
                $afternoon = collect($history)->firstWhere('shift', 'PM');

                return $morning['total'] === 20
                    && $morning['normal'] === 19
                    && $morning['issue'] === 1
                    && $afternoon['normal'] === 20
                    && $afternoon['issue'] === 0;
            })
            ->assertSee('ประวัติการตรวจ');
    }

    public function test_the_daily_log_timeline_offers_the_report_for_the_linked_entry(): void
    {
        $checker = $this->user();
        $session = $this->completedSession($checker);
        $this->acknowledgeDailyBrief($checker);

        $this->actingAs($checker)
            ->get(route('daily-logs.index'))
            ->assertOk()
            ->assertSee('ดูแบบตรวจ')
            ->assertSee(route('daily-logs.computer-check.report', ['session' => $session->id]), false);
    }

    public function test_the_operational_report_offers_the_report_for_the_linked_entry(): void
    {
        $checker = $this->user();
        $session = $this->completedSession($checker);
        $this->acknowledgeDailyBrief($checker);

        $this->actingAs($checker)
            ->get(route('reports.operational.daily'))
            ->assertOk()
            ->assertSee('ดูแบบตรวจ')
            ->assertSee(route('daily-logs.computer-check.report', ['session' => $session->id]), false);
    }

    /**
     * ไฟล์ CSV ของรายงานปฏิบัติงานต้องมีคอลัมน์เท่าเดิม
     *
     * ลิงก์แบบตรวจเป็นของหน้าจอ ไม่ใช่ข้อมูลของ CSV ถ้าหลุดเข้าไปจะทำให้ไฟล์ที่
     * คนอื่นเปิดด้วย Excel อยู่แล้วเลื่อนคอลัมน์ทั้งแผ่น
     */
    public function test_the_csv_columns_are_unchanged_by_the_inspection_link(): void
    {
        $checker = $this->user();
        $this->completedSession($checker);

        $response = $this->actingAs($checker)
            ->get(route('reports.operational.daily.csv'))
            ->assertOk();

        $csv = $response->streamedContent();
        $header = explode("\n", trim($csv))[0];

        $this->assertStringNotContainsString('computer-check', $csv);
        $this->assertSame(
            count(OperationalWorkloadReportService::DAILY_HEADERS),
            count(str_getcsv(trim($header, "\u{FEFF}")))
        );
    }

    /* ---------- สิทธิ์ ---------- */

    public function test_a_colleague_outside_the_session_cannot_open_the_report(): void
    {
        $department = Department::create(['department_name' => 'IT', 'computer_check_enabled' => true]);
        $checker = $this->user($department);
        $outsider = $this->user($department);
        $session = $this->completedSession($checker);

        $this->actingAs($outsider)
            ->get(route('daily-logs.computer-check.report', ['session' => $session->id]))
            ->assertForbidden();
    }

    public function test_a_department_head_and_an_admin_can_open_the_report(): void
    {
        $department = Department::create(['department_name' => 'IT', 'computer_check_enabled' => true]);
        $checker = $this->user($department);
        $head = $this->user($department, head: true);
        $admin = $this->user($department, role: 'admin');
        $session = $this->completedSession($checker);

        $url = route('daily-logs.computer-check.report', ['session' => $session->id]);

        $this->actingAs($head)->get($url)->assertOk();
        $this->actingAs($admin)->get($url)->assertOk();
    }

    public function test_a_viewer_cannot_open_the_report(): void
    {
        $department = Department::create(['department_name' => 'IT', 'computer_check_enabled' => true]);
        $checker = $this->user($department);
        $viewer = $this->user($department, role: 'viewer');
        $session = $this->completedSession($checker);

        $this->actingAs($viewer)
            ->get(route('daily-logs.computer-check.report', ['session' => $session->id]))
            ->assertForbidden();
    }

    public function test_the_report_is_unreachable_without_logging_in(): void
    {
        $session = $this->completedSession($this->user());

        // completedSession() ใช้ actingAs() อยู่ภายใน ต้องล้าง guard ก่อน
        // ไม่งั้น request ถัดไปยังถือว่าล็อกอินอยู่ แล้วเทสต์นี้จะผ่านแบบหลอก ๆ
        $this->app['auth']->forgetGuards();

        $this->get(route('daily-logs.computer-check.report', ['session' => $session->id]))
            ->assertRedirect(route('login'));
    }

    /* ---------- helpers ---------- */

    private function user(?Department $department = null, bool $head = false, string $role = 'user'): User
    {
        return User::factory()->create([
            'role' => $role,
            // แผนกต้องถูกเปิดสิทธิ์ใช้ "ตรวจเช็กคอมพิวเตอร์ประจำวัน" ไม่งั้นเปิดรอบไม่ได้เลย
            // (ดู ComputerCheckSessionPolicy และ App\Support\ComputerCheckAccess)
            'department_id' => ($department ?? Department::firstOrCreate(
                ['department_name' => 'IT'],
                ['computer_check_enabled' => true]
            ))->id,
            'is_department_head' => $head,
            'is_active' => true,
            'must_change_password' => false,
        ]);
    }

    /**
     * รอบที่ตรวจครบและปิดแล้ว พร้อมปัญหาหนึ่งจุดถ้าระบุมา
     */
    private function completedSession(
        User $checker,
        string $shift = 'AM',
        ?string $issueAt = null,
        string $device = 'microphone',
        string $note = 'มีปัญหา'
    ): ComputerCheckSession {
        $this->actingAs($checker)->post(route('daily-logs.computer-check.store'), [
            'check_date' => TodayWorkspace::businessNow()->format('Y-m-d'),
            'shift' => $shift,
        ]);

        $session = ComputerCheckSession::query()
            ->whereDate('check_date', TodayWorkspace::businessNow()->format('Y-m-d'))
            ->where('shift', $shift)
            ->firstOrFail();

        $this->actingAs($checker)->post(
            route('daily-logs.computer-check.mark-normal', ['session' => $session->id]),
            ['workstations' => ComputerCheckWorkstation::query()->active()->pluck('id')->all()]
        );

        if ($issueAt !== null) {
            $statuses = array_fill_keys(ComputerCheckDesign::deviceKeys(), 'normal');
            $statuses[$device] = 'issue';

            $this->actingAs($checker)->patch(
                route('daily-logs.computer-check.results.update', [
                    'session' => $session->id,
                    'workstation' => ComputerCheckWorkstation::query()->where('code', $issueAt)->value('id'),
                ]),
                [...$statuses, 'note' => $note]
            );
        }

        $this->actingAs($checker)->post(route('daily-logs.computer-check.complete', ['session' => $session->id]));

        return $session->refresh();
    }
}
