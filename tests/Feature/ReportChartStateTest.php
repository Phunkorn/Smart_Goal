<?php

namespace Tests\Feature;

use App\Models\Department;
use App\Models\User;
use App\Models\WorkLog;
use App\Models\WorkOrder;
use App\Support\ReportChartState;
use App\Support\ReportMetrics;
use App\Support\TodayWorkspace;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * สถานะแรกของการ์ดกราฟ (data-chart-state) ตัดสินที่ server
 *
 * กราฟที่ไม่มีข้อมูลต้องเป็น "empty" ตั้งแต่ HTML แรก ถ้ารอสคริปต์ กรอบโครงกราฟสูงเต็มจะถูกวาดก่อน
 * แล้วหดลงเป็นกล่องว่าง ดันทุกอย่างด้านล่างขึ้น (วัดได้ CLS 0.0036 บนหน้ารายงานปฏิบัติงานที่ไม่มีข้อมูล)
 */
class ReportChartStateTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_chart_starts_loading_only_when_some_value_is_above_zero(): void
    {
        $this->assertSame('empty', ReportChartState::initial([]));
        $this->assertSame('empty', ReportChartState::initial([0, 0.0, '0', null], [0]));
        $this->assertSame('loading', ReportChartState::initial([0, 0], [0, 0.1]));
        $this->assertSame('loading', ReportChartState::initial(['2']));
        // ค่าที่ไม่ใช่ตัวเลขไม่นับว่ามีข้อมูล — เหมือน safeSeries() ฝั่งสคริปต์
        $this->assertSame('empty', ReportChartState::initial(['abc', -3]));
    }

    public function test_project_report_charts_start_empty_without_data_and_loading_with_data(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-08-22 12:00:00', ReportMetrics::BUSINESS_TIMEZONE));
        $department = Department::create(['department_name' => 'IT']);
        $member = $this->user($department);

        $states = fn () => $this->chartStates($this->actingAs($member)->get(route('reports.projects'))->assertOk()->getContent());
        $this->assertSame(['trend' => 'empty', 'status' => 'empty'], $states());

        WorkOrder::create([
            'user_id' => $member->id, 'created_by' => $member->id, 'leader_user_id' => $member->id, 'department_id' => $department->id,
            'job_topic' => 'งานเดือนสิงหาคม', 'job_priority' => 2, 'job_status' => 2, 'approval_status' => 'approved',
            'job_start_at' => '2026-08-01 09:00:00', 'job_due_at' => '2026-08-31 17:00:00',
        ]);
        $this->assertSame(['trend' => 'loading', 'status' => 'loading'], $states());

        CarbonImmutable::setTestNow();
    }

    public function test_operational_report_charts_start_empty_without_hours_and_loading_with_hours(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-09-20 10:00', TodayWorkspace::BUSINESS_TIMEZONE));
        $department = Department::create(['department_name' => 'IT']);
        $member = $this->user($department);

        $states = fn () => $this->chartStates($this->actingAs($member)->get(route('reports.operational'))->assertOk()->getContent());
        $this->assertSame(['daily' => 'empty', 'types' => 'empty'], $states());

        WorkLog::create([
            'user_id' => $member->id, 'created_by' => $member->id, 'department_id' => $department->id,
            'kind' => 'routine', 'status' => 'done', 'source' => 'manual', 'title' => 'ตรวจระบบ',
            'work_date' => '2026-09-10', 'duration_minutes' => 90,
        ]);
        $this->assertSame(['daily' => 'loading', 'types' => 'loading'], $states());
    }

    /**
     * @return array<string, string>
     */
    private function chartStates(string $html): array
    {
        preg_match_all('/chart-card--(\w+)"[^>]*data-chart-state="(\w+)"/', $html, $matches);

        return array_combine($matches[1], $matches[2]);
    }

    private function user(Department $department): User
    {
        return User::factory()->create([
            'role' => 'user', 'department_id' => $department->id, 'is_active' => true, 'must_change_password' => false,
        ]);
    }
}
