@php
$manageableTaskLists = $manageableTaskLists ?? collect();
$projectCreatorMeta = $projectCreatorMeta ?? collect();
$showCreateActions = $showCreateActions ?? true;
$showQuickAdd = $showQuickAdd ?? true;
$taskLinkMode = $taskLinkMode ?? false;
$workspaceContext = $workspaceContext ?? 'user';

/*
 * มุมมอง "ตาราง" แสดงเฉพาะงานของผู้ใช้คนนี้ ไม่ใช่ทุกงานในโปรเจกต์
 *
 * โปรเจกต์ที่ทำร่วมกันทำให้ผู้ใช้มองเห็นงานของเพื่อนร่วมแผนกและของผู้มอบหมายไปด้วย
 * ซึ่งถูกต้องสำหรับมุมมอง "บอร์ด" ที่มีหน้าที่แสดงภาพรวมของทั้งโปรเจกต์
 * แต่ตารางคือกระดานงานของตัวเอง จัดคอลัมน์ตามสถานะเพื่อให้ลากงานของตัวเองไปต่อ
 * งานที่ลากไม่ได้จึงเป็นแค่สิ่งกีดขวางสายตา ไม่ได้ช่วยให้ทำงานเร็วขึ้น
 *
 * ใช้ ability participate ไม่ใช่ work เพราะ work ปฏิเสธงานที่ปิดแล้วทุกใบ
 * ถ้ากรองด้วย work คอลัมน์ "เสร็จแล้ว" จะว่างเปล่าทั้งที่เป็นงานของเจ้าของเอง
 *
 * บอร์ดไม่ถูกแตะ — มันอ่านจาก $allTasks ชุดเต็มใน project-board-card.blade.php
 */
$kanbanTasks = $allTasks->filter(fn ($task) => auth()->user()->can('participate', $task))->values();

$workspaceLists = collect([(object) ['id' => 0, 'name' => 'วันนี้']]);
$defaultKanbanList = $workspaceLists->first();
$defaultKanbanListIsManageable = $defaultKanbanList
    && $manageableTaskLists->contains('id', $defaultKanbanList->id);

// ลำดับเดียวกันทุกหน้าจอ: พักงาน → กำลังทำ → รอตรวจสอบ → ล่าช้า → เสร็จแล้ว
$statuses = [
    5 => ['พักงาน', 'paused'],
    2 => ['กำลังทำ', 'progress'],
    3 => ['รอตรวจสอบ', 'review'],
    6 => ['ล่าช้า', 'late'],
    4 => ['เสร็จแล้ว', 'done'],
];

/*
 * คอลัมน์ "รอตรวจสอบ" มีอยู่เฉพาะเมื่อผู้ใช้กำลังทำงานร่วมกับผู้อื่น
 *
 * ขั้นตรวจสอบเกิดได้ก็ต่อเมื่อมีคนอื่นเป็นผู้ตรวจ งานที่ผู้ใช้เปิดเอง รับผิดชอบเอง
 * และอนุมัติเองจึงไม่มีวันเข้าสถานะนี้ การโชว์คอลัมน์เปล่าไว้ทำให้เข้าใจผิดว่า
 * ต้องส่งงานของตัวเองไปให้ใครสักคนตรวจ จึงตัดสินจากงานที่แสดงอยู่จริงในมุมมองนี้
 */
if (! \App\Support\TaskReviewStage::appliesToAny($kanbanTasks, auth()->user())) {
    unset($statuses[3]);
}

$priorities = [
    2 => 'สำคัญไม่ด่วน',
    3 => 'สำคัญด่วน',
    4 => 'ด่วนไม่ค่อยสำคัญ',
    5 => 'ไม่รีบ ไม่มีกำหนด',
];

$projectPriorities = [
    1 => ['สำคัญ/ต่ำ', 'priority-low'],
    2 => ['สำคัญ/กลาง', 'priority-medium'],
    3 => ['สำคัญ/สูง', 'priority-high'],
];

$defaultProjectPriority = (int) ($defaultKanbanList?->priority ?? 2);
@endphp

