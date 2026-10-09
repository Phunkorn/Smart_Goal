<?php

namespace Tests\Feature;

use App\Models\ComputerCheckSession;
use App\Models\ComputerCheckWorkstation;
use App\Models\Department;
use App\Models\User;
use App\Support\ComputerCheckDesign;
use App\Support\TodayWorkspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Regression: รอบตรวจของวัน+กะหนึ่งถูกค้นหาและบังคับความไม่ซ้ำแบบ "ทั้งระบบ"
 * ไม่ได้แยกตามแผนก ทั้งที่สิทธิ์ใช้งานเป็นรายแผนกและตารางเก็บ department_id
 *
 * ผลคือเมื่อ admin เปิดสิทธิ์ให้สองแผนก แผนกที่กดเริ่มรอบเป็นคนที่สองจะได้
 * ใบของแผนกแรกกลับมา ซึ่งตัวเองไม่ได้เป็นผู้ตรวจ จึงติ๊กผลไม่ได้เลย
 */
class ComputerCheckCrossDepartmentSessionTest extends TestCase
{
    use RefreshDatabase;

    public function test_two_permitted_departments_each_get_their_own_session(): void
    {
        $itChecker = $this->user($this->department('IT'));
        $salesChecker = $this->user($this->department('Sales'));

        $today = TodayWorkspace::businessNow()->format('Y-m-d');

        $this->actingAs($itChecker)
            ->post(route('daily-logs.computer-check.store'), ['check_date' => $today, 'shift' => 'AM'])
            ->assertRedirect();

        $this->actingAs($salesChecker)
            ->post(route('daily-logs.computer-check.store'), ['check_date' => $today, 'shift' => 'AM'])
            ->assertRedirect();

        $this->assertSame(
            2,
            ComputerCheckSession::query()->whereDate('check_date', $today)->where('shift', 'AM')->count(),
            'แต่ละแผนกต้องได้ใบตรวจของตัวเอง ไม่ใช่ใบเดียวร่วมกันทั้งระบบ'
        );
    }

    public function test_a_checker_can_tick_results_even_when_another_department_started_first(): void
    {
        $itChecker = $this->user($this->department('IT'));
        $salesChecker = $this->user($this->department('Sales'));

        $today = TodayWorkspace::businessNow()->format('Y-m-d');

        $this->actingAs($itChecker)
            ->post(route('daily-logs.computer-check.store'), ['check_date' => $today, 'shift' => 'AM']);

        $this->actingAs($salesChecker)
            ->post(route('daily-logs.computer-check.store'), ['check_date' => $today, 'shift' => 'AM']);

        $salesSession = ComputerCheckSession::query()
            ->where('checker_1_id', $salesChecker->id)
            ->first();

        $this->assertNotNull($salesSession, 'ผู้ตรวจของแผนกที่สองต้องมีรอบของตัวเอง');

        $station = ComputerCheckWorkstation::query()->first();

        $this->actingAs($salesChecker)
            ->patch(route('daily-logs.computer-check.results.update', [
                'session' => $salesSession->id,
                'workstation' => $station->id,
            ]), array_fill_keys(ComputerCheckDesign::deviceKeys(), 'normal'))
            ->assertSessionHasNoErrors();

        $this->assertDatabaseHas('computer_check_results', [
            'computer_check_session_id' => $salesSession->id,
            'computer_check_workstation_id' => $station->id,
            'checked_by' => $salesChecker->id,
            'computer_status' => 'normal',
        ]);
    }

    private function department(string $name): Department
    {
        return Department::create([
            'department_name' => $name,
            'computer_check_enabled' => true,
        ]);
    }

    private function user(Department $department, string $role = 'user'): User
    {
        return User::factory()->create([
            'role' => $role,
            'department_id' => $department->id,
            'must_change_password' => false,
            'is_active' => true,
        ]);
    }
}
