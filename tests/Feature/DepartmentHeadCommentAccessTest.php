<?php

namespace Tests\Feature;

use App\Models\Department;
use App\Models\User;
use App\Models\WorkOrder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * หัวหน้าแผนกคอมเมนต์งานลูกทีมได้จาก Workspace แบบอ่านอย่างเดียว
 *
 * Workspace ของสมาชิกที่หัวหน้าแผนกเปิดดูถูกตั้ง forceReadOnly ไว้ เพื่อปิดการแก้งาน
 * การจัดการทีม ไฟล์แนบ และการเปลี่ยนสถานะ แต่การแสดงความคิดเห็นไม่ใช่การแก้งาน
 * ธงตัวนั้นจึงต้องไม่ปิดกล่องคอมเมนต์ไปด้วย ไม่งั้นหัวหน้าอ่านความคิดเห็นได้แต่ตอบไม่ได้
 * แล้วบทสนทนาจะย้ายไปอยู่นอกระบบ
 *
 * สิทธิ์จริงยังตัดสินที่ WorkOrderPolicy::comment() ที่เดียว หน้าจอเป็นเพียงผลลัพธ์
 */
class DepartmentHeadCommentAccessTest extends TestCase
{
    use RefreshDatabase;

    private Department $department;

    private User $head;

    private User $member;

    protected function setUp(): void
    {
        parent::setUp();
        $this->department = Department::create(['department_name' => 'ฝ่ายไอที']);
        $this->member = $this->user(['department_id' => $this->department->id]);
        $this->head = $this->user([
            'department_id' => $this->department->id,
            'is_department_head' => true,
        ]);
    }

    public function test_the_read_only_member_workspace_still_offers_the_comment_box(): void
    {
        $task = $this->task();

        $this->actingAs($this->head)
            ->get(route('work-board.member', [$this->department, $this->member, 'workspace' => 1]))
            ->assertOk()
            ->assertSee($this->inJsonIsland(route('tasks.comments.store', $task)), false);
    }

    /**
     * ธงอ่านอย่างเดียวต้องยังปิดสิ่งที่มันเคยปิด การเปิดกล่องคอมเมนต์ไม่ใช่การเปิดทั้งหน้า
     */
    public function test_the_read_only_workspace_keeps_every_other_control_closed(): void
    {
        $task = $this->task();

        $this->actingAs($this->head)
            ->get(route('work-board.member', [$this->department, $this->member, 'workspace' => 1]))
            ->assertOk()
            ->assertDontSee($this->inJsonIsland(route('tasks.details.update', $task->job_id)), false)
            ->assertDontSee($this->inJsonIsland(route('tasks.attachments.store', $task->job_id)), false)
            ->assertDontSee($this->inJsonIsland(route('tasks.collaborators.store', $task->job_id)), false);
    }

    /**
     * หัวหน้าแผนกอื่นไม่มีทางไปถึงกล่องคอมเมนต์
     *
     * WorkBoardController::member() ให้เฉพาะผู้ที่ดูแลแผนกนั้นเข้า Workspace เต็ม
     * คนนอกถูกพากลับไปหน้า Preview งานวันนี้เสมอ แม้จะเติม workspace=1 มาเองก็ตาม
     */
    public function test_a_head_from_another_department_never_reaches_the_comment_box(): void
    {
        $task = $this->task();
        $outsider = $this->user([
            'department_id' => Department::create(['department_name' => 'ฝ่ายบัญชี'])->id,
            'is_department_head' => true,
        ]);

        $this->actingAs($outsider)
            ->get(route('work-board.member', [$this->department, $this->member, 'workspace' => 1]))
            ->assertOk()
            ->assertDontSee($this->inJsonIsland(route('tasks.comments.store', $task)), false);

        // และถ้ายิงตรงไปที่ endpoint ก็ต้องถูกปฏิเสธที่ policy อยู่ดี
        $this->actingAs($outsider)
            ->postJson(route('tasks.comments.store', $task), ['message' => 'blocked'])
            ->assertForbidden();
    }

    /**
     * URL ในเกาะ JSON ถูก json_encode มาแล้ว เครื่องหมาย / จึงกลายเป็น \/
     *
     * ถ้าเทียบกับ URL ดิบ assertSee จะไม่เจอ ส่วน assertDontSee จะผ่านด้วยเหตุผลผิด
     * คือไม่เจอเพราะรูปแบบไม่ตรง ไม่ใช่เพราะสิทธิ์ถูกปิดจริง
     */
    private function inJsonIsland(string $url): string
    {
        return str_replace('/', '\/', $url);
    }

    private function user(array $attributes = []): User
    {
        return User::factory()->create(array_merge([
            'role' => 'user',
            'must_change_password' => false,
            'is_active' => true,
        ], $attributes));
    }

    private function task(): WorkOrder
    {
        return WorkOrder::create([
            'user_id' => $this->member->id,
            'created_by' => $this->member->id,
            'leader_user_id' => $this->member->id,
            'department_id' => $this->department->id,
            'job_topic' => 'งานของลูกทีม',
            'job_priority' => 2,
            'job_status' => 2,
            'approval_status' => 'approved',
            'job_start_at' => now()->subDay(),
            'job_due_at' => now()->addDay(),
        ]);
    }
}
