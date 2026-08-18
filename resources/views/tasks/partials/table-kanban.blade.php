@php
$manageableTaskLists = $manageableTaskLists ?? collect();
$projectCreatorMeta = $projectCreatorMeta ?? collect();
$showCreateActions = $showCreateActions ?? true;
$showQuickAdd = $showQuickAdd ?? true;
$taskLinkMode = $taskLinkMode ?? false;
$workspaceContext = $workspaceContext ?? 'user';

$defaultKanbanList = $manageableTaskLists->first() ?? $taskLists->first();
$defaultKanbanListIsManageable = $defaultKanbanList
    && $manageableTaskLists->contains('id', $defaultKanbanList->id);

$statuses = [
    1 => ['ยังไม่เริ่ม', 'todo'],
    2 => ['กำลังทำ', 'progress'],
    3 => ['รอตรวจสอบ', 'review'],
    5 => ['พักงาน', 'paused'],
    4 => ['เสร็จแล้ว', 'done'],
];

$priorities = [
    1 => 'routine',
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

    @forelse($taskLists as $list)

        @php
            $creatorSummary = $projectCreatorMeta->get($list->id) ?? [];
            $uniformAdminName = $creatorSummary['uniform_admin_name'] ?? null;
        @endphp

        <div
            class="mytasks-kanban__project"
            data-kanban-panel="{{ $list->id }}"
            {{ $defaultKanbanList && (int) $defaultKanbanList->id === (int) $list->id ? '' : 'hidden' }}
        >
            <div class="mytasks-kanban__columns">

                @include('tasks.partials.kanban-project-context', [
                    'list' => $list,
                    'adminSenderName' => $uniformAdminName,
                    'workspaceContext' => $workspaceContext
                ])

                @foreach ($statuses as $status => [$label, $tone])

                    <section
                        class="mytasks-kanban__column status-{{ $tone }}"
                        data-kanban-column="{{ $status }}"
                    >
                        <header>
                            <span><i></i>{{ $label }}</span>
                            <b data-kanban-count>0</b>
                        </header>

                        <div class="mytasks-kanban__cards">

                            @foreach ($allTasks->where('work_order_list_id', $list->id)->where('job_status', $status) as $task)

                                @php
                                    $people = collect([$task->user])
                                        ->filter()
                                        ->concat(
                                            $task->collaborators->where('pivot.status', 'accepted')
                                        )
                                        ->unique('id');

                                    $taskAdminSenderName =
                                        ! $uniformAdminName && $task->creator?->role === 'admin'
                                            ? $task->creator->name
                                            : null;
                                @endphp

                                @include('tasks.partials.task-support-source', [
                                    'task' => $task,
                                    'adminSenderName' => $taskAdminSenderName,
                                    'taskLinkMode' => $taskLinkMode,
                                    'includeCollaborators' => true
                                ])

                                <article
                                    class="mytasks-kanban__card priority-{{ $task->job_priority }}"
                                    data-kanban-card
                                    data-id="{{ $task->job_id }}"
                                    data-status="{{ $status }}"
                                    data-priority="{{ $task->job_priority }}"
                                >
                                    <button
                                        type="button"
                                        class="mytasks-kanban__open"
                                        data-open-kanban-task="{{ $task->job_id }}"
                                    >

                                        <div class="mytasks-kanban__card-head">

                                            <strong data-kanban-title>
                                                {{ $task->job_topic }}
                                            </strong>

                                            <span class="mytasks-kanban__priority priority-tone-{{ $task->job_priority }}">
                                                {{ $priorities[(int) $task->job_priority] ?? $priorities[2] }}
                                            </span>

                                        </div>

                                        <span class="mytasks-kanban__project-name">
                                            โปรเจกต์: {{ $list->name }}
                                        </span>

                                        <span class="mytasks-kanban__due {{ (int) $task->job_status !== 4 && $task->job_due_at?->isPast() ? 'is-late' : '' }}">
                                            <i class="bi bi-calendar3"></i>

                                            @if ((int) $task->job_status !== 4 && $task->job_due_at?->isPast())
                                                เลยกำหนด {{ $task->job_due_at->diffForHumans(null, true) }}
                                            @else
                                                กำหนดส่ง
                                                {{ $task->job_due_at ? $task->job_due_at->translatedFormat('j M Y') : 'ไม่มีกำหนด' }}
                                            @endif
                                        </span>

                                    </button>

                                    <footer>

                                        <div class="mytasks-kanban__assignee">

                                            @if ($task->user)
                                                <span class="mytasks-kanban__avatar">
                                                    {{ Str::upper(Str::substr($task->user->name, 0, 1)) }}
                                                </span>

                                                <span class="mytasks-kanban__assignee-name">
                                                    {{ $task->user->name }}
                                                </span>
                                            @else
                                                <span class="mytasks-kanban__unassigned">
                                                    ยังไม่มีผู้รับผิดชอบ
                                                </span>
                                            @endif

                                        </div>

                                        <button
                                            type="button"
                                            class="mytasks-kanban__attachment"
                                            data-open-attachments="{{ $task->job_id }}"
                                            aria-label="ไฟล์แนบ"
                                        >
                                            <i class="bi bi-paperclip"></i>
                                            {{ $task->images_count ?? $task->images->count() }}
                                        </button>

                                    </footer>

                                </article>

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