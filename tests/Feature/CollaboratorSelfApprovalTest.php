<?php

namespace Tests\Feature;

use App\Models\Department;
use App\Models\SystemNotification;
use App\Models\User;
use App\Models\WorkOrder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * คำขอผู้ร่วมงานต้องไม่ค้างเงียบเมื่อผู้เชิญคือผู้อนุมัติคนเดียวกัน
 *
 * โมเดลของการเชิญผู้ร่วมงานคือ "ยืมตัวคน" — ผู้อนุมัติคือหัวหน้าแผนกของคนที่ถูกยืม
 * ไม่ว่างานจะเป็นของแผนกไหน ซึ่งถูกต้องตามเจตนา
 *
 * แต่มีช่องหนึ่งที่ตรรกะนี้วนกลับมาที่ตัวเอง: หัวหน้าแผนกเชิญลูกน้องของตัวเองเข้างาน
 * ที่ปลายทางเป็นแผนกอื่น candidate กับ task จึงคนละแผนก สถานะเป็น pending แล้วผู้
 * อนุมัติที่ถูกเลือกคือหัวหน้าแผนกของ candidate ซึ่งก็คือผู้เชิญคนเดิม
 * NotificationService::notifyApprovalRequest() ตัดผู้รับที่เป็นคนลงมือออก แจ้งเตือน
 * ฉบับเดียวที่จะออกจึงหายไป คำขอนั่งค้างโดยไม่มีใครได้รับแจ้ง
 */
class CollaboratorSelfApprovalTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_head_inviting_their_own_member_into_another_departments_task_does_not_stall(): void
    {
        $it = Department::create(['department_name' => 'IT']);
        $account = Department::create(['department_name' => 'Account']);

        $itHead = $this->member($it, true);
        $itStaff = $this->member($it);
        $accountStaff = $this->member($account);
        $admin = User::factory()->create(['role' => 'admin', 'department_id' => null, 'is_active' => true]);

        // งานปลายทางเป็นแผนก Account แต่หัวหน้า IT เป็นผู้สร้างจึงจัดทีมได้
        $task = WorkOrder::create([
            'user_id' => $accountStaff->id,
            'created_by' => $itHead->id,
            'assigned_by' => $itHead->id,
            'leader_user_id' => $itHead->id,
            'department_id' => $account->id,
            'job_topic' => 'ปิดงบประจำเดือน',
            'job_priority' => 2,
            'job_status' => 2,
            'job_start_at' => now(),
            'job_due_at' => now()->addDay(),
            'approval_status' => 'approved',
        ]);

        $this->actingAs($itHead)
            ->postJson(route('tasks.collaborators.store', $task->job_id), ['collaborators' => [$itStaff->id]])
            ->assertSuccessful();

        $pivot = $task->fresh()->collaborators->firstWhere('id', $itStaff->id);

        $this->assertNotNull($pivot, 'ผู้ถูกเชิญต้องอยู่ในทีม');
        $this->assertSame(
            'accepted',
            $pivot->pivot->status,
            'ผู้เชิญเป็นผู้อนุมัติคนเดียวของคำขอนี้อยู่แล้ว จึงไม่ควรมีสถานะรออนุมัติที่ไม่มีใครไปกดได้'
        );
        $this->assertTrue($itStaff->fresh()->can('view', $task->fresh()), 'ผู้ถูกเชิญต้องเปิดงานได้จริง');

        // ไม่มีคำขออนุมัติค้างอยู่ให้ใครต้องตามเก็บ
        $this->assertFalse(
            SystemNotification::where('type', 'collaborator_approval_request')->exists(),
            'ไม่ควรมีคำขออนุมัติเกิดขึ้นเลยเมื่อผู้เชิญมีอำนาจอนุมัติคนคนนี้อยู่แล้ว'
        );
        $this->assertFalse(SystemNotification::where('user_id', $admin->id)->exists());

        // ผู้ถูกเชิญต้องได้รับแจ้งว่าเข้าร่วมแล้ว ไม่ใช่เงียบไปทั้งสองฝั่ง
        $this->assertTrue(SystemNotification::where('user_id', $itStaff->id)
            ->where('type', 'collaborator_added')->exists());
    }

    /**
     * กติกาเดิมของการยืมตัวคนข้ามแผนกต้องไม่เปลี่ยน
     *
     * เชิญคนของแผนกอื่นยังต้องรอหัวหน้าแผนกของคนนั้นอนุมัติเหมือนเดิม
     */
    public function test_borrowing_someone_from_another_department_still_needs_their_head(): void
    {
        $it = Department::create(['department_name' => 'IT']);
        $account = Department::create(['department_name' => 'Account']);

        $itHead = $this->member($it, true);
        $accountHead = $this->member($account, true);
        $accountStaff = $this->member($account);

        $task = WorkOrder::create([
            'user_id' => $itHead->id,
            'created_by' => $itHead->id,
            'assigned_by' => $itHead->id,
            'leader_user_id' => $itHead->id,
            'department_id' => $it->id,
            'job_topic' => 'ติดตั้งระบบใหม่',
            'job_priority' => 2,
            'job_status' => 2,
            'job_start_at' => now(),
            'job_due_at' => now()->addDay(),
            'approval_status' => 'approved',
        ]);

        $this->actingAs($itHead)
            ->postJson(route('tasks.collaborators.store', $task->job_id), ['collaborators' => [$accountStaff->id]])
            ->assertSuccessful();

        $pivot = $task->fresh()->collaborators->firstWhere('id', $accountStaff->id);
        $this->assertSame('pending', $pivot->pivot->status, 'ยืมคนของแผนกอื่นยังต้องขออนุมัติเหมือนเดิม');

        $this->assertTrue(SystemNotification::where('user_id', $accountHead->id)
            ->where('type', 'collaborator_approval_request')->exists(),
            'หัวหน้าแผนกของคนที่ถูกยืมต้องได้รับคำขอ');
    }

    private function member(Department $department, bool $head = false): User
    {
        return User::factory()->create([
            'role' => 'user',
            'department_id' => $department->id,
            'is_department_head' => $head,
            'is_active' => true,
        ]);
    }
}
