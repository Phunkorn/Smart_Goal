<?php

namespace Tests\Feature;

use App\Models\Department;
use App\Models\User;
use App\Models\WorkspaceBoard;
use App\Models\WorkspaceBoardDocument;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * เส้นทาง HTTP ของกระดานไอเดีย ตั้งแต่หน้ารวมไปจนถึงการสร้างและลบ
 *
 * เน้นสิ่งที่เทสต์ระดับ policy มองไม่เห็น ได้แก่ การกรองที่หน้ารายการ
 * การส่ง $capabilities ให้ Blade และการที่ route กันการยิงข้ามแผนกได้จริง
 */
class WorkspaceBoardPageTest extends TestCase
{
    use RefreshDatabase;

    private Department $it;

    private Department $hr;

    protected function setUp(): void
    {
        parent::setUp();

        $this->it = Department::create(['department_name' => 'IT']);
        $this->hr = Department::create(['department_name' => 'HR']);
    }

    public function test_index_lists_every_department_with_the_visible_board_count(): void
    {
        $staff = $this->user('user', $this->it);

        $this->board($this->it, $staff, 'organization');
        $this->board($this->hr, $this->user('user', $this->hr), 'department');

        $this->actingAs($staff)
            ->get(route('workspace.index'))
            ->assertOk()
            ->assertSee('IT', false)
            ->assertSee('HR', false)
            // IT มีกระดาน 1 ใบที่เห็นได้ ส่วนกระดานเฉพาะแผนกของ HR ต้องไม่ถูกนับ
            ->assertSee('1 กระดาน', false)
            ->assertSee('0 กระดาน', false);
    }

    public function test_department_page_hides_private_boards_from_other_departments(): void
    {
        $itStaff = $this->user('user', $this->it);
        $outsider = $this->user('user', $this->hr);

        $shared = $this->board($this->it, $itStaff, 'organization');
        $private = $this->board($this->it, $itStaff, 'department');

        $this->actingAs($outsider)
            ->get(route('workspace.department', $this->it))
            ->assertOk()
            ->assertSee($shared->title, false)
            ->assertDontSee($private->title, false)
            // คนนอกแผนกต้องไม่เห็นปุ่มสร้าง เพราะสร้างในแผนกอื่นไม่ได้
            ->assertDontSee('data-workspace-create', false);

        $this->actingAs($itStaff)
            ->get(route('workspace.department', $this->it))
            ->assertOk()
            ->assertSee($private->title, false)
            ->assertSee('data-workspace-create', false);
    }

    public function test_board_page_marks_outsiders_as_read_only(): void
    {
        $board = $this->board($this->it, $this->user('user', $this->it), 'organization');

        $this->actingAs($this->user('user', $this->it))
            ->get(route('workspace.boards.show', $board))
            ->assertOk()
            ->assertSee('"canEdit":true', false)
            ->assertDontSee('ดูอย่างเดียว', false);

        $this->actingAs($this->user('user', $this->hr))
            ->get(route('workspace.boards.show', $board))
            ->assertOk()
            ->assertSee('"canEdit":false', false)
            ->assertSee('ดูอย่างเดียว', false);
    }

    public function test_a_private_board_returns_403_for_other_departments(): void
    {
        $board = $this->board($this->it, $this->user('user', $this->it), 'department');

        $this->actingAs($this->user('user', $this->hr))
            ->get(route('workspace.boards.show', $board))
            ->assertForbidden();

        $this->actingAs($this->user('viewer', $this->it))
            ->get(route('workspace.boards.show', $board))
            ->assertForbidden();
    }

    public function test_creating_a_board_seeds_an_empty_document_in_the_same_transaction(): void
    {
        $staff = $this->user('user', $this->it);

        $this->actingAs($staff)
            ->post(route('workspace.boards.store'), [
                'department_id' => $this->it->id,
                'title' => 'ไอเดียปรับปรุงขั้นตอนแจ้งซ่อม',
                'visibility' => 'department',
            ])
            ->assertRedirect();

        $board = WorkspaceBoard::query()->firstOrFail();

        $this->assertSame('ไอเดียปรับปรุงขั้นตอนแจ้งซ่อม', $board->title);
        $this->assertSame('department', $board->visibility);
        $this->assertSame($staff->id, $board->created_by);

        // กระดานที่ไม่มีแถว document เป็นสถานะที่การบันทึกอัตโนมัติกู้เองไม่ได้
        $document = WorkspaceBoardDocument::query()->findOrFail($board->id);

        $this->assertSame(['schema' => 1, 'elements' => []], $document->document);
        $this->assertSame(1, $document->content_version);
    }

