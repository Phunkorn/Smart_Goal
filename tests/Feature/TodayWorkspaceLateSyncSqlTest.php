<?php

namespace Tests\Feature;

use App\Models\Department;
use App\Models\User;
use App\Models\WorkOrder;
use App\Services\MemberWorkloadQuery;
use App\Support\TodayWorkspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * TodayWorkspace::synchronizeLate() ต้องไม่ UPDATE ผ่านคิวรีที่อ่าน work_orders ซ้อนอยู่
 *
 * MySQL ปฏิเสธ UPDATE ที่ subquery อ่านตารางเดียวกับที่กำลังเขียน (error 1093)
 * ผู้เรียกจริงอย่าง WorkBoardController::adminMember() ส่ง
 * MemberWorkloadQuery::forMemberWorkspace() ซึ่งมี orWhereHas('children') เข้ามา
 * หน้า Member Workspace จึงพัง 500 ทุกครั้งบนฐานข้อมูลจริง
 *
 * เทสต์ชุดนี้วัดจาก "รูปของ SQL" ไม่ใช่ผลลัพธ์ เพราะ SQLite ที่ชุดทดสอบใช้ยอมให้
 * UPDATE แบบนั้นทำงานได้ ถ้าวัดจากผลลัพธ์ เทสต์จะผ่านทั้งก่อนและหลังการแก้
 * แล้วบั๊กจะกลับมาได้โดยไม่มีใครรู้
 */
class TodayWorkspaceLateSyncSqlTest extends TestCase
{
    use RefreshDatabase;

    private User $member;

    private WorkOrder $parent;

    private WorkOrder $overdueChild;

    protected function setUp(): void
    {
        parent::setUp();

        $department = Department::create(['department_name' => 'IT']);
        $this->member = User::factory()->create([
            'role' => 'user',
            'department_id' => $department->id,
            'is_active' => true,
            'must_change_password' => false,
        ]);

        $this->parent = $this->task($department, 'งานแม่', ['job_due_at' => now()->addDays(3)]);
        $this->overdueChild = $this->task($department, 'งานย่อยที่เลยกำหนด', [
            'parent_job_id' => $this->parent->job_id,
            'job_due_at' => now()->subDay(),
        ]);
    }

    public function test_late_sync_never_updates_through_a_query_that_reads_work_orders(): void
    {
        $statements = [];
        DB::listen(function ($query) use (&$statements): void {
            $statements[] = $query->sql;
        });

        TodayWorkspace::synchronizeLate(
            app(MemberWorkloadQuery::class)->forMemberWorkspace($this->member)
        );

        $updates = array_values(array_filter(
            $statements,
            fn (string $sql) => str_starts_with(strtolower(ltrim($sql)), 'update')
        ));

        $this->assertNotEmpty($updates, 'ต้องมีคำสั่ง update เกิดขึ้นจริง ไม่ใช่เงียบไปเลย');

        foreach ($updates as $sql) {
            $this->assertStringNotContainsString(
                'select',
                strtolower($sql),
                'MySQL ปฏิเสธ UPDATE ที่มี subquery อ่าน work_orders (error 1093) '
                .'ให้หา job_id ให้ครบก่อนแล้วค่อย update ด้วยคีย์: '.$sql
            );
        }
    }

    public function test_late_sync_still_marks_the_overdue_task_late(): void
    {
        TodayWorkspace::synchronizeLate(
            app(MemberWorkloadQuery::class)->forMemberWorkspace($this->member)
        );

        // งานที่เลยกำหนดต้องกลายเป็นล่าช้า (6) และได้เวลาบันทึกไว้
        $overdue = $this->overdueChild->fresh();
        $this->assertSame(6, (int) $overdue->job_status);
        $this->assertNotNull($overdue->late_at);

        // งานที่ยังไม่ถึงกำหนดต้องไม่ถูกแตะ
        $this->assertSame(2, (int) $this->parent->fresh()->job_status);
    }

    public function test_late_sync_leaves_tasks_outside_the_query_alone(): void
    {
        $otherDepartment = Department::create(['department_name' => 'Marketing']);
        $outsider = User::factory()->create([
            'role' => 'user',
            'department_id' => $otherDepartment->id,
            'is_active' => true,
            'must_change_password' => false,
        ]);
        $otherOverdue = $this->task($otherDepartment, 'งานของอีกแผนกที่เลยกำหนด', [
            'job_due_at' => now()->subDay(),
            'user_id' => $outsider->id,
            'created_by' => $outsider->id,
            'leader_user_id' => $outsider->id,
        ]);

        TodayWorkspace::synchronizeLate(
            app(MemberWorkloadQuery::class)->forMemberWorkspace($this->member)
        );

        // การแก้ไปใช้ whereIn ด้วยคีย์ ต้องไม่ขยายขอบเขตของการอัปเดตออกไปนอกคิวรีเดิม
        $this->assertSame(2, (int) $otherOverdue->fresh()->job_status);
    }

    public function test_late_sync_does_nothing_when_no_task_is_overdue(): void
    {
        $this->overdueChild->forceFill(['job_due_at' => now()->addDays(5)])->save();

        $statements = [];
        DB::listen(function ($query) use (&$statements): void {
            $statements[] = $query->sql;
        });

        TodayWorkspace::synchronizeLate(
            app(MemberWorkloadQuery::class)->forMemberWorkspace($this->member)
        );

        $updates = array_filter(
            $statements,
            fn (string $sql) => str_starts_with(strtolower(ltrim($sql)), 'update')
        );

        $this->assertSame([], array_values($updates), 'ไม่มีงานเลยกำหนด ต้องไม่ยิง update เปล่า');
    }

    /** @param  array<string, mixed>  $overrides */
    private function task(Department $department, string $topic, array $overrides = []): WorkOrder
    {
        return WorkOrder::create([
            'user_id' => $this->member->id,
            'created_by' => $this->member->id,
            'leader_user_id' => $this->member->id,
            'department_id' => $department->id,
            'job_topic' => $topic,
            'job_priority' => 2,
            'job_status' => 2,
            'approval_status' => 'approved',
            'job_start_at' => now()->subDays(2),
            ...$overrides,
        ]);
    }
}
