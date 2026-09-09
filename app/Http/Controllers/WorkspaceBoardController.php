<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\RespondsWithTaskResult;
use App\Models\Department;
use App\Models\WorkspaceBoard;
use App\Services\WorkspaceBoardDocumentService;
use App\Services\WorkspaceBoardQueryService;
use App\Services\WorkspaceBoardService;
use App\Support\WorkspaceBoardPresenter;
use App\Support\WorkspaceDesign;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * หน้า "กระดานไอเดีย" - พื้นที่วาดเปล่าแบบกระดานไวท์บอร์ดของแต่ละแผนก
 *
 * หน้าเดียวใช้ได้ทั้งพนักงาน หัวหน้าแผนก admin และ viewer ความต่างของสิทธิ์ส่งไป
 * ที่ Blade ผ่านตัวแปร $capabilities ที่คำนวณจาก policy ฝั่ง server ไม่ใช่การซ่อน
 * ปุ่มด้วยเงื่อนไข role ใน Blade หรือ JavaScript ตามกติกาใน CLAUDE.md
 */
class WorkspaceBoardController extends Controller
{
    use RespondsWithTaskResult;

    public function __construct(
        private readonly WorkspaceBoardQueryService $query,
        private readonly WorkspaceBoardService $boards,
        private readonly WorkspaceBoardDocumentService $documents,
    ) {}

    /**
     * หน้ารวม - การ์ดของทุกแผนกพร้อมจำนวนกระดานที่ผู้ใช้คนนี้เห็นได้
     */
    public function index()
    {
        $viewer = Auth::user();
        Gate::authorize('viewAny', WorkspaceBoard::class);

        return view('workspace.index', [
            'summaries' => $this->query->departmentSummaries($viewer),
            'ownDepartment' => $viewer->department,
            'canCreate' => Gate::allows('create', WorkspaceBoard::class),
        ]);
    }

    /**
     * กระดานทั้งหมดของแผนกหนึ่ง
     *
     * ไม่ต้อง authorize ต่อแผนก เพราะรายชื่อกระดานถูกกรองด้วย visibleQuery()
     * อยู่แล้ว คนนอกแผนกที่เปิดหน้านี้จะเห็นเฉพาะกระดานที่ตั้งเป็นทั้งองค์กร
     * ซึ่งเป็นพฤติกรรมที่ต้องการ ไม่ใช่การรั่วของข้อมูล
     */
    public function department(Department $department)
    {
        $viewer = Auth::user();
        Gate::authorize('viewAny', WorkspaceBoard::class);

        return view('workspace.department', [
            'department' => $department,
            'boards' => $this->query->boardsForDepartment($viewer, $department),
            'isOwnDepartment' => (int) $viewer->department_id === (int) $department->id,
            'canCreate' => Gate::allows('createInDepartment', [WorkspaceBoard::class, $department]),
        ]);
    }

    /**
     * หน้าวาด
     */
    public function show(WorkspaceBoard $board)
    {
        Gate::authorize('view', $board);

        $board->loadMissing(['department:id,department_name', 'creator:id,name', 'lastEditor:id,name']);

        $state = $this->documents->currentState($board);

        return view('workspace.board', [
            'board' => $board,
            'design' => WorkspaceDesign::forClient(),
            'document' => WorkspaceBoardPresenter::documentForClient($board, $state['document']),
            'documentVersion' => $state['version'],
            'capabilities' => $this->capabilities($board),
        ]);
    }

    public function store(Request $request)
    {
        $actor = Auth::user();
        Gate::authorize('create', WorkspaceBoard::class);

        $data = $request->validate($this->settingsRules() + [
            'department_id' => ['required', 'integer', 'exists:departments,id'],
        ]);

        $department = Department::query()->findOrFail($data['department_id']);

        // ตรวจแผนกปลายทางแยกต่างหาก มิฉะนั้นพนักงานแผนกหนึ่งจะยิง department_id
        // ของแผนกอื่นเข้ามาสร้างกระดานในบ้านคนอื่นได้
        Gate::authorize('createInDepartment', [WorkspaceBoard::class, $department]);

        $board = $this->boards->create($actor, $department, $data['title'], $data['visibility']);

        return $this->jsonOrBack($request, true, 'สร้างกระดานไอเดียแล้ว', 200, [
            'board_id' => $board->id,
            'redirect' => route('workspace.boards.show', $board),
        ]);
    }

    public function update(Request $request, WorkspaceBoard $board)
    {
        Gate::authorize('manageSettings', $board);

        $data = $request->validate($this->settingsRules());

        $this->boards->updateSettings($board, $data['title'], $data['visibility']);

        return $this->jsonOrBack($request, true, 'บันทึกการตั้งค่ากระดานแล้ว', 200, [
            'title' => $board->title,
            'visibility' => $board->visibility,
            'visibility_label' => WorkspaceDesign::visibility($board->visibility)['label'],
        ]);
    }

    public function destroy(Request $request, WorkspaceBoard $board)
    {
        Gate::authorize('delete', $board);

        $departmentId = $board->department_id;
        $this->boards->delete($board);

        return $this->jsonOrBack($request, true, 'ลบกระดานไอเดียแล้ว', 200, [
            'redirect' => route('workspace.department', $departmentId),
        ]);
    }

    /**
     * กติกาการตรวจข้อมูลของชื่อและระดับการมองเห็น ใช้ร่วมกันระหว่าง store กับ update
     *
     * @return array<string, array<int, mixed>>
     */
    private function settingsRules(): array
    {
        return [
            'title' => ['required', 'string', 'max:'.WorkspaceDesign::MAX_TITLE_LENGTH],
            'visibility' => ['required', Rule::in(WorkspaceDesign::visibilityKeys())],
        ];
    }

    /**
     * สิทธิ์ที่หน้าจอต้องรู้ คำนวณจาก policy ฝั่ง server ทั้งหมด
     *
     * Blade ใช้ค่านี้ตัดสินว่าจะ disable ปุ่มไหน และ JavaScript อ่านค่าเดียวกัน
     * ผ่าน JSON island โดยไม่ต้องรู้จักคำว่า role เลย
     *
     * @return array<string, bool>
     */
    private function capabilities(WorkspaceBoard $board): array
    {
        return [
            'canEdit' => Gate::allows('update', $board),
            'canManageSettings' => Gate::allows('manageSettings', $board),
            'canDelete' => Gate::allows('delete', $board),
        ];
    }
}
