<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\WorkOrder;
use App\Models\WorkOrderList;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * เปิดงานอีกครั้งแล้วหน้าเว็บต้องเปลี่ยนตามทันที โดยไม่ต้อง Ctrl + F5
 *
 * ฝั่ง server มีสองหน้าที่ต้องทำให้ครบ
 *
 *   1. วาดกล่องควบคุมไว้ให้ฝั่งหน้าจอเขียนป้ายใหม่ได้ — งานที่ปิดแล้วทำให้
 *      WorkOrderPolicy::work() เป็นเท็จ ของเดิมจึงวาดป้ายเป็น <span> คงที่
 *      แล้วไม่มี element ให้ JavaScript อัปเดต ป้าย "เสร็จแล้ว" จึงค้าง
 *   2. คืน canonical state ชุดเต็มหลัง mutation รวมถึงสถานะใหม่ของงานแม่
 *      เพราะการปิด/เปิดงานย่อยทำให้ด่านงานย่อยของงานแม่เปลี่ยน
 */
class TaskReopenStateTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private WorkOrder $parent;

    private WorkOrder $subtask;

    protected function setUp(): void
    {
        parent::setUp();

        $this->owner = User::factory()->create([
            'role' => 'user',
            'must_change_password' => false,
            'is_active' => true,
        ]);

        $project = WorkOrderList::create(['user_id' => $this->owner->id, 'name' => 'โปรเจกต์ทดสอบ']);
        $this->parent = $this->task('งานแม่', ['work_order_list_id' => $project->id]);
        $this->subtask = $this->task('งานย่อยที่ปิดแล้ว', [
            'work_order_list_id' => $project->id,
            'parent_job_id' => $this->parent->job_id,
            'job_status' => 4,
            'job_completed_at' => now()->subDays(3),
        ]);
    }

    public function test_a_completed_subtask_row_still_carries_the_controls_the_ui_repaints(): void
    {
        $row = $this->subtaskRow();

        // กล่องเมนูสถานะต้องอยู่ ไม่ใช่ป้ายอ่านอย่างเดียว
        $this->assertStringContainsString('data-board-status-menu', $row);
        $this->assertStringContainsString('data-board-status-label', $row);
        // และต้องมีคำสั่งเปิดงานอีกครั้งอยู่บนแถวจริง ไม่ใช่มีแต่ในโมดัล
        $this->assertStringContainsString('data-board-reopen-option', $row);
        $this->assertStringContainsString('data-board-normal-status-option', $row);
        /*
         * ความสำคัญและกำหนดการถูกวาดไว้แบบล็อก เพื่อให้ปลดล็อกได้ทันทีที่งานถูกเปิดใหม่
         * (components/date-picker.js ข้ามช่องที่ disabled อยู่แล้ว จึงยังกดไม่ได้จริง)
         */
        $this->assertStringContainsString('data-board-priority-value', $row);
        $this->assertStringContainsString('data-board-field="due"', $row);
        $this->assertStringContainsString('is-locked', $row);
        $this->assertStringContainsString('disabled', $row);
    }

    public function test_a_completed_parent_row_keeps_its_schedule_cells_for_the_reopener(): void
    {
        $this->subtask->forceFill(['job_status' => 4])->save();
        $this->parent->forceFill(['job_status' => 4, 'job_completed_at' => now()->subDay()])->save();

        $row = $this->boardRow($this->parent->job_id);

        $this->assertStringContainsString('data-board-reopen-option', $row);
        $this->assertStringContainsString('data-board-field="start"', $row);
        $this->assertStringContainsString('data-board-field="due"', $row);
        $this->assertStringContainsString('data-board-priority-value', $row);
    }

    public function test_a_read_only_viewer_still_gets_a_plain_label_without_controls(): void
    {
        $stranger = User::factory()->create([
            'role' => 'viewer',
            'must_change_password' => false,
            'is_active' => true,
        ]);

        // viewer ไม่มีหน้า "งานของฉัน" จึงตรวจที่ระดับสิทธิ์: ไม่มีทั้ง work และ reopen
        $this->assertFalse($stranger->can('work', $this->subtask));
        $this->assertFalse($stranger->can('reopen', $this->subtask));
    }

    public function test_reopening_a_subtask_returns_canonical_state_for_the_subtask_and_its_parent(): void
    {
        $response = $this->actingAs($this->owner)
            ->patchJson(route('tasks.updateStatus', $this->subtask), [
                'job_status' => 2,
                'action' => 'reopen',
            ])
            ->assertOk();

        $response->assertJsonPath('job_status', 2);
        $response->assertJsonPath('transitions.is_final', false);
        $response->assertJsonPath('transitions.can_reopen', false);
        $response->assertJsonPath('transitions.can_edit', true);
        $response->assertJsonPath('interactions.is_closed', false);

        // งานแม่มาด้วย เพราะด่านงานย่อยของมันเปลี่ยนจากการเปิดงานย่อยใบนี้
        $response->assertJsonPath('parent.job_id', $this->parent->job_id);
        $response->assertJsonPath('parent.transitions.open_child_count', 1);

        $this->assertSame(2, (int) $this->subtask->fresh()->job_status);
        $this->assertNull($this->subtask->fresh()->job_completed_at);
    }

    public function test_closing_a_subtask_reports_the_parent_gate_as_cleared(): void
    {
        $this->subtask->forceFill(['job_status' => 2, 'job_completed_at' => null])->save();

        $this->actingAs($this->owner)
            ->patchJson(route('tasks.updateStatus', $this->subtask), ['job_status' => 4])
            ->assertOk()
            ->assertJsonPath('parent.transitions.open_child_count', 0)
            ->assertJsonPath('parent.job_status', 2);
    }

    public function test_a_top_level_task_response_carries_no_parent_block(): void
    {
        $this->actingAs($this->owner)
            ->patchJson(route('tasks.updateStatus', $this->parent), ['job_status' => 5])
            ->assertOk()
            ->assertJsonMissingPath('parent');
    }

    /**
     * การ์ด kanban ของงานใบเดียวต้องขอได้ เพื่อแทรกกลับเข้าหน้าแทนการโหลดหน้าใหม่
     *
     * มุมมองนี้ตัดงานที่ปิดไปก่อนวันนี้ออก (TodayWorkspace::workspaceTable) งานที่ถูกเปิด
     * อีกครั้งจึงไม่มีการ์ดในหน้าให้ย้ายคอลัมน์
     */
    public function test_the_kanban_card_fragment_renders_one_card_for_a_task_the_user_works_on(): void
    {
        $this->subtask->forceFill(['job_status' => 2, 'job_completed_at' => null])->save();

        $response = $this->actingAs($this->owner)
            ->getJson(route('mytasks.kanban-card', $this->parent))
            ->assertOk()
            ->assertJsonPath('job_id', $this->parent->job_id);

        $card = $response->json('card');
        $this->assertStringContainsString('data-kanban-card', $card);
        $this->assertStringContainsString('data-id="'.$this->parent->job_id.'"', $card);
        $this->assertStringContainsString($this->parent->job_topic, $card);
    }

    public function test_the_kanban_card_fragment_is_refused_for_work_the_user_is_not_part_of(): void
    {
        $stranger = User::factory()->create([
            'role' => 'user',
            'must_change_password' => false,
            'is_active' => true,
        ]);

        $this->actingAs($stranger)
            ->getJson(route('mytasks.kanban-card', $this->parent))
            ->assertForbidden();

        // งานย่อยไม่เคยเป็นการ์ดของตัวเองในมุมมองนี้ แม้เจ้าของงานขอเองก็ตาม
        $this->actingAs($this->owner)
            ->getJson(route('mytasks.kanban-card', $this->subtask))
            ->assertNotFound();
    }

    private function subtaskRow(): string
    {
        $html = $this->actingAs($this->owner)
            ->get(route('mytasks.index', ['view' => 'board']))
            ->assertOk()
            ->getContent();

        $anchor = strpos($html, 'data-detail-id="'.$this->subtask->job_id.'"');
        $this->assertNotFalse($anchor, 'ไม่พบแถวงานย่อยในหน้า');
        $row = substr($html, (int) strrpos(substr($html, 0, $anchor), '<li '));

        return substr($row, 0, (int) strpos($row, '</li>'));
    }

    private function boardRow(int $jobId): string
    {
        $html = $this->actingAs($this->owner)
            ->get(route('mytasks.index', ['view' => 'board']))
            ->assertOk()
            ->getContent();

        $anchor = strpos($html, 'data-task-id="'.$jobId.'" data-participate');
        $this->assertNotFalse($anchor, 'ไม่พบแถวงานแม่ในหน้า');
        $row = substr($html, (int) strrpos(substr($html, 0, $anchor), '<article '));

        return substr($row, 0, (int) strpos($row, '<div class="board-task-details__panel'));
    }

    private function task(string $topic, array $attributes = []): WorkOrder
    {
        return WorkOrder::create(array_merge([
            'user_id' => $this->owner->id,
            'created_by' => $this->owner->id,
            'leader_user_id' => $this->owner->id,
            'job_topic' => $topic,
            'job_priority' => 2,
            'job_status' => 2,
            'approval_status' => 'approved',
            'job_start_at' => now()->subDay(),
            'job_due_at' => now()->addDays(2),
        ], $attributes));
    }
}
