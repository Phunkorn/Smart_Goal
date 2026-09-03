<?php

namespace Tests\Feature;

use App\Models\Department;
use App\Models\SystemNotification;
use App\Models\User;
use App\Models\WorkOrder;
use App\Support\ApprovalPresenter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * หน้าคำขออนุมัติและการแจ้งเตือนเคยพูดเป็นภาษาอังกฤษดิบ ("pending") และเรียกผู้ตัดสิน
 * ว่า "ผู้ดูแลระบบ" เสมอ ทั้งที่ผู้อนุมัติจริงส่วนใหญ่คือหัวหน้าแผนกปลายทาง
 */
class ApprovalWordingTest extends TestCase
{
    use RefreshDatabase;

    public function test_pending_badge_is_thai_and_never_shows_the_raw_english_status(): void
    {
        [$it, $marketing] = [Department::create(['department_name' => 'IT']), Department::create(['department_name' => 'Marketing'])];
        $requester = $this->user('user', $it);
        $assignee = $this->user('user', $marketing);
        $head = $this->departmentHead($marketing);

        $this->task($requester, $assignee, $marketing, 'ขอให้ช่วยทำสไลด์', 'pending');

        $response = $this->actingAs($head)->get(route('admin.approvals.index'))->assertOk();

        $response->assertSee('รอตรวจสอบ');
        $response->assertDontSee('>pending<', false);
    }

    public function test_header_names_the_real_scope_instead_of_calling_every_viewer_an_admin(): void
    {
        $it = Department::create(['department_name' => 'IT']);
        $head = $this->departmentHead($it);
        $admin = $this->user('admin', $it);

        $this->actingAs($head)->get(route('admin.approvals.index'))
            ->assertOk()
            ->assertSee('DEPARTMENT APPROVALS')
            ->assertSee('แผนกIT')
            ->assertDontSee('จัดการคำขอที่ต้องได้รับการอนุมัติจากผู้ดูแลระบบ');

        $this->actingAs($admin)->get(route('admin.approvals.index'))
            ->assertOk()
            ->assertSee('ADMIN APPROVALS')
            ->assertSee('ทุกแผนกในระบบ');
    }

    public function test_approval_row_shows_the_task_details_so_the_head_is_not_deciding_blind(): void
    {
        [$it, $marketing] = [Department::create(['department_name' => 'IT']), Department::create(['department_name' => 'Marketing'])];
        $task = $this->task($this->user('user', $it), $this->user('user', $marketing), $marketing, 'ขอสไลด์', 'pending');
        $task->update(['job_details' => 'ต้องใช้ในการประชุมผู้บริหารวันศุกร์']);

        $this->actingAs($this->departmentHead($marketing))
            ->get(route('admin.approvals.index'))
            ->assertOk()
            ->assertSee('ต้องใช้ในการประชุมผู้บริหารวันศุกร์');
    }

    public function test_decision_notification_credits_the_head_who_decided_not_the_system_admin(): void
    {
        [$it, $marketing] = [Department::create(['department_name' => 'IT']), Department::create(['department_name' => 'Marketing'])];
        $requester = $this->user('user', $it);
        $assignee = $this->user('user', $marketing);
        $head = $this->departmentHead($marketing);
        $task = $this->task($requester, $assignee, $marketing, 'ขอสไลด์', 'pending');

        $this->actingAs($head)
            ->patch(route('admin.tasks.approval', $task->job_id), ['approval_status' => 'approved'])
            ->assertRedirect();

        $message = SystemNotification::query()->where('user_id', $requester->id)->value('message');

        $this->assertStringContainsString($head->name, (string) $message);
        $this->assertStringContainsString('หัวหน้าแผนกMarketing', (string) $message);
        $this->assertStringNotContainsString('ผู้ดูแลระบบอนุมัติ', (string) $message);
    }

    public function test_requester_sees_that_the_cross_department_request_is_still_waiting(): void
    {
        [$it, $marketing] = [Department::create(['department_name' => 'IT']), Department::create(['department_name' => 'Marketing'])];
        $requester = $this->user('user', $it);
        $this->task($requester, $this->user('user', $marketing), $marketing, 'งานรออนุมัติ', 'pending');

        $this->actingAs($requester)->get(route('mytasks.index'))
            ->assertOk()
            ->assertSee('รอตรวจสอบจากแผนกปลายทาง');
    }

    public function test_approver_label_falls_back_safely_and_names_the_role_that_grants_the_power(): void
    {
        $it = Department::create(['department_name' => 'IT']);

        $this->assertSame('ผู้ดูแลระบบ', ApprovalPresenter::roleLabel(null));
        $this->assertSame('ผู้ดูแลระบบ', ApprovalPresenter::roleLabel($this->user('admin', $it)));
        $this->assertSame('หัวหน้าแผนกIT', ApprovalPresenter::roleLabel($this->departmentHead($it)));
        $this->assertSame('ผู้อนุมัติ', ApprovalPresenter::roleLabel($this->user('user', $it)));
        $this->assertSame('อนุมัติ', ApprovalPresenter::decisionVerb('accepted'));
        $this->assertSame('ปฏิเสธ', ApprovalPresenter::decisionVerb('rejected'));
    }

    private function user(string $role, Department $department): User
    {
        return User::factory()->create([
            'role' => $role,
            'department_id' => $department->id,
            'must_change_password' => false,
            'is_active' => true,
        ]);
    }

    private function departmentHead(Department $department): User
    {
        return User::factory()->create([
            'role' => 'user',
            'department_id' => $department->id,
            'is_department_head' => true,
            'must_change_password' => false,
            'is_active' => true,
        ]);
    }

    private function task(User $creator, User $assignee, Department $department, string $topic, string $approvalStatus): WorkOrder
    {
        return WorkOrder::create([
            'user_id' => $assignee->id,
            'created_by' => $creator->id,
            'assigned_by' => $creator->id,
            'leader_user_id' => $creator->id,
            'department_id' => $department->id,
            'job_topic' => $topic,
            'job_priority' => 2,
            'job_status' => 2,
            'approval_status' => $approvalStatus,
            'job_start_at' => now(),
            'job_due_at' => now()->addDay(),
        ]);
    }
}
