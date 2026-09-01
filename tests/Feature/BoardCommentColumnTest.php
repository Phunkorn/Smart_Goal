<?php

namespace Tests\Feature;

use App\Models\Department;
use App\Models\User;
use App\Models\WorkOrder;
use App\Models\WorkOrderList;
use App\Services\TaskCommentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * คอลัมน์ "คอมเมนต์" ของบอร์ด
 *
 * จำนวนที่แสดงคือคอมเมนต์ทั้งหมด นับจาก updates ที่ถูก eager-load ไว้แล้วสำหรับ timeline
 * จึงไม่มีคิวรีเพิ่ม ส่วนคอมเมนต์ที่ยังไม่ได้อ่านเป็นเพียงสถานะเชิงสายตาบนปุ่มเดียวกัน
 *
 * ช่องนี้ต้อง render ทุกแถวเสมอ เพราะบอร์ดเป็น CSS grid การซ่อนช่องจะทำให้คอลัมน์ที่เหลือเลื่อน
 */
class BoardCommentColumnTest extends TestCase
{
    use RefreshDatabase;

    private const HEADER = '<span>ไฟล์แนบ</span><span>คอมเมนต์</span><span></span>';

    private Department $department;

    protected function setUp(): void
    {
        parent::setUp();
        $this->department = Department::create(['department_name' => 'Workspace']);
    }

    public function test_board_header_places_the_comment_column_after_attachments(): void
    {
        [$member] = $this->scenario();

        $content = $this->actingAs($member)->get(route('mytasks.index'))->assertOk()->getContent();

        $this->assertStringContainsString(self::HEADER, $content);
    }

    public function test_admin_member_board_shows_the_same_comment_column(): void
    {
        [$member, $admin] = $this->scenario();

        $content = $this->actingAs($admin)
            ->get(route('admin.work-board.member', [$this->department, $member]))
            ->assertOk()->getContent();

        $this->assertStringContainsString(self::HEADER, $content);
        $this->assertStringContainsString('class="board-comments', $content);
    }

    public function test_comment_count_no_longer_renders_under_the_task_title(): void
    {
        [$member, , $task] = $this->scenario();
        $this->comment($task, $member, 'คอมเมนต์แรก');

        $content = $this->actingAs($member)->get(route('mytasks.index'))->assertOk()->getContent();

        $this->assertStringNotContainsString('board-reference-comments', $content);
    }

    public function test_comment_cell_carries_the_shared_task_modal_opener_metadata(): void
    {
        [$member, , $task] = $this->scenario();

        $content = $this->actingAs($member)->get(route('mytasks.index'))->assertOk()->getContent();
        $cell = $this->commentCell($content);

        $this->assertStringContainsString('data-open-task-modal', $cell);
        $this->assertStringContainsString('data-task-id="'.$task->job_id.'"', $cell);
        $this->assertStringContainsString('data-task-tab="updates"', $cell);
        $this->assertStringContainsString('data-unread-comments="'.$task->job_id.'"', $cell);
        $this->assertStringContainsString('data-unread-persistent', $cell);
    }

    public function test_a_task_without_comments_renders_the_empty_dash_and_still_keeps_its_cell(): void
    {
        [$member] = $this->scenario();

        $cell = $this->commentCell($this->actingAs($member)->get(route('mytasks.index'))->assertOk()->getContent());

        $this->assertStringContainsString('<strong>-</strong>', $cell);
        $this->assertStringNotContainsString('has-comments', $cell);
        $this->assertStringNotContainsString('has-unread', $cell);
    }

    public function test_the_column_counts_every_comment_not_only_the_unread_ones(): void
    {
        [$member, , $task] = $this->scenario();
        $author = $this->member();
        $task->collaborators()->attach($author->id, ['status' => 'accepted']);

        $this->comment($task, $author, 'คอมเมนต์ที่หนึ่ง');
        $this->comment($task, $author, 'คอมเมนต์ที่สอง');
        // อ่านครบแล้ว จำนวนรวมต้องยังเป็น 2 และหายไปเฉพาะสถานะ unread
        app(TaskCommentService::class)->markRead($task->fresh(), $member);

        $cell = $this->commentCell($this->actingAs($member)->get(route('mytasks.index'))->assertOk()->getContent());

        $this->assertStringContainsString('<strong>2</strong>', $cell);
        $this->assertStringContainsString('has-comments', $cell);
        $this->assertStringNotContainsString('has-unread', $cell);
    }

    public function test_unread_comments_only_add_a_visual_state_and_never_a_second_number(): void
    {
        [$member, , $task] = $this->scenario();
        $author = $this->member();
        $task->collaborators()->attach($author->id, ['status' => 'accepted']);

        $this->comment($task, $author, 'คอมเมนต์ใหม่');

        $cell = $this->commentCell($this->actingAs($member)->get(route('mytasks.index'))->assertOk()->getContent());

        $this->assertStringContainsString('has-unread', $cell);
        $this->assertStringContainsString('<strong>1</strong>', $cell);
        $this->assertSame(1, substr_count($cell, '<strong>'), 'ช่องคอมเมนต์ต้องแสดงตัวเลขเดียวคือจำนวนรวม');
    }

    /** บันทึกอัตโนมัติของระบบไม่ใช่คอมเมนต์ จึงต้องไม่ถูกนับรวมในคอลัมน์นี้ */
    public function test_non_comment_updates_are_excluded_from_the_count(): void
    {
        [$member, , $task] = $this->scenario();
        $task->updates()->create(['user_id' => $member->id, 'note' => 'เปลี่ยนสถานะ', 'is_comment' => false]);

        $cell = $this->commentCell($this->actingAs($member)->get(route('mytasks.index'))->assertOk()->getContent());

        $this->assertStringContainsString('<strong>-</strong>', $cell);
    }

    private function comment(WorkOrder $task, User $author, string $message): void
    {
        app(TaskCommentService::class)->post($task->fresh()->load('collaborators'), $author, $message);
    }

    private function commentCell(string $content): string
    {
        $start = strpos($content, '<button type="button" class="board-comments');
        $this->assertNotFalse($start, 'ไม่พบช่องคอมเมนต์บนบอร์ด');
        $end = strpos($content, '</button>', $start);

        return substr($content, $start, $end - $start);
    }

    /**
     * @return array{0: User, 1: User, 2: WorkOrder}
     */
    private function scenario(): array
    {
        $member = $this->member();
        $admin = $this->member('admin');
        $project = WorkOrderList::create([
            'user_id' => $member->id,
            'name' => 'งานอบรม',
            'priority' => 2,
            'is_visible' => true,
            'sort_order' => 1,
        ]);

        $task = WorkOrder::create([
            'user_id' => $member->id,
            'created_by' => $member->id,
            'leader_user_id' => $member->id,
            'department_id' => $member->department_id,
            'work_order_list_id' => $project->id,
            'job_topic' => 'ทดสอบคอมเมนต์',
            'job_priority' => 4,
            'job_status' => 2,
            'approval_status' => 'approved',
            'approved_by' => $member->id,
            'approved_at' => now(),
            'job_start_at' => now(),
            'job_due_at' => now()->addDay(),
        ]);

        return [$member, $admin, $task];
    }

    private function member(string $role = 'user'): User
    {
        return User::factory()->create([
            'role' => $role,
            'department_id' => $this->department->id,
            'is_active' => true,
            'must_change_password' => false,
        ]);
    }
}
