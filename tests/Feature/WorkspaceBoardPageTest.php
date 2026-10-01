<?php

namespace Tests\Feature;

use App\Models\Department;
use App\Models\User;
use App\Models\WorkspaceBoard;
use App\Models\WorkspaceBoardDocument;
use App\Support\WorkspaceDesign;
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

    /**
     * หน้าวาดฝังเลขเวอร์ชันไว้ใน HTML ถ้าเบราว์เซอร์หยิบฉบับเก่าจาก cache มาแสดง
     * (กดย้อนกลับ หรือเปิดจากประวัติ) การบันทึกครั้งแรกจะชนกับงานของตัวเอง
     */
    public function test_the_board_page_is_never_served_from_the_browser_cache(): void
    {
        $staff = $this->user('user', $this->it);
        $board = $this->board($this->it, $staff, 'organization');

        $cacheControl = $this->actingAs($staff)
            ->get(route('workspace.boards.show', $board))
            ->assertOk()
            ->headers->get('Cache-Control');

        $this->assertStringContainsString('no-store', $cacheControl);
    }

    /**
     * จำนวนชิ้นงานบนการ์ดไม่ได้ช่วยให้ตัดสินใจเปิดกระดานไหน แต่ทำให้การ์ดรก
     */
    public function test_board_cards_do_not_show_the_element_count(): void
    {
        $staff = $this->user('user', $this->it);
        $board = $this->board($this->it, $staff, 'organization');
        $board->forceFill(['element_count' => 37])->save();

        $this->actingAs($staff)
            ->get(route('workspace.department', $this->it))
            ->assertOk()
            ->assertSee($board->title, false)
            ->assertDontSee('37 ชิ้น', false)
            ->assertDontSee('ws-board-card__count', false);
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

    /*
     * โครงเมนูบนแถบเครื่องมืออ้างถึงเครื่องมือด้วยคีย์ ไม่ได้ประกาศป้ายซ้ำ
     * ถ้าคีย์ไม่มีใน TOOLS เทมเพลตจะพังตอนเรนเดอร์ด้วย undefined array key
     * ซึ่งเป็นความผิดพลาดที่มองไม่เห็นจนกว่าจะเปิดหน้าจริง
     */
    public function test_toolbar_only_references_tools_that_exist(): void
    {
        $tools = array_keys(WorkspaceDesign::TOOLS);

        foreach (WorkspaceDesign::PRIMARY_TOOLS as $tool) {
            $this->assertContains($tool, $tools, 'แถบอ้างเครื่องมือ '.$tool.' ที่ไม่มีใน TOOLS');
        }

        foreach (WorkspaceDesign::PRIMARY_COMMANDS as $command => $tool) {
            $this->assertContains($tool, $tools, $command.' ยืมป้ายจากเครื่องมือที่ไม่มีอยู่');
        }

        foreach (WorkspaceDesign::TOOL_MENUS as $key => $menu) {
            $this->assertNotSame('', $menu['label'], $key);
            $this->assertStringStartsWith('bi-', $menu['icon'], $key);

            foreach ($menu['tools'] as $tool) {
                $this->assertContains($tool, $tools, $key.' อ้างเครื่องมือ '.$tool.' ที่ไม่มีใน TOOLS');
            }
        }
    }

    /**
     * ทุกเครื่องมือต้องเข้าถึงได้จากแถบ ไม่ใช่มีแต่ปุ่มลัด
     *
     * ถ้ามีคนเพิ่มเครื่องมือใหม่ใน TOOLS แล้วลืมใส่ในแถบหรือในเมนูรูปทรง
     * เครื่องมือนั้นจะกดจากหน้าจอไม่ได้เลย
     */
    public function test_every_tool_is_reachable_from_the_toolbar(): void
    {
        $reachable = array_merge(
            WorkspaceDesign::PRIMARY_TOOLS,
            array_values(WorkspaceDesign::PRIMARY_COMMANDS),
            ...array_values(array_map(fn (array $menu) => $menu['tools'], WorkspaceDesign::TOOL_MENUS)),
        );

        foreach (array_keys(WorkspaceDesign::TOOLS) as $tool) {
            $this->assertContains($tool, $reachable, 'เครื่องมือ '.$tool.' เข้าถึงจากแถบเครื่องมือไม่ได้');
        }
    }

    /**
     * เครื่องมือที่ใช้บ่อยต้องกดถึงในคลิกเดียว ไม่ถูกยุบลงเมนู
     *
     * เคยยุบไว้ในเมนูแล้วผู้ใช้รายงานว่าหาไม่เจอและไม่รู้ว่ากำลังถืออะไรอยู่
     */
    public function test_frequent_tools_are_not_hidden_behind_a_menu(): void
    {
        $inMenus = array_merge(
            ...array_values(array_map(fn (array $menu) => $menu['tools'], WorkspaceDesign::TOOL_MENUS)),
        );

        foreach (['select', 'hand', 'pen', 'eraser', 'sticky', 'text'] as $tool) {
            $this->assertContains($tool, WorkspaceDesign::PRIMARY_TOOLS, $tool.' ต้องอยู่บนแถบ');
            $this->assertNotContains($tool, $inMenus, $tool.' ต้องไม่ถูกซ่อนในเมนู');
        }
    }

    /**
     * เมนูคลิกขวาต้องแบนราบ คำสั่งจัดลำดับชั้นเคยอยู่ในเมนูย่อยแล้วกดไม่ติด
     */
    public function test_the_canvas_menu_is_flat(): void
    {
        foreach (WorkspaceDesign::CANVAS_MENU as $item) {
            $this->assertArrayNotHasKey('submenu', $item);
            $this->assertArrayNotHasKey('items', $item);
        }

        $commands = array_column(WorkspaceDesign::CANVAS_MENU, 'command');

        foreach (['bring-to-front', 'bring-forward', 'send-backward', 'send-to-back'] as $command) {
            $this->assertContains($command, $commands, $command.' ต้องอยู่ในเมนูชั้นบนสุด');
        }
    }

    public function test_canvas_menu_items_declare_a_label_icon_and_scope(): void
    {
        $items = array_filter(
            WorkspaceDesign::CANVAS_MENU,
            fn (array $item) => ! isset($item['separator'])
        );

        $this->assertNotEmpty($items);

        foreach ($items as $item) {
            $name = $item['command'];

            $this->assertNotSame('', $item['label'], $name);
            $this->assertStringStartsWith('bi-', $item['icon'], $name);
            $this->assertContains($item['needs'], ['selection', 'clipboard', 'always'], $name);
            $this->assertIsBool($item['edit'], $name);
        }
    }

    /**
     * คีย์ของกลุ่มบนแถบรูปแบบต้องตรงกับ CONTEXT_GROUPS ฝั่ง JavaScript ซึ่งเป็น
     * ผู้ตัดสินว่ากลุ่มไหนโผล่ ถ้าไม่ตรง กลุ่มนั้นจะไม่มีวันโผล่โดยไม่มี error
     */
    public function test_context_group_keys_match_the_javascript_source(): void
    {
        $source = file_get_contents(base_path('resources/js/pages/workspace/toolbar-context.js'));

        preg_match("/CONTEXT_GROUPS = \[([^\]]*)\]/", $source, $matches);
        preg_match_all("/'([a-z]+)'/", $matches[1], $keys);

        $this->assertSame(array_keys(WorkspaceDesign::CONTEXT_GROUPS), $keys[1]);
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