    public function test_staff_cannot_create_a_board_in_another_department(): void
    {
        $this->actingAs($this->user('user', $this->it))
            ->post(route('workspace.boards.store'), [
                'department_id' => $this->hr->id,
                'title' => 'แอบสร้างในแผนกอื่น',
                'visibility' => 'organization',
            ])
            ->assertForbidden();

        $this->assertSame(0, WorkspaceBoard::query()->count());
    }

    public function test_a_colleague_cannot_rename_or_delete_someone_elses_board(): void
    {
        $board = $this->board($this->it, $this->user('user', $this->it), 'organization');
        $colleague = $this->user('user', $this->it);

        $this->actingAs($colleague)
            ->patch(route('workspace.boards.update', $board), [
                'title' => 'ชื่อใหม่',
                'visibility' => 'organization',
            ])
            ->assertForbidden();

        $this->actingAs($colleague)
            ->delete(route('workspace.boards.destroy', $board))
            ->assertForbidden();

        $this->assertNotSoftDeleted($board);
    }

    public function test_the_department_head_can_flip_visibility_and_delete(): void
    {
        $board = $this->board($this->it, $this->user('user', $this->it), 'organization');
        $head = $this->user('user', $this->it, true);

        $this->actingAs($head)
            ->patch(route('workspace.boards.update', $board), [
                'title' => 'กระดานแผนก IT',
                'visibility' => 'department',
            ])
            ->assertRedirect();

        $this->assertSame('department', $board->fresh()->visibility);

        $this->actingAs($head)
            ->delete(route('workspace.boards.destroy', $board))
            ->assertRedirect();

        $this->assertSoftDeleted($board);
    }

    public function test_viewer_is_blocked_from_every_mutating_endpoint(): void
    {
        $board = $this->board($this->it, $this->user('user', $this->it), 'organization');
        $viewer = $this->user('viewer', $this->it);

        // route middleware role:admin,user กันชั้นแรก policy กันชั้นที่สอง
        $this->actingAs($viewer)
            ->post(route('workspace.boards.store'), [
                'department_id' => $this->it->id,
                'title' => 'ผู้เข้าชมสร้างกระดาน',
                'visibility' => 'organization',
            ])
            ->assertForbidden();

        $this->actingAs($viewer)
            ->patch(route('workspace.boards.update', $board), [
                'title' => 'ผู้เข้าชมเปลี่ยนชื่อ',
                'visibility' => 'organization',
            ])
            ->assertForbidden();

        $this->actingAs($viewer)
            ->delete(route('workspace.boards.destroy', $board))
            ->assertForbidden();

        $this->assertSame(1, WorkspaceBoard::query()->count());
    }

    public function test_an_invalid_visibility_value_is_rejected(): void
    {
        $this->actingAs($this->user('user', $this->it))
            ->post(route('workspace.boards.store'), [
                'department_id' => $this->it->id,
                'title' => 'กระดานทดสอบ',
                'visibility' => 'public',
            ])
            ->assertSessionHasErrors('visibility');

        $this->assertSame(0, WorkspaceBoard::query()->count());
    }

    private function board(Department $department, User $creator, string $visibility): WorkspaceBoard
    {
        return WorkspaceBoard::query()->create([
            'department_id' => $department->id,
            'created_by' => $creator->id,
            'title' => 'กระดาน '.$visibility.' '.uniqid(),
            'visibility' => $visibility,
        ]);
    }

    private function user(string $role, Department $department, bool $isHead = false): User
    {
        return User::factory()->create([
            'role' => $role,
            'department_id' => $department->id,
            'is_department_head' => $isHead,
            'is_active' => true,
        ]);
    }
}
