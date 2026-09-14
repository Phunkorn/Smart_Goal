<?php

namespace Tests\Feature;

use App\Models\Department;
use App\Models\User;
use App\Models\WorkOrder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ชวนผู้ร่วมงานเข้างานย่อยได้หรือไม่
 *
 * งานย่อยเป็น WorkOrder จริง มันจึงใช้เส้นทางผู้ร่วมงานชุดเดียวกับงานปกติ
 * แต่ WorkOrderPolicy::manageTeam() ตัดสินจาก created_by และ leader_user_id ของ "งานใบนั้น"
 * เทสต์ชุดนี้จึงยืนยันว่าใครชวนได้จริงบ้าง เพื่อไม่ให้เข้าใจผิดว่าสิทธิ์ของงานแม่ไหลลงมาเอง
 */
class WorkOrderSubtaskCollaboratorTest extends TestCase
{
    use RefreshDatabase;

    private function parentTask(User $owner): WorkOrder
    {
        return WorkOrder::create([
            'user_id' => $owner->id,
            'created_by' => $owner->id,
            'assigned_by' => $owner->id,
            'leader_user_id' => $owner->id,
            'department_id' => $owner->department_id,
            'job_topic' => 'งานแม่',
            'job_priority' => 2,
            'job_status' => 2,
            'job_start_at' => now(),
            'job_due_at' => now()->addDay(),
            'approval_status' => 'approved',
        ]);
    }

    public function test_the_subtask_creator_can_invite_a_collaborator_into_the_subtask(): void
    {
        $department = Department::create(['department_name' => 'IT']);
        $owner = User::factory()->create(['role' => 'user', 'department_id' => $department->id]);
        $helper = User::factory()->create(['role' => 'user', 'department_id' => $department->id]);
        $parent = $this->parentTask($owner);

        $this->actingAs($owner)
            ->postJson(route('mytasks.details.store', $parent), ['title' => 'งานย่อย'])
            ->assertCreated();

        $child = $parent->children()->firstOrFail();

        $this->actingAs($owner)
            ->postJson(route('tasks.collaborators.store', $child->job_id), ['collaborators' => [$helper->id]])
            ->assertSuccessful();

        $this->assertTrue($child->fresh()->collaborators->contains('id', $helper->id));
    }

    public function test_a_viewer_can_never_be_invited_into_a_subtask(): void
    {
        $department = Department::create(['department_name' => 'IT']);
        $owner = User::factory()->create(['role' => 'user', 'department_id' => $department->id]);
        $viewer = User::factory()->create(['role' => 'viewer', 'department_id' => $department->id]);
        $parent = $this->parentTask($owner);

        $this->actingAs($owner)
            ->postJson(route('mytasks.details.store', $parent), ['title' => 'งานย่อย'])
            ->assertCreated();

        $child = $parent->children()->firstOrFail();

        // controller คัด viewer ทิ้งเงียบ ๆ แทนการตอบ error สิ่งที่ต้องคุมคือ "ต้องไม่ถูกผูกเข้ามา"
        $this->actingAs($owner)
            ->postJson(route('tasks.collaborators.store', $child->job_id), ['collaborators' => [$viewer->id]]);

        $this->assertFalse($child->fresh()->collaborators->contains('id', $viewer->id));
    }

    /**
     * งานย่อยที่ "ผู้ร่วมงาน" เป็นคนสร้าง เจ้าของงานแม่ต้องยังจัดการทีมของมันได้
     *
     * manageTeam() ตัดสินจาก created_by หรือ leader_user_id ของงานใบนั้น
     * งานย่อยรับ leader_user_id มาจากงานแม่ตอนสร้าง เจ้าของงานแม่จึงยังคุมทีมได้
     * ทั้งที่ created_by เป็นของผู้ร่วมงาน — เทสต์นี้ล็อกพฤติกรรมนั้นไว้ไม่ให้หลุด
     */
    /**
     * ผู้ร่วมงานสร้างงานย่อยในงานของคนอื่นไม่ได้
     *
     * กฎเดิมเปิดให้ผู้ร่วมงานที่ตอบรับแล้วสร้างงานย่อยได้ (ใช้ ability work() ตัวเดียว
     * กับการลงมือทำงาน) ผลข้างเคียงคือผู้สร้างงานย่อยกลายเป็น created_by ของงานย่อยนั้น
     * จึงได้สิทธิ์ระดับเจ้าของบนมันต่อ — จัดการทีมได้ และแชร์ออกไปให้คนนอกขอเข้าร่วมได้
     * ทั้งที่เขาเป็นเพียงผู้ถูกเชิญมาช่วยงานของคนอื่น
     *
     * การนิยามว่างานใบหนึ่งประกอบด้วยงานย่อยอะไร เป็นสิทธิ์ของผู้รับผิดชอบ ผู้สร้าง
     * และหัวหน้างานเท่านั้น (ability manageSubtasks)
     */
    public function test_a_collaborator_cannot_create_subtasks_in_someone_elses_task(): void
    {
        $department = Department::create(['department_name' => 'IT']);
        $owner = User::factory()->create(['role' => 'user', 'department_id' => $department->id]);
        $collaborator = User::factory()->create(['role' => 'user', 'department_id' => $department->id]);
        $parent = $this->parentTask($owner);
        $parent->collaborators()->attach($collaborator->id, ['status' => 'accepted', 'responded_at' => now()]);

        $this->actingAs($collaborator)
            ->postJson(route('mytasks.details.store', $parent), ['title' => 'งานย่อยของผู้ร่วมงาน'])
            ->assertForbidden();

        $this->assertSame(0, $parent->children()->count());

        // ลงมือทำงานยังทำได้ตามเดิม สิทธิ์ที่ถูกตัดคือการนิยามขอบเขตงานเท่านั้น
        $this->assertTrue($collaborator->can('work', $parent));
        $this->assertFalse($collaborator->can('manageSubtasks', $parent));
    }

    /**
     * เจ้าของงานยังสร้างงานย่อยและจัดการทีมของงานย่อยได้ตามเดิม
     */
    public function test_the_owner_creates_subtasks_and_manages_their_team(): void
    {
        $department = Department::create(['department_name' => 'IT']);
        $owner = User::factory()->create(['role' => 'user', 'department_id' => $department->id]);
        $helper = User::factory()->create(['role' => 'user', 'department_id' => $department->id]);
        $parent = $this->parentTask($owner);

        $this->actingAs($owner)
            ->postJson(route('mytasks.details.store', $parent), ['title' => 'งานย่อยของเจ้าของ'])
            ->assertCreated();

        $child = $parent->children()->firstOrFail();
        $this->assertSame($owner->id, (int) $child->created_by);
        $this->assertSame((int) $parent->leader_user_id, (int) $child->leader_user_id);
        $this->assertTrue($owner->can('manageTeam', $child));

        $this->actingAs($owner)
            ->postJson(route('tasks.collaborators.store', $child->job_id), ['collaborators' => [$helper->id]])
            ->assertSuccessful();

        $this->assertTrue($child->fresh()->collaborators->contains('id', $helper->id));
    }
}
