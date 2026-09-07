<?php

namespace Tests\Feature;

use App\Exceptions\WorkspaceVersionConflict;
use App\Models\Department;
use App\Models\User;
use App\Models\WorkspaceBoard;
use App\Models\WorkspaceBoardDocument;
use App\Services\WorkspaceBoardDocumentService;
use App\Services\WorkspaceBoardService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * การบันทึกเนื้อหาผืนผ้าใบ และกลไกกันสองคนเขียนทับกัน
 *
 * ระบบไม่ทำ realtime จึงต้องรับประกันว่าคนที่บันทึกทีหลังจะ "ถูกบอก" ไม่ใช่
 * "เขียนทับเงียบ ๆ" นี่คือสัญญาหลักของฟีเจอร์ที่ผู้ใช้ตัดสินใจเลือกไว้
 */
class WorkspaceBoardDocumentSaveTest extends TestCase
{
    use RefreshDatabase;

    private Department $it;

    private User $staff;

    private WorkspaceBoard $board;

    protected function setUp(): void
    {
        parent::setUp();

        $this->it = Department::create(['department_name' => 'IT']);
        $this->staff = $this->user('user', $this->it);
        $this->board = app(WorkspaceBoardService::class)
            ->create($this->staff, $this->it, 'กระดานทดสอบ', 'organization');
    }

    public function test_saving_stores_the_document_and_bumps_the_version(): void
    {
        $this->actingAs($this->staff)
            ->putJson(route('workspace.boards.document.save', $this->board), [
                'base_version' => 1,
                'document' => $this->document([$this->penElement('e-1')]),
            ])
            ->assertOk()
            ->assertJson(['ok' => true, 'version' => 2, 'element_count' => 1]);

        $stored = WorkspaceBoardDocument::query()->findOrFail($this->board->id);

        $this->assertSame(2, $stored->content_version);
        $this->assertCount(1, $stored->document['elements']);
        $this->assertSame('e-1', $stored->document['elements'][0]['id']);

        $board = $this->board->fresh();
        $this->assertSame(1, $board->element_count);
        $this->assertSame($this->staff->id, $board->last_edited_by);
        $this->assertNotNull($board->last_edited_at);
    }

    public function test_a_stale_base_version_is_rejected_with_the_current_document(): void
    {
        // คนแรกบันทึกไปแล้ว เวอร์ชันบนเซิร์ฟเวอร์จึงเป็น 2
        $this->actingAs($this->staff)
            ->putJson(route('workspace.boards.document.save', $this->board), [
                'base_version' => 1,
                'document' => $this->document([$this->penElement('e-first')]),
            ])
            ->assertOk();

        $colleague = $this->user('user', $this->it);

        // คนที่สองยังถือเวอร์ชัน 1 อยู่
        $response = $this->actingAs($colleague)
            ->putJson(route('workspace.boards.document.save', $this->board), [
                'base_version' => 1,
                'document' => $this->document([$this->penElement('e-second')]),
            ])
            ->assertStatus(409)
            ->assertJson([
                'ok' => false,
                'code' => 'version_conflict',
                'version' => 2,
            ]);

        // ต้องส่งเนื้อหาฉบับล่าสุดกลับไปด้วย เพื่อให้กู้คืนได้ในคำขอเดียว
        $payload = $response->json();
        $this->assertSame('e-first', $payload['document']['elements'][0]['id']);
        $this->assertSame($this->staff->name, $payload['saved_by']['name']);
        $this->assertNotNull($payload['saved_at_label']);

        // และของเดิมต้องไม่ถูกแตะ
        $stored = WorkspaceBoardDocument::query()->findOrFail($this->board->id);
        $this->assertSame('e-first', $stored->document['elements'][0]['id']);
        $this->assertSame(2, $stored->content_version);
    }