<section class="mytasks-kanban" data-kanban>

    @if(false)
    <header class="mytasks-kanban__toolbar">

        <label class="mytasks-kanban__project-picker">
            <i class="bi bi-folder2-open"></i>
            <span>โปรเจกต์</span>

            <select data-kanban-project aria-label="เลือกโปรเจกต์">
                @foreach ($taskLists as $list)
                    <option
                        value="{{ $list->id }}"
                        data-manageable="{{ $manageableTaskLists->contains('id', $list->id) ? '1' : '0' }}"
                        data-priority="{{ (int) ($list->priority ?? 2) }}"
                        data-update-url="{{ route('mytasks.lists.update', $list) }}"
                        @selected($defaultKanbanList && (int) $defaultKanbanList->id === (int) $list->id)
                    >
                        {{ $list->name }}
                    </option>
                @endforeach
            </select>
        </label>

        <span data-kanban-project-count></span>

        @if($defaultKanbanList)
            <details
                class="mytasks-kanban__project-priority"
                data-kanban-project-priority
                data-url="{{ route('mytasks.lists.update', $defaultKanbanList) }}"
            >
                <summary class="{{ $projectPriorities[$defaultProjectPriority][1] ?? 'priority-medium' }}">
                    <i class="bi bi-flag-fill"></i>

                    <span data-kanban-project-priority-label>
                        {{ $projectPriorities[$defaultProjectPriority][0] ?? 'สำคัญ/กลาง' }}
                    </span>

                    <i class="bi bi-chevron-down"></i>
                </summary>

                <div class="mytasks-kanban__project-priority-menu">
                    @foreach($projectPriorities as $value => [$label, $class])
                        <button
                            type="button"
                            class="{{ $class }}"
                            data-kanban-project-priority-value="{{ $value }}"
                        >
                            <i class="bi bi-flag-fill"></i>
                            {{ $label }}

                            @if($value === $defaultProjectPriority)
                                <span class="bi bi-check2"></span>
                            @endif
                        </button>
                    @endforeach
                </div>
            </details>
        @endif

        @if($showCreateActions || $showQuickAdd)
            <div class="mytasks-kanban__actions">

                @if($showCreateActions)
                    <button
                        type="button"
                        class="mytasks-kanban__button mytasks-kanban__button--project"
                        data-open-create
                    >
                        <i class="bi bi-plus-lg"></i>
                        เพิ่มโปรเจกต์
                    </button>
                @endif

                @if($showQuickAdd)
                    <button
                        type="button"
                        class="mytasks-kanban__button mytasks-kanban__button--task"
                        data-add-in-group
                        data-list-id="{{ $defaultKanbanListIsManageable ? $defaultKanbanList->id : '' }}"
                        @disabled(! $defaultKanbanListIsManageable)
                    >
                        <i class="bi bi-plus-lg"></i>
                        เพิ่มงาน
                    </button>
                @endif

            </div>
        @endif

    </header>

    @endif
    @forelse($workspaceLists as $list)

        @php
            $creatorSummary = $projectCreatorMeta->get($list->id) ?? [];
            $uniformAdminName = $creatorSummary['uniform_admin_name'] ?? null;
        @endphp

        <div
            class="mytasks-kanban__project"
            data-kanban-panel="{{ $list->id }}"
            {{ $defaultKanbanList && (int) $defaultKanbanList->id === (int) $list->id ? '' : 'hidden' }}
        >
            <nav class="mytasks-kanban__status-tabs" data-kanban-status-tabs role="tablist" aria-label="เลือกสถานะงาน">
                @foreach ($statuses as $status => [$label, $tone])
                    <button
                        type="button"
                        class="status-{{ $tone }} {{ $status === 2 ? 'is-selected' : '' }}"
                        data-kanban-status-tab="{{ $status }}"
                        role="tab"
                        aria-selected="{{ $status === 2 ? 'true' : 'false' }}"
                    >
                        <span>{{ $label }}</span>
                        <b data-kanban-tab-count>0</b>
                    </button>
                @endforeach
            </nav>

            <div class="mytasks-kanban__columns">

                @foreach ($statuses as $status => [$label, $tone])

                    <section
                        class="mytasks-kanban__column status-{{ $tone }} {{ $status === 2 ? 'is-mobile-selected' : '' }}"
                        data-kanban-column="{{ $status }}"
                    >
                        <header>
                            <span><i></i>{{ $label }}</span>
                            <b data-kanban-count>0</b>
                        </header>

                        <div class="mytasks-kanban__cards">

                            @foreach ($kanbanTasks->filter(fn ($task) => (int) $task->job_status === $status) as $task)
                                @include('tasks.partials.kanban-card', [
                                    'task' => $task,
                                    'priorities' => $priorities,
                                    'unreadCommentCounts' => $unreadCommentCounts ?? collect(),
                                    'uniformAdminName' => $uniformAdminName,
                                    'taskLinkMode' => $taskLinkMode,
                                    'workspaceSubject' => $workspaceSubject ?? auth()->user(),
                                ])
                            @endforeach

                        </div>
                    </section>

                @endforeach

            </div>
        </div>

    @empty

        <div class="mytasks-kanban__empty">
            <i class="bi bi-kanban"></i>
            <p>ยังไม่มีโปรเจกต์สำหรับแสดงบอร์ดงาน</p>
        </div>

    @endforelse

</section>