    /**
     * สองคำขอที่ถือเวอร์ชันเดียวกัน ต้องสำเร็จแค่หนึ่ง
     *
     * เทสต์นี้คือเหตุผลที่ service ใช้ UPDATE ... WHERE content_version = ?
     * แทนการอ่านค่ามาเทียบก่อนแล้วค่อยเขียน ถ้ามีใครเปลี่ยนกลับไปเป็นแบบอ่านก่อน
     * เทสต์นี้จะเห็นเวอร์ชันกลายเป็น 3 แทนที่จะเป็น 2
     */
    public function test_two_saves_from_the_same_base_version_only_apply_once(): void
    {
        $service = app(WorkspaceBoardDocumentService::class);

        $service->save($this->board, $this->staff, $this->document([$this->penElement('e-a')]), 1);

        try {
            $service->save($this->board, $this->staff, $this->document([$this->penElement('e-b')]), 1);
            $this->fail('การบันทึกครั้งที่สองด้วยเวอร์ชันเดิมต้องถูกปฏิเสธ');
        } catch (WorkspaceVersionConflict $conflict) {
            $this->assertSame(2, $conflict->currentState['version']);
        }

        $this->assertSame(2, WorkspaceBoardDocument::query()->findOrFail($this->board->id)->content_version);
    }

    public function test_reading_the_document_is_allowed_for_read_only_visitors(): void
    {
        app(WorkspaceBoardDocumentService::class)
            ->save($this->board, $this->staff, $this->document([$this->penElement('e-1')]), 1);

        $hr = Department::create(['department_name' => 'HR']);

        $this->actingAs($this->user('user', $hr))
            ->getJson(route('workspace.boards.document', $this->board))
            ->assertOk()
            ->assertJson(['ok' => true, 'version' => 2]);
    }

    public function test_outsiders_cannot_save_even_when_they_can_read_the_board(): void
    {
        $hr = Department::create(['department_name' => 'HR']);
        $outsider = $this->user('user', $hr);

        // คนนอกแผนกเปิดอ่านกระดานสาธารณะได้
        $this->actingAs($outsider)
            ->getJson(route('workspace.boards.document', $this->board))
            ->assertOk();

        // แต่ยิงบันทึกตรง ๆ ไม่ได้ แม้จะข้ามหน้าจอไปเลย
        $this->actingAs($outsider)
            ->putJson(route('workspace.boards.document.save', $this->board), [
                'base_version' => 1,
                'document' => $this->document([$this->penElement('e-x')]),
            ])
            ->assertForbidden();

        $this->assertSame(1, WorkspaceBoardDocument::query()->findOrFail($this->board->id)->content_version);
    }

    public function test_viewer_cannot_save(): void
    {
        $this->actingAs($this->user('viewer', $this->it))
            ->putJson(route('workspace.boards.document.save', $this->board), [
                'base_version' => 1,
                'document' => $this->document([]),
            ])
            ->assertForbidden();
    }

    public function test_base_version_is_required(): void
    {
        $this->actingAs($this->staff)
            ->putJson(route('workspace.boards.document.save', $this->board), [
                'document' => $this->document([]),
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('base_version');
    }

    /**
     * กระดานเก่าที่ไม่มีแถวเนื้อหาต้องกู้ได้เอง ไม่ใช่ติด 409 ตลอดกาล
     */
    public function test_a_board_without_a_document_row_recovers_on_read(): void
    {
        WorkspaceBoardDocument::query()->where('workspace_board_id', $this->board->id)->delete();

        $state = app(WorkspaceBoardDocumentService::class)->currentState($this->board);

        $this->assertSame(1, $state['version']);
        $this->assertSame([], $state['document']['elements']);
        $this->assertDatabaseHas('workspace_board_documents', ['workspace_board_id' => $this->board->id]);
    }

    /**
     * @param  array<int, array<string, mixed>>  $elements
     * @return array<string, mixed>
     */
    private function document(array $elements): array
    {
        return ['schema' => 1, 'elements' => $elements];
    }

    /**
     * @return array<string, mixed>
     */
    private function penElement(string $id): array
    {
        return [
            'id' => $id,
            'type' => 'pen',
            'z' => 1,
            'stroke' => '#1f2937',
            'strokeWidth' => 4,
            'points' => [[10, 10], [20, 24]],
        ];
    }

    private function user(string $role, Department $department): User
    {
        return User::factory()->create([
            'role' => $role,
            'department_id' => $department->id,
            'is_active' => true,
        ]);
    }
}
